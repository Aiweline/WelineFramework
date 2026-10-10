<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Support;

/**
 * Helpers for slim widget.php listings (path strings + optional template overrides).
 */
final class SlimWidgetPhpListing
{
    /**
     * @return list<string>
     */
    public static function templatePaths(string $widgetPhpFile): array
    {
        /** @var mixed $data */
        $data = require $widgetPhpFile;
        if (!\is_array($data)) {
            return [];
        }
        $paths = [];
        foreach ($data as $key => $entry) {
            if (\is_string($entry)) {
                $paths[] = $entry;
                continue;
            }
            if (\is_array($entry)) {
                if (isset($entry['template']) && \is_string($entry['template'])) {
                    $paths[] = $entry['template'];
                } elseif (\is_string($key) && \str_contains($key, '::')) {
                    $paths[] = $key;
                }
            }
        }

        return \array_values(\array_unique($paths));
    }

    public static function listsTemplate(string $widgetPhpFile, string $logicalTemplate): bool
    {
        return \in_array($logicalTemplate, self::templatePaths($widgetPhpFile), true);
    }

    public static function resolveViewPath(string $logicalTemplate): string
    {
        if (!\preg_match('/^([A-Za-z0-9_]+)::(.+)$/', $logicalTemplate, $m)) {
            throw new \InvalidArgumentException('bad template: ' . $logicalTemplate);
        }
        $parts = \explode('_', $m[1], 2);
        if (\count($parts) !== 2) {
            throw new \InvalidArgumentException('bad module: ' . $m[1]);
        }
        // Support → Test → Widget → Weline → code → app → repo
        $root = \defined('BP') ? \rtrim((string)BP, '/\\') : \dirname(__DIR__, 6);

        return $root . '/app/code/' . $parts[0] . '/' . $parts[1] . '/view/' . $m[2];
    }
}
