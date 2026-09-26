<?php

declare(strict_types=1);

/**
 * Published binding miss must not read an unfinished draft artifact (v3 identity tree).
 */
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
                ? new $class(new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths())
                : new $class();
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
    $configStore = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore($paths);
    $materializer = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer($paths, $tree, $configStore);
    $store = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBindingStore($paths);

    $layoutHash = hash('sha256', 'homepage-identity');
    $draftIdentity = new \Weline\Theme\Api\Version\ThemeVersionIdentity(
        901,
        'test.default.default',
        'normal',
        'frontend',
        1,
        'draft',
        1,
    );
    $formalIdentity = new \Weline\Theme\Api\Version\ThemeVersionIdentity(
        901,
        'test.default.default',
        'normal',
        'frontend',
        2,
        'formal',
        1,
    );

    $uid = str_repeat('a', 32);
    $draftNodes = [$uid => [
        'node_uid' => $uid,
        'area' => 'content',
        'slot_id' => 'draft-only',
        'widget_code' => 'test',
        'config' => ['title' => 'draft'],
    ]];
    $materializer->materializePage($draftIdentity, $layoutHash, 'source', $draftNodes, [], 'homepage');

    // Formal version has no binding yet — must not fall back to draft binding.
    $entityBinding = $store->readPageBinding($formalIdentity, $layoutHash);
    $result = [
        'binding_null' => $entityBinding === null,
        'draft_binding_exists' => $store->readPageBinding($draftIdentity, $layoutHash) !== null,
    ];

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
