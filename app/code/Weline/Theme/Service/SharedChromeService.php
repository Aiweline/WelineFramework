<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemePatchCommand;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\ThemeLayout;

/**
 * 页头/页脚全局 chrome：默认继承站点一套；本布局显式脱离后才独立。
 *
 * 持久化载体仍是 homepage workspace（存储载体，不是归属）。
 * 语义：本布局 chrome 区无本地节点 = inherit；有本地节点 = local。
 */
final class SharedChromeService
{
    public const MODE_INHERIT = 'inherit';
    public const MODE_LOCAL = 'local';

    /** @var list<string> */
    public const CHROME_AREAS = ['header', 'footer'];

    /** @var list<string> */
    public const CHROME_SLOTS = ['header', 'footer'];

    public function __construct(
        private readonly ThemeScopedWorkspaceInterface $workspace,
    ) {
    }

    public function isChromeCarrierPageType(string $pageType): bool
    {
        return \trim($pageType) === ThemeLayout::PAGE_TYPE_HOME;
    }

    public function isChromeArea(string $area): bool
    {
        return \in_array(\strtolower(\trim($area)), self::CHROME_AREAS, true);
    }

    public function isChromeSlot(?string $slotId): bool
    {
        $slotId = \strtolower(\trim((string)$slotId));
        if ($slotId === '') {
            return false;
        }
        if (\in_array($slotId, self::CHROME_SLOTS, true)) {
            return true;
        }

        // Nested chrome slots stay under the same global chrome ownership model.
        foreach (self::CHROME_SLOTS as $root) {
            if ($slotId === $root || \str_starts_with($slotId, $root . '-') || \str_starts_with($slotId, $root . '_')) {
                return true;
            }
        }

        return false;
    }

    public function isChromeTarget(?string $area, ?string $slotId): bool
    {
        return $this->isChromeArea((string)$area) || $this->isChromeSlot($slotId);
    }

    /**
     * @return array{
     *   page_type:string,
     *   is_carrier:bool,
     *   slots:array<string,array{mode:string,local_node_count:int}>,
     *   overall_mode:string
     * }
     */
    public function resolveModes(ThemeEditorContext $pageContext): array
    {
        $pageContext = $pageContext->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        $pageType = $pageContext->layoutType;
        $isCarrier = $this->isChromeCarrierPageType($pageType);
        $localNodes = $this->chromeNodesFromState($this->workspace->load($pageContext, true));

        $slots = [];
        foreach (self::CHROME_AREAS as $area) {
            $count = 0;
            foreach ($localNodes as $node) {
                if ($this->nodeBelongsToChromeArea($node, $area)) {
                    ++$count;
                }
            }
            // Hard cut: non-carrier layouts always inherit global chrome (MODE_LOCAL removed).
            $slots[$area] = [
                'mode' => $isCarrier ? self::MODE_LOCAL : self::MODE_INHERIT,
                'local_node_count' => $count,
            ];
        }

        return [
            'page_type' => $pageType,
            'is_carrier' => $isCarrier,
            'slots' => $slots,
            'overall_mode' => $isCarrier ? self::MODE_LOCAL : self::MODE_INHERIT,
            'carrier_page_type' => ThemeLayout::PAGE_TYPE_HOME,
        ];
    }

    /**
     * 继承态 chrome 写操作应落到全局载体（homepage），并同步 ThemeScopeVersion chrome 权威。
     * 非载体布局永久 inherit，因此 chrome 写一律路由到 homepage。
     */
    public function resolveWriteLayoutType(
        ThemeEditorContext $pageContext,
        ?string $area,
        ?string $slotId,
    ): string {
        $pageType = $pageContext->layoutType;
        if ($this->isChromeCarrierPageType($pageType) || !$this->isChromeTarget($area, $slotId)) {
            return $pageType;
        }

        $modes = $this->resolveModes($pageContext);
        $chromeArea = $this->isChromeArea((string)$area)
            ? \strtolower(\trim((string)$area))
            : $this->inferChromeAreaFromSlot($slotId);
        if ($chromeArea === null) {
            return $pageType;
        }

        $slotMode = (string)($modes['slots'][$chromeArea]['mode'] ?? self::MODE_INHERIT);
        return $slotMode === self::MODE_INHERIT ? ThemeLayout::PAGE_TYPE_HOME : $pageType;
    }

    /**
     * Detach (MODE_LOCAL) permanently removed — shared chrome is always inherit for non-carriers.
     *
     * @param list<string>|null $areas
     * @return array{copied:int,areas:list<string>,workspace:array<string,mixed>|null}
     */
    public function detach(
        ThemeEditorContext $pageContext,
        ?array $areas,
        string $actorId,
        string $actorName = '',
    ): array {
        throw new \InvalidArgumentException('shared_chrome_detach_removed');
    }

