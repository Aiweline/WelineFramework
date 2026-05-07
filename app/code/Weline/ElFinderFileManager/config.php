<?php

declare(strict_types=1);

namespace Weline\ElFinderFileManager;

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

if (!defined('BP')) {
    define('BP', dirname(__DIR__, 4) . DS);
}

if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', BP . 'vendor' . DS);
}

if (!function_exists(__NAMESPACE__ . '\\syncElFinderDirectory')) {
    function syncElFinderDirectory(string $source, string $destination): void
    {
        $source = rtrim($source, DS);
        $destination = rtrim($destination, DS);
        if (!is_dir($source)) {
            return;
        }
        if (!is_dir($destination)) {
            @mkdir($destination, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $targetPath = $destination . DS . $relativePath;
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    @mkdir($targetPath, 0755, true);
                }
                continue;
            }

            @copy($item->getPathname(), $targetPath);
        }
    }
}

$targetStaticPath = __DIR__ . DS . 'view' . DS . 'statics';
if (!is_dir($targetStaticPath)) {
    @mkdir($targetStaticPath, 0755, true);
}

$vendorPath = VENDOR_PATH . 'studio-42' . DS . 'elfinder';
if (is_dir($vendorPath)) {
    syncElFinderDirectory($vendorPath, $targetStaticPath);
}

return is_dir($targetStaticPath);
