<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/delete_helpers.php';
require_roles([ROLE_SUPER_ADMIN]);

$errors = [];
$success = [];

$school = $pdo->query('SELECT id, name, logo_path FROM schools ORDER BY id ASC LIMIT 1')->fetch();
if (!$school) {
    $pdo->exec("INSERT INTO schools (name) VALUES ('My School')");
    $school = $pdo->query('SELECT id, name, logo_path FROM schools ORDER BY id ASC LIMIT 1')->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_school') {
        $name = trim($_POST['school_name'] ?? '');
        $logoPath = trim($_POST['logo_path'] ?? '');

        if ($name === '') {
            $errors[] = 'School name is required.';
        } else {
            $stmt = $pdo->prepare('UPDATE schools SET name = :name, logo_path = :logo_path WHERE id = :id');
            $stmt->execute([
                'name' => $name,
                'logo_path' => $logoPath !== '' ? $logoPath : null,
                'id' => $school['id'],
            ]);
            $success[] = 'School settings updated.';
        }
    }

    if ($action === 'create_session') {
        $title = trim($_POST['title'] ?? '');
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($title === '' || $startDate === '' || $endDate === '') {
            $errors[] = 'Session title and date range are required.';
        } elseif ($startDate > $endDate) {
            $errors[] = 'Session start date cannot be after end date.';
        } else {
            try {
                $pdo->beginTransaction();

                if ($isActive === 1) {
                    $pdo->exec('UPDATE academic_sessions SET is_active = 0');
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO academic_sessions (school_id, title, start_date, end_date, is_active)
                     VALUES (:school_id, :title, :start_date, :end_date, :is_active)'
                );
                $stmt->execute([
                    'school_id' => $school['id'],
                    'title' => $title,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'is_active' => $isActive,
                ]);

                $pdo->commit();
                $success[] = 'Academic session created.';
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to create session: ' . $throwable->getMessage();
            }
        }
    }

    if ($action === 'activate_session') {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        if ($sessionId <= 0) {
            $errors[] = 'Invalid session selected.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->exec('UPDATE academic_sessions SET is_active = 0');
                $stmt = $pdo->prepare('UPDATE academic_sessions SET is_active = 1 WHERE id = :id');
                $stmt->execute(['id' => $sessionId]);
                $pdo->commit();
                $success[] = 'Academic session activated.';
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to activate session: ' . $throwable->getMessage();
            }
        }
    }

    if ($action === 'delete_session') {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        if ($sessionId <= 0) {
            $errors[] = 'Invalid session delete request.';
        } else {
            try {
                $pdo->beginTransaction();

                $classIdsStmt = $pdo->prepare('SELECT id FROM classes WHERE session_id = :session_id');
                $classIdsStmt->execute(['session_id' => $sessionId]);
                $classIds = array_map(static fn(array $row): int => (int)$row['id'], $classIdsStmt->fetchAll());

                foreach ($classIds as $classId) {
                    cascade_delete_class($pdo, $classId);
                }

                $deleteSession = $pdo->prepare('DELETE FROM academic_sessions WHERE id = :id');
                $deleteSession->execute(['id' => $sessionId]);

                if ($deleteSession->rowCount() < 1) {
                    throw new RuntimeException('Session not found or could not be deleted.');
                }

                $pdo->commit();
                $success[] = 'Academic session deleted with related classes and records.';
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to delete session: ' . $throwable->getMessage();
            }
        }
    }

    $school = $pdo->query('SELECT id, name, logo_path FROM schools ORDER BY id ASC LIMIT 1')->fetch();
}

$sessions = $pdo->query(
    'SELECT a.id, a.title, a.start_date, a.end_date, a.is_active,
            COUNT(c.id) AS class_count
     FROM academic_sessions a
     LEFT JOIN classes c ON c.session_id = a.id
     GROUP BY a.id, a.title, a.start_date, a.end_date, a.is_active
     ORDER BY a.is_active DESC, a.start_date DESC'
)->fetchAll();

$pageTitle = 'System Academic Settings';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>School System Settings</h2>

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

    <div class="grid-2">
        <section class="card">
            <h3>School Profile</h3>
            <form method="post" class="form-grid form-grid-wide">
                <input type="hidden" name="action" value="update_school">

                <label>School Name</label>
                <input type="text" name="school_name" value="<?= htmlspecialchars((string)$school['name']) ?>" required>

                <label>Logo Path (optional)</label>
                <input type="text" name="logo_path" value="<?= htmlspecialchars((string)$school['logo_path']) ?>" placeholder="/assets/images/logo.png">

                <button type="submit">Save School Settings</button>
            </form>
        </section>

        <section class="card">
            <h3>Create Academic Session</h3>
            <form method="post" class="form-grid form-grid-wide">
                <input type="hidden" name="action" value="create_session">

                <label>Session Title</label>
                <input type="text" name="title" placeholder="2026-2027" required>

                <label>Start Date</label>
                <input type="date" name="start_date" required>

                <label>End Date</label>
                <input type="date" name="end_date" required>

                <label>
                    <input type="checkbox" name="is_active" checked>
                    Mark as active session
                </label>

                <button type="submit">Add Session</button>
            </form>
        </section>
    </div>

    <section class="card">
        <h3>Academic Sessions</h3>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Duration</th>
                        <th>Classes</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessions as $session): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$session['title']) ?></td>
                            <td><?= htmlspecialchars((string)$session['start_date']) ?> → <?= htmlspecialchars((string)$session['end_date']) ?></td>
                            <td><?= (int)$session['class_count'] ?></td>
                            <td><span class="pill"><?= (int)$session['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                            <td>
                                <?php if ((int)$session['is_active'] !== 1): ?>
                                    <form method="post" class="inline-form" style="margin-bottom:6px;">
                                        <input type="hidden" name="action" value="activate_session">
                                        <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                                        <button type="submit">Set Active</button>
                                    </form>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this session and all related classes/records?');">
                                        <input type="hidden" name="action" value="delete_session">
                                        <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                                        <button type="submit" class="danger">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span>Current Session</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($sessions)): ?>
                        <tr><td colspan="5">No academic sessions found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
