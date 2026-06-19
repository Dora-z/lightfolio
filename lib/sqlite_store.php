<?php
declare(strict_types=1);

const LIGHTFOLIO_DATA_DIR = __DIR__ . '/../data';
const LIGHTFOLIO_STORAGE_DIR = __DIR__ . '/../../lightfolio-storage';
const LIGHTFOLIO_PUBLIC_DB_FILE = LIGHTFOLIO_DATA_DIR . '/lightfolio.sqlite';
const LIGHTFOLIO_DB_FILE = LIGHTFOLIO_STORAGE_DIR . '/lightfolio.sqlite';
const LIGHTFOLIO_CATEGORIES_JSON = LIGHTFOLIO_DATA_DIR . '/categories.json';
const LIGHTFOLIO_GROUPS_JSON = LIGHTFOLIO_DATA_DIR . '/groups.json';
const LIGHTFOLIO_PHOTOS_JSON = LIGHTFOLIO_DATA_DIR . '/photos.json';

function lightfolio_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO SQLite extension is not enabled.');
    }

    lightfolio_prepare_storage();

    $pdo = new PDO('sqlite:' . LIGHTFOLIO_DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    lightfolio_initialize_schema($pdo);
    lightfolio_migrate_json_once($pdo);

    return $pdo;
}

function lightfolio_prepare_storage(): void
{
    if (!is_dir(LIGHTFOLIO_STORAGE_DIR)) {
        mkdir(LIGHTFOLIO_STORAGE_DIR, 0755, true);
    }

    if (!file_exists(LIGHTFOLIO_DB_FILE) && file_exists(LIGHTFOLIO_PUBLIC_DB_FILE)) {
        if (!@rename(LIGHTFOLIO_PUBLIC_DB_FILE, LIGHTFOLIO_DB_FILE)) {
            if (!copy(LIGHTFOLIO_PUBLIC_DB_FILE, LIGHTFOLIO_DB_FILE)) {
                throw new RuntimeException('Could not move SQLite database outside the public document root.');
            }

            @unlink(LIGHTFOLIO_PUBLIC_DB_FILE);
        }
    }

    if (file_exists(LIGHTFOLIO_DB_FILE) && file_exists(LIGHTFOLIO_PUBLIC_DB_FILE)) {
        @unlink(LIGHTFOLIO_PUBLIC_DB_FILE);
    }
}

function lightfolio_initialize_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS categories (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS groups (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            category TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            cover_photo_id TEXT NOT NULL DEFAULT "",
            sort_order INTEGER NOT NULL DEFAULT 0
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS photos (
            id TEXT PRIMARY KEY,
            title TEXT NOT NULL,
            group_id TEXT NOT NULL,
            meta TEXT NOT NULL DEFAULT "",
            shot_at TEXT NOT NULL DEFAULT "",
            camera TEXT NOT NULL DEFAULT "",
            lens TEXT NOT NULL DEFAULT "",
            focal_length TEXT NOT NULL DEFAULT "",
            aperture TEXT NOT NULL DEFAULT "",
            shutter TEXT NOT NULL DEFAULT "",
            iso TEXT NOT NULL DEFAULT "",
            url TEXT NOT NULL,
            preview_url TEXT NOT NULL DEFAULT "",
            sort_order INTEGER NOT NULL DEFAULT 0
        )'
    );

    lightfolio_ensure_column($pdo, 'groups', 'cover_photo_id', 'TEXT NOT NULL DEFAULT ""');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS store_meta (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )'
    );
}

