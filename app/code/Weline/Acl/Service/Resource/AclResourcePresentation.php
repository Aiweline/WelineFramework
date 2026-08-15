<?php

declare(strict_types=1);

namespace Weline\Acl\Service\Resource;

use Weline\Framework\Authorization\Resource\AuthorizationResourceType;
use Weline\Framework\Authorization\Resource\SourceIdParser;

final class AclResourcePresentation
{
    /**
     * jstree three_state will check every child if a parent starts selected.
     * Only preselect leaves; parents become checked/undetermined from children.
     */
    public static function assignmentNodePreselected(bool $granted, bool $hasChildren): bool
    {
        return $granted && !$hasChildren;
    }

    public static function typeLabel(string $storageType): string
    {
        return match ($storageType) {
            AuthorizationResourceType::STORAGE_MENUS, 'menus' => (string)__('菜单'),
            AuthorizationResourceType::STORAGE_PC, 'pc' => (string)__('HTTP 接口'),
            AuthorizationResourceType::STORAGE_API, 'api' => (string)__('REST API'),
            AuthorizationResourceType::STORAGE_QUERY, 'query' => (string)__('bin-query 接口'),
            AuthorizationResourceType::STORAGE_TASK, 'task' => (string)__('后台任务'),
            AuthorizationResourceType::STORAGE_OPERATION, 'operation' => (string)__('运维操作'),
            default => (string)__('其他'),
        };
    }

    public static function typeIcon(string $storageType): string
    {
        return match ($storageType) {
            'menus' => 'menu',
            'pc' => 'api',
            'api' => 'cloud-outline',
            'query' => 'database-search',
            'task' => 'timeline-clock',
            'operation' => 'wrench',
            default => 'key',
        };
    }

    /**
     * @return list<string>
     */
    public static function tagsFromSourceId(string $sourceId, ?string $metadataJson = null): array
    {
        $parsed = SourceIdParser::parse($sourceId);
        if ($parsed !== null && $parsed['tags'] !== []) {
            return $parsed['tags'];
        }
        if ($metadataJson) {
            $meta = \json_decode($metadataJson, true);
            if (\is_array($meta) && \is_array($meta['tags'] ?? null)) {
                return \array_values(\array_map('strval', $meta['tags']));
            }
        }
        return [];
    }

