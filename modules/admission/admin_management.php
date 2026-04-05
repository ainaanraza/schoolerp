<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/delete_helpers.php';
require_roles([ROLE_SUPER_ADMIN]);

$errors = [];
$success = [];

// Search params
$searchQuery = trim($_GET['search'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_admin') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($fullName === '' || $email === '' || $password === '') {
            $errors[] = 'Name, email, and password are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid admin email format.';
        } else {
            $existsStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $existsStmt->execute(['email' => $email]);
            if ($existsStmt->fetch()) {
                $errors[] = 'An account with this email already exists.';
            } else {
                $createStmt = $pdo->prepare(
                    'INSERT INTO users (full_name, email, password_hash, role, is_active)
                     VALUES (:full_name, :email, :password_hash, :role, :is_active)'
                );
                $createStmt->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => ROLE_ADMIN,
                    'is_active' => 1,
                ]);
                $success[] = 'Admin user created successfully.';
            }
        }
    }

    if ($action === 'toggle_admin') {
        $adminUserId = (int)($_POST['admin_user_id'] ?? 0);
        $newState = (int)($_POST['new_state'] ?? 0);

        if ($adminUserId <= 0 || !in_array($newState, [0, 1], true)) {
            $errors[] = 'Invalid admin status update request.';
        } else {
            $toggleStmt = $pdo->prepare('UPDATE users SET is_active = :is_active WHERE id = :id AND role = :role');
            $toggleStmt->execute([
                'is_active' => $newState,
                'id' => $adminUserId,
                'role' => ROLE_ADMIN,
            ]);
            $success[] = $newState === 1 ? 'Admin activated.' : 'Admin deactivated.';
        }
    }

    if ($action === 'reset_password') {
        $adminUserId = (int)($_POST['admin_user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';

        if ($adminUserId <= 0 || trim($newPassword) === '') {
            $errors[] = 'Invalid password reset request.';
        } else {
            $resetStmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id AND role = :role');
            $resetStmt->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => $adminUserId,
                'role' => ROLE_ADMIN,
            ]);
            $success[] = 'Admin password reset successfully.';
        }
    }

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

    if ($action === 'reset_teacher_password') {
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

    if ($action === 'delete_staff') {
        $staffUserId = (int)($_POST['staff_user_id'] ?? 0);
        $staffRole = $_POST['staff_role'] ?? '';

        if ($staffUserId <= 0 || !in_array($staffRole, [ROLE_ADMIN, ROLE_TEACHER], true)) {
            $errors[] = 'Invalid delete request.';
        } else {
            try {
                $pdo->beginTransaction();
                cascade_delete_staff_user($pdo, $staffUserId, $staffRole);

                $pdo->commit();
                $success[] = ($staffRole === ROLE_ADMIN ? 'Admin' : 'Teacher') . ' deleted successfully.';
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to delete staff: ' . $throwable->getMessage();
            }
        }
    }
}

$staffSearchQuery = trim($_GET['search'] ?? '');
$staffSql = 'SELECT u.id,
                u.full_name,
                u.email,
                u.role,
                u.is_active,
                t.id AS teacher_id,
                t.employee_code,
                t.phone,
                t.qualification
         FROM users u
         LEFT JOIN teachers t ON t.user_id = u.id
         WHERE u.role IN (:admin_role, :teacher_role)';
$staffParams = [
    'admin_role' => ROLE_ADMIN,
    'teacher_role' => ROLE_TEACHER,
];

if ($staffSearchQuery !== '') {
    $staffSql .= ' AND (u.full_name LIKE :search OR u.email LIKE :search OR t.employee_code LIKE :search)';
    $staffParams['search'] = '%' . $staffSearchQuery . '%';
}

$staffSql .= ' ORDER BY FIELD(u.role, :admin_order, :teacher_order), u.id DESC';
$staffParams['admin_order'] = ROLE_ADMIN;
$staffParams['teacher_order'] = ROLE_TEACHER;

$staffStatement = $pdo->prepare($staffSql);
$staffStatement->execute($staffParams);
$staffMembers = $staffStatement->fetchAll();

