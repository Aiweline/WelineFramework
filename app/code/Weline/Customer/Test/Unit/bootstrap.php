<?php

declare(strict_types=1);

/**
 * Prefer app/code module sources over vendored copies for Customer unit tests.
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
    \define('BP', \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}

$customerRoot = \dirname(__DIR__, 2);
$welineRoot = \dirname($customerRoot);
spl_autoload_register(static function (string $class) use ($customerRoot, $welineRoot): void {
    $prefixes = [
        'Weline\\Customer\\' => $customerRoot . '/',
        'Weline\\Checkout\\' => $welineRoot . '/Checkout/',
        'Weline\\Order\\' => $welineRoot . '/Order/',
    ];
    foreach ($prefixes as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        $path = $base . $relative;
        if (is_file($path)) {
            require_once $path;

            return;
        }
    }
}, true, true);

$autoload = BP . 'vendor/autoload.php';
if (\is_file($autoload)) {
    require_once $autoload;
}
