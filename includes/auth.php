<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const ROLE_SUPER_ADMIN = 'super_admin';
const ROLE_ADMIN = 'admin';
const ROLE_TEACHER = 'teacher';
const ROLE_STUDENT = 'student';
const ROLE_PARENT = 'parent';

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function current_role(): ?string
{
    return $_SESSION['user']['role'] ?? null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: /itierp/index.php');
        exit;
    }
}

function has_any_role(array $roles): bool
{
    $role = current_role();
    return $role !== null && in_array($role, $roles, true);
}

function require_roles(array $roles): void
{
    require_login();

    if (!has_any_role($roles)) {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
}

function login_user(array $user): void
{
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function role_home_path(string $role): string
{
    return match ($role) {
        ROLE_SUPER_ADMIN => '/itierp/superadmin/dashboard.php',
        ROLE_ADMIN => '/itierp/admin/dashboard.php',
        ROLE_TEACHER => '/itierp/teacher/dashboard.php',
        ROLE_STUDENT => '/itierp/student/dashboard.php',
        ROLE_PARENT => '/itierp/parent/dashboard.php',
        default => '/itierp/index.php',
    };
}
