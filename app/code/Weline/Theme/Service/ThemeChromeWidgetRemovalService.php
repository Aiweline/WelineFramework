<?php
declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionBakeMerger;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionContract;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityOwnerLock;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSlotTreeBuilder;
use Weline\Theme\Service\Version\ThemeScopeVersionWidgetDecisionService;
use Weline\Theme\Service\Version\ThemeVersionResourceSnapshotService;

/** Shared chrome removal is a canonical homepage-carrier resource save. */
final class ThemeChromeWidgetRemovalService
{
    public function __construct(
        private readonly ThemeScopeVersionService $versions,
        private readonly ThemeScopedWorkspaceInterface $workspace,
        private readonly ThemeVersionResourceSnapshotService $snapshots,
        private readonly ThemeLayoutSlotTreeBuilder $slotTree,
        private readonly RequiredDefaultInjectionBakeMerger $defaults,
    ) {}

    public function remove(ThemeEditorContext $context, string $nodeUid, string $actor = ''): ?array
    {
        $nodeUid = strtolower(trim($nodeUid));
        if (preg_match('/^[a-f0-9]{32}$/D', $nodeUid) !== 1) { return null; }
        $owner = new ThemeVersionIdentity($context->themeId, $context->scope->storageScope, $context->scope->storeMode, $context->area);
        return ThemeLayoutEntityOwnerLock::write($owner, function () use ($context, $nodeUid, $actor): ?array {
            $carrier = new ThemeEditorContext($context->scope, $context->area, ThemeEditorContext::RESOURCE_LAYOUT,
                $context->themeId, 'homepage', 'default');
            $current = $this->versions->getCurrent($context->themeId, $context->scope->storageScope, $context->scope->storeMode, $context->area)
                ?? $this->versions->getPublished($context->themeId, $context->scope->storageScope, $context->scope->storeMode, $context->area);
            $head = $current === null ? null : $this->snapshots->head($current->toVersionIdentity());
            $chrome = $head === null ? ($current?->getChromePayload() ?? [])
                : (array)json_decode((string)($head['chrome_intent_json'] ?? '[]'), true);
            $descriptor = json_decode((string)($head['package_default_json'] ?? '{}'), true);
            $chrome = $this->slotTree->filterChromeNodes($this->defaults->mergeIntoNodes($chrome, $context->themeId,
                'homepage', $current?->getVersionId(), [], (array)($descriptor['omissions']['homepage'] ?? [])));
            if (!is_array($chrome[$nodeUid] ?? null)) { return null; }

            $state = $this->workspace->load($carrier, true);
            $nodes = (array)($state['draft_payload']['nodes'] ?? []);
            $alreadyRemoved = empty($chrome[$nodeUid]['is_active'])
                && RequiredDefaultInjectionContract::isUninstallSource((string)($chrome[$nodeUid]['source'] ?? ''));
            // A tombstone owns the instance even when the inherited/default node
            // has never had a local workspace row. Copy its visible siblings so
            // the shared slot keeps the same effective contents.
            $chrome[$nodeUid]['is_active'] = false;
            $chrome[$nodeUid]['source'] = 'user_deleted';
            $changes = [];
            foreach ($chrome as $uid => $node) {
                if (($nodes[$uid] ?? null) !== $node) {
                    $changes[] = ['op' => 'set', 'path' => '/nodes/' . $uid, 'value' => $node];
                }
            }
            if ($changes !== []) {
                $saved = $this->workspace->applyChanges($carrier, (int)$state['revision'], $state['expected_parent_release_id'] ?? null,
                    $changes, $actor !== '' ? $actor : 'theme-editor', '', 'Remove shared chrome widget');
            } else {
                $saved = $state;
            }
            $this->versions->invalidateOwner($context->themeId, $context->scope->storageScope, $context->scope->storeMode, $context->area);
            $target = $this->versions->getCurrent($context->themeId, $context->scope->storageScope, $context->scope->storeMode, $context->area);
            if ($target !== null) { $this->persistTargetVersionDecision($context, $target, $chrome[$nodeUid], $nodeUid, $actor); }
            return ['status' => $alreadyRemoved ? 'already_absent' : 'removed', 'resource_type' => 'chrome',
                'scope' => $context->scope->storageScope, 'version_id' => (int)($saved['theme_version_id'] ?? $target?->getVersionId() ?? 0),
                'content_revision' => (int)($saved['content_revision'] ?? $target?->getContentRevision() ?? 0),
                'node_uid' => $nodeUid, 'workspace' => $saved];
        });
    }

    private function persistTargetVersionDecision(
        ThemeEditorContext $context,
        ThemeScopeVersion $current,
        array $node,
        string $nodeUid,
        string $actor,
    ): void {
        $versionId = $current->getVersionId();
        if ($versionId < 1) {
            return;
        }
        try {
            /** @var ThemeScopeVersionWidgetDecisionService $decisions */
            $decisions = ObjectManager::getInstance(ThemeScopeVersionWidgetDecisionService::class);
            $module = \trim((string)($node['widget_module'] ?? ''));
            $code = \trim((string)($node['widget_code'] ?? ''));
            $slot = \trim((string)($node['slot_id'] ?? ''));
            if ($slot === '') {
                $slot = \trim((string)($node['meta']['config']['slot'] ?? $node['meta']['slot'] ?? $node['area'] ?? ''));
            }
            $injectionKey = $slot . '|' . $module . '|' . $code;
            if ($code === '') {
                $injectionKey = 'chrome-node|' . $nodeUid;
            }
            $resourceHash = $decisions->chromeResourceIdentityHash(
                $context->themeId,
                $context->scope->storageScope,
                (string)$context->scope->storeMode,
                $context->area !== '' ? $context->area : 'frontend',
            );
            $decisions->recordUninstall(
                $versionId,
                \max(1, $current->getContentRevision()),
                $resourceHash,
                $injectionKey,
                $slot,
                $module !== '' || $code !== '' ? ($module . '|' . $code) : $nodeUid,
                $actor,
            );
        } catch (\Throwable) {
            // Chrome payload source remains the durable uninstall marker.
        }
    }

}
