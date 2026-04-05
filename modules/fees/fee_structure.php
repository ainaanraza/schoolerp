<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/delete_helpers.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$errors = [];
$success = [];

function ensure_fee_structure_columns(PDO $pdo): void
{
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM fee_structures')->fetchAll() as $row) {
        $columns[$row['Field']] = true;
    }

    if (!isset($columns['fee_type'])) {
        $pdo->exec("ALTER TABLE fee_structures ADD COLUMN fee_type ENUM('admission_fee', 'caution_money', 'tuition_fee') NOT NULL DEFAULT 'tuition_fee' AFTER session_id");
    }
}

function fee_type_label(string $feeType): string
{
    return match ($feeType) {
        'admission_fee' => 'Admission Fee',
        'caution_money' => 'Caution Money',
        'tuition_fee' => 'Tuition Fee',
        default => ucfirst(str_replace('_', ' ', $feeType)),
    };
}

ensure_fee_structure_columns($pdo);

$classesStatement = $pdo->query(
    'SELECT c.id, c.session_id, c.class_name, c.section, s.title AS session_title
     FROM classes c
     JOIN academic_sessions s ON s.id = c.session_id
     ORDER BY c.class_name, c.section'
);
$classes = $classesStatement->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_structure') {
        $classId = (int)($_POST['class_id'] ?? 0);
        $feeType = $_POST['fee_type'] ?? 'tuition_fee';
        $feeTitle = trim($_POST['fee_title'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $billingCycle = $_POST['billing_cycle'] ?? '';
        $dueDayRaw = trim($_POST['due_day'] ?? '');
        $allowedCycles = ['monthly', 'quarterly', 'half_yearly', 'yearly', 'one_time'];
        $allowedFeeTypes = ['admission_fee', 'caution_money', 'tuition_fee'];

        if ($classId <= 0 || $feeTitle === '' || $amount <= 0 || !in_array($billingCycle, $allowedCycles, true) || !in_array($feeType, $allowedFeeTypes, true)) {
            $errors[] = 'Please provide valid class, fee type, fee title, amount, and billing cycle.';
        } else {
            $classLookup = $pdo->prepare('SELECT id, session_id FROM classes WHERE id = :id LIMIT 1');
            $classLookup->execute(['id' => $classId]);
            $classRow = $classLookup->fetch();

            if (!$classRow) {
                $errors[] = 'Selected class not found.';
            } else {
                $dueDay = null;
                if ($dueDayRaw !== '') {
                    $dueDayInt = (int)$dueDayRaw;
                    if ($dueDayInt < 1 || $dueDayInt > 31) {
                        $errors[] = 'Due day must be between 1 and 31.';
                    } else {
                        $dueDay = $dueDayInt;
                    }
                }

                if (empty($errors)) {
                    $insertStructure = $pdo->prepare(
                        'INSERT INTO fee_structures (class_id, session_id, fee_type, fee_title, amount, billing_cycle, due_day, is_active, created_by)
                         VALUES (:class_id, :session_id, :fee_type, :fee_title, :amount, :billing_cycle, :due_day, :is_active, :created_by)'
                    );
                    $insertStructure->execute([
                        'class_id' => $classId,
                        'session_id' => $classRow['session_id'],
                        'fee_type' => $feeType,
                        'fee_title' => $feeTitle,
                        'amount' => $amount,
                        'billing_cycle' => $billingCycle,
                        'due_day' => $dueDay,
                        'is_active' => 1,
                        'created_by' => current_user()['id'],
                    ]);
                    $success[] = 'Fee structure created successfully.';
                }
            }
        }
    }

    if ($action === 'generate_fees') {
        $feeStructureId = (int)($_POST['fee_structure_id'] ?? 0);
        $periodLabel = trim($_POST['period_label'] ?? '');
        $dueDateRaw = trim($_POST['due_date'] ?? '');
        $dueDate = $dueDateRaw !== '' ? $dueDateRaw : null;

        if ($feeStructureId <= 0 || $periodLabel === '') {
            $errors[] = 'Fee structure and period label are required for generation.';
        } else {
            $structureStatement = $pdo->prepare(
                'SELECT id, class_id, session_id, fee_type, amount, is_active
                 FROM fee_structures
                 WHERE id = :id
                 LIMIT 1'
            );
            $structureStatement->execute(['id' => $feeStructureId]);
            $structure = $structureStatement->fetch();

            if (!$structure || (int)$structure['is_active'] !== 1) {
                $errors[] = 'Fee structure not found or inactive.';
            } else {
                $studentsStatement = $pdo->prepare(
                    'SELECT s.id
                     FROM student_class_enrollments sce
                     JOIN students s ON s.id = sce.student_id
                     WHERE sce.class_id = :class_id
                       AND sce.session_id = :session_id
                       AND sce.is_active = 1
                       AND s.status = :student_status'
                );
                $studentsStatement->execute([
                    'class_id' => $structure['class_id'],
                    'session_id' => $structure['session_id'],
                    'student_status' => 'enrolled',
                ]);
                $students = $studentsStatement->fetchAll();

                if (empty($students)) {
                    $errors[] = 'No active enrolled students found in this class/session.';
                } else {
                    $insertFee = $pdo->prepare(
                        'INSERT INTO student_fees (student_id, fee_structure_id, period_label, total_amount, discount_amount, payable_amount, paid_amount, status, due_date)
                         VALUES (:student_id, :fee_structure_id, :period_label, :total_amount, :discount_amount, :payable_amount, :paid_amount, :status, :due_date)'
                    );
                    $existsFee = $pdo->prepare(
                        'SELECT id FROM student_fees WHERE student_id = :student_id AND fee_structure_id = :fee_structure_id AND period_label = :period_label LIMIT 1'
                    );

                    $createdCount = 0;
                    $skippedCount = 0;
                    foreach ($students as $student) {
                        $existsFee->execute([
                            'student_id' => $student['id'],
                            'fee_structure_id' => $feeStructureId,
                            'period_label' => $periodLabel,
                        ]);

                        if ($existsFee->fetch()) {
                            $skippedCount++;
                            continue;
                        }

                        $insertFee->execute([
                            'student_id' => $student['id'],
                            'fee_structure_id' => $feeStructureId,
                            'period_label' => $periodLabel,
                            'total_amount' => $structure['amount'],
                            'discount_amount' => 0,
                            'payable_amount' => $structure['amount'],
                            'paid_amount' => 0,
                            'status' => 'pending',
                            'due_date' => $dueDate,
                        ]);
                        $createdCount++;
                    }

                    $success[] = "Fee generation complete. Created: {$createdCount}, Skipped existing: {$skippedCount}.";
                }
            }
        }
    }

    if ($action === 'delete_structure') {
        $feeStructureId = (int)($_POST['fee_structure_id'] ?? 0);
        if ($feeStructureId <= 0) {
            $errors[] = 'Invalid fee structure delete request.';
        } else {
            try {
                $pdo->beginTransaction();
                cascade_delete_fee_structure($pdo, $feeStructureId);
                $pdo->commit();
                $success[] = 'Fee structure and related student fee records deleted.';
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to delete fee structure: ' . $throwable->getMessage();
            }
        }
    }
}

