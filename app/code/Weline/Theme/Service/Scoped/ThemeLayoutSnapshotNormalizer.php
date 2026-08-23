<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\ThemeLayout;

/** Normalize legacy grouped layout-version snapshots into the canonical node map. */
final class ThemeLayoutSnapshotNormalizer
{
    public function __construct(
        private readonly ThemeNodePlacementResolver $placements,
    ) {
    }

    /** @return array{theme_id:int,nodes:array<string,array<string,mixed>>,selection:array<string,mixed>} */
    public function normalize(ThemeEditorContext $context, array $snapshot): array
    {
        $nodes = [];
        foreach ($snapshot as $areaCode => $areaData) {
            if (!\is_array($areaData) || !\is_array($areaData['widgets'] ?? null)) {
                continue;
            }
            foreach ($areaData['widgets'] as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                $config = $widget['config'] ?? [];
                if (\is_string($config)) {
                    $decoded = \json_decode($config, true);
                    $config = \is_array($decoded) ? $decoded : [];
                }
                $config = \is_array($config) ? $config : [];
                unset($config['_theme_release_id'], $config['_theme_scope_draft_projection']);
                $uid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
                if (\preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
                    $uid = $this->legacyNodeUid($widget, $config);
                }
                if (isset($nodes[$uid])) {
                    throw new \RuntimeException('theme_layout_snapshot_node_uid_collision:' . $uid);
                }
                if (\trim((string)($config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] ?? '')) === '') {
                    $config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] = 'wi_' . $uid;
                }
                $area = \trim((string)($widget['area'] ?? $areaCode));
                $nodes[$uid] = [
                    'node_uid' => $uid,
                    'area' => $area !== '' ? $area : ThemeLayout::AREA_CONTENT,
                    'slot_id' => \array_key_exists('slot_id', $widget) && $widget['slot_id'] !== null
                        ? (string)$widget['slot_id']
                        : null,
                    'widget_code' => (string)($widget['widget_code'] ?? ''),
                    'widget_module' => (string)($widget['widget_module'] ?? ''),
                    'widget_type' => (string)($widget['widget_type'] ?? ''),
                    'config' => $config,
                    'sort_order' => (int)($widget['sort_order'] ?? 0),
                    'is_active' => (bool)($widget['is_active'] ?? true),
                ];
            }
        }

        return [
            'theme_id' => $context->themeId,
            'nodes' => $nodes,
            'selection' => ['layout_option' => $context->layoutOption],
        ];
    }

    /** Convert a canonical layout draft back to the compatibility version-snapshot shape. */
    public function denormalize(ThemeEditorContext $context, array $payload): array
    {
        $snapshot = [];
        foreach (ThemeLayout::getAreas() as $areaCode => $label) {
            $snapshot[$areaCode] = ['label' => (string)$label, 'widgets' => []];
        }
        $nodes = \is_array($payload['nodes'] ?? null)
            ? $this->placements->materialize($payload['nodes'])
            : [];
        foreach ($nodes as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $uid = \strtolower((string)($node['node_uid'] ?? $uid));
            if (\preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
                throw new \RuntimeException('theme_layout_payload_node_uid_invalid');
            }
            $area = (string)($node['area'] ?? ThemeLayout::AREA_CONTENT);
            if (!isset($snapshot[$area])) {
                $snapshot[$area] = ['label' => $area, 'widgets' => []];
            }
            $snapshot[$area]['widgets'][] = [
                'node_uid' => $uid,
                'area' => $area,
                'page_type' => $context->layoutType,
                'widget_code' => (string)($node['widget_code'] ?? ''),
                'widget_module' => (string)($node['widget_module'] ?? ''),
                'widget_type' => (string)($node['widget_type'] ?? ''),
                'slot_id' => \array_key_exists('slot_id', $node) ? $node['slot_id'] : null,
                'layout_option' => $context->layoutOption,
                'scope' => $context->scope->storageScope,
                'locale_code' => $context->locale === 'default' ? '' : $context->locale,
                'target_type' => $context->targetType,
                'target_id' => $context->targetId,
                'config' => \is_array($node['config'] ?? null) ? $node['config'] : [],
                'sort_order' => (int)($node['sort_order'] ?? 0),
                'is_active' => (bool)($node['is_active'] ?? true),
                'status' => ThemeLayout::STATUS_DRAFT,
            ];
        }
        foreach ($snapshot as &$areaData) {
            \usort($areaData['widgets'], static fn(array $left, array $right): int =>
                ((int)$left['sort_order']) <=> ((int)$right['sort_order']));
        }
        unset($areaData);

        return $snapshot;
    }

    /** @param array<string,mixed> $widget @param array<string,mixed> $config */
    private function legacyNodeUid(array $widget, array $config): string
    {
        $i18n = \trim((string)($config[ThemeData::WIDGET_I18N_INSTANCE_CONFIG_KEY] ?? ''));
        if ($i18n !== '') {
            return \substr(\hash('sha256', 'i18n:' . $i18n), 0, 32);
        }

        return \substr(\hash('sha256', \implode("\0", [
            (string)($widget['layout_id'] ?? $widget[ThemeLayout::schema_fields_ID] ?? ''),
            (string)($widget['widget_module'] ?? $widget[ThemeLayout::schema_fields_WIDGET_MODULE] ?? ''),
            (string)($widget['widget_type'] ?? $widget[ThemeLayout::schema_fields_WIDGET_TYPE] ?? ''),
            (string)($widget['widget_code'] ?? $widget[ThemeLayout::schema_fields_WIDGET_CODE] ?? ''),
            (string)($widget['slot_id'] ?? $widget[ThemeLayout::schema_fields_SLOT_ID] ?? ''),
        ])), 0, 32);
    }
}
