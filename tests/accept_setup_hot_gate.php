<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('VAR_DIR', '/tmp/weline-hot-gate/var/');
@mkdir(VAR_DIR . 'process', 0775, true);
require BP . 'app/code/Weline/Framework/Setup/Lock/SetupDatabaseAccessLock.php';
require BP . 'app/code/Weline/Framework/Setup/Lock/SetupUpgradeIntent.php';
require BP . 'app/code/Weline/Framework/Phrase/DatabaseFreeTranslator.php';
require BP . 'app/code/Weline/Framework/Console/EarlyDatabaseAccessGate.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$lease = new \Weline\Framework\Setup\Lock\SetupDatabaseAccessLock();
$check($lease->acquireExclusive() === true, 'test could not acquire EX lease');

ob_start();
$hotCode = \Weline\Framework\Console\EarlyDatabaseAccessGate::prepare(['bin/w', 'setup:upgrade', '--hot']);
$hotOutput = (string) ob_get_clean();
$check($hotCode === 75, 'hot with EX held must return 75, got ' . var_export($hotCode, true));
$check(str_contains($hotOutput, '热更新'), 'hot blocked message must mention 热更新; got: ' . trim($hotOutput));
$check(!str_contains($hotOutput, '计划任务'), 'hot must not reuse cron skip message');

ob_start();
$helpCode = \Weline\Framework\Console\EarlyDatabaseAccessGate::prepare(['bin/w', 'setup:upgrade', '--help']);
$helpOutput = (string) ob_get_clean();
$check($helpCode === 0, 'help with EX held must still return 0, got ' . var_export($helpCode, true));
$check(!str_contains($helpOutput, '热更新被系统升级占用'), 'help must not use hot blocked message');

\Weline\Framework\Setup\Lock\SetupDatabaseAccessLock::releaseCliBootstrapLease();
@rmdir(VAR_DIR . 'process');
@rmdir(VAR_DIR);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
