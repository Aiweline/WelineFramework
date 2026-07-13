<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service\Document;

use Weline\SystemConfig\Api\ConfigStore as SystemConfig;

class DocumentTranslationConfigService
{
    public const MODULE = 'Weline_DeveloperWorkspace';
    public const CONFIG_KEY = 'document_translation';
    public const SOURCE_LOCALE = 'zh_Hans_CN';

    public function __construct(private SystemConfig $systemConfig)
    {
    }

    public function getConfig(): array
    {
        $raw = $this->systemConfig->getConfig(self::CONFIG_KEY, self::MODULE, SystemConfig::area_BACKEND);
        $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        if (!is_array($decoded)) {
            $decoded = [];
        }

        return array_replace_recursive($this->getDefaultConfig(), $decoded);
    }

    public function saveConfig(array $config): void
    {
        $normalized = $this->normalizeConfig($config);
        $this->systemConfig->setConfig(
            self::CONFIG_KEY,
            json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::MODULE,
            SystemConfig::area_BACKEND
        );
    }

    public function getDefaultConfig(): array
    {
        return [
            'enabled' => 0,
            'source_locale' => self::SOURCE_LOCALE,
            'locales' => [
                self::SOURCE_LOCALE => ['enabled' => 1, 'ai_enabled' => 0],
            ],
            'scopes' => [
                'documents' => 1,
                'api_documents' => 1,
                'catalogs' => 1,
            ],
            'batch_size' => 10,
            'max_retries' => 3,
            'fallback_policy' => 'source',
            'daily_token_limit' => 0,
            'monthly_token_limit' => 0,
            'max_document_tokens' => 12000,
            'show_translation_status' => 1,
        ];
    }

    public function getEnabledTargetLocales(): array
    {
        $config = $this->getConfig();
        if (empty($config['enabled'])) {
            return [];
        }

        $sourceLocale = (string)($config['source_locale'] ?? self::SOURCE_LOCALE);
        $locales = [];
        foreach (($config['locales'] ?? []) as $locale => $row) {
            $locale = trim((string)$locale);
            if ($locale === '' || $locale === $sourceLocale) {
                continue;
            }
            if (!empty($row['enabled']) && !empty($row['ai_enabled'])) {
                $locales[] = $locale;
            }
        }

        return array_values(array_unique($locales));
    }

    public function getSupportedLocales(): array
    {
        $config = $this->getConfig();
        $sourceLocale = $this->normalizeLocale($config['source_locale'] ?? self::SOURCE_LOCALE);
        $locales = [$sourceLocale];

        foreach (($config['locales'] ?? []) as $locale => $row) {
            $locale = trim((string)$locale);
            if ($locale === '') {
                continue;
            }
            $row = is_array($row) ? $row : [];
            if ($locale === $sourceLocale || !empty($row['enabled'])) {
                $locales[] = $locale;
            }
        }

        return array_values(array_unique($locales));
    }

    public function isSupportedLocale(string $locale): bool
    {
        return in_array($this->normalizeLocale($locale), $this->getSupportedLocales(), true);
    }

    public function canAutoTranslateLocale(string $locale): bool
    {
        $locale = $this->normalizeLocale($locale);
        return in_array($locale, $this->getEnabledTargetLocales(), true);
    }

    public function isSourceLocale(string $locale): bool
    {
        return $this->normalizeLocale($locale) === (string)$this->getConfig()['source_locale'];
    }

    public function isScopeEnabled(string $scope): bool
    {
        $config = $this->getConfig();
        return !empty($config['scopes'][$scope]);
    }

    public function hasEnabledScope(): bool
    {
        $config = $this->getConfig();
        foreach (($config['scopes'] ?? []) as $enabled) {
            if (!empty($enabled)) {
                return true;
            }
        }

        return false;
    }

    public function normalizeLocale(?string $locale): string
    {
        $locale = trim((string)$locale);
        return $locale !== '' ? $locale : self::SOURCE_LOCALE;
    }

    public function normalizeConfig(array $config): array
    {
        $default = $this->getDefaultConfig();
        $sourceLocale = $this->normalizeLocale($config['source_locale'] ?? $default['source_locale']);
        $locales = [];

        foreach (($config['locales'] ?? []) as $locale => $row) {
            if (is_int($locale) && is_string($row)) {
                $locale = $row;
                $row = ['enabled' => 1, 'ai_enabled' => 0];
            }
            $locale = trim((string)$locale);
            if ($locale === '') {
                continue;
            }
            $row = is_array($row) ? $row : [];
            $locales[$locale] = [
                'enabled' => !empty($row['enabled']) ? 1 : 0,
                'ai_enabled' => !empty($row['ai_enabled']) ? 1 : 0,
            ];
        }

        $locales[$sourceLocale] = ['enabled' => 1, 'ai_enabled' => 0];

        return [
            'enabled' => !empty($config['enabled']) ? 1 : 0,
            'source_locale' => $sourceLocale,
            'locales' => $locales,
            'scopes' => [
                'documents' => !empty($config['scopes']['documents']) ? 1 : 0,
                'api_documents' => !empty($config['scopes']['api_documents']) ? 1 : 0,
                'catalogs' => !empty($config['scopes']['catalogs']) ? 1 : 0,
            ],
            'batch_size' => max(1, min(100, (int)($config['batch_size'] ?? $default['batch_size']))),
            'max_retries' => max(0, min(10, (int)($config['max_retries'] ?? $default['max_retries']))),
            'fallback_policy' => in_array(($config['fallback_policy'] ?? 'source'), ['source', 'empty'], true)
                ? (string)$config['fallback_policy']
                : 'source',
            'daily_token_limit' => max(0, (int)($config['daily_token_limit'] ?? 0)),
            'monthly_token_limit' => max(0, (int)($config['monthly_token_limit'] ?? 0)),
            'max_document_tokens' => max(1000, (int)($config['max_document_tokens'] ?? $default['max_document_tokens'])),
            'show_translation_status' => !empty($config['show_translation_status']) ? 1 : 0,
        ];
    }
}
