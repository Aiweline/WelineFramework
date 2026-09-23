<?php

declare(strict_types=1);

// 仅隔离外部目录/资源发现；真实固化器、槽树、绑定和文件发布均运行。
namespace Weline\Framework\Manager {
    final class ObjectManager {
        public static function getInstance(string $class): object { return new $class(); }
    }
}
namespace Weline\Theme\Service {
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
    foreach (['ThemeLayoutEntityPaths', 'ThemeLayoutSlotTreeBuilder', 'ThemeLayoutEntityConfigStore', 'EntityRenderBinding', 'ThemeLayoutEntityBindingStore', 'ThemeLayoutEntityMaterializer'] as $class) {
        require $theme . '/Service/LayoutEntity/' . $class . '.php';
    }
    $paths = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths();
    $shared = (new \ReflectionClass(\Weline\Theme\Service\SharedChromeService::class))->newInstanceWithoutConstructor();
    $tree = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutSlotTreeBuilder($shared);
    $materializer = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer($paths, $tree, new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore($paths));
    $store = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBindingStore($paths);
    $uid = str_repeat('a', 32);
    $nodes = [$uid => ['node_uid' => $uid, 'area' => 'content', 'slot_id' => 'content', 'widget_code' => 'test', 'config' => ['title' => 'old']]];
    $path = $materializer->materializePage(901, 'test', 'identity', 'source', $nodes, [], false, null, 'homepage', 1);
    touch($path, 1234567890);
    $old = $store->readPageBinding(901, 'test', 'identity', 'd1');
    $nodes[$uid]['config']['title'] = 'new';
    $next = $materializer->materializePage(901, 'test', 'identity', 'source', $nodes, [], true, 2, 'homepage', 2);
    $new = $store->readPageBinding(901, 'test', 'identity', 'r2');
    $render = static function ($entityBinding): string { ob_start(); include $entityBinding->templatePath; return ob_get_clean(); };
    clearstatcache(true, $path);
    $result = ['same_path' => $path === $next, 'mtime' => filemtime($path), 'old_html' => $render($old), 'new_html' => $render($new), 'shell_exists' => is_file($new->shellPath)];
    $nodes[$uid]['slot_id'] = 'other';
    $changed = $materializer->materializePage(901, 'test', 'identity', 'source', $nodes, [], true, 3, 'homepage', 3);
    $result['structure_changed'] = $changed !== $path;
    $sourceChanged = $materializer->materializePage(901, 'test', 'identity', 'different-source', $nodes, [], true, 4, 'homepage', 4);
    $result['source_changed'] = $sourceChanged !== $changed;
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BP, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir(BP);
    echo json_encode($result, JSON_THROW_ON_ERROR);
}
