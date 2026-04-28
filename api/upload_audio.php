<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

if (!isset($_FILES['audio'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No audio file uploaded']);
    exit;
}

$file = $_FILES['audio'];

if (!isset($file['error']) || is_array($file['error'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid upload payload']);
    exit;
}

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Upload error code: ' . $file['error']]);
    exit;
}

if (!isset($file['size']) || (int)$file['size'] <= 0 || (int)$file['size'] > (int)$config['max_upload_bytes']) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'File size must be between 1 byte and 10MB']);
    exit;
}

$originalName = (string)($file['name'] ?? '');
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if ($ext === '') {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file((string)$file['tmp_name']);
    $mimeToExt = [
        'audio/webm' => 'webm',
        'audio/mp4' => 'mp4',
        'audio/x-m4a' => 'm4a',
        'audio/ogg' => 'ogg',
        'application/ogg' => 'ogg',
    ];
    $ext = $mimeToExt[$mime] ?? '';
}

if (!in_array($ext, $config['allowed_extensions'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid file type']);
    exit;
}

$uploadDir = realpath(__DIR__ . '/../public/audio/uploads');
if ($uploadDir === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Upload directory not found']);
    exit;
}

$timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd_His');
$random = bin2hex(random_bytes(4));
$filename = sprintf('user_%s_%s.%s', $timestamp, $random, $ext);
$destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;

if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to move uploaded file']);
    exit;
}

$audioPath = '/audio/uploads/' . $filename;

try {
    $pdo = db_connect($config);
    $stmt = $pdo->prepare('INSERT INTO messages (role, text, audio_path) VALUES (:role, :text, :audio_path)');
    $stmt->execute([
        ':role' => 'user',
        ':text' => null,
        ':audio_path' => $audioPath,
    ]);

    $messageId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    @unlink($destination);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database insert failed']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'audio_path' => $audioPath,
    'message_id' => $messageId,
]);