function lightfolio_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    foreach ($columns as $info) {
        if (($info['name'] ?? '') === $column) {
            return;
        }
    }

    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function lightfolio_migrate_json_once(PDO $pdo): void
{
    $statement = $pdo->prepare('SELECT value FROM store_meta WHERE key = :key');
    $statement->execute([':key' => 'json_migrated']);

    if ($statement->fetchColumn() === '1') {
        return;
    }

    $categories = lightfolio_migration_normalize_categories(
        lightfolio_load_json_array(LIGHTFOLIO_CATEGORIES_JSON)
    );

    if ((int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 0 && count($categories) > 0) {
        lightfolio_save_categories($categories);
    }

    $groups = lightfolio_migration_normalize_groups(
        lightfolio_load_json_array(LIGHTFOLIO_GROUPS_JSON),
        array_column($categories, 'id')
    );

    if ((int)$pdo->query('SELECT COUNT(*) FROM groups')->fetchColumn() === 0 && count($groups) > 0) {
        lightfolio_save_groups($groups);
    }

    $photos = lightfolio_migration_normalize_photos(
        lightfolio_load_json_array(LIGHTFOLIO_PHOTOS_JSON),
        array_column($groups, 'id')
    );

    if ((int)$pdo->query('SELECT COUNT(*) FROM photos')->fetchColumn() === 0 && count($photos) > 0) {
        lightfolio_save_photos($photos);
    }

    $statement = $pdo->prepare('INSERT OR REPLACE INTO store_meta (key, value) VALUES (:key, :value)');
    $statement->execute([':key' => 'json_migrated', ':value' => '1']);
}

function lightfolio_load_json_array(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $content = file_get_contents($path);
    $decoded = json_decode($content ?: '[]', true);

    return is_array($decoded) ? $decoded : [];
}

function lightfolio_migration_normalize_categories(array $value): array
{
    $categories = [];
    $seen = [];

    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = trim((string)($item['name'] ?? ''));
        $id = lightfolio_migration_normalize_id((string)($item['id'] ?? $name));

        if ($id === '' || $name === '' || isset($seen[$id])) {
            continue;
        }

        $seen[$id] = true;
        $categories[] = ['id' => $id, 'name' => $name];
    }

    return $categories;
}

function lightfolio_migration_normalize_groups(array $value, array $categoryIds): array
{
    $groups = [];
    $seen = [];
    $fallbackCategory = $categoryIds[0] ?? 'portfolio';

    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = trim((string)($item['name'] ?? ''));
        $id = lightfolio_migration_normalize_id((string)($item['id'] ?? $name));

        if ($id === '' || $name === '' || isset($seen[$id])) {
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
            'coverPhotoId' => trim((string)($item['coverPhotoId'] ?? ($item['cover_photo_id'] ?? ''))),
        ];
    }

    return $groups;
}

function lightfolio_migration_normalize_photos(array $value, array $groupIds): array
{
    $photos = [];
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

        $group = trim((string)($item['group'] ?? ($item['category'] ?? $fallbackGroup)));
        if (!in_array($group, $groupIds, true)) {
            $group = $fallbackGroup;
        }

        $photos[] = [
            'id' => trim((string)($item['id'] ?? '')) ?: 'p-' . str_pad((string)(count($photos) + 1), 3, '0', STR_PAD_LEFT),
            'title' => $title,
            'group' => $group,
            'meta' => lightfolio_migration_limit_text($item['meta'] ?? '', 120),
            'shotAt' => lightfolio_migration_normalize_shot_at($item['shotAt'] ?? ''),
            'camera' => lightfolio_migration_limit_text($item['camera'] ?? '', 80),
            'lens' => lightfolio_migration_limit_text($item['lens'] ?? '', 80),
            'focalLength' => lightfolio_migration_limit_text($item['focalLength'] ?? '', 24),
            'aperture' => lightfolio_migration_limit_text($item['aperture'] ?? '', 24),
            'shutter' => lightfolio_migration_limit_text($item['shutter'] ?? '', 24),
            'iso' => lightfolio_migration_limit_text($item['iso'] ?? '', 24),
            'url' => $url,
            'previewUrl' => lightfolio_migration_normalize_preview_url(
                $url,
                trim((string)($item['previewUrl'] ?? ($item['thumbnail'] ?? '')))
            ),
        ];
    }

    return $photos;
}

function lightfolio_migration_normalize_id(string $value): string
{
    $id = strtolower(trim($value));
    $id = preg_replace('/[^a-z0-9_-]+/', '-', $id) ?: '';
    $id = trim($id, '-_');

    return substr($id, 0, 40);
}

function lightfolio_migration_limit_text(mixed $value, int $limit): string
{
    return mb_substr(trim((string)$value), 0, $limit, 'UTF-8');
}

function lightfolio_migration_normalize_shot_at(mixed $value): string
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

    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $normalized) === 1) {
        return $normalized . ':00';
    }

    return $normalized;
}

function lightfolio_migration_normalize_preview_url(string $url, string $previewUrl): string
{
    $inferredPreviewUrl = lightfolio_migration_inferred_preview_url($url);

    if ($previewUrl === '' || $previewUrl === $url) {
        return $inferredPreviewUrl;
    }

    return $previewUrl;
}

function lightfolio_migration_inferred_preview_url(string $url): string
{
    if (preg_match('#^\./uploads/([^/]+)\.webp$#i', $url, $matches) !== 1) {
        return $url;
    }

    return './uploads/previews/' . $matches[1] . '-preview.webp';
}

