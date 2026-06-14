<?php
declare(strict_types=1);

const LIGHTFOLIO_ADMIN_USER = 'admin';
// Default password: admin123. Change the salt/hash pair before publishing.
const LIGHTFOLIO_ADMIN_PASSWORD_SALT = 'lightfolio-admin-v1';
const LIGHTFOLIO_ADMIN_PASSWORD_PBKDF2 = 'd5f356d6ae6ae1f4c301e9d055bf44c8c5d809946987debd3c3f1f7864df0831';

session_name('lightfolio_admin_session');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function is_logged_in(): bool
{
    return isset($_SESSION['lightfolio_admin']) && $_SESSION['lightfolio_admin'] === true;
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

function verify_admin_credentials(string $username, string $password): bool
{
    $passwordHash = hash_pbkdf2('sha256', $password, LIGHTFOLIO_ADMIN_PASSWORD_SALT, 120000, 64);

    return hash_equals(LIGHTFOLIO_ADMIN_USER, $username)
        && hash_equals(LIGHTFOLIO_ADMIN_PASSWORD_PBKDF2, $passwordHash);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token(): bool
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}
