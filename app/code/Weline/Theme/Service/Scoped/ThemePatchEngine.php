<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Theme\Api\Scoped\ThemePatchCommand;

/** Pure deterministic JSON-map patch/merge engine. */
final class ThemePatchEngine
{
    /**
     * @param list<ThemePatchCommand> $commands
     * @return array<string,mixed>
     */
    public function apply(array $base, array $commands): array
    {
        $payload = $base;
        foreach ($commands as $command) {
            if (!$command instanceof ThemePatchCommand) {
                throw new \InvalidArgumentException('theme_patch_command_type_invalid');
            }
            switch ($command->operation) {
                case ThemePatchCommand::OP_SET:
                    $this->setPath($payload, $command->path, $command->value);
                    break;
                case ThemePatchCommand::OP_ADD_NODE:
                    $node = $command->value;
                    $node['node_uid'] = $command->nodeUid;
                    // Placement metadata is owned by the command contract, not
                    // by an untrusted/stale whole-node value. Keep exactly one
                    // canonical relation so graph validation cannot be bypassed.
                    unset($node['parent_uid'], $node['anchor_uid'], $node['position']);
                    if ($command->anchorUid !== null && $command->position === 'inside') {
                        $node['parent_uid'] = $command->anchorUid;
                        $node['position'] = 'inside';
                    } elseif ($command->anchorUid !== null && $command->position !== null) {
                        $node['anchor_uid'] = $command->anchorUid;
                        $node['position'] = $command->position;
                    }
                    $this->setPath($payload, $command->path, $node);
                    break;
                case ThemePatchCommand::OP_REMOVE_NODE:
                    $this->removePath($payload, $command->path);
                    break;
                case ThemePatchCommand::OP_MOVE_NODE:
                    [$exists, $node] = $this->readPath($payload, $command->path);
                    if (!$exists || !\is_array($node)) {
                        break;
                    }
                    if ($command->position === 'inside') {
                        $node['parent_uid'] = $command->anchorUid;
                        $node['anchor_uid'] = null;
                        $node['position'] = 'inside';
                    } else {
                        unset($node['parent_uid']);
                        $node['anchor_uid'] = $command->anchorUid;
                        $node['position'] = $command->position;
                    }
                    $this->setPath($payload, $command->path, $node);
                    break;
                case ThemePatchCommand::OP_INHERIT:
                    // Inherit commands are consumed while building the local patch map.
                    break;
            }
        }

        return $this->normalizeNodeIdentities($payload);
    }

    /**
     * @param list<ThemePatchCommand> $commands
     * @return list<array{code:string,path:string,node_uid:?string,anchor_uid:?string}>
     */
    public function structuralConflicts(array $oldParent, array $newParent, array $commands): array
    {
        $conflicts = [];
        $locallyAdded = [];
        foreach ($commands as $candidate) {
            if ($candidate instanceof ThemePatchCommand
                && $candidate->operation === ThemePatchCommand::OP_ADD_NODE
                && $candidate->nodeUid !== null
            ) {
                $locallyAdded[$candidate->nodeUid] = true;
            }
        }
        foreach ($commands as $command) {
            if (!$command instanceof ThemePatchCommand) {
                continue;
            }
            $nodeUid = $command->nodeUid ?? $this->nodeUidFromPath($command->path);
            if ($nodeUid === null) {
                continue;
            }
            $nodePath = '/nodes/' . $nodeUid;
            [$oldNodeExists] = $this->readPath($oldParent, $nodePath);
            [$newNodeExists] = $this->readPath($newParent, $nodePath);

            if ($command->operation === ThemePatchCommand::OP_REMOVE_NODE) {
                // Parent deleting the same node already satisfies the local tombstone.
                continue;
            }
            if ($command->operation !== ThemePatchCommand::OP_ADD_NODE
                && $oldNodeExists
                && !$newNodeExists
                && !isset($locallyAdded[$nodeUid])
            ) {
                $conflicts[] = $this->conflict('parent_deleted_owned_node', $command);
                continue;
            }
            if ($command->operation === ThemePatchCommand::OP_MOVE_NODE) {
                if (!$newNodeExists && !isset($locallyAdded[$nodeUid])) {
                    $conflicts[] = $this->conflict('move_node_missing', $command);
                    continue;
                }
                if (!$this->nodeExists($newParent, (string)$command->anchorUid)
                    && !isset($locallyAdded[(string)$command->anchorUid])
                ) {
                    $conflicts[] = $this->conflict('move_anchor_missing', $command);
                }
                continue;
            }
            if ($command->operation === ThemePatchCommand::OP_ADD_NODE
                && $command->anchorUid !== null
                && !$this->nodeExists($newParent, $command->anchorUid)
                && !isset($locallyAdded[$command->anchorUid])
            ) {
                $conflicts[] = $this->conflict('add_anchor_missing', $command);
            }
        }

        return $conflicts;
    }