$structuresStatement = $pdo->query(
    'SELECT fs.id, fs.fee_type, fs.fee_title, fs.amount, fs.billing_cycle, fs.due_day, fs.is_active, fs.created_at,
            c.class_name, c.section, a.title AS session_title
     FROM fee_structures fs
     JOIN classes c ON c.id = fs.class_id
     JOIN academic_sessions a ON a.id = fs.session_id
     ORDER BY fs.id DESC'
);
$structures = $structuresStatement->fetchAll();

$pageTitle = 'Fee Structure';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Fee Structure</h2>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="success">
            <?php foreach ($success as $message): ?>
                <p><?= htmlspecialchars($message) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($classes)): ?>
        <p>No classes found. Create classes in Academic Setup before configuring fee structures.</p>
    <?php else: ?>
        <div class="grid-2">
            <section class="card">
                <h3>Create Fee Structure</h3>
                <form method="post" class="form-grid form-grid-wide">
                    <input type="hidden" name="action" value="create_structure">

                    <label>Particular Course (Class/Section)</label>
                    <select name="class_id" required>
                        <option value="">Select class</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= (int)$class['id'] ?>">
                                <?= htmlspecialchars($class['class_name'] . ' - ' . $class['section'] . ' (' . $class['session_title'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label>Fee Type</label>
                    <select name="fee_type" required>
                        <option value="admission_fee">Admission Fee</option>
                        <option value="caution_money">Caution Money</option>
                        <option value="tuition_fee">Tuition Fee</option>
                    </select>

                    <label>Fee Title</label>
                    <input type="text" name="fee_title" placeholder="Tuition Fee" required>

                    <label>Amount</label>
                    <input type="number" name="amount" min="1" step="0.01" required>

                    <label>Billing Cycle</label>
                    <select name="billing_cycle" required>
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="half_yearly">Half Yearly</option>
                        <option value="yearly">Yearly</option>
                        <option value="one_time">One Time</option>
                    </select>

                    <label>Due Day (optional)</label>
                    <input type="number" name="due_day" min="1" max="31" placeholder="5">

                    <button type="submit">Create Structure</button>
                </form>
            </section>

            <section class="card">
                <h3>Generate Fees for Period</h3>
                <form method="post" class="form-grid form-grid-wide">
                    <input type="hidden" name="action" value="generate_fees">

                    <label>Fee Structure</label>
                    <select name="fee_structure_id" required>
                        <option value="">Select structure</option>
                        <?php foreach ($structures as $structure): ?>
                            <option value="<?= (int)$structure['id'] ?>">
                                <?= htmlspecialchars(fee_type_label((string)$structure['fee_type']) . ' | ' . $structure['fee_title'] . ' | ' . $structure['class_name'] . '-' . $structure['section'] . ' | ' . $structure['billing_cycle']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label>Period Label</label>
                    <input type="text" name="period_label" placeholder="Apr-2026" required>

                    <label>Due Date (optional)</label>
                    <input type="date" name="due_date">

                    <button type="submit">Generate Student Fees</button>
                </form>
            </section>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <h3>Existing Fee Structures</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Title</th>
                    <th>Class</th>
                    <th>Session</th>
                    <th>Cycle</th>
                    <th>Amount</th>
                    <th>Due Day</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($structures as $structure): ?>
                    <tr>
                        <td><?= (int)$structure['id'] ?></td>
                        <td><?= htmlspecialchars(fee_type_label((string)$structure['fee_type'])) ?></td>
                        <td><?= htmlspecialchars($structure['fee_title']) ?></td>
                        <td><?= htmlspecialchars($structure['class_name'] . ' - ' . $structure['section']) ?></td>
                        <td><?= htmlspecialchars($structure['session_title']) ?></td>
                        <td><?= htmlspecialchars($structure['billing_cycle']) ?></td>
                        <td><?= number_format((float)$structure['amount'], 2) ?></td>
                        <td><?= htmlspecialchars((string)($structure['due_day'] ?? '-')) ?></td>
                        <td><span class="pill"><?= (int)$structure['is_active'] === 1 ? 'active' : 'inactive' ?></span></td>
                        <td>
                            <form method="post" class="inline-form" onsubmit="return confirm('Delete this fee structure and all generated fee/payment records?');">
                                <input type="hidden" name="action" value="delete_structure">
                                <input type="hidden" name="fee_structure_id" value="<?= (int)$structure['id'] ?>">
                                <button type="submit" class="danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($structures)): ?>
                    <tr>
                        <td colspan="10">No fee structures created yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
