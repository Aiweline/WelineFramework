<?php

declare(strict_types=1);

if (!defined('BP')) {
    define('BP', dirname(__DIR__, 6) . DIRECTORY_SEPARATOR);
}
if (!defined('PUB')) {
    define('PUB', BP . 'pub' . DIRECTORY_SEPARATOR);
}

$autoload = BP . 'vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

spl_autoload_register(static function (string $class): void {
    $roots = [
        'Weline\\Feed\\' => dirname(__DIR__, 2),
        'Weline\\Product\\' => dirname(__DIR__, 3) . '/Product',
    ];
    foreach ($roots as $prefix => $root) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $root . '/' . $relative . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