    /**
     * Parse list filter param: "a,b" or ["a","b"].
     *
     * @return list<string>
     */
    public static function parseTagFilterParam(mixed $raw): array
    {
        if (\is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = \preg_split('/\s*,\s*/', \trim((string)$raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $out = [];
        foreach ($parts as $part) {
            $tag = \trim((string)$part);
            if ($tag === '' || isset($out[$tag])) {
                continue;
            }
            $out[$tag] = true;
        }
        return \array_keys($out);
    }

    /**
     * Whether resource tags match selected filter tags (OR: any selected tag present).
     *
     * @param list<string> $resourceTags
     * @param list<string> $filterTags
     */
    public static function resourceMatchesTagFilter(array $resourceTags, array $filterTags): bool
    {
        if ($filterTags === []) {
            return true;
        }
        if ($resourceTags === []) {
            return false;
        }
        $set = \array_fill_keys($resourceTags, true);
        foreach ($filterTags as $tag) {
            if (isset($set[$tag])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build tag select options from ACL rows + optional AclTag metadata.
     *
     * @param list<array<string,mixed>> $aclRows rows with source_id + resource_metadata
     * @param array<string,array<string,mixed>> $metaByTag tag => AclTag row
     * @return list<array{value:string,label:string,meta:string}>
     */
    public static function buildTagSelectOptions(array $aclRows, array $metaByTag = []): array
    {
        $counts = [];
        foreach ($aclRows as $row) {
            foreach (self::tagsFromSourceId(
                (string)($row['source_id'] ?? ''),
                isset($row['resource_metadata']) ? (string)$row['resource_metadata'] : null,
            ) as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        $options = [];
        foreach ($counts as $tag => $count) {
            $meta = $metaByTag[$tag] ?? [];
            $label = (string)($meta['display_name'] ?? $tag);
            if ($label === '') {
                $label = $tag;
            }
            $options[] = [
                'value' => $tag,
                'label' => $label,
                'meta' => (string)$count,
            ];
        }
        \usort(
            $options,
            static function (array $a, array $b) use ($metaByTag): int {
                $sa = (int)($metaByTag[$a['value']]['sort_order'] ?? 0);
                $sb = (int)($metaByTag[$b['value']]['sort_order'] ?? 0);
                return ($sa <=> $sb) ?: \strcmp((string)$a['value'], (string)$b['value']);
            }
        );
        return $options;
    }

    /**
     * Build tag-dimension tree nodes from ACL rows.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,true> $selectedSourceIds
     * @return list<array<string,mixed>>
     */
    public static function buildTagTree(array $rows, array $selectedSourceIds = []): array
    {
        $root = [];
        foreach ($rows as $row) {
            $sourceId = (string)($row['source_id'] ?? '');
            $type = (string)($row['type'] ?? '');
            if ($sourceId === '' || !\in_array($type, ['query', 'task', 'operation'], true)) {
                continue;
            }
            $tags = self::tagsFromSourceId($sourceId, (string)($row['resource_metadata'] ?? ''));
            if ($tags === []) {
                $tags = [$type];
            }
            $cursor = &$root;
            $path = [];
            foreach ($tags as $tag) {
                $path[] = $tag;
                $key = \implode(':', $path);
                if (!isset($cursor[$key])) {
                    $cursor[$key] = [
                        'id' => 'tag:' . $key,
                        'tag_path' => $key,
                        'tag' => $tag,
                        'name' => $tag,
                        'is_tag' => true,
                        'children' => [],
                        'leaves' => [],
                    ];
                }
                $cursor = &$cursor[$key]['children'];
            }
            // attach leaf to last node via path walk again
            unset($cursor);
            $cursor = &$root;
            $path = [];
            $lastKey = '';
            foreach ($tags as $tag) {
                $path[] = $tag;
                $lastKey = \implode(':', $path);
                $cursor = &$cursor[$lastKey]['children'];
            }
            unset($cursor);
            // find last node
            $nodeRef = &$root;
            $path = [];
            foreach ($tags as $i => $tag) {
                $path[] = $tag;
                $key = \implode(':', $path);
                if ($i === \count($tags) - 1) {
                    $nodeRef[$key]['leaves'][] = [
                        'id' => $sourceId,
                        'source_id' => $sourceId,
                        'name' => (string)($row['source_name'] ?? $sourceId),
                        'module' => (string)($row['module'] ?? ''),
                        'type' => $type,
                        'selected' => isset($selectedSourceIds[$sourceId]),
                        'is_tag' => false,
                    ];
                } else {
                    $nodeRef = &$nodeRef[$key]['children'];
                }
            }
            unset($nodeRef);
        }
        return self::normalizeTagNodes($root);
    }

    /**
     * Map every tag_path prefix to leaf source_ids (any storage type that carries tags).
     * Used by role UI for D-1 cancel/check semantics beyond the tag-tree display filter.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,list<string>>
     */
    public static function buildTagPathLeaves(array $rows): array
    {
        $paths = [];
        foreach ($rows as $row) {
            $sourceId = (string)($row['source_id'] ?? '');
            if ($sourceId === '') {
                continue;
            }
            $tags = self::tagsFromSourceId($sourceId, (string)($row['resource_metadata'] ?? ''));
            if ($tags === []) {
                continue;
            }
            for ($i = 1, $n = \count($tags); $i <= $n; ++$i) {
                $path = \implode(':', \array_slice($tags, 0, $i));
                $paths[$path][$sourceId] = true;
            }
        }
        $out = [];
        foreach ($paths as $path => $leafMap) {
            $out[$path] = \array_keys($leafMap);
        }
        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private static function normalizeTagNodes(array $nodes): array
    {
        $list = \array_values($nodes);
        \usort($list, static fn($a, $b) => ((string)$a['tag_path'] <=> (string)$b['tag_path']));
        foreach ($list as &$node) {
            $node['children'] = self::normalizeTagNodes($node['children'] ?? []);
        }
        unset($node);
        return $list;
    }

    /**
     * Drop tag grants that would restore sources the operator just unchecked.
     *
     * @param list<string> $postedIds
     * @param list<string> $tagPaths
     * @param array<string, array<string, true>> $byTagPath
     * @param list<string> $previouslyGrantedIds
     * @return array{tag_paths: list<string>, extra_ids: list<string>}
     */
    public static function reconcileTagGrantsForSave(
        array $postedIds,
        array $tagPaths,
        array $byTagPath,
        array $previouslyGrantedIds,
    ): array {
        $posted = [];
        foreach ($postedIds as $id) {
            $id = \trim((string)$id);
            if ($id !== '') {
                $posted[$id] = true;
            }
        }
        $previous = [];
        foreach ($previouslyGrantedIds as $id) {
            $id = \trim((string)$id);
            if ($id !== '') {
                $previous[$id] = true;
            }
        }

        $keptPaths = [];
        $extraIds = [];
        foreach ($tagPaths as $path) {
            $path = \trim((string)$path);
            if ($path === '' || !isset($byTagPath[$path])) {
                continue;
            }
            $tagSources = \array_keys($byTagPath[$path]);
            $previouslyGrantedUnderTag = 0;
            $stillPostedUnderTag = 0;
            foreach ($tagSources as $sourceId) {
                if (isset($previous[$sourceId])) {
                    ++$previouslyGrantedUnderTag;
                }
                if (isset($posted[$sourceId])) {
                    ++$stillPostedUnderTag;
                }
            }
            if ($previouslyGrantedUnderTag > 0 && $stillPostedUnderTag < $previouslyGrantedUnderTag) {
                continue;
            }
            $keptPaths[] = $path;
            foreach ($tagSources as $sourceId) {
                if (!isset($posted[$sourceId])) {
                    $extraIds[] = $sourceId;
                }
            }
        }

        return [
            'tag_paths' => $keptPaths,
            'extra_ids' => $extraIds,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string, list<string>>
     */
    public static function buildSourceDescendants(array $rows): array
    {
        $children = [];
        foreach ($rows as $row) {
            $id = \trim((string)($row['source_id'] ?? ''));
            $parent = \trim((string)($row['parent_source'] ?? ''));
            if ($id === '' || $parent === '') {
                continue;
            }
            $children[$parent][] = $id;
        }

        $memo = [];
        $walk = static function (string $id) use (&$walk, &$memo, $children): array {
            if (isset($memo[$id])) {
                return $memo[$id];
            }
            $out = [];
            foreach ($children[$id] ?? [] as $child) {
                $out[$child] = true;
                foreach ($walk($child) as $descendant) {
                    $out[$descendant] = true;
                }
            }

            return $memo[$id] = \array_keys($out);
        };

        $map = [];
        foreach (\array_keys($children) as $id) {
            $descendants = $walk($id);
            if ($descendants !== []) {
                $map[$id] = $descendants;
            }
        }

        return $map;
    }

    /**
     * If a previously granted menu root is absent from POST and no menu descendant
     * remains posted, drop every descendant (pc/api/query included) so a stale
     * shared/tag-tree selection cannot resurrect the unchecked subtree.
     *
     * @param list<string> $postedIds
     * @param list<string> $previouslyGrantedIds
     * @param list<array<string,mixed>> $allRows
     * @return list<string>
     */
    public static function revokeUncheckedMenuSubtrees(
        array $postedIds,
        array $previouslyGrantedIds,
        array $allRows,
    ): array {
        $posted = [];
        foreach ($postedIds as $id) {
            $id = \trim((string)$id);
            if ($id !== '') {
                $posted[$id] = true;
            }
        }
        $previous = [];
        foreach ($previouslyGrantedIds as $id) {
            $id = \trim((string)$id);
            if ($id !== '') {
                $previous[$id] = true;
            }
        }

        $byId = [];
        foreach ($allRows as $row) {
            $id = \trim((string)($row['source_id'] ?? ''));
            if ($id !== '') {
                $byId[$id] = $row;
            }
        }
        $descendants = self::buildSourceDescendants($allRows);

        $drop = [];
        foreach ($previous as $sourceId => $_) {
            if (isset($posted[$sourceId])) {
                continue;
            }
            if (!self::isMenusType((string)($byId[$sourceId]['type'] ?? ''))) {
                continue;
            }
            $hasPostedMenuDescendant = false;
            foreach ($descendants[$sourceId] ?? [] as $descendant) {
                if (isset($posted[$descendant]) && self::isMenusType((string)($byId[$descendant]['type'] ?? ''))) {
                    $hasPostedMenuDescendant = true;
                    break;
                }
            }
            if ($hasPostedMenuDescendant) {
                continue;
            }
            $drop[$sourceId] = true;
            foreach ($descendants[$sourceId] ?? [] as $descendant) {
                $drop[$descendant] = true;
            }
        }

        $kept = [];
        foreach ($postedIds as $id) {
            $id = \trim((string)$id);
            if ($id === '' || isset($drop[$id])) {
                continue;
            }
            $kept[] = $id;
        }

        return \array_values(\array_unique($kept));
    }

    private static function isMenusType(string $type): bool
    {
        return $type === 'menus';
    }

    /**
     * Expand menus ancestors for a set of source ids (D-13).
     *
     * @param list<string> $sourceIds
     * @param list<array<string,mixed>> $allRows
     * @return list<string>
     */
    public static function expandMenusAncestors(array $sourceIds, array $allRows): array
    {
        $byId = [];
        foreach ($allRows as $row) {
            $id = (string)($row['source_id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $row;
            }
        }
        $out = [];
        foreach ($sourceIds as $sourceId) {
            $sourceId = \trim($sourceId);
            if ($sourceId === '') {
                continue;
            }
            $out[$sourceId] = true;
            $current = $sourceId;
            $guard = 0;
            while ($guard++ < 64 && isset($byId[$current])) {
                $parent = (string)($byId[$current]['parent_source'] ?? '');
                if ($parent === '' || isset($out[$parent])) {
                    break;
                }
                if (((string)($byId[$parent]['type'] ?? '')) === 'menus' || isset($byId[$parent])) {
                    $out[$parent] = true;
                }
                $current = $parent;
            }
        }
        return \array_keys($out);
    }
}
