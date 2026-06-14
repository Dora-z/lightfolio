<?php
declare(strict_types=1);

require __DIR__ . '/../lib/auth.php';

const DATA_FILE = __DIR__ . '/../data/photos.json';
const GROUPS_FILE = __DIR__ . '/../data/groups.json';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(read_photos(), JSON_UNESCAPED_UNICODE);
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

$photos = normalize_photos($payload);
$directory = dirname(DATA_FILE);

if (!is_dir($directory)) {
    mkdir($directory, 0755, true);
}

file_put_contents(
    DATA_FILE,
    json_encode($photos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    LOCK_EX
);

echo json_encode($photos, JSON_UNESCAPED_UNICODE);

function read_photos(): array
{
    if (!file_exists(DATA_FILE)) {
        return [];
    }

    $content = file_get_contents(DATA_FILE);
    $photos = json_decode($content ?: '[]', true);

    return normalize_photos(is_array($photos) ? $photos : []);
}

function normalize_photos(array $value): array
{
    $photos = [];
    $groupIds = group_ids();
    $fallbackGroup = $groupIds[0] ?? 'daily';

    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }

        $title = trim((string)($item['title'] ?? ''));
        $url = trim((string)($item['url'] ?? ''));

        if ($title === '' || $url === '') {
            continue;
        }

        $group = trim((string)($item['group'] ?? ($item['category'] ?? 'daily')));
        if (!in_array($group, $groupIds, true)) {
            $group = $fallbackGroup;
        }

        $previewUrl = normalize_preview_url(
            $url,
            trim((string)($item['previewUrl'] ?? ($item['thumbnail'] ?? '')))
        );

        $photos[] = [
            'id' => trim((string)($item['id'] ?? '')) ?: 'p-' . str_pad((string)(count($photos) + 1), 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'group' => $group,
            'meta' => trim((string)($item['meta'] ?? '')),
            'url' => $url,
            'previewUrl' => $previewUrl,
        ];
    }

    return $photos;
}

function normalize_preview_url(string $url, string $previewUrl): string
{
    $inferredPreviewUrl = inferred_preview_url($url);

    if ($previewUrl === '' || $previewUrl === $url) {
        return $inferredPreviewUrl;
    }

    return $previewUrl;
}

function inferred_preview_url(string $url): string
{
    if (preg_match('#^\./uploads/([^/]+)\.webp$#i', $url, $matches) !== 1) {
        return $url;
    }

    return './uploads/previews/' . $matches[1] . '-preview.webp';
}

function group_ids(): array
{
    if (!file_exists(GROUPS_FILE)) {
        return ['daily', 'travel'];
    }

    $content = file_get_contents(GROUPS_FILE);
    $groups = json_decode($content ?: '[]', true);

    if (!is_array($groups)) {
        return ['daily', 'travel'];
    }

    $ids = [];
    foreach ($groups as $group) {
        if (is_array($group) && isset($group['id'])) {
            $id = trim((string)$group['id']);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
    }

    return count($ids) > 0 ? $ids : ['daily', 'travel'];
}
