<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('DEV', false);
define('PROD', true);
require BP . 'app/autoload.php';
require BP . 'app/code/Weline/Framework/Common/functions.php';

use Weline\Database\Service\ModuleRollbackManager;
use Weline\Database\Service\SchemaRollbackService;
use Weline\Framework\Manager\ObjectManager;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

foreach ([ModuleRollbackManager::class, SchemaRollbackService::class] as $class) {
    $check(class_exists($class), 'missing class ' . $class);
    if (!class_exists($class)) {
        continue;
    }
    try {
        $instance = ObjectManager::make($class);
        $check(is_object($instance), $class . ' not constructible');
    } catch (Throwable $exception) {
        $failures[] = $class . ' DI failed: ' . $exception->getMessage();
    }
}

$source = (string)file_get_contents(BP . 'app/code/Weline/Database/Service/ModuleRollbackManager.php');
$check(!str_contains($source, 'MigrationService'), 'ModuleRollbackManager must not depend on deleted MigrationService');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'T47G ROLLBACK-DI PASS' . PHP_EOL;
