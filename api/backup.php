<?php
declare(strict_types=1);

require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/sqlite_store.php';

if (!is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $format = trim((string)($_GET['format'] ?? 'sqlite'));

    try {
        if ($format === 'json') {
            send_json_backup();
        }

        send_sqlite_backup();
    } catch (Throwable $error) {
        lightfolio_json_error($error);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if (!verify_csrf_token()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_FILES['backup']) || !is_array($_FILES['backup'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No backup uploaded'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['backup'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Backup upload failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tmpName = (string)$file['tmp_name'];
$name = strtolower((string)($file['name'] ?? ''));

try {
    if (str_ends_with($name, '.json')) {
        restore_json_backup($tmpName);
    } else {
        restore_sqlite_backup($tmpName);
    }
} catch (Throwable $error) {
    lightfolio_json_error($error);
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);

function send_json_backup(): void
{
    $payload = [
        'version' => 1,
        'exportedAt' => date(DATE_ATOM),
        'categories' => lightfolio_read_categories(),
        'groups' => lightfolio_read_groups(),
        'photos' => lightfolio_read_photos(),
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="lightfolio-backup-' . date('Ymd-His') . '.json"');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function send_sqlite_backup(): void
{
    lightfolio_db();
    $dbFile = lightfolio_db_file();

    if (!file_exists($dbFile)) {
        throw new RuntimeException('Database file is missing.');
    }

    header('Content-Type: application/vnd.sqlite3');
    header('Content-Length: ' . filesize($dbFile));
    header('Content-Disposition: attachment; filename="lightfolio-' . date('Ymd-His') . '.sqlite"');
    readfile($dbFile);
    exit;
}

function restore_json_backup(string $path): void
{
    $content = file_get_contents($path);
    $payload = json_decode($content ?: '', true);

    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JSON backup.');
    }

    lightfolio_save_categories(api_normalize_categories($payload['categories'] ?? []));
    lightfolio_save_groups(api_normalize_groups($payload['groups'] ?? []));
    lightfolio_save_photos(api_normalize_photos($payload['photos'] ?? []));
}

function restore_sqlite_backup(string $path): void
{
    $candidate = new PDO('sqlite:' . $path);
    $candidate->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    foreach (['categories', 'groups', 'photos', 'store_meta'] as $table) {
        $statement = $candidate->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name");
        $statement->execute([':name' => $table]);
        if ($statement->fetchColumn() !== $table) {
            throw new RuntimeException('Invalid SQLite backup.');
        }
    }

    $dbFile = lightfolio_db_file();
    $backupPath = $dbFile . '.restore-backup-' . date('YmdHis');
    if (file_exists($dbFile) && !copy($dbFile, $backupPath)) {
        throw new RuntimeException('Could not create restore backup.');
    }

    if (!copy($path, $dbFile)) {
        throw new RuntimeException('Could not restore SQLite backup.');
    }
}

function api_normalize_categories(mixed $value): array
{
    $categories = [];
    $seen = [];

    foreach (is_array($value) ? $value : [] as $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = trim((string)($item['name'] ?? ''));
        $id = api_normalize_id((string)($item['id'] ?? $name));
        if ($name === '' || $id === '' || isset($seen[$id])) {
            continue;
        }

        $seen[$id] = true;
        $categories[] = ['id' => $id, 'name' => $name];
    }

    return count($categories) > 0 ? $categories : [
        ['id' => 'portfolio', 'name' => '作品'],
        ['id' => 'life', 'name' => '生活'],
    ];
}

function api_normalize_groups(mixed $value): array
{
    $categoryIds = array_column(lightfolio_read_categories(), 'id') ?: ['portfolio', 'life'];
    $fallbackCategory = $categoryIds[0] ?? 'portfolio';
    $groups = [];
    $seen = [];

    foreach (is_array($value) ? $value : [] as $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = trim((string)($item['name'] ?? ''));
        $id = api_normalize_id((string)($item['id'] ?? $name));
        if ($name === '' || $id === '' || isset($seen[$id])) {
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
            'coverPhotoId' => trim((string)($item['coverPhotoId'] ?? '')),
        ];
    }

    return count($groups) > 0 ? $groups : [
        ['id' => 'daily', 'name' => '日常记录', 'category' => 'life', 'description' => '', 'coverPhotoId' => ''],
    ];
}

function api_normalize_photos(mixed $value): array
{
    $groupIds = array_column(lightfolio_read_groups(), 'id') ?: ['daily'];
    $fallbackGroup = $groupIds[0] ?? 'daily';
    $photos = [];

    foreach (is_array($value) ? $value : [] as $item) {
        if (!is_array($item)) {
            continue;
        }

        $title = trim((string)($item['title'] ?? ''));
        $url = trim((string)($item['url'] ?? ''));
        if ($title === '' || $url === '') {
            continue;
        }

        $group = trim((string)($item['group'] ?? $fallbackGroup));
        if (!in_array($group, $groupIds, true)) {
            $group = $fallbackGroup;
        }

        $photos[] = [
            'id' => trim((string)($item['id'] ?? '')) ?: 'p-' . str_pad((string)(count($photos) + 1), 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'group' => $group,
            'meta' => mb_substr(trim((string)($item['meta'] ?? '')), 0, 120, 'UTF-8'),
            'shotAt' => trim((string)($item['shotAt'] ?? '')),
            'camera' => mb_substr(trim((string)($item['camera'] ?? '')), 0, 80, 'UTF-8'),
            'lens' => mb_substr(trim((string)($item['lens'] ?? '')), 0, 80, 'UTF-8'),
            'focalLength' => mb_substr(trim((string)($item['focalLength'] ?? '')), 0, 24, 'UTF-8'),
            'aperture' => mb_substr(trim((string)($item['aperture'] ?? '')), 0, 24, 'UTF-8'),
            'shutter' => mb_substr(trim((string)($item['shutter'] ?? '')), 0, 24, 'UTF-8'),
            'iso' => mb_substr(trim((string)($item['iso'] ?? '')), 0, 24, 'UTF-8'),
            'url' => $url,
            'previewUrl' => trim((string)($item['previewUrl'] ?? $url)),
        ];
    }

    return $photos;
}

function api_normalize_id(string $value): string
{
    $id = strtolower(trim($value));
    $id = preg_replace('/[^a-z0-9_-]+/', '-', $id) ?: '';
    $id = trim($id, '-_');

    return substr($id, 0, 40);
}
