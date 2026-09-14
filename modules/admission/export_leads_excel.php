<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$searchName = trim($_GET['search_name'] ?? '');
$searchStatus = trim($_GET['search_status'] ?? '');

$sql = 'SELECT id, lead_name, guardian_name, guardian_phone, guardian_email, email, phone, class_applied, status, admission_fee_amount, admission_concession_amount, admission_concession_note, payment_mode, source, created_at FROM leads';
$params = [];
$whereClauses = [];

if ($searchName !== '') {
    $whereClauses[] = '(lead_name LIKE :search_name OR guardian_name LIKE :search_name OR guardian_phone LIKE :search_name OR email LIKE :search_name)';
    $params['search_name'] = '%' . $searchName . '%';
}
if ($searchStatus !== '') {
    $whereClauses[] = 'status = :status';
    $params['status'] = $searchStatus;
}

if (!empty($whereClauses)) {
    $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
}

$sql .= ' ORDER BY created_at DESC, id DESC';

$statement = $pdo->prepare($sql);
$statement->execute($params);
$rows = $statement->fetchAll();

$filename = 'leads_report_' . date('Ymd_His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);
header('Pragma: no-cache');
header('Expires: 0');

echo "<table border='1'>";
echo '<tr>';
echo '<th>ID</th><th>Lead Name</th><th>Guardian Name</th><th>Guardian Phone</th><th>Guardian Email</th><th>Email</th><th>Phone</th><th>Course Applied</th><th>Status</th><th>Admission Fee Amount</th><th>Concession Amount</th><th>Concession Note</th><th>Payment Mode</th><th>Source</th><th>Created At</th>';
echo '</tr>';

foreach ($rows as $row) {
    echo '<tr>';
    echo '<td>' . (int)$row['id'] . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['lead_name']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['guardian_name']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['guardian_phone']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['guardian_email']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['email']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['phone']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['class_applied']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['status']) . '</td>';
    echo '<td>' . number_format((float)($row['admission_fee_amount'] ?? 0), 2, '.', '') . '</td>';
    echo '<td>' . number_format((float)($row['admission_concession_amount'] ?? 0), 2, '.', '') . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['admission_concession_note']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['payment_mode']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['source']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['created_at']) . '</td>';
    echo '</tr>';
}

echo '</table>';
exit;
