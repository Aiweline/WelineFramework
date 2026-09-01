<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemePatchCommand;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\Scoped\ThemeLayoutSnapshotNormalizer;
use Weline\Theme\Service\TemplateInlineWidgetMerger;
use Weline\Theme\Service\WidgetImageContentContractValidator;

/** Scoped-only layout widget mutations (add/update/remove/move). */
final class ThemeScopedLayoutWriteService
{
    private const NO_PLACEMENTS_WIDGET_MODULE = 'Weline_Theme';
    private const NO_PLACEMENTS_WIDGET_TYPE = 'layout_state';
    private const NO_PLACEMENTS_WIDGET_CODE = '__no_widget_placements__';

    public function __construct(
        private readonly ThemeScopedWorkspaceInterface $workspace,
        private readonly WidgetImageContentContractValidator $imageValidator,
        private readonly ThemeLayoutSnapshotNormalizer $snapshotNormalizer,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     * @return array{node_uid:string,workspace:array<string,mixed>}
     */
    public function addWidget(
        ThemeEditorContext $context,
        array $data,
        string $actorId,
        string $actorName = '',
    ): array {
        $context = $context->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        $pageType = (string)($data['page_type'] ?? $context->layoutType);
        $area = (string)($data['area'] ?? ThemeLayout::AREA_CONTENT);
        $slotId = isset($data['slot_id']) && $data['slot_id'] !== '' ? (string)$data['slot_id'] : null;
        $exclusive = (bool)($data['exclusive'] ?? false);
        $sortOrder = (int)($data['sort_order'] ?? 0);
        $config = \is_array($data['config'] ?? null) ? $data['config'] : [];
        $isNoPlacementsMarker = (string)($data['widget_module'] ?? '') === self::NO_PLACEMENTS_WIDGET_MODULE
            && (string)($data['widget_type'] ?? '') === self::NO_PLACEMENTS_WIDGET_TYPE
            && (string)($data['widget_code'] ?? '') === self::NO_PLACEMENTS_WIDGET_CODE;

        if (!$isNoPlacementsMarker) {
            $this->imageValidator->validate([
                $area => [[
                    'widget_module' => (string)($data['widget_module'] ?? ''),
                    'widget_type' => (string)($data['widget_type'] ?? ''),
                    'widget_code' => (string)($data['widget_code'] ?? ''),
                    'page_type' => $pageType,
                    'target_type' => $context->targetType,
                    'config' => $config,
                ]],
            ], [
                'phase' => 'save',
                'page_type' => $pageType,
                'layout_area' => $area,
                'target_type' => $context->targetType,
            ]);
        }

        $nodeUid = $this->resolveNodeUid($data);
        if (\trim((string)($config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] ?? '')) === '') {
            $config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] = 'wi_' . $nodeUid;
        }

        $state = $this->workspace->load($context, true);
        $commands = [];
        $hasTemplateRef = \trim((string)($config[TemplateInlineWidgetMerger::CONFIG_TEMPLATE_REF] ?? '')) !== '';

        if ($exclusive && !$hasTemplateRef) {
            $commands = \array_merge(
                $commands,
                $this->removeNodesInSlot($state, $area, $slotId, (string)($data['widget_code'] ?? '')),
            );
        }

        if (!$exclusive) {
            $commands = \array_merge(
                $commands,
                $this->shiftSortOrders($state, $area, $slotId, $sortOrder, 1),
            );
        }

        if (!$isNoPlacementsMarker) {
            $commands = \array_merge($commands, $this->clearNoPlacementsMarker($state));
        }

        $node = [
            'node_uid' => $nodeUid,
            'area' => $area,
            'slot_id' => $slotId,
            'widget_code' => (string)($data['widget_code'] ?? ''),
            'widget_module' => (string)($data['widget_module'] ?? ''),
            'widget_type' => (string)($data['widget_type'] ?? ''),
            'config' => $config,
            'sort_order' => $sortOrder,
            'is_active' => (bool)($data['is_active'] ?? true),
        ];
        $commands[] = ThemePatchCommand::fromArray([
            'op' => ThemePatchCommand::OP_ADD_NODE,
            'path' => '/nodes/' . $nodeUid,
            'node_uid' => $nodeUid,
            'value' => $node,
        ]);

