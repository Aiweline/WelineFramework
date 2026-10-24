<?php

declare(strict_types=1);

// Isolate DEV/PROD/WLS_MODE constants per deploy mode; never touches generated/.
$mode = (string)($argv[1] ?? 'unset');
$framework = dirname(__DIR__, 4);

define('DS', DIRECTORY_SEPARATOR);
define('BP', dirname($framework, 4) . DS);
if ($mode === 'wls') {
    define('WLS_MODE', true);
}
if ($mode !== 'missing_constants') {
    define('DEV', $mode === 'dev');
    define('PROD', $mode === 'prod');
}

spl_autoload_register(static function (string $class) use ($framework): void {
    $prefix = 'Weline\\Framework\\';
    if (str_starts_with($class, $prefix)) {
        $file = $framework . DS . str_replace('\\', DS, substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$missing = sys_get_temp_dir() . DS . 'weline-missing-container-' . getmypid() . DS . 'container.php';
try {
    new \Weline\Framework\Container\CompiledContainer($missing);
    echo 'fallback';
} catch (\Weline\Framework\Container\ContainerException $e) {
    echo 'strict';
}
