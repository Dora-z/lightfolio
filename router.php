<?php
declare(strict_types=1);

$path = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$path = str_replace('\\', '/', $path);

$configFile = getenv('LIGHTFOLIO_CONFIG_FILE');
$configFile = is_string($configFile) && trim($configFile) !== '' ? $configFile : __DIR__ . '/lib/config.php';

if (strcasecmp($path, '/install.php') === 0 && is_file($configFile)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

if (preg_match('#^/(?:data|lib)(?:/|$)#i', $path) === 1 || preg_match('#/(?:\.git|\.env|[^/]*\.log)(?:/|$)#i', $path) === 1) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

return false;
