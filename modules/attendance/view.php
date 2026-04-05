<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT, ROLE_PARENT]);

$role = current_role();
$user = current_user();
$errors = [];

$fromDate = $_GET['from_date'] ?? date('Y-m-01');
$toDate = $_GET['to_date'] ?? date('Y-m-d');
$selectedClassId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $errors[] = 'Invalid date filter.';
}

$classes = [];
if (in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_TEACHER], true)) {
    $classes = $pdo->query(
        'SELECT c.id, c.class_name, c.section, s.title AS session_title
         FROM classes c
         JOIN academic_sessions s ON s.id = c.session_id
         ORDER BY c.class_name, c.section'
    )->fetchAll();
}

$baseSql =
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

if ($selectedClassId > 0) {
    $baseSql .= ' AND a.class_id = :class_id';
    $params['class_id'] = $selectedClassId;
}

if ($role === ROLE_STUDENT) {
    $studentStatement = $pdo->prepare('SELECT id FROM students WHERE user_id = :user_id LIMIT 1');
    $studentStatement->execute(['user_id' => $user['id']]);
    $studentId = (int)($studentStatement->fetchColumn() ?: 0);
    $baseSql .= ' AND a.student_id = :student_id';
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
        $baseSql .= ' AND 1 = 0';
    } else {
        $childPlaceholders = [];
        foreach ($childIds as $index => $childId) {
            $key = 'child_' . $index;
            $childPlaceholders[] = ':' . $key;
            $params[$key] = $childId;
        }
        $baseSql .= ' AND a.student_id IN (' . implode(', ', $childPlaceholders) . ')';
    }
}

$baseSql .= ' ORDER BY a.attendance_date DESC, c.class_name, c.section, s.roll_number, u.full_name';

$records = [];
if (empty($errors)) {
    $statement = $pdo->prepare($baseSql);
    $statement->execute($params);
    $records = $statement->fetchAll();
}

$summary = [
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'leave' => 0,
    'total' => 0,
];
foreach ($records as $record) {
    $status = $record['status'];
    if (isset($summary[$status])) {
        $summary[$status]++;
    }
    $summary['total']++;
}

$pageTitle = 'Attendance Summary';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Attendance Summary</h2>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="get" class="form-grid form-grid-wide">
        <label>From Date</label>
        <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" required>

        <label>To Date</label>
        <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" required>

        <?php if (in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_TEACHER], true)): ?>
            <label>Class (optional)</label>
            <select name="class_id">
                <option value="0">All classes</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= (int)$class['id'] ?>" <?= $selectedClassId === (int)$class['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($class['class_name'] . ' - ' . $class['section'] . ' (' . $class['session_title'] . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <button type="submit">Apply Filter</button>
    </form>

    <div class="grid-2" style="margin-top: 16px;">
        <div class="card">
            <h3>Summary</h3>
            <p><strong>Total Entries:</strong> <?= (int)$summary['total'] ?></p>
            <p><strong>Present:</strong> <?= (int)$summary['present'] ?></p>
            <p><strong>Absent:</strong> <?= (int)$summary['absent'] ?></p>
            <p><strong>Late:</strong> <?= (int)$summary['late'] ?></p>
            <p><strong>Leave:</strong> <?= (int)$summary['leave'] ?></p>
        </div>
    </div>

    <div class="table-wrap" id="section-table" style="margin-top: 16px;">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Class</th>
                    <th>Roll</th>
                    <th>Admission</th>
                    <th>Student</th>
                    <th>Status</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$record['attendance_date']) ?></td>
                        <td><?= htmlspecialchars((string)$record['class_name'] . ' - ' . (string)$record['section']) ?></td>
                        <td><?= htmlspecialchars((string)($record['roll_number'] ?: '-')) ?></td>
                        <td><?= htmlspecialchars((string)$record['admission_no']) ?></td>
                        <td><?= htmlspecialchars((string)$record['student_name']) ?></td>
                        <td><span class="pill"><?= htmlspecialchars((string)$record['status']) ?></span></td>
                        <td><?= htmlspecialchars((string)$record['remarks']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="7">No attendance records found for selected filters.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
