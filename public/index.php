<?php

declare(strict_types=1);

$config = require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/db.php';

$errorMessage = null;
$messages = [];

try {
    $pdo = db_connect($config);
    $stmt = $pdo->query('SELECT id, text, audio_path, created_at FROM messages ORDER BY created_at DESC, id DESC');
    $messages = $stmt->fetchAll();
} catch (Throwable $e) {
    $errorMessage = 'Не удалось загрузить сообщения из БД.';
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Транскрибация сообщений</title>
    <style>
        :root {
            --bg: #f3f4f6;
            --surface: #ffffff;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #047857;
            --danger: #b91c1c;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 24px;
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
        }

        .header h1 {
            margin: 0;
            font-size: 1.4rem;
        }

        .header p {
            margin: 6px 0 0;
            color: var(--muted);
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 920px;
        }

        th, td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: top;
        }

        th {
            font-size: 0.83rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
            background: #f9fafb;
        }

        .text-cell {
            max-width: 460px;
            white-space: pre-wrap;
            line-height: 1.45;
        }

        .placeholder {
            color: var(--muted);
            font-style: italic;
        }

        .status {
            font-size: 0.87rem;
            margin-top: 6px;
            color: var(--muted);
        }

        .status.success { color: var(--success); }
        .status.error { color: var(--danger); }

        button {
            border: 0;
            background: var(--primary);
            color: #fff;
            border-radius: 10px;
            padding: 9px 14px;
            font-weight: 600;
            cursor: pointer;
            transition: .15s ease;
        }

        button:hover:not(:disabled) { background: var(--primary-hover); }

        button:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .error-box {
            margin: 20px 24px;
            background: #fef2f2;
            color: var(--danger);
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 12px 14px;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Сообщения и транскрибация</h1>
        <p>Нажмите «Транскрибировать», чтобы отправить аудио в Gemini 1.5 Flash.</p>
    </div>

    <?php if ($errorMessage !== null): ?>
        <div class="error-box"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Дата</th>
                    <th>Аудио</th>
                    <th>Текст</th>
                    <th>Действие</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($messages as $row): ?>
                <?php
                    $id = (int)$row['id'];
                    $hasText = trim((string)($row['text'] ?? '')) !== '';
                ?>
                <tr data-id="<?= $id ?>">
                    <td><?= e((string)$row['created_at']) ?></td>
                    <td>
                        <?php if (!empty($row['audio_path'])): ?>
                            <audio controls preload="none" src="<?= e((string)$row['audio_path']) ?>"></audio>
                        <?php else: ?>
                            <span class="placeholder">Нет аудио</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-cell" data-text-cell>
                        <?php if ($hasText): ?>
                            <?= nl2br(e((string)$row['text'])) ?>
                        <?php else: ?>
                            <span class="placeholder">Текст пока отсутствует</span>
                        <?php endif; ?>
                        <div class="status" data-status></div>
                    </td>
                    <td>
                        <button
                            data-transcribe-btn
                            data-id="<?= $id ?>"
                            <?= $hasText ? 'disabled' : '' ?>
                        >Транскрибировать</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.querySelectorAll('[data-transcribe-btn]').forEach((button) => {
    button.addEventListener('click', async () => {
        const row = button.closest('tr');
        const textCell = row.querySelector('[data-text-cell]');
        const statusNode = row.querySelector('[data-status]');
        const messageId = button.dataset.id;

        if (!messageId) return;

        button.disabled = true;
        textCell.innerHTML = '<span class="placeholder">Processing...</span>';
        statusNode.textContent = '';
        statusNode.className = 'status';

        try {
            const response = await fetch('transcribe.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: Number(messageId) })
            });

            const data = await response.json();

            if (!response.ok || data.status !== 'success') {
                throw new Error(data.message || 'Транскрибация не удалась.');
            }

            const safeText = (data.text || '').replace(/[&<>"']/g, (ch) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[ch]);

            textCell.innerHTML = safeText.replace(/\n/g, '<br>');
            statusNode.textContent = 'Готово';
            statusNode.className = 'status success';
        } catch (error) {
            textCell.innerHTML = '<span class="placeholder">Текст пока отсутствует</span>';
            statusNode.textContent = error.message || 'Ошибка при обработке';
            statusNode.className = 'status error';
            button.disabled = false;
        }
    });
});
</script>
</body>
</html>
