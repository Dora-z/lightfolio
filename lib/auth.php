<?php
declare(strict_types=1);

$lightfolioAuthConfig = [
    'admin_user' => 'admin',
    // Default password: admin123. Run install.php before publishing.
    'admin_password_salt' => 'lightfolio-admin-v1',
    'admin_password_pbkdf2' => 'd5f356d6ae6ae1f4c301e9d055bf44c8c5d809946987debd3c3f1f7864df0831',
];

$lightfolioConfigFile = lightfolio_auth_config_file();
if (is_file($lightfolioConfigFile)) {
    $customConfig = require $lightfolioConfigFile;
    if (is_array($customConfig)) {
        foreach ($lightfolioAuthConfig as $key => $fallback) {
            $value = $customConfig[$key] ?? $fallback;
            if (is_string($value) && $value !== '') {
                $lightfolioAuthConfig[$key] = $value;
            }
        }
    }
}

define('LIGHTFOLIO_ADMIN_USER', $lightfolioAuthConfig['admin_user']);
define('LIGHTFOLIO_ADMIN_PASSWORD_SALT', $lightfolioAuthConfig['admin_password_salt']);
define('LIGHTFOLIO_ADMIN_PASSWORD_PBKDF2', $lightfolioAuthConfig['admin_password_pbkdf2']);

function lightfolio_auth_config_file(): string
{
    $configFile = getenv('LIGHTFOLIO_CONFIG_FILE');
    if (is_string($configFile) && trim($configFile) !== '') {
        return $configFile;
    }

    return __DIR__ . '/config.php';
}

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
