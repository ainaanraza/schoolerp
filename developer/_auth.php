<?php
require_once __DIR__ . '/../config/developer_portal.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('DEVPORTALSESSID');
    session_start();
}

function developer_is_logged_in(): bool
{
    return isset($_SESSION['developer_portal_auth']) && $_SESSION['developer_portal_auth'] === true;
}

function developer_current_user(): ?string
{
    $value = $_SESSION['developer_portal_user'] ?? null;
    return is_string($value) ? $value : null;
}

function developer_login(string $username, string $password): bool
{
    $credentials = developer_portal_credentials();

    $validUser = hash_equals((string)$credentials['username'], $username);
    $validPass = hash_equals((string)$credentials['password'], $password);

    if (!$validUser || !$validPass) {
        return false;
    }

    $_SESSION['developer_portal_auth'] = true;
    $_SESSION['developer_portal_user'] = $credentials['username'];
    $_SESSION['developer_portal_login_at'] = time();
    return true;
}

function developer_require_login(): void
{
    if (!developer_is_logged_in()) {
        header('Location: /school-erp/developer/login.php');
        exit;
    }
}

function developer_logout(): void
{
    unset(
        $_SESSION['developer_portal_auth'],
        $_SESSION['developer_portal_user'],
        $_SESSION['developer_portal_login_at']
    );
}
