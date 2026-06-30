<?php
declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\I18n\Model\Locale;
use Weline\SystemConfig\Model\SystemConfig;

class AiTranslationConfig
{
    public const MODULE = 'Weline_I18n';
    public const AREA = SystemConfig::area_BACKEND;
    public const KEY = 'ai_translation';

    public const DEFAULT_SOURCE_LOCALE = 'zh_Hans_CN';
    public const DEFAULT_BATCH_SIZE = 100;
    public const MAX_BATCH_SIZE = 1000;
    public const DEFAULT_STRATEGY = 'light';

    public function __construct(
        private readonly SystemConfig $systemConfig,
        private readonly Locale $locale
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        $raw = $this->systemConfig->getConfig(self::KEY, self::MODULE, self::AREA);
        $config = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($config)) {
            $config = [];
        }

        return $this->normalizeConfig($config);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function saveFromPost(array $post): array
    {
        $installedActiveLocales = $this->getInstalledActiveLocaleCodes();
        $sourceLocale = self::DEFAULT_SOURCE_LOCALE;
        $batchSize = $this->normalizeBatchSize((int)($post['batch_size'] ?? self::DEFAULT_BATCH_SIZE));
        $strategy = $this->normalizeStrategy((string)($post['strategy'] ?? self::DEFAULT_STRATEGY));
        $postedLocales = $post['locales'] ?? [];
        if (!is_array($postedLocales)) {
            $postedLocales = [];
        }

        $locales = [];
        foreach ($installedActiveLocales as $localeCode) {
            $row = $postedLocales[$localeCode] ?? [];
            if (!is_array($row)) {
                $row = [];
            }
            $enabled = !empty($row['enabled']) && $localeCode !== $sourceLocale;
            $locales[$localeCode] = ['enabled' => $enabled];
        }

        $config = $this->normalizeConfig([
            'enabled' => !empty($post['enabled']),
            'source_locale' => $sourceLocale,
            'batch_size' => $batchSize,
            'strategy' => $strategy,
            'auto_publish' => !empty($post['auto_publish']),
            'locales' => $locales,
        ]);

        $this->saveConfig($config);

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function saveConfig(array $config): void
    {
        $config = $this->normalizeConfig($config);
        $this->systemConfig->setConfig(
            self::KEY,
            (string)json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::MODULE,
            self::AREA
        );
    }

    public function isEnabled(): bool
    {
        return (bool)($this->getConfig()['enabled'] ?? false);
    }

    public function isLocaleEnabled(string $localeCode): bool
    {
        $config = $this->getConfig();
        $localeCode = $this->normalizeLocaleCode($localeCode);

        if (empty($config['enabled']) || $localeCode === (string)$config['source_locale']) {
            return false;
        }

        return !empty($config['locales'][$localeCode]['enabled']) && $this->isInstalledActiveLocale($localeCode);
    }

    public function isTranslatableLocale(string $localeCode): bool
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);

        return $localeCode !== ''
            && $localeCode !== $this->getSourceLocale()
            && $this->isInstalledActiveLocale($localeCode);
    }

    /**
     * @return list<string>
     */
    public function getEnabledLocaleCodes(): array
    {
        $config = $this->getConfig();
        if (empty($config['enabled'])) {
            return [];
        }

        $enabled = [];
        foreach ((array)($config['locales'] ?? []) as $localeCode => $localeConfig) {
            if (!empty($localeConfig['enabled']) && $this->isLocaleEnabled((string)$localeCode)) {
                $enabled[] = (string)$localeCode;
            }
        }

        return array_values(array_unique($enabled));
    }

    public function getSourceLocale(): string
    {
        return (string)($this->getConfig()['source_locale'] ?? self::DEFAULT_SOURCE_LOCALE);
    }

    public function getBatchSize(?string $localeCode = null): int
    {
        return (int)($this->getConfig()['batch_size'] ?? self::DEFAULT_BATCH_SIZE);
    }

    public function getStrategy(?string $localeCode = null): string
    {
        return (string)($this->getConfig()['strategy'] ?? self::DEFAULT_STRATEGY);
    }

    public function shouldAutoPublish(): bool
    {
        return (bool)($this->getConfig()['auto_publish'] ?? true);
    }

    /**
     * @return list<string>
     */
    public function getInstalledActiveLocaleCodes(): array
    {
        try {
            $rows = $this->locale->clear()->reset()
                ->where(Locale::schema_fields_IS_INSTALL, 1)
                ->where(Locale::schema_fields_IS_ACTIVE, 1)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $codes = [];
        foreach ((array)$rows as $row) {
            $code = $this->normalizeLocaleCode((string)($row[Locale::schema_fields_CODE] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    public function isInstalledActiveLocale(string $localeCode): bool
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        if ($localeCode === '') {
            return false;
        }

        try {
            $locale = $this->locale->clear()->reset()
                ->where(Locale::schema_fields_CODE, $localeCode)
                ->where(Locale::schema_fields_IS_INSTALL, 1)
                ->where(Locale::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
        } catch (\Throwable) {
            return false;
        }

        return (string)$locale->getData(Locale::schema_fields_CODE) === $localeCode;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeConfig(array $config): array
    {
        $sourceLocale = self::DEFAULT_SOURCE_LOCALE;

        $normalized = [
            'enabled' => !empty($config['enabled']),
            'source_locale' => $sourceLocale,
            'batch_size' => $this->normalizeBatchSize((int)($config['batch_size'] ?? self::DEFAULT_BATCH_SIZE)),
            'strategy' => $this->normalizeStrategy((string)($config['strategy'] ?? self::DEFAULT_STRATEGY)),
            'auto_publish' => array_key_exists('auto_publish', $config) ? !empty($config['auto_publish']) : true,
            'locales' => [],
        ];

        $validLocales = $this->getInstalledActiveLocaleCodes();
        $localeConfig = is_array($config['locales'] ?? null) ? $config['locales'] : [];
        foreach ($validLocales as $localeCode) {
            $row = $localeConfig[$localeCode] ?? [];
            $enabled = is_array($row) && !empty($row['enabled']) && $localeCode !== $sourceLocale;
            $normalized['locales'][$localeCode] = ['enabled' => $enabled];
        }

        return $normalized;
    }

    private function normalizeBatchSize(int $batchSize): int
    {
        if ($batchSize <= 0) {
            return self::DEFAULT_BATCH_SIZE;
        }

        return min(self::MAX_BATCH_SIZE, $batchSize);
    }

    private function normalizeStrategy(string $strategy): string
    {
        return in_array($strategy, ['light', 'high_fidelity'], true) ? $strategy : self::DEFAULT_STRATEGY;
    }

    private function normalizeLocaleCode(string $localeCode): string
    {
        return trim(str_replace('-', '_', $localeCode));
    }
}
