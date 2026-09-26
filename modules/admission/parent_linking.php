<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/delete_helpers.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$errors = [];
$success = [];

// Search params
$searchQuery = trim($_GET['search'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'link_parent_student') {
        $parentId = (int)($_POST['parent_id'] ?? 0);
        $studentId = (int)($_POST['student_id'] ?? 0);
        $relation = trim($_POST['relation'] ?? 'guardian');

        if ($parentId <= 0 || $studentId <= 0) {
            $errors[] = 'Parent and student are required.';
        } else {
            $checkParent = $pdo->prepare('SELECT id FROM parents WHERE id = :id LIMIT 1');
            $checkStudent = $pdo->prepare('SELECT id FROM students WHERE id = :id LIMIT 1');
            $checkParent->execute(['id' => $parentId]);
            $checkStudent->execute(['id' => $studentId]);

            if (!$checkParent->fetch() || !$checkStudent->fetch()) {
                $errors[] = 'Invalid parent or student selected.';
            } else {
                $linkStmt = $pdo->prepare(
                    'INSERT INTO parent_student (parent_id, student_id, relation)
                     VALUES (:parent_id, :student_id, :relation)
                     ON DUPLICATE KEY UPDATE relation = VALUES(relation)'
                );
                $linkStmt->execute([
                    'parent_id' => $parentId,
                    'student_id' => $studentId,
                    'relation' => $relation !== '' ? $relation : 'guardian',
                ]);
                $success[] = 'Parent-child relation saved successfully.';
            }
        }
    }

    if ($action === 'withdraw_student') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        if ($studentId <= 0) {
            $errors[] = 'Invalid withdraw request.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Remove student from currently enrolled classes
                $deactivateStmt = $pdo->prepare('UPDATE student_class_enrollments SET is_active = 0 WHERE student_id = :student_id AND is_active = 1');
                $deactivateStmt->execute(['student_id' => $studentId]);
                
                // Update student status to inactive
                $statusStmt = $pdo->prepare('UPDATE students SET status = "inactive" WHERE id = :id');
                $statusStmt->execute(['id' => $studentId]);
                
                $pdo->commit();
                $success[] = 'Student successfully withdrawn from current courses. Record has been kept.';
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to withdraw student: ' . $throwable->getMessage();
            }
        }
    }

    if ($action === 'restore_student') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        if ($studentId <= 0) {
            $errors[] = 'Invalid restore request.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Update student status to enrolled
                $statusStmt = $pdo->prepare('UPDATE students SET status = "enrolled" WHERE id = :id');
                $statusStmt->execute(['id' => $studentId]);
                
                // Reactivate their most recent class enrollment
                $reactivateStmt = $pdo->prepare('UPDATE student_class_enrollments SET is_active = 1 WHERE student_id = :student_id ORDER BY id DESC LIMIT 1');
                $reactivateStmt->execute(['student_id' => $studentId]);
                
                $pdo->commit();
                $success[] = 'Student admission restored successfully.';
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to restore student: ' . $throwable->getMessage();
            }
        }
    }

    if ($action === 'delete_parent_account') {
        $parentId = (int)($_POST['parent_id'] ?? 0);
        if ($parentId <= 0) {
            $errors[] = 'Invalid parent delete request.';
        } else {
            try {
                $pdo->beginTransaction();
                cascade_delete_parent($pdo, $parentId);
                $pdo->commit();
                $success[] = 'Parent account deleted with related links and portal user.';
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to delete parent account: ' . $throwable->getMessage();
            }
        }
    }
}

$parents = $pdo->query(
    'SELECT p.id, u.full_name, u.email
     FROM parents p
     JOIN users u ON u.id = p.user_id
     ORDER BY u.full_name'
)->fetchAll();

$students = $pdo->query(
    'SELECT s.id, s.admission_no, u.full_name, u.email
     FROM students s
     LEFT JOIN users u ON u.id = s.user_id
     WHERE s.status = "enrolled"
     ORDER BY u.full_name'
)->fetchAll();

