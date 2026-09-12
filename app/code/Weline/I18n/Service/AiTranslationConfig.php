<?php
declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locale;
use Weline\SystemConfig\Api\ConfigStore as SystemConfig;

class AiTranslationConfig
{
    public const MODULE = 'Weline_I18n';
    public const AREA = SystemConfig::area_BACKEND;
    public const KEY = 'ai_translation';

    public const DEFAULT_SOURCE_LOCALE = 'zh_Hans_CN';
    public const DEFAULT_BATCH_SIZE = 100;
    public const MAX_BATCH_SIZE = 1000;
    public const DEFAULT_STRATEGY = 'light';
    public const MAX_CONSECUTIVE_BATCH_FAILURES = 3;
    /** Same as AiTranslationWordSkipStore::MAX_FAILURES — documented for operators. */
    public const MAX_WORD_TRANSLATION_FAILURES = AiTranslationWordSkipStore::MAX_FAILURES;

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
        $candidateLocales = $this->getTranslationCandidateLocaleCodes();
        $sourceLocale = self::DEFAULT_SOURCE_LOCALE;
        $batchSize = $this->normalizeBatchSize((int)($post['batch_size'] ?? self::DEFAULT_BATCH_SIZE));
        $strategy = $this->normalizeStrategy((string)($post['strategy'] ?? self::DEFAULT_STRATEGY));
        $postedLocales = $post['locales'] ?? [];
        if (!is_array($postedLocales)) {
            $postedLocales = [];
        }
        $websiteUnion = array_fill_keys($this->getWebsiteAssignedLocaleCodes(), true);
        $tracksUnion = $this->tracksWebsiteLocaleUnion();

        $locales = [];
        foreach ($candidateLocales as $localeCode) {
            $row = $postedLocales[$localeCode] ?? [];
            if (!is_array($row)) {
                $row = [];
            }
            if ($localeCode === $sourceLocale) {
                $enabled = false;
            } elseif ($tracksUnion) {
                // Add → translate; remove from all websites → skip (cannot keep translating by checkbox).
                $enabled = isset($websiteUnion[$localeCode]);
            } else {
                $enabled = !empty($row['enabled']);
            }
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

        // When Websites is present, the multi-website language union is the sole target set:
        // assigned → translate; removed from all sites → skip.
        if ($this->tracksWebsiteLocaleUnion()) {
            return $this->isWebsiteAssignedLocale($localeCode);
        }

        return !empty($config['locales'][$localeCode]['enabled']) && $this->isInstalledActiveLocale($localeCode);
    }

    public function isTranslatableLocale(string $localeCode): bool
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        if ($localeCode === '' || $localeCode === $this->getSourceLocale()) {
            return false;
        }
        if ($this->tracksWebsiteLocaleUnion()) {
            return $this->isWebsiteAssignedLocale($localeCode);
        }

        return $this->isInstalledActiveLocale($localeCode);
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

        $sourceLocale = (string)($config['source_locale'] ?? self::DEFAULT_SOURCE_LOCALE);
        $enabled = [];

        if ($this->tracksWebsiteLocaleUnion()) {
            foreach ($this->getWebsiteAssignedLocaleCodes() as $localeCode) {
                if ($localeCode === $sourceLocale) {
                    continue;
                }
                // Skip languages no longer in the website union; only installed ones are runnable.
                if ($this->isInstalledActiveLocale($localeCode)) {
                    $enabled[] = $localeCode;
                }
            }

            return array_values(array_unique($enabled));
        }

