<?php
/**
 * Shared HTTP status page shell.
 *
 * Expected variables (set by ErrorPageRenderer or per-code templates):
 * @var int    $statusCode
 * @var string $statusText
 * @var string $message
 * @var string $pageTitle
 * @var string $pageLead
 * @var string $pageHint
 * @var string $homeHref
 * @var string $requestId
 * @var string $detail
 * @var bool   $isDev
 * @var string $accent
 */

$statusCode = (int)($statusCode ?? $code ?? 500);
$statusText = (string)($statusText ?? 'Error');
$message = (string)($message ?? $msg ?? $statusText);
$pageTitle = (string)($pageTitle ?? $statusText);
$pageLead = (string)($pageLead ?? '');
$pageHint = (string)($pageHint ?? '');
$homeHref = (string)($homeHref ?? '/');
$requestId = (string)($requestId ?? '');
$detail = (string)($detail ?? '');
$isDev = (bool)($isDev ?? false);
$accent = (string)($accent ?? 'neutral');

$h = static fn(string $value): string => \htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$codeClass = 'w-error-code' . match ($accent) {
    'danger' => ' is-danger',
    'warn' => ' is-warn',
    default => '',
};
$showMessage = $message !== '' && $message !== $statusText && $message !== $pageLead;
$homeJs = \json_encode($homeHref !== '' ? $homeHref : '/', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $h((string)$statusCode) ?> · <?= $h($pageTitle) ?></title>
    <style>
        :root {
            color-scheme: light;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --bg: #f8fafc;
            --card: #ffffff;
            --accent: #0f766e;
            --danger: #b91c1c;
            --warn: #b45309;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: "IBM Plex Sans", "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1200px 600px at 10% -10%, #ccfbf1 0%, transparent 55%),
                radial-gradient(900px 500px at 100% 0%, #e2e8f0 0%, transparent 50%),
                var(--bg);
        }
        .w-error {
            width: min(560px, 100%);
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 36px 32px;
            box-shadow: 0 18px 50px rgba(15, 23, 42, 0.06);
        }
        .w-error-code {
            font-family: "IBM Plex Mono", ui-monospace, monospace;
            font-size: clamp(3rem, 8vw, 4.5rem);
            font-weight: 600;
            letter-spacing: -0.04em;
            line-height: 1;
            color: var(--accent);
            margin: 0 0 12px;
        }
        .w-error-code.is-danger { color: var(--danger); }
        .w-error-code.is-warn { color: var(--warn); }
        h1 {
            margin: 0 0 10px;
            font-size: 1.5rem;
            font-weight: 650;
            letter-spacing: -0.02em;
        }
        .w-error-lead,
        .w-error-hint,
        .w-error-msg,
        .w-error-meta {
            margin: 0 0 10px;
            color: var(--muted);
            line-height: 1.55;
        }
        .w-error-msg { color: var(--ink); }
        .w-error-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 22px;
        }
        .w-error-actions a,
        .w-error-actions button {
            appearance: none;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--ink);
            text-decoration: none;
            border-radius: 999px;
            padding: 10px 16px;
            font: inherit;
            cursor: pointer;
        }
        .w-error-actions a.primary {
            background: var(--ink);
            border-color: var(--ink);
            color: #fff;
        }
        .w-error-detail {
            margin-top: 18px;
            padding: 12px;
            border-radius: 10px;
            background: #0f172a;
            color: #e2e8f0;
            font-size: 12px;
            overflow: auto;
            max-height: 220px;
        }
    </style>
</head>
<body>
<main class="w-error" role="alert">
    <p class="<?= $h($codeClass) ?>"><?= $h((string)$statusCode) ?></p>
    <h1><?= $h($pageTitle) ?></h1>
    <?php if ($pageLead !== ''): ?>
        <p class="w-error-lead"><?= $h($pageLead) ?></p>
    <?php endif; ?>
    <?php if ($showMessage): ?>
        <p class="w-error-msg"><?= $h($message) ?></p>
    <?php endif; ?>
    <?php if ($pageHint !== ''): ?>
        <p class="w-error-hint"><?= $h($pageHint) ?></p>
    <?php endif; ?>
    <?php if ($requestId !== ''): ?>
        <p class="w-error-meta">Request ID: <?= $h($requestId) ?></p>
    <?php endif; ?>
    <?php if ($isDev && $detail !== ''): ?>
        <pre class="w-error-detail"><?= $h($detail) ?></pre>
    <?php endif; ?>
    <div class="w-error-actions">
        <a class="primary" href="<?= $h($homeHref !== '' ? $homeHref : '/') ?>">返回首页</a>
        <button type="button" data-w-error-back>返回上一页</button>
    </div>
</main>
<script>
document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest && e.target.closest('[data-w-error-back]');
    if (!btn) return;
    if (window.history.length > 1) { window.history.back(); return; }
    window.location.href = <?= $homeJs ?>;
});
</script>
</body>
</html>
