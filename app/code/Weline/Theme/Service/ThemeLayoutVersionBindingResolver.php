<?php
declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * Legacy ThemeLayoutVersion snapshot helpers retained for offline convert/archive only.
 *
 * Online preview / bake MUST NOT call resolvePageVersion / resolveChromeOmissions /
 * matchChromeOmissions — those formerly guessed history by nodeProjection equality.
 * Runtime chrome omissions come from ThemeScopeVersionWidgetDecisionService.
 */
final class ThemeLayoutVersionBindingResolver
{
    public const REASON_PROJECTION_MATCHING_REMOVED = 'projection_matching_removed';

    public function __construct(private readonly SharedChromeService $chrome) {}

    /**
     * @deprecated Online paths must use explicit ThemeScopeVersion / ThemeVersionIdentity.
     * @return array{resolved:bool,version_id:int,reason:string,candidate_version_ids:list<int>}
     */
    public function resolvePageVersion(
        int $themeId,
        string $scope,
        string $pageType,
        string $layoutOption,
        string $area,
        array $nodes,
        string $targetType = 'global',
        int $targetId = 0,
    ): array {
        unset($themeId, $scope, $pageType, $layoutOption, $area, $nodes, $targetType, $targetId);

        return [
            'resolved' => false,
            'version_id' => 0,
            'reason' => self::REASON_PROJECTION_MATCHING_REMOVED,
            'candidate_version_ids' => [],
        ];
    }

    /**
     * @deprecated Online paths must use ThemeScopeVersionWidgetDecisionService::listUninstallOmissions.
     * @return array{resolved:bool,version_ids:list<int>,omissions:list<array<string,mixed>>,reason:string}
     */
    public function resolveChromeOmissions(int $themeId, string $scope, array $chromeNodes): array
    {
        unset($themeId, $scope, $chromeNodes);

        return [
            'resolved' => false,
            'version_ids' => [],
            'omissions' => [],
            'reason' => self::REASON_PROJECTION_MATCHING_REMOVED,
        ];
    }

    /**
     * @deprecated Projection matching removed from runtime; offline convert must not rely on this.
     * @param array<string|int,mixed> $chromeNodes
     * @param list<array<string,mixed>> $versions
     * @param list<array<string,mixed>> $decisions
     * @return array{resolved:bool,version_ids:list<int>,omissions:list<array<string,mixed>>,reason:string}
     */
    public function matchChromeOmissions(array $chromeNodes, array $versions, array $decisions): array
    {
        unset($chromeNodes, $versions, $decisions);

        return [
            'resolved' => false,
            'version_ids' => [],
            'omissions' => [],
            'reason' => self::REASON_PROJECTION_MATCHING_REMOVED,
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return list<array<string,mixed>>|array<string|int,mixed>
     */
    public function snapshotNodes(array $snapshot): array
    {
        if (isset($snapshot['nodes']) && \is_array($snapshot['nodes'])) {
            return $snapshot['nodes'];
        }
        $nodes = [];
        foreach ($snapshot as $area => $group) {
            foreach (\is_array($group) ? ($group['widgets'] ?? []) : [] as $widget) {
                if (\is_array($widget)) {
                    $widget['area'] = (string)($widget['area'] ?? $area);
                    $nodes[] = $widget;
                }
            }
        }

        return $nodes;
    }

    /**
     * Structural fingerprint helper for offline/archive tooling only — not a resolve authority.
     *
     * @param array<string|int,mixed> $nodes
     * @return list<array<string,mixed>>
     */
    public function nodeProjection(array $nodes, bool $chromeOnly = false): array
    {
        $result = [];
        foreach ($nodes as $key => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $area = (string)($node['area'] ?? 'content');
            $slot = (string)($node['slot_id'] ?? $area);
            if ($chromeOnly && !$this->chrome->isChromeTarget($area, $slot)) {
                continue;
            }
            $uid = (string)($node['node_uid'] ?? (\is_string($key) ? $key : ''));
            $config = $this->decode($node['config'] ?? []);
            unset($config['_theme_release_id'], $config['_theme_scope_draft_projection'], $config['_skip_translation_merge']);
            $result[] = [
                'node_uid' => $uid,
                'area' => $area,
                'slot_id' => $slot,
                'widget_module' => (string)($node['widget_module'] ?? ''),
                'widget_type' => (string)($node['widget_type'] ?? ''),
                'widget_code' => (string)($node['widget_code'] ?? ''),
                'sort_order' => (int)($node['sort_order'] ?? 0),
                'is_active' => (bool)($node['is_active'] ?? true),
                'config' => $this->canonical($config),
            ];
        }
        \usort($result, static fn(array $a, array $b): int => \strcmp(\json_encode($a), \json_encode($b)));

        return $result;
    }

    private function canonical(array $value): array
    {
        foreach ($value as &$item) {
            if (\is_array($item)) {
                $item = $this->canonical($item);
            }
        }
        unset($item);
        if (!\array_is_list($value)) {
            \ksort($value);
        }

        return $value;
    }

    private function decode(mixed $value): array
    {
        $value = \is_string($value) ? \json_decode($value, true) : $value;

        return \is_array($value) ? $value : [];
    }
}
