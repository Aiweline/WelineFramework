<?php

declare(strict_types=1);

/**
 * setup:upgrade 性能基准：并发检测 + 阶段/observer Top。
 *
 * 用法：php tests/bench_setup_upgrade.php [--runs=2] [--dry-run]
 * 只在无人并发跑 setup:upgrade 时测量，否则提示 measurement invalid。
 */

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);

$runs = 1;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--runs=')) {
        $runs = max(1, min(5, (int)substr($arg, 7)));
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}

$self = getmypid();
$concurrent = [];
exec('pgrep -f ' . escapeshellarg('bin/w setup:upgrade'), $pids, $code);
foreach ($pids as $pid) {
    $pid = (int)trim($pid);
    if ($pid > 0 && $pid !== $self) {
        $concurrent[] = $pid;
    }
}
if ($concurrent !== []) {
    fwrite(STDERR, 'measurement invalid: concurrent setup:upgrade pids=' . implode(',', $concurrent) . PHP_EOL);
    exit(2);
}

if ($dryRun) {
    echo 'BENCH READY', PHP_EOL;
    exit(0);
}

$profileLog = BP . 'var/log/setup_profile.log';
$command = 'WELINE_SETUP_PROFILE=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BP . 'bin/w') . ' setup:upgrade -f 2>&1';
$results = [];
for ($i = 1; $i <= $runs; $i++) {
    @unlink($profileLog);
    $start = microtime(true);
    $output = [];
    $exit = 0;
    exec($command, $output, $exit);
    $wall = microtime(true) - $start;
    $text = implode("\n", $output);
    $total = '';
    if (preg_match('/总墙钟：([^\s]+)/u', $text, $m)) {
        $total = $m[1];
    }
    $observers = [];
    if (is_file($profileLog)) {
        foreach (file($profileLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $parts = explode("\t", $line);
            if (($parts[0] ?? '') === 'observer' && isset($parts[1], $parts[2])) {
                $observers[$parts[1]] = (int)round((float)rtrim($parts[2], 'ms'));
            }
        }
        arsort($observers);
    }
    $results[] = ['run' => $i, 'exit' => $exit, 'wall' => $wall, 'total' => $total, 'observers' => array_slice($observers, 0, 5, true)];
}

foreach ($results as $row) {
    printf(
        "run=%d exit=%d wall=%.1fs total=%s top=%s\n",
        $row['run'],
        $row['exit'],
        $row['wall'],
        $row['total'] !== '' ? $row['total'] : 'n/a',
        json_encode($row['observers'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}
echo 'BENCH DONE', PHP_EOL;
