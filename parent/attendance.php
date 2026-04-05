<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_roles([ROLE_PARENT]);

$userId = (int)current_user()['id'];
$errors = [];

$fromDate = $_GET['from_date'] ?? date('Y-m-01');
$toDate = $_GET['to_date'] ?? date('Y-m-d');
$selectedStudentId = (int)($_GET['student_id'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $errors[] = 'Invalid date filter.';
}

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

if ($selectedStudentId > 0 && !in_array($selectedStudentId, $childIds, true)) {
    $errors[] = 'Invalid child selected.';
}

$records = [];
$summary = [
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'leave' => 0,
    'total' => 0,
];

if (empty($errors) && !empty($childIds)) {
    $placeholders = [];
    $params = [
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ];

    foreach ($childIds as $index => $childId) {
        $key = 'child_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $childId;
    }

    $sql =
        'SELECT a.attendance_date, a.status, a.remarks,
                s.id AS student_id, s.admission_no,
                su.full_name AS student_name,
                c.class_name, c.section
         FROM attendance a
         JOIN students s ON s.id = a.student_id
         LEFT JOIN users su ON su.id = s.user_id
         JOIN classes c ON c.id = a.class_id
         WHERE a.student_id IN (' . implode(', ', $placeholders) . ')
           AND a.attendance_date BETWEEN :from_date AND :to_date';

    if ($selectedStudentId > 0) {
        $sql .= ' AND a.student_id = :selected_student_id';
        $params['selected_student_id'] = $selectedStudentId;
    }

    $sql .= ' ORDER BY a.attendance_date DESC, su.full_name';

    $recordsStatement = $pdo->prepare($sql);
    $recordsStatement->execute($params);
    $records = $recordsStatement->fetchAll();

    foreach ($records as $record) {
        $status = (string)$record['status'];
        if (isset($summary[$status])) {
            $summary[$status]++;
        }
        $summary['total']++;
    }
}

$pageTitle = 'Child Attendance';
require __DIR__ . '/../includes/header.php';
?>
<section class="card">
    <h2>Child Attendance</h2>

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

        <label>From Date</label>
        <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" required>

        <label>To Date</label>
        <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" required>

        <button type="submit">Apply Filter</button>
    </form>

    <div class="metrics-grid">
        <div class="metric-card"><p class="metric-label">Total</p><p class="metric-value"><?= (int)$summary['total'] ?></p></div>
        <div class="metric-card"><p class="metric-label">Present</p><p class="metric-value"><?= (int)$summary['present'] ?></p></div>
        <div class="metric-card"><p class="metric-label">Absent</p><p class="metric-value"><?= (int)$summary['absent'] ?></p></div>
        <div class="metric-card"><p class="metric-label">Late</p><p class="metric-value"><?= (int)$summary['late'] ?></p></div>
        <div class="metric-card"><p class="metric-label">Leave</p><p class="metric-value"><?= (int)$summary['leave'] ?></p></div>
    </div>

    <div class="table-wrap" id="section-table">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Class</th>
                    <th>Status</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$record['attendance_date']) ?></td>
                        <td><?= htmlspecialchars((string)$record['student_name']) ?></td>
                        <td><?= htmlspecialchars((string)$record['class_name'] . ' - ' . (string)$record['section']) ?></td>
                        <td><span class="pill"><?= htmlspecialchars((string)$record['status']) ?></span></td>
                        <td><?= htmlspecialchars((string)($record['remarks'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($records)): ?>
                    <tr><td colspan="5">No attendance records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
