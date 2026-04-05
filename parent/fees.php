<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_roles([ROLE_PARENT]);

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

$userId = (int)current_user()['id'];
$selectedStudentId = (int)($_GET['student_id'] ?? 0);

$childrenStatement = $pdo->prepare(
    'SELECT s.id, s.admission_no, su.full_name AS student_name
     FROM parents p
     JOIN parent_student ps ON ps.parent_id = p.id
     JOIN students s ON s.id = ps.student_id
     LEFT JOIN users su ON su.id = s.user_id
     WHERE p.user_id = :user_id
     ORDER BY su.full_name'
);
$childrenStatement->execute(['user_id' => $userId]);
$children = $childrenStatement->fetchAll();
$childIds = array_map(static fn(array $row): int => (int)$row['id'], $children);

$errors = [];
if ($selectedStudentId > 0 && !in_array($selectedStudentId, $childIds, true)) {
    $errors[] = 'Invalid child selected.';
}

$summaryRows = [];
if (!empty($childIds) && empty($errors)) {
    $placeholders = [];
    $params = [];
    foreach ($childIds as $index => $childId) {
        $key = 'child_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $childId;
    }

    $sql =
        'SELECT sf.id, sf.student_id, sf.period_label, sf.payable_amount, sf.paid_amount, sf.status, sf.due_date,
                fs.fee_type, fs.fee_title, fs.billing_cycle,
                su.full_name AS student_name, s.admission_no
         FROM student_fees sf
         JOIN fee_structures fs ON fs.id = sf.fee_structure_id
         JOIN students s ON s.id = sf.student_id
         LEFT JOIN users su ON su.id = s.user_id
         WHERE sf.student_id IN (' . implode(', ', $placeholders) . ')';

    if ($selectedStudentId > 0) {
        $sql .= ' AND sf.student_id = :selected_student_id';
        $params['selected_student_id'] = $selectedStudentId;
    }

    $sql .= ' ORDER BY su.full_name, sf.student_id, CASE fs.fee_type WHEN "caution_money" THEN 0 WHEN "admission_fee" THEN 1 WHEN "tuition_fee" THEN 2 ELSE 3 END, sf.due_date IS NULL, sf.due_date, sf.id';

    $feesStatement = $pdo->prepare($sql);
    $feesStatement->execute($params);
    $fees = $feesStatement->fetchAll();

    foreach ($fees as $fee) {
        $studentId = (int)$fee['student_id'];
        $feeType = (string)$fee['fee_type'];

        if (!isset($summaryRows[$studentId])) {
            $summaryRows[$studentId] = [
                'student_id' => $studentId,
                'student_name' => (string)$fee['student_name'],
                'admission_no' => (string)$fee['admission_no'],
                'payable' => 0.0,
                'paid' => 0.0,
                'outstanding' => 0.0,
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

        $summaryRows[$studentId]['payable'] += $payable;
        $summaryRows[$studentId]['paid'] += $paid;
        $summaryRows[$studentId]['outstanding'] += $outstanding;

        if (!isset($summaryRows[$studentId]['fee_types'][$feeType])) {
            $summaryRows[$studentId]['fee_types'][$feeType] = ['payable' => 0.0, 'paid' => 0.0];
        }

        $summaryRows[$studentId]['fee_types'][$feeType]['payable'] += $payable;
        $summaryRows[$studentId]['fee_types'][$feeType]['paid'] += $paid;
    }

    foreach ($summaryRows as &$summaryRow) {
        foreach ($summaryRow['fee_types'] as $feeType => &$bucket) {
            $bucket['outstanding'] = max(0, $bucket['payable'] - $bucket['paid']);
            $bucket['status'] = fee_status_for_amount($bucket['payable'], $bucket['paid']);
        }
        unset($bucket);
    }
    unset($summaryRow);
}

$pageTitle = 'Fee Payments';
require __DIR__ . '/../includes/header.php';
?>
<section class="card">
    <h2>Fee Payments</h2>
    <p>Each child is shown as a single summary row with caution money, admission fee, and tuition fee status.</p>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="get" class="form-grid form-grid-wide">
        <label>Child</label>
        <select name="student_id">
            <option value="0">All children</option>
            <?php foreach ($children as $child): ?>
                <option value="<?= (int)$child['id'] ?>" <?= $selectedStudentId === (int)$child['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)$child['student_name'] . ' (' . (string)$child['admission_no'] . ')') ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit">Apply Filter</button>
    </form>

    <div class="metrics-grid">
        <div class="metric-card"><p class="metric-label">Total Payable</p><p class="metric-value">₹<?= number_format(array_sum(array_map(static fn(array $row): float => (float)$row['payable'], $summaryRows)), 2) ?></p></div>
        <div class="metric-card"><p class="metric-label">Total Paid</p><p class="metric-value">₹<?= number_format(array_sum(array_map(static fn(array $row): float => (float)$row['paid'], $summaryRows)), 2) ?></p></div>
        <div class="metric-card"><p class="metric-label">Outstanding</p><p class="metric-value">₹<?= number_format(array_sum(array_map(static fn(array $row): float => (float)$row['outstanding'], $summaryRows)), 2) ?></p></div>
    </div>

    <p><a href="/school-erp/modules/fees/student_fees.php">Open detailed fee summary</a></p>

    <div class="table-wrap" id="section-table">
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Caution Money</th>
                    <th>Admission Fee</th>
                    <th>Tuition Fee</th>
                    <th>Total Payable</th>
                    <th>Total Paid</th>
                    <th>Outstanding</th>
                    <th>Status</th>
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
                        <td>₹<?= number_format((float)$summaryRow['payable'], 2) ?></td>
                        <td>₹<?= number_format((float)$summaryRow['paid'], 2) ?></td>
                        <td>₹<?= number_format((float)$summaryRow['outstanding'], 2) ?></td>
                        <td><span class="pill"><?= htmlspecialchars(summary_status_label($overallStatus)) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($summaryRows)): ?>
                    <tr><td colspan="8">No fee records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
