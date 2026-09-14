<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT, ROLE_PARENT]);

$role = current_role();
$user = current_user();
$fromDate = $_GET['from_date'] ?? date('Y-m-01');
$toDate = $_GET['to_date'] ?? date('Y-m-d');
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    http_response_code(422);
    echo 'Invalid date filters.';
    exit;
}

$sql =
    'SELECT a.id, a.attendance_date, a.status, a.remarks,
            c.class_name, c.section,
            s.admission_no, s.roll_number,
            u.full_name AS student_name
     FROM attendance a
     JOIN students s ON s.id = a.student_id
     LEFT JOIN users u ON u.id = s.user_id
     JOIN classes c ON c.id = a.class_id
     WHERE a.attendance_date BETWEEN :from_date AND :to_date';

$params = [
    'from_date' => $fromDate,
    'to_date' => $toDate,
];

if ($classId > 0) {
    $sql .= ' AND a.class_id = :class_id';
    $params['class_id'] = $classId;
}

if ($role === ROLE_STUDENT) {
    $studentStatement = $pdo->prepare('SELECT id FROM students WHERE user_id = :user_id LIMIT 1');
    $studentStatement->execute(['user_id' => $user['id']]);
    $studentId = (int)($studentStatement->fetchColumn() ?: 0);
    $sql .= ' AND a.student_id = :student_id';
    $params['student_id'] = $studentId;
}

if ($role === ROLE_PARENT) {
    $childStatement = $pdo->prepare(
        'SELECT ps.student_id
         FROM parents p
         JOIN parent_student ps ON ps.parent_id = p.id
         WHERE p.user_id = :user_id'
    );
    $childStatement->execute(['user_id' => $user['id']]);
    $childIds = array_map(static fn(array $row): int => (int)$row['student_id'], $childStatement->fetchAll());

    if (empty($childIds)) {
        $sql .= ' AND 1 = 0';
    } else {
        $childPlaceholders = [];
        foreach ($childIds as $index => $childId) {
            $key = 'child_' . $index;
            $childPlaceholders[] = ':' . $key;
            $params[$key] = $childId;
        }
        $sql .= ' AND a.student_id IN (' . implode(', ', $childPlaceholders) . ')';
    }
}

$sql .= ' ORDER BY a.attendance_date DESC, c.class_name, c.section, s.roll_number, u.full_name';

$statement = $pdo->prepare($sql);
$statement->execute($params);
$records = $statement->fetchAll();

$filename = 'attendance_report_' . date('Ymd_His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);
header('Pragma: no-cache');
header('Expires: 0');

echo "<table border='1'>";
echo '<tr>';
echo '<th>Date</th><th>Course</th><th>Roll</th><th>Admission No</th><th>Student</th><th>Status</th><th>Remarks</th>';
echo '</tr>';

foreach ($records as $record) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars((string)$record['attendance_date']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$record['class_name']) . '</td>';
    echo '<td>' . htmlspecialchars((string)($record['roll_number'] ?: '-')) . '</td>';
    echo '<td>' . htmlspecialchars((string)$record['admission_no']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$record['student_name']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$record['status']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$record['remarks']) . '</td>';
    echo '</tr>';
}

echo '</table>';
exit;
