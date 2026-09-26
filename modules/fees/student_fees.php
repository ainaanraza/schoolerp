<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_STUDENT, ROLE_PARENT]);

function resolve_accessible_student_ids(PDO $pdo, array $user): ?array
{
    if (in_array($user['role'], [ROLE_SUPER_ADMIN, ROLE_ADMIN], true)) {
        return null;
    }

    if ($user['role'] === ROLE_STUDENT) {
        $statement = $pdo->prepare('SELECT id FROM students WHERE user_id = :user_id');
        $statement->execute(['user_id' => $user['id']]);
        return array_map(static fn(array $row): int => (int)$row['id'], $statement->fetchAll());
    }

    if ($user['role'] === ROLE_PARENT) {
        $statement = $pdo->prepare(
            'SELECT ps.student_id
             FROM parents p
             JOIN parent_student ps ON ps.parent_id = p.id
             WHERE p.user_id = :user_id'
        );
        $statement->execute(['user_id' => $user['id']]);
        return array_map(static fn(array $row): int => (int)$row['student_id'], $statement->fetchAll());
    }

    return [];
}

function build_student_filter_clause(?array $studentIds): array
{
    if ($studentIds === null) {
        return ['clause' => '', 'params' => []];
    }

    if (empty($studentIds)) {
        return ['clause' => ' WHERE 1 = 0 ', 'params' => []];
    }

    $placeholders = [];
    $params = [];
    foreach ($studentIds as $index => $studentId) {
        $key = 'student_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $studentId;
    }

    return [
        'clause' => ' WHERE sf.student_id IN (' . implode(', ', $placeholders) . ') ',
        'params' => $params,
    ];
}

function fee_type_priority(string $feeType): int
{
    return match ($feeType) {
        'caution_money' => 0,
        'admission_fee' => 1,
        'tuition_fee' => 2,
        default => 3,
    };
}

function fee_type_label(string $feeType): string
{
    return match ($feeType) {
        'caution_money' => 'Caution Money',
        'admission_fee' => 'Admission Fee',
        'tuition_fee' => 'Tuition Fee',
        default => ucfirst(str_replace('_', ' ', $feeType)),
    };
}

function fee_status_for_amount(float $payableAmount, float $paidAmount): string
{
    if ($payableAmount <= 0 || $paidAmount >= $payableAmount) {
        return 'paid';
    }

    if ($paidAmount > 0 && $paidAmount < $payableAmount) {
        return 'partial';
    }

    return 'pending';
}

function summary_status_label(string $status): string
{
    return match ($status) {
        'paid' => 'Paid',
        'partial' => 'Partial',
        default => 'Pending',
    };
}

$errors = [];
$success = [];
$user = current_user();
$isSuperAdmin = $user['role'] === ROLE_SUPER_ADMIN;
$isFinanceEditor = in_array($user['role'], [ROLE_SUPER_ADMIN, ROLE_ADMIN], true);
$accessibleStudentIds = resolve_accessible_student_ids($pdo, $user);
$filterData = build_student_filter_clause($accessibleStudentIds);

if (isset($_GET['imported']) && $isFinanceEditor) {
    $imported = (int)($_GET['imported'] ?? 0);
    $failed = (int)($_GET['failed'] ?? 0);
    $skipped = (int)($_GET['skipped'] ?? 0);
    $success[] = 'Fees import completed. Processed: ' . $imported . ', Skipped: ' . $skipped . ', Failed: ' . $failed . '.';
}
if (isset($_GET['import_error']) && $isFinanceEditor) {
    $errors[] = (string)$_GET['import_error'];
}