    /** @return array{0:bool,1:mixed} */
    public function readPath(array $payload, string $path): array
    {
        $cursor = $payload;
        foreach ($this->segments($path) as $segment) {
            if (!\is_array($cursor) || !\array_key_exists($segment, $cursor)) {
                return [false, null];
            }
            $cursor = $cursor[$segment];
        }

        return [true, $cursor];
    }

    /**
     * Later commands replace the same owned path; inherit removes it entirely.
     *
     * @param list<ThemePatchCommand> $current
     * @param list<ThemePatchCommand> $changes
     * @return list<ThemePatchCommand>
     */
    public function mergeOwnedCommands(array $current, array $changes): array
    {
        $map = [];
        foreach ($current as $command) {
            if ($command instanceof ThemePatchCommand && $command->operation !== ThemePatchCommand::OP_INHERIT) {
                $map[$command->path] = $command;
            }
        }
        foreach ($changes as $command) {
            if (!$command instanceof ThemePatchCommand) {
                throw new \InvalidArgumentException('theme_patch_command_type_invalid');
            }
            if ($command->operation === ThemePatchCommand::OP_INHERIT) {
                $prefix = \rtrim($command->path, '/') . '/';
                foreach (\array_keys($map) as $ownedPath) {
                    if ($ownedPath === $command->path || \str_starts_with($ownedPath, $prefix)) {
                        unset($map[$ownedPath]);
                    }
                }
            } else {
                if ($command->operation === ThemePatchCommand::OP_MOVE_NODE) {
                    // A move is the canonical owner of all three relative
                    // placement fields. Remove older explicit clears/sets so
                    // lexical path ordering cannot override the newer move.
                    foreach (['parent_uid', 'anchor_uid', 'position'] as $field) {
                        unset($map[\rtrim($command->path, '/') . '/' . $field]);
                    }
                } elseif ($command->operation === ThemePatchCommand::OP_SET
                    && \preg_match(
                        '#^(/nodes/[a-f0-9]{32})/(?:parent_uid|anchor_uid|position)$#D',
                        $command->path,
                        $placementPath,
                    ) === 1
                ) {
                    $rootCommand = $map[$placementPath[1]] ?? null;
                    if ($rootCommand instanceof ThemePatchCommand
                        && $rootCommand->operation === ThemePatchCommand::OP_MOVE_NODE
                    ) {
                        // Explicit field ownership is newer than the previous
                        // move (used by full snapshots to clear inheritance).
                        unset($map[$placementPath[1]]);
                    }
                }
                $existingAtPath = $map[$command->path] ?? null;
                if ($existingAtPath instanceof ThemePatchCommand
                    && $existingAtPath->operation === ThemePatchCommand::OP_ADD_NODE
                    && $command->operation === ThemePatchCommand::OP_REMOVE_NODE
                ) {
                    // Removing a node created only by this Scope restores the
                    // parent (where the random UID is absent); it is not a
                    // tombstone against inherited data.
                    unset($map[$command->path]);
                    $prefix = \rtrim($command->path, '/') . '/';
                    foreach (\array_keys($map) as $ownedPath) {
                        if (\str_starts_with($ownedPath, $prefix)) {
                            unset($map[$ownedPath]);
                        }
                    }
                    continue;
                }
                if ($command->operation === ThemePatchCommand::OP_MOVE_NODE
                    && $existingAtPath instanceof ThemePatchCommand
                ) {
                    if ($existingAtPath->operation === ThemePatchCommand::OP_REMOVE_NODE) {
                        throw new \InvalidArgumentException('theme_patch_move_removed_node');
                    }
                    if ($existingAtPath->operation === ThemePatchCommand::OP_ADD_NODE) {
                        $map[$command->path] = ThemePatchCommand::fromArray([
                            'op' => ThemePatchCommand::OP_ADD_NODE,
                            'path' => $existingAtPath->path,
                            'node_uid' => $existingAtPath->nodeUid,
                            'anchor_uid' => $command->anchorUid,
                            'position' => $command->position,
                            'value' => $existingAtPath->value,
                        ]);
                        continue;
                    }
                    if ($existingAtPath->operation === ThemePatchCommand::OP_SET
                        && \is_array($existingAtPath->value)
                    ) {
                        $value = $existingAtPath->value;
                        unset($value['parent_uid'], $value['anchor_uid'], $value['position']);
                        if ($command->position === 'inside') {
                            $value['parent_uid'] = $command->anchorUid;
                        } else {
                            $value['anchor_uid'] = $command->anchorUid;
                        }
                        $value['position'] = $command->position;
                        $map[$command->path] = ThemePatchCommand::fromArray([
                            'op' => ThemePatchCommand::OP_SET,
                            'path' => $existingAtPath->path,
                            'value' => $value,
                        ]);
                        continue;
                    }
                }
                // A field edit below a locally removed node means "restore the
                // parent node, then own this field". Keeping the ancestor
                // tombstone would otherwise manufacture a partial node map.
                $commandPath = \rtrim($command->path, '/');
                foreach (\array_keys($map) as $ownedPath) {
                    $owned = $map[$ownedPath] ?? null;
                    if ($owned instanceof ThemePatchCommand
                        && $owned->operation === ThemePatchCommand::OP_REMOVE_NODE
                        && \str_starts_with($commandPath, \rtrim($ownedPath, '/') . '/')
                    ) {
                        unset($map[$ownedPath]);
                    }
                }
                // A value/subtree replacement owns the whole addressed path.
                // Drop older descendant commands first so remove_node and
                // conflict rebaseline cannot accidentally recreate stale data.
                if (\in_array($command->operation, [
                    ThemePatchCommand::OP_SET,
                    ThemePatchCommand::OP_ADD_NODE,
                    ThemePatchCommand::OP_REMOVE_NODE,
                ], true)) {
                    $prefix = \rtrim($command->path, '/') . '/';
                    foreach (\array_keys($map) as $ownedPath) {
                        if (\str_starts_with($ownedPath, $prefix)) {
                            unset($map[$ownedPath]);
                        }
                    }
                }
                $map[$command->path] = $command;
            }
        }
        \ksort($map, SORT_STRING);

        return \array_values($map);
    }

