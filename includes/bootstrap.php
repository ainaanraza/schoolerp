<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/fee_helpers.php';
require_once __DIR__ . '/maintenance_helper.php';

// Check and enforce Yearly Maintenance Lock across all ERP endpoints
if (isset($pdo)) {
    check_and_enforce_maintenance_lock($pdo);
}

function app_base_url(): string {
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $appDirs = ['modules', 'admin', 'superadmin', 'teacher', 'student', 'parent', 'developer', 'assets', 'uploads'];
    $pattern = '#^(.*?)/(?:' . implode('|', $appDirs) . '|index\.php|logout\.php)#';
    if (preg_match($pattern, $script, $matches)) {
        $base = rtrim($matches[1], '/');
    } else {
        $base = '';
    }
    return $base;
}

function resolve_photo_url(?string $path): string {
    if (!$path || trim($path) === '') {
        return '';
    }
    $trimmed = trim($path);
    if (preg_match('#^https?://#i', $trimmed)) {
        return $trimmed;
    }
    $base = app_base_url();
    $pos = strpos($trimmed, 'uploads/');
    if ($pos !== false) {
        $relPath = substr($trimmed, $pos);
        return ($base !== '' ? $base : '') . '/' . $relPath;
    }
    return ($base !== '' ? $base : '') . '/' . ltrim($trimmed, '/');
}
