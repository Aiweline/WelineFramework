<?php

declare(strict_types=1);

namespace Weline\Framework\Authorization\Resource;

/**
 * Builds a catalog from QueryProvider compiled registry + optional task scan rows.
 * Does not depend on Weline\Acl.
 */
final class AuthorizationResourceCatalogBuilder
{
    /**
     * Merge priority: existing definition wins over lower-priority derived ones.
     * Call with higher-priority definitions first (menu/controller), then query/task.
     *
     * @param list<AuthorizationResourceDefinition> $definitions
     * @param list<AuthorizationResourceBinding> $bindings
     */
    public function build(array $definitions, array $bindings): AuthorizationResourceCatalog
    {
        $merged = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof AuthorizationResourceDefinition) {
                continue;
            }
            $sourceId = \trim($definition->sourceId);
            if ($sourceId === '') {
                continue;
            }
            if (isset($merged[$sourceId])) {
                $this->assertCompatible($merged[$sourceId], $definition);
                continue;
            }
            $merged[$sourceId] = $definition;
        }

        $normalizedBindings = [];
        $seen = [];
        foreach ($bindings as $binding) {
            if (!$binding instanceof AuthorizationResourceBinding) {
                continue;
            }
            $sourceId = \trim($binding->sourceId);
            if ($sourceId === '') {
                continue;
            }
            $key = $binding->surface . "\0" . $binding->surfaceId . "\0" . $sourceId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalizedBindings[] = $binding;
            if (!isset($merged[$sourceId])) {
                $parsed = SourceIdParser::parse($sourceId);
                $merged[$sourceId] = new AuthorizationResourceDefinition(
                    sourceId: $sourceId,
                    name: $parsed['code'] ?? $sourceId,
                    description: '',
                    module: $parsed['module'] ?? $binding->module,
                    resourceType: $this->resourceTypeForSurface($binding->surface),
                    origin: $this->originForSurface($binding->surface),
                    tags: $parsed['tags'] ?? [],
                    code: $parsed['code'] ?? '',
                    metadata: [
                        'derived_from_binding' => true,
                        'surface' => $binding->surface,
                        'surface_id' => $binding->surfaceId,
                    ],
                );
            }
        }

        \ksort($merged, SORT_STRING);
        return new AuthorizationResourceCatalog($merged, $normalizedBindings);
    }

    /**
     * Extract source/param_map bindings from compiled query_providers registry.
     *
     * @param array<string,mixed> $registry
     * @return array{definitions:list<AuthorizationResourceDefinition>,bindings:list<AuthorizationResourceBinding>}
     */
    public function fromQueryProviderRegistry(array $registry): array
    {
        $definitions = [];
        $bindings = [];
        $operations = $registry['operations'] ?? [];
        $descriptors = $registry['descriptors'] ?? [];
        if (!\is_array($operations)) {
            return ['definitions' => [], 'bindings' => []];
        }

        foreach ($operations as $providerName => $providerOps) {
            if (!\is_array($providerOps)) {
                continue;
            }
            $descriptor = \is_array($descriptors[$providerName] ?? null) ? $descriptors[$providerName] : [];
            $module = \trim((string)($descriptor['module'] ?? ''));
            foreach ($providerOps as $operationName => $operation) {
                if (!\is_array($operation)) {
                    continue;
                }
                $acl = $operation['backend_acl'] ?? null;
                if (!\is_array($acl)) {
                    continue;
                }
                $kind = (string)($acl['kind'] ?? '');
                if ($kind === 'self') {
                    continue;
                }
                $surfaceId = (string)$providerName . '.' . (string)$operationName;
                $sourceIds = [];
                if ($kind === 'source') {
                    $sourceId = (string)($acl['source_id'] ?? '');
                    if ($sourceId !== '') {
                        $sourceIds[] = $sourceId;
                    }
                } elseif ($kind === 'param_map') {
                    foreach (($acl['map'] ?? []) as $mapped) {
                        if (\is_string($mapped) && $mapped !== '') {
                            $sourceIds[] = $mapped;
                        }
                    }
                }
                foreach (\array_unique($sourceIds) as $sourceId) {
                    $parsed = SourceIdParser::parse($sourceId);
                    $bindings[] = new AuthorizationResourceBinding(
                        sourceId: $sourceId,
                        surface: AuthorizationResourceBinding::SURFACE_QUERY_PROVIDER,
                        surfaceId: $surfaceId,
                        module: $module !== '' ? $module : (string)($parsed['module'] ?? ''),
                        metadata: [
                            'provider' => (string)$providerName,
                            'operation' => (string)$operationName,
                            'acl_kind' => $kind,
                        ],
                    );
                    // Definition only when tagged as query-only (has query tag prefix) —
                    // shared HTTP sources are filled later by controller merge (higher priority).
                    if ($parsed !== null && ($parsed['tags'][0] ?? null) === 'query') {
                        $definitions[] = new AuthorizationResourceDefinition(
                            sourceId: $sourceId,
                            name: (string)($operation['description'] ?? $parsed['code']),
                            description: (string)($operation['description'] ?? ''),
                            module: $module !== '' ? $module : $parsed['module'],
                            resourceType: AuthorizationResourceType::QUERY,
                            origin: AuthorizationResourceOrigin::QUERY_PROVIDER,
                            tags: $parsed['tags'],
                            code: $parsed['code'],
                            metadata: [
                                'provider' => (string)$providerName,
                                'operation' => (string)$operationName,
                            ],
                        );
                    }
                }
            }
        }

        return ['definitions' => $definitions, 'bindings' => $bindings];
    }

    /**
     * @param list<array<string,mixed>> $taskRows each: module, task_id, backend_acl (string|array), name?, description?
     * @return array{definitions:list<AuthorizationResourceDefinition>,bindings:list<AuthorizationResourceBinding>}
     */
    public function fromResumableTaskRows(array $taskRows): array
    {
        $definitions = [];
        $bindings = [];
        foreach ($taskRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $module = \trim((string)($row['module'] ?? ''));
            $taskId = \trim((string)($row['task_id'] ?? $row['id'] ?? ''));
            $acl = $row['backend_acl'] ?? null;
            $sourceId = '';
            if (\is_string($acl)) {
                $sourceId = \trim($acl);
            } elseif (\is_array($acl)) {
                $sourceId = \trim((string)($acl['source_id'] ?? ''));
                if ($sourceId === '' && isset($acl['code'])) {
                    $tags = SourceIdParser::mergeTags(
                        ['task'],
                        \is_array($acl['tags'] ?? null) ? $acl['tags'] : [],
                    );
                    $sourceId = SourceIdParser::compose(
                        \trim((string)($acl['module'] ?? $module)),
                        $tags,
                        (string)$acl['code'],
                    );
                }
            }
            if ($sourceId === '' || $taskId === '') {
                continue;
            }
            $parsed = SourceIdParser::parse($sourceId);
            $bindings[] = new AuthorizationResourceBinding(
                sourceId: $sourceId,
                surface: AuthorizationResourceBinding::SURFACE_RESUMABLE_TASK,
                surfaceId: $module . '::' . $taskId,
                module: $module !== '' ? $module : (string)($parsed['module'] ?? ''),
                metadata: ['task_id' => $taskId],
            );
            $definitions[] = new AuthorizationResourceDefinition(
                sourceId: $sourceId,
                name: (string)($row['name'] ?? ($parsed['code'] ?? $taskId)),
                description: (string)($row['description'] ?? ''),
                module: $module !== '' ? $module : (string)($parsed['module'] ?? ''),
                resourceType: AuthorizationResourceType::RESUMABLE_TASK,
                origin: AuthorizationResourceOrigin::RESUMABLE_TASK,
                tags: $parsed['tags'] ?? [],
                code: $parsed['code'] ?? '',
                metadata: ['task_id' => $taskId],
            );
        }
        return ['definitions' => $definitions, 'bindings' => $bindings];
    }

    private function assertCompatible(
        AuthorizationResourceDefinition $existing,
        AuthorizationResourceDefinition $incoming,
    ): void {
        if ($existing->resourceType !== $incoming->resourceType
            && $this->priority($incoming->origin) >= $this->priority($existing->origin)
        ) {
            // Lower or equal priority cannot override; higher already skipped by caller order.
            // Conflict only when same priority and different core fields.
        }
        if ($this->priority($incoming->origin) !== $this->priority($existing->origin)) {
            return;
        }
        if ($existing->resourceType !== $incoming->resourceType
            || $existing->isBackend !== $incoming->isBackend
            || $existing->module !== $incoming->module
        ) {
            throw new \RuntimeException(
                'ACL resource definition conflict for ' . $existing->sourceId
                . ': incompatible core metadata from origin ' . $incoming->origin,
            );
        }
    }

    private function priority(string $origin): int
    {
        return match ($origin) {
            AuthorizationResourceOrigin::MENU_XML,
            AuthorizationResourceOrigin::CONTROLLER_ATTRIBUTE => 300,
            AuthorizationResourceOrigin::SYSTEM_OPERATION => 200,
            AuthorizationResourceOrigin::QUERY_PROVIDER,
            AuthorizationResourceOrigin::RESUMABLE_TASK => 100,
            default => 0,
        };
    }

    private function resourceTypeForSurface(string $surface): string
    {
        return match ($surface) {
            AuthorizationResourceBinding::SURFACE_QUERY_PROVIDER => AuthorizationResourceType::QUERY,
            AuthorizationResourceBinding::SURFACE_RESUMABLE_TASK => AuthorizationResourceType::RESUMABLE_TASK,
            AuthorizationResourceBinding::SURFACE_SYSTEM_OPERATION => AuthorizationResourceType::OPERATION,
            AuthorizationResourceBinding::SURFACE_MENU => AuthorizationResourceType::MENU,
            default => AuthorizationResourceType::HTTP,
        };
    }

    private function originForSurface(string $surface): string
    {
        return match ($surface) {
            AuthorizationResourceBinding::SURFACE_QUERY_PROVIDER => AuthorizationResourceOrigin::QUERY_PROVIDER,
            AuthorizationResourceBinding::SURFACE_RESUMABLE_TASK => AuthorizationResourceOrigin::RESUMABLE_TASK,
            AuthorizationResourceBinding::SURFACE_SYSTEM_OPERATION => AuthorizationResourceOrigin::SYSTEM_OPERATION,
            AuthorizationResourceBinding::SURFACE_MENU => AuthorizationResourceOrigin::MENU_XML,
            default => AuthorizationResourceOrigin::CONTROLLER_ATTRIBUTE,
        };
    }
}
