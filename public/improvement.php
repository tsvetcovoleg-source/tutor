<?php

declare(strict_types=1);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Improvement</title>
  <style>
    body { font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif; background:#f1f5f9; margin:0; padding:20px; color:#0f172a; }
    .card { max-width: 900px; margin: 0 auto; background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:20px; box-shadow:0 6px 20px rgba(15,23,42,.06); }
    button { background:#16a34a; color:#fff; border:none; border-radius:12px; padding:12px 16px; font-weight:700; cursor:pointer; }
    button:disabled { background:#94a3b8; cursor:not-allowed; }
    pre { white-space: pre-wrap; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px; min-height:120px; }
    .muted { color:#64748b; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Improvement</h1>
    <p class="muted">Соберём последние 4 итерации (text + text_grammar), отправим в промпт и сохраним результат в БД.</p>
    <p><a href="index.php">← Back to index</a></p>
    <button id="improveBtn" type="button">Improve your English</button>
    <h2>Result</h2>
    <pre id="resultBox">Waiting for request...</pre>
  </div>

  <script>
    (() => {
      const btn = document.getElementById('improveBtn');
      const box = document.getElementById('resultBox');
      const endpoints = ['/api/generate_improvement.php', '../api/generate_improvement.php', 'api/generate_improvement.php'];

      const parseResponseBody = async (response) => {
        const rawText = await response.text();
        if (!rawText) return { rawText: '', json: null };
        try { return { rawText, json: JSON.parse(rawText) }; } catch (_) { return { rawText, json: null }; }
      };

      const requestImprovement = async () => {
        let lastError = null;
        for (const endpoint of endpoints) {
          try {
            const response = await fetch(endpoint, { method: 'POST' });
            const { rawText, json } = await parseResponseBody(response);
            if (response.ok && json && json.status === 'success') return json;
            if (response.status === 404) continue;
            const message = json?.message || response.statusText || 'Generation failed';
            const preview = !json && rawText ? ` | ${rawText.slice(0, 180)}` : '';
            throw new Error(`${message}${preview}`);
          } catch (error) {
            lastError = error;
            break;
          }
        }
        if (lastError) throw lastError;
        throw new Error('Improvement endpoint not found');
      };

      btn.addEventListener('click', async () => {
        btn.disabled = true;
        box.textContent = 'Generating...';
        try {
          const payload = await requestImprovement();
          box.textContent = payload.result || 'Empty result';
        } catch (error) {
          box.textContent = `Error: ${error.message || error}`;
          btn.disabled = false;
        }
      });
    })();
  </script>
</body>
</html>
