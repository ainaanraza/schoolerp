<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/delete_helpers.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$errors = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_course') {
        $courseName = trim($_POST['course_name'] ?? '');
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $teacherId = (int)($_POST['teacher_id'] ?? 0);

        if ($courseName === '' || $sessionId <= 0) {
            $errors[] = 'Course name and academic session are required.';
        } else {
            try {
                $existingCourseStatement = $pdo->prepare(
                    'SELECT id
                     FROM classes
                     WHERE class_name = :course_name
                       AND section = :section
                       AND session_id = :session_id
                     LIMIT 1'
                );
                $existingCourseStatement->execute([
                    'course_name' => $courseName,
                    'section' => 'General',
                    'session_id' => $sessionId,
                ]);
                $existingCourseId = (int)($existingCourseStatement->fetchColumn() ?: 0);

                if ($existingCourseId > 0) {
                    $updateStatement = $pdo->prepare(
                        'UPDATE classes
                         SET class_teacher_id = :teacher_id
                         WHERE id = :id'
                    );
                    $updateStatement->execute([
                        'teacher_id' => $teacherId > 0 ? $teacherId : null,
                        'id' => $existingCourseId,
                    ]);
                    $success[] = 'Course already exists. Teacher assignment updated.';
                } else {
                    $insertStatement = $pdo->prepare(
                        'INSERT INTO classes (class_name, section, session_id, class_teacher_id)
                         VALUES (:course_name, :section, :session_id, :teacher_id)'
                    );
                    $insertStatement->execute([
                        'course_name' => $courseName,
                        'section' => 'General',
                        'session_id' => $sessionId,
                        'teacher_id' => $teacherId > 0 ? $teacherId : null,
                    ]);
                    $success[] = 'Course created successfully.';
                }
            } catch (Throwable $throwable) {
                $errors[] = 'Unable to create course: ' . $throwable->getMessage();
            }
        }
    }

    if ($action === 'assign_teacher') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        $teacherId = (int)($_POST['teacher_id'] ?? 0);

        if ($courseId <= 0) {
            $errors[] = 'Invalid course teacher assignment request.';
        } else {
            try {
                $updateTeacherStatement = $pdo->prepare(
                    'UPDATE classes
                     SET class_teacher_id = :teacher_id
                     WHERE id = :course_id'
                );
                $updateTeacherStatement->execute([
                    'teacher_id' => $teacherId > 0 ? $teacherId : null,
                    'course_id' => $courseId,
                ]);
                $success[] = 'Course teacher updated.';
            } catch (Throwable $throwable) {
                $errors[] = 'Unable to update course teacher: ' . $throwable->getMessage();
            }
        }
    }

    if ($action === 'delete_course') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        if ($courseId <= 0) {
            $errors[] = 'Invalid course delete request.';
        } else {
            try {
                $pdo->beginTransaction();
                cascade_delete_class($pdo, $courseId);
                $pdo->commit();
                $success[] = 'Course deleted with related records.';
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to delete course: ' . $throwable->getMessage();
            }
        }
    }
}

$sessions = $pdo->query(
    'SELECT id, title, start_date, end_date, is_active
     FROM academic_sessions
     ORDER BY is_active DESC, start_date DESC'
)->fetchAll();

$teachers = $pdo->query(
    'SELECT t.id, u.full_name, u.email
     FROM teachers t
     JOIN users u ON u.id = t.user_id
     ORDER BY u.full_name'
)->fetchAll();

$courses = $pdo->query(
    'SELECT c.id, c.class_name AS course_name, c.class_teacher_id,
            s.title AS session_title,
            u.full_name AS course_teacher
     FROM classes c
     JOIN academic_sessions s ON s.id = c.session_id
     LEFT JOIN teachers t ON t.id = c.class_teacher_id
     LEFT JOIN users u ON u.id = t.user_id
     ORDER BY c.class_name'
)->fetchAll();

$pageTitle = 'Course Setup';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Course Setup</h2>

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

    <div class="metrics-grid">
        <div class="metric-card">
            <p class="metric-label">Sessions</p>
            <p class="metric-value"><?= count($sessions) ?></p>
        </div>
        <div class="metric-card">
            <p class="metric-label">Courses</p>
            <p class="metric-value"><?= count($courses) ?></p>
        </div>
        <div class="metric-card">
            <p class="metric-label">Teachers</p>
            <p class="metric-value"><?= count($teachers) ?></p>
        </div>
    </div>

    <section class="card">
        <div class="form-header-actions">
            <div>
                <h3>Course List</h3>
                <p class="muted-inline-note">Single structure model: Course only (no class/subject mapping)</p>
            </div>
            <button type="button" class="btn-toggle-form" onclick="toggleForm('form-course', this)">+ Add Course</button>
        </div>

        <div class="table-wrap">
                <table class="compact-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Course</th>
                        <th>Session</th>
                        <th>Assigned Teacher</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses as $index => $course): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><strong><?= htmlspecialchars((string)$course['course_name']) ?></strong></td>
                            <td><span class="pill"><?= htmlspecialchars((string)$course['session_title']) ?></span></td>
                            <td>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="action" value="assign_teacher">
                                    <input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>">
                                    <select name="teacher_id" onchange="this.form.submit()">
                                        <option value="0">Not assigned</option>
                                        <?php foreach ($teachers as $teacher): ?>
                                            <option value="<?= (int)$teacher['id'] ?>" <?= (int)$course['class_teacher_id'] === (int)$teacher['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string)$teacher['full_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <form method="post" class="inline-form" onsubmit="return confirm('Delete this course?');">
                                    <input type="hidden" name="action" value="delete_course">
                                    <input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>">
                                    <button type="submit" class="danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($courses)): ?>
                        <tr><td colspan="5">No courses created yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </section>
</section>

<div id="form-course" class="collapsible-form">
    <section class="card">
        <h3>Create Course</h3>
        <form method="post" class="form-grid form-grid-wide">
            <input type="hidden" name="action" value="create_course">

            <label>Course Name</label>
            <input type="text" name="course_name" placeholder="Enter course name" required>

            <label>Academic Session</label>
            <select name="session_id" required>
                <option value="">Select session</option>
                <?php foreach ($sessions as $session): ?>
                    <option value="<?= (int)$session['id'] ?>"><?= htmlspecialchars((string)$session['title']) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Course Teacher (optional)</label>
            <select name="teacher_id">
                <option value="0">Not assigned</option>
                <?php foreach ($teachers as $teacher): ?>
                    <option value="<?= (int)$teacher['id'] ?>"><?= htmlspecialchars((string)$teacher['full_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Add Course</button>
        </form>
    </section>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
