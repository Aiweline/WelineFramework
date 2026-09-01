<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

use Weline\SystemConfig\Api\ConfigStore as SystemConfig;

/**
 * Module-level switch: AI auto translation for FileAssetLocale gap-fill (cron/queue only).
 */
class FileAssetLocaleTranslationConfig
{
    public const MODULE = 'Weline_FileManager';
    public const AREA = SystemConfig::area_BACKEND;
    public const KEY = 'ai_auto_translation';

    public function __construct(
        private readonly SystemConfig $systemConfig,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool)($this->getConfig()['enabled'] ?? false);
    }

    public function setEnabled(bool $enabled): void
    {
        $config = $this->getConfig();
        $config['enabled'] = $enabled;
        $this->saveConfig($config);
    }

    /** @return array{enabled:bool} */
    public function getConfig(): array
    {
        $raw = $this->systemConfig->getConfig(self::KEY, self::MODULE, self::AREA);
        $config = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($config)) {
            $config = [];
        }

        return [
            'enabled' => !empty($config['enabled']),
        ];
    }

    /** @param array<string,mixed> $config */
    public function saveConfig(array $config): void
    {
        $normalized = ['enabled' => !empty($config['enabled'])];
        $this->systemConfig->setConfig(
            self::KEY,
            (string)json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::MODULE,
            self::AREA,
        );
    }
}
