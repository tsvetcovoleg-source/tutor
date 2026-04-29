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
    $stmt = $pdo->prepare('SELECT id, question_text, text FROM messages WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    respond(['status' => 'error', 'message' => 'Database error'], 500);
}

if (!$row) {
    respond(['status' => 'error', 'message' => 'Message not found'], 404);
}

$questionText = trim((string)($row['question_text'] ?? ''));
$userAnswer = trim((string)($row['text'] ?? ''));

if ($questionText === '') {
    respond(['status' => 'error', 'message' => 'Question text is empty'], 400);
}
if ($userAnswer === '') {
    respond(['status' => 'error', 'message' => 'Message text is empty'], 400);
}

$promptTemplate = <<<'PROMPT'
You are a senior interviewer for a Credit Risk Business Lead role in an international fintech company.

You will receive:

1. Interview question
2. Candidate answer

Your task is to evaluate the candidate's answer.

Evaluation criteria:

1. English Quality
   Evaluate grammar, vocabulary, fluency, and whether the answer sounds natural in a professional interview.

2. Clarity & Structure
   Evaluate whether the answer is clear, logically organized, easy to follow, and not too vague.

3. Risk & Decision Thinking
   Evaluate whether the candidate demonstrates credit risk logic, practical decision-making, risk-based judgment, and understanding of business trade-offs.

4. Stakeholder Thinking
   Evaluate whether the candidate considers product, business, risk, regulator, customer, or management perspectives where relevant.

Scoring rules:

* Give each criterion a score from 0 to 10.
* Use one decimal if needed.
* Calculate the overall score as the average of the four criteria.
* Do not be too soft. Evaluate as a real fintech interviewer.
* If the answer is too short or vague, reduce the score.

Feedback rules:

* Provide ONE общий комментарий (max 5 sentences).
* Explain in simple, clear English.
* Focus on how the answer can be improved to get a higher score.
* Suggest what is missing (e.g., clearer structure, decision, risk logic, stakeholder view).
* Do NOT rewrite the answer.
* Do NOT provide a better answer.
* Do NOT ask a new question.

Output ONLY valid JSON in this format:

{
"english_quality": 0,
"clarity_structure": 0,
"risk_decision_thinking": 0,
"stakeholder_thinking": 0,
"overall_score": 0,
"improvement_comment": "..."
}

Interview question:
"""
{{QUESTION}}
"""

Candidate answer:
"""
{{ANSWER}}
"""
PROMPT;

$prompt = str_replace(['{{QUESTION}}', '{{ANSWER}}'], [$questionText, $userAnswer], $promptTemplate);

$payload = [
    'contents' => [[
        'parts' => [
            ['text' => $prompt],
        ],
    ]],
    'generationConfig' => [
        'temperature' => 0.2,
        'maxOutputTokens' => 400,
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

$evaluation = json_decode($modelText, true);
if (!is_array($evaluation)) {
    respond(['status' => 'error', 'message' => 'Gemini did not return valid JSON', 'raw' => $modelText], 502);
}

try {
    $update = $pdo->prepare('UPDATE messages SET evaluation = :evaluation WHERE id = :id');
    $update->execute([
        ':evaluation' => json_encode($evaluation, JSON_UNESCAPED_UNICODE),
        ':id' => $id,
    ]);
} catch (Throwable $e) {
    respond(['status' => 'error', 'message' => 'Failed to save evaluation'], 500);
}

respond([
    'status' => 'success',
    'id' => $id,
    'evaluation' => $evaluation,
]);
