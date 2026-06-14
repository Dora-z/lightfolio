<?php
declare(strict_types=1);

require __DIR__ . '/../lib/auth.php';

const GROUPS_FILE = __DIR__ . '/../data/groups.json';
const CATEGORIES_FILE = __DIR__ . '/../data/categories.json';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(read_groups(), JSON_UNESCAPED_UNICODE);
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

$groups = normalize_groups($payload);

if (count($groups) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'At least one group is required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$directory = dirname(GROUPS_FILE);

if (!is_dir($directory)) {
    mkdir($directory, 0755, true);
}

file_put_contents(
    GROUPS_FILE,
    json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    LOCK_EX
);

echo json_encode($groups, JSON_UNESCAPED_UNICODE);

function read_groups(): array
{
    if (!file_exists(GROUPS_FILE)) {
        return default_groups();
    }

    $content = file_get_contents(GROUPS_FILE);
    $groups = json_decode($content ?: '[]', true);
    $normalized = normalize_groups(is_array($groups) ? $groups : []);

    return count($normalized) > 0 ? $normalized : default_groups();
}

function normalize_groups(array $value): array
{
    $groups = [];
    $seen = [];
    $categoryIds = category_ids();
    $fallbackCategory = $categoryIds[0] ?? 'portfolio';

    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = trim((string)($item['name'] ?? ''));
        $id = trim((string)($item['id'] ?? ''));

        if ($name === '') {
            continue;
        }

        $id = normalize_group_id($id !== '' ? $id : $name);
        if ($id === '' || isset($seen[$id])) {
            continue;
        }

        $category = trim((string)($item['category'] ?? $fallbackCategory));
        if (!in_array($category, $categoryIds, true)) {
            $category = $fallbackCategory;
        }

        $seen[$id] = true;
        $groups[] = [
            'id' => $id,
            'name' => $name,
            'category' => $category,
            'description' => trim((string)($item['description'] ?? '')),
        ];
    }

    return $groups;
}

function normalize_group_id(string $value): string
{
    $id = strtolower(trim($value));
    $id = preg_replace('/[^a-z0-9_-]+/', '-', $id) ?: '';
    $id = trim($id, '-_');

    return substr($id, 0, 40);
}

function default_groups(): array
{
    return [
        ['id' => 'daily', 'name' => '日常记录', 'category' => 'life', 'description' => '随手捕捉的光线、街角与瞬间。'],
        ['id' => 'travel', 'name' => '旅行片段', 'category' => 'portfolio', 'description' => '按旅程整理的一组照片。'],
    ];
}

function category_ids(): array
{
    if (!file_exists(CATEGORIES_FILE)) {
        return ['portfolio', 'life'];
    }

    $content = file_get_contents(CATEGORIES_FILE);
    $categories = json_decode($content ?: '[]', true);

    if (!is_array($categories)) {
        return ['portfolio', 'life'];
    }

    $ids = [];
    foreach ($categories as $category) {
        if (is_array($category) && isset($category['id'])) {
            $id = trim((string)$category['id']);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
    }

    return count($ids) > 0 ? $ids : ['portfolio', 'life'];
}
