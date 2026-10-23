<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

// A. 僵尸锁文件：不得 unlink 换 inode，只用 flock 判活
$lockFile = '/tmp/weline-lock-inode/setup_upgrade.lock';
@mkdir(dirname($lockFile), 0775, true);
file_put_contents($lockFile, json_encode(['pid' => 999999, 'time' => date('Y-m-d H:i:s'), 'command' => 'setup:upgrade']));
clearstatcache(true, $lockFile);
$inodeBefore = fileinode($lockFile);

$upgrade = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Setup\Console\Setup\Upgrade::class);
$acquire = new \ReflectionMethod($upgrade, 'acquireLock');
$acquire->setAccessible(true);
$handle = $acquire->invoke($upgrade, $lockFile);
$check(is_resource($handle), 'acquireLock should acquire a stale lock file without blocking');
clearstatcache(true, $lockFile);
$inodeAfter = fileinode($lockFile);
$check($inodeBefore === $inodeAfter, 'acquireLock must not unlink/recreate the lock file (inode changed)');
if (is_resource($handle)) {
    $release = new \ReflectionMethod($upgrade, 'releaseLock');
    $release->setAccessible(true);
    $release->invoke($upgrade, $handle, $lockFile);
}
@unlink($lockFile);
@rmdir(dirname($lockFile));

// B. 路由指纹必须在 route_stage commit 之后才落盘
$src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php');
$commitPos = strpos($src, '$routeStage->commit();');
$persistPos = strpos($src, 'persistRouteFingerprints($toPersist);');
$check($commitPos !== false, 'routeStage->commit() not found');
$check($persistPos !== false, 'persistRouteFingerprints call not found');
if ($commitPos !== false && $persistPos !== false) {
    $check($persistPos > $commitPos, 'route fingerprints must be persisted after routeStage->commit()');
}

// C. 路由临时文件清理方法必须存在且被 commit 调用
$stageSrc = (string) file_get_contents(BP . 'app/code/Weline/Framework/Setup/Stage/RouteUpdateStage.php');
$check(str_contains($stageSrc, 'cleanupStaleRouterTempFiles'), 'RouteUpdateStage missing cleanupStaleRouterTempFiles()');
$check((bool) preg_match('/function commit\([^)]*\)[^{]*\{[\s\S]{0,400}cleanupStaleRouterTempFiles\(\)/', $stageSrc), 'commit() must call cleanupStaleRouterTempFiles() near the top');
$check(str_contains($stageSrc, '.tmp.'), 'cleanupStaleRouterTempFiles must target .tmp.* files');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