$pageTitle = 'Staff Management';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Staff Management</h2>

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
            <p class="metric-label">Total Staff</p>
            <p class="metric-value"><?= count($staffMembers) ?></p>
        </div>
        <div class="metric-card">
            <p class="metric-label">Active Staff</p>
            <p class="metric-value"><?= count(array_filter($staffMembers, static fn(array $row): bool => (int)$row['is_active'] === 1)) ?></p>
        </div>
        <div class="metric-card">
            <p class="metric-label">Admins</p>
            <p class="metric-value"><?= count(array_filter($staffMembers, static fn(array $row): bool => $row['role'] === ROLE_ADMIN)) ?></p>
        </div>
        <div class="metric-card">
            <p class="metric-label">Teachers</p>
            <p class="metric-value"><?= count(array_filter($staffMembers, static fn(array $row): bool => $row['role'] === ROLE_TEACHER)) ?></p>
        </div>
    </div>

    <div class="grid-2">
        <section class="card">
            <h3>Create Admin</h3>
            <form method="post" class="form-grid form-grid-wide">
                <input type="hidden" name="action" value="create_admin">

                <label>Full Name</label>
                <input type="text" name="full_name" required>

                <label>Email</label>
                <input type="email" name="email" required>

                <label>Temporary Password</label>
                <input type="text" name="password" required>

                <button type="submit">Create Admin Account</button>
            </form>
        </section>

        <section class="card">
            <h3>Add Teacher</h3>
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

    <section class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3>Staff Members</h3>
            <form method="get" class="filter-bar" style="margin-bottom:0;">
                <input type="text" name="search" placeholder="Search name/email/code" value="<?= htmlspecialchars($staffSearchQuery) ?>">
                <button type="submit">Search</button>
                <a href="?" style="font-size:0.85rem; color:var(--primary); text-decoration:none; font-weight:600; margin-left:0.5rem;">Clear</a>
            </form>
        </div>

        <div class="table-wrap" id="section-table">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Role</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Employee Code</th>
                        <th>Phone</th>
                        <th>Qualification</th>
                        <th>Status</th>
                        <th>Password Reset</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staffMembers as $staff): ?>
                        <tr>
                            <td><?= (int)$staff['id'] ?></td>
                            <td><?= htmlspecialchars($staff['role'] === ROLE_ADMIN ? 'Admin' : 'Teacher') ?></td>
                            <td><?= htmlspecialchars((string)$staff['full_name']) ?></td>
                            <td><?= htmlspecialchars((string)$staff['email']) ?></td>
                            <td><?= htmlspecialchars((string)($staff['employee_code'] ?: '—')) ?></td>
                            <td><?= htmlspecialchars((string)($staff['phone'] ?: '—')) ?></td>
                            <td><?= htmlspecialchars((string)($staff['qualification'] ?: '—')) ?></td>
                            <td><span class="pill"><?= (int)$staff['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                            <td>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="action" value="<?= $staff['role'] === ROLE_ADMIN ? 'reset_password' : 'reset_teacher_password' ?>">
                                    <input type="hidden" name="<?= $staff['role'] === ROLE_ADMIN ? 'admin_user_id' : 'teacher_user_id' ?>" value="<?= (int)$staff['id'] ?>">
                                    <input type="text" name="new_password" placeholder="New password" required>
                                    <button type="submit">Reset</button>
                                </form>
                            </td>
                            <td>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="action" value="<?= $staff['role'] === ROLE_ADMIN ? 'toggle_admin' : 'toggle_teacher' ?>">
                                    <input type="hidden" name="<?= $staff['role'] === ROLE_ADMIN ? 'admin_user_id' : 'teacher_user_id' ?>" value="<?= (int)$staff['id'] ?>">
                                    <input type="hidden" name="new_state" value="<?= (int)$staff['is_active'] === 1 ? 0 : 1 ?>">
                                    <button type="submit"><?= (int)$staff['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                                <form method="post" class="inline-form" style="margin-top: 6px;" onsubmit="return confirm('Delete this <?= $staff['role'] === ROLE_ADMIN ? 'admin' : 'teacher' ?> permanently?');">
                                    <input type="hidden" name="action" value="delete_staff">
                                    <input type="hidden" name="staff_user_id" value="<?= (int)$staff['id'] ?>">
                                    <input type="hidden" name="staff_role" value="<?= htmlspecialchars((string)$staff['role']) ?>">
                                    <button type="submit" class="danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($staffMembers)): ?>
                        <tr><td colspan="10">No staff members found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
