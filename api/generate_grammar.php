<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
$prompts = require __DIR__ . '/prompts.php';

function respond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$rawBody = file_get_contents('php://input');
$data = json_decode((string)$rawBody, true);
$id = is_array($data) ? (int)($data['id'] ?? 0) : 0;
if ($id <= 0) {
    respond(['status' => 'error', 'message' => 'Invalid message id'], 400);
}

$geminiApiKey = (string)($config['gemini_api_key'] ?? '');
if ($geminiApiKey === '') {
    respond(['status' => 'error', 'message' => 'Gemini API key not configured'], 500);
}

try {
    $pdo = db_connect($config);
    $stmt = $pdo->prepare('SELECT id, text FROM messages WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    respond(['status' => 'error', 'message' => 'Database error'], 500);
}

if (!$row) {
    respond(['status' => 'error', 'message' => 'Message not found'], 404);
}

$userAnswer = trim((string)($row['text'] ?? ''));
if ($userAnswer === '') {
    respond(['status' => 'error', 'message' => 'Message text is empty'], 400);
}

$promptTemplate = (string)($prompts['generate_grammar'] ?? '');
if ($promptTemplate === '') {
    respond(['status' => 'error', 'message' => 'Prompt template not configured'], 500);
}

$prompt = str_replace('{{USER_ANSWER}}', $userAnswer, $promptTemplate);


$payload = [
    'contents' => [[
        'parts' => [
            ['text' => $prompt],
        ],
    ]],
    'generationConfig' => [
        'temperature' => 0.2,
        'maxOutputTokens' => 300,
    ],
];

$apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite-preview:generateContent?key=' . urlencode($geminiApiKey);
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 90,
]);

$apiResponse = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($apiResponse === false) {
    respond(['status' => 'error', 'message' => 'Gemini request failed: ' . $curlError], 502);
}

$decoded = json_decode($apiResponse, true);
if (!is_array($decoded) || $httpCode >= 400) {
    $apiError = is_array($decoded) ? (string)($decoded['error']['message'] ?? 'Gemini API error') : 'Invalid Gemini response';
    respond(['status' => 'error', 'message' => $apiError], 502);
}

$textGrammar = trim((string)($decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''));
if ($textGrammar === '') {
    respond(['status' => 'error', 'message' => 'Gemini returned empty response'], 502);
}

try {
    $update = $pdo->prepare('UPDATE messages SET text_grammar = :text_grammar WHERE id = :id');
    $update->execute([
        ':text_grammar' => $textGrammar,
        ':id' => $id,
    ]);
} catch (Throwable $e) {
    respond(['status' => 'error', 'message' => 'Failed to save grammar text'], 500);
}

respond([
    'status' => 'success',
    'id' => $id,
    'text_grammar' => $textGrammar,
]);
