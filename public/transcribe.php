<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/db.php';

$debug = (bool)($config['debug'] ?? false);

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
function fail(string $message, int $statusCode, bool $debug, array $context = []): void
{
    $payload = ['status' => 'error', 'message' => $message];
    if ($debug && $context !== []) {
        $payload['debug'] = $context;
    }
    respond($payload, $statusCode);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405, $debug);
}

$rawBody = file_get_contents('php://input');
$data = json_decode((string)$rawBody, true);

if (!is_array($data)) {
    fail('Invalid JSON payload', 400, $debug);
}

$id = isset($data['id']) ? (int)$data['id'] : 0;
if ($id <= 0) {
    fail('Invalid message id', 400, $debug);
}

$geminiApiKey = getenv('GEMINI_API_KEY') ?: 'ТВОЙ_API_KEY';
if ($geminiApiKey === 'ТВОЙ_API_KEY') {
    fail('Gemini API key not configured', 500, $debug, ['hint' => 'Set GEMINI_API_KEY environment variable']);
}

try {
    $pdo = db_connect($config);
    $stmt = $pdo->prepare('SELECT id, text, audio_path FROM messages WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $message = $stmt->fetch();
} catch (Throwable $e) {
    fail('Database error while reading message', 500, $debug, ['error' => $e->getMessage()]);
}

if (!$message) {
    fail('Message not found', 404, $debug);
}

if (trim((string)($message['text'] ?? '')) !== '') {
    respond([
        'status' => 'success',
        'text' => (string)$message['text'],
        'already_transcribed' => true,
    ]);
}

$audioPath = trim((string)($message['audio_path'] ?? ''));
if ($audioPath === '') {
    fail('Audio path is empty', 400, $debug);
}

$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    fail('Cannot resolve project root', 500, $debug);
}

$relativePath = ltrim($audioPath, '/\\');
$absoluteAudioPath = realpath($projectRoot . DIRECTORY_SEPARATOR . $relativePath);

if ($absoluteAudioPath === false || strncmp($absoluteAudioPath, $projectRoot, strlen($projectRoot)) !== 0) {
    fail('Audio file path is invalid', 400, $debug, ['audio_path' => $audioPath]);
}

if (!is_file($absoluteAudioPath) || !is_readable($absoluteAudioPath)) {
    fail('Audio file is not accessible', 404, $debug, ['resolved_path' => $absoluteAudioPath]);
}

$audioBinary = file_get_contents($absoluteAudioPath);
if ($audioBinary === false) {
    fail('Cannot read audio file', 500, $debug);
}

$mimeType = mime_content_type($absoluteAudioPath) ?: 'audio/webm';

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

$apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($geminiApiKey);

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
    fail('Gemini API request failed', 502, $debug, ['curl_error' => $curlError]);
}

$decoded = json_decode($apiResponse, true);
if (!is_array($decoded)) {
    fail('Invalid Gemini API response', 502, $debug, ['raw' => $apiResponse]);
}

if ($httpCode >= 400) {
    $apiError = $decoded['error']['message'] ?? 'Gemini API returned error';
    fail('Gemini API error: ' . $apiError, 502, $debug, ['http_code' => $httpCode]);
}

$transcribedText = trim((string)($decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''));
if ($transcribedText === '') {
    fail('Gemini returned empty transcription', 502, $debug, ['response' => $decoded]);
}

try {
    $update = $pdo->prepare('UPDATE messages SET text = :text WHERE id = :id');
    $update->execute([
        ':text' => $transcribedText,
        ':id' => $id,
    ]);
} catch (Throwable $e) {
    fail('Database error while saving transcription', 500, $debug, ['error' => $e->getMessage()]);
}

respond([
    'status' => 'success',
    'id' => $id,
    'text' => $transcribedText,
]);
