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
    require $theme . '/Api/Version/ThemeVersionIdentity.php';
    require $theme . '/Service/SharedChromeService.php';
    require $theme . '/Service/SlotBoundaryMarkers.php';
    foreach ([
        'ThemeLayoutEntityPaths',
        'ThemeLayoutSlotTreeBuilder',
        'ThemeLayoutEntityConfigStore',
        'EntityRenderBinding',
        'ThemeLayoutEntityBindingStore',
        'ThemeLayoutEntityMaterializer',
    ] as $class) {
        require $theme . '/Service/LayoutEntity/' . $class . '.php';
    }

    $paths = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths();
    $shared = (new \ReflectionClass(\Weline\Theme\Service\SharedChromeService::class))->newInstanceWithoutConstructor();
    $tree = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutSlotTreeBuilder($shared);
    $materializer = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer(
        $paths,
        $tree,
        new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore($paths),
    );
    $store = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBindingStore($paths);
    $uid = str_repeat('a', 32);
    $nodes = [$uid => [
        'node_uid' => $uid,
        'area' => 'content',
        'slot_id' => 'content',
        'widget_code' => 'test',
        'config' => ['title' => 'old'],
    ]];
    $layoutHash = hash('sha256', 'identity');
    $draft = new \Weline\Theme\Api\Version\ThemeVersionIdentity(
        901, 'test.default.default', 'normal', 'frontend', 1, 'draft', 1,
    );
    $formal2 = new \Weline\Theme\Api\Version\ThemeVersionIdentity(
        901, 'test.default.default', 'normal', 'frontend', 2, 'formal', 1,
    );
    $formal3 = new \Weline\Theme\Api\Version\ThemeVersionIdentity(
        901, 'test.default.default', 'normal', 'frontend', 3, 'formal', 1,
    );
    $formal4 = new \Weline\Theme\Api\Version\ThemeVersionIdentity(
        901, 'test.default.default', 'normal', 'frontend', 4, 'formal', 1,
    );

    $path = $materializer->materializePage($draft, $layoutHash, 'source', $nodes, [], 'homepage');
    touch($path, 1234567890);
    $old = $store->readPageBinding($draft, $layoutHash);
    $nodes[$uid]['config']['title'] = 'new';
    $next = $materializer->materializePage($formal2, $layoutHash, 'source', $nodes, [], 'homepage');
    $new = $store->readPageBinding($formal2, $layoutHash);
    $render = static function ($entityBinding): string {
        ob_start();
        include $entityBinding->templatePath;
        return ob_get_clean();
    };
    clearstatcache(true, $path);
    // Same structure hash can share bytes; paths must still be version-isolated.
    $result = [
        'same_path' => $path === $next,
        'paths_isolated' => $path !== $next,
        'mtime' => filemtime($path),
        'old_html' => $render($old),
        'new_html' => $render($new),
        'shell_exists' => is_file($new->shellPath),
    ];
    $nodes[$uid]['slot_id'] = 'other';
    $changed = $materializer->materializePage($formal3, $layoutHash, 'source', $nodes, [], 'homepage');
    $result['structure_changed'] = $changed !== $path && $changed !== $next;
    $sourceChanged = $materializer->materializePage($formal4, $layoutHash, 'different-source', $nodes, [], 'homepage');
    $result['source_changed'] = $sourceChanged !== $changed;

    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator(BP, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir(BP);
    echo json_encode($result, JSON_THROW_ON_ERROR);
}
