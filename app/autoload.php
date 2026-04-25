<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 *
 * 统一自动加载入口：app/code 与 generated/code 优先于 vendor，
 * 供 app/bootstrap.php、WLS worker (worker.php / worker_ssl.php) 等复用。
 */

if (!\defined('BP')) {
    \define('BP', \dirname(__DIR__) . \DIRECTORY_SEPARATOR);
}
if (!\defined('DS')) {
    \define('DS', \DIRECTORY_SEPARATOR);
}
if (!\defined('APP_PATH')) {
    \define('APP_PATH', BP . 'app' . DS);
}
if (!\defined('APP_ETC_PATH')) {
    \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
}
if (!\defined('PUB')) {
    \define('PUB', BP . 'pub' . DS);
}
if (!\defined('VENDOR_PATH')) {
    \define('VENDOR_PATH', BP . 'vendor' . DS);
}
if (!\defined('APP_CODE_PATH')) {
    \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
}

// 注册 app/code 和 generated/code 优先的自动加载器（在 Composer 之前，prepend=true）
// 顺序：classmap → generated/code → app/code（兜底，classmap 未刷新时新类仍可加载）
$welineProjectAutoloader = static function ($class) {
    static $loadedFiles = [];
    static $classMap = null;
    static $classMapLoaded = false;

    if (\class_exists($class, false) || \interface_exists($class, false) || \trait_exists($class, false)) {
        return true;
    }

    if (!$classMapLoaded) {
        $classMapLoaded = true;
        $classMapFile = BP . 'generated' . \DIRECTORY_SEPARATOR . 'classmap.php';
        if (\is_file($classMapFile)) {
            $classMap = @include $classMapFile;
            if (!\is_array($classMap)) {
                $classMap = null;
            }
        }
    }

    if ($classMap !== null && isset($classMap[$class])) {
        $cachedPath = $classMap[$class];
        if (!isset($loadedFiles[$cachedPath]) && \is_file($cachedPath)) {
            $loadedFiles[$cachedPath] = true;
            require_once $cachedPath;
            return true;
        }
    }

    $relativePath = \str_replace('\\', \DIRECTORY_SEPARATOR, $class) . '.php';

    $generatedPath = BP . 'generated' . \DIRECTORY_SEPARATOR . 'code' . \DIRECTORY_SEPARATOR . $relativePath;
    if (!isset($loadedFiles[$generatedPath]) && \is_file($generatedPath)) {
        $loadedFiles[$generatedPath] = true;
        require_once $generatedPath;
        if (\class_exists($class, false) || \interface_exists($class, false) || \trait_exists($class, false)) {
            return true;
        }
    }

    $fullPath = APP_CODE_PATH . $relativePath;
    if (isset($loadedFiles[$fullPath])) {
        return true;
    }
    if (\is_file($fullPath)) {
        $loadedFiles[$fullPath] = true;
        require_once $fullPath;
        return true;
    }

    return false;
};
\spl_autoload_register($welineProjectAutoloader, true, true);

$autoloader = VENDOR_PATH . 'autoload.php';
if (\is_file($autoloader)) {
    $composerLoader = require $autoloader;
    // Composer registers itself with prepend=true. Restore project priority so stale absolute paths cannot shadow BP.
    \spl_autoload_unregister($welineProjectAutoloader);
    \spl_autoload_register($welineProjectAutoloader, true, true);

    $psr4CacheFile = BP . 'generated' . \DIRECTORY_SEPARATOR . 'psr4_map.php';
    if (\is_file($psr4CacheFile)) {
        $cachedPsr4 = @include $psr4CacheFile;
        if (\is_array($cachedPsr4) && $cachedPsr4 !== []) {
        foreach ($cachedPsr4 as $prefix => $paths) {
            $composerLoader->setPsr4($prefix, $paths);
        }
        }
    } else {
    $psr4Map = $composerLoader->getPrefixesPsr4();
    foreach ($psr4Map as $prefix => $paths) {
        $rel = \str_replace('\\', \DIRECTORY_SEPARATOR, \trim($prefix, '\\'));
        $appCodePath = APP_CODE_PATH . $rel . \DIRECTORY_SEPARATOR;
        if (\is_dir($appCodePath)) {
            $paths = \array_filter($paths, function ($path) use ($appCodePath) {
                $norm = \rtrim($path, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
                return $norm !== $appCodePath;
            });
            \array_unshift($paths, $appCodePath);
            $composerLoader->setPsr4($prefix, \array_values($paths));
        }
    }
}

    $localClassMapFile = BP . 'generated' . \DIRECTORY_SEPARATOR . 'classmap.php';
    if (\is_file($localClassMapFile) && \method_exists($composerLoader, 'addClassMap')) {
        $localClassMap = @include $localClassMapFile;
        if (\is_array($localClassMap) && $localClassMap !== []) {
            $generatedCodePath = BP . 'generated' . \DIRECTORY_SEPARATOR . 'code' . \DIRECTORY_SEPARATOR;
            foreach ($localClassMap as $className => $path) {
                if (\str_starts_with($path, $generatedCodePath) && !\is_file($path)) {
                    unset($localClassMap[$className]);
                }
            }

            $composerLoader->addClassMap($localClassMap);
        }
    }

    if (\method_exists($composerLoader, 'addPsr4')) {
        $composerLoader->addPsr4('', [
            APP_CODE_PATH,
            BP . 'generated' . \DIRECTORY_SEPARATOR . 'code' . \DIRECTORY_SEPARATOR,
        ], true);
    }
}
