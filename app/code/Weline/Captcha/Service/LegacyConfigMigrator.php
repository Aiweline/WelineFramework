<?php

declare(strict_types=1);

namespace Weline\Captcha\Service;

use Weline\Captcha\Model\Config as LegacyConfig;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;

/**
 * 旧独立配置表只迁入空的新键，绝不覆盖管理员已保存的 SystemConfig。
 */
final class LegacyConfigMigrator
{
    private const MAP = [
        'google_recaptcha_v2_site_key' => ['captcha/legacy/v2_site_key', false],
        'google_recaptcha_v2_secret_key' => ['captcha/legacy/v2_secret_key', true],
        'google_recaptcha_v3_site_key' => ['captcha/legacy/v3_site_key', false],
        'google_recaptcha_v3_secret_key' => ['captcha/legacy/v3_secret_key', true],
        'google_recaptcha_v3_threshold' => ['captcha/legacy/v3_threshold', false],
    ];

    public function __construct(
        private readonly LegacyConfig $legacy,
        private readonly ConfigReader $reader,
        private readonly ConfigStore $store,
    ) {
    }

    public function migrate(): int
    {
        $migrated = 0;
        $stored = $this->reader->getConfigMapByModule(
            CaptchaConfig::MODULE,
            CaptchaConfig::AREA,
            ConfigReader::SCOPE_GLOBAL,
        );
        foreach (self::MAP as $legacyKey => [$newKey, $sensitive]) {
            if (\array_key_exists($newKey, $stored) && \trim((string)$stored[$newKey]) !== '') {
                continue;
            }
            $value = $this->legacy->getConfig(
                $legacyKey,
                CaptchaConfig::MODULE,
                LegacyConfig::area_FRONTEND,
                '',
            );
            if (\trim((string)$value) === '') {
                continue;
            }
            $this->store->setScopedConfig(
                $newKey,
                $value,
                CaptchaConfig::MODULE,
                CaptchaConfig::AREA,
                null,
                null,
                [
                    'is_sensitive' => $sensitive,
                    'reason' => 'captcha_legacy_config_migration',
                ],
            );
            $migrated++;
        }
        return $migrated;
    }
}
