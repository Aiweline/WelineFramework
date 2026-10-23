<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('VAR_DIR', '/tmp/weline-intent-corrupt/var/');
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
$reset = static function () use ($path): void {
    @unlink($path);
    foreach (glob($path . '.stale.*') ?: [] as $stale) {
        @unlink($stale);
    }
};

// 1. 新鲜 0 字节 payload：publish 必须能回收并重建
$reset();
file_put_contents($path, '');
$ok = SetupUpgradeIntent::publish('setup:upgrade');
$check($ok === true, 'fresh corrupt payload: publish() should recover and return true');
$decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
$check(is_array($decoded) && (int) ($decoded['pid'] ?? 0) === (int) getmypid(), 'fresh corrupt payload: file should contain current pid json');
$check(SetupUpgradeIntent::isActive() === true, 'fresh corrupt payload: after publish, isActive() should be true');

// 2. 过期 0 字节 payload：isActive 必须回收
$reset();
file_put_contents($path, '');
touch($path, time() - 7200);
$check(SetupUpgradeIntent::isActive() === false, 'stale corrupt payload: isActive() should be false');
$check(!is_file($path), 'stale corrupt payload: main intent file should be reclaimed');

// 3. 新鲜坏 payload：isActive 不得把当前进程当 owner，publish 仍能恢复
$reset();
file_put_contents($path, '');
$check(SetupUpgradeIntent::isActive() === false, 'fresh corrupt payload: isActive() should be false');
$check(SetupUpgradeIntent::publish('setup:upgrade') === true, 'fresh corrupt payload: publish() should still recover');

// 4. 合法 payload：不应被回收
$reset();
$check(SetupUpgradeIntent::publish('setup:upgrade') === true, 'valid publish should succeed');
$check(SetupUpgradeIntent::isActive() === true, 'valid payload: isActive() should be true');
$check(is_file($path), 'valid payload: file should stay');

// 5. 残留 stale 文件应被清理（只保留最近 1 小时）
$stale = $path . '.stale.1.1';
file_put_contents($stale, 'x');
touch($stale, time() - 7200);
SetupUpgradeIntent::isActive();
$check(!is_file($stale), 'stale sibling older than 1h should be cleaned');

$reset();
@rmdir(VAR_DIR . 'process');
@rmdir(VAR_DIR);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
