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
require_once BP . 'app/code/Weline/Framework/Setup/Kernel/ChangeSet.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php');
$check(str_contains($src, 'use Weline\\Framework\\Setup\\Kernel\\ChangeSet;'), 'Upgrade must import ChangeSet');
$check(str_contains($src, 'recordStage(\'discover\''), 'Upgrade must record the discover stage');
$check(str_contains($src, 'state.json'), 'Upgrade must read/write var/setup/state.json');
$check(str_contains($src, 'snapshotModuleState('), 'Upgrade must snapshot module state');

$ref = new \ReflectionClass(\Weline\Framework\Setup\Console\Setup\Upgrade::class);
$upgrade = $ref->newInstanceWithoutConstructor();
if (!method_exists($upgrade, 'buildChangeSet')) {
    fwrite(STDERR, 'missing buildChangeSet()' . PHP_EOL);
    exit(1);
}
$method = new \ReflectionMethod($upgrade, 'buildChangeSet');
$method->setAccessible(true);

$previous = ['A' => ['version' => '1.0.0', 'status' => true, 'fingerprint' => '']];
$current = [
    'A' => ['version' => '1.0.0', 'status' => true, 'fingerprint' => ''],
    'B' => ['version' => '2.0.0', 'status' => true, 'fingerprint' => ''],
];
$cs = $method->invoke($upgrade, $previous, $current);
$check($cs instanceof \Weline\Framework\Setup\Kernel\ChangeSet, 'buildChangeSet must return ChangeSet');
$check($cs->modules()['added'] === ['B'], 'buildChangeSet must mark B as added');
$check($cs->modules()['unchanged'] === ['A'], 'buildChangeSet must keep A unchanged');
$check($cs->needs()['schema'] === true, 'module add must need schema');

$cs2 = $method->invoke($upgrade, $current, $current);
$check($cs2->isEmpty() === true, 'identical snapshots must be empty');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
