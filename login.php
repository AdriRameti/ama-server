<?php
session_start();
$cfg = require __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: ' . $cfg['cors_origin']);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $password = isset($body['password']) ? $body['password'] : null;
    if ($password && $password === $cfg['admin_password']) {
        $_SESSION['is_admin'] = true;
        echo json_encode(['ok' => true]);
        exit;
    }
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
    exit;
}

// simple HTML form for manual login
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><body><form method="post"><input type="password" name="password" placeholder="Password"/><button>Login</button></form></body></html>';
}
