<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityChrome;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore;
use Weline\Theme\Service\LayoutEntity\EntityRenderBinding;

/** Explicit editor removals belong to the selected chrome draft, never an ancestor publication. */
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
        $alreadyRemoved = empty($nodes[$nodeUid]['is_active'])
            && ($nodes[$nodeUid]['source'] ?? '') === 'user_deleted';
        if (!$alreadyRemoved) {
            // Keep the instance identity as the existing inactive-node uninstall
            // decision: required-default merging cannot bring this instance back.
            $nodes[$nodeUid]['is_active'] = false;
            $nodes[$nodeUid]['source'] = 'user_deleted';
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
        } elseif ($current->isPublished()) {
            $current = $this->versions->createRevisionFrom($current, actor: $actor);
        }
        if (!$alreadyRemoved || $current->getChromePayload() !== $nodes) {
            $this->versions->setChromePayload($current, $nodes);
        }
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
            if (!$binding instanceof EntityRenderBinding || $binding->themeId !== $context->themeId) {
                continue;
            }
            // Current DB payload is canonical even if its derived binding has not
            // been rebuilt yet; never substitute an older same-scope sidecar.
            if ($current !== null && $binding->scope === $context->scope->storageScope) {
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
