<?php

declare(strict_types=1);

use LearningMcp\Config;
use LearningMcp\DataDirLegacyPurge;
use LearningMcp\SharedIndexLeftoverPurge;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$temporary = sys_get_temp_dir() . '/weline-shared-index-purge-' . bin2hex(random_bytes(5));
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
    $hash = str_repeat('cd', 32);
    $generation = str_repeat('ef', 32);
    $shared = $temporary . '/.learning-mcp';
    $dataDir = $shared . '/projects/' . $hash;
    $localIndex = $dataDir . '/indexes/' . $generation;
    $sharedIndex = $shared . '/indexes/' . $generation;
    mkdir($localIndex, 0700, true);
    mkdir($sharedIndex, 0700, true);
    file_put_contents($localIndex . '/project.sqlite', 'local-index');
    file_put_contents($sharedIndex . '/project.sqlite', 'shared-leftover');
    file_put_contents($sharedIndex . '/.weline-index-owner.json', '{"owner":"test"}');
    file_put_contents($dataDir . '/.migrated-from-shared-v1', "{\"status\":\"moved\"}\n");
    file_put_contents($dataDir . '/learning.db', 'live');
    file_put_contents($dataDir . '/learning.db.bak-delete-conflict-20261011', 'old-bak');
    file_put_contents($dataDir . '/learning.db-wal', 'wal-keep');
    file_put_contents($temporary . '/config.json', json_encode([
        'data_dir' => $dataDir,
        'analysis' => ['provider' => 'none'],
        'index' => [
            'sidecar_enabled' => false,
            'gc' => [
                'enabled' => true,
                'purge_shared_leftover' => true,
                'checkpoint_wal' => false,
            ],
        ],
        'storage' => ['purge_legacy' => true, 'legacy_ttl' => '1d'],
    ], JSON_THROW_ON_ERROR));
    $config = Config::load($temporary . '/config.json', $dataDir);

    $dry = (new SharedIndexLeftoverPurge($config))->sweep(false);
    $check(($dry['eligible'] ?? false) === true, 'shared leftover is eligible after migration marker');
    $check(($dry['candidates'] ?? []) === [$sharedIndex], 'dry-run lists the shared leftover generation');
    $check(is_dir($sharedIndex), 'dry-run does not delete the shared leftover');

    $legacy = (new DataDirLegacyPurge($config, static fn (): int => 1_700_000_000))->sweep();
    $check(($legacy['shared_index_leftover']['eligible'] ?? false) === true, 'legacy purge reports shared leftover metrics');
    $check(!is_dir($sharedIndex), 'legacy purge deletes shared leftover generation');
    $check(is_file($localIndex . '/project.sqlite'), 'project-local index is kept');
    $check(!is_file($dataDir . '/learning.db.bak-delete-conflict-20261011'), 'learning.db.bak* is purged');
    $check(is_file($dataDir . '/learning.db'), 'live learning.db is kept');
    $check(is_file($dataDir . '/learning.db-wal'), 'learning.db-wal is kept');

    // Sibling project tree must never be touched.
    $siblingHash = str_repeat('11', 32);
    $sibling = $shared . '/projects/' . $siblingHash . '/indexes/' . str_repeat('22', 32);
    mkdir($sibling, 0700, true);
    file_put_contents($sibling . '/project.sqlite', 'sibling');
    mkdir($shared . '/indexes/' . str_repeat('33', 32), 0700, true);
    file_put_contents($shared . '/indexes/' . str_repeat('33', 32) . '/project.sqlite', 'again');
    $again = (new SharedIndexLeftoverPurge($config))->sweep(true);
    $check(($again['removed'] ?? []) !== [], 'second leftover generation is purged');
    $check(is_file($sibling . '/project.sqlite'), 'sibling project index is never purged');
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");
} finally {
    $removeTree($temporary);
}

fwrite(STDOUT, json_encode(['checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES) . "\n");
exit($failures === [] ? 0 : 1);
