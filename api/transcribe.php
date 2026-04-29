<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

$debug = (bool)($config['debug'] ?? false);
$trace = [];

/**
 * @param array<string, mixed> $context
 */
function add_trace(array &$trace, string $stage, string $message, array $context = []): void
{
    $trace[] = [
        'time' => gmdate('Y-m-d H:i:s') . ' UTC',
        'stage' => $stage,
        'message' => $message,
        'context' => $context,
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function respond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @param array<string, mixed> $context
 */
function fail(string $message, int $statusCode, bool $debug, array $context = [], array $trace = [], ?string $stage = null): void
{
    $payload = ['status' => 'error', 'message' => $message];
    if ($stage !== null) {
        $payload['stage'] = $stage;
    }
    $payload['trace'] = $trace;
    if ($debug && $context !== []) {
        $payload['debug'] = $context;
    }
    respond($payload, $statusCode);
}

add_trace($trace, 'request_received', 'Incoming request accepted', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'uri' => $_SERVER['REQUEST_URI'] ?? null,
]);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail('Method not allowed', 405, $debug, [], $trace, 'request_method');
}

$rawBody = file_get_contents('php://input');
$data = json_decode((string)$rawBody, true);

if (!is_array($data)) {
    fail('Invalid JSON payload', 400, $debug, [], $trace, 'request_payload');
}

$id = isset($data['id']) ? (int)$data['id'] : 0;
if ($id <= 0) {
    fail('Invalid message id', 400, $debug, ['received_id' => $data['id'] ?? null], $trace, 'request_id_validation');
}
add_trace($trace, 'request_id_validation', 'Message id parsed', ['id' => $id]);

$geminiApiKey = (string)($config['gemini_api_key'] ?? '');
if ($geminiApiKey === '') {
    fail('Gemini API key not configured', 500, $debug, ['hint' => 'Set gemini_api_key in api/config.php'], $trace, 'config_api_key');
}
add_trace($trace, 'config_api_key', 'Gemini key loaded from api/config.php');

try {
    $pdo = db_connect($config);
    $stmt = $pdo->prepare('SELECT id, text, audio_path FROM messages WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $message = $stmt->fetch();
} catch (Throwable $e) {
    fail('Database error while reading message', 500, $debug, ['error' => $e->getMessage()], $trace, 'db_select_message');
}
add_trace($trace, 'db_select_message', 'Message loaded from DB', ['found' => (bool)$message]);

if (!$message) {
    fail('Message not found', 404, $debug, ['id' => $id], $trace, 'db_message_not_found');
}

if (trim((string)($message['text'] ?? '')) !== '') {
    add_trace($trace, 'db_existing_text', 'Message already contains transcription');
    respond([
        'status' => 'success',
        'text' => (string)$message['text'],
        'already_transcribed' => true,
        'trace' => $trace,
    ]);
}

$audioPath = trim((string)($message['audio_path'] ?? ''));
if ($audioPath === '') {
    fail('Audio path is empty', 400, $debug, ['id' => $id], $trace, 'audio_path_empty');
}

$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    fail('Cannot resolve project root', 500, $debug, [], $trace, 'path_project_root');
}

$relativePath = ltrim($audioPath, '/\\');
$pathCandidates = [
    $projectRoot . DIRECTORY_SEPARATOR . $relativePath,
    $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $relativePath,
];

$absoluteAudioPath = false;
foreach ($pathCandidates as $candidate) {
    $resolved = realpath($candidate);
    if ($resolved === false) {
        continue;
    }

    if (strncmp($resolved, $projectRoot, strlen($projectRoot)) !== 0) {
        continue;
    }

    $absoluteAudioPath = $resolved;
    break;
}

if ($absoluteAudioPath === false) {
    fail('Audio file path is invalid', 400, $debug, [
        'audio_path' => $audioPath,
        'tried_paths' => $pathCandidates,
    ], $trace, 'path_audio_validation');
}
add_trace($trace, 'path_audio_validation', 'Audio path resolved', ['resolved_path' => $absoluteAudioPath]);

if (!is_file($absoluteAudioPath) || !is_readable($absoluteAudioPath)) {
    fail('Audio file is not accessible', 404, $debug, ['resolved_path' => $absoluteAudioPath], $trace, 'audio_file_access');
}

$audioBinary = file_get_contents($absoluteAudioPath);
if ($audioBinary === false) {
    fail('Cannot read audio file', 500, $debug, ['resolved_path' => $absoluteAudioPath], $trace, 'audio_file_read');
}
add_trace($trace, 'audio_file_read', 'Audio file loaded into memory', ['bytes' => strlen($audioBinary)]);

$extension = strtolower(pathinfo($absoluteAudioPath, PATHINFO_EXTENSION));
$extensionToMime = [
    'webm' => 'audio/webm',
    'ogg' => 'audio/ogg',
    'm4a' => 'audio/mp4',
    'mp4' => 'audio/mp4',
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
];

$detectedMime = mime_content_type($absoluteAudioPath) ?: '';
$mimeType = $extensionToMime[$extension] ?? $detectedMime;

if ($mimeType === '' || str_starts_with($mimeType, 'video/')) {
    $mimeType = $extensionToMime[$extension] ?? 'audio/webm';
}
add_trace($trace, 'audio_mime_detect', 'Audio MIME type detected', ['mime_type' => $mimeType]);

$payload = [
    'contents' => [[
        'parts' => [
            ['text' => 'Сделай точную транскрибацию речи из аудио на исходном языке. Верни только текст без комментариев.'],
            [
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data' => base64_encode($audioBinary),
                ],
            ],
        ],
    ]],
];

$apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . urlencode($geminiApiKey);
add_trace($trace, 'gemini_request_prepare', 'Prepared Gemini request payload');

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 90,
]);

$apiResponse = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($apiResponse === false) {
    fail('Gemini API request failed', 502, $debug, ['curl_error' => $curlError], $trace, 'gemini_request_transport');
}
add_trace($trace, 'gemini_request_transport', 'Gemini response received', ['http_code' => $httpCode]);

$decoded = json_decode($apiResponse, true);
if (!is_array($decoded)) {
    fail('Invalid Gemini API response', 502, $debug, ['raw' => $apiResponse], $trace, 'gemini_response_decode');
}

if ($httpCode >= 400) {
    $apiError = $decoded['error']['message'] ?? 'Gemini API returned error';
    fail('Gemini API error: ' . $apiError, 502, $debug, ['http_code' => $httpCode], $trace, 'gemini_response_error');
}
add_trace($trace, 'gemini_response_decode', 'Gemini response decoded');

$transcribedText = trim((string)($decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''));
if ($transcribedText === '') {
    fail('Gemini returned empty transcription', 502, $debug, ['response' => $decoded], $trace, 'gemini_response_empty_text');
}
add_trace($trace, 'gemini_response_text', 'Transcription text extracted', ['length' => strlen($transcribedText)]);

try {
    $update = $pdo->prepare('UPDATE messages SET text = :text WHERE id = :id');
    $update->execute([
        ':text' => $transcribedText,
        ':id' => $id,
    ]);
} catch (Throwable $e) {
    fail('Database error while saving transcription', 500, $debug, ['error' => $e->getMessage()], $trace, 'db_update_transcription');
}
add_trace($trace, 'db_update_transcription', 'Transcription saved to DB', ['id' => $id]);

respond([
    'status' => 'success',
    'id' => $id,
    'text' => $transcribedText,
    'trace' => $trace,
]);
