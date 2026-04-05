<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_TEACHER]);

$role = current_role();
$errors = [];
$success = [];

$allClasses = $pdo->query(
    'SELECT c.id, c.class_name, c.section, s.title AS session_title
     FROM classes c
     JOIN academic_sessions s ON s.id = c.session_id
     ORDER BY c.class_name, c.section'
)->fetchAll();

$teacherClassIds = [];
if ($role === ROLE_TEACHER) {
    $teacherStatement = $pdo->prepare('SELECT id FROM teachers WHERE user_id = :user_id LIMIT 1');
    $teacherStatement->execute(['user_id' => current_user()['id']]);
    $teacherId = (int)($teacherStatement->fetchColumn() ?: 0);

    if ($teacherId > 0) {
        $teacherClassStatement = $pdo->prepare(
            'SELECT DISTINCT c.id
             FROM classes c
             LEFT JOIN class_subjects cs ON cs.class_id = c.id
             WHERE c.class_teacher_id = :teacher_id OR cs.teacher_id = :teacher_id'
        );
        $teacherClassStatement->execute(['teacher_id' => $teacherId]);
        $teacherClassIds = array_map(static fn(array $row): int => (int)$row['id'], $teacherClassStatement->fetchAll());
    }
}

$allowedRoleTargets = [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT, ROLE_PARENT];
$allowedScopesByRole = [
    ROLE_SUPER_ADMIN => ['all', 'role', 'class', 'user'],
    ROLE_ADMIN => ['role', 'class', 'user'],
    ROLE_TEACHER => ['class'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'delete_notification') {
        $notificationId = (int)($_POST['notification_id'] ?? 0);
        if ($notificationId <= 0) {
            $errors[] = 'Invalid notification delete request.';
        } else {
            try {
                $pdo->beginTransaction();

                $ownerStmt = $pdo->prepare('SELECT sender_id FROM notifications WHERE id = :id LIMIT 1 FOR UPDATE');
                $ownerStmt->execute(['id' => $notificationId]);
                $senderId = (int)($ownerStmt->fetchColumn() ?: 0);

                if ($senderId <= 0) {
                    throw new RuntimeException('Notification not found.');
                }

                if (!in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN], true) && $senderId !== (int)current_user()['id']) {
                    throw new RuntimeException('You cannot delete this notification.');
                }

                $deleteReads = $pdo->prepare('DELETE FROM notification_reads WHERE notification_id = :id');
                $deleteReads->execute(['id' => $notificationId]);

                $deleteNotification = $pdo->prepare('DELETE FROM notifications WHERE id = :id');
                $deleteNotification->execute(['id' => $notificationId]);

                $pdo->commit();
                $success[] = 'Notification deleted.';
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to delete notification: ' . $throwable->getMessage();
            }
        }
    }

    if (!empty($_POST['action']) && $_POST['action'] === 'delete_notification') {
        // Skip create flow when deletion action is handled.
    } else {
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $targetScope = $_POST['target_scope'] ?? '';
    $targetValue = trim($_POST['target_value'] ?? '');

    $allowedScopes = $allowedScopesByRole[$role] ?? [];

    if ($title === '' || $message === '') {
        $errors[] = 'Title and message are required.';
    }

    if (!in_array($targetScope, $allowedScopes, true)) {
        $errors[] = 'Selected target scope is not allowed for your role.';
    }

    if ($targetScope === 'role' && !in_array($targetValue, $allowedRoleTargets, true)) {
        $errors[] = 'Invalid role target.';
    }

    if ($targetScope === 'class') {
        $classId = (int)$targetValue;
        if ($classId <= 0) {
            $errors[] = 'Class target is required.';
        } else {
            $classIds = array_map(static fn(array $row): int => (int)$row['id'], $allClasses);
            if (!in_array($classId, $classIds, true)) {
                $errors[] = 'Selected class does not exist.';
            }
            if ($role === ROLE_TEACHER && !in_array($classId, $teacherClassIds, true)) {
                $errors[] = 'Teachers can only notify their assigned classes.';
            }
        }
    }

    if ($targetScope === 'user') {
        $targetUserId = (int)$targetValue;
        if ($targetUserId <= 0) {
            $errors[] = 'User target is required.';
        } else {
            $userCheck = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
            $userCheck->execute(['id' => $targetUserId]);
            if (!$userCheck->fetch()) {
                $errors[] = 'Target user not found.';
            }
        }
    }

    if (empty($errors)) {
        $insert = $pdo->prepare(
            'INSERT INTO notifications (title, message, sender_id, target_scope, target_value)
             VALUES (:title, :message, :sender_id, :target_scope, :target_value)'
        );
        $insert->execute([
            'title' => $title,
            'message' => $message,
            'sender_id' => current_user()['id'],
            'target_scope' => $targetScope,
            'target_value' => $targetValue !== '' ? $targetValue : null,
        ]);
        $success[] = 'Notification sent successfully.';
    }
    }
}

