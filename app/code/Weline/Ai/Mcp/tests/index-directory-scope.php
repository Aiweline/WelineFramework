<?php

declare(strict_types=1);

use LearningMcp\Config;
use LearningMcp\ProjectIndex;
use LearningMcp\ProjectIndexer;
use LearningMcp\ProjectResolver;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$temporary = sys_get_temp_dir() . '/weline-index-directory-' . bin2hex(random_bytes(5));
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
    $root = $temporary . '/project';
    $put = static function (string $path, string $body = 'fixture') use ($root): void {
        $absolute = $root . '/' . $path;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0700, true);
        }
        file_put_contents($absolute, $body . "\n");
    };
    $put('target/Existing.txt');
    $put('target/nested/Keep.txt');
    $put('elsewhere/Existing.txt');
    file_put_contents($temporary . '/config.json', json_encode([
        'data_dir' => $temporary . '/data',
        'analysis' => ['provider' => 'none'],
        'index' => ['sidecar_enabled' => false, 'excluded_paths' => ['target/excluded/**']],
    ], JSON_THROW_ON_ERROR));
    $config = Config::load($temporary . '/config.json', $temporary . '/data');
    $index = new ProjectIndex($config, ProjectResolver::resolve($root));
    $indexer = new ProjectIndexer($index, $config);
    $paths = static fn (): array => $index->pdo()->query('SELECT path FROM indexed_files ORDER BY path')->fetchAll(PDO::FETCH_COLUMN);
    $indexer->indexPaths(['target/Existing.txt', 'elsewhere/Existing.txt']);
    $first = $indexer->indexPaths(['target']);
    $check($first['discovered'] === 2 && $paths() === [
        'elsewhere/Existing.txt', 'target/Existing.txt', 'target/nested/Keep.txt',
    ], 'an explicit directory discovers only its files and nested files while preserving outside records');

    unlink($root . '/target/Existing.txt');
    $put('target/Added.txt', 'new in scope');
    $put('elsewhere/NotRequested.txt', 'outside scope');
    $second = $indexer->indexPaths(['target']);
    $check($second['deleted'] === 1 && $paths() === [
        'elsewhere/Existing.txt', 'target/Added.txt', 'target/nested/Keep.txt',
    ], 'directory refresh observes additions and deletions without discovering new outside files');

    $put('target/tests/Allowed.txt');
    $put('target/tests/vendor/Denied.txt');
    $put('target/excluded/nested/Denied.txt');
    $put('target/vendor/Denied.txt');
    $put('target/node_modules/Denied.txt');
    $put('target/.env/Denied.txt');
    $put('target/secrets.json', '{}');
    $put('generated/Denied.txt');
    mkdir($temporary . '/outside', 0700, true);
    file_put_contents($temporary . '/outside/Secret.txt', "outside\n");
    symlink($temporary . '/outside', $root . '/target/outside-link');
    symlink($root . '/elsewhere', $root . '/target/inside-link');
    $policy = $indexer->indexPaths(['target']);
    $expected = ['elsewhere/Existing.txt', 'target/Added.txt', 'target/nested/Keep.txt', 'target/tests/Allowed.txt'];
    $check($policy['discovered'] === 3 && $paths() === $expected,
        'directory traversal admits explicit test context and excludes secrets, configured exclusions, vendor and symlinks');
    $testDirectory = $indexer->indexPaths(['target/tests']);
    $check($testDirectory['discovered'] === 1 && $paths() === $expected,
        'an explicitly selected tests directory retains the hard exclusions');
    foreach (['target/excluded/nested', 'target/vendor', 'target/.env', 'generated', 'target/inside-link', 'target/outside-link'] as $denied) {
        $result = $indexer->indexPaths([$denied]);
        $check($result['discovered'] === 0 && $paths() === $expected,
            'direct excluded or symlink directory selection stays excluded: ' . $denied);
    }

    unlink($root . '/target/nested/Keep.txt');
    rmdir($root . '/target/nested');
    $removedDirectory = $indexer->indexPaths(['target/nested']);
    $check($removedDirectory['deleted'] === 1 && $paths() === [
        'elsewhere/Existing.txt', 'target/Added.txt', 'target/tests/Allowed.txt',
    ], 'a removed requested subdirectory clears only its old indexed descendants');
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
} finally {
    unset($paths, $indexer, $index);
    gc_collect_cycles();
    $removeTree($temporary);
}

fwrite(STDOUT, json_encode(['checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES) . "\n");
exit($failures === [] ? 0 : 1);
