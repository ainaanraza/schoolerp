<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT, ROLE_PARENT]);

$user = current_user();
$role = current_role();
$errors = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    $notificationId = (int)($_POST['notification_id'] ?? 0);

    if ($notificationId <= 0) {
        $errors[] = 'Invalid notification id.';
    } else {
        $markStatement = $pdo->prepare(
            'INSERT INTO notification_reads (notification_id, user_id, read_at)
             VALUES (:notification_id, :user_id, NOW())
             ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)'
        );
        $markStatement->execute([
            'notification_id' => $notificationId,
            'user_id' => $user['id'],
        ]);
        $success[] = 'Notification marked as read.';
    }
}

$classIds = [];
if ($role === ROLE_STUDENT) {
    $classStatement = $pdo->prepare(
        'SELECT DISTINCT sce.class_id
         FROM students s
         JOIN student_class_enrollments sce ON sce.student_id = s.id AND sce.is_active = 1
         WHERE s.user_id = :user_id'
    );
    $classStatement->execute(['user_id' => $user['id']]);
    $classIds = array_map(static fn(array $row): int => (int)$row['class_id'], $classStatement->fetchAll());
}

if ($role === ROLE_PARENT) {
    $classStatement = $pdo->prepare(
        'SELECT DISTINCT sce.class_id
         FROM parents p
         JOIN parent_student ps ON ps.parent_id = p.id
         JOIN student_class_enrollments sce ON sce.student_id = ps.student_id AND sce.is_active = 1
         WHERE p.user_id = :user_id'
    );
    $classStatement->execute(['user_id' => $user['id']]);
    $classIds = array_map(static fn(array $row): int => (int)$row['class_id'], $classStatement->fetchAll());
}

if ($role === ROLE_TEACHER) {
    $teacherStatement = $pdo->prepare('SELECT id FROM teachers WHERE user_id = :user_id LIMIT 1');
    $teacherStatement->execute(['user_id' => $user['id']]);
    $teacherId = (int)($teacherStatement->fetchColumn() ?: 0);

    if ($teacherId > 0) {
        $classStatement = $pdo->prepare(
            'SELECT DISTINCT c.id AS class_id
             FROM classes c
             LEFT JOIN class_subjects cs ON cs.class_id = c.id
             WHERE c.class_teacher_id = :teacher_id OR cs.teacher_id = :teacher_id'
        );
        $classStatement->execute(['teacher_id' => $teacherId]);
        $classIds = array_map(static fn(array $row): int => (int)$row['class_id'], $classStatement->fetchAll());
    }
}

$sql =
    'SELECT n.id, n.title, n.message, n.target_scope, n.target_value, n.created_at,
            sender.full_name AS sender_name,
            nr.read_at
     FROM notifications n
     JOIN users sender ON sender.id = n.sender_id
     LEFT JOIN notification_reads nr
         ON nr.notification_id = n.id AND nr.user_id = :current_user_id
     WHERE (
        n.target_scope = "all"
        OR (n.target_scope = "role" AND n.target_value = :current_role)
        OR (n.target_scope = "user" AND n.target_value = :current_user_id_text)';

$params = [
    'current_user_id' => $user['id'],
    'current_role' => $role,
    'current_user_id_text' => (string)$user['id'],
];

if (!empty($classIds)) {
    $classPlaceholders = [];
    foreach ($classIds as $index => $classId) {
        $key = 'class_' . $index;
        $classPlaceholders[] = ':' . $key;
        $params[$key] = (string)$classId;
    }
    $sql .= ' OR (n.target_scope = "class" AND n.target_value IN (' . implode(', ', $classPlaceholders) . '))';
}

$sql .= ')
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT 200';

$statement = $pdo->prepare($sql);
$statement->execute($params);
$notifications = $statement->fetchAll();

$unreadCount = 0;
foreach ($notifications as $notification) {
    if (empty($notification['read_at'])) {
        $unreadCount++;
    }
}

$pageTitle = 'Notification Inbox';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Inbox</h2>

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

    <p><strong>Total:</strong> <?= count($notifications) ?> | <strong>Unread:</strong> <?= $unreadCount ?></p>

    <div class="table-wrap" id="section-table">
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Message</th>
                    <th>Scope</th>
                    <th>Target</th>
                    <th>Sender</th>
                    <th>Time</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($notifications as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['title']) ?></td>
                        <td><?= nl2br(htmlspecialchars($row['message'])) ?></td>
                        <td><?= htmlspecialchars($row['target_scope']) ?></td>
                        <td><?= htmlspecialchars((string)$row['target_value']) ?></td>
                        <td><?= htmlspecialchars($row['sender_name']) ?></td>
                        <td><?= htmlspecialchars($row['created_at']) ?></td>
                        <td>
                            <?php if (!empty($row['read_at'])): ?>
                                <span class="pill">Read</span>
                            <?php else: ?>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="notification_id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit">Mark Read</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($notifications)): ?>
                    <tr>
                        <td colspan="7">No notifications found for your account.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
