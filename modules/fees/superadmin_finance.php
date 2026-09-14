<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN]);

$errors = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust_fee') {
    $studentFeeId = (int)($_POST['student_fee_id'] ?? 0);
    $adjustmentType = 'override';
    $amount = (float)($_POST['amount'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($studentFeeId <= 0 || !in_array($adjustmentType, ['override'], true) || $amount < 0) {
        $errors[] = 'Invalid adjustment request.';
    } else {
        try {
            $pdo->beginTransaction();

            $feeStatement = $pdo->prepare('SELECT id, total_amount, discount_amount, payable_amount, paid_amount FROM student_fees WHERE id = :id FOR UPDATE');
            $feeStatement->execute(['id' => $studentFeeId]);
            $fee = $feeStatement->fetch();

            if (!$fee) {
                throw new RuntimeException('Fee record not found.');
            }

            $totalAmount = (float)$fee['total_amount'];
            $currentDiscount = (float)$fee['discount_amount'];
            $currentPaid = (float)$fee['paid_amount'];

            $newDiscount = $currentDiscount;
            $newPayable = (float)$fee['payable_amount'];

            if ($adjustmentType === 'override') {
                $newPayable = max(0, $amount);
                $newDiscount = max(0, $totalAmount - $newPayable);
            }

            if ($currentPaid > $newPayable) {
                $currentPaid = $newPayable;
            }

            $newStatus = 'pending';
            if ($newPayable <= 0 || $currentPaid >= $newPayable) {
                $newStatus = 'paid';
            } elseif ($currentPaid > 0) {
                $newStatus = 'partial';
            }

            $updateStatement = $pdo->prepare(
                'UPDATE student_fees
                 SET discount_amount = :discount_amount,
                     payable_amount = :payable_amount,
                     paid_amount = :paid_amount,
                     status = :status
                 WHERE id = :id'
            );
            $updateStatement->execute([
                'discount_amount' => $newDiscount,
                'payable_amount' => $newPayable,
                'paid_amount' => $currentPaid,
                'status' => $newStatus,
                'id' => $studentFeeId,
            ]);

            $adjustmentAmount = $adjustmentType === 'override' ? $newPayable : $amount;
            $adjustmentInsert = $pdo->prepare(
                'INSERT INTO fee_adjustments (student_fee_id, adjustment_type, amount, reason, approved_by)
                 VALUES (:student_fee_id, :adjustment_type, :amount, :reason, :approved_by)'
            );
            $adjustmentInsert->execute([
                'student_fee_id' => $studentFeeId,
                'adjustment_type' => $adjustmentType,
                'amount' => $adjustmentAmount,
                'reason' => $reason !== '' ? $reason : null,
                'approved_by' => current_user()['id'],
            ]);

            $pdo->commit();
            $success[] = 'Payable override applied successfully.';
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Adjustment failed: ' . $throwable->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_adjustment') {
    $adjustmentId = (int)($_POST['adjustment_id'] ?? 0);

    if ($adjustmentId <= 0) {
        $errors[] = 'Invalid adjustment delete request.';
    } else {
        try {
            $deleteStmt = $pdo->prepare('DELETE FROM fee_adjustments WHERE id = :id');
            $deleteStmt->execute(['id' => $adjustmentId]);
            $success[] = 'Adjustment record deleted.';
        } catch (Throwable $throwable) {
            $errors[] = 'Unable to delete adjustment: ' . $throwable->getMessage();
        }
    }
}

$summary = $pdo->query(
    'SELECT
        COUNT(*) AS total_rows,
        COALESCE(SUM(payable_amount), 0) AS total_payable,
        COALESCE(SUM(paid_amount), 0) AS total_paid,
        COALESCE(SUM(payable_amount - paid_amount), 0) AS total_pending,
        SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN status = "partial" THEN 1 ELSE 0 END) AS partial_count,
        SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) AS pending_count
     FROM student_fees'
)->fetch();

$cycleReport = $pdo->query(
    'SELECT fs.billing_cycle,
            COALESCE(SUM(sf.payable_amount), 0) AS payable,
            COALESCE(SUM(sf.paid_amount), 0) AS paid
     FROM fee_structures fs
     JOIN student_fees sf ON sf.fee_structure_id = fs.id
     GROUP BY fs.billing_cycle
     ORDER BY fs.billing_cycle'
)->fetchAll();

$adjustmentHistory = $pdo->query(
    'SELECT fa.id, fa.adjustment_type, fa.amount, fa.reason, fa.created_at,
            sf.id AS student_fee_id,
            su.full_name AS approved_by_name,
            stu.full_name AS student_name
     FROM fee_adjustments fa
     JOIN student_fees sf ON sf.id = fa.student_fee_id
     LEFT JOIN users su ON su.id = fa.approved_by
     JOIN students s ON s.id = sf.student_id
     LEFT JOIN users stu ON stu.id = s.user_id
     ORDER BY fa.id DESC
     LIMIT 50'
)->fetchAll();

$sfSearchName = trim($_GET['sf_name'] ?? '');
$sfSearchStatus = trim($_GET['sf_status'] ?? '');

$adjustableFeeSql =
    'SELECT sf.id, sf.period_label, sf.total_amount, sf.discount_amount, sf.payable_amount, sf.paid_amount, sf.status,
            fs.fee_type, fs.fee_title,
            su.full_name AS student_name,
            l.admission_concession_amount,
            l.admission_concession_note
     FROM student_fees sf
     JOIN fee_structures fs ON fs.id = sf.fee_structure_id
     JOIN students s ON s.id = sf.student_id
     LEFT JOIN users su ON su.id = s.user_id
     LEFT JOIN leads l ON l.converted_student_id = s.id
     WHERE 1=1';

$sfParams = [];
if ($sfSearchName !== '') {
    $adjustableFeeSql .= ' AND su.full_name LIKE :sf_name';
    $sfParams['sf_name'] = '%' . $sfSearchName . '%';
}
if ($sfSearchStatus !== '' && in_array($sfSearchStatus, ['paid', 'partial', 'pending'], true)) {
    $adjustableFeeSql .= ' AND sf.status = :sf_status';
    $sfParams['sf_status'] = $sfSearchStatus;
}

$adjustableFeeSql .= ' ORDER BY sf.id DESC LIMIT 200';

$sfStmt = $pdo->prepare($adjustableFeeSql);
$sfStmt->execute($sfParams);
$adjustableFees = $sfStmt->fetchAll();

$concessionFees = $pdo->query(
    'SELECT sf.id, sf.period_label, sf.payable_amount, sf.discount_amount,
            fs.fee_title, fs.fee_type,
            su.full_name AS student_name,
            l.admission_concession_amount,
            l.admission_concession_note
     FROM student_fees sf
     JOIN fee_structures fs ON fs.id = sf.fee_structure_id
     JOIN students s ON s.id = sf.student_id
     LEFT JOIN users su ON su.id = s.user_id
     LEFT JOIN leads l ON l.converted_student_id = s.id
     WHERE COALESCE(l.admission_concession_amount, 0) > 0
     ORDER BY l.id DESC, sf.id DESC
     LIMIT 50'
)->fetchAll();

$pageTitle = 'Super Admin Finance Control';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Super Admin Finance Control</h2>

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

    <div class="grid-2">
        <div class="card">
            <h3>Overall Finance Summary</h3>
            <p><strong>Total Fee Rows:</strong> <?= (int)$summary['total_rows'] ?></p>
            <p><strong>Total Payable:</strong> <?= number_format((float)$summary['total_payable'], 2) ?></p>
            <p><strong>Total Paid:</strong> <?= number_format((float)$summary['total_paid'], 2) ?></p>
            <p><strong>Total Pending:</strong> <?= number_format((float)$summary['total_pending'], 2) ?></p>
            <p><strong>Status:</strong> Paid <?= (int)$summary['paid_count'] ?> | Partial <?= (int)$summary['partial_count'] ?> | Pending <?= (int)$summary['pending_count'] ?></p>
        </div>

        <div class="card">
            <h3>Billing Cycle Report</h3>
            <ul>
                <?php foreach ($cycleReport as $cycle): ?>
                    <li>
                        <?= htmlspecialchars($cycle['billing_cycle']) ?>:
                        Payable <?= number_format((float)$cycle['payable'], 2) ?>,
                        Paid <?= number_format((float)$cycle['paid'], 2) ?>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($cycleReport)): ?>
                    <li>No cycle data available.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</section>

