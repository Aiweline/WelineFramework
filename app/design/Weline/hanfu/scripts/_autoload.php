<?php

declare(strict_types=1);

/**
 * Autoload website-concept Sample helpers relocated out of Weline_Product.
 * Keep historical namespaces so existing import/cleanup scripts keep working.
 */
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Weline\\Product\\Sample\\Hanfu1688\\' => dirname(__DIR__) . '/Sample/Hanfu1688/',
        'Weline\\Product\\Sample\\HanfuCleanup\\' => dirname(__DIR__) . '/Sample/HanfuCleanup/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }

        return;
    }
});
