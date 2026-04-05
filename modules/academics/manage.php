<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/delete_helpers.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$errors = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_subject') {
        $subjectName = trim($_POST['subject_name'] ?? '');
        $subjectCode = trim($_POST['subject_code'] ?? '');

        if ($subjectName === '') {
            $errors[] = 'Subject name is required.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO subjects (subject_name, subject_code) VALUES (:subject_name, :subject_code)'
                );
                $stmt->execute([
                    'subject_name' => $subjectName,
                    'subject_code' => $subjectCode !== '' ? $subjectCode : null,
                ]);
                $success[] = 'Subject created successfully.';
            } catch (Throwable $throwable) {
                $errors[] = 'Unable to create subject: ' . $throwable->getMessage();
            }
        }
    }

    if ($action === 'create_class') {
        $className = trim($_POST['class_name'] ?? '');
        $section = trim($_POST['section'] ?? '');
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $classTeacherId = (int)($_POST['class_teacher_id'] ?? 0);

        if ($className === '' || $section === '' || $sessionId <= 0) {
            $errors[] = 'Class name, section, and session are required.';
        } else {
            try {
                $existingClassStatement = $pdo->prepare(
                    'SELECT id, class_teacher_id
                     FROM classes
                     WHERE class_name = :class_name
                       AND section = :section
                       AND session_id = :session_id
                     LIMIT 1'
                );
                $existingClassStatement->execute([
                    'class_name' => $className,
                    'section' => $section,
                    'session_id' => $sessionId,
                ]);
                $existingClass = $existingClassStatement->fetch();

                if ($existingClass) {
                    if ($classTeacherId > 0 && (int)$existingClass['class_teacher_id'] !== $classTeacherId) {
                        $updateClassTeacherStatement = $pdo->prepare(
                            'UPDATE classes
                             SET class_teacher_id = :class_teacher_id
                             WHERE id = :id'
                        );
                        $updateClassTeacherStatement->execute([
                            'class_teacher_id' => $classTeacherId,
                            'id' => $existingClass['id'],
                        ]);
                        $success[] = 'Class already exists. Class teacher updated.';
                    } else {
                        $errors[] = 'This class and section already exists for the selected session.';
                    }
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO classes (class_name, section, session_id, class_teacher_id)
                         VALUES (:class_name, :section, :session_id, :class_teacher_id)'
                    );
                    $stmt->execute([
                        'class_name' => $className,
                        'section' => $section,
                        'session_id' => $sessionId,
                        'class_teacher_id' => $classTeacherId > 0 ? $classTeacherId : null,
                    ]);
                    $success[] = 'Class created successfully.';
                }
            } catch (Throwable $throwable) {
                $errors[] = 'Unable to create class: ' . $throwable->getMessage();
            }
        }
    }

    if ($action === 'assign_subject') {
        $classId = (int)($_POST['class_id'] ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $teacherId = (int)($_POST['teacher_id'] ?? 0);

        if ($classId <= 0 || $subjectId <= 0) {
            $errors[] = 'Class and subject are required for assignment.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO class_subjects (class_id, subject_id, teacher_id)
                     VALUES (:class_id, :subject_id, :teacher_id)
                     ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id)'
                );
                $stmt->execute([
                    'class_id' => $classId,
                    'subject_id' => $subjectId,
                    'teacher_id' => $teacherId > 0 ? $teacherId : null,
                ]);
                $success[] = 'Subject assignment saved.';
            } catch (Throwable $throwable) {
                $errors[] = 'Unable to assign subject: ' . $throwable->getMessage();
            }
        }
    }

    if ($action === 'delete_mapping') {
        $mappingId = (int)($_POST['mapping_id'] ?? 0);
        if ($mappingId <= 0) {
            $errors[] = 'Invalid mapping delete request.';
        } else {
            $deleteMapping = $pdo->prepare('DELETE FROM class_subjects WHERE id = :id');
            $deleteMapping->execute(['id' => $mappingId]);
            $success[] = 'Class-subject mapping deleted.';
        }
    }

    if ($action === 'delete_subject') {
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        if ($subjectId <= 0) {
            $errors[] = 'Invalid subject delete request.';
        } else {
            try {
                $pdo->beginTransaction();
                cascade_delete_subject($pdo, $subjectId);
                $pdo->commit();
                $success[] = 'Subject deleted with related mappings/homework.';
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to delete subject: ' . $throwable->getMessage();
            }
        }
    }

    if ($action === 'delete_class') {
        $classId = (int)($_POST['class_id'] ?? 0);
        if ($classId <= 0) {
            $errors[] = 'Invalid class delete request.';
        } else {
            try {
                $pdo->beginTransaction();
                cascade_delete_class($pdo, $classId);
                $pdo->commit();
                $success[] = 'Class deleted with related records.';
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to delete class: ' . $throwable->getMessage();
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

$classes = $pdo->query(
    'SELECT c.id, c.class_name, c.section, c.session_id,
            s.title AS session_title,
            u.full_name AS class_teacher
     FROM classes c
     JOIN academic_sessions s ON s.id = c.session_id
     LEFT JOIN teachers t ON t.id = c.class_teacher_id
     LEFT JOIN users u ON u.id = t.user_id
     ORDER BY c.class_name, c.section'
)->fetchAll();

$subjects = $pdo->query(
    'SELECT id, subject_name, subject_code
     FROM subjects
     ORDER BY subject_name'
)->fetchAll();

$mappings = $pdo->query(
    'SELECT cs.id,
            c.class_name, c.section,
            s.subject_name, s.subject_code,
            u.full_name AS teacher_name
     FROM class_subjects cs
     JOIN classes c ON c.id = cs.class_id
     JOIN subjects s ON s.id = cs.subject_id
     LEFT JOIN teachers t ON t.id = cs.teacher_id
     LEFT JOIN users u ON u.id = t.user_id
     ORDER BY c.class_name, c.section, s.subject_name'
)->fetchAll();

$pageTitle = 'Academic Setup';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Academic Setup</h2>

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
            <p class="metric-label">Academic Sessions</p>
            <p class="metric-value"><?= count($sessions) ?></p>
        </div>
        <div class="metric-card">
            <p class="metric-label">Classes</p>
            <p class="metric-value"><?= count($classes) ?></p>
        </div>
        <div class="metric-card">
            <p class="metric-label">Subjects</p>
            <p class="metric-value"><?= count($subjects) ?></p>
        </div>
        <div class="metric-card">
            <p class="metric-label">Class-Subject Mappings</p>
            <p class="metric-value"><?= count($mappings) ?></p>
        </div>
    </div>

    <div class="grid-2">
        <section class="card">
            <h3>Create Subject</h3>
            <form method="post" class="form-grid form-grid-wide">
                <input type="hidden" name="action" value="create_subject">
                <label>Subject Name</label>
                <input type="text" name="subject_name" required>

                <label>Subject Code (optional)</label>
                <input type="text" name="subject_code">

                <button type="submit">Add Subject</button>
            </form>
        </section>

        <section class="card">
            <h3>Create Class & Section</h3>
            <form method="post" class="form-grid form-grid-wide">
                <input type="hidden" name="action" value="create_class">
                <label>Class Name</label>
                <input type="text" name="class_name" placeholder="Grade 5" required>

                <label>Section</label>
                <input type="text" name="section" placeholder="A" required>

                <label>Academic Session</label>
                <select name="session_id" required>
                    <option value="">Select session</option>
                    <?php foreach ($sessions as $session): ?>
                        <option value="<?= (int)$session['id'] ?>"><?= htmlspecialchars($session['title']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Class Teacher (optional)</label>
                <select name="class_teacher_id">
                    <option value="0">Not assigned</option>
                    <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= (int)$teacher['id'] ?>"><?= htmlspecialchars($teacher['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="submit">Add Class</button>
            </form>
        </section>
    </div>

    <section class="card">
        <h3>Assign Subject to Class</h3>
        <form method="post" class="form-grid form-grid-wide">
            <input type="hidden" name="action" value="assign_subject">

            <label>Class</label>
            <select name="class_id" required>
                <option value="">Select class</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= (int)$class['id'] ?>"><?= htmlspecialchars($class['class_name'] . ' - ' . $class['section'] . ' (' . $class['session_title'] . ')') ?></option>
                <?php endforeach; ?>
            </select>

            <label>Subject</label>
            <select name="subject_id" required>
                <option value="">Select subject</option>
                <?php foreach ($subjects as $subject): ?>
                    <option value="<?= (int)$subject['id'] ?>"><?= htmlspecialchars($subject['subject_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Teacher (optional)</label>
            <select name="teacher_id">
                <option value="0">Not assigned</option>
                <?php foreach ($teachers as $teacher): ?>
                    <option value="<?= (int)$teacher['id'] ?>"><?= htmlspecialchars($teacher['full_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Save Assignment</button>
        </form>
    </section>

    <div class="grid-3">
        <section class="card">
            <h3>Classes</h3>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Session</th>
                            <th>Teacher</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $class): ?>
                            <tr>
                                <td><?= htmlspecialchars($class['class_name'] . ' - ' . $class['section']) ?></td>
                                <td><?= htmlspecialchars((string)$class['session_title']) ?></td>
                                <td><?= htmlspecialchars((string)($class['class_teacher'] ?: '—')) ?></td>
                                <td>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this class and all related data?');">
                                        <input type="hidden" name="action" value="delete_class">
                                        <input type="hidden" name="class_id" value="<?= (int)$class['id'] ?>">
                                        <button type="submit" class="danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($classes)): ?>
                            <tr><td colspan="4">No classes found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h3>Subjects</h3>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Code</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $subject): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$subject['subject_name']) ?></td>
                                <td><?= htmlspecialchars((string)$subject['subject_code']) ?></td>
                                <td>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this subject and all related mappings/homework?');">
                                        <input type="hidden" name="action" value="delete_subject">
                                        <input type="hidden" name="subject_id" value="<?= (int)$subject['id'] ?>">
                                        <button type="submit" class="danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($subjects)): ?>
                            <tr><td colspan="3">No subjects found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h3>Class-Subject-Teacher</h3>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Teacher</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mappings as $mapping): ?>
                            <tr>
                                <td><?= htmlspecialchars($mapping['class_name'] . '-' . $mapping['section']) ?></td>
                                <td><?= htmlspecialchars((string)$mapping['subject_name']) ?></td>
                                <td><?= htmlspecialchars((string)($mapping['teacher_name'] ?: '—')) ?></td>
                                <td>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this class-subject mapping?');">
                                        <input type="hidden" name="action" value="delete_mapping">
                                        <input type="hidden" name="mapping_id" value="<?= (int)$mapping['id'] ?>">
                                        <button type="submit" class="danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($mappings)): ?>
                            <tr><td colspan="4">No mappings found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
