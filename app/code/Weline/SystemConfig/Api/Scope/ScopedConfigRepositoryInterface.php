<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Api\Scope;

/**
 * Public scoped configuration and version boundary.
 *
 * Every method exchanges scalars and arrays; ORM models and schema constants stay internal.
 */
interface ScopedConfigRepositoryInterface
{
    public function normalizeScope(?string $scope = null): string;

    public function normalizeLocale(?string $locale = null): string;

    /** @return list<string> */
    public function getFallbackScopes(?string $scope = null): array;

    /** @param array<string, mixed> $values @param array<string, mixed> $options @return array<string, mixed> */
    public function saveScopeConfig(
        string $module,
        string $area,
        array $values,
        ?string $scope = null,
        ?string $locale = null,
        array $options = [],
    ): array;

    /** @return array<string, mixed> */
    public function resolveConfig(
        string $key,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        mixed $default = null,
    ): array;

    /** @return array<int, array<string, mixed>> */
    public function getConfigVersions(
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        int $limit = 50,
    ): array;

    /** @return array<string, mixed>|null */
    public function getConfigVersionDetail(int $versionId): ?array;

    /** @return array<string, mixed>|null */
    public function getScopedConfigRow(
        string $key,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
    ): ?array;

    /** @param array<string, mixed>|null $row @return array<string, mixed>|null */
    public function maskSensitiveRow(?array $row): ?array;

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function rollbackScopeConfigVersion(int $versionId, array $options = []): array;
}
