<?php
require_once __DIR__ . '/_auth.php';

if (developer_is_logged_in()) {
    header('Location: /itierp/developer/portal.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!developer_login($username, $password)) {
        $error = 'Invalid developer portal credentials.';
    } else {
        header('Location: /itierp/developer/portal.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Developer Portal Login</title>
    <link rel="icon" type="image/png" href="/itierp/assets/images/favicon.png">
    <link rel="icon" type="image/x-icon" href="/itierp/assets/images/favicon.ico">
    <link rel="shortcut icon" href="/itierp/assets/images/favicon.ico">
    <link rel="apple-touch-icon" href="/itierp/assets/images/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/itierp/assets/css/style.css?v=<?= time() ?>">
</head>
<body class="is-guest">
    <main class="container guest-container">
        <section class="card" style="max-width: 560px; margin: 48px auto;">
            <div class="login-brand-block">
                <p class="login-overline">INTERNAL CONTROL</p>
                <h2>Developer Portal</h2>
                <p class="login-subtitle">Standalone access for direct portal switching and no-SQL table operations.</p>
            </div>

            <?php if ($error): ?>
                <p class="error"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <form method="post" class="stacked-form">
                <div>
                    <label>Developer Username</label>
                    <input type="text" name="username" required placeholder="Enter developer username">
                </div>

                <div>
                    <label>Developer Password</label>
                    <input type="password" name="password" required placeholder="Enter developer password">
                </div>

                <button type="submit">Access Developer Portal</button>
            </form>

            <p style="margin-top: 16px; color: #6b7280; font-size: 0.9rem;">
                This login is separate from school role accounts.
            </p>
        </section>
    </main>
    <footer class="erp-bottom-credit">
        Developed by <a href="https://ainaanraza.me" target="_blank" rel="noopener noreferrer">Ainaan Raza</a>
    </footer>
</body>
</html>
