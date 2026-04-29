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

$geminiApiKey = (string)($config['gemini_api_key'] ?? '');
if ($geminiApiKey === '') {
    respond(['status' => 'error', 'message' => 'Gemini API key not configured'], 500);
}

$promptTemplate = (string)($prompts['improvement_listening'] ?? '');
if ($promptTemplate === '') {
    respond(['status' => 'error', 'message' => 'Prompt template not configured'], 500);
}

try {
    $pdo = db_connect($config);
    $stmt = $pdo->query('SELECT id, text, text_grammar FROM messages WHERE text IS NOT NULL AND text <> "" AND text_grammar IS NOT NULL AND text_grammar <> "" ORDER BY created_at DESC, id DESC LIMIT 4');
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    respond(['status' => 'error', 'message' => 'Database error'], 500);
}

if (count($rows) === 0) {
    respond(['status' => 'error', 'message' => 'No completed iterations found'], 400);
}

$rows = array_reverse($rows);
$chunks = [];
foreach ($rows as $row) {
    $chunks[] = 'Candidate answer: ' . trim((string)$row['text']) . "\n" . 'Improved professional version: ' . trim((string)$row['text_grammar']);
}
$contextBlock = implode("\n\n", $chunks);
$prompt = str_replace('{{CONTEXT_BLOCK}}', $contextBlock, $promptTemplate);

$payload = [
    'contents' => [[
        'parts' => [
            ['text' => $prompt],
        ],
    ]],
    'generationConfig' => [
        'temperature' => 0.3,
        'maxOutputTokens' => 700,
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

$modelText = trim((string)($decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''));
if ($modelText === '') {
    respond(['status' => 'error', 'message' => 'Gemini returned empty response'], 502);
}

try {
    $insert = $pdo->prepare('INSERT INTO improvements (phrases, history) VALUES (:phrases, :history)');
    $insert->execute([
        ':phrases' => $modelText,
        ':history' => $contextBlock,
    ]);
    $improvementId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    respond(['status' => 'error', 'message' => 'Failed to save improvement'], 500);
}

respond([
    'status' => 'success',
    'id' => $improvementId,
    'result' => $modelText,
]);
