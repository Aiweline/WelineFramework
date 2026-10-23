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

$stateClass = 'Weline\\Framework\\Setup\\Kernel\\RegistrationState';
$changeClass = 'Weline\\Framework\\Setup\\Kernel\\ChangeSet';
if (!class_exists($stateClass)) {
    fwrite(STDERR, 'missing RegistrationState' . PHP_EOL);
    exit(1);
}

$previous = [
    'A' => ['version' => '1', 'status' => true, 'fingerprint' => ''],
    'B' => ['version' => '1', 'status' => true, 'fingerprint' => ''],
    'C' => ['version' => '1', 'status' => true, 'fingerprint' => ''],
    'D' => ['version' => '1', 'status' => true, 'fingerprint' => ''],
];
$current = [
    'A' => ['version' => '1', 'status' => true, 'fingerprint' => ''],
    'B' => ['version' => '2', 'status' => true, 'fingerprint' => ''],
    'D' => ['version' => '1', 'status' => false, 'fingerprint' => ''],
    'E' => ['version' => '1', 'status' => true, 'fingerprint' => ''],
];
$cs = new $changeClass($previous, $current, ['F']);

$state = $stateClass::fromChangeSet($cs);
$check($state instanceof $stateClass, 'fromChangeSet must return RegistrationState');
$check($state->dirtyModules() === ['B', 'E', 'F'], 'dirty modules must merge added/updated/pending, got ' . json_encode($state->dirtyModules()));
$check($state->needsFullRefresh() === true, 'removed/disabled must require full refresh');

$state->advance($stateClass::REGISTRY_READY);
$registries = $state->registriesToRefresh();
foreach (['extends', 'plugin', 'event', 'hook', 'route', 'menu'] as $registry) {
    $check(in_array($registry, $registries, true), 'REGISTRY_READY must refresh ' . $registry);
}
$check($state->shouldRefresh('extends') === true, 'shouldRefresh(extends) must be true');

$state->advance($stateClass::INSTALLED);
$check($state->registriesToRefresh() === ['extends'], 'INSTALLED must only refresh extends');

$state->advance($stateClass::DISCOVERED);
$check($state->registriesToRefresh() === [], 'DISCOVERED must not refresh registries');

$emptyCs = new $changeClass($current, $current, []);
$emptyState = $stateClass::fromChangeSet($emptyCs);
$check($emptyState->dirtyModules() === [], 'unchanged modules must have no dirty modules');
$check($emptyState->needsFullRefresh() === false, 'unchanged modules must not need full refresh');
$emptyState->advance($stateClass::REGISTRY_READY);
$check($emptyState->registriesToRefresh() === [], 'unchanged modules must refresh nothing');

$src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Setup/Kernel/RegistrationState.php');
$check(str_contains($src, 'fromChangeSet'), 'RegistrationState must be built from ChangeSet');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
