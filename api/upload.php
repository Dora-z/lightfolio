<?php
declare(strict_types=1);

ob_start();

require __DIR__ . '/../lib/auth.php';

const UPLOAD_DIR = __DIR__ . '/../uploads';
const PREVIEW_DIR = UPLOAD_DIR . '/previews';

header('Content-Type: application/json; charset=utf-8');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    lightfolio_upload_log($error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    lightfolio_upload_json(['error' => 'Upload unavailable'], 500);
});

try {
    lightfolio_handle_upload();
} catch (Throwable $error) {
    lightfolio_upload_log($error->getMessage());
    lightfolio_upload_json(['error' => 'Upload unavailable'], 500);
}

function lightfolio_handle_upload(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        lightfolio_upload_json(['error' => 'Method not allowed'], 405);
    }

    if (!is_logged_in()) {
        lightfolio_upload_json(['error' => 'Unauthorized'], 401);
    }

    if (!verify_csrf_token()) {
        lightfolio_upload_json(['error' => 'Invalid CSRF token'], 403);
    }

    if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
        lightfolio_upload_json(['error' => 'No file uploaded'], 400);
    }

    $file = $_FILES['photo'];
    $previewFile = isset($_FILES['preview']) && is_array($_FILES['preview']) ? $_FILES['preview'] : null;

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        lightfolio_upload_json(['error' => lightfolio_upload_error_message((int)($file['error'] ?? UPLOAD_ERR_NO_FILE))], 400);
    }

    if ($previewFile !== null && ($previewFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        lightfolio_upload_json(['error' => 'Preview upload failed: ' . lightfolio_upload_error_message((int)$previewFile['error'])], 400);
    }

    $tmpName = (string)$file['tmp_name'];

    if (!lightfolio_is_webp($tmpName)) {
        lightfolio_upload_json(['error' => 'Only WebP uploads are accepted'], 400);
    }
    $mimeType = 'image/webp';

    $previewTmpName = $previewFile ? (string)$previewFile['tmp_name'] : '';

    if ($previewFile !== null && !lightfolio_is_webp($previewTmpName)) {
        lightfolio_upload_json(['error' => 'Only WebP previews are accepted'], 400);
    }

    $dimensions = @getimagesize($tmpName);
    if (!$dimensions) {
        lightfolio_upload_json(['error' => 'Invalid image'], 400);
    }

    $previewDimensions = null;
    if ($previewFile !== null) {
        $previewDimensions = @getimagesize($previewTmpName);
        if (!$previewDimensions) {
            lightfolio_upload_json(['error' => 'Invalid preview image'], 400);
        }
    }

    lightfolio_prepare_upload_dir(UPLOAD_DIR);
    lightfolio_prepare_upload_dir(PREVIEW_DIR);

    $filename = 'photo-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.webp';
    $targetPath = UPLOAD_DIR . '/' . $filename;
    $previewFilename = 'preview-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.webp';
    $previewTargetPath = PREVIEW_DIR . '/' . $previewFilename;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        lightfolio_upload_json(['error' => 'Could not save upload. Check uploads directory permissions.'], 500);
    }

    $previewUrl = './uploads/' . $filename;
    if ($previewFile !== null) {
        if (!move_uploaded_file($previewTmpName, $previewTargetPath)) {
            @unlink($targetPath);
            lightfolio_upload_json(['error' => 'Could not save preview. Check uploads/previews permissions.'], 500);
        }

        $previewUrl = './uploads/previews/' . $previewFilename;
    }

    lightfolio_upload_json([
        'url' => './uploads/' . $filename,
        'previewUrl' => $previewUrl,
        'width' => (int)$dimensions[0],
        'height' => (int)$dimensions[1],
        'previewWidth' => $previewDimensions ? (int)$previewDimensions[0] : (int)$dimensions[0],
        'previewHeight' => $previewDimensions ? (int)$previewDimensions[1] : (int)$dimensions[1],
        'mime' => $mimeType,
    ]);
}

function lightfolio_prepare_upload_dir(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        throw new RuntimeException('Could not create upload directory: ' . $directory);
    }

    if (!is_writable($directory)) {
        throw new RuntimeException('Upload directory is not writable: ' . $directory);
    }
}

function lightfolio_is_webp(string $path): bool
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }

    $header = fread($handle, 12);
    fclose($handle);

    return is_string($header)
        && strlen($header) >= 12
        && substr($header, 0, 4) === 'RIFF'
        && substr($header, 8, 4) === 'WEBP';
}

function lightfolio_upload_error_message(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file is too large',
        UPLOAD_ERR_PARTIAL => 'Upload was interrupted',
        UPLOAD_ERR_NO_FILE => 'No file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Server upload temp directory is missing',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write uploaded file',
        UPLOAD_ERR_EXTENSION => 'Upload blocked by a PHP extension',
        default => 'Upload failed',
    };
}

function lightfolio_upload_json(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function lightfolio_upload_log(string $message): void
{
    error_log('[Lightfolio upload] ' . $message);
}
