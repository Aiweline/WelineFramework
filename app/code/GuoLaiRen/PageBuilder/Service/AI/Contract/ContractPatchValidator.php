<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class ContractPatchValidator
{
    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $next
     * @param array<string, mixed> $permissionMatrix
     * @param list<string> $frozenFields
     * @return array{valid:bool,errors:list<string>}
     */
    public function validate(array $previous, array $next, array $permissionMatrix = [], array $frozenFields = []): array
    {
        $errors = [];
        $frozenFields = $frozenFields !== [] ? $frozenFields : $this->extractFrozenFields($previous);

        foreach ($frozenFields as $path) {
            if ($path === '') {
                continue;
            }
            $before = $this->valuesForPattern($previous, $path);
            $after = $this->valuesForPattern($next, $path);
            if ($before !== $after) {
                $errors[] = 'Frozen field changed: ' . $path;
            }
        }

        $readOnly = \array_values(\array_filter(\array_map('strval', \is_array($permissionMatrix['read_only'] ?? null) ? $permissionMatrix['read_only'] : [])));
        foreach ($readOnly as $path) {
            $before = $this->valuesForPattern($previous, $path);
            $after = $this->valuesForPattern($next, $path);
            if ($before !== $after) {
                $errors[] = 'Read-only field changed: ' . $path;
            }
        }

        $changedPaths = $this->collectChangedPaths($previous, $next);
        $forbidden = $this->patternList($permissionMatrix['forbidden'] ?? []);
        foreach ($changedPaths as $path) {
            foreach ($forbidden as $pattern) {
                if ($this->pathMatchesPattern($path, $pattern)) {
                    $errors[] = 'Forbidden field changed: ' . $path;
                    break;
                }
            }
        }

        $allowedPatterns = \array_values(\array_unique(\array_merge(
            $this->patternList($permissionMatrix['patch'] ?? []),
            $this->patternList($permissionMatrix['can_patch'] ?? []),
            $this->patternList($permissionMatrix['create'] ?? []),
            $this->patternList($permissionMatrix['can_create'] ?? [])
        )));
        if ($this->shouldEnforceAllowedPatterns($allowedPatterns, $previous, $next)) {
            foreach ($changedPaths as $path) {
                if ($this->isAdministrativePath($path)) {
                    continue;
                }
                $allowed = false;
                foreach ($allowedPatterns as $pattern) {
                    if ($this->pathMatchesPattern($path, $pattern)) {
                        $allowed = true;
                        break;
                    }
                }
                if (!$allowed) {
                    $errors[] = 'Path is not patchable by permission_matrix: ' . $path;
                }
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => \array_values(\array_unique($errors)),
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    private function extractFrozenFields(array $contract): array
    {
        return \array_values(\array_filter(\array_map('strval', \is_array($contract['frozen_fields'] ?? null) ? $contract['frozen_fields'] : [])));
    }

    /**
     * @param array<string, mixed> $source
     * @return list<mixed>
     */
    private function valuesForPattern(array $source, string $path): array
    {
        return $this->walkPath([$source], \explode('.', $path));
    }

    /**
     * @param list<mixed> $nodes
     * @param list<string> $segments
     * @return list<mixed>
     */
    private function walkPath(array $nodes, array $segments): array
    {
        if ($segments === []) {
            return $nodes;
        }

        $segment = \array_shift($segments);
        $next = [];
        foreach ($nodes as $node) {
            if (!\is_array($node)) {
                continue;
            }
            if ($segment === '*') {
                foreach ($node as $value) {
                    $next[] = $value;
                }
                continue;
            }
            if (\array_key_exists((string)$segment, $node)) {
                $next[] = $node[(string)$segment];
            }
        }

        return $this->walkPath($next, $segments);
    }

    /**
     * @return list<string>
     */
    private function patternList(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        return \array_values(\array_filter(\array_map(
            static fn(mixed $value): string => \trim((string)$value),
            $values
        ), static fn(string $value): bool => $value !== ''));
    }

    /**
     * @return list<string>
     */
    private function collectChangedPaths(mixed $previous, mixed $next, string $prefix = ''): array
    {
        if (\is_array($previous) && \is_array($next)) {
            $paths = [];
            $keys = \array_values(\array_unique(\array_merge(\array_keys($previous), \array_keys($next))));
            foreach ($keys as $key) {
                $segment = (string)$key;
                $path = $prefix !== '' ? ($prefix . '.' . $segment) : $segment;
                $paths = \array_merge(
                    $paths,
                    $this->collectChangedPaths($previous[$key] ?? null, $next[$key] ?? null, $path)
                );
            }

            return \array_values(\array_unique($paths));
        }

        if ($previous !== $next) {
            return $prefix !== '' ? [$prefix] : ['__root__'];
        }

        return [];
    }

    private function pathMatchesPattern(string $path, string $pattern): bool
    {
        $path = \trim($path);
        $pattern = \trim($pattern);
        if ($path === '' || $pattern === '') {
            return false;
        }
        if ($path === $pattern) {
            return true;
        }
        if (\str_starts_with($path, $pattern . '.')) {
            return true;
        }
        if (\str_ends_with($pattern, '.*')) {
            $prefix = \substr($pattern, 0, -2);
            return $path === $prefix || \str_starts_with($path, $prefix . '.');
        }

        $pathSegments = \explode('.', $path);
        $patternSegments = \explode('.', $pattern);
        if (\count($pathSegments) !== \count($patternSegments)) {
            return false;
        }
        foreach ($patternSegments as $index => $segment) {
            if ($segment === '*') {
                continue;
            }
            if ($segment !== $pathSegments[$index]) {
                return false;
            }
        }

        return true;
    }

    private function shouldEnforceAllowedPatterns(array $patterns, array $previous, array $next): bool
    {
        if ($patterns === []) {
            return false;
        }

        foreach ($patterns as $pattern) {
            $root = \trim((string)\strtok($pattern, '.'));
            if ($root === '' || $root === '*') {
                return true;
            }
            if (\array_key_exists($root, $previous) || \array_key_exists($root, $next)) {
                return true;
            }
        }

        return false;
    }

    private function isAdministrativePath(string $path): bool
    {
        foreach ([
            'contract_meta.status',
            'contract_meta.confirmed_at',
            'contract_meta.signature',
            'contract_meta.created_at',
            'contract_meta.source_signature',
        ] as $prefix) {
            if ($path === $prefix || \str_starts_with($path, $prefix . '.')) {
                return true;
            }
        }

        return false;
    }
}