$recentSql =
    'SELECT n.id, n.sender_id, n.title, n.target_scope, n.target_value, n.created_at, u.full_name AS sender_name
     FROM notifications n
     JOIN users u ON u.id = n.sender_id';
$recentParams = [];

if ($role === ROLE_TEACHER) {
    $recentSql .= ' WHERE n.sender_id = :sender_id';
    $recentParams['sender_id'] = current_user()['id'];
}

$recentSql .= ' ORDER BY n.id DESC LIMIT 50';

$recentStatement = $pdo->prepare($recentSql);
$recentStatement->execute($recentParams);
$recentNotifications = $recentStatement->fetchAll();

$userTargets = [];
if ($role !== ROLE_TEACHER) {
    $userTargets = $pdo->query(
        'SELECT id, full_name, role, email
         FROM users
         WHERE is_active = 1
         ORDER BY full_name'
    )->fetchAll();
}

$pageTitle = 'Send Notifications';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Notification Sender</h2>

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

    <form method="post" class="form-grid form-grid-wide">
        <label>Title</label>
        <input type="text" name="title" required>

        <label>Message</label>
        <textarea name="message" rows="4" required></textarea>

        <label>Target Scope</label>
        <select name="target_scope" id="targetScopeSelect" required>
            <?php foreach ($allowedScopesByRole[$role] as $scope): ?>
                <option value="<?= htmlspecialchars($scope) ?>"><?= htmlspecialchars(strtoupper($scope)) ?></option>
            <?php endforeach; ?>
        </select>

        <label>Target Value</label>
        <select name="target_value" id="targetValueSelect">
            <option value="">Select target</option>
            <?php if ($role === ROLE_TEACHER): ?>
                <?php foreach ($allClasses as $class): ?>
                    <?php if (in_array((int)$class['id'], $teacherClassIds, true)): ?>
                        <option value="<?= (int)$class['id'] ?>">Class: <?= htmlspecialchars($class['class_name'] . ' - ' . $class['section']) ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($allowedRoleTargets as $roleTarget): ?>
                    <option value="<?= htmlspecialchars($roleTarget) ?>">Role: <?= htmlspecialchars($roleTarget) ?></option>
                <?php endforeach; ?>
                <?php foreach ($allClasses as $class): ?>
                    <option value="<?= (int)$class['id'] ?>">Class: <?= htmlspecialchars($class['class_name'] . ' - ' . $class['section']) ?></option>
                <?php endforeach; ?>
                <?php foreach ($userTargets as $targetUser): ?>
                    <option value="<?= (int)$targetUser['id'] ?>">User: <?= htmlspecialchars($targetUser['full_name'] . ' [' . $targetUser['role'] . ']') ?></option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>

        <button type="submit">Send Notification</button>
    </form>
</section>

<section class="card">
    <h3>Recent Notifications</h3>
    <div class="table-wrap" id="section-table">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Scope</th>
                    <th>Target</th>
                    <th>Sender</th>
                    <th>Time</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentNotifications as $row): ?>
                    <tr>
                        <td><?= (int)$row['id'] ?></td>
                        <td><?= htmlspecialchars($row['title']) ?></td>
                        <td><?= htmlspecialchars($row['target_scope']) ?></td>
                        <td><?= htmlspecialchars((string)$row['target_value']) ?></td>
                        <td><?= htmlspecialchars($row['sender_name']) ?></td>
                        <td><?= htmlspecialchars($row['created_at']) ?></td>
                        <td>
                            <?php if (in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN], true) || (int)$row['sender_id'] === (int)current_user()['id']): ?>
                                <form method="post" class="inline-form" onsubmit="return confirm('Delete this notification?');">
                                    <input type="hidden" name="action" value="delete_notification">
                                    <input type="hidden" name="notification_id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="danger">Delete</button>
                                </form>
                            <?php else: ?>
                                <span>-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recentNotifications)): ?>
                    <tr>
                        <td colspan="7">No notifications created yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
