<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

/**
 * Materialize stable anchor relations for flat legacy layout projections.
 *
 * Canonical Releases retain UID relations. Existing ThemeLayout readers still
 * consume area/slot/sort_order, so before/after relations are deterministically
 * projected without turning derived sibling order into additional ownership.
 */
final class ThemeNodePlacementResolver
{
    /** @param array<string,array<string,mixed>> $nodes @return array<string,array<string,mixed>> */
    public function materialize(array $nodes): array
    {
        $hasRelativePlacement = false;
        foreach ($nodes as $rawUid => $node) {
            if (!\is_array($node)) {
                throw new \InvalidArgumentException('theme_layout_node_payload_invalid');
            }
            $uid = \strtolower((string)$rawUid);
            if (\preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1
                || (string)$rawUid !== $uid
                || \strtolower((string)($node['node_uid'] ?? '')) !== $uid
            ) {
                throw new \InvalidArgumentException('theme_layout_node_identity_invalid');
            }
            $anchor = $this->uid($node['anchor_uid'] ?? null);
            $parent = $this->uid($node['parent_uid'] ?? null);
            $position = \trim((string)($node['position'] ?? ''));
            if ($anchor !== null && $parent !== null) {
                throw new \InvalidArgumentException('theme_layout_node_relation_ambiguous');
            }
            if ($anchor !== null && !\in_array($position, ['before', 'after'], true)) {
                throw new \InvalidArgumentException('theme_layout_node_anchor_position_invalid');
            }
            if ($parent !== null && $position !== 'inside') {
                throw new \InvalidArgumentException('theme_layout_node_parent_position_invalid');
            }
            if ($anchor === null && $parent === null && $position !== '') {
                throw new \InvalidArgumentException('theme_layout_node_position_orphaned');
            }
            $relativeUid = $anchor ?? $parent;
            if ($relativeUid !== null && ($relativeUid === $uid || !isset($nodes[$relativeUid]))) {
                throw new \InvalidArgumentException('theme_layout_node_relative_target_invalid');
            }
            if ($anchor !== null || $parent !== null) {
                $hasRelativePlacement = true;
            }
        }
        if (!$hasRelativePlacement) {
            return $nodes;
        }

        $this->assertAcyclic($nodes);
        $resolved = $nodes;
        // Anchor chains may cross legacy area/slot boundaries. Propagate the
        // final anchor container before ordering, bounded by the node count.
        for ($pass = 0, $limit = \count($resolved); $pass < $limit; ++$pass) {
            $changed = false;
            foreach ($resolved as $uid => &$node) {
                $position = (string)($node['position'] ?? '');
                $relativeUid = $position === 'inside'
                    ? $this->uid($node['parent_uid'] ?? null)
                    : $this->uid($node['anchor_uid'] ?? null);
                if ($relativeUid === null || !isset($resolved[$relativeUid])) {
                    continue;
                }
                $relative = $resolved[$relativeUid];
                $area = (string)($relative['area'] ?? 'content');
                $slotId = \array_key_exists('slot_id', $relative) ? $relative['slot_id'] : null;
                if (($node['area'] ?? null) !== $area || ($node['slot_id'] ?? null) !== $slotId) {
                    $node['area'] = $area;
                    $node['slot_id'] = $slotId;
                    $changed = true;
                }
            }
            unset($node);
            if (!$changed) {
                break;
            }
        }

        $groups = [];
        foreach ($resolved as $uid => $node) {
            $groups[$this->containerKey($node)][] = (string)$uid;
        }
        foreach ($groups as $uids) {
            \usort($uids, function (string $left, string $right) use ($resolved): int {
                $order = ((int)($resolved[$left]['sort_order'] ?? 0))
                    <=> ((int)($resolved[$right]['sort_order'] ?? 0));
                return $order !== 0 ? $order : \strcmp($left, $right);
            });
            $uidSet = \array_fill_keys($uids, true);
            $edges = [];
            $inDegree = \array_fill_keys($uids, 0);
            foreach ($uids as $uid) {
                $node = $resolved[$uid];
                $position = (string)($node['position'] ?? '');
                $anchor = $position === 'inside'
                    ? $this->uid($node['parent_uid'] ?? null)
                    : $this->uid($node['anchor_uid'] ?? null);
                if ($anchor === null || !isset($uidSet[$anchor])) {
                    continue;
                }
                // Flat legacy readers cannot express a nested slot. Their
                // deterministic fallback orders an inside child after its
                // parent in the inherited parent container.
                [$from, $to] = $position === 'before' ? [$uid, $anchor] : [$anchor, $uid];
                if (!isset($edges[$from][$to])) {
                    $edges[$from][$to] = true;
                    ++$inDegree[$to];
                }
            }
            $baseline = \array_flip($uids);
            $ordered = [];
            while (\count($ordered) < \count($uids)) {
                $ready = \array_values(\array_filter(
                    $uids,
                    static fn(string $uid): bool => !isset($ordered[$uid]) && $inDegree[$uid] === 0,
                ));
                if ($ready === []) {
                    throw new \RuntimeException('theme_layout_node_anchor_cycle');
                }
                \usort($ready, static fn(string $left, string $right): int => $baseline[$left] <=> $baseline[$right]);
                $uid = $ready[0];
                $ordered[$uid] = true;
                foreach (\array_keys($edges[$uid] ?? []) as $to) {
                    --$inDegree[$to];
                }
            }
            foreach (\array_keys($ordered) as $index => $uid) {
                $resolved[$uid]['sort_order'] = $index;
            }
        }

        return $resolved;
    }

    /** @param array<string,array<string,mixed>> $nodes */
    private function assertAcyclic(array $nodes): void
    {
        $visiting = [];
        $visited = [];
        $visit = function (string $uid) use (&$visit, &$visiting, &$visited, $nodes): void {
            if (isset($visited[$uid])) {
                return;
            }
            if (isset($visiting[$uid])) {
                throw new \RuntimeException('theme_layout_node_anchor_cycle');
            }
            $visiting[$uid] = true;
            $node = $nodes[$uid] ?? [];
            $next = $this->uid($node['anchor_uid'] ?? null)
                ?? $this->uid($node['parent_uid'] ?? null);
            if ($next !== null && isset($nodes[$next])) {
                $visit($next);
            }
            unset($visiting[$uid]);
            $visited[$uid] = true;
        };
        foreach (\array_keys($nodes) as $uid) {
            $visit((string)$uid);
        }
    }

    /** @param array<string,mixed> $node */
    private function containerKey(array $node): string
    {
        return (string)($node['area'] ?? 'content') . "\0" . (string)($node['slot_id'] ?? '');
    }

    private function uid(mixed $value): ?string
    {
        $uid = \strtolower(\trim((string)($value ?? '')));
        if ($uid === '') {
            return null;
        }
        if (\preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
            throw new \InvalidArgumentException('theme_layout_node_anchor_invalid');
        }

        return $uid;
    }
}
