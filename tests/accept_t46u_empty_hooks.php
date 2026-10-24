<?php

declare(strict_types=1);

/**
 * T46u 验收：仅删除真正 no-op 的 Setup 钩子。
 * - 6 个已确认 no-op 文件不存在；
 * - 5 个含 seed/继承/读取/输出的候选保留，并有行为证据；
 * - 剩余全部 Setup 钩子 php -l 通过；
 * - 被删 FQCN/路径在 app/code + app/etc（排除 Test/test）无引用。
 */

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);

$deleted = [
    'app/code/Weline/ModuleManager/Setup/Upgrade.php',
    'app/code/Weline/Theme/Setup/Install.php',
    'app/code/Weline/Marketing/Setup/Install.php',
    'app/code/Weline/Meta/Setup/Install.php',
    'app/code/Weline/Deploy/Setup/Install.php',
    'app/code/Weline/PlatformAppStore/Setup/Install.php',
];

$kept = [
    'app/code/Weline/Bt/Center/Setup/Install.php' => 'extends \\Weline\\Bt_Center\\Setup\\Install',
    'app/code/Weline/Bt/Center/Setup/Upgrade.php' => 'extends \\Weline\\Bt_Center\\Setup\\Upgrade',
    'app/code/Weline/Checkout/Setup/Install.php' => '$setup->getDb()',
    'app/code/Weline/Multipass/Setup/Install.php' => '$context->getPrinter()',
    'app/code/Weline/Database/Setup/Install.php' => '->tableExist()',
];

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

foreach ($deleted as $relative) {
    $check(!file_exists(BP . $relative), 'no-op hook still exists: ' . $relative);
}

foreach ($kept as $relative => $marker) {
    $path = BP . $relative;
    $check(is_file($path), 'kept hook missing: ' . $relative);
    if (!is_file($path)) {
        continue;
    }
    $source = (string) file_get_contents($path);
    $check(str_contains($source, $marker), 'kept hook lost behavior marker (' . $marker . '): ' . $relative);
}

$hooks = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BP . 'app/code/Weline', FilesystemIterator::SKIP_DOTS)) as $file) {
    $path = $file->getPathname();
    if (!preg_match('#/Setup/(Install|Upgrade)\.php$#', $path) || str_contains($path, '/Console/')) {
        continue;
    }
    $hooks[] = $path;
}
sort($hooks);
$check(count($hooks) >= 70, 'unexpected hook count after cleanup: ' . count($hooks));

foreach ($hooks as $hook) {
    $output = [];
    $exit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($hook) . ' 2>&1', $output, $exit);
    $text = implode(PHP_EOL, $output);
    if ($exit !== 0 || str_contains($text, 'Errors parsing')) {
        $check(false, 'php -l failed: ' . str_replace(BP, '', $hook) . PHP_EOL . $text);
    }
}

$needles = [
    'Weline\\ModuleManager\\Setup\\Upgrade',
    'Weline\\Theme\\Setup\\Install',
    'Weline\\Marketing\\Setup\\Install',
    'Weline\\Meta\\Setup\\Install',
    'Weline\\Deploy\\Setup\\Install',
    'Weline\\PlatformAppStore\\Setup\\Install',
    'ModuleManager/Setup/Upgrade.php',
    'Theme/Setup/Install.php',
    'Marketing/Setup/Install.php',
    'Meta/Setup/Install.php',
    'Deploy/Setup/Install.php',
    'PlatformAppStore/Setup/Install.php',
];

$rg = trim((string) shell_exec('command -v rg 2>/dev/null'));
if ($rg !== '') {
    $pattern = implode('|', array_map(static fn(string $needle): string => preg_quote($needle, '/'), $needles));
    $lines = [];
    $exit = 0;
    exec(
        'rg -n --glob ' . escapeshellarg('!**/Test/**')
        . ' --glob ' . escapeshellarg('!**/test/**')
        . ' -- ' . escapeshellarg($pattern)
        . ' ' . escapeshellarg(BP . 'app/code')
        . ' ' . escapeshellarg(BP . 'app/etc')
        . ' 2>/dev/null',
        $lines,
        $exit
    );
    $check($exit === 1 || $exit === 0, 'reference scan failed with exit ' . $exit);
    $check($lines === [], 'deleted hook still referenced:' . PHP_EOL . implode(PHP_EOL, array_slice($lines, 0, 10)));
} else {
    // 无 rg 时退化为 6 个模块目录 + app/etc 的 PHP 源码扫描。
    $scanDirs = [
        BP . 'app/code/Weline/ModuleManager',
        BP . 'app/code/Weline/Theme',
        BP . 'app/code/Weline/Marketing',
        BP . 'app/code/Weline/Meta',
        BP . 'app/code/Weline/Deploy',
        BP . 'app/code/Weline/PlatformAppStore',
        BP . 'app/etc',
    ];
    foreach ($scanDirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();
            if (!$file->isFile() || !str_ends_with($path, '.php') || str_contains($path, '/Test/') || str_contains($path, '/test/')) {
                continue;
            }
            $source = (string) file_get_contents($path);
            foreach ($needles as $needle) {
                if (str_contains($source, $needle)) {
                    $check(false, 'deleted hook still referenced: ' . $needle . ' in ' . str_replace(BP, '', $path));
                }
            }
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'deleted=6; kept=' . count($kept) . '; remaining_hooks=' . count($hooks), PHP_EOL;
echo 'T46U EMPTY-HOOKS PASS', PHP_EOL;