<section class="card">
    <div class="form-header-actions">
        <h3>Finance Activity</h3>
        <button type="button" class="btn-toggle-form" onclick="toggleForm('form-adjustment', this)">+ Set Payable Override</button>
    </div>

    <div class="finance-tabs" role="tablist" aria-label="Finance activity tabs">
        <button type="button" class="finance-tab active" data-finance-tab="tab-adjustments" onclick="switchFinanceTab('tab-adjustments')">
            Recent Adjustments
            <span class="finance-tab-count"><?= count($adjustmentHistory) ?></span>
        </button>
        <button type="button" class="finance-tab" data-finance-tab="tab-concessions" onclick="switchFinanceTab('tab-concessions')">
            Admission Concessions On File
            <span class="finance-tab-count"><?= count($concessionFees) ?></span>
        </button>
    </div>

    <div id="tab-adjustments" class="finance-tab-panel active">
        <div class="table-wrap" id="section-table">
            <table class="compact-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Student</th>
                        <th>Fee Row</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Reason</th>
                        <th>Approved By</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($adjustmentHistory as $row): ?>
                        <tr>
                            <td><?= (int)$row['id'] ?></td>
                            <td><?= htmlspecialchars((string)$row['student_name']) ?></td>
                            <td><?= (int)$row['student_fee_id'] ?></td>
                            <td><?= htmlspecialchars($row['adjustment_type']) ?></td>
                            <td><?= number_format((float)$row['amount'], 2) ?></td>
                            <td><?= htmlspecialchars((string)$row['reason']) ?></td>
                            <td><?= htmlspecialchars((string)$row['approved_by_name']) ?></td>
                            <td><?= htmlspecialchars((string)$row['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($adjustmentHistory)): ?>
                        <tr>
                            <td colspan="8">No adjustments made yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="tab-concessions" class="finance-tab-panel">
        <div class="table-wrap">
            <table class="compact-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Fee</th>
                        <th>Period</th>
                        <th>Concession</th>
                        <th>Note</th>
                        <th>Payable</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($concessionFees as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$row['student_name']) ?></td>
                            <td><?= htmlspecialchars((string)$row['fee_title']) ?></td>
                            <td><?= htmlspecialchars((string)$row['period_label']) ?></td>
                            <td>₹<?= number_format((float)$row['admission_concession_amount'], 2) ?></td>
                            <td><?= htmlspecialchars((string)$row['admission_concession_note']) ?></td>
                            <td>₹<?= number_format((float)$row['payable_amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($concessionFees)): ?>
                        <tr>
                            <td colspan="6">No admission concessions found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div id="form-adjustment" class="collapsible-form">
    <section class="card">
        <h3>Payable Override (Super Admin Only)</h3>
        <form method="post" class="form-grid form-grid-wide">
            <input type="hidden" name="action" value="adjust_fee">
            <input type="hidden" name="adjustment_type" value="override">

            <label>Student Fee Record</label>
            <select name="student_fee_id" required>
                <option value="">Select fee record</option>
                <?php foreach ($adjustableFees as $fee): ?>
                    <option value="<?= (int)$fee['id'] ?>">
                        <?= htmlspecialchars('#' . $fee['id'] . ' | ' . $fee['student_name'] . ' | ' . $fee['fee_title'] . ' | ' . ucwords(str_replace('_', ' ', (string)$fee['fee_type'])) . ' | ' . $fee['period_label'] . ' | payable ' . number_format((float)$fee['payable_amount'], 2) . ((float)($fee['admission_concession_amount'] ?? 0) > 0 ? ' | concession ₹' . number_format((float)$fee['admission_concession_amount'], 2) . (!empty($fee['admission_concession_note']) ? ' | ' . $fee['admission_concession_note'] : '') : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Final Payable Amount</label>
            <input type="number" name="amount" min="0" step="0.01" required>

            <label>Reason</label>
            <input type="text" name="reason" placeholder="Scholarship / correction / special approval">

            <button type="submit">Apply Override</button>
        </form>
    </section>
</div>

<script>
function switchFinanceTab(tabId) {
    document.querySelectorAll('.finance-tab').forEach(function (tab) {
        tab.classList.remove('active');
    });
    document.querySelectorAll('.finance-tab-panel').forEach(function (panel) {
        panel.classList.remove('active');
    });

    var selectedTab = document.querySelector('.finance-tab[data-finance-tab="' + tabId + '"]');
    var selectedPanel = document.getElementById(tabId);
    if (selectedTab) {
        selectedTab.classList.add('active');
    }
    if (selectedPanel) {
        selectedPanel.classList.add('active');
    }
}
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
