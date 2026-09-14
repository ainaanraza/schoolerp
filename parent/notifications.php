<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_roles([ROLE_PARENT]);

$user = current_user();
$userId = (int)$user['id'];
$errors = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    $notificationId = (int)($_POST['notification_id'] ?? 0);
    if ($notificationId <= 0) {
        $errors[] = 'Invalid notification.';
    } else {
        $markStatement = $pdo->prepare(
            'INSERT INTO notification_reads (notification_id, user_id, read_at)
             VALUES (:notification_id, :user_id, NOW())
             ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)'
        );
        $markStatement->execute([
            'notification_id' => $notificationId,
            'user_id' => $userId,
        ]);
        $success[] = 'Notification marked as read.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    $markAllStatement = $pdo->prepare(
        'INSERT INTO notification_reads (notification_id, user_id, read_at)
         SELECT n.id, :user_id, NOW()
         FROM notifications n
         LEFT JOIN notification_reads nr
             ON nr.notification_id = n.id
            AND nr.user_id = :user_id
         WHERE nr.id IS NULL
           AND (
                n.target_scope = "all"
                OR (n.target_scope = "role" AND n.target_value COLLATE utf8mb4_unicode_ci = CAST(:role AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci)
                OR (n.target_scope = "user" AND n.target_value COLLATE utf8mb4_unicode_ci = CAST(:user_id_text AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci)
                OR (
                    n.target_scope = "class"
                    AND n.target_value COLLATE utf8mb4_unicode_ci IN (
                        SELECT DISTINCT CAST(sce.class_id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
                        FROM parents p
                        JOIN parent_student ps ON ps.parent_id = p.id
                        JOIN student_class_enrollments sce
                            ON sce.student_id = ps.student_id
                           AND sce.is_active = 1
                        WHERE p.user_id = :user_id
                    )
                )
           )'
    );
    $markAllStatement->execute([
        'user_id' => $userId,
        'role' => ROLE_PARENT,
        'user_id_text' => (string)$userId,
    ]);
    $success[] = 'All visible notifications marked as read.';
}

$notificationsStatement = $pdo->prepare(
    'SELECT n.id, n.title, n.message, n.target_scope, n.target_value, n.created_at,
            sender.full_name AS sender_name,
            nr.read_at
     FROM notifications n
     JOIN users sender ON sender.id = n.sender_id
     LEFT JOIN notification_reads nr
         ON nr.notification_id = n.id AND nr.user_id = :user_id
     WHERE (
            n.target_scope = "all"
            OR (n.target_scope = "role" AND n.target_value COLLATE utf8mb4_unicode_ci = CAST(:role AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci)
            OR (n.target_scope = "user" AND n.target_value COLLATE utf8mb4_unicode_ci = CAST(:user_id_text AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci)
            OR (
                n.target_scope = "class"
                AND n.target_value COLLATE utf8mb4_unicode_ci IN (
                    SELECT DISTINCT CAST(sce.class_id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
                    FROM parents p
                    JOIN parent_student ps ON ps.parent_id = p.id
                    JOIN student_class_enrollments sce
                        ON sce.student_id = ps.student_id
                       AND sce.is_active = 1
                    WHERE p.user_id = :user_id
                )
            )
     )
     ORDER BY n.created_at DESC, n.id DESC
     LIMIT 200'
);
$notificationsStatement->execute([
    'user_id' => $userId,
    'role' => ROLE_PARENT,
    'user_id_text' => (string)$userId,
]);
$notifications = $notificationsStatement->fetchAll();

$unreadCount = 0;
foreach ($notifications as $notification) {
    if (empty($notification['read_at'])) {
        $unreadCount++;
    }
}

$pageTitle = 'Notifications';
require __DIR__ . '/../includes/header.php';
?>
<section class="card">
    <h2>Notifications</h2>

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

    <form method="post" class="inline-form">
        <input type="hidden" name="action" value="mark_all_read">
        <button type="submit">Mark All Read</button>
    </form>

    <div class="table-wrap table-wrap-offset" id="section-table">
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Message</th>
                    <th>Scope</th>
                    <th>Sender</th>
                    <th>Time</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($notifications as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$row['title']) ?></td>
                        <td><?= nl2br(htmlspecialchars((string)$row['message'])) ?></td>
                        <td><?= htmlspecialchars((string)$row['target_scope']) ?></td>
                        <td><?= htmlspecialchars((string)$row['sender_name']) ?></td>
                        <td><?= htmlspecialchars((string)$row['created_at']) ?></td>
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
                    <tr><td colspan="6">No notifications found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
