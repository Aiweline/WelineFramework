<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Api;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

final class ConfigReader
{
    public const SCOPE_GLOBAL = 'default.default.default';
    public const LOCALE_DEFAULT = 'default';
    public const area_BACKEND = 'backend';
    public const area_FRONTEND = 'frontend';

    private readonly SystemConfig $config;

    public function __construct()
    {
        $this->config = ObjectManager::getInstance(SystemConfig::class);
    }

    public function get(
        string $key,
        string $module,
        string $area,
        mixed $default = null,
        ?string $scope = null,
    ): mixed {
        return $this->config->getConfig(
            key: $key,
            module: $module,
            area: $area,
            default: $default,
            scope: $scope,
        );
    }

    public function getConfig(
        string $key,
        string $module,
        string $area,
        mixed $default = null,
        ?string $scope = null,
        ?string $locale = null,
    ): mixed {
        return $this->config->getConfig($key, $module, $area, $default, $scope, $locale);
    }

    /** @return array<string, mixed> */
    public function getConfigMapByModule(
        string $module,
        string $area = self::area_FRONTEND,
        ?string $scope = null,
        ?string $locale = null,
    ): array {
        return $this->config->getConfigMapByModule($module, $area, $scope, $locale);
    }

    public function normalizeScope(?string $scope = null): string
    {
        return $this->config->normalizeScope($scope);
    }

    public function normalizeLocale(?string $locale = null): string
    {
        return $this->config->normalizeLocale($locale);
    }

    /** @return list<string> */
    public function getFallbackScopes(?string $scope = null): array
    {
        return $this->config->getFallbackScopes($scope);
    }

    public function globalScope(): string
    {
        return self::SCOPE_GLOBAL;
    }

    public function frontendArea(): string
    {
        return self::area_FRONTEND;
    }

    public function backendArea(): string
    {
        return self::area_BACKEND;
    }

}