if (isset($_GET['payment']) && $_GET['payment'] === 'success') {
    $success[] = 'Payment posted successfully.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_student_fee') {
    if (!$isFinanceEditor) {
        $errors[] = 'Only admin can update fee status.';
    }

    $studentId = (int)($_POST['student_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $method = $_POST['method'] ?? 'cash';
    $allowedMethods = ['cash', 'card', 'upi', 'bank_transfer'];

    if (empty($errors) && ($studentId <= 0 || $amount <= 0 || !in_array($method, $allowedMethods, true))) {
        $errors[] = 'Invalid payment request.';
    } elseif (empty($errors)) {
        $accessParams = $filterData['params'];
        $targetSql =
            'SELECT sf.id, sf.student_id, sf.payable_amount, sf.paid_amount, fs.fee_type, fs.billing_cycle, sf.period_label
             FROM student_fees sf
             JOIN fee_structures fs ON fs.id = sf.fee_structure_id' .
            $filterData['clause'] .
            ($filterData['clause'] === '' ? ' WHERE ' : ' AND ') .
            'sf.student_id = :student_id
             ORDER BY CASE fs.fee_type WHEN "caution_money" THEN 0 WHEN "admission_fee" THEN 1 WHEN "tuition_fee" THEN 2 ELSE 3 END,
                      CASE WHEN fs.billing_cycle = "one_time" THEN 0 ELSE 1 END,
                      sf.due_date IS NULL,
                      sf.due_date,
                      sf.id
             FOR UPDATE';

        $targetParams = $accessParams;
        $targetParams['student_id'] = $studentId;

        try {
            $pdo->beginTransaction();

            $targetStatement = $pdo->prepare($targetSql);
            $targetStatement->execute($targetParams);
            $feeRows = $targetStatement->fetchAll();

            if (empty($feeRows)) {
                throw new RuntimeException('Fee records not found or access denied.');
            }

            $totalOutstanding = 0.0;
            foreach ($feeRows as $feeRow) {
                $totalOutstanding += max(0, (float)$feeRow['payable_amount'] - (float)$feeRow['paid_amount']);
            }

            if ($amount > $totalOutstanding) {
                throw new RuntimeException('Amount cannot exceed outstanding balance of ' . number_format($totalOutstanding, 2) . '.');
            }

            $remaining = $amount;
            $insertPayment = $pdo->prepare(
                'INSERT INTO payments (student_fee_id, amount, payment_date, method, transaction_ref, status, received_by)
                 VALUES (:student_fee_id, :amount, NOW(), :method, :transaction_ref, :status, :received_by)'
            );
            $updateFee = $pdo->prepare(
                'UPDATE student_fees
                 SET paid_amount = :paid_amount,
                     status = :status
                 WHERE id = :id'
            );

            $transactionRef = 'PAY' . date('YmdHis') . random_int(1000, 9999);

            foreach ($feeRows as $feeRow) {
                if ($remaining <= 0) {
                    break;
                }

                $payable = (float)$feeRow['payable_amount'];
                $paid = (float)$feeRow['paid_amount'];
                $outstanding = max(0, $payable - $paid);
                if ($outstanding <= 0) {
                    continue;
                }

                $applied = min($remaining, $outstanding);
                $newPaid = $paid + $applied;
                $newStatus = fee_status_for_amount($payable, $newPaid);

                $insertPayment->execute([
                    'student_fee_id' => (int)$feeRow['id'],
                    'amount' => $applied,
                    'method' => $method,
                    'transaction_ref' => $transactionRef,
                    'status' => 'success',
                    'received_by' => in_array($user['role'], [ROLE_SUPER_ADMIN, ROLE_ADMIN], true) ? $user['id'] : null,
                ]);

                $updateFee->execute([
                    'paid_amount' => $newPaid,
                    'status' => $newStatus,
                    'id' => (int)$feeRow['id'],
                ]);

                $remaining -= $applied;
            }

            $pdo->commit();
            $success[] = 'Payment posted and allocated across fees in priority order.';
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Payment failed: ' . $throwable->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_concession') {
    if (!$isSuperAdmin) {
        $errors[] = 'Only superadmin can apply concession.';
    }

    $studentId = (int)($_POST['student_id'] ?? 0);
    $concessionAmount = (float)($_POST['concession_amount'] ?? 0);
    $concessionNote = trim((string)($_POST['concession_note'] ?? ''));

    if (empty($errors) && $studentId <= 0) {
        $errors[] = 'Invalid concession request.';
    }
    if (empty($errors) && $concessionAmount <= 0) {
        $errors[] = 'Concession amount must be greater than zero.';
    }
    if (empty($errors) && $concessionNote === '') {
        $errors[] = 'Please add a concession note.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $feeRowsStmt = $pdo->prepare(
                'SELECT sf.id, sf.total_amount, sf.discount_amount, sf.payable_amount, sf.paid_amount,
                        fs.fee_type, fs.billing_cycle
                 FROM student_fees sf
                 JOIN fee_structures fs ON fs.id = sf.fee_structure_id
                 WHERE sf.student_id = :student_id
                 ORDER BY CASE fs.fee_type WHEN "caution_money" THEN 0 WHEN "admission_fee" THEN 1 WHEN "tuition_fee" THEN 2 ELSE 3 END,
                          CASE WHEN fs.billing_cycle = "one_time" THEN 0 ELSE 1 END,
                          sf.id
                 FOR UPDATE'
            );
            $feeRowsStmt->execute(['student_id' => $studentId]);
            $feeRows = $feeRowsStmt->fetchAll();

            if (empty($feeRows)) {
                throw new RuntimeException('No fee records found for this student.');
            }

            $totalDiscountable = 0.0;
            foreach ($feeRows as $feeRow) {
                $totalAmount = (float)$feeRow['total_amount'];
                $currentDiscount = (float)$feeRow['discount_amount'];
                $currentPaid = (float)$feeRow['paid_amount'];
                $totalDiscountable += max(0, $totalAmount - $currentDiscount - $currentPaid);
            }

            if ($totalDiscountable <= 0) {
                throw new RuntimeException('No outstanding amount available for concession.');
            }

            if ($concessionAmount > $totalDiscountable) {
                throw new RuntimeException('Concession cannot exceed outstanding amount of ₹' . number_format($totalDiscountable, 2) . '.');
            }

            $updateFeeStmt = $pdo->prepare(
                'UPDATE student_fees
                 SET discount_amount = :discount_amount,
                     payable_amount = :payable_amount,
                     status = :status
                 WHERE id = :id'
            );
            $insertAdjustmentStmt = $pdo->prepare(
                'INSERT INTO fee_adjustments (student_fee_id, adjustment_type, amount, reason, approved_by)
                 VALUES (:student_fee_id, :adjustment_type, :amount, :reason, :approved_by)'
            );

            $remainingConcession = $concessionAmount;
            foreach ($feeRows as $feeRow) {
                if ($remainingConcession <= 0) {
                    break;
                }

                $totalAmount = (float)$feeRow['total_amount'];
                $currentDiscount = (float)$feeRow['discount_amount'];
                $currentPaid = (float)$feeRow['paid_amount'];
                $discountableAmount = max(0, $totalAmount - $currentDiscount - $currentPaid);

                if ($discountableAmount <= 0) {
                    continue;
                }

                $appliedConcession = min($remainingConcession, $discountableAmount);
                $newDiscount = $currentDiscount + $appliedConcession;
                $newPayable = max($currentPaid, $totalAmount - $newDiscount);

                $updateFeeStmt->execute([
                    'discount_amount' => $newDiscount,
                    'payable_amount' => $newPayable,
                    'status' => fee_status_for_amount($newPayable, $currentPaid),
                    'id' => (int)$feeRow['id'],
                ]);

                $insertAdjustmentStmt->execute([
                    'student_fee_id' => (int)$feeRow['id'],
                    'adjustment_type' => 'discount',
                    'amount' => $appliedConcession,
                    'reason' => $concessionNote,
                    'approved_by' => (int)$user['id'],
                ]);

                $remainingConcession -= $appliedConcession;
            }

            $pdo->commit();
            $success[] = 'Concession applied successfully.';
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Unable to apply concession: ' . $throwable->getMessage();
        }
    }
}

$searchName = trim($_GET['search_name'] ?? '');
$searchStatus = trim($_GET['search_status'] ?? '');
$searchFee = trim($_GET['search_fee'] ?? '');
$searchDueBefore = trim($_GET['due_before'] ?? '');
$searchDueAfter = trim($_GET['due_after'] ?? '');

$extraWhere = [];
$extraParams = [];

if ($searchName !== '') {
    $extraWhere[] = 'su.full_name LIKE :search_name';
    $extraParams['search_name'] = '%' . $searchName . '%';
}
if ($searchStatus !== '' && in_array($searchStatus, ['paid', 'partial', 'pending'], true)) {
    $extraWhere[] = 'sf.status = :search_status';
    $extraParams['search_status'] = $searchStatus;
}
if ($searchFee !== '') {
    $extraWhere[] = '(fs.fee_title LIKE :search_fee OR fs.fee_type LIKE :search_fee)';
    $extraParams['search_fee'] = '%' . $searchFee . '%';
}
if ($searchDueAfter !== '') {
    $extraWhere[] = 'sf.due_date >= :due_after';
    $extraParams['due_after'] = $searchDueAfter;
}
if ($searchDueBefore !== '') {
    $extraWhere[] = 'sf.due_date <= :due_before';
    $extraParams['due_before'] = $searchDueBefore;
}

$feesSql =
    'SELECT sf.id, sf.student_id, sf.period_label, sf.total_amount, sf.discount_amount, sf.payable_amount, sf.paid_amount, sf.status, sf.due_date,
            fs.fee_type, fs.fee_title, fs.billing_cycle,
            su.full_name AS student_name, s.admission_no
     FROM student_fees sf
     JOIN fee_structures fs ON fs.id = sf.fee_structure_id
     JOIN students s ON s.id = sf.student_id
     LEFT JOIN users su ON su.id = s.user_id' .
    $filterData['clause'];

if (!empty($extraWhere)) {
    $feesSql .= ($filterData['clause'] === '' ? ' WHERE ' : ' AND ') . implode(' AND ', $extraWhere);
}

$feesSql .= ' ORDER BY su.full_name, sf.student_id, CASE fs.fee_type WHEN "caution_money" THEN 0 WHEN "admission_fee" THEN 1 WHEN "tuition_fee" THEN 2 ELSE 3 END, sf.due_date IS NULL, sf.due_date, sf.id';

$allParams = array_merge($filterData['params'], $extraParams);
$feesStatement = $pdo->prepare($feesSql);
$feesStatement->execute($allParams);
$fees = $feesStatement->fetchAll();

$latestConcessionByStudent = [];
if ($isSuperAdmin) {
    $latestConcessionRows = $pdo->query(
        'SELECT latest.student_id, fa.reason, fa.created_at
         FROM fee_adjustments fa
         JOIN student_fees sf ON sf.id = fa.student_fee_id
         JOIN (
             SELECT sf2.student_id, MAX(fa2.id) AS latest_adjustment_id
             FROM fee_adjustments fa2
             JOIN student_fees sf2 ON sf2.id = fa2.student_fee_id
             WHERE fa2.adjustment_type = "discount"
             GROUP BY sf2.student_id
         ) latest ON latest.latest_adjustment_id = fa.id AND latest.student_id = sf.student_id
         WHERE fa.adjustment_type = "discount"'
    )->fetchAll();

    foreach ($latestConcessionRows as $row) {
        $latestConcessionByStudent[(int)$row['student_id']] = [
            'note' => trim((string)($row['reason'] ?? '')),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }
}

$studentSummaries = [];
foreach ($fees as $fee) {
    $studentId = (int)$fee['student_id'];
    $feeType = (string)$fee['fee_type'];

    if (!isset($studentSummaries[$studentId])) {
        $studentSummaries[$studentId] = [
            'student_id' => $studentId,
            'student_name' => (string)$fee['student_name'],
            'admission_no' => (string)$fee['admission_no'],
            'payable' => 0.0,
            'paid' => 0.0,
            'outstanding' => 0.0,
            'concession_total' => 0.0,
            'concession_note' => '',
            'concession_updated_at' => '',
            'rows' => [],
            'fee_types' => [
                'caution_money' => ['payable' => 0.0, 'paid' => 0.0],
                'admission_fee' => ['payable' => 0.0, 'paid' => 0.0],
                'tuition_fee' => ['payable' => 0.0, 'paid' => 0.0],
            ],
        ];
    }

    $payable = (float)$fee['payable_amount'];
    $paid = (float)$fee['paid_amount'];
    $outstanding = max(0, $payable - $paid);

    $studentSummaries[$studentId]['payable'] += $payable;
    $studentSummaries[$studentId]['paid'] += $paid;
    $studentSummaries[$studentId]['outstanding'] += $outstanding;
    $studentSummaries[$studentId]['concession_total'] += (float)$fee['discount_amount'];
    $studentSummaries[$studentId]['rows'][] = $fee;

    if (!isset($studentSummaries[$studentId]['fee_types'][$feeType])) {
        $studentSummaries[$studentId]['fee_types'][$feeType] = ['payable' => 0.0, 'paid' => 0.0];
    }

    $studentSummaries[$studentId]['fee_types'][$feeType]['payable'] += $payable;
    $studentSummaries[$studentId]['fee_types'][$feeType]['paid'] += $paid;
}

foreach ($studentSummaries as &$summaryRow) {
    if (isset($latestConcessionByStudent[(int)$summaryRow['student_id']])) {
        $summaryRow['concession_note'] = $latestConcessionByStudent[(int)$summaryRow['student_id']]['note'];
        $summaryRow['concession_updated_at'] = $latestConcessionByStudent[(int)$summaryRow['student_id']]['created_at'];
    }

    foreach ($summaryRow['fee_types'] as $feeType => &$bucket) {
        $bucket['outstanding'] = max(0, $bucket['payable'] - $bucket['paid']);
        $bucket['status'] = fee_status_for_amount($bucket['payable'], $bucket['paid']);
    }
    unset($bucket);
}
unset($summaryRow);

$summaryRows = array_values($studentSummaries);

$pageTitle = 'Student Fees';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Fees Summary</h2>
    <p>Payments are allocated in this order: caution money, admission fee, then tuition fee.</p>

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

    <?php if (in_array(current_role(), [ROLE_SUPER_ADMIN, ROLE_ADMIN], true)): ?>
    <div class="toolbar-row">
        <form method="get" class="filter-bar filter-bar-inline" id="feeFilterForm">
            <input type="text" name="search_name" placeholder="Student name" value="<?= htmlspecialchars($searchName) ?>">
            <select name="search_status">
                <option value="">All Statuses</option>
                <option value="paid" <?= $searchStatus === 'paid' ? 'selected' : '' ?>>Paid</option>
                <option value="partial" <?= $searchStatus === 'partial' ? 'selected' : '' ?>>Partial</option>
                <option value="pending" <?= $searchStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
            </select>
            <input type="text" name="search_fee" placeholder="Fee title or type" value="<?= htmlspecialchars($searchFee) ?>">
            <input type="date" name="due_after" title="Due from" value="<?= htmlspecialchars($searchDueAfter) ?>">
            <input type="date" name="due_before" title="Due until" value="<?= htmlspecialchars($searchDueBefore) ?>">
            <button type="submit">Filter</button>
            <a href="<?= strtok($_SERVER['REQUEST_URI'], '?') ?>" class="toolbar-link-clear">Clear</a>
        </form>

        <div class="toolbar-actions-tight">
            <a class="nav-item" href="/itierp/modules/fees/export_excel.php">Download Excel</a>
        </div>
    </div>
    <?php endif; ?>

    <div class="table-wrap" id="section-table">
        <table class="compact-table fees-summary-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Caution</th>
                    <th>Admission</th>
                    <th>Tuition</th>
                    <th>Payable</th>
                    <th>Paid</th>
                    <th>Outstanding</th>
                    <th>Status</th>
                    <?php if ($isSuperAdmin): ?>
                        <th>Concession</th>
                    <?php endif; ?>
                    <th>Update</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaryRows as $summaryRow): ?>
                    <?php
                        $overallStatus = 'paid';
                        if ($summaryRow['outstanding'] > 0 && $summaryRow['paid'] > 0) {
                            $overallStatus = 'partial';
                        } elseif ($summaryRow['outstanding'] > 0) {
                            $overallStatus = 'pending';
                        }
                    ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($summaryRow['student_name']) ?><br>
                            <small><?= htmlspecialchars($summaryRow['admission_no']) ?></small>
                        </td>
                        <?php foreach (['caution_money', 'admission_fee', 'tuition_fee'] as $feeType): ?>
                            <?php $bucket = $summaryRow['fee_types'][$feeType] ?? ['payable' => 0, 'paid' => 0, 'outstanding' => 0, 'status' => 'pending']; ?>
                            <td>
                                <strong><?= number_format((float)$bucket['paid'], 2) ?></strong> / <?= number_format((float)$bucket['payable'], 2) ?><br>
                                <small><?= htmlspecialchars(summary_status_label((string)$bucket['status'])) ?></small>
                            </td>
                        <?php endforeach; ?>
                        <td><?= number_format((float)$summaryRow['payable'], 2) ?></td>
                        <td><?= number_format((float)$summaryRow['paid'], 2) ?></td>
                        <td><?= number_format((float)$summaryRow['outstanding'], 2) ?></td>
                        <td><span class="pill"><?= htmlspecialchars(summary_status_label($overallStatus)) ?></span></td>
                        <?php if ($isSuperAdmin): ?>
                            <td>
                                <?php if ((float)$summaryRow['concession_total'] > 0): ?>
                                    <small>₹<?= number_format((float)$summaryRow['concession_total'], 2) ?></small><br>
                                    <small><?= htmlspecialchars((string)$summaryRow['concession_note']) ?></small>
                                <?php else: ?>
                                    <small>No concession</small>
                                <?php endif; ?>
                                <div class="concession-trigger-wrap">
                                    <button
                                        type="button"
                                        class="btn-concession-slim"
                                        data-student-id="<?= (int)$summaryRow['student_id'] ?>"
                                        data-student-name="<?= htmlspecialchars((string)$summaryRow['student_name'], ENT_QUOTES) ?>"
                                        data-admission-no="<?= htmlspecialchars((string)$summaryRow['admission_no'], ENT_QUOTES) ?>"
                                        data-current-note="<?= htmlspecialchars((string)$summaryRow['concession_note'], ENT_QUOTES) ?>"
                                        onclick="openFeeConcessionModal(this)">
                                        Give Concession
                                    </button>
                                </div>
                            </td>
                        <?php endif; ?>
                        <td>
                            <?php if (!$isFinanceEditor): ?>
                                <span>Admin</span>
                            <?php elseif ($summaryRow['outstanding'] > 0): ?>
                                <form method="post" class="inline-form fees-update-form">
                                    <input type="hidden" name="action" value="pay_student_fee">
                                    <input type="hidden" name="student_id" value="<?= (int)$summaryRow['student_id'] ?>">

                                    <input
                                        type="number"
                                        name="amount"
                                        min="0.01"
                                        max="<?= htmlspecialchars((string)$summaryRow['outstanding']) ?>"
                                        step="0.01"
                                        value="<?= htmlspecialchars((string)$summaryRow['outstanding']) ?>"
                                        required
                                    >

                                    <select name="method">
                                        <option value="cash">Cash</option>
                                        <option value="card">Card</option>
                                        <option value="upi">UPI</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                    </select>

                                    <button type="submit">Pay</button>
                                </form>
                            <?php else: ?>
                                <span>Done</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($summaryRows)): ?>
                    <tr>
                        <td colspan="<?= $isSuperAdmin ? '10' : '9' ?>">No fee records found for your account.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($isSuperAdmin): ?>
<div id="form-fee-concession" class="collapsible-form">
    <section class="card">
        <h3>Give Concession</h3>
        <p id="feeConcessionStudentLabel" class="concession-lead-label"></p>
        <form method="post" class="form-grid form-grid-wide">
            <input type="hidden" name="action" value="apply_concession">
            <input type="hidden" name="student_id" value="">

            <label>Concession Amount</label>
            <input type="number" name="concession_amount" min="0.01" step="0.01" required>

            <label>Concession Note</label>
            <textarea name="concession_note" rows="4" placeholder="Reason for concession" required></textarea>

            <button type="submit">Apply</button>
        </form>
    </section>
</div>

<script>
function openFeeConcessionModal(button) {
    var modal = document.getElementById('form-fee-concession');
    var overlay = document.getElementById('modal-overlay');
    var label = document.getElementById('feeConcessionStudentLabel');
    var studentIdInput = modal ? modal.querySelector('input[name="student_id"]') : null;
    var amountInput = modal ? modal.querySelector('input[name="concession_amount"]') : null;
    var noteInput = modal ? modal.querySelector('textarea[name="concession_note"]') : null;

    if (!modal || !overlay || !button) {
        return;
    }

    if (studentIdInput) {
        studentIdInput.value = button.dataset.studentId || '';
    }
    if (amountInput) {
        amountInput.value = '';
    }
    if (noteInput) {
        noteInput.value = button.dataset.currentNote || '';
    }
    if (label) {
        var studentName = button.dataset.studentName || 'Selected student';
        var admissionNo = button.dataset.admissionNo || '';
        label.textContent = admissionNo ? (studentName + ' | ' + admissionNo) : studentName;
    }

    document.querySelectorAll('.collapsible-form.active').forEach(function(form) {
        form.classList.remove('active');
    });

    modal.classList.add('active');
    overlay.classList.add('active');
    document.body.classList.add('modal-open');

    if (!modal.querySelector('.modal-close-btn')) {
        var closeBtn = document.createElement('span');
        closeBtn.className = 'modal-close-btn';
        closeBtn.innerHTML = '&times;';
        closeBtn.onclick = function() {
            modal.classList.remove('active');
            overlay.classList.remove('active');
            document.body.classList.remove('modal-open');
        };
        modal.prepend(closeBtn);
    }
}
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
