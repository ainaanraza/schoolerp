<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT, ROLE_PARENT]);

header('Content-Type: application/json');

$role = current_role();
$user = current_user();
$fromDate = $_GET['from_date'] ?? date('Y-m-01');
$toDate = $_GET['to_date'] ?? date('Y-m-d');
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid date parameters.']);
    exit;
}

$sql =
    'SELECT a.id, a.attendance_date, a.status, a.remarks,
            a.class_id, c.class_name, c.section,
            s.id AS student_id, s.admission_no, s.roll_number,
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
    $statement = $pdo->prepare('SELECT id FROM students WHERE user_id = :user_id LIMIT 1');
    $statement->execute(['user_id' => $user['id']]);
    $studentId = (int)($statement->fetchColumn() ?: 0);

    $sql .= ' AND a.student_id = :student_id';
    $params['student_id'] = $studentId;
}

if ($role === ROLE_PARENT) {
    $statement = $pdo->prepare(
        'SELECT ps.student_id
         FROM parents p
         JOIN parent_student ps ON ps.parent_id = p.id
         WHERE p.user_id = :user_id'
    );
    $statement->execute(['user_id' => $user['id']]);
    $childIds = array_map(static fn(array $row): int => (int)$row['student_id'], $statement->fetchAll());

    if (empty($childIds)) {
        $sql .= ' AND 1 = 0';
    } else {
        $placeholders = [];
        foreach ($childIds as $index => $childId) {
            $key = 'child_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $childId;
        }
        $sql .= ' AND a.student_id IN (' . implode(', ', $placeholders) . ')';
    }
}

$sql .= ' ORDER BY a.attendance_date DESC, c.class_name, c.section, s.roll_number, u.full_name';

$query = $pdo->prepare($sql);
$query->execute($params);
$rows = $query->fetchAll();

foreach ($rows as &$row) {
    $row['course_id'] = (int)$row['class_id'];
    $row['course_name'] = (string)$row['class_name'];
}
unset($row);

$summary = [
    'total' => 0,
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'leave' => 0,
];

foreach ($rows as $row) {
    $summary['total']++;
    if (isset($summary[$row['status']])) {
        $summary[$row['status']]++;
    }
}

echo json_encode([
    'filters' => [
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'class_id' => $classId,
        'course_id' => $classId,
        'role' => $role,
    ],
    'summary' => $summary,
    'rows' => $rows,
], JSON_PRETTY_PRINT);
