<?php

declare(strict_types=1);

/**
 * Minimal bootstrap for Weline_Inventory unit tests.
 */
if (!\function_exists('__')) {
    function __(string $text, array $params = []): string
    {
        $out = $text;
        foreach ($params as $i => $value) {
            $out = str_replace('%{' . ($i + 1) . '}', (string)$value, $out);
        }
        return $out;
    }
}

if (!\defined('BP')) {
    \define('BP', \dirname(__DIR__, 6) . DIRECTORY_SEPARATOR);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}

$autoload = \dirname(__DIR__, 6) . '/vendor/autoload.php';
if (\is_file($autoload)) {
    require_once $autoload;
}

$codeRoot = \dirname(__DIR__, 3); // app/code/Weline

spl_autoload_register(static function (string $class) use ($codeRoot): void {
    $map = [
        'Weline\\Inventory\\' => $codeRoot . '/Inventory/',
        'Weline\\Framework\\' => $codeRoot . '/Framework/',
        'Weline\\SystemConfig\\' => $codeRoot . '/SystemConfig/',
    ];
    foreach ($map as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $base . $relative . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
