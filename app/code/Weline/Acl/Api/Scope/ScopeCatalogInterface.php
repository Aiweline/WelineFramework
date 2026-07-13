<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Scope;

/**
 * Data-only ACL scope catalog boundary.
 *
 * Rows use the stable ACL storage keys declared below; no ORM object or query
 * builder crosses the module boundary.
 */
interface ScopeCatalogInterface
{
    public const FIELD_SOURCE_ID = 'source_id';
    public const FIELD_SOURCE_NAME = 'source_name';
    public const FIELD_DOCUMENT = 'document';
    public const FIELD_MODULE = 'module';
    public const FIELD_SCOPE_GROUP = 'scope_group';
    public const FIELD_ACCESS_MODE = 'access_mode';
    public const FIELD_ROUTE = 'route';
    public const FIELD_METHOD = 'method';
    public const FIELD_API_EXPOSABLE = 'api_exposable';
    public const ACCESS_MODE_EDIT = 'edit';

    /** @return list<array<string, mixed>> */
    public function listExposableRows(): array;

    /**
     * @param list<string> $sourceIds
     * @return list<array<string, mixed>>
     */
    public function getExposableRowsBySourceIds(array $sourceIds): array;

    /**
     * @param list<string> $sourceIds
     * @return list<array<string, mixed>>
     */
    public function getEnabledRowsBySourceIds(array $sourceIds): array;

    public function normalizeAccessMode(?string $accessMode = null, ?string $httpMethod = null): string;
}
