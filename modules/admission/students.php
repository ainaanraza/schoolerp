<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/delete_helpers.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_TEACHER]);

$role = current_role();
$students = [];
$canEditStudents = in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN], true);
$errors = [];
$success = [];
$credentialPreview = null;

if (isset($_GET['imported']) && $canEditStudents) {
    $imported = (int)($_GET['imported'] ?? 0);
    $updated = (int)($_GET['updated'] ?? 0);
    $failed = (int)($_GET['failed'] ?? 0);
    $success[] = 'Student import completed. Inserted: ' . $imported . ', Updated: ' . $updated . ', Failed: ' . $failed . '.';
}
if (isset($_GET['import_error']) && $canEditStudents) {
    $errors[] = (string)$_GET['import_error'];
}

function generate_temp_password(int $length = 10): string
{
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
    $maxIndex = strlen($characters) - 1;
    $password = '';

    for ($index = 0; $index < $length; $index++) {
        $password .= $characters[random_int(0, $maxIndex)];
    }

    return $password;
}

function ensure_student_credentials_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS student_login_credentials (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            student_email VARCHAR(150) NOT NULL,
            student_password_plain VARCHAR(255) NOT NULL,
            parent_email VARCHAR(150) NULL,
            parent_password_plain VARCHAR(255) NULL,
            generated_by INT NULL,
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_credential (student_id),
            FOREIGN KEY (student_id) REFERENCES students(id),
            FOREIGN KEY (generated_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

// Get search params
$searchName = trim($_GET['search_name'] ?? '');
$searchNo = trim($_GET['search_no'] ?? '');

$baseSql =
    'SELECT
        s.id,
        s.admission_no,
        s.roll_number,
        s.status,
        s.created_at,
        su.full_name AS student_name,
        su.email AS student_email,
        pu.full_name AS parent_name,
        pu.email AS parent_email,
        CONCAT(c.class_name, " - ", c.section) AS class_label
     FROM students s
     LEFT JOIN users su ON su.id = s.user_id
     LEFT JOIN parent_student ps ON ps.student_id = s.id
     LEFT JOIN parents p ON p.id = ps.parent_id
     LEFT JOIN users pu ON pu.id = p.user_id
     LEFT JOIN student_class_enrollments sce ON sce.student_id = s.id AND sce.is_active = 1
     LEFT JOIN classes c ON c.id = sce.class_id';

$whereClauses = [];
$params = [];

if ($role === ROLE_TEACHER) {
    $teacherIdStatement = $pdo->prepare('SELECT id FROM teachers WHERE user_id = :user_id LIMIT 1');
    $teacherIdStatement->execute(['user_id' => current_user()['id']]);
    $teacherId = (int)($teacherIdStatement->fetchColumn() ?: 0);

    if ($teacherId > 0) {
        $whereClauses[] = '(c.class_teacher_id = :teacher_id OR EXISTS (SELECT 1 FROM class_subjects cs WHERE cs.class_id = c.id AND cs.teacher_id = :teacher_id))';
        $params['teacher_id'] = $teacherId;
    } else {
        $whereClauses[] = '1 = 0'; // No teacher profile
    }
}

if ($searchName !== '') {
    $whereClauses[] = '(su.full_name LIKE :search_name OR pu.full_name LIKE :search_name)';
    $params['search_name'] = '%' . $searchName . '%';
}
if ($searchNo !== '') {
    $whereClauses[] = 's.admission_no LIKE :search_no';
    $params['search_no'] = '%' . $searchNo . '%';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEditStudents) {
    $action = $_POST['action'] ?? '';
    $studentId = (int)($_POST['student_id'] ?? 0);

    if ($studentId <= 0) {
        $errors[] = 'Invalid student selection for requested action.';
    }

    if (empty($errors) && in_array($action, ['view_credentials', 'regenerate_credentials'], true)) {
        try {
            ensure_student_credentials_table($pdo);
        } catch (Throwable $throwable) {
            $errors[] = 'Unable to prepare credentials storage: ' . $throwable->getMessage();
        }
    }

    if (empty($errors) && $action === 'view_credentials') {
        $credentialStmt = $pdo->prepare(
            'SELECT s.id, s.admission_no,
                    su.full_name AS student_name,
                    su.email AS student_email,
                    pu.email AS parent_email,
                    slc.student_email AS saved_student_email,
                    slc.student_password_plain,
                    slc.parent_email AS saved_parent_email,
                    slc.parent_password_plain,
                    slc.updated_at
             FROM students s
             LEFT JOIN users su ON su.id = s.user_id
             LEFT JOIN parent_student ps ON ps.student_id = s.id
             LEFT JOIN parents p ON p.id = ps.parent_id
             LEFT JOIN users pu ON pu.id = p.user_id
             LEFT JOIN student_login_credentials slc ON slc.student_id = s.id
             WHERE s.id = :student_id
             LIMIT 1'
        );
        $credentialStmt->execute(['student_id' => $studentId]);
        $credentialRow = $credentialStmt->fetch();

        if (!$credentialRow) {
            $errors[] = 'Student not found.';
        } elseif (empty($credentialRow['student_password_plain'])) {
            $errors[] = 'No stored credentials found for this student. Use Regenerate to create and view credentials.';
        } else {
            $credentialPreview = [
                'student_name' => (string)$credentialRow['student_name'],
                'admission_no' => (string)$credentialRow['admission_no'],
                'student_email' => (string)($credentialRow['saved_student_email'] ?: $credentialRow['student_email']),
                'student_password' => (string)$credentialRow['student_password_plain'],
                'parent_email' => (string)($credentialRow['saved_parent_email'] ?: $credentialRow['parent_email']),
                'parent_password' => (string)($credentialRow['parent_password_plain'] ?? ''),
                'updated_at' => (string)($credentialRow['updated_at'] ?? ''),
            ];
            $success[] = 'Stored credentials loaded.';
        }
    }

    if (empty($errors) && $action === 'regenerate_credentials') {
        try {
            $pdo->beginTransaction();

            $accountStmt = $pdo->prepare(
                'SELECT s.id, s.user_id, s.admission_no,
                        su.full_name AS student_name,
                        su.email AS student_email,
                        pu.id AS parent_user_id,
                        pu.email AS parent_email
                 FROM students s
                 LEFT JOIN users su ON su.id = s.user_id
                 LEFT JOIN parent_student ps ON ps.student_id = s.id
                 LEFT JOIN parents p ON p.id = ps.parent_id
                 LEFT JOIN users pu ON pu.id = p.user_id
                 WHERE s.id = :student_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $accountStmt->execute(['student_id' => $studentId]);
            $account = $accountStmt->fetch();

            if (!$account || (int)($account['user_id'] ?? 0) <= 0) {
                throw new RuntimeException('Student login account not found.');
            }

            $newStudentPassword = generate_temp_password();
            $updateStudentUserStmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $updateStudentUserStmt->execute([
                'password_hash' => password_hash($newStudentPassword, PASSWORD_DEFAULT),
                'id' => (int)$account['user_id'],
            ]);

            $newParentPassword = null;
            $parentUserId = (int)($account['parent_user_id'] ?? 0);
            if ($parentUserId > 0) {
                $newParentPassword = generate_temp_password();
                $updateParentUserStmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
                $updateParentUserStmt->execute([
                    'password_hash' => password_hash($newParentPassword, PASSWORD_DEFAULT),
                    'id' => $parentUserId,
                ]);
            }

            $saveCredentialStmt = $pdo->prepare(
                'INSERT INTO student_login_credentials (
                    student_id,
                    student_email,
                    student_password_plain,
                    parent_email,
                    parent_password_plain,
                    generated_by
                ) VALUES (
                    :student_id,
                    :student_email,
                    :student_password_plain,
                    :parent_email,
                    :parent_password_plain,
                    :generated_by
                )
                ON DUPLICATE KEY UPDATE
                    student_email = VALUES(student_email),
                    student_password_plain = VALUES(student_password_plain),
                    parent_email = VALUES(parent_email),
                    parent_password_plain = VALUES(parent_password_plain),
                    generated_by = VALUES(generated_by),
                    updated_at = CURRENT_TIMESTAMP'
            );
            $saveCredentialStmt->execute([
                'student_id' => $studentId,
                'student_email' => (string)$account['student_email'],
                'student_password_plain' => $newStudentPassword,
                'parent_email' => (string)($account['parent_email'] ?? ''),
                'parent_password_plain' => $newParentPassword,
                'generated_by' => (int)current_user()['id'],
            ]);

            $pdo->commit();

            $credentialPreview = [
                'student_name' => (string)$account['student_name'],
                'admission_no' => (string)$account['admission_no'],
                'student_email' => (string)$account['student_email'],
                'student_password' => $newStudentPassword,
                'parent_email' => (string)($account['parent_email'] ?? ''),
                'parent_password' => (string)($newParentPassword ?? ''),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $success[] = 'Credentials regenerated and saved.';
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Unable to regenerate credentials: ' . $throwable->getMessage();
        }
    }

    if (empty($errors) && $action === 'delete_student') {
        try {
            $pdo->beginTransaction();
            cascade_delete_student($pdo, $studentId);

            $pdo->commit();
            $success[] = 'Student deleted successfully.';
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Unable to delete student: ' . $throwable->getMessage();
        }
    }
}

if (!empty($whereClauses)) {
    $baseSql .= ' WHERE ' . implode(' AND ', $whereClauses);
}

$baseSql .= ' ORDER BY s.id DESC';

$studentsStatement = $pdo->prepare($baseSql);
$studentsStatement->execute($params);
$students = $studentsStatement->fetchAll();

$pageTitle = 'Student Management';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Student Management</h2>
    <p>View enrolled students, linked parent account, and class mapping.</p>

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

    <?php if ($credentialPreview && $canEditStudents): ?>
        <div class="card">
            <h3>Student Credentials</h3>
            <p><strong>Student:</strong> <?= htmlspecialchars($credentialPreview['student_name']) ?> | Admission No: <?= htmlspecialchars($credentialPreview['admission_no']) ?></p>
            <p><strong>Student Login:</strong> <?= htmlspecialchars($credentialPreview['student_email']) ?> / <?= htmlspecialchars($credentialPreview['student_password']) ?></p>
            <p><strong>Parent Login:</strong> <?= htmlspecialchars((string)$credentialPreview['parent_email']) ?> / <?= htmlspecialchars((string)$credentialPreview['parent_password']) ?></p>
            <?php if ($credentialPreview['updated_at'] !== ''): ?>
                <p><small>Last generated: <?= htmlspecialchars($credentialPreview['updated_at']) ?></small></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
        <div class="success">
            <p>Student details updated successfully.</p>
        </div>
    <?php endif; ?>

    <div style="display:flex; gap:8px; align-items:center; justify-content:space-between; flex-wrap:wrap;">
        <form method="get" class="filter-bar" style="margin-bottom:0;">
            <input type="text" name="search_name" placeholder="Search by Student or Parent" value="<?= htmlspecialchars($searchName) ?>">
            <input type="text" name="search_no" placeholder="Admission No" value="<?= htmlspecialchars($searchNo) ?>">
            <button type="submit">Search</button>
            <a href="?clear=1" style="font-size:0.85rem; color:var(--primary); text-decoration:none; font-weight:600;">Clear</a>
        </form>

        <?php if ($canEditStudents): ?>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <a class="nav-item" href="/school-erp/modules/admission/export_students_excel.php?search_name=<?= urlencode($searchName) ?>&search_no=<?= urlencode($searchNo) ?>">Download Excel</a>
                <form method="post" action="/school-erp/modules/admission/import_students_excel.php" enctype="multipart/form-data" class="inline-form">
                    <input type="file" name="excel_file" accept=".csv,.txt" required>
                    <button type="submit">Upload Excel</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="table-wrap" id="section-table">
        <table class="student-table compact-student-table">
            <thead>
                <tr>
                    <th>Admission No</th>
                    <th>Student</th>
                    <th>Parent</th>
                    <th>Class</th>
                    <th>Status</th>
                    <th>Created</th>
                    <?php if ($canEditStudents): ?>
                        <th>Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td><?= htmlspecialchars($student['admission_no']) ?></td>
                        <td>
                            <?= htmlspecialchars((string)$student['student_name']) ?><br>
                            <small><?= htmlspecialchars((string)$student['student_email']) ?></small>
                        </td>
                        <td>
                            <?= htmlspecialchars((string)$student['parent_name']) ?><br>
                            <small><?= htmlspecialchars((string)$student['parent_email']) ?></small>
                        </td>
                        <td><?= htmlspecialchars((string)($student['class_label'] ?: 'Not assigned')) ?></td>
                        <td><span class="pill"><?= htmlspecialchars($student['status']) ?></span></td>
                        <td><?= htmlspecialchars((string)$student['created_at']) ?></td>
                        <?php if ($canEditStudents): ?>
                            <td>
                                <div class="student-actions">
                                    <a class="nav-item student-action-link" href="/school-erp/modules/admission/edit_student.php?id=<?= (int)$student['id'] ?>">Edit</a>
                                    <form method="post" class="inline-form student-action-form">
                                        <input type="hidden" name="action" value="view_credentials">
                                        <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                                        <button type="submit" class="btn-compact">View Credentials</button>
                                    </form>
                                    <form method="post" class="inline-form student-action-form" onsubmit="return confirm('Regenerate student and parent passwords for this record?');">
                                        <input type="hidden" name="action" value="regenerate_credentials">
                                        <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                                        <button type="submit" class="btn-compact">Regenerate Credentials</button>
                                    </form>
                                    <form method="post" class="inline-form student-action-form" onsubmit="return confirm('Delete this student permanently? This action cannot be undone.');">
                                        <input type="hidden" name="action" value="delete_student">
                                        <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                                        <button type="submit" class="danger">Delete</button>
                                    </form>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="<?= $canEditStudents ? 7 : 6 ?>">No students found matching your criteria.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
