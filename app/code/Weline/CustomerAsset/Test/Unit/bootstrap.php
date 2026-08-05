<?php

declare(strict_types=1);

if (!\function_exists('__')) {
    function __(string $text, array $params = []): string
    {
        $out = $text;
        foreach ($params as $i => $value) {
            $out = str_replace('%{' . ($i + 1) . '}', (string) $value, $out);
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
if (!\defined('APP_PATH')) {
    \define('APP_PATH', BP . 'app' . DS);
}
if (!\defined('APP_ETC_PATH')) {
    \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
}
if (!\defined('APP_CODE_PATH')) {
    \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
}
if (!\defined('PUB')) {
    \define('PUB', BP . 'pub' . DS);
}
if (!\defined('VENDOR_PATH')) {
    \define('VENDOR_PATH', BP . 'vendor' . DS);
}
if (!\defined('DEBUG')) {
    \define('DEBUG', false);
}
if (!\defined('CLI')) {
    \define('CLI', true);
}
if (!\defined('SANDBOX')) {
    \define('SANDBOX', false);
}
if (!\defined('DEV')) {
    \define('DEV', true);
}
if (!\defined('PROD')) {
    \define('PROD', false);
}

$autoload = \dirname(__DIR__, 6) . '/vendor/autoload.php';
if (\is_file($autoload)) {
    require_once $autoload;
}
$frameworkFunctions = APP_CODE_PATH . 'Weline/Framework/Common/functions.php';
if (\is_file($frameworkFunctions)) {
    require_once $frameworkFunctions;
}

$codeRoot = \dirname(__DIR__, 3);

spl_autoload_register(static function (string $class) use ($codeRoot): void {
    $map = [
        'Weline\\CustomerAsset\\' => $codeRoot . '/CustomerAsset/',
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