    /**
     * 清空非载体布局上的本地 chrome，恢复默认全局继承。
     *
     * @param list<string>|null $pageTypes null = ThemeLayout::getPageTypes() 全部（跳过载体与 dashboard）
     * @param list<string>|null $areas
     * @return array{
     *   restored_layouts:int,
     *   removed_nodes:int,
     *   layouts:list<array{page_type:string,removed:int}>,
     *   mode:string
     * }
     */
    public function restoreNonCarrierLayouts(
        ThemeEditorContext $baseContext,
        ?array $pageTypes,
        ?array $areas,
        string $actorId,
        string $actorName = '',
    ): array {
        $baseContext = $baseContext->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        if ($pageTypes === null || $pageTypes === []) {
            $pageTypes = \array_keys(ThemeLayout::getPageTypes());
        }

        $layouts = [];
        $restoredLayouts = 0;
        $removedNodes = 0;
        foreach ($pageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType === ''
                || $this->isChromeCarrierPageType($pageType)
                || $pageType === ThemeLayout::PAGE_TYPE_DASHBOARD
            ) {
                continue;
            }

            try {
                $result = $this->restore(
                    $baseContext->withLayoutType($pageType),
                    $areas,
                    $actorId,
                    $actorName,
                );
            } catch (\Throwable) {
                continue;
            }

            $removed = (int)($result['removed'] ?? 0);
            if ($removed > 0) {
                ++$restoredLayouts;
                $removedNodes += $removed;
            }
            $layouts[] = [
                'page_type' => $pageType,
                'removed' => $removed,
            ];
        }