    private function setPath(array &$payload, string $path, mixed $value): void
    {
        $segments = $this->segments($path);
        $last = \array_pop($segments);
        if ($last === null) {
            throw new \InvalidArgumentException('theme_patch_root_write_forbidden');
        }
        $cursor =& $payload;
        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !\is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor =& $cursor[$segment];
        }
        $cursor[$last] = $value;
        $this->ensureNodeIdentity($payload, $path);
    }

    private function removePath(array &$payload, string $path): void
    {
        $segments = $this->segments($path);
        $last = \array_pop($segments);
        if ($last === null) {
            return;
        }
        $cursor =& $payload;
        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !\is_array($cursor[$segment])) {
                return;
            }
            $cursor =& $cursor[$segment];
        }
        unset($cursor[$last]);
    }

    /** @return list<string> */
    private function segments(string $path): array
    {
        $raw = \explode('/', \ltrim($path, '/'));
        $segments = [];
        foreach ($raw as $segment) {
            $segments[] = \str_replace(['~1', '~0'], ['/', '~'], $segment);
        }

        return $segments;
    }

    private function nodeExists(array $payload, string $uid): bool
    {
        return $uid !== '' && $this->readPath($payload, '/nodes/' . $uid)[0];
    }

    private function nodeUidFromPath(string $path): ?string
    {
        if (\preg_match('#^/nodes/([a-f0-9]{32})(?:/|$)#D', $path, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /** @return array{code:string,path:string,node_uid:?string,anchor_uid:?string} */
    private function conflict(string $code, ThemePatchCommand $command): array
    {
        return [
            'code' => $code,
            'path' => $command->path,
            'node_uid' => $command->nodeUid ?? $this->nodeUidFromPath($command->path),
            'anchor_uid' => $command->anchorUid,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function ensureNodeIdentity(array &$payload, string $path): void
    {
        if (\preg_match('#^/nodes/([a-f0-9]{32})(?:/|$)#D', $path, $matches) !== 1) {
            return;
        }
        $uid = $matches[1];
        if (!isset($payload['nodes'][$uid]) || !\is_array($payload['nodes'][$uid])) {
            return;
        }
        if ((string)($payload['nodes'][$uid]['node_uid'] ?? '') === '') {
            $payload['nodes'][$uid]['node_uid'] = $uid;
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function normalizeNodeIdentities(array $payload): array
    {
        if (!isset($payload['nodes']) || !\is_array($payload['nodes'])) {
            return $payload;
        }
        foreach ($payload['nodes'] as $uid => &$node) {
            if (!\is_array($node)) {
                continue;
            }
            $key = \strtolower((string)$uid);
            if (\preg_match('/^[a-f0-9]{32}$/D', $key) === 1) {
                $node['node_uid'] = $key;
            }
        }
        unset($node);

        return $payload;
    }
}
