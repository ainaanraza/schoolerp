<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    header('Location: ' . role_home_path(current_role()));
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $statement = $pdo->prepare('SELECT id, full_name, email, password_hash, role, is_active FROM users WHERE email = :email LIMIT 1');
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();

    if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
        $error = 'Invalid email or password.';
    } else {
        login_user($user);
        header('Location: ' . role_home_path($user['role']));
        exit;
    }
}

$pageTitle = 'Login';
require __DIR__ . '/includes/header.php';
?>
<section class="card">
    <h2>Login</h2>
    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="post" style="display: flex; flex-direction: column; gap: 1.5rem;">
        <div>
            <label>Email</label>
            <input type="email" name="email" required placeholder="Enter your email">
        </div>

        <div>
            <label>Password</label>
            <input type="password" name="password" required placeholder="Enter your password">
        </div>

        <button type="submit">Sign In</button>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
