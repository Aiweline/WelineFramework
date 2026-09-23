<?php
declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\ThemeWidgetDefaultInjection;

/** Recover legacy version relationships from exact stored snapshots, never current pointers. */
final class ThemeLayoutVersionBindingResolver
{
    public function __construct(private readonly SharedChromeService $chrome) {}

    public function resolvePageVersion(int $themeId, string $scope, string $pageType, string $layoutOption, string $area, array $nodes, string $targetType = 'global', int $targetId = 0): array
    {
        $filters = ['theme_id' => $themeId, 'scope' => $scope, 'page_type' => $pageType,
            'layout_option' => $layoutOption, 'target_type' => $targetType, 'target_id' => $targetId];
        $versions = $this->rows(ThemeLayoutVersion::class, $filters);
        $expected = $this->nodeProjection($nodes);
        $matches = [];
        foreach ($versions as $version) {
            if ($this->nodeProjection($this->snapshotNodes($this->decode($version['snapshot_data'] ?? []))) === $expected) {
                $matches[] = (int)$version['version_id'];
            }
        }
        if (count($matches) === 1) {
            return ['resolved' => true, 'version_id' => $matches[0], 'reason' => ''];
        }
        $decisions = $versions === [] ? $this->rows(ThemeWidgetDefaultInjection::class, $filters + ['component_area' => $area]) : [];
        $hasUninstall = false;
        foreach ($decisions as $decision) {
            $hasUninstall = $hasUninstall || str_starts_with((string)($decision['source'] ?? ''), 'user_deleted@');
        }
        return ['resolved' => $versions === [] && !$hasUninstall, 'version_id' => 0,
            'reason' => $versions === [] && !$hasUninstall ? '' : 'layout_version_unresolved', 'candidate_version_ids' => $matches];
    }

    public function resolveChromeOmissions(int $themeId, string $scope, array $chromeNodes): array
    {
        $filters = ['theme_id' => $themeId, 'scope' => $scope, 'page_type' => 'homepage',
            'layout_option' => 'default', 'target_type' => 'global', 'target_id' => 0];
        return $this->matchChromeOmissions($chromeNodes,
            $this->rows(ThemeLayoutVersion::class, $filters),
            $this->rows(ThemeWidgetDefaultInjection::class, $filters + ['component_area' => 'frontend']));
    }

    public function matchChromeOmissions(array $chromeNodes, array $versions, array $decisions): array
    {
        $expected = $this->nodeProjection($chromeNodes, true);
        $matches = [];
        foreach ($versions as $version) {
            $snapshot = $this->decode($version['snapshot_data'] ?? []);
            if ($this->nodeProjection($this->snapshotNodes($snapshot), true) === $expected) {
                $matches[] = (int)$version['version_id'];
            }
        }
        $byVersion = [];
        foreach ($decisions as $decision) {
            if (preg_match('/^user_deleted@(\d+)$/D', (string)($decision['source'] ?? ''), $m) !== 1) {
                continue;
            }
            $slot = (string)($decision['slot_id'] ?? '');
            if (!$this->chrome->isChromeTarget((string)($decision['area'] ?? ''), $slot)) {
                continue;
            }
            $byVersion[(int)$m[1]][] = ['slot_id' => $slot,
                'widget_module' => (string)($decision['widget_module'] ?? ''),
                'widget_code' => (string)($decision['widget_code'] ?? '')];
        }
        if ($matches === []) {
            return ['resolved' => $versions === [] && $byVersion === [], 'version_ids' => [], 'omissions' => [],
                'reason' => $versions === [] && $byVersion === [] ? '' : 'chrome_layout_version_unresolved'];
        }
        $sets = [];
        foreach ($matches as $id) {
            $items = $byVersion[$id] ?? [];
            usort($items, static fn(array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
            $sets[json_encode($items, JSON_THROW_ON_ERROR)] = $items;
        }
        return ['resolved' => count($sets) === 1, 'version_ids' => $matches,
            'omissions' => count($sets) === 1 ? array_values($sets)[0] : [],
            'reason' => count($sets) === 1 ? '' : 'chrome_uninstall_history_ambiguous'];
    }

    public function snapshotNodes(array $snapshot): array
    {
        if (isset($snapshot['nodes']) && is_array($snapshot['nodes'])) {
            return $snapshot['nodes'];
        }
        $nodes = [];
        foreach ($snapshot as $area => $group) {
            foreach (is_array($group) ? ($group['widgets'] ?? []) : [] as $widget) {
                if (is_array($widget)) {
                    $widget['area'] = (string)($widget['area'] ?? $area);
                    $nodes[] = $widget;
                }
            }
        }
        return $nodes;
    }

    public function nodeProjection(array $nodes, bool $chromeOnly = false): array
    {
        $result = [];
        foreach ($nodes as $key => $node) {
            if (!is_array($node)) {
                continue;
            }
            $area = (string)($node['area'] ?? 'content');
            $slot = (string)($node['slot_id'] ?? $area);
            if ($chromeOnly && !$this->chrome->isChromeTarget($area, $slot)) {
                continue;
            }
            $uid = (string)($node['node_uid'] ?? (is_string($key) ? $key : ''));
            $config = $this->decode($node['config'] ?? []);
            unset($config['_theme_release_id'], $config['_theme_scope_draft_projection'], $config['_skip_translation_merge']);
            $result[] = ['node_uid' => $uid, 'area' => $area, 'slot_id' => $slot,
                'widget_module' => (string)($node['widget_module'] ?? ''),
                'widget_type' => (string)($node['widget_type'] ?? ''),
                'widget_code' => (string)($node['widget_code'] ?? ''),
                'sort_order' => (int)($node['sort_order'] ?? 0), 'is_active' => (bool)($node['is_active'] ?? true),
                'config' => $this->canonical($config)];
        }
        usort($result, static fn(array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
        return $result;
    }

    private function canonical(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) { $item = $this->canonical($item); }
        }
        unset($item);
        if (!array_is_list($value)) { ksort($value); }
        return $value;
    }

    private function rows(string $model, array $filters): array
    {
        $query = (clone ObjectManager::getInstance($model))->clearQuery()->clearData();
        foreach ($filters as $key => $value) { $query->where($key, $value); }
        $rows = $query->select()->fetchArray();
        return !is_array($rows) || $rows === [] ? [] : (array_is_list($rows) ? $rows : [$rows]);
    }

    private function decode(mixed $value): array
    {
        $value = is_string($value) ? json_decode($value, true) : $value;
        return is_array($value) ? $value : [];
    }
}
