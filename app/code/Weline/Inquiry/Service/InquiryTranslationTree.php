<?php

declare(strict_types=1);

namespace Weline\Inquiry\Service;

/**
 * Flatten / rehydrate inquiry translation trees (locale copy payloads).
 */
final class InquiryTranslationTree
{
    /**
     * @param array<string, mixed> $node
     * @return array<string, string> path => leaf text
     */
    public function flatten(array $node, string $prefix = ''): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            $segment = (string)$key;
            if ($segment === '') {
                continue;
            }
            $path = $prefix === '' ? $segment : ($prefix . '.' . $segment);
            if (is_array($value)) {
                $out += $this->flatten($value, $path);
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
                $text = trim((string)$value);
                if ($text !== '') {
                    $out[$path] = $text;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<string, string> $leaves path => text
     * @return array<string, mixed>
     */
    public function mergeLeaves(array $tree, array $leaves, bool $overwrite): array
    {
        foreach ($leaves as $path => $text) {
            $text = trim((string)$text);
            if ($text === '' || !is_string($path) || $path === '') {
                continue;
            }
            $existing = $this->getByPath($tree, $path);
            if (!$overwrite && is_string($existing) && trim($existing) !== '') {
                continue;
            }
            $this->setByPath($tree, $path, $text);
        }

        return $tree;
    }

    /**
     * @param array<string, mixed> $tree
     */
    public function getByPath(array $tree, string $path): mixed
    {
        $cursor = $tree;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param array<string, mixed> $tree
     */
    public function setByPath(array &$tree, string $path, string $value): void
    {
        $segments = explode('.', $path);
        $cursor = &$tree;
        $last = array_pop($segments);
        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
        $cursor[$last] = $value;
    }
}