        foreach ((array)($config['locales'] ?? []) as $localeCode => $localeConfig) {
            $localeCode = $this->normalizeLocaleCode((string)$localeCode);
            if ($localeCode === '' || $localeCode === $sourceLocale) {
                continue;
            }
            if (!empty($localeConfig['enabled']) && $this->isInstalledActiveLocale($localeCode)) {
                $enabled[] = $localeCode;
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
     * Installed+active I18n locales ∪ multi-website assigned language codes.
     *
     * @return list<string>
     */
    public function getTranslationCandidateLocaleCodes(): array
    {
        return array_values(array_unique(array_merge(
            $this->getInstalledActiveLocaleCodes(),
            $this->getWebsiteAssignedLocaleCodes(),
        )));
    }

    /**
     * Distinct language codes assigned across all websites (plus each site default_language).
     *
     * @return list<string>
     */
    public function getWebsiteAssignedLocaleCodes(): array
    {
        if (!class_exists(\Weline\Websites\Model\WebsiteLanguage::class)) {
            return [];
        }

        $codes = [];
        $seen = [];
        try {
            /** @var \Weline\Websites\Model\WebsiteLanguage $websiteLanguage */
            $websiteLanguage = ObjectManager::getInstance(\Weline\Websites\Model\WebsiteLanguage::class);
            $rows = $websiteLanguage->clear()->clearQuery()
                ->select(\Weline\Websites\Model\WebsiteLanguage::schema_fields_LANGUAGE_CODE)
                ->fetchArray();
            foreach ((array)$rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = $this->normalizeLocaleCode((string)($row[
                    \Weline\Websites\Model\WebsiteLanguage::schema_fields_LANGUAGE_CODE
                ] ?? ''));
                $this->pushUniqueLocale($codes, $seen, $code);
            }
        } catch (\Throwable) {
            // Websites optional at runtime for pure I18n installs.
        }

        if (class_exists(\Weline\Websites\Model\Website::class)) {
            try {
                /** @var \Weline\Websites\Model\Website $website */
                $website = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
                $rows = $website->clear()->clearQuery()
                    ->select(\Weline\Websites\Model\Website::schema_fields_DEFAULT_LANGUAGE)
                    ->fetchArray();
                foreach ((array)$rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $code = $this->normalizeLocaleCode((string)($row[
                        \Weline\Websites\Model\Website::schema_fields_DEFAULT_LANGUAGE
                    ] ?? ''));
                    $this->pushUniqueLocale($codes, $seen, $code);
                }
            } catch (\Throwable) {
            }
        }

        return $codes;
    }

    public function isWebsiteAssignedLocale(string $localeCode): bool
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        if ($localeCode === '') {
            return false;
        }
        foreach ($this->getWebsiteAssignedLocaleCodes() as $code) {
            if (strcasecmp($code, $localeCode) === 0) {
                return true;
            }
        }

        return false;
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
            // 未落库 / 缺省：默认开启（与 auto_publish 同策略）
            'enabled' => array_key_exists('enabled', $config) ? !empty($config['enabled']) : true,
            'source_locale' => $sourceLocale,
            'batch_size' => $this->normalizeBatchSize((int)($config['batch_size'] ?? self::DEFAULT_BATCH_SIZE)),
            'strategy' => $this->normalizeStrategy((string)($config['strategy'] ?? self::DEFAULT_STRATEGY)),
            'auto_publish' => array_key_exists('auto_publish', $config) ? !empty($config['auto_publish']) : true,
            'locales' => [],
        ];

        $websiteUnion = array_fill_keys($this->getWebsiteAssignedLocaleCodes(), true);
        $tracksUnion = $this->tracksWebsiteLocaleUnion();
        $validLocales = array_values(array_unique(array_merge(
            $this->getInstalledActiveLocaleCodes(),
            array_keys($websiteUnion),
        )));
        $localeConfig = is_array($config['locales'] ?? null) ? $config['locales'] : [];
        $localesExplicit = $localeConfig !== [];
        foreach ($validLocales as $localeCode) {
            if ($localeCode === $sourceLocale) {
                $normalized['locales'][$localeCode] = ['enabled' => false];
                continue;
            }
            $row = $localeConfig[$localeCode] ?? null;
            if ($tracksUnion) {
                // In union → translate; left the union (site language removed) → skip.
                $enabled = isset($websiteUnion[$localeCode]);
            } elseif (isset($websiteUnion[$localeCode])) {
                // Website-assigned languages are always translation targets.
                $enabled = true;
            } elseif (!$localesExplicit) {
                // 无 locales 配置时：已安装激活语种默认全部开启
                $enabled = true;
            } elseif (is_array($row) && array_key_exists('enabled', $row)) {
                $enabled = !empty($row['enabled']);
            } else {
                $enabled = true;
            }
            $normalized['locales'][$localeCode] = ['enabled' => $enabled];
        }

        return $normalized;
    }

    /**
     * Websites module present → multi-website language union owns AI translation targets.
     */
    public function tracksWebsiteLocaleUnion(): bool
    {
        return class_exists(\Weline\Websites\Model\WebsiteLanguage::class);
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

    /**
     * @param list<string> $codes
     * @param array<string, true> $seen
     */
    private function pushUniqueLocale(array &$codes, array &$seen, string $code): void
    {
        if ($code === '') {
            return;
        }
        $key = strtolower($code);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $codes[] = $code;
    }
}
