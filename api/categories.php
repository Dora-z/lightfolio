<?php
declare(strict_types=1);

require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/sqlite_store.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        echo json_encode(read_categories(), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $error) {
        lightfolio_json_error($error);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!verify_csrf_token()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody ?: '', true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$categories = normalize_categories($payload);

if (count($categories) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'At least one category is required'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    lightfolio_save_categories($categories);
} catch (Throwable $error) {
    lightfolio_json_error($error);
}

echo json_encode($categories, JSON_UNESCAPED_UNICODE);

function read_categories(): array
{
    $normalized = normalize_categories(lightfolio_read_categories());

    return count($normalized) > 0 ? $normalized : default_categories();
}

function normalize_categories(array $value): array
{
    $categories = [];
    $seen = [];

    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = trim((string)($item['name'] ?? ''));
        $id = normalize_category_id((string)($item['id'] ?? $name));

        if ($name === '' || $id === '' || isset($seen[$id])) {
            continue;
        }

        $seen[$id] = true;
        $categories[] = [
            'id' => $id,
            'name' => $name,
        ];
    }

    return $categories;
}

function normalize_category_id(string $value): string
{
    $id = strtolower(trim($value));
    $id = preg_replace('/[^a-z0-9_-]+/', '-', $id) ?: '';
    $id = trim($id, '-_');

    return substr($id, 0, 40);
}

function default_categories(): array
{
    return [
        ['id' => 'portfolio', 'name' => '作品'],
        ['id' => 'life', 'name' => '生活'],
    ];
}
