<?php
declare(strict_types=1);

require __DIR__ . '/../lib/auth.php';

const UPLOAD_DIR = __DIR__ . '/../uploads';
const PREVIEW_DIR = UPLOAD_DIR . '/previews';

header('Content-Type: application/json; charset=utf-8');

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

if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['photo'];
$previewFile = isset($_FILES['preview']) && is_array($_FILES['preview']) ? $_FILES['preview'] : null;

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($previewFile !== null && ($previewFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Preview upload failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tmpName = (string)$file['tmp_name'];
$mimeType = mime_content_type($tmpName) ?: '';

if ($mimeType !== 'image/webp') {
    http_response_code(400);
    echo json_encode(['error' => 'Only WebP uploads are accepted'], JSON_UNESCAPED_UNICODE);
    exit;
}

$previewTmpName = $previewFile ? (string)$previewFile['tmp_name'] : '';
$previewMimeType = $previewTmpName !== '' ? (mime_content_type($previewTmpName) ?: '') : '';

if ($previewFile !== null && $previewMimeType !== 'image/webp') {
    http_response_code(400);
    echo json_encode(['error' => 'Only WebP previews are accepted'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dimensions = getimagesize($tmpName);
if (!$dimensions) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid image'], JSON_UNESCAPED_UNICODE);
    exit;
}

$previewDimensions = null;
if ($previewFile !== null) {
    $previewDimensions = getimagesize($previewTmpName);
    if (!$previewDimensions) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid preview image'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

if (!is_dir(PREVIEW_DIR)) {
    mkdir(PREVIEW_DIR, 0755, true);
}

$filename = 'photo-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.webp';
$targetPath = UPLOAD_DIR . '/' . $filename;
$previewFilename = 'preview-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.webp';
$previewTargetPath = PREVIEW_DIR . '/' . $previewFilename;

if (!move_uploaded_file($tmpName, $targetPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save upload'], JSON_UNESCAPED_UNICODE);
    exit;
}

$previewUrl = './uploads/' . $filename;
if ($previewFile !== null) {
    if (!move_uploaded_file($previewTmpName, $previewTargetPath)) {
        @unlink($targetPath);
        http_response_code(500);
        echo json_encode(['error' => 'Could not save preview'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $previewUrl = './uploads/previews/' . $previewFilename;
}

echo json_encode(
    [
        'url' => './uploads/' . $filename,
        'previewUrl' => $previewUrl,
        'width' => (int)$dimensions[0],
        'height' => (int)$dimensions[1],
        'previewWidth' => $previewDimensions ? (int)$previewDimensions[0] : (int)$dimensions[0],
        'previewHeight' => $previewDimensions ? (int)$previewDimensions[1] : (int)$dimensions[1],
        'mime' => $mimeType,
    ],
    JSON_UNESCAPED_UNICODE
);
