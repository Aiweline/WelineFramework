<?php

declare(strict_types=1);

/**
 * Minimal bootstrap for Cart V2 unit tests.
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

if (!\function_exists('w_env_set')) {
    function w_env_set(string $key, mixed $value, string $reason = ''): void
    {
        $GLOBALS['weline_cart_test_env'][$key] = $value;
    }
}

if (!\function_exists('w_env_cookie')) {
    function w_env_cookie(?string $key = null, mixed $default = null): mixed
    {
        $cookies = $GLOBALS['weline_cart_test_env'] ?? [];
        if ($key === null) {
            return $cookies;
        }
        return $cookies['cookie.' . $key] ?? $default;
    }
}

if (!\function_exists('w_env')) {
    function w_env(?string $key = null, mixed $default = null): mixed
    {
        return $default;
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
        'Weline\\Cart\\' => $codeRoot . '/Cart/',
        'Weline\\Product\\' => $codeRoot . '/Product/',
        'Weline\\Framework\\' => $codeRoot . '/Framework/',
    ];
    foreach ($map as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $candidates = [
            $base . $relative . '.php',
            // Extends path on disk is often lowercase `extends/`
            $base . str_replace('Extends/', 'extends/', $relative) . '.php',
        ];
        foreach ($candidates as $file) {
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    }
});