        return [
            'restored_layouts' => $restoredLayouts,
            'removed_nodes' => $removedNodes,
            'layouts' => $layouts,
            'mode' => self::MODE_INHERIT,
        ];
    }

    /**
     * 发版本覆盖：清空非载体本地 chrome，并把清理后的草稿发布，避免 published 仍残留旧头尾。
     *
     * @param list<string>|null $pageTypes
     * @param list<string>|null $areas
     * @return array{
     *   restored_layouts:int,
     *   removed_nodes:int,
     *   published_layouts:int,
     *   layouts:list<array{page_type:string,removed:int,published:bool}>,
     *   mode:string
     * }
     */
    public function forceInheritAndPublishNonCarriers(
        ThemeEditorContext $baseContext,
        ?array $pageTypes,
        ?array $areas,
        string $actorId,
        string $actorName = '',
        string $reason = 'shared_chrome_force_inherit_publish',
    ): array {
        $baseContext = $baseContext->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        $restore = $this->restoreNonCarrierLayouts($baseContext, $pageTypes, $areas, $actorId, $actorName);
        $publishedLayouts = 0;
        $layouts = [];

        foreach ($restore['layouts'] as $layout) {
            $pageType = (string)($layout['page_type'] ?? '');
            $removed = (int)($layout['removed'] ?? 0);
            $published = false;
            if ($pageType === '') {
                continue;
            }

            $pageContext = $baseContext->withLayoutType($pageType);
            $needsPublish = $removed > 0;
            if (!$needsPublish) {
                // 草稿已空但 published 仍含本地 chrome 时也必须覆盖发布。
                try {
                    $state = $this->workspace->load($pageContext, true);
                    $needsPublish = $this->chromeNodesFromPayload(
                        \is_array($state['published_payload'] ?? null) ? $state['published_payload'] : [],
                    ) !== [];
                } catch (\Throwable) {
                    $needsPublish = false;
                }
            }

            if ($needsPublish) {
                try {
                    $state = $this->workspace->load($pageContext, true);
                    $revision = (int)($state['revision'] ?? 0);
                    if ($revision > 0) {
                        $this->workspace->publish(
                            context: $pageContext,
                            expectedRevision: $revision,
                            expectedParentReleaseId: $this->nullableReleaseId(
                                $state['expected_parent_release_id'] ?? null,
                            ),
                            actorId: $actorId,
                            actorName: $actorName,
                            reason: $reason,
                        );
                        $published = true;
                        ++$publishedLayouts;
                    }
                } catch (\Throwable) {
                    $published = false;
                }
            }

            $layouts[] = [
                'page_type' => $pageType,
                'removed' => $removed,
                'published' => $published,
            ];
        }

        return [
            'restored_layouts' => (int)($restore['restored_layouts'] ?? 0),
            'removed_nodes' => (int)($restore['removed_nodes'] ?? 0),
            'published_layouts' => $publishedLayouts,
            'layouts' => $layouts,
            'mode' => self::MODE_INHERIT,
        ];
    }

    /**
     * 清空本布局 chrome 占用，恢复跟随全局。
     *
     * @param list<string>|null $areas
     * @return array{removed:int,areas:list<string>,workspace:array<string,mixed>|null}
     */
    public function restore(
        ThemeEditorContext $pageContext,
        ?array $areas,
        string $actorId,
        string $actorName = '',
    ): array {
        $pageContext = $pageContext->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        if ($this->isChromeCarrierPageType($pageContext->layoutType)) {
            throw new \InvalidArgumentException('shared_chrome_carrier_cannot_restore');
        }

        $areas = $this->normalizeAreas($areas);
        $pageState = $this->workspace->load($pageContext, true);
        $commands = [];
        $removed = 0;
        foreach ($this->chromeNodesFromState($pageState) as $uid => $node) {
            if (!$this->nodeBelongsToAreas($node, $areas)) {
                continue;
            }
            $commands[] = ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_REMOVE_NODE,
                'path' => '/nodes/' . $uid,
                'node_uid' => $uid,
            ]);
            ++$removed;
        }

        $workspace = null;
        if ($commands !== []) {
            $workspace = $this->workspace->applyChanges(
                context: $pageContext,
                expectedRevision: (int)($pageState['revision'] ?? 0),
                expectedParentReleaseId: $this->nullableReleaseId($pageState['expected_parent_release_id'] ?? null),
                changes: $commands,
                actorId: $actorId,
                actorName: $actorName,
                summary: 'shared_chrome_restored',
            );
        }

        return [
            'removed' => $removed,
            'areas' => $areas,
            'workspace' => $workspace,
            'mode' => self::MODE_INHERIT,
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,array<string,mixed>>
     */
    private function chromeNodesFromState(array $state): array
    {
        return $this->chromeNodesFromPayload(
            \is_array($state['draft_payload'] ?? null) ? $state['draft_payload'] : [],
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,array<string,mixed>>
     */
    private function chromeNodesFromPayload(array $payload): array
    {
        $nodes = \is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
        $chrome = [];
        foreach ($nodes as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $uid = \strtolower(\trim((string)$uid));
            if ($uid === '' || \preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
                continue;
            }
            $area = \strtolower(\trim((string)($node['area'] ?? '')));
            $slotId = isset($node['slot_id']) ? (string)$node['slot_id'] : null;
            if (!$this->isChromeTarget($area, $slotId)) {
                continue;
            }
            // Skip layout-state markers.
            if ((string)($node['widget_code'] ?? '') === '__no_widget_placements__') {
                continue;
            }
            $chrome[$uid] = $node;
        }

        return $chrome;
    }

    /** @param array<string,mixed> $node */
    private function nodeBelongsToChromeArea(array $node, string $area): bool
    {
        $area = \strtolower(\trim($area));
        $nodeArea = \strtolower(\trim((string)($node['area'] ?? '')));
        if ($nodeArea === $area) {
            return true;
        }
        $slotId = \strtolower(\trim((string)($node['slot_id'] ?? '')));
        return $slotId === $area
            || \str_starts_with($slotId, $area . '-')
            || \str_starts_with($slotId, $area . '_');
    }

    /**
     * @param array<string,mixed> $node
     * @param list<string> $areas
     */
    private function nodeBelongsToAreas(array $node, array $areas): bool
    {
        foreach ($areas as $area) {
            if ($this->nodeBelongsToChromeArea($node, $area)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string>|null $areas
     * @return list<string>
     */
    private function normalizeAreas(?array $areas): array
    {
        if ($areas === null || $areas === []) {
            return self::CHROME_AREAS;
        }
        $normalized = [];
        foreach ($areas as $area) {
            $area = \strtolower(\trim((string)$area));
            if ($this->isChromeArea($area)) {
                $normalized[] = $area;
            }
        }
        $normalized = \array_values(\array_unique($normalized));

        return $normalized !== [] ? $normalized : self::CHROME_AREAS;
    }

    private function inferChromeAreaFromSlot(?string $slotId): ?string
    {
        $slotId = \strtolower(\trim((string)$slotId));
        foreach (self::CHROME_AREAS as $area) {
            if ($slotId === $area || \str_starts_with($slotId, $area . '-') || \str_starts_with($slotId, $area . '_')) {
                return $area;
            }
        }

        return null;
    }

    private function nullableReleaseId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }
}
