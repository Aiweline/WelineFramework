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
foreach (['ChangeSet', 'RegistrationState'] as $class) {
    require_once BP . 'app/code/Weline/Framework/Setup/Kernel/' . $class . '.php';
}

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php');
$check(str_contains($src, 'use Weline\\Framework\\Setup\\Kernel\\RegistrationState;'), 'Upgrade must import RegistrationState');
$check(str_contains($src, 'RegistrationState::fromChangeSet'), 'Upgrade must build registration state from ChangeSet');
$check(str_contains($src, 'shouldSkipStep2RegistryRefresh'), 'step2 must skip when nothing changed');
$check((bool) preg_match('/updateModuleRegistriesIncremental\(\$dirty\)/', $src), 'step2 must use incremental refresh for dirty modules');
$check(str_contains($src, 'resolveExtendsRebuildScope'), 'extends rebuild must resolve skip/full/scoped');
$check((bool) preg_match('/rebuildExtendsRegistry\(\$this->parseModuleArgs\(\$args\)\)/', $src), 'extends rebuild must receive module scope');

$ref = new \ReflectionClass(\Weline\Framework\Setup\Console\Setup\Upgrade::class);
$upgrade = $ref->newInstanceWithoutConstructor();
$stateProp = new \ReflectionProperty($upgrade, 'registrationState');
$stateProp->setAccessible(true);

$changeClass = \Weline\Framework\Setup\Kernel\ChangeSet::class;
$stateClass = \Weline\Framework\Setup\Kernel\RegistrationState::class;
$same = ['A' => ['version' => '1', 'status' => true, 'fingerprint' => '']];
$changed = [
    'A' => ['version' => '1', 'status' => true, 'fingerprint' => ''],
    'B' => ['version' => '2', 'status' => true, 'fingerprint' => ''],
];

$skip = new \ReflectionMethod($upgrade, 'shouldSkipStep2RegistryRefresh');
$skip->setAccessible(true);
$scope = new \ReflectionMethod($upgrade, 'resolveExtendsRebuildScope');
$scope->setAccessible(true);

// 无状态 -> 不跳过、全量
$stateProp->setValue($upgrade, null);
$check($skip->invoke($upgrade) === false, 'null state must not skip step2');
$check($scope->invoke($upgrade, [])['mode'] === 'full', 'null state must rebuild extends fully');

// 无变化 -> 跳过、跳过 rebuild
$stateProp->setValue($upgrade, $stateClass::fromChangeSet(new $changeClass($same, $same, [])));
$check($skip->invoke($upgrade) === true, 'unchanged state must skip step2');
$check($scope->invoke($upgrade, [])['mode'] === 'skip', 'unchanged state must skip extends rebuild');

// 有 dirty -> 增量、scoped
$dirtyState = $stateClass::fromChangeSet(new $changeClass($same, $changed, []));
$stateProp->setValue($upgrade, $dirtyState);
$check($skip->invoke($upgrade) === false, 'dirty modules must not skip step2');
$scoped = $scope->invoke($upgrade, []);
$check($scoped['mode'] === 'scoped', 'dirty modules must rebuild scoped extends');
$check($scoped['modules'] === ['B'], 'scoped rebuild must use dirty modules, got ' . json_encode($scoped['modules']));

// 有删除 -> 全量
$removedState = $stateClass::fromChangeSet(new $changeClass($same, [], []));
$stateProp->setValue($upgrade, $removedState);
$check($skip->invoke($upgrade) === false, 'removed modules must not skip step2');
$check($scope->invoke($upgrade, [])['mode'] === 'full', 'removed modules must rebuild fully');

// -m 显式作用域优先
$stateProp->setValue($upgrade, $dirtyState);
$explicit = $scope->invoke($upgrade, ['Weline_X']);
$check($explicit['mode'] === 'scoped' && $explicit['modules'] === ['Weline_X'], 'explicit -m must win');

$stateProp->setValue($upgrade, null);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
