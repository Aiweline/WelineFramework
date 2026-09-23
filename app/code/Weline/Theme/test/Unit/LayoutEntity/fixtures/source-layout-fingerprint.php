<?php

declare(strict_types=1);
namespace Weline\Framework\Manager {
    final class ObjectManager {
        public static function getInstance(string $class): object { return new $class(); }
    }
}
namespace Weline\Theme\Model {
    final class WelineTheme { public function load(int $id): void {} }
}
namespace Weline\Theme\Service {
    final class ThemeDirectoryResolver {
        public static array $directories = [];
        public function getAreaDirectories(string $area, object $theme): array { return self::$directories; }
    }
}
namespace {
    require dirname(__DIR__, 4) . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php';
    $root = sys_get_temp_dir() . '/source-fingerprint-' . bin2hex(random_bytes(6));
    foreach (['theme', 'module'] as $layer) {
        mkdir($root . '/' . $layer . '/layouts/homepage', 0775, true);
        file_put_contents($root . '/' . $layer . '/layouts/homepage/default.phtml', $layer);
    }
    \Weline\Theme\Service\ThemeDirectoryResolver::$directories = [['path' => $root . '/theme'], ['path' => $root . '/module']];
    $coordinator = (new ReflectionClass(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($coordinator, 'sourceLayoutFingerprint');
    $hash = fn(string $option = 'default') => $method->invoke($coordinator, 1, 'homepage', $option, 'frontend');
    $first = $hash();
    file_put_contents($root . '/module/layouts/homepage/default.phtml', 'module changed');
    $lower = $hash();
    file_put_contents($root . '/theme/layouts/homepage/default.phtml', 'theme changed');
    $second = $hash();
    $result = ['lower_precedence_ignored' => $first === $lower, 'source_change' => $first !== $second, 'option_distinct' => $second !== $hash('alternate')];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
    echo json_encode($result, JSON_THROW_ON_ERROR);
}
