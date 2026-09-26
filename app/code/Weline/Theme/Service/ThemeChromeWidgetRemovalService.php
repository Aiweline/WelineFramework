<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Service\LayoutEntity\EntityRenderBinding;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionContract;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityChrome;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore;
use Weline\Theme\Service\Version\ThemeScopeVersionWidgetDecisionService;

/** Explicit editor removals belong to the selected chrome draft target version, never an ancestor. */
final class ThemeChromeWidgetRemovalService
{
    public function __construct(
        private readonly ThemeScopeVersionService $versions,
        private readonly ThemeLayoutEntityBakeCoordinator $bake,
        private readonly ThemeLayoutEntityChrome $chrome,
        private readonly ThemeLayoutEntityConfigStore $configs,
    ) {
    }

    /** @return array{status:string,resource_type:string,scope:string,version_id:int,node_uid:string}|null */
    public function remove(ThemeEditorContext $context, string $nodeUid, string $actor = ''): ?array
    {
        $nodeUid = strtolower(trim($nodeUid));
        if (preg_match('/^[a-f0-9]{32}$/D', $nodeUid) !== 1) {
            return null;
        }
        $scope = $context->scope->storageScope;
        $current = $this->versions->getCurrent($context->themeId, $scope);
        $nodes = $this->resolveRemovalNodes($context, $current, $nodeUid);
        if ($nodes === null) {
            return null;
        }
        if (!$current instanceof ThemeScopeVersion) {
            // Copy the complete inherited owner slot before overriding locally.
            // Other slots keep following their existing scope inheritance.
            $current = $this->versions->ensureCurrent(
                $context->themeId,
                $scope,
                $context->scope->identity->scopeKind,
                $context->scope->identity->websiteId,
                $context->scope->storeMode,
            );
        } elseif ($current->isPublished() || $this->isSelectionPublished($context, $current, $scope)) {
            // 选择优先：v3 发布只维护 w_theme_scope_version_selection，**从不维护** legacy
            // is_published 标志。只读标志会把「selection 已发布、但标志未置位」的版本当成草稿，
            // 于是删除被直接写到**已发布版本**上，破坏「删除只落在目标草稿、已发布产物不受影响」。
            // （该标志此前靠 chrome ensure 的 bootstrap 顺手置位才碰巧同步，不能作为判据。）
            $current = $this->versions->createRevisionFrom($current, actor: $actor);
        }
        $versionId = $current->getVersionId();
        $deletedSource = RequiredDefaultInjectionContract::userDeletedSource($versionId);
        $alreadyRemoved = empty($nodes[$nodeUid]['is_active'])
            && RequiredDefaultInjectionContract::isUninstallSource((string)($nodes[$nodeUid]['source'] ?? ''))
            && (
                RequiredDefaultInjectionContract::matchesVersionUninstall(
                    (string)($nodes[$nodeUid]['source'] ?? ''),
                    $versionId,
                )
                || (string)($nodes[$nodeUid]['source'] ?? '') === 'user_deleted'
            );
        if (!$alreadyRemoved) {
            // Keep the instance identity as the existing inactive-node uninstall
            // decision keyed to the TARGET theme version (V).
            $nodes[$nodeUid]['is_active'] = false;
            $nodes[$nodeUid]['source'] = $deletedSource !== '' ? $deletedSource : 'user_deleted';
        }
        if (!$alreadyRemoved || $current->getChromePayload() !== $nodes) {
            $this->versions->setChromePayload($current, $nodes);
        }
        $this->persistTargetVersionDecision($context, $current, $nodes[$nodeUid] ?? [], $nodeUid, $actor);
        // Retrying an already removed node also repairs derived artifacts if the
        // earlier request persisted the draft but was interrupted during baking.
        $this->bake->bakeChromeFromNodes($context->themeId, $scope, $nodes, true, true);

        return [
            'status' => $alreadyRemoved ? 'already_absent' : 'removed',
            'resource_type' => 'chrome',
            'scope' => $scope,
            'version_id' => $current->getVersionId(),
            'node_uid' => $nodeUid,
        ];
    }

    /**
     * 该版本是否就是本 owner 的**已发布版本**（以 selection 为唯一权威）。
     *
     * 与 ThemeScopeVersionService::getCurrent()/getPublished() 的「选择优先」口径保持一致：
     * legacy `is_published` 标志在 v3 发布路径上不再维护，不能单独作为判据。
     */
    private function isSelectionPublished(
        ThemeEditorContext $context,
        ThemeScopeVersion $version,
        string $scope,
    ): bool {
        try {
            $published = $this->versions->getPublished(
                $context->themeId,
                $scope,
                (string)$context->scope->storeMode,
                $context->area !== '' ? $context->area : 'frontend',
            );
        } catch (\Throwable) {
            return false;
        }

        return $published instanceof ThemeScopeVersion
            && $published->getVersionId() === $version->getVersionId();
    }

    /**
     * @param array<string,mixed> $node
     */
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

    /** @return array<string,array<string,mixed>>|null */
    private function resolveRemovalNodes(ThemeEditorContext $context, ?ThemeScopeVersion $current, string $nodeUid): ?array
    {
        $nodes = $current?->getChromePayload() ?? [];
        if (is_array($nodes[$nodeUid] ?? null)) {
            return $nodes;
        }
        // A deliberately empty local version owns the empty chrome decision.
        if ($current !== null && $nodes === []) {
            return null;
        }
        $ownedSlots = array_fill_keys(array_keys($this->bySlot($nodes)), true);
        try {
            $sources = $this->chrome->resolveRenderSources($context->themeId, $context->scope->storageScope, true);
        } catch (\RuntimeException $error) {
            // Without a rendered chrome source there is no visible inherited
            // chrome owner; content/layout removal must still be able to proceed.
            if (str_starts_with($error->getMessage(), 'theme_layout_entity_chrome_missing:')) {
                return null;
            }
            throw $error;
        }
        foreach ($sources as $source) {
            $binding = $source['binding'] ?? null;
            if (!$binding instanceof EntityRenderBinding || $binding->identity->themeId !== $context->themeId) {
                continue;
            }
            // Current DB payload is canonical even if its derived binding has not
            // been rebuilt yet; never substitute an older same-scope sidecar.
            if ($current !== null && $binding->identity->canonicalScope === $context->scope->storageScope) {
                continue;
            }
            $sourceNodes = $this->configs->readBoundConfig($binding);
            foreach ($this->bySlot($sourceNodes) as $slot => $slotNodes) {
                if (isset($ownedSlots[$slot])) {
                    continue;
                }
                $ownedSlots[$slot] = true;
                if (isset($slotNodes[$nodeUid])) {
                    // Preserve every sibling in this inherited slot, plus all
                    // local slots. Otherwise deleting one link would hide peers.
                    return $nodes + $slotNodes;
                }
            }
            if ($sourceNodes === []) {
                break;
            }
        }
        return null;
    }

    /** @return array<string,array<string,array<string,mixed>>> */
    private function bySlot(array $nodes): array
    {
        $slots = [];
        foreach ($nodes as $key => $node) {
            if (!is_array($node)) {
                continue;
            }
            $slot = (string)($node['slot_id'] ?? '');
            if ($slot === '') {
                $slot = (string)($node['meta']['config']['slot'] ?? $node['meta']['slot'] ?? $node['area'] ?? '');
            }
            $uid = (string)($node['node_uid'] ?? $key);
            $slots[$slot][$uid] = $node;
        }
        return $slots;
    }
}
