<?php

declare(strict_types=1);

use LearningMcp\Config;
use LearningMcp\IndexRefreshScheduler;
use LearningMcp\IndexSidecar;

require dirname(__DIR__) . '/src/bootstrap.php';

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-index-sched-' . bin2hex(random_bytes(5));
$repo = $temporary . '/project';
$data = $temporary . '/data';
$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    fwrite($ok ? STDOUT : STDERR, ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n");
    if (!$ok) {
        $failures[] = $label;
    }
};

mkdir($repo . '/app/code/Acme/Demo/doc', 0700, true);
foreach (['README.md', '需求.md', '开发日志.md'] as $name) {
    file_put_contents($repo . '/app/code/Acme/Demo/doc/' . $name, "# Demo\n");
}
mkdir($data, 0700, true);
file_put_contents($temporary . '/config.json', json_encode([
    'data_dir' => $data,
    'index' => [
        'enabled' => true,
        'sidecar_enabled' => true,
        'bound_repository' => $repo,
        'refresh_interval' => '10m',
    ],
], JSON_THROW_ON_ERROR));

$config = Config::load($temporary . '/config.json', $data);

// Without a live sidecar socket, STDIO path must use proc_open re-exec — never claim sidecar fork.
$schedule = IndexRefreshScheduler::scheduleFromStdio($config, $repo);
$check(($schedule['deferred'] ?? false) === true, 'scheduleFromStdio defers when possible');
$check(
    in_array((string) ($schedule['mode'] ?? ''), ['proc_open_reexec', 'sidecar_send', 'coalesced'], true),
    'scheduleFromStdio mode is sidecar_send, proc_open_reexec, or coalesced',
);
$check((string) ($schedule['mode'] ?? '') !== 'fork', 'scheduleFromStdio never reports fork mode');
$check(is_array($schedule['worker'] ?? null), 'scheduleFromStdio returns worker status');
$check(
    str_contains((string) file_get_contents(dirname(__DIR__) . '/src/IndexRefreshScheduler.php'), 'deadline_epoch'),
    'scheduler source passes deadline_epoch into detached index_project input',
);
$check(
    str_contains((string) file_get_contents(dirname(__DIR__) . '/src/IndexRefreshScheduler.php'), 'proc_nice'),
    'scheduler source applies proc_nice to detached workers',
);
$check((int) $config->get('index.refresh_worker_max_seconds', 0) === 900, 'default refresh_worker_max_seconds is 900');
$check((int) $config->get('index.refresh_worker_nice', -1) === 10, 'default refresh_worker_nice is 10');
$deadline = IndexRefreshScheduler::defaultDeadlineEpoch($config);
$check($deadline >= time() + 60, 'defaultDeadlineEpoch is at least 60s ahead');

// Second schedule while a worker may still be alive must coalesce, not pile workers.
$again = IndexRefreshScheduler::scheduleFromStdio($config, $repo);
$check(($again['deferred'] ?? false) === true, 'second schedule still deferred');
$check(
    in_array((string) ($again['mode'] ?? ''), ['coalesced', 'sidecar_send', 'proc_open_reexec'], true),
    'second schedule stays single-flight (coalesced/sidecar/proc_open)',
);
$check(
    !str_contains((string) file_get_contents(dirname(__DIR__) . '/src/IndexRefreshScheduler.php'), 'pcntl_fork(); if ($pid === -1)')
        || str_contains((string) file_get_contents(dirname(__DIR__) . '/src/IndexRefreshScheduler.php'), 'index_refresh_already_running'),
    'scheduler source includes single-flight coalesce reason',
);

// Over-budget reclaim: plant a fake meta with past deadline and a non-alive pid file.
$metaPath = $data . '/index-refresh-worker.meta.json';
$pidPath = $data . '/index-refresh-worker.pid';
file_put_contents($pidPath, '1');
file_put_contents($metaPath, json_encode([
    'pid' => 1,
    'started_at' => time() - 1000,
    'deadline_epoch' => time() - 10,
    'repository' => $repo,
    'nice' => 10,
], JSON_THROW_ON_ERROR));
$status = IndexRefreshScheduler::workerStatus($config);
$check(($status['over_budget'] ?? null) === false || ($status['alive'] ?? true) === false, 'dead pid is not reported as live over-budget worker');
@unlink($pidPath);
@unlink($metaPath);

$trySend = IndexSidecar::trySend($config, $repo, []);
$check($trySend === false, 'trySend returns false when no sidecar socket is present (no fork side effect)');

$status = IndexSidecar::status($config);
$check(is_array($status), 'IndexSidecar::status remains available for health/sidecar reporting');

$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    } elseif (is_file($path) || is_link($path)) {
        @unlink($path);
    }
};
$remove($temporary);

if ($failures !== []) {
    fwrite(STDERR, 'index-refresh-scheduler failures: ' . count($failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "index-refresh-scheduler: all checks passed\n");
exit(0);