$sql =
    'SELECT ps.id, ps.relation, ps.created_at,
            pu.full_name AS parent_name, pu.email AS parent_email,
            p.id AS parent_id,
            su.full_name AS student_name, su.email AS student_email,
            s.admission_no, s.id AS student_id, s.status AS student_status
     FROM parent_student ps
     JOIN parents p ON p.id = ps.parent_id
     JOIN users pu ON pu.id = p.user_id
     JOIN students s ON s.id = ps.student_id
     LEFT JOIN users su ON su.id = s.user_id';

$params = [];
if ($searchQuery !== '') {
    $sql .= ' WHERE pu.full_name LIKE :search OR su.full_name LIKE :search OR s.admission_no LIKE :search';
    $params['search'] = '%' . $searchQuery . '%';
}

$sql .= ' ORDER BY ps.id DESC';

$linksStatement = $pdo->prepare($sql);
$linksStatement->execute($params);
$links = $linksStatement->fetchAll();

$pageTitle = 'Parent-Child Linking';
require __DIR__ . '/../../includes/header.php';
?>

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

    <section class="card">
        <div class="form-header-actions">
            <h3>Linked Parent-Child Records</h3>
            <button type="button" class="btn-toggle-form" onclick="toggleForm('form-link-relation', this)">+ New Link</button>
        </div>
        
        <form method="get" class="filter-bar">
            <input type="text" name="search" placeholder="Search parent/student" value="<?= htmlspecialchars($searchQuery) ?>">
            <button type="submit">Search</button>
            <a href="?" class="toolbar-link-clear">Clear</a>
        </form>
        
        <div class="table-wrap" id="section-table">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Parent</th>
                        <th>Student</th>
                        <th>Relation</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($links as $link): ?>
                        <tr>
                            <td><?= (int)$link['id'] ?></td>
                            <td>
                                <?= htmlspecialchars($link['parent_name']) ?><br>
                                <small><?= htmlspecialchars((string)$link['parent_email']) ?></small>
                            </td>
                            <td>
                                <?= htmlspecialchars((string)$link['student_name']) ?> (<?= htmlspecialchars((string)$link['admission_no']) ?>)<br>
                                <small><?= htmlspecialchars((string)$link['student_email']) ?></small>
                            </td>
                            <td><?= htmlspecialchars((string)$link['relation']) ?></td>
                            <td><?= htmlspecialchars((string)$link['created_at']) ?></td>
                            <td>
                                <?php if ($link['student_status'] === 'inactive'): ?>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Restore student admission to their last class?');">
                                        <input type="hidden" name="action" value="restore_student">
                                        <input type="hidden" name="student_id" value="<?= (int)$link['student_id'] ?>">
                                        <button type="submit" style="background: white; color: var(--secondary); border: 1px solid var(--secondary);">Restore</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Withdraw student from all active classes? The student record will be kept.');">
                                        <input type="hidden" name="action" value="withdraw_student">
                                        <input type="hidden" name="student_id" value="<?= (int)$link['student_id'] ?>">
                                        <button type="submit">Withdraw</button>
                                    </form>
                                <?php endif; ?>

                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($links)): ?>
                        <tr><td colspan="6">No parent-child links found matching your search.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div id="form-link-relation" class="collapsible-form">
        <section class="card">
            <h3>Link Parent & Student</h3>
            <form method="post" class="form-grid form-grid-wide">
                <input type="hidden" name="action" value="link_parent_student">

                <label>Parent</label>
                <select name="parent_id" required>
                    <option value="">Select parent</option>
                    <?php foreach ($parents as $parent): ?>
                        <option value="<?= (int)$parent['id'] ?>"><?= htmlspecialchars($parent['full_name'] . ' (' . $parent['email'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Student</label>
                <select name="student_id" required>
                    <option value="">Select student</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= (int)$student['id'] ?>"><?= htmlspecialchars($student['full_name'] . ' | ' . $student['admission_no']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Relation</label>
                <input type="text" name="relation" value="guardian" placeholder="guardian / father / mother">

                <button type="submit">Save Relation</button>
            </form>
        </section>
    </div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
