<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\SystemConfig\Api\Scope\ScopedConfigRepositoryInterface;
use Weline\SystemConfig\Model\SystemConfig;

/** Keeps SystemConfig ORM state behind the public scoped-config contract. */
final class ScopedConfigRepository implements ScopedConfigRepositoryInterface
{
    public function __construct(
        private readonly SystemConfig $config,
    ) {
    }

    public function normalizeScope(?string $scope = null): string
    {
        return $this->config->normalizeScope($scope);
    }

    public function normalizeLocale(?string $locale = null): string
    {
        return $this->config->normalizeLocale($locale);
    }

    public function getFallbackScopes(?string $scope = null): array
    {
        return $this->config->getFallbackScopes($scope);
    }

    public function saveScopeConfig(
        string $module,
        string $area,
        array $values,
        ?string $scope = null,
        ?string $locale = null,
        array $options = [],
    ): array {
        return $this->config->saveScopeConfig($module, $area, $values, $scope, $locale, $options);
    }

    public function resolveConfig(
        string $key,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        mixed $default = null,
    ): array {
        return $this->config->resolveConfig($key, $module, $area, $scope, $locale, $default);
    }

    public function getConfigVersions(
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        int $limit = 50,
    ): array {
        return $this->config->getConfigVersions($module, $area, $scope, $locale, $limit);
    }

    public function getConfigVersionDetail(int $versionId): ?array
    {
        return $this->config->getConfigVersionDetail($versionId);
    }

    public function getScopedConfigRow(
        string $key,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
    ): ?array {
        return $this->config->getScopedConfigRow($key, $module, $area, $scope, $locale);
    }

    public function maskSensitiveRow(?array $row): ?array
    {
        return $this->config->maskSensitiveRow($row);
    }

    public function rollbackScopeConfigVersion(int $versionId, array $options = []): array
    {
        return $this->config->rollbackScopeConfigVersion($versionId, $options);
    }
}
