<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

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

try {
    $pdo = db_connect($config);
    $stmt = $pdo->query('SELECT question_text, text FROM messages ORDER BY created_at DESC, id DESC LIMIT 5');
    $recent = $stmt->fetchAll();
} catch (Throwable $e) {
    respond(['status' => 'error', 'message' => 'Database error'], 500);
}

$recent = array_reverse($recent);
$interactionLines = [];
foreach ($recent as $row) {
    $q = trim((string)($row['question_text'] ?? ''));
    $a = trim((string)($row['text'] ?? ''));
    if ($q === '' && $a === '') {
        continue;
    }

    $interactionLines[] = "question: " . ($q !== '' ? $q : '[missing]');
    $interactionLines[] = "candidate answer: " . ($a !== '' ? $a : '[pending]');
    $interactionLines[] = 'improved professional answer: [not available yet]';
    $interactionLines[] = 'target phrases: [not available yet]';
}

$contextBlock = $interactionLines !== [] ? implode("\n", $interactionLines) : 'No prior interactions yet.';
$prompt = "You are a senior interviewer for a fintech company.\n\nYour task is to generate the next interview question based on the candidate's recent answers.\n\nContext:\nBelow are the last 3–5 interview interactions, including:\n- question\n- candidate answer\n- improved professional answer\n- target phrases\n\n" . $contextBlock . "\n\nInstructions:\n1. Identify:\n   - weak areas in communication (lack of structure, vocabulary, clarity)\n   - missing professional phrases\n   - topics that were partially covered but not deeply explained\n\n2. Generate ONE new interview question that:\n   - stays within fintech / credit risk / lending / product context\n   - builds on previous topics\n   - pushes the candidate slightly out of comfort zone\n   - encourages explanation, reasoning, and decision-making\n\n3. The question should:\n   - be realistic for a fintech interview or work discussion\n   - include a follow-up angle (implicit or explicit)\n   - require a structured answer (not yes/no)\n\nOutput:\n- Question\n- What skill it targets (1 sentence)";

$payload = [
    'contents' => [[
        'parts' => [
            ['text' => $prompt],
        ],
    ]],
];

$apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=' . urlencode($geminiApiKey);
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

preg_match('/Question\s*[:\-]\s*(.+)/iu', $modelText, $m);
$questionText = trim($m[1] ?? '');
if ($questionText === '') {
    $lines = preg_split('/\R+/', $modelText) ?: [];
    $questionText = trim((string)($lines[0] ?? $modelText));
}

try {
    $insert = $pdo->prepare('INSERT INTO messages (role, question_text, text, audio_path) VALUES (:role, :question_text, :text, :audio_path)');
    $insert->execute([
        ':role' => 'assistant',
        ':question_text' => $questionText,
        ':text' => null,
        ':audio_path' => null,
    ]);
    $messageId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    respond(['status' => 'error', 'message' => 'Failed to save generated question'], 500);
}

respond([
    'status' => 'success',
    'message_id' => $messageId,
    'question' => $questionText,
    'raw' => $modelText,
    'prompt' => $prompt,
    'context' => $contextBlock,
]);
