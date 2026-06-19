<?php
declare(strict_types=1);

require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/sqlite_store.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        echo json_encode(read_photos(), JSON_UNESCAPED_UNICODE);
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

$photos = normalize_photos($payload);

try {
    lightfolio_save_photos($photos);
} catch (Throwable $error) {
    lightfolio_json_error($error);
}

echo json_encode($photos, JSON_UNESCAPED_UNICODE);

function read_photos(): array
{
    return normalize_photos(lightfolio_read_photos());
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
            'meta' => limit_text($item['meta'] ?? '', 120),
            'shotAt' => normalize_shot_at($item['shotAt'] ?? ''),
            'camera' => limit_text($item['camera'] ?? '', 80),
            'lens' => limit_text($item['lens'] ?? '', 80),
            'focalLength' => limit_text($item['focalLength'] ?? '', 24),
            'aperture' => limit_text($item['aperture'] ?? '', 24),
            'shutter' => limit_text($item['shutter'] ?? '', 24),
            'iso' => limit_text($item['iso'] ?? '', 24),
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
    $ids = [];
    foreach (lightfolio_read_groups() as $group) {
        if (is_array($group) && isset($group['id'])) {
            $id = trim((string)$group['id']);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
    }

    return count($ids) > 0 ? $ids : ['daily', 'travel'];
}

function limit_text(mixed $value, int $limit): string
{
    return mb_substr(trim((string)$value), 0, $limit, 'UTF-8');
}

function normalize_shot_at(mixed $value): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    $text = str_replace('T', ' ', $text);
    if (preg_match('/^\d{4}[:\/-]\d{2}[:\/-]\d{2}(?:\s+\d{2}:\d{2}(?::\d{2})?)?$/', $text) !== 1) {
        return $text;
    }

    $normalized = preg_replace('/[\/:](?=\d{2}(?:\s|$))/', '-', $text, 2);

    if ($normalized === null) {
        return $text;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) === 1) {
        return $normalized;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $normalized) === 1) {
        return $normalized . ':00';
    }

    return $normalized;
}
