<?php
declare(strict_types=1);

// Like upgrade-failure-boundary.php, isolate persistence boundaries in a child
// process. The coordinator, identity, paths, owner lock and file publisher are real.
namespace Weline\Framework\Manager {
    class ObjectManager {
        public static array $instances = [];
        public static function getInstance(string $class): object { return self::$instances[$class] ??= new $class(); }
    }
}
namespace Weline\Framework\Runtime {
    class RequestContext { public static function getId(): ?string { return null; } }
}
namespace Weline\Theme\Api\Scoped {
    class ThemeEditorContext {
        public const RESOURCE_LAYOUT = 'layout', RESOURCE_META = 'meta', RESOURCE_I18N = 'i18n';
        public string $resourceType = 'layout', $area = 'frontend', $layoutType = 'homepage', $layoutOption = 'default', $locale = 'default', $targetType = 'global';
        public int $themeId = 999997, $targetId = 0;
        public object $scope;
        public function __construct() { $this->scope = (object)['storageScope' => 'fixture.store.channel', 'storeMode' => 'normal']; }
        public function withResource(string $resource): self { $copy = clone $this; $copy->resourceType = $resource; return $copy; }
        public function withLocale(string $locale): self { $copy = clone $this; $copy->locale = $locale; return $copy; }
        public function identityHash(): string { return hash('sha256', $this->resourceType); }
        public function toArray(): array { return ['layout_type' => 'homepage', 'layout_option' => 'default', 'target_type' => 'global', 'target_id' => 0]; }
    }
}
namespace Weline\Theme\Model {
    class ThemeScopeVersion {
        public const LIFECYCLE_DRAFT = 'draft', LIFECYCLE_SEALED = 'sealed';
        public static array $rows = [];
        private array $data = [];
        public function clearData(): self { $this->data = []; return $this; }
        public function clearQuery(): self { return $this; }
        public function where(...$args): self { return $this; }
        public function setData(array $data): self { $this->data = $data; return $this; }
        public function select(): self { return $this; }
        public function fetchArray(): array { return array_values(self::$rows); }
        public function load(int $id): self { $this->data = self::$rows[$id]; return $this; }
        public function getVersionId(): int { return $this->data['version_id']; }
        public function getThemeId(): int { return 999997; }
        public function getScope(): string { return 'fixture.store.channel'; }
        public function getStoreMode(): string { return 'normal'; }
        public function getArea(): string { return 'frontend'; }
        public function getLifecycle(): string { return $this->data['lifecycle']; }
        public function getContentRevision(): int { return $this->data['content_revision']; }
        public function setContentRevision(int $revision): self { $this->data['content_revision'] = $revision; return $this; }
        public function setLifecycle(string $lifecycle): self { $this->data['lifecycle'] = $lifecycle; return $this; }
        public function getChromePayload(): array { return $this->data['chrome'] ?? []; }
        public function setChromePayload(array $nodes): self { $this->data['chrome'] = $nodes; return $this; }
        public function toVersionIdentity(): \Weline\Theme\Api\Version\ThemeVersionIdentity {
            return new \Weline\Theme\Api\Version\ThemeVersionIdentity($this->getThemeId(), $this->getScope(), 'normal', 'frontend',
                $this->getVersionId(), $this->getLifecycle() === self::LIFECYCLE_DRAFT ? 'draft' : 'formal', $this->getContentRevision());
        }
    }
    class WelineTheme extends ThemeScopeVersion { public function load(int $id): self { return $this; } }
}
namespace Weline\Theme\Helper {
    class LayoutScanner { public static function scanLayouts(...$args): array { return ['homepage' => ['default']]; } }
}
namespace Weline\Theme\Service\Version {
    class ThemeVersionResourceSnapshotService {
        public function captureUnresolvedCurrentIntent($version, $context): ?\Weline\Theme\Api\Version\ThemeVersionIdentity { return null; }
        public function head($identity): array { return ['package_default_json' => '{"current_package_defaults":true}', 'chrome_intent_json' => '[]']; }
        public function resources($identity): array { return [['resource_type' => 'layout', 'resource_key_json' => '{"layout_type":"homepage","layout_option":"default"}']]; }
        public function read($identity, $context): array {
            return ['resolved' => true, 'has_intent' => true, 'payload' => $context->resourceType === 'layout'
                ? ['nodes' => ['fixture' => ['node_uid' => 'fixture', 'config' => [], 'slot_id' => 'content']]] : ['values' => []]];
        }
    }
}
namespace Weline\Theme\Service\LayoutEntity {
    class RequiredDefaultInjectionBakeMerger {
        public function loadDeclarations(): array { return []; }
        public function mergeIntoNodes(array $nodes, ...$args): array { return $nodes; }
    }
    class ThemeLayoutSlotTreeBuilder {
        public function filterContentNodes(array $nodes): array { return $nodes; }
        public function filterChromeNodes(array $nodes): array { return []; }
    }
    class ThemeLayoutEntityConfigStore {}
    class ThemeLayoutEntityPointerResolver {}
    class ThemeLayoutEntityMaterializer {
        public array $generatedVersions = [];
        public function resolveNodeConfigurations(...$args): array { return []; }
        public function candidatePage($identity, $hash, $structure, $nodes, $configs, $type, $option, $target, $targetId, ...$rest): array {
            $this->generatedVersions[] = $identity->themeVersionId;
            $path = \Weline\Framework\Manager\ObjectManager::getInstance(ThemeLayoutEntityPaths::class)->pageLayoutPhtml($identity, $type, $option, $target, $targetId);
            return [$path => $identity->mode . ':' . $identity->themeVersionId . ':R' . $identity->contentRevision];
        }
        public function candidateChrome(...$args): array { return []; }
    }
}
namespace Weline\Theme\Service {
    class SharedChromeService {}
    class ThemeRuntimeCacheCleaner { public function clearLayoutEntityCaches(...$args): void {} }
    class ThemeRuntimeLayoutResolver {
        public function buildContext(...$args): \Weline\Theme\Api\Scoped\ThemeEditorContext { return new \Weline\Theme\Api\Scoped\ThemeEditorContext(); }
    }
    class ThemeScopeVersionService {
        public function getCurrent(...$args): \Weline\Theme\Model\ThemeScopeVersion { return (new \Weline\Theme\Model\ThemeScopeVersion())->load(1121); }
        public function getPublished(...$args): \Weline\Theme\Model\ThemeScopeVersion { return (new \Weline\Theme\Model\ThemeScopeVersion())->load(1116); }
    }
}
namespace {
    $theme = dirname(__DIR__, 4);
    $parent = sys_get_temp_dir() . '/weline-selected-draft-rebake-' . bin2hex(random_bytes(6));
    mkdir($parent, 0770, true);
    define('BP', $parent);
    foreach (['Api/Version/ThemeVersionIdentity.php', 'Service/LayoutEntity/ThemeLayoutEntityPaths.php',
        'Service/LayoutEntity/ThemeLayoutEntityOwnerLock.php', 'Service/LayoutEntity/ThemeLayoutEntityBatchPublisher.php',
        'Service/LayoutEntity/ThemeLayoutTemplateDependencies.php',
        'Service/LayoutEntity/ThemeLayoutEntityInputResolver.php', 'Service/LayoutEntity/ThemeLayoutSourceSnapshot.php',
        'Service/LayoutEntity/ThemeLayoutEntityInjectionTargets.php', 'Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php'] as $file) { require $theme . '/' . $file; }
    \Weline\Theme\Model\ThemeScopeVersion::$rows = [
        1121 => ['version_id' => 1121, 'lifecycle' => 'draft', 'content_revision' => 1],
        1116 => ['version_id' => 1116, 'lifecycle' => 'sealed', 'content_revision' => 8],
        1114 => ['version_id' => 1114, 'lifecycle' => 'sealed', 'content_revision' => 3],
        1117 => ['version_id' => 1117, 'lifecycle' => 'draft', 'content_revision' => 4],
    ];
    $inputs = \Weline\Theme\Model\ThemeScopeVersion::$rows;
    $paths = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths($parent . '/theme-layout-entities');
    \Weline\Framework\Manager\ObjectManager::$instances[$paths::class] = $paths;
    $materializer = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer();
    $coordinator = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator(
        new \Weline\Theme\Service\ThemeScopeVersionService(), $materializer,
        new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore(),
        new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPointerResolver(),
        new \Weline\Theme\Service\LayoutEntity\ThemeLayoutSlotTreeBuilder(),
        new \Weline\Theme\Service\SharedChromeService());
    try {
        if (($argv[1] ?? '') === 'published-write') {
            $selected = (new \Weline\Theme\Model\ThemeScopeVersion())->load(1121)->toVersionIdentity();
            $sealed = (new \Weline\Theme\Model\ThemeScopeVersion())->load(1116)->toVersionIdentity();
            $draftPath = $paths->pageLayoutPhtml($selected, 'homepage');
            mkdir(dirname($draftPath), 0770, true);
            file_put_contents($draftPath, 'selected-draft-before-publication');

            // Both keys are present in real publish receipts. The release must
            // select the formal version even though a draft payload is included.
            $coordinator->afterResourceWrite(new \Weline\Theme\Api\Scoped\ThemeEditorContext(), [
                'theme_version_id' => 1116,
                'release_id' => 7201,
                'draft_payload' => ['nodes' => []],
            ]);
            $publishedPath = $paths->pageLayoutPhtml($sealed, 'homepage');
            echo json_encode([
                'published_artifact' => file_get_contents($publishedPath),
                'published_path' => $publishedPath,
                'shared_draft' => file_get_contents($draftPath),
                'generated_versions' => $materializer->generatedVersions,
                'version_rows_unchanged' => $inputs === \Weline\Theme\Model\ThemeScopeVersion::$rows,
            ], JSON_THROW_ON_ERROR), "\n";
            return;
        }
        $coordinator->rebakeAfterInjectionCollect(999997);
        $selected = (new \Weline\Theme\Model\ThemeScopeVersion())->load(1121)->toVersionIdentity();
        $sealed = (new \Weline\Theme\Model\ThemeScopeVersion())->load(1116)->toVersionIdentity();
        $historicalSealed = (new \Weline\Theme\Model\ThemeScopeVersion())->load(1114)->toVersionIdentity();
        $old = (new \Weline\Theme\Model\ThemeScopeVersion())->load(1117)->toVersionIdentity();
        $draftPath = $paths->pageLayoutPhtml($selected, 'homepage');
        $result = ['shared_draft' => file_get_contents($draftPath),
            'sealed_version' => file_get_contents($paths->pageLayoutPhtml($sealed, 'homepage')),
            'historical_sealed_version' => file_get_contents($paths->pageLayoutPhtml($historicalSealed, 'homepage')),
            'rebake_generated_versions' => array_values(array_unique($materializer->generatedVersions))];
        $candidates = $coordinator->candidateForIdentity($old, 'homepage');
        $result['historical_candidate'] = $candidates[$draftPath];
        $result['shared_draft_after_historical_candidate'] = file_get_contents($draftPath);
        $result['version_rows_unchanged'] = $inputs === \Weline\Theme\Model\ThemeScopeVersion::$rows;
        echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
    } finally {
        $paths->purgeAllEntities();
        rmdir($parent);
    }
}
