<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$searchName = trim($_GET['search_name'] ?? '');
$searchNo = trim($_GET['search_no'] ?? '');

$sql =
    'SELECT s.id, s.admission_no, s.roll_number, s.status, s.created_at,
            su.full_name AS student_name, su.email AS student_email,
            CONCAT(c.class_name, " - ", c.section) AS class_label
     FROM students s
     LEFT JOIN users su ON su.id = s.user_id
     LEFT JOIN student_class_enrollments sce ON sce.student_id = s.id AND sce.is_active = 1
     LEFT JOIN classes c ON c.id = sce.class_id';
$where = [];
$params = [];

if ($searchName !== '') {
    $where[] = 'su.full_name LIKE :search_name';
    $params['search_name'] = '%' . $searchName . '%';
}
if ($searchNo !== '') {
    $where[] = 's.admission_no LIKE :search_no';
    $params['search_no'] = '%' . $searchNo . '%';
}
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY s.id DESC';

$statement = $pdo->prepare($sql);
$statement->execute($params);
$rows = $statement->fetchAll();

$filename = 'students_report_' . date('Ymd_His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);
header('Pragma: no-cache');
header('Expires: 0');

echo "<table border='1'>";
echo '<tr>';
echo '<th>ID</th><th>Admission No</th><th>Student Name</th><th>Student Email</th><th>Roll Number</th><th>Status</th><th>Class</th><th>Created At</th>';
echo '</tr>';

foreach ($rows as $row) {
    echo '<tr>';
    echo '<td>' . (int)$row['id'] . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['admission_no']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['student_name']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['student_email']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['roll_number']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['status']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['class_label']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['created_at']) . '</td>';
    echo '</tr>';
}

echo '</table>';
exit;
