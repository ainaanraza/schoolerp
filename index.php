<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    header('Location: ' . role_home_path(current_role()));
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginInput = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $statement = $pdo->prepare('
        SELECT id, full_name, email, password_hash, role, is_active 
        FROM users 
        WHERE email = :email 
           OR email = :with_domain 
           OR email LIKE :prefix
           OR full_name = :exact_name
           OR full_name LIKE :name_like
        LIMIT 1
    ');
    $statement->execute([
        'email' => $loginInput,
        'with_domain' => strpos($loginInput, '@') === false ? $loginInput . '@school.local' : $loginInput,
        'prefix' => $loginInput . '@%',
        'exact_name' => $loginInput,
        'name_like' => '%' . $loginInput . '%',
    ]);
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
    <div class="login-brand-block">
        <div class="login-brand-mark" aria-hidden="true">
            <img src="/itierp/assets/images/unityiti_logo.jpeg" alt="" class="login-brand-logo">
        </div>
        <p class="login-overline">Unity ITI ERP SUITE</p>
        <h2>Sign in to your workspace</h2>
        <p class="login-subtitle">Securely access admissions, academics, attendance, and fees from one refined dashboard.</p>
    </div>
    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="post" class="stacked-form">
        <div>
            <label>Email or Username</label>
            <input type="text" name="email" required placeholder="Enter your email or username">
        </div>

        <div>
            <label>Password</label>
            <input type="password" name="password" required placeholder="Enter your password">
        </div>

        <button type="submit">Sign In</button>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
