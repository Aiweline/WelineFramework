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

        return [
            'valid' => $errors === [],
            'errors' => $errors,
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
}
