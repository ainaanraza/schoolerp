<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_roles([ROLE_PARENT]);

$userId = (int)current_user()['id'];
$selectedStudentId = (int)($_GET['student_id'] ?? 0);
$statusFilter = $_GET['status'] ?? 'all';
$allowedStatusFilters = ['all', 'pending', 'submitted', 'late'];

if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'all';
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

$errors = [];
if ($selectedStudentId > 0 && !in_array($selectedStudentId, $childIds, true)) {
    $errors[] = 'Invalid child selected.';
}

$rows = [];
$summary = [
    'total' => 0,
    'pending' => 0,
    'submitted' => 0,
    'late' => 0,
    'overdue_pending' => 0,
];

if (!empty($childIds) && empty($errors)) {
    $placeholders = [];
    $params = [];
    foreach ($childIds as $index => $childId) {
        $key = 'child_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $childId;
    }

    $sql =
        'SELECT h.id, h.title, h.description, h.due_date, h.created_at,
                c.class_name, c.section,
                st.id AS student_id,
                su.full_name AS student_name,
                hs.status AS submission_status,
                hs.submitted_at
         FROM homework h
         JOIN classes c ON c.id = h.class_id
         JOIN student_class_enrollments sce ON sce.class_id = h.class_id AND sce.is_active = 1
         JOIN students st ON st.id = sce.student_id
         LEFT JOIN users su ON su.id = st.user_id
         LEFT JOIN homework_submissions hs
             ON hs.homework_id = h.id
            AND hs.student_id = st.id
         WHERE st.id IN (' . implode(', ', $placeholders) . ')';

    if ($selectedStudentId > 0) {
        $sql .= ' AND st.id = :selected_student_id';
        $params['selected_student_id'] = $selectedStudentId;
    }

    if ($statusFilter !== 'all') {
        $sql .= ' AND COALESCE(hs.status, "pending") = :status_filter';
        $params['status_filter'] = $statusFilter;
    }

    $sql .= ' ORDER BY h.due_date IS NULL, h.due_date ASC, su.full_name, h.created_at DESC';

    $rowsStatement = $pdo->prepare($sql);
    $rowsStatement->execute($params);
    $rows = $rowsStatement->fetchAll();

    foreach ($rows as $row) {
        $status = (string)($row['submission_status'] ?? 'pending');
        if (!isset($summary[$status])) {
            $summary[$status] = 0;
        }
        $summary[$status]++;
        $summary['total']++;

        $dueDate = (string)($row['due_date'] ?? '');
        if ($status === 'pending' && $dueDate !== '' && $dueDate < date('Y-m-d')) {
            $summary['overdue_pending']++;
        }
    }
}

$pageTitle = 'Homework Updates';
require __DIR__ . '/../includes/header.php';
?>
<section class="card">
    <h2>Homework Updates</h2>

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

        <label>Status</label>
        <select name="status">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="submitted" <?= $statusFilter === 'submitted' ? 'selected' : '' ?>>Submitted</option>
            <option value="late" <?= $statusFilter === 'late' ? 'selected' : '' ?>>Late</option>
        </select>

        <button type="submit">Apply Filter</button>
    </form>

    <div class="metrics-grid">
        <div class="metric-card"><p class="metric-label">Total</p><p class="metric-value"><?= (int)$summary['total'] ?></p></div>
        <div class="metric-card"><p class="metric-label">Pending</p><p class="metric-value"><?= (int)$summary['pending'] ?></p></div>
        <div class="metric-card"><p class="metric-label">Submitted</p><p class="metric-value"><?= (int)$summary['submitted'] ?></p></div>
        <div class="metric-card"><p class="metric-label">Late</p><p class="metric-value"><?= (int)$summary['late'] ?></p></div>
        <div class="metric-card"><p class="metric-label">Overdue Pending</p><p class="metric-value"><?= (int)$summary['overdue_pending'] ?></p></div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Course</th>
                    <th>Title</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Submitted At</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                        $status = (string)($row['submission_status'] ?? 'pending');
                        $dueDate = (string)($row['due_date'] ?? '');
                        $isOverdue = $status === 'pending' && $dueDate !== '' && $dueDate < date('Y-m-d');
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$row['student_name']) ?></td>
                        <td><?= htmlspecialchars((string)$row['class_name']) ?></td>
                        <td><?= htmlspecialchars((string)$row['title']) ?></td>
                        <td>
                            <?= htmlspecialchars((string)($row['due_date'] ?: '-')) ?>
                            <?php if ($isOverdue): ?>
                                <br><span class="pill">overdue</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="pill"><?= htmlspecialchars($status) ?></span></td>
                        <td><?= htmlspecialchars((string)($row['submitted_at'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6">No homework records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
