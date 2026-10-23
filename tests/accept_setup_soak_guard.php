<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

use Weline\Framework\Setup\Lock\SoakExclusiveGate;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};
define('SOAK_PATH', SoakExclusiveGate::lockPath());
$backup = is_file(SOAK_PATH) ? (string) file_get_contents(SOAK_PATH) : null;
$restore = static function () use ($backup): void {
    if ($backup === null) {
        @unlink(SOAK_PATH);
    } else {
        @file_put_contents(SOAK_PATH, $backup);
    }
};

// 1. 首次 acquire 成功；同进程再次 acquire 允许续期
$first = SoakExclusiveGate::acquire(['owner' => 'accept']);
$check($first === SOAK_PATH, 'first acquire should return the lock path');
$second = SoakExclusiveGate::acquire(['owner' => 'accept']);
$check($second === SOAK_PATH, 'same-process re-acquire should renew, not throw');
$check(SoakExclusiveGate::isActive() === true, 'lock should be active after acquire');

// 2. 其他进程的 fresh 锁不得被覆盖
file_put_contents(SOAK_PATH, json_encode([
    'schema' => 'wls-soak-exclusive.v1',
    'pid' => 1,
    'started_utc' => gmdate('c'),
    'stale_after_sec' => 3600,
]) . "\n");
touch(SOAK_PATH);
try {
    SoakExclusiveGate::acquire(['owner' => 'accept']);
    $failures[] = 'acquire must refuse to overwrite a fresh lock owned by another pid';
} catch (\Throwable) {
}

// 3. release 不得删除别人的锁
$check(SoakExclusiveGate::release() === false, 'release must refuse to unlink another pid lock');
$check(is_file(SOAK_PATH), 'foreign lock file must stay after refused release');

// 4. inspect 优先使用 payload 的 stale_after_sec
file_put_contents(SOAK_PATH, json_encode([
    'schema' => 'wls-soak-exclusive.v1',
    'pid' => 1,
    'started_utc' => gmdate('c'),
    'stale_after_sec' => 1,
]) . "\n");
touch(SOAK_PATH, time() - 5);
$state = SoakExclusiveGate::inspect(3600);
$check(($state['active'] ?? null) === false, 'inspect must honor payload stale_after_sec over the default argument');
$check(($state['reason'] ?? '') === 'stale', 'payload stale_after_sec should mark the lock stale');

$restore();

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
