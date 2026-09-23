<?php

declare(strict_types=1);

// Process-local persistence doubles: never connect to or mutate the configured database.
namespace Weline\Theme\Model {
    class ThemeScopeVersion
    {
        public function __construct(public int $id, public string $scope, public array $nodes, public bool $published = false) {}
        public function getVersionId(): int { return $this->id; }
        public function getThemeId(): int { return 3; }
        public function getScope(): string { return $this->scope; }
        public function getChromePayload(): array { return $this->nodes; }
        public function isPublished(): bool { return $this->published; }
    }
}
namespace Weline\Theme\Service {
    use Weline\Theme\Model\ThemeScopeVersion;
    class ThemeScopeVersionService
    {
        public ?ThemeScopeVersion $current;
        public ThemeScopeVersion $published;
        public function getCurrent(int $themeId, string $scope): ?ThemeScopeVersion { return $this->current; }
        public function getPublished(int $themeId, string $scope): ?ThemeScopeVersion { return $this->published; }
        public function createRevisionFrom(ThemeScopeVersion $source, string $name = '', string $actor = ''): ThemeScopeVersion {
            return $this->current = new ThemeScopeVersion(11, $source->scope, $source->nodes);
        }
        public function ensureCurrent(int $themeId, string $scope, string $scopeKind = 'website', ?int $websiteId = null, string $storeMode = 'normal'): ThemeScopeVersion {
            return $this->current ??= new ThemeScopeVersion(11, $scope, []);
        }
        public function setChromePayload(ThemeScopeVersion $version, array $nodes): void { $version->nodes = $nodes; }
    }
}
namespace Weline\Theme\Service\LayoutEntity {
    class ThemeLayoutEntityChrome
    {
        public array $sources = [];
        public bool $missing = false;
        public function resolveRenderSources(int $themeId, string $scope, bool $preview = false): array {
            if ($this->missing) { throw new \RuntimeException('theme_layout_entity_chrome_missing: fixture'); }
            return $this->sources;
        }
    }
    class ThemeLayoutEntityConfigStore
    {
        public array $nodes = [];
        public function readBoundConfig(EntityRenderBinding $binding): array { return $this->nodes[$binding->scope] ?? []; }
    }
    class ThemeLayoutEntityBakeCoordinator
    {
        public function bakeChromeFromNodes(int $themeId, string $scope, array $nodes, bool $structural = true, bool $invalidate = true, ?int $versionId = null): string { return '/fixture/chrome.phtml'; }
    }
}
namespace {
    require dirname(__DIR__, 8) . '/vendor/autoload.php';
    use Weline\Framework\Runtime\ScopeIdentity;
    use Weline\SystemConfig\Api\Scope\ScopeContext;
    use Weline\Theme\Api\Scoped\ThemeEditorContext;
    use Weline\Theme\Model\ThemeScopeVersion;
    use Weline\Theme\Service\ThemeScopeVersionService;
    use Weline\Theme\Service\ThemeChromeWidgetRemovalService;
    use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;
    use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionContract;
    use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityChrome;
    use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore;
    use Weline\Theme\Service\LayoutEntity\EntityRenderBinding;

    $uid = str_repeat('a', 32);
    $other = str_repeat('b', 32);
    $nodes = [
        $uid => ['node_uid' => $uid, 'area' => 'footer', 'slot_id' => 'footer-help-links', 'widget_module' => 'Weline_Test', 'widget_type' => 'footer', 'widget_code' => 'help', 'is_active' => true],
        $other => ['node_uid' => $other, 'area' => 'footer', 'slot_id' => 'footer-help-links', 'widget_module' => 'Weline_Test', 'widget_type' => 'footer', 'widget_code' => 'account', 'config' => ['title' => 'kept']],
    ];
    $service = new ThemeScopeVersionService();
    $service->published = new ThemeScopeVersion(9, 'shop.__website__.default', $nodes, true);
    $service->current = $argv[1] === 'inherited' ? null : new ThemeScopeVersion(10, 'shop.store.channel', $nodes, $argv[1] === 'published');
    if (in_array($argv[1], ['absent', 'missing-binding'], true)) { unset($service->current->nodes[$uid]); }
    $headerUid = str_repeat('c', 32);
    if (in_array($argv[1], ['partial', 'nearer-slot', 'empty'], true)) {
        $service->current->nodes = $argv[1] === 'empty' ? [] : [$headerUid => ['node_uid' => $headerUid, 'area' => 'header', 'slot_id' => 'header', 'widget_module' => 'Weline_Test', 'widget_type' => 'header', 'widget_code' => 'logo', 'config' => ['title' => 'local-header']]];
    }
    if ($argv[1] === 'removed') { $service->current->nodes[$uid]['is_active'] = false; $service->current->nodes[$uid]['source'] = 'user_deleted'; }
    $history = serialize($service->published);
    $currentHistory = $service->current?->published ? serialize($service->current) : null;
    $oldCurrent = $service->current;
    $scope = new ScopeContext(ScopeIdentity::channel(1, 'shop', 'store', 'channel', 'normal'), 'shop.store.channel', 'normal', ['shop.store.channel', 'shop.__website__.default']);
    $context = new ThemeEditorContext($scope, 'frontend', themeId: 3, layoutType: 'product');
    $chrome = new ThemeLayoutEntityChrome();
    $chrome->missing = $argv[1] === 'missing-binding';
    $configs = new ThemeLayoutEntityConfigStore();
    $binding = static fn(string $scope): EntityRenderBinding => new EntityRenderBinding(3, $scope, '', '9', 's', 'c', 'chrome', '', '', '', '', '');
    $chrome->sources = [['binding' => $binding('shop.__website__.default')]];
    $configs->nodes['shop.__website__.default'] = $nodes;
    if ($argv[1] === 'nearer-slot') {
        array_unshift($chrome->sources, ['binding' => $binding('shop.store.default')]);
        $configs->nodes['shop.store.default'] = [$other => $nodes[$other]];
    }
    $result = (new ThemeChromeWidgetRemovalService($service, new ThemeLayoutEntityBakeCoordinator(), $chrome, $configs))->remove($context, $uid, 'backend-user:1');
    $current = $service->current;
    $bySlot = ['footer-help-links' => array_values($current?->nodes ?? [])];
    $declarations = [['module' => 'Weline_Test', 'type' => 'footer', 'code' => 'help', 'default_injections' => [['layout_type' => 'homepage', 'slot' => 'footer-help-links', 'area' => 'footer', 'required' => true]]]];
    $merged = RequiredDefaultInjectionContract::merge($bySlot, 'homepage', $declarations, []);
    $rebaked = array_values(array_filter($merged['footer-help-links'], fn(array $node): bool => $node['widget_code'] === 'help'));
    echo json_encode([
        'result' => $result,
        'history_before' => [$history, $currentHistory],
        'history_after' => [serialize($service->published), $currentHistory !== null ? serialize($oldCurrent) : null],
        'current_id' => $current?->id,
        'active' => $current?->nodes[$uid]['is_active'] ?? null,
        'source' => $current?->nodes[$uid]['source'] ?? null,
        'rebaked_active' => $rebaked[0]['is_active'] ?? null,
        'owner_scope' => $current?->scope,
        'local_header' => $current?->nodes[$headerUid]['config']['title'] ?? null,
        'other_config' => $current?->nodes[$other]['config']['title'] ?? null,
    ], JSON_THROW_ON_ERROR);
}
