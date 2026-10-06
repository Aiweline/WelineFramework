<?php

declare(strict_types=1);

use LearningMcp\Config;
use LearningMcp\DataDirLegacyPurge;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$temporary = sys_get_temp_dir() . '/weline-legacy-purge-' . bin2hex(random_bytes(5));
$failures = [];
$checks = 0;
$check = static function (bool $passed, string $message) use (&$checks, &$failures): void {
    ++$checks;
    if (!$passed) {
        $failures[] = $message;
    }
    fwrite($passed ? STDOUT : STDERR, ($passed ? '[PASS] ' : '[FAIL] ') . $message . "\n");
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $removeTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
};

try {
    $hash = str_repeat('ab', 32);
    $shared = $temporary . '/.learning-mcp';
    $dataDir = $shared . '/projects/' . $hash;
    mkdir($dataDir . '/indexes/keep', 0700, true);
    mkdir($shared . '/edit-journal/old-ticket', 0700, true);
    mkdir($shared . '/edit-journal/fresh-ticket', 0700, true);
    mkdir($shared . '/history-backups/clone.git', 0700, true);
    mkdir($shared . '/edit-locks', 0700, true);
    mkdir($dataDir . '/edit-journal/old-ticket', 0700, true);
    file_put_contents($shared . '/edit-journal/old-ticket/file.before', 'snapshot');
    file_put_contents($shared . '/edit-journal/fresh-ticket/file.after', 'live');
    file_put_contents($shared . '/history-backups/clone.git/HEAD', 'ref');
    file_put_contents($shared . '/edit-locks/stale.lock', 'lock');
    file_put_contents($dataDir . '/edit-journal/old-ticket/file.after', 'snapshot');
    file_put_contents($dataDir . '/indexes/keep/project.sqlite', 'index');
    file_put_contents($dataDir . '/learning.db', 'scoped');
    file_put_contents($shared . '/learning.db', 'unscoped leftover');
    file_put_contents($shared . '/config.yaml', 'keep');
    $expired = 1_577_836_800;
    $fresh = 1_700_000_000;
    foreach ([
        $shared . '/edit-journal/old-ticket/file.before',
        $shared . '/history-backups/clone.git/HEAD',
        $shared . '/edit-locks/stale.lock',
        $dataDir . '/edit-journal/old-ticket/file.after',
    ] as $path) {
        touch($path, $expired);
    }
    touch($shared . '/edit-journal/fresh-ticket/file.after', $fresh);
    file_put_contents($temporary . '/config.json', json_encode([
        'data_dir' => $dataDir,
        'analysis' => ['provider' => 'none'],
        'index' => ['sidecar_enabled' => false],
        'storage' => ['purge_legacy' => true, 'legacy_ttl' => '1d'],
    ], JSON_THROW_ON_ERROR));
    $config = Config::load($temporary . '/config.json', $dataDir);
    $result = (new DataDirLegacyPurge($config, static fn (): int => $fresh))->sweep();
    $check(($result['enabled'] ?? false) === true, 'legacy purge is enabled by default');
    $check((int) ($result['ttl_seconds'] ?? 0) === 86_400, 'legacy_ttl is one day');
    $check(!is_dir($shared . '/edit-journal/old-ticket'), 'expired edit-journal ticket is removed');
    $check(is_file($shared . '/edit-journal/fresh-ticket/file.after'), 'fresh edit-journal ticket is kept until expiry');
    $check(!is_dir($shared . '/history-backups'), 'expired history-backups is removed');
    $check(!is_file($shared . '/edit-locks/stale.lock'), 'expired edit-lock is removed');
    $check(!is_dir($dataDir . '/edit-journal'), 'expired project edit-journal is removed');
    $check(!is_file($shared . '/learning.db'), 'unscoped leftover learning.db is removed immediately');
    $check(is_file($dataDir . '/learning.db'), 'isolated learning.db is kept');
    $check(is_file($dataDir . '/indexes/keep/project.sqlite'), 'project index is kept');
    $check(is_file($shared . '/config.yaml'), 'shared config is kept');
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");
} finally {
    $removeTree($temporary);
}

fwrite(STDOUT, json_encode(['checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES) . "\n");
exit($failures === [] ? 0 : 1);
