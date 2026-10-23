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

$class = 'Weline\\Framework\\Setup\\Kernel\\UpgradeSession';
if (!class_exists($class)) {
    fwrite(STDERR, 'missing UpgradeSession' . PHP_EOL);
    exit(1);
}

$root = '/tmp/weline-kernel-session-' . bin2hex(random_bytes(4));

try {
    // 1. 正常完成
    $session = new $class('op-ok', $root, false);
    $session->begin();
    $session->recordStage('discover', 'ok', 12.5, ['added' => 1]);
    $session->recordSkip('schema', 'no module change');
    $check($session->closeout(true) === 0, 'successful session must exit 0');
    $progress = $session->progressPath();
    $report = $session->reportPath();
    $check(is_file($progress), 'progress.json must be written');
    $check(is_file($report), 'report.json must be written');
    $data = json_decode((string) file_get_contents($report), true);
    $check(is_array($data), 'report.json must be valid json');
    $check(($data['status'] ?? '') === 'completed', 'report status must be completed');
    $check(($data['stages'][0]['key'] ?? '') === 'discover', 'report must keep stage key');
    $check(($data['skips'][0]['reason'] ?? '') === 'no module change', 'report must keep skip reason');

    // 2. fatal -> 非零
    $s2 = new $class('op-fatal', $root, false);
    $s2->begin();
    $s2->recordStage('schema', 'failed', 1.0);
    $s2->fatal('ddl failed');
    $check($s2->hasFatal() === true, 'hasFatal must be true after fatal()');
    $check($s2->closeout(true) === 1, 'fatal session must exit 1');
    $d2 = json_decode((string) file_get_contents($s2->reportPath()), true);
    $check(($d2['status'] ?? '') === 'failed', 'fatal report status must be failed');

    // 3. 维护未关闭 -> 非零
    $s3 = new $class('op-maint', $root, false);
    $s3->begin();
    $check($s3->closeout(false) === 1, 'unclosed maintenance must exit 1');

    // 4. keep-maintenance 由 session 记录
    $s4 = new $class('op-keep', $root, true);
    $s4->begin();
    $check($s4->keepMaintenance() === true, 'keepMaintenance flag must be preserved');
    $check($s4->closeout(true) === 0, 'keep-maintenance session with manual close must exit 0');
    $d4 = json_decode((string) file_get_contents($s4->reportPath()), true);
    $check(($d4['keep_maintenance'] ?? false) === true, 'report must record keep_maintenance');
} catch (\Throwable $e) {
    $failures[] = 'session check failed: ' . $e->getMessage();
} finally {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($root);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
