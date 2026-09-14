<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT, ROLE_PARENT]);

function get_student_id_by_user(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare('SELECT id FROM students WHERE user_id = :user_id LIMIT 1');
    $statement->execute(['user_id' => $userId]);
    return (int)($statement->fetchColumn() ?: 0);
}

function ensure_homework_upload_directory(string $directory): bool
{
    if (is_dir($directory)) {
        return true;
    }

    return mkdir($directory, 0775, true);
}

function move_homework_file(array $file, int $studentId, array &$errors): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors[] = 'Homework file upload failed. Please try again.';
        return null;
    }

    $maxSize = 8 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxSize) {
        $errors[] = 'Homework file must be 8MB or smaller.';
        return null;
    }

    $originalName = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png'];
    if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
        $errors[] = 'Allowed homework file types: pdf, doc, docx, txt, jpg, jpeg, png.';
        return null;
    }

    $targetDir = __DIR__ . '/../../uploads/homework';
    if (!ensure_homework_upload_directory($targetDir)) {
        $errors[] = 'Unable to create homework upload directory.';
        return null;
    }

    $safeFileName = 'hw_' . $studentId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $targetDir . '/' . $safeFileName;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        $errors[] = 'Unable to save uploaded homework file.';
        return null;
    }

    return 'uploads/homework/' . $safeFileName;
}

function ensure_default_homework_subject(PDO $pdo): int
{
    $existingStatement = $pdo->prepare('SELECT id FROM subjects WHERE subject_name = :subject_name LIMIT 1');
    $existingStatement->execute(['subject_name' => 'Course Work']);
    $existingId = (int)($existingStatement->fetchColumn() ?: 0);
    if ($existingId > 0) {
        return $existingId;
    }

    $insertStatement = $pdo->prepare(
        'INSERT INTO subjects (subject_name, subject_code)
         VALUES (:subject_name, :subject_code)'
    );
    $insertStatement->execute([
        'subject_name' => 'Course Work',
        'subject_code' => 'COURSE',
    ]);

    return (int)$pdo->lastInsertId();
}

$errors = [];
$success = [];
$role = current_role();
$user = current_user();
$studentId = 0;
$teacherId = 0;
$teacherClasses = [];

if ($role === ROLE_TEACHER) {
    $teacherIdStatement = $pdo->prepare('SELECT id FROM teachers WHERE user_id = :user_id LIMIT 1');
    $teacherIdStatement->execute(['user_id' => (int)$user['id']]);
    $teacherId = (int)($teacherIdStatement->fetchColumn() ?: 0);

    if ($teacherId <= 0) {
        $errors[] = 'Teacher profile not linked to this account.';
    } else {
        $teacherClassesStatement = $pdo->prepare(
            'SELECT DISTINCT c.id, c.class_name, c.section
             FROM classes c
             WHERE c.class_teacher_id = :teacher_id
             ORDER BY c.class_name, c.section'
        );
        $teacherClassesStatement->execute(['teacher_id' => $teacherId]);
        $teacherClasses = $teacherClassesStatement->fetchAll();
    }
}

