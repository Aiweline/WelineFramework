<?php
declare(strict_types=1);

// Persistence doubles supply immutable heads and current selections. The real
// removal service, patch engine and uninstall merger execute below.
namespace Weline\Framework\Manager {
    class ObjectManager { public static function getInstance(string $class): object { return new class { public function __call($name,$args) { return ''; } }; } }
}
namespace Weline\Theme\Api\Scoped {
    interface ThemeScopedWorkspaceInterface { public function load($context, $draft): array; public function applyChanges($context, $revision, $parent, $changes, $actor, $actorName = '', $summary = ''): array; }
}
namespace Weline\Theme\Model {
    class ThemeScopeVersion {
        public int $revision = 1;
        public function __construct(public int $id, public string $scope, public array $nodes, public bool $published = false) {}
        public function getVersionId(): int { return $this->id; }
        public function getThemeId(): int { return 3; }
        public function getScope(): string { return $this->scope; }
        public function getContentRevision(): int { return $this->revision; }
        public function getChromePayload(): array { return $this->nodes; }
        public function toVersionIdentity(): \Weline\Theme\Api\Version\ThemeVersionIdentity { return new \Weline\Theme\Api\Version\ThemeVersionIdentity(3,$this->scope,'normal','frontend',$this->id,$this->published?'formal':'draft',$this->revision); }
    }
}
namespace Weline\Theme\Service {
    class ThemeScopeVersionService {
        public ?\Weline\Theme\Model\ThemeScopeVersion $current;
        public \Weline\Theme\Model\ThemeScopeVersion $published;
        public function getCurrent(...$args) { return $this->current; }
        public function getPublished(...$args) { return $this->published; }
        public function invalidateOwner(...$args): void {}
    }
}
namespace Weline\Theme\Service\Version {
    class ThemeVersionResourceSnapshotService {
        public function __construct(private object $versions) {}
        public function head($identity): array {
            $version = $this->versions->current?->id === $identity->themeVersionId ? $this->versions->current : $this->versions->published;
            return ['chrome_intent_json'=>json_encode($version->nodes),'package_default_json'=>'{"omissions":{"homepage":[]}}'];
        }
    }
}
namespace Weline\Theme\Service\LayoutEntity {
    class RequiredDefaultInjectionBakeMerger { public function mergeIntoNodes($nodes,...$args): array { return $nodes; } }
    class ThemeLayoutSlotTreeBuilder { public function filterChromeNodes($nodes): array { return array_filter($nodes,fn($n)=>in_array($n['area'],['header','footer'],true)); } }
}
namespace {
    $theme=dirname(__DIR__,4);
    require dirname(__DIR__,8).'/vendor/autoload.php';
    foreach (['Api/Version/ThemeVersionIdentity.php','Api/Scoped/ThemePatchCommand.php','Service/Scoped/ThemePatchEngine.php','Service/LayoutEntity/ThemeLayoutEntityOwnerLock.php','Service/LayoutEntity/RequiredDefaultInjectionContract.php','Service/ThemeChromeWidgetRemovalService.php'] as $file) { require_once $theme.'/'.$file; }
    use Weline\Framework\Runtime\ScopeIdentity;
    use Weline\SystemConfig\Api\Scope\ScopeContext;
    use Weline\Theme\Api\Scoped\ThemeEditorContext;
    use Weline\Theme\Api\Scoped\ThemePatchCommand;
    use Weline\Theme\Model\ThemeScopeVersion;
    use Weline\Theme\Service\ThemeScopeVersionService;
    use Weline\Theme\Service\ThemeChromeWidgetRemovalService;
    use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionContract;
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
    if ($argv[1] === 'partial') { $service->current->nodes += $nodes; }
    if ($argv[1] === 'nearer-slot') { $service->current->nodes += [$other => $nodes[$other]]; }
    $history = serialize($service->published);
    $currentHistory = $service->current?->published ? serialize($service->current) : null;
    $oldCurrent = $service->current;
    $scope = new ScopeContext(ScopeIdentity::channel(1, 'shop', 'store', 'channel', 'normal'), 'shop.store.channel', 'normal', ['shop.store.channel', 'shop.__website__.default']);
    $context = new ThemeEditorContext($scope, 'frontend', themeId: 3, layoutType: 'product');
    $workspace = new class($service) implements \Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface {
        public function __construct(private object $versions) {}
        public function load($context,$draft): array { return ['draft_payload'=>['nodes'=>$this->versions->current?->nodes??[]], 'revision'=>1,'expected_parent_release_id'=>null]; }
        public function applyChanges($context,$revision,$parent,$changes,$actor,$actorName='',$summary=''): array {
            $current=$this->versions->current;
            if ($current===null || $current->published) { $current=new ThemeScopeVersion(11,$context->scope->storageScope,$current?->nodes??[]); $this->versions->current=$current; }
            $payload=(new \Weline\Theme\Service\Scoped\ThemePatchEngine())->apply(['nodes'=>$current->nodes],array_map([ThemePatchCommand::class,'fromArray'],$changes));
            $current->nodes=$payload['nodes']; ++$current->revision;
            return ['theme_version_id'=>$current->id,'content_revision'=>$current->revision,'draft_payload'=>$payload];
        }
    };
    $result=(new ThemeChromeWidgetRemovalService($service,$workspace,new \Weline\Theme\Service\Version\ThemeVersionResourceSnapshotService($service),new \Weline\Theme\Service\LayoutEntity\ThemeLayoutSlotTreeBuilder(),new \Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionBakeMerger()))->remove($context,$uid,'backend-user:1');
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
        'content_revision' => $current?->getContentRevision(),
        'active' => $current?->nodes[$uid]['is_active'] ?? null,
        'source' => $current?->nodes[$uid]['source'] ?? null,
        'rebaked_active' => $rebaked[0]['is_active'] ?? null,
        'owner_scope' => $current?->scope,
        'local_header' => $current?->nodes[$headerUid]['config']['title'] ?? null,
        'other_config' => $current?->nodes[$other]['config']['title'] ?? null,
    ], JSON_THROW_ON_ERROR);
}
