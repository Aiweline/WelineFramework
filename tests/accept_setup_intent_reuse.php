<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('VAR_DIR', '/tmp/weline-intent-reuse/var/');
@mkdir(VAR_DIR . 'process', 0775, true);
require BP . 'app/code/Weline/Framework/Setup/Lock/SetupUpgradeIntent.php';

use Weline\Framework\Setup\Lock\SetupUpgradeIntent;

$path = VAR_DIR . 'process/' . SetupUpgradeIntent::FILENAME;
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};
$write = static function (int $pid, float $startedAt) use ($path): void {
    file_put_contents($path, json_encode([
        'pid' => $pid,
        'started_at' => $startedAt,
        'command' => 'setup:upgrade',
    ], JSON_UNESCAPED_UNICODE) . "\n");
};

// 1. 当前进程 + 当前时间：活着
@unlink($path);
$write((int) getmypid(), microtime(true));
$check(SetupUpgradeIntent::isActive() === true, 'current pid with fresh started_at should be active');

// 2. 当前进程 + 1 天前：PID 复用，必须判死并回收
@unlink($path);
$write((int) getmypid(), microtime(true) - 86400);
$check(SetupUpgradeIntent::isActive() === false, 'current pid with 1-day-old started_at must be treated as reused/dead');
$check(!is_file($path), 'reused pid: intent file should be reclaimed');

// 3. 当前进程 + 未来 60s（时钟偏差）：仍算活着
@unlink($path);
$write((int) getmypid(), microtime(true) + 60);
$check(SetupUpgradeIntent::isActive() === true, 'clock skew within tolerance should still be active');

// 4. 不存在的 pid：仍判死
@unlink($path);
$write(999999, microtime(true));
$check(SetupUpgradeIntent::isActive() === false, 'missing pid should be inactive');
$check(!is_file($path), 'missing pid: intent file should be reclaimed');

@unlink($path);
@rmdir(VAR_DIR . 'process');
@rmdir(VAR_DIR);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
