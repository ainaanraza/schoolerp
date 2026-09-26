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
             WHERE c.class_teacher_id = :teacher_id'
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
    $targetScopes = $_POST['target_scope'] ?? [];
    $targetValues = $_POST['target_value'] ?? [];
    
    if (!is_array($targetScopes)) $targetScopes = [$targetScopes];
    if (!is_array($targetValues)) $targetValues = [$targetValues];

    $allowedScopes = $allowedScopesByRole[$role] ?? [];
    $validTargets = [];

    if ($title === '' || $message === '') {
        $errors[] = 'Title and message are required.';
    }

    if (in_array('all', $targetScopes, true)) {
        if (!in_array('all', $allowedScopes, true)) {
            $errors[] = 'Selected target scope is not allowed for your role.';
        } else {
            $validTargets[] = ['scope' => 'all', 'value' => null];
        }
    } else {
        foreach ($targetValues as $val) {
            $parts = explode('_', $val, 2);
            if (count($parts) !== 2) {
                $errors[] = 'Invalid target value format.';
                continue;
            }
            $scope = $parts[0];
            $value = $parts[1];

            if (!in_array($scope, $targetScopes, true) || !in_array($scope, $allowedScopes, true)) {
                $errors[] = "Target scope '$scope' is not allowed.";
                continue;
            }

            if ($scope === 'role' && !in_array($value, $allowedRoleTargets, true)) {
                $errors[] = 'Invalid role target.';
            }

            if ($scope === 'class') {
                $classId = (int)$value;
                $classIds = array_map(static fn(array $row): int => (int)$row['id'], $allClasses);
                if (!in_array($classId, $classIds, true)) {
                    $errors[] = 'Selected course does not exist.';
                }
                if ($role === ROLE_TEACHER && !in_array($classId, $teacherClassIds, true)) {
                    $errors[] = 'Teachers can only notify their assigned courses.';
                }
            }

            if ($scope === 'user') {
                $targetUserId = (int)$value;
                $userCheck = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
                $userCheck->execute(['id' => $targetUserId]);
                if (!$userCheck->fetch()) {
                    $errors[] = 'Target user not found.';
                }
            }
            
            $validTargets[] = ['scope' => $scope, 'value' => $value];
        }
        
        if (empty($validTargets) && empty($errors)) {
            $errors[] = 'Please select at least one target.';
        }
    }

    if (empty($errors)) {
        $insert = $pdo->prepare(
            'INSERT INTO notifications (title, message, sender_id, target_scope, target_value)
             VALUES (:title, :message, :sender_id, :target_scope, :target_value)'
        );
        foreach ($validTargets as $t) {
            $insert->execute([
                'title' => $title,
                'message' => $message,
                'sender_id' => current_user()['id'],
                'target_scope' => $t['scope'],
                'target_value' => $t['value'],
            ]);
        }
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
    <!-- CLEAN TABLES VIEW FIRST -->
    <section class="card">
        <div class="form-header-actions">
            <h3>Recent Notifications</h3>
            <button type="button" class="btn-toggle-form" onclick="toggleForm('form-broadcast', this)">+ New Broadcast</button>
        </div>
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
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentNotifications as $row): ?>
                        <tr>
                            <td><?= (int)$row['id'] ?></td>
                            <td><?= htmlspecialchars($row['title']) ?></td>
                            <td><?= htmlspecialchars($row['target_scope'] === 'class' ? 'course' : (string)$row['target_scope']) ?></td>
                            <td><?= htmlspecialchars((string)$row['target_value']) ?></td>
                            <td><?= htmlspecialchars($row['sender_name']) ?></td>
                            <td><?= htmlspecialchars($row['created_at']) ?></td>

                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentNotifications)): ?>
                        <tr>
                            <td colspan="6">No notifications created yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div id="form-broadcast" class="collapsible-form">
        <section class="card">
            <h3>Notification Sender</h3>
            <form method="post" class="form-grid form-grid-wide">
                <label>Title</label>
                <input type="text" name="title" required>

                <label>Message</label>
                <textarea name="message" rows="4" required></textarea>

                <label>Target Scope</label>
                <div class="multi-select-widget" id="scopeWidget">
                    <div class="multi-select-button" onclick="toggleMultiSelect('scopeDropdown', this)">
                        <span class="multi-select-button-text">Select Scope...</span>
                        <i class="chevron">▼</i>
                    </div>
                    <div class="multi-select-dropdown" id="scopeDropdown">
                        <?php foreach ($allowedScopesByRole[$role] as $scope): ?>
                            <label class="multi-select-option">
                                <input type="checkbox" name="target_scope[]" value="<?= htmlspecialchars($scope) ?>" onchange="updateMultiSelectText('scopeWidget', 'Select Scope...'); updateTargetDropdown();">
                                <?= htmlspecialchars($scope === 'class' ? 'COURSE' : strtoupper($scope)) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <label id="targetValueLabel">Target Value</label>
                <div class="multi-select-widget" id="targetWidget">
                    <div class="multi-select-button" onclick="toggleMultiSelect('targetDropdown', this)">
                        <span class="multi-select-button-text">Select Target...</span>
                        <i class="chevron">▼</i>
                    </div>
                    <div class="multi-select-dropdown" id="targetDropdown">
                        <?php if ($role === ROLE_TEACHER): ?>
                            <?php foreach ($allClasses as $class): ?>
                                <?php if (in_array((int)$class['id'], $teacherClassIds, true)): ?>
                                    <label class="multi-select-option" data-scope="class">
                                        <input type="checkbox" name="target_value[]" value="class_<?= (int)$class['id'] ?>" onchange="updateMultiSelectText('targetWidget', 'Select Target...')">
                                        Course: <?= htmlspecialchars($class['class_name']) ?>
                                    </label>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php foreach ($allowedRoleTargets as $roleTarget): ?>
                                <label class="multi-select-option" data-scope="role">
                                    <input type="checkbox" name="target_value[]" value="role_<?= htmlspecialchars($roleTarget) ?>" onchange="updateMultiSelectText('targetWidget', 'Select Target...')">
                                    Role: <?= htmlspecialchars($roleTarget) ?>
                                </label>
                            <?php endforeach; ?>
                            <?php foreach ($allClasses as $class): ?>
                                <label class="multi-select-option" data-scope="class">
                                    <input type="checkbox" name="target_value[]" value="class_<?= (int)$class['id'] ?>" onchange="updateMultiSelectText('targetWidget', 'Select Target...')">
                                    Course: <?= htmlspecialchars($class['class_name']) ?>
                                </label>
                            <?php endforeach; ?>
                            <?php foreach ($userTargets as $targetUser): ?>
                                <label class="multi-select-option" data-scope="user">
                                    <input type="checkbox" name="target_value[]" value="user_<?= (int)$targetUser['id'] ?>" onchange="updateMultiSelectText('targetWidget', 'Select Target...')">
                                    User: <?= htmlspecialchars($targetUser['full_name'] . ' [' . $targetUser['role'] . ']') ?>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <button type="submit">Send Notification</button>
            </form>
        </section>
    </div>

