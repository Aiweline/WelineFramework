<?php

declare(strict_types=1);

namespace Weline\CustomerService\Service;

use Weline\CustomerService\Model\CustomerServiceConfig;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * Scoped customer-service settings from SystemConfig.
 *
 * Locale fields left empty fall back to the current website default language.
 * Legacy rows in customer_service_config are migrated into Global once.
 */
final class CustomerServiceSettings
{
    public const MODULE = 'Weline_CustomerService';
    public const AREA = ConfigReader::area_FRONTEND;

    public const KEY_ENABLED = 'customer_service/general/enabled';
    public const KEY_DEFAULT_AGENT_LOCALE = 'customer_service/general/default_agent_locale';
    public const KEY_DEFAULT_CUSTOMER_LOCALE = 'customer_service/general/default_customer_locale';
    public const KEY_AI_ENABLED = 'customer_service/ai/enabled';
    public const KEY_AI_MODEL = 'customer_service/ai/model';

    private const LEGACY_MAP = [
        'enabled' => self::KEY_ENABLED,
        'default_agent_locale' => self::KEY_DEFAULT_AGENT_LOCALE,
        'default_customer_locale' => self::KEY_DEFAULT_CUSTOMER_LOCALE,
        'ai_enabled' => self::KEY_AI_ENABLED,
        'ai_model' => self::KEY_AI_MODEL,
    ];

    private bool $legacyMigrated = false;

    /** Process-level: legacy scan finished or proven unnecessary (SystemConfig already owns keys). */
    private static bool $processLegacySettled = false;

    public function __construct(
        private readonly ConfigReader $config,
    ) {
    }

    /** @internal tests / process cache reset */
    public static function clearProcessLegacySettlement(): void
    {
        self::$processLegacySettled = false;
    }

    public function isServiceEnabled(): bool
    {
        $this->migrateLegacyOnce();

        return $this->boolean(self::KEY_ENABLED, true);
    }

    public function isAiEnabled(): bool
    {
        $this->migrateLegacyOnce();

        return $this->boolean(self::KEY_AI_ENABLED, false);
    }

    public function aiModel(): string
    {
        $this->migrateLegacyOnce();
        $model = $this->string(self::KEY_AI_MODEL);
        return $model !== '' ? $model : 'gpt-4o';
    }

    /**
     * Resolved agent locale: configured value, else website default, else request/lang fallback.
     */
    public function defaultAgentLocale(): string
    {
        $this->migrateLegacyOnce();
        $configured = $this->string(self::KEY_DEFAULT_AGENT_LOCALE);

        return $this->resolveLocale($configured);
    }

    /**
     * Resolved customer locale: configured value, else website default, else request/lang fallback.
     */
    public function defaultCustomerLocale(): string
    {
        $this->migrateLegacyOnce();
        $configured = $this->string(self::KEY_DEFAULT_CUSTOMER_LOCALE);

        return $this->resolveLocale($configured);
    }

    /**
     * Raw configured locale (may be empty when inheriting website default).
     */
    public function configuredAgentLocale(): string
    {
        $this->migrateLegacyOnce();

        return $this->string(self::KEY_DEFAULT_AGENT_LOCALE);
    }

    public function configuredCustomerLocale(): string
    {
        $this->migrateLegacyOnce();

        return $this->string(self::KEY_DEFAULT_CUSTOMER_LOCALE);
    }

    public function websiteDefaultLocale(): string
    {
        try {
            $website = trim((string)State::resolveWebsiteDefaultLanguage());
            if ($website !== '') {
                return $website;
            }
        } catch (\Throwable) {
        }
        $request = trim((string)(State::getLangLocal() ?: ''));
        if ($request !== '') {
            return $request;
        }

        return 'zh_Hans_CN';
    }

    private function resolveLocale(string $configured): string
    {
        $configured = trim($configured);
        if ($configured !== '') {
            return $configured;
        }

        return $this->websiteDefaultLocale();
    }

    private function string(string $key): string
    {
        return trim((string)$this->config->get($key, self::MODULE, self::AREA, ''));
    }

    private function boolean(string $key, bool $default): bool
    {
        $value = $this->config->get($key, self::MODULE, self::AREA, $default ? '1' : '0');
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function migrateLegacyOnce(): void
    {
        if ($this->legacyMigrated || self::$processLegacySettled) {
            $this->legacyMigrated = true;

            return;
        }
        $this->legacyMigrated = true;

        try {
            /** @var SystemConfig $store */
            $store = ObjectManager::getInstance(SystemConfig::class);
            // Cold homepage paid ~64ms SELECT * on customer_service_config even when
            // SystemConfig already holds the unified keys. Skip the legacy table entirely.
            if ($store->getScopedConfigRow(
                self::KEY_ENABLED,
                self::MODULE,
                self::AREA,
                ConfigReader::SCOPE_GLOBAL,
                ConfigReader::LOCALE_DEFAULT,
            ) !== null) {
                self::$processLegacySettled = true;

                return;
            }

            /** @var CustomerServiceConfig $legacy */
            $legacy = ObjectManager::getInstance(CustomerServiceConfig::class);
            $rows = $legacy->reset()->select()->fetch()->getItems();
            if ($rows === []) {
                self::$processLegacySettled = true;

                return;
            }

            foreach ($rows as $row) {
                $legacyKey = trim((string)($row['key'] ?? ''));
                $unifiedKey = self::LEGACY_MAP[$legacyKey] ?? '';
                if ($unifiedKey === '') {
                    continue;
                }
                $existing = $store->getScopedConfigRow(
                    $unifiedKey,
                    self::MODULE,
                    self::AREA,
                    ConfigReader::SCOPE_GLOBAL,
                    ConfigReader::LOCALE_DEFAULT,
                );
                if ($existing !== null) {
                    continue;
                }
                $value = (string)($row['value'] ?? '');
                $store->setScopedConfig(
                    $unifiedKey,
                    $value,
                    self::MODULE,
                    self::AREA,
                    ConfigReader::SCOPE_GLOBAL,
                    ConfigReader::LOCALE_DEFAULT,
                );
            }
            self::$processLegacySettled = true;
        } catch (\Throwable) {
            // Legacy table may be absent; SystemConfig remains source of truth.
            self::$processLegacySettled = true;
        }
    }
}
