<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Config;

use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;

/**
 * Public backend-area system configuration facade.
 *
 * It fixes the area to backend and never exposes SystemConfig ORM/query state.
 */
class BackendConfigStore
{
    private const AREA = ConfigReader::area_BACKEND;

    public function __construct(
        private readonly ConfigReader $reader,
        private readonly ConfigStore $store,
    ) {
    }

    public function getConfig(string $key, string $module): mixed
    {
        return $this->reader->getConfig(
            $key,
            $module,
            self::AREA,
            null,
            ConfigReader::SCOPE_GLOBAL,
            ConfigReader::LOCALE_DEFAULT,
        );
    }

    /** @return array<string, mixed> */
    public function getConfigs(string $module): array
    {
        return $this->reader->getConfigMapByModule(
            $module,
            self::AREA,
            ConfigReader::SCOPE_GLOBAL,
            ConfigReader::LOCALE_DEFAULT,
        );
    }

    public function setConfig(string $key, string $value, string $module): bool
    {
        return $this->store->setConfig($key, $value, $module, self::AREA);
    }

    public function deleteConfig(string $key, string $module): bool
    {
        return $this->store->deleteScopedConfig(
            $key,
            $module,
            self::AREA,
            ConfigReader::SCOPE_GLOBAL,
            ConfigReader::LOCALE_DEFAULT,
        );
    }
}
