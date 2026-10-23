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

$src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php');

// A. 前置校验方法存在，并在 execute() 内调用
$check(str_contains($src, 'private function validateStageArgument'), 'missing validateStageArgument()');
if (preg_match('/public function execute\(array \$args = \[\], array \$data = \[\]\)\s*\{(.*?)\n    \}/s', $src, $m)) {
    $check(str_contains($m[1], 'validateStageArgument('), 'execute() must call validateStageArgument() before locks/maintenance');
} else {
    $failures[] = 'execute() not found';
}

try {
    $upgrade = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Setup\Console\Setup\Upgrade::class);
    if (!method_exists($upgrade, 'validateStageArgument')) {
        $failures[] = 'missing validateStageArgument()';
        throw new \RuntimeException('missing validateStageArgument');
    }
    $method = new \ReflectionMethod($upgrade, 'validateStageArgument');
    $method->setAccessible(true);
} catch (\Throwable $e) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

$expect = static function (array $args, bool $shouldThrow, string $label) use ($method, $upgrade, &$failures): void {
    try {
        $method->invoke($upgrade, $args);
        if ($shouldThrow) {
            $failures[] = $label . ': expected exception, none thrown';
        }
    } catch (\Throwable $e) {
        if (!$shouldThrow) {
            $failures[] = $label . ': unexpected exception ' . $e->getMessage();
        }
    }
};
$expect(['stage' => true], true, 'stage=true (missing value)');
$expect(['stage' => ''], true, 'stage empty');
$expect(['stage' => 'bogus'], true, 'stage unknown');
$expect(['stage' => 'route_update'], false, 'stage route_update');
$expect(['stage' => 'schema_diff,module_setup'], false, 'stage list');
$expect([], false, 'no stage');

// B. 文件迁移：--stage 不含 schema/module_setup/database_update 时跳过
if (preg_match('/private function runDatabaseFileMigrations\([^)]*\): void\s*\{(.*?)\n    \}/s', $src, $m2)) {
    $body = $m2[1];
    $check(str_contains($body, 'array_intersect'), 'runDatabaseFileMigrations must gate by stage filter');
    $check((bool) preg_match('/array_intersect\([^;]+\)\s*===\s*\[\]\)\s*\{[\s\S]{0,300}return;/', $body), 'stage gate must return early');
}
// 外部 RSSE 重构已将数据库迁移移入 ReleaseDatabaseCoordinator；方法不存在时不作失败。

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
