<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('VAR_DIR', '/tmp/weline-gate-guard/var/');
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

$peer = new \ReflectionMethod(\Weline\Framework\Console\EarlyDatabaseAccessGate::class, 'isSameProjectGatePeer');
$peer->setAccessible(true);
$check($peer->invoke(null, 'php bin/w cron:task:run --process') === true, 'cron:task:run must stay signal-able');
$check($peer->invoke(null, 'php bin/w setup:upgrade') === false, 'setup:upgrade must NOT be signal-able by another -f');
$check($peer->invoke(null, 'php bin/w s:up -f') === false, 's:up must NOT be signal-able by another -f');

$lockPath = VAR_DIR . 'process/setup_database_access.lock';
file_put_contents($lockPath, '');
$list = new \ReflectionMethod(\Weline\Framework\Console\EarlyDatabaseAccessGate::class, 'listLockHolderPids');
$list->setAccessible(true);
$result = $list->invoke(null, $lockPath);
$check(is_array($result) && ($result['pids'] ?? null) === [], 'empty lsof output must mean no holders');
$check(($result['failure'] ?? null) === '', 'empty lsof output must not be reported as holders_invisible');

@unlink($lockPath);
@rmdir(VAR_DIR . 'process');
@rmdir(VAR_DIR);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
