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

$class = \Weline\Framework\Register\Register::class;
if (!method_exists($class, 'pendingRegistrationModules')) {
    fwrite(STDERR, 'missing pendingRegistrationModules()' . PHP_EOL);
    exit(1);
}

$prop = new \ReflectionProperty($class, 'pendingRegistrations');
$prop->setAccessible(true);
$original = $prop->getValue();
$method = new \ReflectionMethod($class, 'pendingRegistrationModules');
$method->setAccessible(true);

try {
    $prop->setValue(null, [
        ['event', 'Weline_A', ['x']],
        ['hook', 'Weline_A', ['y']],
        ['theme', 'Weline_B', []],
        ['event', '', []],
    ]);
    $modules = $method->invoke(null);
    $check($modules === ['Weline_A', 'Weline_B'], 'pending modules must be unique/sorted/non-empty, got ' . json_encode($modules));

    $prop->setValue(null, []);
    $check($method->invoke(null) === [], 'empty pending must return empty array');
} finally {
    $prop->setValue(null, $original);
}

$src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Register/Register.php');
$check(str_contains($src, 'pendingRegistrationModules'), 'Register must expose pendingRegistrationModules()');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
