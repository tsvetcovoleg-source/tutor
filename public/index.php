<?php

declare(strict_types=1);

$config = require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/db.php';

$errorMessage = null;
$messages = [];

try {
    $pdo = db_connect($config);
    $stmt = $pdo->query('SELECT id, question_text, text, created_at FROM messages ORDER BY created_at ASC, id ASC');
    $messages = $stmt->fetchAll();
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
      gap: 12px;
      max-width: 420px;
    }

    button {
      border: none;
      border-radius: 12px;
      font-size: 0.96rem;
      font-weight: 700;
      color: white;
      padding: 10px 14px;
      cursor: pointer;
    }

    button:focus-visible {
      outline: 3px solid var(--ring);
      outline-offset: 2px;
    }

    #startBtn,
    #generateQuestionBtn,
    .btn-transcribe {
      background: var(--primary);
    }

    #startBtn:hover,
    #generateQuestionBtn:hover:not(:disabled),
    .btn-transcribe:hover:not(:disabled) {
      background: var(--primary-dark);
    }

    #stopBtn { background: var(--danger); }

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

    @media (max-width: 700px) {
      body { padding: 12px; }
      .card { padding: 14px; }
      .text-cell { max-width: 100%; font-size: 0.92rem; }
      button { width: 100%; }
      .controls { max-width: none; }
    }
  </style>
</head>
<body>
  <main class="layout">
    <section class="card">
      <h1>AI Voice Tutor</h1>
      <div id="status" class="status" aria-live="polite">Ready</div>

      <div class="controls">
        <button id="startBtn" type="button">Start speaking</button>
        <button id="stopBtn" type="button" disabled>Stop recording</button>
        <button id="generateQuestionBtn" type="button">Сгенерировать следующий вопрос</button>
      </div>

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
              <div class="actions">
                <button
                  type="button"
                  class="btn-transcribe"
                  data-transcribe-btn
                  data-id="<?= $id ?>"
                  <?= $hasText ? 'disabled' : '' ?>
                >Транскрибировать</button>
              </div>
              <div class="row-status" data-row-status></div>
            </article>
          <?php endforeach; ?>
      </div>
      <div id="globalLog" class="log-box" aria-live="polite">
        <strong>Журнал действий:</strong>
        <ul id="globalLogList">
          <li>Ожидание действий...</li>
        </ul>
      </div>
    </section>
  </main>

  <script src="app.js"></script>
  <script>
    (() => {
      const endpointCandidates = [
        '/api/transcribe.php',
        '../api/transcribe.php',
        'api/transcribe.php'
      ];
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

      const requestTranscription = async (messageId) => {
        appendGlobalLog(`Старт транскрибации для id=${messageId}`);
        let lastError = null;

        for (const endpoint of endpointCandidates) {
          try {
            const response = await fetch(endpoint, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id: Number(messageId) })
            });

            const { rawText, json } = await parseResponseBody(response);

            if (response.ok && json && json.status === 'success') {
              appendGlobalLog(`Транскрибация id=${messageId} завершена успешно через ${endpoint}`);
              return json;
            }

            if (response.status === 404) {
              continue;
            }

            const message = json?.message || response.statusText || 'Transcription failed';
            const preview = !json && rawText ? ` | ${rawText.slice(0, 120)}` : '';
            throw new Error(`${message}${preview}`);
          } catch (error) {
            appendGlobalLog(`Ошибка транскрибации id=${messageId}: ${error.message || error}`);
            lastError = error;
            break;
          }
        }

        if (lastError) {
          throw lastError;
        }

        throw new Error('Transcription endpoint not found (/api/transcribe.php).');
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

      const escapeHtml = (value) => value.replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      })[ch]);
      
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

      document.querySelectorAll('[data-transcribe-btn]').forEach((button) => {
        button.addEventListener('click', async () => {
          const row = button.closest('.message-item');
          const textCell = row.querySelector('[data-text-cell]');
          const statusNode = row.querySelector('[data-row-status]');
          const messageId = button.dataset.id;

          button.disabled = true;
          textCell.innerHTML = '<span class="placeholder">Processing...</span>';
          statusNode.textContent = '';
          statusNode.className = 'row-status';

          try {
            const payload = await requestTranscription(messageId);
            const safeText = escapeHtml(String(payload.text || ''));
            textCell.innerHTML = safeText.replace(/\n/g, '<br>');
            statusNode.textContent = 'Готово';
            statusNode.className = 'row-status success';
            appendGlobalLog(`Получен текст длиной ${safeText.length} символов для id=${messageId}`);
          } catch (error) {
            textCell.innerHTML = '<span class="placeholder">Текст пока отсутствует</span>';
            statusNode.textContent = error.message || 'Ошибка транскрибации';
            statusNode.className = 'row-status error';
            button.disabled = false;
          }
        });
      });
    })();
  </script>
</body>
</html>
