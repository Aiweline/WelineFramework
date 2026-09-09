<?php

declare(strict_types=1);

namespace Weline\Geo\Service;

use Weline\SystemConfig\Api\ConfigReader;

/**
 * GEO push settings from SystemConfig (unified module config).
 */
final class GeoPushSettings
{
    public const MODULE = 'Weline_Geo';
    public const AREA = ConfigReader::area_BACKEND;
    public const KEY_SCHEDULED_ENABLED = 'geo/push/scheduled_enabled';

    public function __construct(
        private readonly ConfigReader $config,
    ) {
    }

    public function isScheduledPushEnabled(): bool
    {
        return $this->boolean(self::KEY_SCHEDULED_ENABLED, false);
    }

    private function boolean(string $key, bool $default): bool
    {
        $raw = $this->config->getConfig(
            $key,
            self::MODULE,
            self::AREA,
            $default ? '1' : '0',
            null,
            ConfigReader::LOCALE_DEFAULT,
        );
        $normalized = strtolower(trim((string)$raw));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
