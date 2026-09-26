<?php

declare(strict_types=1);

namespace Weline\Framework\Manager {
    final class ObjectManager {
        public static function getInstance(string $class): object {
            return $class === \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBindingStore::class
                ? new $class(new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths())
                : new $class();
        }
    }
}
namespace {
    $theme = dirname(__DIR__, 4);
    define('BP', sys_get_temp_dir() . '/bound-shell-' . bin2hex(random_bytes(6)));
    require $theme . '/../Framework/Compilation/AtomicCompiledFilePublisher.php';
    require $theme . '/Api/Version/ThemeVersionIdentity.php';
    foreach (['ThemeLayoutEntityPaths', 'EntityRenderBinding', 'ThemeLayoutEntityBindingStore', 'ThemeLayoutEntityBakeCoordinator'] as $class) {
        require $theme . '/Service/LayoutEntity/' . $class . '.php';
    }

    $paths = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths();
    $store = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBindingStore($paths);
    $identity = new \Weline\Theme\Api\Version\ThemeVersionIdentity(
        901, 'test.default.default', 'normal', 'frontend', 1, 'formal', 1,
    );
    $layoutHash = hash('sha256', 'identity');
    $structure = str_repeat('a', 64);
    $dir = $paths->pageStructureDir($identity, $layoutHash, $structure);
    mkdir($dir, 0775, true);
    file_put_contents($dir . 'layout.phtml', 'layout');
    file_put_contents($dir . 'structure.json', '{}');
    file_put_contents($dir . 'shell.phtml', 'bound shell');
    touch($dir . 'shell.phtml', 1234567890);
    $binding = $store->publishPageBinding($identity, $layoutHash, $structure, [], []);
    $coordinator = (new ReflectionClass(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::class))
        ->newInstanceWithoutConstructor();
    $path = $coordinator->writePublishedWholeShell($identity, $layoutHash, $structure);
    $result = [
        'same_path' => $path === $binding->shellPath,
        'mtime' => filemtime($binding->shellPath),
        'content' => file_get_contents($binding->shellPath),
    ];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(BP, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir(BP);
    echo json_encode($result, JSON_THROW_ON_ERROR);
}
