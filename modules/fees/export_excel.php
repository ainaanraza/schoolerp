<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_STUDENT, ROLE_PARENT]);

function resolve_accessible_student_ids_for_export(PDO $pdo, array $user): ?array
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

function build_student_filter_clause_for_export(?array $studentIds): array
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

$user = current_user();
$filterData = build_student_filter_clause_for_export(resolve_accessible_student_ids_for_export($pdo, $user));

$feesSql =
    'SELECT sf.id, sf.period_label, sf.total_amount, sf.discount_amount, sf.payable_amount, sf.paid_amount, sf.status, sf.due_date,
            fs.fee_title, fs.billing_cycle,
            su.full_name AS student_name
     FROM student_fees sf
     JOIN fee_structures fs ON fs.id = sf.fee_structure_id
     JOIN students s ON s.id = sf.student_id
     LEFT JOIN users su ON su.id = s.user_id' .
    $filterData['clause'] .
    ' ORDER BY sf.id DESC';

$feesStatement = $pdo->prepare($feesSql);
$feesStatement->execute($filterData['params']);
$fees = $feesStatement->fetchAll();

$filename = 'finance_report_' . date('Ymd_His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);
header('Pragma: no-cache');
header('Expires: 0');

echo "<table border='1'>";
echo '<tr>';
echo '<th>Fee ID</th><th>Student</th><th>Fee Title</th><th>Billing Cycle</th><th>Period</th><th>Total Amount</th><th>Discount</th><th>Payable</th><th>Paid</th><th>Outstanding</th><th>Status</th><th>Due Date</th>';
echo '</tr>';

foreach ($fees as $fee) {
    $outstanding = max(0, (float)$fee['payable_amount'] - (float)$fee['paid_amount']);

    echo '<tr>';
    echo '<td>' . (int)$fee['id'] . '</td>';
    echo '<td>' . htmlspecialchars((string)$fee['student_name']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$fee['fee_title']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$fee['billing_cycle']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$fee['period_label']) . '</td>';
    echo '<td>' . number_format((float)$fee['total_amount'], 2, '.', '') . '</td>';
    echo '<td>' . number_format((float)$fee['discount_amount'], 2, '.', '') . '</td>';
    echo '<td>' . number_format((float)$fee['payable_amount'], 2, '.', '') . '</td>';
    echo '<td>' . number_format((float)$fee['paid_amount'], 2, '.', '') . '</td>';
    echo '<td>' . number_format($outstanding, 2, '.', '') . '</td>';
    echo '<td>' . htmlspecialchars((string)$fee['status']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$fee['due_date']) . '</td>';
    echo '</tr>';
}

echo '</table>';
exit;
