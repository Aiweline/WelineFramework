<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;

/** Resolves saved relations and localized paths once, before source generation. */
final class ThemeLayoutEntityInputResolver
{
    public function omissionsForNodes(array $before, array $after, array $previous): array
    {
        $key = static fn(array $node): string => ($node['slot_id'] ?? '') . '|' . ($node['widget_module'] ?? '') . '|' . ($node['widget_code'] ?? '');
        $out = [];
        foreach ($previous as $item) { $out[$key($item)] = $item; }
        foreach ($before as $uid => $node) {
            if (!is_array($node) || isset($after[$uid]) || empty($node['widget_code'])) { continue; }
            $out[$key($node)] = array_intersect_key($node, array_flip(['slot_id','widget_module','widget_code']));
        }
        foreach ($after as $node) {
            if (!is_array($node) || empty($node['widget_code'])) { continue; }
            if (RequiredDefaultInjectionContract::isUninstallSource((string)($node['source'] ?? $node['config']['_source'] ?? ''))) {
                $out[$key($node)] = array_intersect_key($node, array_flip(['slot_id','widget_module','widget_code']));
            } elseif ($node['is_active'] ?? true) { unset($out[$key($node)]); }
        }
        return array_values($out);
    }

    public function placements(array $nodes): array
    {
        $visiting = []; $resolved = [];
        $resolve = function (string $uid) use (&$resolve, &$nodes, &$visiting, &$resolved): void {
            if (isset($resolved[$uid])) { return; }
            if (isset($visiting[$uid])) { throw new \RuntimeException('theme_layout_node_anchor_cycle'); }
            $visiting[$uid] = true;
            $node = $nodes[$uid];
            $anchor = (string)($node['anchor_uid'] ?? '');
            if ($anchor !== '' && in_array($node['position'] ?? '', ['before','after'], true)) {
                if (!isset($nodes[$anchor])) { throw new \RuntimeException('theme_layout_node_relative_target_missing'); }
                $resolve($anchor);
                $node['area'] = $nodes[$anchor]['area'] ?? 'content';
                $node['slot_id'] = $nodes[$anchor]['slot_id'] ?? null;
                if (isset($nodes[$anchor]['parent_uid'])) { $node['parent_uid'] = $nodes[$anchor]['parent_uid']; }
                else { unset($node['parent_uid']); }
            }
            $nodes[$uid] = $node;
            unset($visiting[$uid]); $resolved[$uid] = true;
        };
        foreach (array_keys($nodes) as $uid) { $resolve((string)$uid); }
        $groups = [];
        foreach ($nodes as $uid => $node) {
            $key = (string)($node['parent_uid'] ?? '') . "\0" . (string)($node['area'] ?? 'content') . "\0" . (string)($node['slot_id'] ?? '');
            $groups[$key][] = (string)$uid;
        }
        foreach ($groups as $uids) {
            usort($uids, static fn(string $a, string $b): int => ((int)($nodes[$a]['sort_order'] ?? 0) <=> (int)($nodes[$b]['sort_order'] ?? 0)) ?: strcmp($a,$b));
            $relations = []; $roots = [];
            foreach ($uids as $uid) {
                $anchor = (string)($nodes[$uid]['anchor_uid'] ?? '');
                $position = (string)($nodes[$uid]['position'] ?? '');
                if ($anchor !== '' && in_array($anchor,$uids,true) && in_array($position,['before','after'],true)) { $relations[$anchor][$position][] = $uid; }
                else { $roots[] = $uid; }
            }
            $order = 0;
            $emit = function (string $uid) use (&$emit, &$nodes, &$order, $relations): void {
                foreach ($relations[$uid]['before'] ?? [] as $child) { $emit($child); }
                $nodes[$uid]['sort_order'] = $order++;
                unset($nodes[$uid]['anchor_uid']);
                if (!empty($nodes[$uid]['parent_uid'])) { $nodes[$uid]['position'] = 'inside'; }
                else { unset($nodes[$uid]['position']); }
                foreach ($relations[$uid]['after'] ?? [] as $child) { $emit($child); }
            };
            foreach ($roots as $uid) { $emit($uid); }
        }
        return $nodes;
    }

    public function localizedConfig(array $base, array $overrides): array
    {
        foreach ($overrides as $path => $value) {
            $segments = explode('.', trim((string)$path, '.'));
            $cursor =& $base;
            foreach ($segments as $index => $segment) {
                if ($segment === '' || !preg_match('/^[a-zA-Z0-9_:@-]+$/D', $segment)) { throw new \RuntimeException('theme_scoped_preview_translation_path_invalid'); }
                if ($index === count($segments)-1) { $cursor[$segment] = $value; break; }
                if (!is_array($cursor[$segment] ?? null)) { $cursor[$segment] = []; }
                $cursor =& $cursor[$segment];
            }
            unset($cursor);
        }
        return $base;
    }
}
