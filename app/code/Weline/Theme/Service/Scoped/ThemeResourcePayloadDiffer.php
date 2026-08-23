<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemePatchCommand;

/**
 * Converts sparse legacy resources into per-path ownership commands.
 *
 * Missing target keys deliberately remain inherited. Legacy Meta, appearance
 * and dictionary rows are sparse overrides, unlike the layout node snapshot
 * whose deletion semantics are handled by ThemeLayoutPayloadDiffer.
 */
final class ThemeResourcePayloadDiffer
{
    public function __construct(
        private readonly ThemeLayoutPayloadDiffer $layouts,
    ) {
    }

    /** @return list<ThemePatchCommand> */
    public function diff(ThemeEditorContext $context, array $parent, array $target): array
    {
        if ($context->resourceType === ThemeEditorContext::RESOURCE_LAYOUT) {
            return $this->layouts->diff($parent, $target);
        }
        if ($context->resourceType === ThemeEditorContext::RESOURCE_THEME_BINDING) {
            if (!\array_key_exists('theme_id', $target)
                || (($parent['theme_id'] ?? null) === $target['theme_id'])
            ) {
                return [];
            }

            return [ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_SET,
                'path' => '/theme_id',
                'value' => $target['theme_id'],
            ])];
        }

        $roots = match ($context->resourceType) {
            ThemeEditorContext::RESOURCE_META => ['values'],
            ThemeEditorContext::RESOURCE_APPEARANCE => ['tokens', 'disks'],
            ThemeEditorContext::RESOURCE_I18N => ['translations'],
            default => [],
        };
        $commands = [];
        foreach ($roots as $root) {
            $parentValues = \is_array($parent[$root] ?? null) ? $parent[$root] : [];
            $targetValues = \is_array($target[$root] ?? null) ? $target[$root] : [];
            foreach ($targetValues as $key => $value) {
                $segment = $this->pathSegment($key, $root === 'translations');
                if ($segment === null) {
                    continue;
                }
                $this->diffValue(
                    \array_key_exists($key, $parentValues),
                    $parentValues[$key] ?? null,
                    $value,
                    '/' . $root . '/' . $segment,
                    $commands,
                );
            }
        }

        return $commands;
    }

    /** @param list<ThemePatchCommand> $commands */
    private function diffValue(
        bool $parentExists,
        mixed $parent,
        mixed $target,
        string $path,
        array &$commands,
    ): void {
        if ($parentExists && $parent === $target) {
            return;
        }
        if (!\is_array($target)
            || \array_is_list($target)
            || $target === []
            || ($parentExists && (!\is_array($parent) || \array_is_list($parent)))
        ) {
            $commands[] = $this->set($path, $target);
            return;
        }

        $parentMap = \is_array($parent) && !\array_is_list($parent) ? $parent : [];
        foreach ($target as $key => $value) {
            $segment = $this->pathSegment($key);
            if ($segment === null) {
                // A value containing an unaddressable map key is owned at its
                // nearest valid ancestor; never invent a lossy escaped identity.
                $commands[] = $this->set($path, $target);
                return;
            }
            $this->diffValue(
                \array_key_exists($key, $parentMap),
                $parentMap[$key] ?? null,
                $value,
                $path . '/' . $segment,
                $commands,
            );
        }
    }

    private function set(string $path, mixed $value): ThemePatchCommand
    {
        return ThemePatchCommand::fromArray([
            'op' => ThemePatchCommand::OP_SET,
            'path' => $path,
            'value' => $value,
        ]);
    }

    private function pathSegment(int|string $value, bool $allowStableUid = false): ?string
    {
        $value = (string)$value;
        if ((\preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1
                && !($allowStableUid && \preg_match('/^[0-9]{32}$/D', $value) === 1))
            || $value === ''
            || \strlen($value) > 255
            || \str_contains($value, "\0")
        ) {
            return null;
        }

        return \str_replace(['~', '/'], ['~0', '~1'], $value);
    }
}
