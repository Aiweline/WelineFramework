<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Weline\\')) {
        return;
    }
    $path = BP . 'app/code/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$commands = [
    'Weline\\Database\\Console\\Release\\Plan' => 'release:plan',
    'Weline\\Database\\Console\\Release\\Apply' => 'release:apply',
    'Weline\\Database\\Console\\Release\\Rollback' => 'release:rollback',
];
foreach ($commands as $class => $name) {
    $check(class_exists($class), 'missing class ' . $class);
    if (!class_exists($class)) {
        continue;
    }
    $reflection = new ReflectionClass($class);
    $check($reflection->implementsInterface(\Weline\Framework\Console\CommandInterface::class), $class . ' must implement CommandInterface');
    foreach (['execute', 'tip', 'help'] as $method) {
        $check($reflection->hasMethod($method), $class . ' missing ' . $method);
    }
    $file = $reflection->getFileName();
    $check(is_string($file) && str_contains($file, '/Console/Release/'), $class . ' file location mismatch');
    $check(str_contains(strtolower($file ?: ''), strtolower($reflection->getShortName() . '.php')), $class . ' file name mismatch');
}

$planFile = BP . 'app/code/Weline/Database/Console/Release/Plan.php';
$applyFile = BP . 'app/code/Weline/Database/Console/Release/Apply.php';
$rollbackFile = BP . 'app/code/Weline/Database/Console/Release/Rollback.php';
foreach ([$planFile, $applyFile, $rollbackFile] as $file) {
    $source = is_file($file) ? (string)file_get_contents($file) : '';
    $check(str_contains($source, 'DatabaseReleaseCoordinator'), basename($file) . ' must use DatabaseReleaseCoordinator');
    $check(!str_contains($source, 'MigrationService') && !str_contains($source, 'MigrationBackup'), basename($file) . ' must not reference legacy migration domain');
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'T45E CLI PASS' . PHP_EOL;
