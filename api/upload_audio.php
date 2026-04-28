<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

$debugEnabled = (bool)($config['debug'] ?? false);

/**
 * @param array<string, mixed> $payload
 */
function respond_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @param array<string, mixed> $details
 */
function fail_with_stage(string $stage, string $message, int $statusCode, bool $debugEnabled, array $details = []): void
{
    $response = [
        'status' => 'error',
        'stage' => $stage,
        'message' => $message,
    ];

    if ($debugEnabled) {
        $response['debug'] = $details;
    }

    if (!empty($details)) {
        error_log('[upload_audio][' . $stage . '] ' . json_encode($details, JSON_UNESCAPED_UNICODE));
    }

    respond_json($response, $statusCode);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail_with_stage('request_method', 'Method not allowed', 405, $debugEnabled, [
        'expected' => 'POST',
        'actual' => $_SERVER['REQUEST_METHOD'] ?? null,
    ]);
}

if (!isset($_FILES['audio'])) {
    fail_with_stage('file_presence', 'No audio file uploaded', 400, $debugEnabled, [
        'files_keys' => array_keys($_FILES),
    ]);
}

$file = $_FILES['audio'];

if (!isset($file['error']) || is_array($file['error'])) {
    fail_with_stage('upload_payload', 'Invalid upload payload', 400, $debugEnabled, [
        'file' => $file,
    ]);
}

if ($file['error'] !== UPLOAD_ERR_OK) {
    fail_with_stage('upload_transport', 'Upload error', 400, $debugEnabled, [
        'upload_error_code' => $file['error'],
    ]);
}

$fileSize = (int)($file['size'] ?? 0);
$maxUpload = (int)$config['max_upload_bytes'];
if ($fileSize <= 0 || $fileSize > $maxUpload) {
    fail_with_stage('file_size_validation', 'File size must be between 1 byte and 10MB', 400, $debugEnabled, [
        'actual_bytes' => $fileSize,
        'max_bytes' => $maxUpload,
    ]);
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
    fail_with_stage('file_type_validation', 'Invalid file type', 400, $debugEnabled, [
        'original_name' => $originalName,
        'detected_extension' => $ext,
        'allowed_extensions' => $config['allowed_extensions'],
    ]);
}

$uploadDir = realpath(__DIR__ . '/../public/audio/uploads');
if ($uploadDir === false) {
    fail_with_stage('upload_directory', 'Upload directory not found', 500, $debugEnabled, [
        'resolved_path' => __DIR__ . '/../public/audio/uploads',
    ]);
}

$timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd_His');
$random = bin2hex(random_bytes(4));
$filename = sprintf('user_%s_%s.%s', $timestamp, $random, $ext);
$destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;

if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
    fail_with_stage('move_uploaded_file', 'Failed to move uploaded file', 500, $debugEnabled, [
        'destination' => $destination,
        'tmp_name' => $file['tmp_name'] ?? null,
        'is_uploaded_file' => isset($file['tmp_name']) ? is_uploaded_file((string)$file['tmp_name']) : false,
    ]);
}

$audioPath = '/audio/uploads/' . $filename;

try {
    $pdo = db_connect($config);
} catch (Throwable $e) {
    @unlink($destination);
    fail_with_stage('db_connect', 'Database connection failed', 500, $debugEnabled, [
        'exception_class' => get_class($e),
        'exception_code' => (string)$e->getCode(),
        'exception_message' => $e->getMessage(),
        'db_host' => $config['db_host'] ?? null,
        'db_name' => $config['db_name'] ?? null,
    ]);
}

try {
    $stmt = $pdo->prepare('INSERT INTO messages (role, text, audio_path) VALUES (:role, :text, :audio_path)');
    $stmt->execute([
        ':role' => 'user',
        ':text' => null,
        ':audio_path' => $audioPath,
    ]);

    $messageId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    @unlink($destination);
    fail_with_stage('db_insert', 'Database insert failed', 500, $debugEnabled, [
        'exception_class' => get_class($e),
        'exception_code' => (string)$e->getCode(),
        'exception_message' => $e->getMessage(),
        'audio_path' => $audioPath,
        'sql' => 'INSERT INTO messages (role, text, audio_path) VALUES (:role, :text, :audio_path)',
    ]);
}

respond_json([
    'status' => 'success',
    'audio_path' => $audioPath,
    'message_id' => $messageId,
    'stage' => 'done',
]);
