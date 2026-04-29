<?php

declare(strict_types=1);

$config = require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/db.php';

$errorMessage = null;
$messages = [];
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;
$totalMessages = 0;
$totalPages = 1;

try {
    $pdo = db_connect($config);
    $countStmt = $pdo->query('SELECT COUNT(*) FROM messages');
    $totalMessages = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalMessages / $perPage));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }
    $offset = ($currentPage - 1) * $perPage;

    $stmt = $pdo->prepare('SELECT id, question_text, text, created_at FROM messages ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $messages = $stmt->fetchAll();
    $messages = array_reverse($messages);
} catch (Throwable $e) {
    $errorMessage = 'Не удалось загрузить список сообщений.';
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <meta name="theme-color" content="#0f172a" />
  <title>AI Voice Tutor</title>
  <style>
    :root {
      --bg: #f1f5f9;
      --panel: #ffffff;
      --text: #0f172a;
      --muted: #64748b;
      --primary: #16a34a;
      --primary-dark: #15803d;
      --danger: #dc2626;
      --disabled: #cbd5e1;
      --ring: rgba(22, 163, 74, 0.35);
      --border: #e2e8f0;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      min-height: 100svh;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
      padding: 20px;
    }

    .layout {
      max-width: 1100px;
      margin: 0 auto;
      display: grid;
      gap: 18px;
    }

    .card {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 20px;
      box-shadow: 0 6px 20px rgba(15, 23, 42, 0.06);
    }

    h1 { margin: 0 0 8px; font-size: 1.35rem; }
    h2 { margin: 0 0 12px; font-size: 1.1rem; }

    p {
      margin: 0 0 16px;
      color: var(--muted);
      line-height: 1.5;
    }

    .status {
      margin: 0 0 16px;
      font-size: 1rem;
      font-weight: 600;
      min-height: 1.5rem;
    }

    .controls {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
    }

    button {
      border: 1px solid transparent;
      border-radius: 14px;
      font-size: 0.95rem;
      font-weight: 700;
      color: white;
      padding: 12px 14px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 10px 20px rgba(2, 132, 199, 0.12);
      transition: transform .15s ease, box-shadow .15s ease, background .2s ease;
    }

    button:focus-visible {
      outline: 3px solid var(--ring);
      outline-offset: 2px;
    }

    #startBtn,
    #generateQuestionBtn {
      background: var(--primary);
    }

    #startBtn:hover,
    #generateQuestionBtn:hover:not(:disabled) {
      background: var(--primary-dark);
      transform: translateY(-1px);
    }

    #stopBtn {
      background: var(--danger);
      box-shadow: 0 10px 20px rgba(220, 38, 38, 0.2);
    }

    button:disabled {
      background: var(--disabled) !important;
      color: #475569;
      cursor: not-allowed;
    }

    .hint {
      margin-top: 12px;
      font-size: 0.85rem;
      color: var(--muted);
    }

    .error-box {
      margin-bottom: 12px;
      background: #fee2e2;
      color: #991b1b;
      border: 1px solid #fecaca;
      border-radius: 10px;
      padding: 10px 12px;
    }

    .messages-list {
      display: grid;
      gap: 14px;
    }

    .message-item {
      display: grid;
      gap: 8px;
      justify-items: end;
    }

    .placeholder {
      color: var(--muted);
      font-style: italic;
    }

    .text-cell {
      max-width: min(720px, 100%);
      line-height: 1.45;
      white-space: pre-wrap;
      background: #dcfce7;
      border: 1px solid #bbf7d0;
      border-radius: 16px 16px 4px 16px;
      padding: 12px 14px;
      width: fit-content;
      margin-left: auto;
    }

    .row-status {
      margin-top: 6px;
      font-size: 0.83rem;
      color: var(--muted);
    }

    .row-status.success { color: #047857; }
    .row-status.error { color: #b91c1c; }

    .log-box {
      margin-top: 10px;
      border: 1px dashed var(--border);
      background: #f8fafc;
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 0.82rem;
      max-height: 220px;
      overflow: auto;
      line-height: 1.4;
    }

    .log-box ul {
      margin: 0;
      padding-left: 18px;
    }

    .log-box li + li {
      margin-top: 6px;
    }

    .actions {
      display: flex;
      justify-content: flex-end;
    }

    .pagination {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 14px;
    }

    .page-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 38px;
      padding: 8px 12px;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: #fff;
      color: #0f172a;
      text-decoration: none;
      font-weight: 600;
    }

    .page-link.active {
      background: #dbeafe;
      border-color: #93c5fd;
      color: #1d4ed8;
    }

    .bottom-panel {
      position: sticky;
      bottom: 0;
      z-index: 2;
      background: rgba(241, 245, 249, 0.85);
      backdrop-filter: blur(4px);
    }

    .icon {
      font-size: 1.1rem;
      line-height: 1;
    }

    @media (max-width: 700px) {
      body { padding: 12px; }
      .card { padding: 14px; }
      .text-cell { max-width: 100%; font-size: 0.92rem; }
      button { width: 100%; }
      .controls { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <main class="layout">
    <section class="card">
      <h1>AI Voice Tutor</h1>
      <div id="status" class="status" aria-live="polite">Ready</div>
    </section>

    <section class="card">
      <h2>Сообщения и транскрибация</h2>
      <?php if ($errorMessage !== null): ?>
        <div class="error-box"><?= e($errorMessage) ?></div>
      <?php endif; ?>

      <div class="messages-list">
          <?php foreach ($messages as $row): ?>
            <?php
            $id = (int)$row['id'];
            $hasText = trim((string)($row['text'] ?? '')) !== '';
            $questionText = trim((string)($row['question_text'] ?? ''));
            ?>
            <article class="message-item">
              <?php if ($questionText !== ''): ?>
                <div class="text-cell" style="background:#e0e7ff;border-color:#c7d2fe;border-radius:16px 16px 16px 4px;margin-right:auto;margin-left:0;"><strong>Вопрос:</strong><br><?= nl2br(e($questionText)) ?></div>
              <?php endif; ?>
              <div class="text-cell" data-text-cell><?php
                if ($hasText) {
                    echo '<strong>Ответ:</strong><br>' . nl2br(e(trim((string)$row['text'])));
                } else {
                    echo '<span class="placeholder">Ответ пока отсутствует</span>';
                }
              ?></div>
              <div class="row-status" data-row-status></div>
            </article>
          <?php endforeach; ?>
      </div>
      <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Пагинация сообщений">
          <?php for ($page = 1; $page <= $totalPages; $page++): ?>
            <a class="page-link <?= $page === $currentPage ? 'active' : '' ?>" href="?page=<?= $page ?>"><?= $page ?></a>
          <?php endfor; ?>
        </nav>
      <?php endif; ?>
      <div id="globalLog" class="log-box" aria-live="polite">
        <strong>Журнал действий:</strong>
        <ul id="globalLogList">
          <li>Ожидание действий...</li>
        </ul>
      </div>
    </section>

    <section class="card bottom-panel">
      <div class="controls">
        <button id="startBtn" type="button"><span class="icon">🎙️</span><span>Начать запись</span></button>
        <button id="stopBtn" type="button" disabled><span class="icon">⏹️</span><span>Остановить</span></button>
        <button id="generateQuestionBtn" type="button"><span class="icon">✨</span><span>Новый вопрос</span></button>
      </div>
    </section>
  </main>

  <script src="app.js"></script>
  <script>
    (() => {
      const globalLogList = document.getElementById('globalLogList');

      const generateBtn = document.getElementById('generateQuestionBtn');
      const generateEndpointCandidates = [
        '/api/generate_question.php',
        '../api/generate_question.php',
        'api/generate_question.php'
      ];


      const nowLabel = () => new Date().toLocaleString('ru-RU', { hour12: false });

      const appendGlobalLog = (message) => {
        if (!globalLogList) return;
        if (globalLogList.children.length === 1 && globalLogList.children[0].textContent === 'Ожидание действий...') {
          globalLogList.innerHTML = '';
        }
        const item = document.createElement('li');
        item.textContent = `[${nowLabel()}] ${message}`;
        globalLogList.appendChild(item);
      };

      window.appLog = appendGlobalLog;

      const parseResponseBody = async (response) => {
        const rawText = await response.text();

        if (!rawText) {
          return { rawText: '', json: null };
        }

        try {
          return { rawText, json: JSON.parse(rawText) };
        } catch (_) {
          return { rawText, json: null };
        }
      };

      const requestNextQuestion = async () => {
        let lastError = null;
        for (const endpoint of generateEndpointCandidates) {
          try {
            const response = await fetch(endpoint, { method: 'POST' });
            const { rawText, json } = await parseResponseBody(response);
            if (response.ok && json && json.status === 'success') {
              return json;
            }
            if (response.status === 404) continue;
            const message = json?.message || response.statusText || 'Generation failed';
            const preview = !json && rawText ? ` | ${rawText.slice(0, 120)}` : '';
            throw new Error(`${message}${preview}`);
          } catch (error) {
            lastError = error;
            break;
          }
        }
        if (lastError) throw lastError;
        throw new Error('Generation endpoint not found (/api/generate_question.php).');
      };

      if (generateBtn) {
        generateBtn.addEventListener('click', async () => {
          generateBtn.disabled = true;
          appendGlobalLog('Запрос на генерацию следующего вопроса отправлен.');
          try {
            const payload = await requestNextQuestion();
            if (payload.prompt) {
              appendGlobalLog('Промпт для Gemini: ' + String(payload.prompt));
            }
            appendGlobalLog(`Вопрос сгенерирован (id=${payload.message_id}). Обновляем страницу.`);
            window.location.reload();
          } catch (error) {
            appendGlobalLog(`Ошибка генерации вопроса: ${error.message || error}`);
            generateBtn.disabled = false;
          }
        });
      }

    })();
  </script>
</body>
</html>
