<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Theme\Api\Scoped\ThemePatchCommand;

/** Convert two canonical layout payloads into stable-UID per-path ownership. */
final class ThemeLayoutPayloadDiffer
{
    /** @return list<ThemePatchCommand> */
    public function diff(array $parent, array $target): array
    {
        $commands = [];
        $parentNodes = \is_array($parent['nodes'] ?? null) ? $parent['nodes'] : [];
        $targetNodes = \is_array($target['nodes'] ?? null) ? $target['nodes'] : [];

        foreach (\array_diff_key($parentNodes, $targetNodes) as $uid => $_node) {
            $commands[] = ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_REMOVE_NODE,
                'path' => '/nodes/' . $uid,
                'node_uid' => (string)$uid,
            ]);
        }
        foreach (\array_diff_key($targetNodes, $parentNodes) as $uid => $node) {
            $node = \is_array($node) ? $node : [];
            $placement = $this->placementCommandData($node);
            unset($node['parent_uid'], $node['anchor_uid'], $node['position']);
            $commands[] = ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_ADD_NODE,
                'path' => '/nodes/' . $uid,
                'node_uid' => (string)$uid,
                'value' => $node,
                ...$placement,
            ]);
        }
        foreach (\array_intersect_key($targetNodes, $parentNodes) as $uid => $node) {
            $parentNode = \is_array($parentNodes[$uid]) ? $parentNodes[$uid] : [];
            $targetNode = \is_array($node) ? $node : [];
            if ($this->placementSignature($parentNode) !== $this->placementSignature($targetNode)) {
                $placement = $this->placementCommandData($targetNode);
                if ($placement !== []) {
                    $commands[] = ThemePatchCommand::fromArray([
                        'op' => ThemePatchCommand::OP_MOVE_NODE,
                        'path' => '/nodes/' . $uid,
                        'node_uid' => (string)$uid,
                        ...$placement,
                    ]);
                } else {
                    // A full legacy snapshot has no delete-key operation. An
                    // explicit null is a legal owned value and semantically
                    // clears inherited relative placement without copying the
                    // remaining parent node fields.
                    foreach (['parent_uid', 'anchor_uid', 'position'] as $field) {
                        $commands[] = ThemePatchCommand::fromArray([
                            'op' => ThemePatchCommand::OP_SET,
                            'path' => '/nodes/' . $uid . '/' . $field,
                            'value' => null,
                        ]);
                    }
                }
            }
            unset(
                $parentNode['parent_uid'],
                $parentNode['anchor_uid'],
                $parentNode['position'],
                $targetNode['parent_uid'],
                $targetNode['anchor_uid'],
                $targetNode['position'],
            );
            $this->diffValue(
                $parentNode,
                $targetNode,
                '/nodes/' . $uid,
                $commands,
            );
        }

        $parentSelection = \is_array($parent['selection'] ?? null) ? $parent['selection'] : [];
        $targetSelection = \is_array($target['selection'] ?? null) ? $target['selection'] : [];
        foreach ($targetSelection as $key => $value) {
            $key = (string)$key;
            if (\preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:@~-]{0,127}$/D', $key) !== 1) {
                continue;
            }
            $this->diffValue(
                \array_key_exists($key, $parentSelection) ? $parentSelection[$key] : null,
                $value,
                '/selection/' . $key,
                $commands,
            );
        }

        return $commands;
    }

    /** @param array<string,mixed> $node @return array{anchor_uid:string,position:string}|array{} */
    private function placementCommandData(array $node): array
    {
        $position = (string)($node['position'] ?? '');
        $relativeUid = $position === 'inside'
            ? (string)($node['parent_uid'] ?? '')
            : (string)($node['anchor_uid'] ?? '');
        if (\preg_match('/^[a-f0-9]{32}$/D', $relativeUid) !== 1
            || !\in_array($position, ['inside', 'before', 'after'], true)
        ) {
            return [];
        }

        return ['anchor_uid' => $relativeUid, 'position' => $position];
    }

    /** @param array<string,mixed> $node */
    private function placementSignature(array $node): string
    {
        $placement = $this->placementCommandData($node);

        return ($placement['position'] ?? '') . ':' . ($placement['anchor_uid'] ?? '');
    }

    /** @param list<ThemePatchCommand> $commands */
    private function diffValue(mixed $parent, mixed $target, string $path, array &$commands): void
    {
        if ($parent === $target) {
            return;
        }
        if (!\is_array($parent)
            || !\is_array($target)
            || \array_is_list($parent)
            || \array_is_list($target)
        ) {
            $commands[] = ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_SET,
                'path' => $path,
                'value' => $target,
            ]);
            return;
        }

        foreach ($target as $key => $value) {
            $segment = (string)$key;
            $escapedSegment = \str_replace(['~', '/'], ['~0', '~1'], $segment);
            if (\preg_match('/^(?:0|[1-9][0-9]*)$/D', $segment) === 1
                || \preg_match('/^[a-zA-Z0-9_.:@~-]+$/D', $escapedSegment) !== 1
            ) {
                $commands[] = ThemePatchCommand::fromArray([
                    'op' => ThemePatchCommand::OP_SET,
                    'path' => $path,
                    'value' => $target,
                ]);
                return;
            }
            $this->diffValue(
                \array_key_exists($key, $parent) ? $parent[$key] : null,
                $value,
                $path . '/' . $escapedSegment,
                $commands,
            );
        }
    }
}
