<?php
declare(strict_types=1);

require __DIR__ . '/../lib/auth.php';

const CATEGORIES_FILE = __DIR__ . '/../data/categories.json';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(read_categories(), JSON_UNESCAPED_UNICODE);
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

$directory = dirname(CATEGORIES_FILE);
if (!is_dir($directory)) {
    mkdir($directory, 0755, true);
}

file_put_contents(
    CATEGORIES_FILE,
    json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    LOCK_EX
);

echo json_encode($categories, JSON_UNESCAPED_UNICODE);

function read_categories(): array
{
    if (!file_exists(CATEGORIES_FILE)) {
        return default_categories();
    }

    $content = file_get_contents(CATEGORIES_FILE);
    $categories = json_decode($content ?: '[]', true);
    $normalized = normalize_categories(is_array($categories) ? $categories : []);

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
