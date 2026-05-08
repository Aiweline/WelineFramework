<?php

namespace Weline\ElFinderFileManager;

$app_boostrap_file = __DIR__ . '/../../../bootstrap.php';
$included = false;
if (file_exists($app_boostrap_file)) {
    require_once $app_boostrap_file;
    $included = true;
}
if (!$included) {
    $app_boostrap_file = __DIR__ . '/../../../app/bootstrap.php';
    if (file_exists($app_boostrap_file)) {
        require_once $app_boostrap_file;
        $included = true;
    }
}
if (!$included) {
    die('Bootstrap file not found. Run this module inside WelineFramework.');
}

$ds = DS;
$vendor_path = rtrim(VENDOR_PATH, '\\/') . $ds . "studio-42{$ds}elfinder";
$target_static_path = __DIR__ . DS . "view{$ds}statics{$ds}";
if (!is_dir($target_static_path)) {
    mkdir($target_static_path, 0755, true);
}

if (!is_dir($vendor_path)) {
    if (function_exists('w_log_warning')) {
        w_log_warning(
            'ElFinder vendor assets were not copied because studio-42/elfinder is missing: ' . $vendor_path,
            [],
            'elfinder'
        );
    }
    return;
}

$copyDirectory = static function (string $source, string $target) use (&$copyDirectory): void {
    if (!is_dir($source)) {
        return;
    }

    if (!is_dir($target)) {
        mkdir($target, 0755, true);
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relativePath = substr($item->getPathname(), strlen($source));
        $destinationPath = rtrim($target, '\\/') . $relativePath;

        if ($item->isDir()) {
            if (!is_dir($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }
            continue;
        }

        $destinationDir = dirname($destinationPath);
        if (!is_dir($destinationDir)) {
            mkdir($destinationDir, 0755, true);
        }
        copy($item->getPathname(), $destinationPath);
    }
};

$copyDirectory($vendor_path, $target_static_path);