function lightfolio_read_categories(): array
{
    $statement = lightfolio_db()->query('SELECT id, name FROM categories ORDER BY sort_order ASC, rowid ASC');

    return $statement->fetchAll();
}

function lightfolio_save_categories(array $categories): void
{
    $pdo = lightfolio_db();
    $pdo->beginTransaction();

    try {
        $pdo->exec('DELETE FROM categories');
        $statement = $pdo->prepare(
            'INSERT INTO categories (id, name, sort_order) VALUES (:id, :name, :sort_order)'
        );

        foreach (array_values($categories) as $index => $category) {
            $statement->execute([
                ':id' => $category['id'],
                ':name' => $category['name'],
                ':sort_order' => $index,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function lightfolio_read_groups(): array
{
    $statement = lightfolio_db()->query(
        'SELECT id, name, category, description, cover_photo_id FROM groups ORDER BY sort_order ASC, rowid ASC'
    );

    return array_map(
        static fn (array $group): array => [
            'id' => $group['id'],
            'name' => $group['name'],
            'category' => $group['category'],
            'description' => $group['description'],
            'coverPhotoId' => $group['cover_photo_id'],
        ],
        $statement->fetchAll()
    );
}

function lightfolio_save_groups(array $groups): void
{
    $pdo = lightfolio_db();
    $pdo->beginTransaction();

    try {
        $pdo->exec('DELETE FROM groups');
        $statement = $pdo->prepare(
            'INSERT INTO groups (id, name, category, description, cover_photo_id, sort_order)
            VALUES (:id, :name, :category, :description, :cover_photo_id, :sort_order)'
        );

        foreach (array_values($groups) as $index => $group) {
            $statement->execute([
                ':id' => $group['id'],
                ':name' => $group['name'],
                ':category' => $group['category'],
                ':description' => $group['description'] ?? '',
                ':cover_photo_id' => $group['coverPhotoId'] ?? '',
                ':sort_order' => $index,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function lightfolio_read_photos(): array
{
    $statement = lightfolio_db()->query(
        'SELECT id, title, group_id, meta, shot_at, camera, lens, focal_length, aperture, shutter, iso, url, preview_url
        FROM photos
        ORDER BY sort_order ASC, rowid ASC'
    );

    return array_map(
        static fn (array $photo): array => [
            'id' => $photo['id'],
            'title' => $photo['title'],
            'group' => $photo['group_id'],
            'meta' => $photo['meta'],
            'shotAt' => $photo['shot_at'],
            'camera' => $photo['camera'],
            'lens' => $photo['lens'],
            'focalLength' => $photo['focal_length'],
            'aperture' => $photo['aperture'],
            'shutter' => $photo['shutter'],
            'iso' => $photo['iso'],
            'url' => $photo['url'],
            'previewUrl' => $photo['preview_url'],
        ],
        $statement->fetchAll()
    );
}

function lightfolio_save_photos(array $photos): void
{
    $pdo = lightfolio_db();
    $pdo->beginTransaction();

    try {
        $pdo->exec('DELETE FROM photos');
        $statement = $pdo->prepare(
            'INSERT INTO photos (
                id, title, group_id, meta, shot_at, camera, lens, focal_length,
                aperture, shutter, iso, url, preview_url, sort_order
            ) VALUES (
                :id, :title, :group_id, :meta, :shot_at, :camera, :lens, :focal_length,
                :aperture, :shutter, :iso, :url, :preview_url, :sort_order
            )'
        );

        foreach (array_values($photos) as $index => $photo) {
            $statement->execute([
                ':id' => $photo['id'],
                ':title' => $photo['title'],
                ':group_id' => $photo['group'],
                ':meta' => $photo['meta'],
                ':shot_at' => $photo['shotAt'],
                ':camera' => $photo['camera'],
                ':lens' => $photo['lens'],
                ':focal_length' => $photo['focalLength'],
                ':aperture' => $photo['aperture'],
                ':shutter' => $photo['shutter'],
                ':iso' => $photo['iso'],
                ':url' => $photo['url'],
                ':preview_url' => $photo['previewUrl'],
                ':sort_order' => $index,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function lightfolio_json_error(Throwable $error): void
{
    error_log($error->getMessage());
    http_response_code(500);
    $payload = ['error' => 'Storage unavailable'];
    if (($_GET['debug'] ?? '') === '1') {
        $payload['message'] = $error->getMessage();
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
