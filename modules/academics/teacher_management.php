<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$errors = [];
$success = [];

// Search params
$searchQuery = trim($_GET['search'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_teacher') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $employeeCode = trim($_POST['employee_code'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $qualification = trim($_POST['qualification'] ?? '');

        if ($fullName === '' || $email === '' || $password === '') {
            $errors[] = 'Teacher name, email, and password are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format.';
        } else {
            $existsStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $existsStmt->execute(['email' => $email]);
            if ($existsStmt->fetch()) {
                $errors[] = 'An account with this email already exists.';
            } else {
                try {
                    $pdo->beginTransaction();

                    // Create user account
                    $createUserStmt = $pdo->prepare(
                        'INSERT INTO users (full_name, email, password_hash, role, is_active)
                         VALUES (:full_name, :email, :password_hash, :role, :is_active)'
                    );
                    $createUserStmt->execute([
                        'full_name' => $fullName,
                        'email' => $email,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'role' => ROLE_TEACHER,
                        'is_active' => 1,
                    ]);
                    $userId = (int)$pdo->lastInsertId();

                    // Create teacher record
                    $createTeacherStmt = $pdo->prepare(
                        'INSERT INTO teachers (user_id, employee_code, phone, qualification)
                         VALUES (:user_id, :employee_code, :phone, :qualification)'
                    );
                    $createTeacherStmt->execute([
                        'user_id' => $userId,
                        'employee_code' => $employeeCode !== '' ? $employeeCode : null,
                        'phone' => $phone !== '' ? $phone : null,
                        'qualification' => $qualification !== '' ? $qualification : null,
                    ]);

                    $pdo->commit();
                    $success[] = 'Teacher account created successfully.';
                } catch (Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = 'Unable to create teacher: ' . $throwable->getMessage();
                }
            }
        }
    }

    if ($action === 'toggle_teacher') {
        $teacherUserId = (int)($_POST['teacher_user_id'] ?? 0);
        $newState = (int)($_POST['new_state'] ?? 0);

        if ($teacherUserId <= 0 || !in_array($newState, [0, 1], true)) {
            $errors[] = 'Invalid teacher status update request.';
        } else {
            $toggleStmt = $pdo->prepare('UPDATE users SET is_active = :is_active WHERE id = :id AND role = :role');
            $toggleStmt->execute([
                'is_active' => $newState,
                'id' => $teacherUserId,
                'role' => ROLE_TEACHER,
            ]);
            $success[] = $newState === 1 ? 'Teacher activated.' : 'Teacher deactivated.';
        }
    }

    if ($action === 'reset_password') {
        $teacherUserId = (int)($_POST['teacher_user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';

        if ($teacherUserId <= 0 || trim($newPassword) === '') {
            $errors[] = 'Invalid password reset request.';
        } else {
            $resetStmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id AND role = :role');
            $resetStmt->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => $teacherUserId,
                'role' => ROLE_TEACHER,
            ]);
            $success[] = 'Teacher password reset successfully.';
        }
    }

    if ($action === 'update_teacher') {
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $employeeCode = trim($_POST['employee_code'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $qualification = trim($_POST['qualification'] ?? '');

        if ($teacherId <= 0) {
            $errors[] = 'Invalid teacher selected.';
        } else {
            try {
                $updateStmt = $pdo->prepare(
                    'UPDATE teachers
                     SET employee_code = :employee_code, phone = :phone, qualification = :qualification
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    'employee_code' => $employeeCode !== '' ? $employeeCode : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'qualification' => $qualification !== '' ? $qualification : null,
                    'id' => $teacherId,
                ]);
                $success[] = 'Teacher details updated successfully.';
            } catch (Throwable $throwable) {
                $errors[] = 'Unable to update teacher: ' . $throwable->getMessage();
            }
        }
    }
}

$sql = 'SELECT u.id, u.full_name, u.email, u.is_active, u.created_at,
                t.id AS teacher_id, t.employee_code, t.phone, t.qualification
         FROM users u
         LEFT JOIN teachers t ON t.user_id = u.id
         WHERE u.role = :role';
$params = ['role' => ROLE_TEACHER];

if ($searchQuery !== '') {
    $sql .= ' AND (u.full_name LIKE :search OR u.email LIKE :search OR t.employee_code LIKE :search)';
    $params['search'] = '%' . $searchQuery . '%';
}

$sql .= ' ORDER BY u.id DESC';

$teacherStatement = $pdo->prepare($sql);
$teacherStatement->execute($params);
$teachers = $teacherStatement->fetchAll();

$pageTitle = 'Teacher Management';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Teacher Management</h2>

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
            <p class="metric-label">Total Teachers</p>
            <p class="metric-value"><?= count($teachers) ?></p>
        </div>
        <div class="metric-card">
            <p class="metric-label">Active Teachers</p>
            <p class="metric-value"><?= count(array_filter($teachers, static fn(array $row): bool => (int)$row['is_active'] === 1)) ?></p>
        </div>
    </div>

    <section class="card">
        <div class="form-header-actions">
            <h3>Teachers</h3>
            <div class="toolbar-actions-tight">
                <button type="button" class="btn-toggle-form" onclick="toggleForm('form-create-teacher', this)">+ Add Teacher</button>
            </div>
        </div>
        <form method="get" class="filter-bar">
            <input type="text" name="search" placeholder="Search name/email/code" value="<?= htmlspecialchars($searchQuery) ?>">
            <button type="submit">Search</button>
        </form>
        <div class="table-wrap">
            <table class="compact-table staff-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Employee Code</th>
                        <th>Phone</th>
                        <th>Qualification</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($teachers)): ?>
                        <tr>
                            <td colspan="7" class="table-empty-cell">No teachers found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($teachers as $teacher): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$teacher['full_name']) ?></td>
                                <td><?= htmlspecialchars((string)$teacher['email']) ?></td>
                                <td><?= htmlspecialchars((string)($teacher['employee_code'] ?: '—')) ?></td>
                                <td><?= htmlspecialchars((string)($teacher['phone'] ?: '—')) ?></td>
                                <td><?= htmlspecialchars((string)($teacher['qualification'] ?: '—')) ?></td>
                                <td>
                                    <span class="pill <?= (int)$teacher['is_active'] === 1 ? 'active' : 'inactive' ?>">
                                        <?= (int)$teacher['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-menu">
                                        <button type="button" class="action-btn" onclick="toggleMenu(this)">⋮</button>
                                        <div class="action-dropdown">
                                            <button type="button" onclick="openEditModal(<?= (int)$teacher['teacher_id'] ?>, '<?= htmlspecialchars($teacher['employee_code'] ?? '') ?>', '<?= htmlspecialchars($teacher['phone'] ?? '') ?>', '<?= htmlspecialchars($teacher['qualification'] ?? '') ?>')">Edit Details</button>
                                            <?php if ((int)$teacher['is_active'] === 1): ?>
                                                <form method="post" class="inline-display">
                                                    <input type="hidden" name="action" value="toggle_teacher">
                                                    <input type="hidden" name="teacher_user_id" value="<?= (int)$teacher['id'] ?>">
                                                    <input type="hidden" name="new_state" value="0">
                                                    <button type="submit" class="action-danger">Deactivate</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" class="inline-display">
                                                    <input type="hidden" name="action" value="toggle_teacher">
                                                    <input type="hidden" name="teacher_user_id" value="<?= (int)$teacher['id'] ?>">
                                                    <input type="hidden" name="new_state" value="1">
                                                    <button type="submit">Activate</button>
                                                </form>
                                            <?php endif; ?>
                                            <button type="button" onclick="openPasswordModal(<?= (int)$teacher['id'] ?>, '<?= htmlspecialchars($teacher['full_name']) ?>')">Reset Password</button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</section>

<div id="form-create-teacher" class="collapsible-form">
    <section class="card">
        <h3>Create Teacher</h3>
        <form method="post" class="form-grid form-grid-wide">
            <input type="hidden" name="action" value="create_teacher">

            <label>Full Name *</label>
            <input type="text" name="full_name" required>

            <label>Email *</label>
            <input type="email" name="email" required>

            <label>Temporary Password *</label>
            <input type="text" name="password" required>

            <label>Employee Code (optional)</label>
            <input type="text" name="employee_code" placeholder="e.g., EMP001">

            <label>Phone (optional)</label>
            <input type="tel" name="phone" placeholder="e.g., 9876543210">

            <label>Qualification (optional)</label>
            <input type="text" name="qualification" placeholder="e.g., B.Sc, B.Ed">

            <button type="submit">Create Teacher Account</button>
        </form>
    </section>
</div>

<!-- Edit Teacher Modal -->
<div id="editModal" class="modal-shell">
    <div class="modal-shell-panel">
        <h3>Edit Teacher Details</h3>
        <form method="post" class="form-grid form-grid-wide">
            <input type="hidden" name="action" value="update_teacher">
            <input type="hidden" name="teacher_id" id="editTeacherId">

            <label>Employee Code (optional)</label>
            <input type="text" name="employee_code" id="editEmployeeCode" placeholder="e.g., EMP001">

            <label>Phone (optional)</label>
            <input type="tel" name="phone" id="editPhone" placeholder="e.g., 9876543210">

            <label>Qualification (optional)</label>
            <input type="text" name="qualification" id="editQualification" placeholder="e.g., B.Sc, B.Ed">

            <div class="modal-shell-actions">
                <button type="submit">Save Changes</button>
                <button type="button" onclick="closeEditModal()" class="btn-neutral">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Password Reset Modal -->
<div id="passwordModal" class="modal-shell">
    <div class="modal-shell-panel">
        <h3>Reset Password</h3>
        <p id="passwordTeacherName"></p>
        <form method="post" class="form-grid form-grid-wide">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="teacher_user_id" id="resetTeacherId">

            <label>New Password</label>
            <input type="text" name="new_password" required placeholder="Enter new password">

            <div class="modal-shell-actions">
                <button type="submit">Reset Password</button>
                <button type="button" onclick="closePasswordModal()" class="btn-neutral">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleMenu(btn) {
    const dropdown = btn.nextElementSibling;
    const isOpen = window.getComputedStyle(dropdown).display !== 'none';
    dropdown.style.display = isOpen ? 'none' : 'block';
}

function openEditModal(teacherId, employeeCode, phone, qualification) {
    document.getElementById('editTeacherId').value = teacherId;
    document.getElementById('editEmployeeCode').value = employeeCode;
    document.getElementById('editPhone').value = phone;
    document.getElementById('editQualification').value = qualification;
    document.getElementById('editModal').style.display = 'block';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

function openPasswordModal(userId, fullName) {
    document.getElementById('resetTeacherId').value = userId;
    document.getElementById('passwordTeacherName').textContent = 'Reset password for: ' + fullName;
    document.getElementById('passwordModal').style.display = 'block';
}

function closePasswordModal() {
    document.getElementById('passwordModal').style.display = 'none';
}

document.addEventListener('click', function(evt) {
    if (!evt.target.closest('.action-menu')) {
        document.querySelectorAll('.action-dropdown').forEach(el => el.style.display = 'none');
    }
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
