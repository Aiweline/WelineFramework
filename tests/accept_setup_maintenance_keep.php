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

try {
    $observerClass = \Weline\Server\Observer\CliCommandExecutedObserver::class;
    $observer = new $observerClass();
    if (!method_exists($observer, 'shouldSkipMaintenanceReconcile')) {
        $failures[] = 'observer missing shouldSkipMaintenanceReconcile';
    } else {
        $method = new \ReflectionMethod($observer, 'shouldSkipMaintenanceReconcile');
        $method->setAccessible(true);
        $check($method->invoke($observer, []) === false, 'empty args must not skip reconcile');
        $check($method->invoke($observer, ['keep-maintenance' => true]) === true, 'keep-maintenance must skip reconcile');
        $check($method->invoke($observer, ['--keep-maintenance' => true]) === true, 'prefixed keep-maintenance must skip reconcile');
        $check($method->invoke($observer, ['command' => 'setup:upgrade']) === false, 'unrelated args must not skip reconcile');
    }
} catch (\Throwable $throwable) {
    $failures[] = 'observer check failed: ' . $throwable->getMessage();
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