<script>
function toggleMultiSelect(dropdownId, btnElement) {
    var dropdown = document.getElementById(dropdownId);
    if (dropdown.classList.contains('show')) {
        dropdown.classList.remove('show');
        btnElement.classList.remove('active');
    } else {
        // close all others first
        document.querySelectorAll('.multi-select-dropdown').forEach(function(d) { d.classList.remove('show'); });
        document.querySelectorAll('.multi-select-button').forEach(function(b) { b.classList.remove('active'); });
        
        dropdown.classList.add('show');
        btnElement.classList.add('active');
    }
}

function updateMultiSelectText(widgetId, defaultText) {
    var widget = document.getElementById(widgetId);
    var checkboxes = widget.querySelectorAll('input[type="checkbox"]:checked');
    var btnText = widget.querySelector('.multi-select-button-text');
    
    if (checkboxes.length === 0) {
        btnText.textContent = defaultText;
    } else {
        var labels = [];
        checkboxes.forEach(function(cb) {
            labels.push(cb.parentNode.textContent.trim());
        });
        btnText.textContent = 'Selected: ' + labels.join(', ');
    }
}

function updateTargetDropdown() {
    var scopeCheckboxes = document.querySelectorAll('#scopeDropdown input[type="checkbox"]:checked');
    var selectedScopes = Array.from(scopeCheckboxes).map(function(cb) { return cb.value; });
    var targetWidget = document.getElementById('targetWidget');
    var targetLabel = document.getElementById('targetValueLabel');
    var targetOptions = document.querySelectorAll('#targetDropdown .multi-select-option');

    if (selectedScopes.includes('all')) {
        targetWidget.style.display = 'none';
        targetLabel.style.display = 'none';
        // Deselect all
        document.querySelectorAll('#targetDropdown input[type="checkbox"]').forEach(function(cb) {
            cb.checked = false;
        });
        updateMultiSelectText('targetWidget', 'Select Target...');
    } else {
        targetWidget.style.display = '';
        targetLabel.style.display = '';

        targetOptions.forEach(function(opt) {
            var scope = opt.getAttribute('data-scope');
            if (selectedScopes.includes(scope)) {
                opt.style.display = '';
            } else {
                opt.style.display = 'none';
                opt.querySelector('input[type="checkbox"]').checked = false;
            }
        });
        updateMultiSelectText('targetWidget', 'Select Target...');
    }
}

// Close dropdowns if clicked outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.multi-select-widget')) {
        document.querySelectorAll('.multi-select-dropdown').forEach(function(d) { d.classList.remove('show'); });
        document.querySelectorAll('.multi-select-button').forEach(function(b) { b.classList.remove('active'); });
    }
});

document.addEventListener('DOMContentLoaded', function() {
    updateTargetDropdown();
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