        $result = $this->workspace->applyChanges(
            context: $context,
            expectedRevision: (int)($state['revision'] ?? 0),
            expectedParentReleaseId: $this->nullableReleaseId($state['expected_parent_release_id'] ?? null),
            changes: $commands,
            actorId: $actorId,
            actorName: $actorName,
            summary: 'layout_node_added',
        );

        return ['node_uid' => $nodeUid, 'workspace' => $result];
    }

    /**
     * @param array<string,mixed> $config
     * @return array{node_uid:string,workspace:array<string,mixed>}
     */
    public function updateWidgetConfig(
        ThemeEditorContext $context,
        string $nodeUid,
        array $config,
        string $actorId,
        string $actorName = '',
    ): array {
        $context = $context->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        $nodeUid = $this->assertNodeUid($nodeUid);
        $state = $this->workspace->load($context, true);
        $this->assertNodeExists($state, $nodeUid);

        $result = $this->workspace->applyChanges(
            context: $context,
            expectedRevision: (int)($state['revision'] ?? 0),
            expectedParentReleaseId: $this->nullableReleaseId($state['expected_parent_release_id'] ?? null),
            changes: [ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_SET,
                'path' => '/nodes/' . $nodeUid . '/config',
                'value' => $config,
            ])],
            actorId: $actorId,
            actorName: $actorName,
            summary: 'layout_node_config_updated',
        );

        return ['node_uid' => $nodeUid, 'workspace' => $result];
    }

    /** @return array{node_uid:string,workspace:array<string,mixed>} */
    public function removeWidget(
        ThemeEditorContext $context,
        string $nodeUid,
        string $actorId,
        string $actorName = '',
    ): array {
        $context = $context->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        $nodeUid = $this->assertNodeUid($nodeUid);
        $state = $this->workspace->load($context, true);
        $this->assertNodeExists($state, $nodeUid);

        $result = $this->workspace->applyChanges(
            context: $context,
            expectedRevision: (int)($state['revision'] ?? 0),
            expectedParentReleaseId: $this->nullableReleaseId($state['expected_parent_release_id'] ?? null),
            changes: [ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_REMOVE_NODE,
                'path' => '/nodes/' . $nodeUid,
                'node_uid' => $nodeUid,
            ])],
            actorId: $actorId,
            actorName: $actorName,
            summary: 'layout_node_removed',
        );

        return ['node_uid' => $nodeUid, 'workspace' => $result];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{node_uid:string,workspace:array<string,mixed>}
     */
    public function moveWidget(
        ThemeEditorContext $context,
        string $nodeUid,
        string $newArea,
        int $sortOrder,
        string $actorId,
        string $actorName = '',
    ): array {
        $context = $context->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        $nodeUid = $this->assertNodeUid($nodeUid);
        $state = $this->workspace->load($context, true);
        $this->assertNodeExists($state, $nodeUid);

        $result = $this->workspace->applyChanges(
            context: $context,
            expectedRevision: (int)($state['revision'] ?? 0),
            expectedParentReleaseId: $this->nullableReleaseId($state['expected_parent_release_id'] ?? null),
            changes: [
                ThemePatchCommand::fromArray([
                    'op' => ThemePatchCommand::OP_SET,
                    'path' => '/nodes/' . $nodeUid . '/area',
                    'value' => $newArea,
                ]),
                ThemePatchCommand::fromArray([
                    'op' => ThemePatchCommand::OP_SET,
                    'path' => '/nodes/' . $nodeUid . '/sort_order',
                    'value' => $sortOrder,
                ]),
            ],
            actorId: $actorId,
            actorName: $actorName,
            summary: 'layout_node_moved',
        );

        return ['node_uid' => $nodeUid, 'workspace' => $result];
    }

    /**
     * @param array<string,int> $sortByNodeUid
     * @return array{nodes:list<array{node_uid:string,sort_order:int}>,workspace:array<string,mixed>}
     */
    public function updateSortOrders(
        ThemeEditorContext $context,
        array $sortByNodeUid,
        string $actorId,
        string $actorName = '',
    ): array {
        $context = $context->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        if ($sortByNodeUid === []) {
            throw new \InvalidArgumentException('theme_layout_sort_data_empty');
        }

        $state = $this->workspace->load($context, true);
        $commands = [];
        $nodes = \is_array($state['draft_payload']['nodes'] ?? null) ? $state['draft_payload']['nodes'] : [];
        $responseNodes = [];
        foreach ($sortByNodeUid as $rawUid => $sortOrder) {
            $nodeUid = $this->assertNodeUid((string)$rawUid);
            if (!isset($nodes[$nodeUid])) {
                throw new \RuntimeException('theme_layout_node_not_found');
            }
            $commands[] = ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_SET,
                'path' => '/nodes/' . $nodeUid . '/sort_order',
                'value' => \max(0, (int)$sortOrder),
            ]);
            $responseNodes[] = [
                'node_uid' => $nodeUid,
                'sort_order' => \max(0, (int)$sortOrder),
            ];
        }

        $result = $this->workspace->applyChanges(
            context: $context,
            expectedRevision: (int)($state['revision'] ?? 0),
            expectedParentReleaseId: $this->nullableReleaseId($state['expected_parent_release_id'] ?? null),
            changes: $commands,
            actorId: $actorId,
            actorName: $actorName,
            summary: 'layout_nodes_sorted',
        );

        return ['nodes' => $responseNodes, 'workspace' => $result];
    }

    /**
     * @return array{nodes:list<array{node_uid:string,sort_order:int}>,workspace:array<string,mixed>}
     */
    public function swapWidgetOrder(
        ThemeEditorContext $context,
        string $nodeUid1,
        string $nodeUid2,
        string $actorId,
        string $actorName = '',
    ): array {
        $context = $context->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        $nodeUid1 = $this->assertNodeUid($nodeUid1);
        $nodeUid2 = $this->assertNodeUid($nodeUid2);
        $state = $this->workspace->load($context, true);
        $nodes = \is_array($state['draft_payload']['nodes'] ?? null) ? $state['draft_payload']['nodes'] : [];
        if (!isset($nodes[$nodeUid1], $nodes[$nodeUid2])) {
            throw new \RuntimeException('theme_layout_node_not_found');
        }

        $sort1 = (int)($nodes[$nodeUid1]['sort_order'] ?? 0);
        $sort2 = (int)($nodes[$nodeUid2]['sort_order'] ?? 0);
        $commands = [
            ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_SET,
                'path' => '/nodes/' . $nodeUid1 . '/sort_order',
                'value' => $sort2,
            ]),
            ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_SET,
                'path' => '/nodes/' . $nodeUid2 . '/sort_order',
                'value' => $sort1,
            ]),
        ];

        $result = $this->workspace->applyChanges(
            context: $context,
            expectedRevision: (int)($state['revision'] ?? 0),
            expectedParentReleaseId: $this->nullableReleaseId($state['expected_parent_release_id'] ?? null),
            changes: $commands,
            actorId: $actorId,
            actorName: $actorName,
            summary: 'layout_nodes_swapped',
        );

        return [
            'nodes' => [
                ['node_uid' => $nodeUid1, 'sort_order' => $sort2],
                ['node_uid' => $nodeUid2, 'sort_order' => $sort1],
            ],
            'workspace' => $result,
        ];
    }

    public function clearTemplateDeletedTombstonesForSlot(
        ThemeEditorContext $context,
        string $slotId,
        string $actorId,
        string $actorName = '',
    ): int {
        $slotId = \trim($slotId);
        if ($slotId === '') {
            return 0;
        }

        $context = $context->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        $state = $this->workspace->load($context, true);
        $nodes = \is_array($state['draft_payload']['nodes'] ?? null) ? $state['draft_payload']['nodes'] : [];
        $commands = [];
        foreach ($nodes as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            if ((string)($node['slot_id'] ?? '') !== $slotId) {
                continue;
            }
            $config = \is_array($node['config'] ?? null) ? $node['config'] : [];
            $ref = \trim((string)($config[TemplateInlineWidgetMerger::CONFIG_TEMPLATE_REF] ?? ''));
            if ($ref === '' || empty($config[TemplateInlineWidgetMerger::CONFIG_TEMPLATE_DELETED])) {
                continue;
            }
            $uid = $this->assertNodeUid((string)$uid);
            $commands[] = ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_REMOVE_NODE,
                'path' => '/nodes/' . $uid,
                'node_uid' => $uid,
            ]);
        }

        if ($commands === []) {
            return 0;
        }

        $this->workspace->applyChanges(
            context: $context,
            expectedRevision: (int)($state['revision'] ?? 0),
            expectedParentReleaseId: $this->nullableReleaseId($state['expected_parent_release_id'] ?? null),
            changes: $commands,
            actorId: $actorId,
            actorName: $actorName,
            summary: 'layout_template_tombstones_cleared',
        );

        return \count($commands);
    }

    /** @param array<string,mixed> $snapshotData */
    public function replaceDraftFromSnapshot(
        ThemeEditorContext $context,
        array $snapshotData,
        string $actorId,
        string $actorName = '',
        string $summary = 'layout_draft_restored_from_snapshot',
    ): array {
        $context = $context->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        $payload = $this->snapshotNormalizer->normalize($context, $snapshotData);
        $state = $this->workspace->load($context, true);

        return $this->workspace->replaceEffectivePayload(
            context: $context,
            expectedRevision: (int)($state['revision'] ?? 0),
            expectedParentReleaseId: $this->nullableReleaseId($state['expected_parent_release_id'] ?? null),
            effectivePayload: $payload,
            actorId: $actorId,
            actorName: $actorName,
            summary: $summary,
        );
    }

    public function clearDraftNodes(
        ThemeEditorContext $context,
        string $actorId,
        string $actorName = '',
    ): array {
        $context = $context->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        $state = $this->workspace->load($context, true);
        $payload = [
            'theme_id' => $context->themeId,
            'nodes' => [],
            'selection' => ['layout_option' => $context->layoutOption],
        ];

        return $this->workspace->replaceEffectivePayload(
            context: $context,
            expectedRevision: (int)($state['revision'] ?? 0),
            expectedParentReleaseId: $this->nullableReleaseId($state['expected_parent_release_id'] ?? null),
            effectivePayload: $payload,
            actorId: $actorId,
            actorName: $actorName,
            summary: 'layout_draft_cleared',
        );
    }

    /**
     * Resolve sort keys that may be legacy layout_id or node_uid.
     *
     * @param array<string|int,int> $sortData
     * @return array<string,int>
     */
    public function resolveSortDataToNodeUids(array $sortData, array $draftNodes): array
    {
        $resolved = [];
        foreach ($sortData as $key => $sortOrder) {
            $key = (string)$key;
            if (\preg_match('/^[a-f0-9]{32}$/D', \strtolower($key)) === 1) {
                $resolved[\strtolower($key)] = (int)$sortOrder;
                continue;
            }
            $layoutId = (int)$key;
            if ($layoutId <= 0) {
                continue;
            }
            foreach ($draftNodes as $uid => $node) {
                if (!\is_array($node)) {
                    continue;
                }
                if ((int)($node['layout_id'] ?? 0) === $layoutId) {
                    $resolved[$this->assertNodeUid((string)$uid)] = (int)$sortOrder;
                    break;
                }
            }
        }

        return $resolved;
    }

    /** @param array<string,mixed> $data */
    private function resolveNodeUid(array $data): string
    {
        $nodeUid = \strtolower(\trim((string)($data['node_uid'] ?? '')));
        if (\preg_match('/^[a-f0-9]{32}$/D', $nodeUid) === 1) {
            return $nodeUid;
        }

        return \bin2hex(\random_bytes(16));
    }

    private function assertNodeUid(string $nodeUid): string
    {
        $nodeUid = \strtolower(\trim($nodeUid));
        if (\preg_match('/^[a-f0-9]{32}$/D', $nodeUid) !== 1) {
            throw new \InvalidArgumentException('theme_layout_node_uid_invalid');
        }

        return $nodeUid;
    }

    /** @param array<string,mixed> $state */
    private function assertNodeExists(array $state, string $nodeUid): void
    {
        $nodes = \is_array($state['draft_payload']['nodes'] ?? null) ? $state['draft_payload']['nodes'] : [];
        if (!isset($nodes[$nodeUid])) {
            throw new \RuntimeException('theme_layout_node_not_found');
        }
    }

    /** @param array<string,mixed> $state
     * @return list<ThemePatchCommand>
     */
    private function removeNodesInSlot(array $state, string $area, ?string $slotId, string $widgetCode): array
    {
        $commands = [];
        $nodes = \is_array($state['draft_payload']['nodes'] ?? null) ? $state['draft_payload']['nodes'] : [];
        foreach ($nodes as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            if ((string)($node['area'] ?? '') !== $area) {
                continue;
            }
            $nodeSlot = isset($node['slot_id']) && $node['slot_id'] !== null ? (string)$node['slot_id'] : null;
            if ($slotId !== null && $nodeSlot !== $slotId) {
                continue;
            }
            if ($widgetCode !== '' && (string)($node['widget_code'] ?? '') !== $widgetCode) {
                continue;
            }
            $uid = $this->assertNodeUid((string)$uid);
            $commands[] = ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_REMOVE_NODE,
                'path' => '/nodes/' . $uid,
                'node_uid' => $uid,
            ]);
        }

        return $commands;
    }

    /** @param array<string,mixed> $state
     * @return list<ThemePatchCommand>
     */
    private function shiftSortOrders(array $state, string $area, ?string $slotId, int $fromSortOrder, int $delta): array
    {
        $commands = [];
        $nodes = \is_array($state['draft_payload']['nodes'] ?? null) ? $state['draft_payload']['nodes'] : [];
        foreach ($nodes as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            if ((string)($node['area'] ?? '') !== $area) {
                continue;
            }
            $nodeSlot = isset($node['slot_id']) && $node['slot_id'] !== null ? (string)$node['slot_id'] : null;
            if ($slotId !== null && $nodeSlot !== $slotId) {
                continue;
            }
            $current = (int)($node['sort_order'] ?? 0);
            if ($current < $fromSortOrder) {
                continue;
            }
            $uid = $this->assertNodeUid((string)$uid);
            $commands[] = ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_SET,
                'path' => '/nodes/' . $uid . '/sort_order',
                'value' => $current + $delta,
            ]);
        }

        return $commands;
    }

    /** @param array<string,mixed> $state
     * @return list<ThemePatchCommand>
     */
    private function clearNoPlacementsMarker(array $state): array
    {
        $commands = [];
        $nodes = \is_array($state['draft_payload']['nodes'] ?? null) ? $state['draft_payload']['nodes'] : [];
        foreach ($nodes as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            if ((string)($node['widget_module'] ?? '') !== self::NO_PLACEMENTS_WIDGET_MODULE
                || (string)($node['widget_type'] ?? '') !== self::NO_PLACEMENTS_WIDGET_TYPE
                || (string)($node['widget_code'] ?? '') !== self::NO_PLACEMENTS_WIDGET_CODE
            ) {
                continue;
            }
            $uid = $this->assertNodeUid((string)$uid);
            $commands[] = ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_REMOVE_NODE,
                'path' => '/nodes/' . $uid,
                'node_uid' => $uid,
            ]);
        }

        return $commands;
    }

    private function nullableReleaseId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int)$value;

        return $id > 0 ? $id : null;
    }
}
