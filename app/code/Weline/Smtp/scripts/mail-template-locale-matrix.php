<?php

declare(strict_types=1);

/**
 * 邮件模板 × 默认站启用语种 穷尽渲染矩阵（UC-1 / UC-2 / UC-3）。
 *
 * 权威实现：Weline\Smtp\Test\Support\MailTemplateLocaleMatrixRunner
 *
 * 断言：
 * - 非中文：resolved_locale ≠ zh_*（须为本语种或 en_US）
 * - 非中文：可见主题+正文无 CJK（白名单：长安汉服）
 * - 非 en_*：不得残留该 channel 的 en_US 种子特征串（en 占位 ≠ 真译；未真译前应红）
 * - 非 zh / 非 en_*：壳层英标 Phone:/Hours:/Address:/Need help?/Monday to Friday（test-shell-en-gate）
 *
 * 用法：
 *   php app/code/Weline/Smtp/scripts/mail-template-locale-matrix.php
 *   php app/code/Weline/Smtp/scripts/mail-template-locale-matrix.php --persist-preview \
 *     --preview-locales=de_DE,it_IT,ru_RU,pl_PL,nl_NL \
 *     --preview-channels=Weline_Newsletter::subscribe_gift,Weline_Newsletter::subscribe_welcome,Weline_Order::order_created
 *
 * 详见：app/code/Weline/Smtp/doc/开发/spec/mail-template-locale-matrix.md
 */

require dirname(__DIR__, 5) . '/app/bootstrap.php';

use Weline\Smtp\Test\Support\MailTemplateLocaleMatrixRunner;

$opts = getopt('', [
    'channel::',
    'locale::',
    'persist-preview',
    'preview-locales::',
    'preview-channels::',
    'preview-channel::',
    'json-out::',
    'fail-fast',
    'quiet',
    'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, "See header / doc/开发/spec/mail-template-locale-matrix.md\n");
    exit(0);
}

$runOpts = [
    'channel' => $opts['channel'] ?? '',
    'locale' => $opts['locale'] ?? '',
    'preview-locales' => $opts['preview-locales'] ?? 'de_DE,it_IT,ru_RU,pl_PL,nl_NL',
    'preview-channels' => $opts['preview-channels'] ?? ($opts['preview-channel'] ?? ''),
    'fail-fast' => isset($opts['fail-fast']),
    'quiet' => isset($opts['quiet']),
];
if (isset($opts['persist-preview'])) {
    $runOpts['persist-preview'] = true;
}

try {
    $result = MailTemplateLocaleMatrixRunner::run($runOpts);
} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(2);
}

$jsonOut = trim((string)($opts['json-out'] ?? ''));
if ($jsonOut === '') {
    $jsonOut = dirname(__DIR__) . '/test/evidence/matrix-' . date('Ymd-His') . '.json';
}
$jsonDir = dirname($jsonOut);
if (!is_dir($jsonDir)) {
    mkdir($jsonDir, 0775, true);
}
file_put_contents($jsonOut, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

$c = $result['counts'] ?? [];
fwrite(STDOUT, sprintf(
    "matrix verdict=%s total=%d pass=%d fail=%d en_placeholder=%d shell_en=%d cjk=%d uc1=%d locales=%d channels=%d\n",
    $result['verdict'],
    (int)($c['total'] ?? 0),
    (int)($c['pass'] ?? 0),
    (int)($c['fail'] ?? 0),
    (int)($c['en_placeholder_fails'] ?? 0),
    (int)($c['shell_en_fails'] ?? 0),
    (int)($c['cjk_fails'] ?? 0),
    (int)($c['uc1_fails'] ?? 0),
    (int)($result['meta']['locale_count'] ?? 0),
    (int)($result['meta']['channel_count'] ?? 0)
));
fwrite(STDOUT, 'json: ' . $jsonOut . "\n");

if (!empty($result['failures'])) {
    $show = array_slice($result['failures'], 0, 40);
    fwrite(STDOUT, 'failures (first ' . count($show) . "):\n");
    foreach ($show as $f) {
        fwrite(STDOUT, sprintf(
            "  - %s | %s | %s\n",
            (string)($f['locale'] ?? ''),
            (string)($f['channel'] ?? ''),
            (string)($f['reason'] ?? '')
        ));
    }
    if (count($result['failures']) > count($show)) {
        fwrite(STDOUT, '  ... +' . (count($result['failures']) - count($show)) . " more\n");
    }
}

if (!empty($result['preview_logs'])) {
    fwrite(STDOUT, "preview logs (" . count($result['preview_logs']) . "):\n");
    foreach ($result['preview_logs'] as $row) {
        fwrite(STDOUT, sprintf(
            "  - %s | %s | log_id=%s | %s\n",
            (string)($row['locale'] ?? ''),
            (string)($row['channel'] ?? ''),
            (string)($row['log_id'] ?? ($row['error'] ?? '')),
            (string)($row['url'] ?? '')
        ));
    }
}

exit(($result['verdict'] ?? '') === 'pass' ? 0 : 1);
