<?php

declare(strict_types=1);

// 仅隔离外部目录/资源发现；真实固化器、槽树、绑定和文件发布均运行。
namespace Weline\Framework\Runtime {
    final class RequestContext {
        private static array $values = [];
        public static function get(string $key): mixed { return self::$values[$key] ?? null; }
        public static function set(string $key, mixed $value): void { self::$values[$key] = $value; }
    }
}
namespace Weline\Framework\Manager {
    final class ObjectManager {
        public static function getInstance(string $class): object {
            return $class === \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBindingStore::class
                ? new $class(new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths()) : new $class();
        }
    }
}
namespace Weline\Theme\Model {
    final class WelineTheme { public function load(int $id): void {} }
}
namespace Weline\Theme\Service {
    final class ThemeDirectoryResolver {
        public function getAreaDirectories(string $area, object $theme): array { return []; }
    }
    final class ProductPageLayoutNormalizer {
        public function normalizeLayoutForRender(string $type, array $layout): array { return $layout; }
    }
}
namespace Weline\Theme\Helper {
    final class FooterDefaultLinksHelper {
        public static function ensureFooterContainerInLayout(array $layout): array { return $layout; }
    }
}
namespace Weline\Theme\Service\LayoutEntity {
    final class RequiredDefaultInjectionBakeMerger {
        public function mergeIntoNodes(array $nodes, mixed ...$context): array { return $nodes; }
    }
    final class ThemeLayoutEntityPointerResolver {
        public function invalidatePage(mixed ...$context): void {}
        public function rememberPagePointer(mixed ...$context): void {}
    }
    final class ThemeLayoutEntityAssetCollector {
        public function collectFromNodes(array $nodes, bool $full): array { return []; }
    }
    final class ThemeLayoutEntityWidgetRenderer {
        public function renderBound(string $uid, string $source, EntityRenderBinding $binding): string {
            return json_decode(file_get_contents($binding->configPath), true)[$uid]['config']['title'];
        }
    }
}
namespace {
    $theme = dirname(__DIR__, 4);
    define('BP', sys_get_temp_dir() . '/weline-materializer-' . bin2hex(random_bytes(6)));
    require $theme . '/../Framework/Compilation/AtomicCompiledFilePublisher.php';
    require $theme . '/Service/SharedChromeService.php';
    require $theme . '/Service/SlotBoundaryMarkers.php';
    foreach (['ThemeLayoutEntityPaths', 'ThemeLayoutSlotTreeBuilder', 'ThemeLayoutEntityConfigStore', 'EntityRenderBinding', 'ThemeLayoutEntityBindingStore', 'ThemeLayoutEntityMaterializer', 'ThemeLayoutEntityBakeCoordinator'] as $class) {
        require $theme . '/Service/LayoutEntity/' . $class . '.php';
    }
    $paths = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths();
    $shared = (new \ReflectionClass(\Weline\Theme\Service\SharedChromeService::class))->newInstanceWithoutConstructor();
    $tree = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutSlotTreeBuilder($shared);
    $materializer = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer($paths, $tree, new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore($paths));
    $store = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBindingStore($paths);
    $uid = str_repeat('a', 32);
    $identity = str_repeat('b', 16);
    $draftNodes = [$uid => ['node_uid' => $uid, 'area' => 'content', 'slot_id' => 'draft-only', 'widget_code' => 'test', 'config' => ['title' => 'draft']]];
    $materializer->materializePage(901, 'test', $identity, 'source', $draftNodes, [], false, null, 'homepage', 1);
    file_put_contents($paths->pageCurrentJson(901, 'test', $identity), json_encode(['draft' => 'd1']));
    $publishedUid = str_repeat('c', 32);
    $publishedNodes = [$publishedUid => ['node_uid' => $publishedUid, 'area' => 'content', 'slot_id' => 'published-only', 'widget_code' => 'test', 'config' => ['title' => 'published']]];
    $coordinator = (new ReflectionClass(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::class))->newInstanceWithoutConstructor();
    foreach (['slotTree' => $tree, 'materializer' => $materializer, 'configStore' => new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore($paths), 'pointers' => new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPointerResolver()] as $name => $value) {
        (new ReflectionProperty($coordinator, $name))->setValue($coordinator, $value);
    }
    (new ReflectionMethod($coordinator, 'updateConfigSidecarsOnly'))->invoke($coordinator, 901, 'test', $identity, $publishedNodes, true, 2, 2, 'homepage');
    $entityBinding = $store->readPageBinding(901, 'test', $identity, 'r2');
    ob_start(); include $entityBinding->templatePath; $html = ob_get_clean();
    $result = ['html' => $html];
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BP, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir(BP);
    echo json_encode($result, JSON_THROW_ON_ERROR);
}
