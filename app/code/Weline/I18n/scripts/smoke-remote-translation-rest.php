<?php

declare(strict_types=1);

/**
 * 本机远程协助翻译 REST/Query 运行时冒烟（非生产）。
 * 用法：php app/code/Weline/I18n/scripts/smoke-remote-translation-rest.php
 * 结果：generated/tmp/remote-translation-rest-smoke.json
 */

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$result = [
    'started_at' => date('c'),
    'steps' => [],
    'ok' => true,
];

$outFile = (defined('BP') ? BP : dirname(__DIR__, 5) . '/')
    . 'generated/tmp/remote-translation-rest-smoke.json';

$step = static function (string $name, callable $fn) use (&$result): void {
    $t0 = microtime(true);
    try {
        $data = $fn();
        $result['steps'][$name] = [
            'ok' => true,
            'ms' => (int)round((microtime(true) - $t0) * 1000),
            'data' => $data,
        ];
        echo "[OK] {$name}\n";
    } catch (Throwable $e) {
        $result['ok'] = false;
        $result['steps'][$name] = [
            'ok' => false,
            'ms' => (int)round((microtime(true) - $t0) * 1000),
            'error' => $e->getMessage(),
            'code' => (int)$e->getCode(),
        ];
        echo "[FAIL] {$name}: {$e->getMessage()}\n";
    }
};

$source = '__remote_rt_closeout_' . date('YmdHis') . '__';

$step('websites_list', static function () {
    $rows = w_query('websites', 'getWebsiteList', []);
    if (!is_array($rows) || $rows === []) {
        throw new RuntimeException('website list empty');
    }
    return ['count' => count($rows)];
});

$step('website_locales', static function () {
    $codes = w_query('websites', 'getWebsiteLanguageCodes', ['website_id' => 0]);
    if (!is_array($codes) || !in_array('en_US', $codes, true)) {
        throw new RuntimeException('en_US not in website locales');
    }
    return ['locales' => array_values($codes)];
});

$step('pending', static function () {
    $r = w_query('i18n_remote_translation', 'remoteTranslationPending', [
        'website_id' => 0,
        'locales' => ['en_US'],
        'limit' => 2,
    ]);
    if (!is_array($r) || !isset($r['items'], $r['has_more'], $r['limit'])) {
        throw new RuntimeException('pending schema mismatch');
    }
    return [
        'items' => count($r['items']),
        'has_more' => (bool)$r['has_more'],
        'next_cursor' => $r['next_cursor'] ?? null,
    ];
});

$step('ingest_write_and_invalid', static function () use ($source) {
    $r = w_query('i18n_remote_translation', 'remoteTranslationIngest', [
        'website_id' => 0,
        'items' => [
            ['source' => $source, 'locale' => 'en_US', 'translation' => 'Closeout RT'],
            ['source' => $source, 'locale' => 'en_US', 'translation' => 'dup in batch'],
            ['source' => '', 'locale' => 'en_US', 'translation' => 'x'],
        ],
    ]);
    if ((int)($r['written'] ?? 0) < 1) {
        throw new RuntimeException('expected written>=1 got ' . json_encode($r));
    }
    if ((int)($r['invalid'] ?? 0) < 1) {
        throw new RuntimeException('expected invalid>=1');
    }
    return $r;
});

$step('ingest_skip_conflict', static function () use ($source) {
    $r = w_query('i18n_remote_translation', 'remoteTranslationIngest', [
        'website_id' => 0,
        'items' => [
            ['source' => $source, 'locale' => 'en_US', 'translation' => 'should skip'],
        ],
    ]);
    if ((int)($r['skipped'] ?? 0) < 1 || (int)($r['written'] ?? -1) !== 0) {
        throw new RuntimeException('expected skip conflict: ' . json_encode($r));
    }
    return $r;
});

$taskId = null;
$step('collect_start', static function () use (&$taskId) {
    $r = w_query('i18n_remote_translation', 'remoteTranslationCollectStart', [
        'owner_key' => 'rt-closeout',
        'website_id' => 0,
    ]);
    $taskId = (string)($r['task_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $taskId)) {
        throw new RuntimeException('bad task_id: ' . json_encode($r));
    }
    return ['task_id' => $taskId];
});

$step('collect_status_owner', static function () use (&$taskId) {
    $r = w_query('i18n_remote_translation', 'remoteTranslationCollectStatus', [
        'task_id' => (string)$taskId,
        'owner_key' => 'rt-closeout',
    ]);
    if (($r['owner_bound'] ?? false) !== true) {
        throw new RuntimeException('owner_bound false');
    }
    return $r;
});

$step('collect_status_foreign_404', static function () use (&$taskId) {
    try {
        w_query('i18n_remote_translation', 'remoteTranslationCollectStatus', [
            'task_id' => (string)$taskId,
            'owner_key' => 'not-owner',
        ]);
        throw new RuntimeException('expected 404 for foreign owner');
    } catch (Throwable $e) {
        if ((int)$e->getCode() !== 404) {
            throw $e;
        }
        return ['code' => 404, 'message' => $e->getMessage()];
    }
});

$step('http_unauth_401', static function () {
    $env = require BP . 'app/etc/env.php';
    $prefix = (string)($env['router']['area_routes']['rest_backend']['prefix'] ?? '');
    if ($prefix === '') {
        throw new RuntimeException('rest_backend prefix empty');
    }
    $url = 'https://p05113ef3.test.weline.com:9555/' . $prefix
        . '/websites/rest/v1/remote-translation-catalog/websites';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 401) {
        throw new RuntimeException('expected HTTP 401 got ' . $code . ' body=' . substr((string)$raw, 0, 200));
    }
    return ['url' => $url, 'http' => 401];
});

$result['finished_at'] = date('c');
$dir = dirname($outFile);
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
file_put_contents($outFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo ($result['ok'] ? "SMOKE_PASS " : "SMOKE_FAIL ") . $outFile . "\n";
exit($result['ok'] ? 0 : 1);
