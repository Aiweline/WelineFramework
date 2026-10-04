<?php
declare(strict_types=1);

// Isolate immutable persistence/discovery inputs in a child process. The real
// coordinator decides whether locale-only intent reaches the materializer.
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
        public static array $configuration = [];
        public function captureUnresolvedCurrentIntent($version, $context): ?\Weline\Theme\Api\Version\ThemeVersionIdentity { return null; }
        public function head($identity): array { return ['package_default_json' => json_encode(['current_package_defaults'=>true, 'configuration'=>self::$configuration]), 'chrome_intent_json'=>'[]']; }
        public function resources($identity): array { return []; }
        public function read($identity, $context): array {
            return ['resolved'=>true, 'has_intent'=>false, 'payload'=>$context->resourceType === 'layout' ? ['nodes'=>[]] : ['values'=>[]]];
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
        public function discoverPageNativeOwners($identity, $type, $option, array $nodes): array { return $nodes; }
        public function resolveNodeConfigurations(...$args): array { return []; }
        public function candidatePage($identity, $hash, $structure, $nodes, $configs, $type, $option, $target, $targetId, ...$rest): array {
            $this->generatedVersions[] = $identity->themeVersionId;
            $path = \Weline\Framework\Manager\ObjectManager::getInstance(ThemeLayoutEntityPaths::class)->pageLayoutPhtml($identity, $type, $option, $target, $targetId);
            return [$path => json_encode(['locale'=>$rest[0] ?? [], 'params'=>$rest[1] ?? []])];
        }
        public function candidateChrome($version, $locale = [], $options = [], $params = []): array {
            $paths=\Weline\Framework\Manager\ObjectManager::getInstance(ThemeLayoutEntityPaths::class);
            return [$paths->partialPhtml($version->toVersionIdentity(),'header')=>json_encode(['locale'=>$locale,'params'=>$params])];
        }
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
    foreach (['Api/Version/ThemeVersionIdentity.php', 'Service/LayoutEntity/ThemeLayoutEntityPaths.php',
        'Service/LayoutEntity/ThemeLayoutEntityInputResolver.php', 'Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php'] as $file) { require $theme . '/' . $file; }
    \Weline\Theme\Model\ThemeScopeVersion::$rows = [1116 => ['version_id'=>1116,'lifecycle'=>'sealed','content_revision'=>8]];
    $paths = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths(sys_get_temp_dir() . '/weline-locale-candidate-no-write');
    \Weline\Framework\Manager\ObjectManager::$instances[$paths::class]=$paths;
    $coordinator = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator(
        new \Weline\Theme\Service\ThemeScopeVersionService(), new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer(),
        new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore(), new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPointerResolver(),
        new \Weline\Theme\Service\LayoutEntity\ThemeLayoutSlotTreeBuilder(), new \Weline\Theme\Service\SharedChromeService());
    $identity=(new \Weline\Theme\Model\ThemeScopeVersion())->load(1116)->toVersionIdentity();
    $result=[];
    foreach (['page'=>['layouts.homepage.default'=>['title'=>'Bonjour']], 'partial'=>['partials.header.default'=>['title'=>'Entête']], 'none'=>[]] as $scenario=>$values) {
        \Weline\Theme\Service\Version\ThemeVersionResourceSnapshotService::$configuration=['locale_params'=>['fr_FR'=>$values]];
        $candidates=$coordinator->candidateForIdentity($identity,'homepage');
        $page=$candidates[$paths->pageLayoutPhtml($identity,'homepage')] ?? null;
        $header=$candidates[$paths->partialPhtml($identity,'header')] ?? null;
        $result[$scenario]=['page'=>$page===null?null:json_decode($page,true),'header'=>$header===null?null:json_decode($header,true)];
    }
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), "\n";
}
