<?php
declare(strict_types=1);
namespace Weline\Theme\Service\Scoped;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityOwnerLock;
use Weline\Theme\Service\ThemeScopeVersionService;

/** Compatibility form endpoints use the same persisted resource commands as scoped editing. */
final class ThemeScopedEditorSaveService
{
    public function __construct(private readonly ThemeScopedWorkspaceInterface $workspace) {}

    public function saveConfig(ThemeEditorContext $context, array $config, array $input, string $actorId, string $actorName = ''): array
    {
        $prefix = $context->resourceType === ThemeEditorContext::RESOURCE_I18N ? '/translations/layout/' : '/values/';
        $changes = [];
        foreach ($config as $name => $value) { $changes[] = ['op' => 'set', 'path' => $prefix . str_replace(['~', '/'], ['~0', '~1'], (string)$name), 'value' => $value]; }
        return $this->save($context, $changes, $input, $actorId, $actorName);
    }

    public function saveSelection(ThemeEditorContext $context, string $option, array $input, string $actorId, string $actorName = ''): array
    {
        $context = new ThemeEditorContext($context->scope, $context->area, 'layout', $context->themeId,
            $context->layoutType, 'default', 'default', $context->targetType, $context->targetId);
        return $this->save($context, [['op' => 'set', 'path' => '/selection/layout_option', 'value' => $option]], $input, $actorId, $actorName);
    }

    public function save(ThemeEditorContext $context, array $changes, array $input, string $actorId, string $actorName = ''): array
    {
        return $this->withCurrentState($context, $input, function (array $state) use ($context, $changes, $input, $actorId, $actorName): array {
            return $this->workspace->applyChanges($context,
                (int)($input['expected_revision'] ?? $state['revision'] ?? 0),
                array_key_exists('expected_parent_release_id', $input) ? $input['expected_parent_release_id'] : ($state['expected_parent_release_id'] ?? null),
                $changes, $actorId, $actorName, 'editor_form_save');
        });
    }

    /** A full form is one canonical replacement, including an explicit empty page. */
    public function saveLayout(ThemeEditorContext $context, array $layoutData, array $input, string $actorId, string $actorName = ''): array
    {
        return $this->withCurrentState($context, $input, function (array $state) use ($context, $layoutData, $input, $actorId, $actorName): array {
            $snapshot = [];
            foreach ($layoutData as $area => $areaData) {
                if (!is_array($areaData)) { continue; }
                $widgets = is_array($areaData['widgets'] ?? null) ? $areaData['widgets'] : $areaData;
                $snapshot[$area] = ['widgets' => []];
                foreach ($widgets as $index => $widget) {
                    if (!is_array($widget)) { continue; }
                    $widget['sort_order'] ??= (int)$index;
                    $snapshot[$area]['widgets'][] = $widget;
                }
            }
            $target = ObjectManager::getInstance(ThemeLayoutSnapshotNormalizer::class)->normalize($context, $snapshot);
            if ($target['nodes'] === []) {
                // Clearing page placements does not uninstall shared partials.
                // Keep exact tombstones so later default collection cannot revive them.
                $chrome = new \Weline\Theme\Service\SharedChromeService($this->workspace);
                $target = array_replace((array)($state['draft_payload'] ?? []), $target);
                foreach ((array)($state['draft_payload']['nodes'] ?? []) as $uid => $node) {
                    if (!is_array($node)) { continue; }
                    if (!$chrome->isChromeTarget((string)($node['area'] ?? ''), $node['slot_id'] ?? null)) {
                        $node['is_active'] = false;
                        $node['source'] = 'user_deleted';
                    }
                    $target['nodes'][$uid] = $node;
                }
                $uid = substr(hash('sha256', 'no-widget-placements:' . $context->identityHash()), 0, 32);
                $target['nodes'][$uid] = [
                    'node_uid' => $uid, 'area' => 'content', 'slot_id' => null,
                    'widget_module' => 'Weline_Theme', 'widget_type' => 'layout_state', 'widget_code' => '__no_widget_placements__',
                    'config' => ['no_widget_placements' => true], 'sort_order' => 0, 'is_active' => false,
                ];
            } else {
                // Legacy placement forms do not carry removal records or shared
                // partials. Omission is not a request to restore template defaults.
                $chrome = new \Weline\Theme\Service\SharedChromeService($this->workspace);
                foreach ((array)($state['draft_payload']['nodes'] ?? []) as $uid => $node) {
                    if (!is_array($node) || array_key_exists($uid, $target['nodes'])) { continue; }
                    if ($chrome->isChromeTarget((string)($node['area'] ?? ''), $node['slot_id'] ?? null)
                        || !($node['is_active'] ?? true)
                        || str_starts_with((string)($node['source'] ?? ''), 'user_deleted')
                        || ($node['widget_code'] ?? '') === '__no_widget_placements__'
                    ) {
                        $target['nodes'][$uid] = $node;
                    }
                }
            }
            return $this->workspace->replaceEffectivePayload($context,
                (int)($input['expected_revision'] ?? $state['revision'] ?? 0),
                array_key_exists('expected_parent_release_id', $input) ? $input['expected_parent_release_id'] : ($state['expected_parent_release_id'] ?? null),
                $target, $actorId, $actorName, 'editor_full_layout_save');
        });
    }

    private function withCurrentState(ThemeEditorContext $context, array $input, callable $write): array
    {
        $owner = new ThemeVersionIdentity($context->themeId, $context->scope->storageScope, $context->scope->storeMode, $context->area);
        return ThemeLayoutEntityOwnerLock::write($owner, function () use ($context, $input, $write): array {
            if (isset($input['expected_content_revision'])) {
                $version = ObjectManager::getInstance(ThemeScopeVersionService::class)->getCurrent($context->themeId, $context->scope->storageScope, $context->scope->storeMode, $context->area);
                if ($version !== null) { $version = (clone $version)->clearData()->clearQuery()->load($version->getVersionId()); }
                if (($version?->getContentRevision() ?? 0) !== (int)$input['expected_content_revision']) {
                    throw new \RuntimeException('theme_version_content_revision_conflict:actual=' . ($version?->getContentRevision() ?? 0));
                }
            }
            $saved = $write($this->workspace->load($context, true));
            return $saved + ['context' => $context->toArray()];
        });
    }
}