if ($role === ROLE_STUDENT) {
    $studentId = get_student_id_by_user($pdo, (int)$user['id']);
    if ($studentId <= 0) {
        $errors[] = 'Student profile not linked to this account.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_homework' && $role === ROLE_STUDENT) {
    $homeworkId = (int)($_POST['homework_id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    $submittedFile = $_FILES['submission_file'] ?? null;

    if ($studentId <= 0) {
        $errors[] = 'You cannot submit homework until your student profile is linked.';
    }

    if ($homeworkId <= 0) {
        $errors[] = 'Invalid homework selected.';
    }

    $targetHomework = null;
    if (empty($errors)) {
        $targetHomeworkStatement = $pdo->prepare(
            'SELECT h.id, h.due_date
             FROM homework h
             JOIN student_class_enrollments sce
                 ON sce.class_id = h.class_id
                AND sce.student_id = :student_id
                AND sce.is_active = 1
             WHERE h.id = :homework_id
             LIMIT 1'
        );
        $targetHomeworkStatement->execute([
            'student_id' => $studentId,
            'homework_id' => $homeworkId,
        ]);
        $targetHomework = $targetHomeworkStatement->fetch();

        if (!$targetHomework) {
            $errors[] = 'Homework not found for your course or access denied.';
        }
    }

    $existingSubmission = null;
    if (empty($errors)) {
        $existingSubmissionStatement = $pdo->prepare(
            'SELECT id, file_path
             FROM homework_submissions
             WHERE homework_id = :homework_id
               AND student_id = :student_id
             LIMIT 1'
        );
        $existingSubmissionStatement->execute([
            'homework_id' => $homeworkId,
            'student_id' => $studentId,
        ]);
        $existingSubmission = $existingSubmissionStatement->fetch();
    }

    $uploadedFilePath = null;
    if (empty($errors)) {
        if ($submittedFile !== null) {
            $uploadedFilePath = move_homework_file($submittedFile, $studentId, $errors);
        }

        $filePathToSave = $uploadedFilePath ?? (($existingSubmission['file_path'] ?? null) ?: null);
        if ($remarks === '' && $filePathToSave === null) {
            $errors[] = 'Add remarks or upload a file before submitting.';
        }
    }

    if (empty($errors) && $targetHomework) {
        $filePathToSave = $uploadedFilePath ?? (($existingSubmission['file_path'] ?? null) ?: null);
        $isLate = !empty($targetHomework['due_date']) && date('Y-m-d') > (string)$targetHomework['due_date'];
        $submissionStatus = $isLate ? 'late' : 'submitted';

        try {
            $saveSubmissionStatement = $pdo->prepare(
                'INSERT INTO homework_submissions (homework_id, student_id, submitted_at, file_path, remarks, status)
                 VALUES (:homework_id, :student_id, NOW(), :file_path, :remarks, :status)
                 ON DUPLICATE KEY UPDATE
                    submitted_at = VALUES(submitted_at),
                    file_path = VALUES(file_path),
                    remarks = VALUES(remarks),
                    status = VALUES(status)'
            );
            $saveSubmissionStatement->execute([
                'homework_id' => $homeworkId,
                'student_id' => $studentId,
                'file_path' => $filePathToSave,
                'remarks' => $remarks !== '' ? $remarks : null,
                'status' => $submissionStatus,
            ]);
            $success[] = 'Homework submitted successfully.';
        } catch (Throwable $throwable) {
            $errors[] = 'Unable to submit homework: ' . $throwable->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_homework' && $role === ROLE_TEACHER) {
    $classId = (int)($_POST['class_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $dueDate = trim($_POST['due_date'] ?? '');

    if ($teacherId <= 0) {
        $errors[] = 'Teacher profile not linked. Cannot post homework.';
    }

    $allowedClassIds = array_map(static fn(array $row): int => (int)$row['id'], $teacherClasses);
    if ($classId <= 0 || !in_array($classId, $allowedClassIds, true)) {
        $errors[] = 'Invalid course selected.';
    }

    if ($title === '') {
        $errors[] = 'Homework title is required.';
    }

    if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        $errors[] = 'Invalid due date format.';
    }

    if (empty($errors)) {
        try {
            $subjectId = ensure_default_homework_subject($pdo);
            $createHomeworkStatement = $pdo->prepare(
                'INSERT INTO homework (class_id, subject_id, title, description, due_date, posted_by)
                 VALUES (:class_id, :subject_id, :title, :description, :due_date, :posted_by)'
            );
            $createHomeworkStatement->execute([
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'due_date' => $dueDate !== '' ? $dueDate : null,
                'posted_by' => $user['id'],
            ]);
            $success[] = 'Homework posted successfully.';
        } catch (Throwable $throwable) {
            $errors[] = 'Unable to post homework: ' . $throwable->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_homework' && $role === ROLE_TEACHER) {
    $homeworkId = (int)($_POST['homework_id'] ?? 0);

    if ($homeworkId <= 0) {
        $errors[] = 'Invalid homework record.';
    } else {
        try {
            $pdo->beginTransaction();

            $ownedHomeworkCheck = $pdo->prepare(
                'SELECT id
                 FROM homework
                 WHERE id = :id
                   AND posted_by = :posted_by
                 LIMIT 1'
            );
            $ownedHomeworkCheck->execute([
                'id' => $homeworkId,
                'posted_by' => $user['id'],
            ]);

            if (!$ownedHomeworkCheck->fetch()) {
                $pdo->rollBack();
                $errors[] = 'Homework not found or you are not allowed to delete it.';
            } else {
                $deleteSubmissionsStatement = $pdo->prepare(
                    'DELETE FROM homework_submissions
                     WHERE homework_id = :homework_id'
                );
                $deleteSubmissionsStatement->execute(['homework_id' => $homeworkId]);

                $deleteHomeworkStatement = $pdo->prepare(
                    'DELETE FROM homework
                     WHERE id = :id
                       AND posted_by = :posted_by'
                );
                $deleteHomeworkStatement->execute([
                    'id' => $homeworkId,
                    'posted_by' => $user['id'],
                ]);

                $pdo->commit();
                $success[] = 'Homework deleted successfully.';
            }
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Unable to delete homework: ' . $throwable->getMessage();
        }
    }
}

$homeworkRows = [];
if ($role === ROLE_STUDENT && $studentId > 0) {
    $homeworkStatement = $pdo->prepare(
        'SELECT h.id, h.title, h.description, h.due_date, h.created_at,
                c.class_name, c.section,
                hs.submitted_at, hs.file_path AS submission_file, hs.remarks AS submission_remarks, hs.status AS submission_status
         FROM homework h
         JOIN classes c ON c.id = h.class_id
         JOIN student_class_enrollments sce
             ON sce.class_id = h.class_id
            AND sce.student_id = :student_id
            AND sce.is_active = 1
         LEFT JOIN homework_submissions hs
             ON hs.homework_id = h.id
            AND hs.student_id = :student_id
         ORDER BY h.due_date IS NULL, h.due_date ASC, h.created_at DESC'
    );
    $homeworkStatement->execute(['student_id' => $studentId]);
    $homeworkRows = $homeworkStatement->fetchAll();
}

if ($role === ROLE_PARENT) {
    $homeworkStatement = $pdo->prepare(
        'SELECT h.id, h.title, h.description, h.due_date, h.created_at,
                c.class_name, c.section,
                su.full_name AS student_name,
                hs.submitted_at, hs.status AS submission_status
         FROM parents p
         JOIN parent_student ps ON ps.parent_id = p.id
         JOIN students st ON st.id = ps.student_id
         LEFT JOIN users su ON su.id = st.user_id
         JOIN student_class_enrollments sce
             ON sce.student_id = st.id
            AND sce.is_active = 1
         JOIN homework h ON h.class_id = sce.class_id
         JOIN classes c ON c.id = h.class_id
         LEFT JOIN homework_submissions hs
             ON hs.homework_id = h.id
            AND hs.student_id = st.id
         WHERE p.user_id = :user_id
         ORDER BY su.full_name, h.due_date IS NULL, h.due_date ASC, h.created_at DESC'
    );
    $homeworkStatement->execute(['user_id' => $user['id']]);
    $homeworkRows = $homeworkStatement->fetchAll();
}

if (in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_TEACHER], true)) {
    $whereClause = '';
    $params = [];

    if ($role === ROLE_TEACHER) {
        $whereClause = 'WHERE h.posted_by = :posted_by';
        $params['posted_by'] = $user['id'];
    }

    $homeworkStatement = $pdo->prepare(
        'SELECT h.id, h.title, h.description, h.due_date, h.created_at,
                c.class_name, c.section,
                u.full_name AS posted_by_name,
                COUNT(hs.id) AS submission_count,
                SUM(CASE WHEN hs.status = "submitted" THEN 1 ELSE 0 END) AS submitted_count,
                SUM(CASE WHEN hs.status = "late" THEN 1 ELSE 0 END) AS late_count,
                SUM(CASE WHEN hs.status = "pending" THEN 1 ELSE 0 END) AS pending_count
         FROM homework h
         JOIN classes c ON c.id = h.class_id
         LEFT JOIN users u ON u.id = h.posted_by
         LEFT JOIN homework_submissions hs ON hs.homework_id = h.id
         ' . $whereClause . '
            GROUP BY h.id, h.title, h.description, h.due_date, h.created_at, c.class_name, c.section, u.full_name
         ORDER BY h.created_at DESC
         LIMIT 200'
    );
    $homeworkStatement->execute($params);
    $homeworkRows = $homeworkStatement->fetchAll();
}

$pageTitle = 'Homework & Assignments';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <div class="form-header-actions">
        <h2>Homework & Assignments</h2>
        <?php if ($role === ROLE_TEACHER): ?>
            <div>
                <button type="button" class="btn-toggle-form" onclick="toggleForm('form-create-homework', this)">+ Add Homework</button>
            </div>
        <?php endif; ?>
    </div>

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

    <div class="table-wrap" id="section-table">
        <table>
            <thead>
                <tr>
                    <?php if ($role === ROLE_PARENT): ?>
                        <th>Student</th>
                    <?php endif; ?>
                    <th>Course</th>
                    <th>Title</th>
                    <th>Description</th>
                    <th>Due Date</th>
                    <?php if (in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_TEACHER], true)): ?>
                        <th>Posted By</th>
                        <th>Submissions</th>
                        <?php if ($role === ROLE_TEACHER): ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <th>Status</th>
                        <th>Submitted At</th>
                        <th>Submission</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($homeworkRows as $row): ?>
                    <?php
                    $dueDate = $row['due_date'] ?? null;
                    $isOverdue = $dueDate !== null && $dueDate !== '' && date('Y-m-d') > (string)$dueDate;
                    $submissionStatus = (string)($row['submission_status'] ?? 'pending');
                    ?>
                    <tr>
                        <?php if ($role === ROLE_PARENT): ?>
                            <td><?= htmlspecialchars((string)($row['student_name'] ?? '-')) ?></td>
                        <?php endif; ?>
                        <td><?= htmlspecialchars((string)$row['class_name']) ?></td>
                        <td><?= htmlspecialchars((string)$row['title']) ?></td>
                        <td><?= nl2br(htmlspecialchars((string)($row['description'] ?? ''))) ?></td>
                        <td>
                            <?= htmlspecialchars((string)($dueDate ?: '-')) ?>
                            <?php if ($isOverdue && !in_array($submissionStatus, ['submitted', 'late'], true)): ?>
                                <br><span class="pill">overdue</span>
                            <?php endif; ?>
                        </td>

                        <?php if (in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_TEACHER], true)): ?>
                            <td><?= htmlspecialchars((string)($row['posted_by_name'] ?: '-')) ?></td>
                            <td>
                                <span class="pill">total: <?= (int)($row['submission_count'] ?? 0) ?></span>
                                <span class="pill">submitted: <?= (int)($row['submitted_count'] ?? 0) ?></span>
                                <span class="pill">late: <?= (int)($row['late_count'] ?? 0) ?></span>
                                <span class="pill">pending: <?= (int)($row['pending_count'] ?? 0) ?></span>
                            </td>
                            <?php if ($role === ROLE_TEACHER): ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <td><span class="pill"><?= htmlspecialchars($submissionStatus) ?></span></td>
                            <td><?= htmlspecialchars((string)($row['submitted_at'] ?? '-')) ?></td>
                            <td>
                                <?php if ($role === ROLE_STUDENT): ?>
                                    <form method="post" enctype="multipart/form-data" class="form-grid">
                                        <input type="hidden" name="action" value="submit_homework">
                                        <input type="hidden" name="homework_id" value="<?= (int)$row['id'] ?>">

                                        <textarea name="remarks" rows="2" placeholder="Remarks (optional)"><?= htmlspecialchars((string)($row['submission_remarks'] ?? '')) ?></textarea>
                                        <input type="file" name="submission_file" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png">

                                        <?php if (!empty($row['submission_file'])): ?>
                                            <a href="/school-erp/<?= htmlspecialchars((string)$row['submission_file']) ?>" target="_blank" rel="noopener">View current file</a>
                                        <?php endif; ?>

                                        <button type="submit">Submit</button>
                                    </form>
                                <?php else: ?>
                                    <span>-</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($homeworkRows)): ?>
                    <tr>
                        <td colspan="8">No homework records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($role === ROLE_TEACHER): ?>
    <div id="form-create-homework" class="collapsible-form">
        <section class="card">
            <h3>Create Homework</h3>
            <form method="post" class="form-grid form-grid-wide">
                <input type="hidden" name="action" value="create_homework">

                <label>Course</label>
                <select name="class_id" id="teacherHomeworkClass" required>
                    <option value="">Select course</option>
                    <?php foreach ($teacherClasses as $class): ?>
                        <option value="<?= (int)$class['id'] ?>"><?= htmlspecialchars((string)$class['class_name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Title</label>
                <input type="text" name="title" maxlength="150" required>

                <label>Description (optional)</label>
                <textarea name="description" rows="4"></textarea>

                <label>Due Date (optional)</label>
                <input type="date" name="due_date">

                <button type="submit">Post Homework</button>
            </form>
        </section>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
