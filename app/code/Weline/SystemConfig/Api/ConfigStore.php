<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Api;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * Public configuration storage boundary.
 *
 * New scope-aware writes must pass an explicit scope. setConfig() remains only
 * as the compatibility path for existing global configuration writers.
 */
final class ConfigStore
{
    public const SCOPE_GLOBAL = ConfigReader::SCOPE_GLOBAL;
    public const LOCALE_DEFAULT = ConfigReader::LOCALE_DEFAULT;
    public const area_BACKEND = ConfigReader::area_BACKEND;
    public const area_FRONTEND = ConfigReader::area_FRONTEND;

    private readonly SystemConfig $config;

    public function __construct()
    {
        $this->config = ObjectManager::getInstance(SystemConfig::class);
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

    public function setConfig(string $key, string $value, string $module, string $area): bool
    {
        return $this->config->setConfig($key, $value, $module, $area);
    }

    public function setScopedConfig(
        string $key,
        mixed $value,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        array $options = [],
    ): bool {
        return $this->config->setScopedConfig($key, $value, $module, $area, $scope, $locale, $options);
    }

    public function deleteScopedConfig(
        string $key,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        array $options = [],
    ): bool {
        return $this->config->deleteScopedConfig($key, $module, $area, $scope, $locale, $options);
    }
}
