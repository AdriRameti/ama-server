<?php
session_start();
$cfg = require __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: ' . $cfg['cors_origin']);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json');

$scope = isset($_GET['scope']) ? $_GET['scope'] : 'collections';
$action = isset($_GET['action']) ? $_GET['action'] : null;

function get_scope_file($scope, $cfg)
{
    if ($scope === 'content') return $cfg['content_file'];
    if ($scope === 'images') return $cfg['images_file'];
    return $cfg['collections_file'];
}

function ensure_json_file($filePath)
{
    $dir = dirname($filePath);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    if (!file_exists($filePath)) {
        file_put_contents($filePath, json_encode(new stdClass(), JSON_PRETTY_PRINT));
    }
}

function ensure_upload_dir($dir)
{
    if (!is_dir($dir)) mkdir($dir, 0775, true);
}

function is_admin()
{
    return !empty($_SESSION['is_admin']);
}

function script_base_path()
{
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/api/collections.php';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $dir === '' ? '/' : $dir;
}

$dataFile = get_scope_file($scope, $cfg);
ensure_json_file($dataFile);

if (!empty($cfg['uploads_dir'])) {
    ensure_upload_dir($cfg['uploads_dir']);
}

// Upload image file (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
    if (!is_admin()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    if (!isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing file']);
        exit;
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Upload error']);
        exit;
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'File too large']);
        exit;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];
    if (!isset($allowed[$mime])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid file type']);
        exit;
    }

    $ext = $allowed[$mime];
    $key = isset($_POST['key']) ? preg_replace('/[^a-zA-Z0-9-_\.]/', '_', $_POST['key']) : 'img';
    $filename = $key . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = rtrim($cfg['uploads_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not move uploaded file']);
        exit;
    }

    $base = script_base_path();
    $url = rtrim($base, '/') . '/uploads/' . $filename;
    echo json_encode(['ok' => true, 'url' => $url]);
    exit;
}

// Delete uploaded image file (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete-image') {
    if (!is_admin()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $url = isset($body['url']) ? $body['url'] : '';
    $basename = basename(parse_url($url, PHP_URL_PATH));
    if (!$basename) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid image URL']);
        exit;
    }

    $targetPath = rtrim($cfg['uploads_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $basename;
    if (file_exists($targetPath)) {
        @unlink($targetPath);
    }

    echo json_encode(['ok' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $raw = file_get_contents($dataFile);
    echo $raw;
    exit;
}

// For saving, accept POST with JSON body; require admin session
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_admin()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $body = file_get_contents('php://input');
    if (!$body) {
        http_response_code(400);
        echo json_encode(['error' => 'Empty body']);
        exit;
    }
    // Validate JSON
    $decoded = json_decode($body, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }
    // Write atomically
    $tmp = $dataFile . '.tmp';
    if (file_put_contents($tmp, json_encode($decoded, JSON_PRETTY_PRINT)) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not write file']);
        exit;
    }
    rename($tmp, $dataFile);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
