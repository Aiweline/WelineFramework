<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

$upgrade = \Weline\Framework\Manager\ObjectManager::getInstance(
    \Weline\Framework\Setup\Console\Setup\Upgrade::class
);
$method = new \ReflectionMethod($upgrade, 'parseModuleArgs');
$method->setAccessible(true);

$cases = [
    'module=true' => [['module' => true], []],
    'm=true' => [['m' => true], []],
    'module=empty' => [['module' => ''], []],
    'module=string' => [['module' => 'Weline_A Weline_B'], ['Weline_A', 'Weline_B']],
    'positional' => [[1 => 'Weline_A'], ['Weline_A']],
];

$failures = [];
foreach ($cases as $name => [$args, $expected]) {
    try {
        $actual = $method->invoke($upgrade, $args);
    } catch (\Throwable $e) {
        $failures[] = $name . ': threw ' . get_class($e) . ' ' . $e->getMessage();
        continue;
    }
    if ($actual !== $expected) {
        $failures[] = $name . ': expected ' . json_encode($expected) . ' got ' . json_encode($actual);
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
