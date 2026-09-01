<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Phrase\DictionaryEvents;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;

/**
 * 词典页「模块」Tab：按活跃模块汇总 i18n 词条与翻译进度。
 */
class DictionaryModuleCatalogService
{
    /** @var array<string, array<string, mixed>> */
    private array $syncCache = [];

    /** @var array<string, list<string>> */
    private array $collectedCache = [];

    public function __construct(
        private readonly LocaleDictionary $localeDictionary,
        private readonly AiTranslationConfig $aiConfig,
        private readonly AiTranslationExportService $exportService,
        private readonly TranslationCollector $translationCollector,
    ) {
    }

    /**
     * @return list<array{
     *     module: string,
     *     has_i18n: bool,
     *     total: int,
     *     translated: int,
     *     untranslated: int,
     *     progress_percent: float,
     *     ai_pending_export: int,
     *     target_csv_exists: bool,
     *     source_csv_exists: bool,
     * }>
     */
    public function listModules(string $targetLocale, string $search = ''): array
    {
        $targetLocale = $this->normalizeLocale($targetLocale);
        $sourceLocale = $this->aiConfig->getSourceLocale();
        $search = trim($search);
        $rows = [];

        foreach (Env::getInstance()->getActiveModules() as $moduleMeta) {
            if (!is_array($moduleMeta)) {
                continue;
            }

            $moduleName = trim((string)($moduleMeta['name'] ?? ''));
            $basePath = rtrim((string)($moduleMeta['base_path'] ?? ''), "\\/");
            if ($moduleName === '' || $basePath === '') {
                continue;
            }

            if ($search !== '' && stripos($moduleName, $search) === false) {
                continue;
            }

            $i18nDir = $basePath . DS . 'i18n';
            $hasI18n = is_dir($i18nDir);
            if (!$hasI18n) {
                continue;
            }

            $sourceCsv = $i18nDir . DS . $sourceLocale . '.csv';
            $targetCsv = $i18nDir . DS . $targetLocale . '.csv';
            $sourceWords = $this->readCsvWords(is_file($sourceCsv) ? $sourceCsv : '');
            $targetWords = $this->readCsvWords(is_file($targetCsv) ? $targetCsv : '');

            if ($sourceWords === [] && $targetWords === []) {
                continue;
            }

            $wordKeys = array_values(array_unique(array_merge(array_keys($sourceWords), array_keys($targetWords))));
            $total = count($wordKeys);
            $translated = 0;

            foreach ($wordKeys as $word) {
                if ($this->isWordTranslated($word, $targetLocale, $targetWords, $moduleName)) {
                    $translated++;
                }
            }

            $untranslated = max(0, $total - $translated);
            $progressPercent = $total > 0 ? round(($translated / $total) * 100, 1) : 0.0;

            $rows[] = [
                'module' => $moduleName,
                'has_i18n' => true,
                'total' => $total,
                'translated' => $translated,
                'untranslated' => $untranslated,
                'progress_percent' => $progressPercent,
                'ai_pending_export' => $this->countAiPendingExport($moduleName, $targetLocale),
                'target_csv_exists' => is_file($targetCsv),
                'source_csv_exists' => is_file($sourceCsv),
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a['module'], $b['module']));

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function translateModule(string $moduleName, string $targetLocale, int $batchSize = 0, bool $syncFirst = true): array
    {
        $moduleName = trim($moduleName);
        $targetLocale = $this->normalizeLocale($targetLocale);
        if ($moduleName === '' || $targetLocale === '') {
            return [
                'success' => false,
                'message' => (string)__('缺少模块或目标语言'),
            ];
        }

        $sourceLocale = $this->normalizeLocale($this->aiConfig->getSourceLocale());
        if ($targetLocale === $sourceLocale) {
            return [
                'success' => false,
                'message' => (string)__('目标语言等于源语言，无法 AI 翻译'),
            ];
        }

        // 写回流水线已在开头收集过源语言时，跳过重复扫描。
        if ($syncFirst) {
            $this->syncModuleCollectedWords($moduleName);
        }

        $batchSize = $batchSize > 0
            ? min(AiTranslationConfig::MAX_BATCH_SIZE, $batchSize)
            : $this->aiConfig->getBatchSize($targetLocale);

        $words = $this->collectUntranslatedModuleWords($moduleName, $targetLocale, $batchSize);
        if ($words === []) {
            return [
                'success' => true,
                'message' => (string)__('模块 %{1} 没有待翻译词条', [$moduleName]),
                'translated' => 0,
                'remaining' => 0,
                'module' => $moduleName,
            ];
        }

        $result = DictionaryEvents::translate($moduleName, $targetLocale, [
            'owner' => $moduleName,
            'module' => $moduleName,
            'module_name' => $moduleName,
            'source_locale' => $sourceLocale,
            'publish' => false,
            'word_filter' => $words,
            'batch_size' => $batchSize,
        ]);

        $remaining = count($this->collectUntranslatedModuleWords($moduleName, $targetLocale, PHP_INT_MAX));

        return [
            'success' => !empty($result['success']),
            'message' => (string)($result['message'] ?? __('模块 AI 翻译完成')),
            'translated' => (int)($result['translated'] ?? 0),
            'failed' => (int)($result['failed'] ?? 0),
            'remaining' => $remaining,
            'module' => $moduleName,
            'data' => $result,
        ];
    }

    /**
     * Real-time scan module templates/source (__()/lang) and merge missing keys into **source-locale** CSV only.
     * Target locale CSVs are filled later by translate + writeback (no placeholder fan-out here).
     *
     * @return array{
     *     success: bool,
     *     module: string,
     *     collected: int,
     *     added_source: int,
     *     persisted: bool,
     *     message: string
     * }
     */
    public function syncModuleCollectedWords(string $moduleName): array
    {
        $moduleName = trim($moduleName);
        if ($moduleName !== '' && isset($this->syncCache[$moduleName])) {
            return $this->syncCache[$moduleName];
        }

        $basePath = $this->resolveModuleBasePath($moduleName);
        if ($moduleName === '' || $basePath === '') {
            return [
                'success' => false,
                'module' => $moduleName,
                'collected' => 0,
                'added_source' => 0,
                'persisted' => false,
                'message' => (string)__('模块不存在或无法解析路径'),
            ];
        }

        $i18nDir = $basePath . DS . 'i18n';
        if (!is_dir($i18nDir) && !mkdir($i18nDir, 0775, true) && !is_dir($i18nDir)) {
            return [
                'success' => false,
                'module' => $moduleName,
                'collected' => 0,
                'added_source' => 0,
                'persisted' => false,
                'message' => (string)__('无法创建模块 i18n 目录'),
            ];
        }

        $collected = $this->scanModuleCollectedWords($moduleName, $basePath);
        $sourceLocale = $this->normalizeLocale($this->aiConfig->getSourceLocale());
        $sourceCsv = $i18nDir . DS . $sourceLocale . '.csv';
        $sourceWords = $this->readCsvWords($sourceCsv);

        $addedSource = 0;
        $registerEntries = [];
        foreach ($collected as $word) {
            if (array_key_exists($word, $sourceWords)) {
                continue;
            }
            $sourceWords[$word] = $word;
            $addedSource++;
            $registerEntries[] = [
                'word' => $word,
                'translate' => $word,
                'module' => $moduleName,
                'locale' => $sourceLocale,
            ];
        }

        $persisted = false;
        if ($this->isDevEnvironment()) {
            $this->writeCsvWords($sourceCsv, $sourceWords);
            if ($registerEntries !== []) {
                DictionaryEvents::register($registerEntries, $moduleName);
            }
            $persisted = true;
        }

        return $this->syncCache[$moduleName] = [
            'success' => true,
            'module' => $moduleName,
            'collected' => count($collected),
            'added_source' => $addedSource,
            'persisted' => $persisted,
            'message' => (string)__(
                '模块 %{1}：模板收集 %{2} 词，源语言 CSV 新增 %{3} 条%{4}',
                [
                    $moduleName,
                    (string)count($collected),
                    (string)$addedSource,
                    $persisted ? '' : (string)__('（非开发环境未写盘）'),
                ],
            ),
        ];
    }

    /**
     * Count untranslated module words without materializing the full pending list.
     * Uses CSV + collected keys, then optionally one batched dictionary lookup.
     *
     * @param bool $includeDb false = CSV/collected gap only (fast SSE path)
     */
    public function countUntranslatedModuleWords(
        string $moduleName,
        string $targetLocale,
        bool $includeDb = true,
    ): int {
        $moduleName = trim($moduleName);
        $targetLocale = $this->normalizeLocale($targetLocale);
        $sourceLocale = $this->normalizeLocale($this->aiConfig->getSourceLocale());
        if ($moduleName === '' || $targetLocale === '' || $targetLocale === $sourceLocale) {
            return 0;
        }

        $basePath = $this->resolveModuleBasePath($moduleName);
        if ($basePath === '') {
            return 0;
        }

        $sourceWords = $this->readCsvWords($basePath . DS . 'i18n' . DS . $sourceLocale . '.csv');
        // SSE 快路径（includeDb=false）只比 CSV，避免 collectLazy 在 WLS Fiber 内挂起。
        if ($includeDb) {
            foreach ($this->scanModuleCollectedWords($moduleName, $basePath) as $word) {
                if (!array_key_exists($word, $sourceWords)) {
                    $sourceWords[$word] = $word;
                }
            }
        }
        $targetWords = $this->readCsvWords($basePath . DS . 'i18n' . DS . $targetLocale . '.csv');

        $candidates = [];
        foreach (array_keys($sourceWords) as $word) {
            $word = trim((string)$word);
            if ($word === '' || !$this->hasTranslatableText($word)) {
                continue;
            }
            $csvTranslation = trim((string)($targetWords[$word] ?? ''));
            if ($csvTranslation !== '' && $csvTranslation !== $word) {
                continue;
            }
            $candidates[] = $word;
        }

        if ($candidates === []) {
            return 0;
        }

        if (!$includeDb) {
            return count($candidates);
        }

        $translatedInDb = $this->loadTranslatedWordSet($targetLocale, $candidates);
        $pending = 0;
        foreach ($candidates as $word) {
            if (!isset($translatedInDb[$word])) {
                $pending++;
            }
        }

        return $pending;
    }

    /**
     * Count keys in the module source-locale CSV (optionally merge live collected keys).
     */
    public function countModuleSourceWords(string $moduleName, bool $includeCollected = true): int
    {
        $moduleName = trim($moduleName);
        if ($moduleName === '') {
            return 0;
        }
        $basePath = $this->resolveModuleBasePath($moduleName);
        if ($basePath === '') {
            return 0;
        }
        $sourceLocale = $this->normalizeLocale($this->aiConfig->getSourceLocale());
        $sourceWords = $this->readCsvWords($basePath . DS . 'i18n' . DS . $sourceLocale . '.csv');
        if ($includeCollected) {
            foreach ($this->scanModuleCollectedWords($moduleName, $basePath) as $word) {
                if (!array_key_exists($word, $sourceWords)) {
                    $sourceWords[$word] = $word;
                }
            }
        }
        $count = 0;
        foreach (array_keys($sourceWords) as $word) {
            $word = trim((string)$word);
            if ($word === '' || !$this->hasTranslatableText($word)) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /**
     * Align a target locale against the module source-locale word base.
     *
     * @return array{
     *     source_words: int,
     *     word_count: int,
     *     translated: int,
     *     untranslated: int,
     *     gap: int
     * }
     */
    public function summarizeModuleLocaleAlignment(
        string $moduleName,
        string $targetLocale,
        bool $includeDb = true,
    ): array {
        $moduleName = trim($moduleName);
        $targetLocale = $this->normalizeLocale($targetLocale);
        $sourceLocale = $this->normalizeLocale($this->aiConfig->getSourceLocale());
        $empty = [
            'source_words' => 0,
            'word_count' => 0,
            'translated' => 0,
            'untranslated' => 0,
            'gap' => 0,
        ];
        if ($moduleName === '' || $targetLocale === '' || $targetLocale === $sourceLocale) {
            $sourceWords = $this->countModuleSourceWords($moduleName, $includeDb);
            return [
                'source_words' => $sourceWords,
                'word_count' => $sourceWords,
                'translated' => $sourceWords,
                'untranslated' => 0,
                'gap' => 0,
            ];
        }

        $basePath = $this->resolveModuleBasePath($moduleName);
        if ($basePath === '') {
            return $empty;
        }

        $sourceWords = $this->readCsvWords($basePath . DS . 'i18n' . DS . $sourceLocale . '.csv');
        if ($includeDb) {
            foreach ($this->scanModuleCollectedWords($moduleName, $basePath) as $word) {
                if (!array_key_exists($word, $sourceWords)) {
                    $sourceWords[$word] = $word;
                }
            }
        }
        $targetWords = $this->readCsvWords($basePath . DS . 'i18n' . DS . $targetLocale . '.csv');

        $sourceKeys = [];
        foreach (array_keys($sourceWords) as $word) {
            $word = trim((string)$word);
            if ($word === '' || !$this->hasTranslatableText($word)) {
                continue;
            }
            $sourceKeys[] = $word;
        }
        $sourceCount = count($sourceKeys);

        $targetKeyCount = 0;
        foreach (array_keys($targetWords) as $word) {
            $word = trim((string)$word);
            if ($word === '' || !$this->hasTranslatableText($word)) {
                continue;
            }
            $targetKeyCount++;
        }

        $csvMissing = [];
        $csvTranslated = 0;
        foreach ($sourceKeys as $word) {
            $csvTranslation = trim((string)($targetWords[$word] ?? ''));
            if ($csvTranslation !== '' && $csvTranslation !== $word) {
                $csvTranslated++;
                continue;
            }
            $csvMissing[] = $word;
        }

        $untranslated = count($csvMissing);
        if ($includeDb && $csvMissing !== []) {
            $translatedInDb = $this->loadTranslatedWordSet($targetLocale, $csvMissing);
            $stillMissing = 0;
            foreach ($csvMissing as $word) {
                if (!isset($translatedInDb[$word])) {
                    $stillMissing++;
                }
            }
            $untranslated = $stillMissing;
            $csvTranslated = $sourceCount - $untranslated;
        }

        return [
            'source_words' => $sourceCount,
            'word_count' => $targetKeyCount,
            'translated' => $csvTranslated,
            'untranslated' => $untranslated,
            'gap' => max(0, $sourceCount - $csvTranslated),
        ];
    }

    /**
     * Fast CSV-only module work overview for list rows (no DB collect scan).
     *
     * @param list<string> $targetLocales
     * @return array{
     *     source_words: int,
     *     locales_total: int,
     *     locales_pending: int,
     *     words_pending: int
     * }
     */
    public function summarizeModuleWorkOverview(string $moduleName, array $targetLocales): array
    {
        $moduleName = trim($moduleName);
        $sourceLocale = $this->normalizeLocale($this->aiConfig->getSourceLocale());
        $empty = [
            'source_words' => 0,
            'locales_total' => 0,
            'locales_pending' => 0,
            'words_pending' => 0,
        ];
        if ($moduleName === '' || $sourceLocale === '') {
            return $empty;
        }

        $targets = [];
        foreach ($targetLocales as $localeCode) {
            $localeCode = $this->normalizeLocale((string)$localeCode);
            if ($localeCode !== '' && $localeCode !== $sourceLocale) {
                $targets[] = $localeCode;
            }
        }
        $targets = array_values(array_unique($targets));
        $basePath = $this->resolveModuleBasePath($moduleName);
        if ($basePath === '') {
            return $empty;
        }

        $sourceWords = $this->readCsvWords($basePath . DS . 'i18n' . DS . $sourceLocale . '.csv');
        $sourceKeys = [];
        foreach (array_keys($sourceWords) as $word) {
            $word = trim((string)$word);
            if ($word === '' || !$this->hasTranslatableText($word)) {
                continue;
            }
            $sourceKeys[] = $word;
        }
        $sourceCount = count($sourceKeys);
        $localesPending = 0;
        $wordsPending = 0;

        foreach ($targets as $localeCode) {
            $targetWords = $this->readCsvWords($basePath . DS . 'i18n' . DS . $localeCode . '.csv');
            $gap = 0;
            foreach ($sourceKeys as $word) {
                $csvTranslation = trim((string)($targetWords[$word] ?? ''));
                if ($csvTranslation !== '' && $csvTranslation !== $word) {
                    continue;
                }
                $gap++;
            }
            if ($gap > 0) {
                $localesPending++;
                $wordsPending += $gap;
            }
        }

        return [
            'source_words' => $sourceCount,
            'locales_total' => count($targets),
            'locales_pending' => $localesPending,
            'words_pending' => $wordsPending,
        ];
    }

    /**
     * @param list<string> $words
     * @return array<string, true>
     */
    private function loadTranslatedWordSet(string $targetLocale, array $words): array
    {
        $translated = [];
        $targetLocale = $this->normalizeLocale($targetLocale);
        if ($targetLocale === '' || $words === []) {
            return $translated;
        }

        foreach (array_chunk(array_values(array_unique($words)), 400) as $chunk) {
            try {
                $items = $this->localeDictionary->reset()
                    ->where(LocaleDictionary::schema_fields_WORD, $chunk, 'IN')
                    ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $targetLocale)
                    ->select()
                    ->fetch()
                    ->getItems();
            } catch (\Throwable) {
                continue;
            }
            foreach ($items as $row) {
                $word = trim((string)$row->getData(LocaleDictionary::schema_fields_WORD));
                $translate = trim((string)$row->getData(LocaleDictionary::schema_fields_TRANSLATE));
                if ($word !== '' && $translate !== '' && $translate !== $word) {
                    $translated[$word] = true;
                }
            }
        }

        return $translated;
    }

    /**
     * @return list<string>
     */
    public function collectUntranslatedModuleWords(string $moduleName, string $targetLocale, int $limit): array
    {
        $moduleName = trim($moduleName);
        $targetLocale = $this->normalizeLocale($targetLocale);
        $sourceLocale = $this->normalizeLocale($this->aiConfig->getSourceLocale());
        $limit = max(1, $limit);

        $basePath = $this->resolveModuleBasePath($moduleName);
        if ($basePath === '') {
            return [];
        }

        $sourceWords = $this->readCsvWords($basePath . DS . 'i18n' . DS . $sourceLocale . '.csv');
        // Also include live template/source collect keys even if CSV write was skipped.
        foreach ($this->scanModuleCollectedWords($moduleName, $basePath) as $word) {
            if (!array_key_exists($word, $sourceWords)) {
                $sourceWords[$word] = $word;
            }
        }
        $targetWords = $this->readCsvWords($basePath . DS . 'i18n' . DS . $targetLocale . '.csv');
        $wordKeys = array_values(array_unique(array_merge(array_keys($sourceWords), array_keys($targetWords))));

        $candidates = [];
        foreach ($wordKeys as $word) {
            $word = trim((string)$word);
            if ($word === '' || !$this->hasTranslatableText($word)) {
                continue;
            }
            $csvTranslation = trim((string)($targetWords[$word] ?? ''));
            if ($csvTranslation !== '' && $csvTranslation !== $word) {
                continue;
            }
            $candidates[] = $word;
        }

        if ($candidates === []) {
            return [];
        }

        $translatedInDb = $this->loadTranslatedWordSet($targetLocale, $candidates);
        $pending = [];
        foreach ($candidates as $word) {
            if (isset($translatedInDb[$word])) {
                continue;
            }
            $pending[] = $word;
            if (count($pending) >= $limit) {
                break;
            }
        }

        return $pending;
    }

    /**
     * @return list<string>
     */
    private function scanModuleCollectedWords(string $moduleName, string $basePath): array
    {
        if (isset($this->collectedCache[$moduleName])) {
            return $this->collectedCache[$moduleName];
        }

        $words = [];
        try {
            foreach ($this->translationCollector->collectLazy($basePath, $moduleName) as $original => $_info) {
                $word = trim((string)$original);
                if ($word === '' || !$this->translationCollector->isValidTranslationString($word)) {
                    continue;
                }
                $words[$word] = true;
            }
        } catch (\Throwable) {
            return $this->collectedCache[$moduleName] = [];
        }

        return $this->collectedCache[$moduleName] = array_keys($words);
    }

    /**
     * @param array<string, string> $translations
     */
    private function writeCsvWords(string $csvFile, array $translations): void
    {
        I18nCsvCodec::writeWords($csvFile, $translations);
    }

    /**
     * @return array<string, mixed>
     */
    public function exportModuleToCsv(string $moduleName, string $targetLocale): array
    {
        if (!$this->isDevEnvironment()) {
            return [
                'success' => false,
                'message' => (string)__('写回模块 CSV 仅允许在开发环境执行'),
            ];
        }

        return $this->exportService->exportModuleTranslations($moduleName, $targetLocale);
    }

    public function isDevEnvironment(): bool
    {
        if (\defined('DEV') && DEV) {
            return true;
        }

        $deploy = \strtolower(\trim((string)(Env::system('deploy') ?? Env::get('deploy', '') ?? '')));
        if (\in_array($deploy, ['dev', 'development', 'local'], true)) {
            return true;
        }

        return (bool)Env::get('dev.mode', false) || (bool)Env::get('wls.debug', false);
    }

    private function isWordTranslated(
        string $word,
        string $targetLocale,
        array $targetCsvWords,
        string $moduleName,
    ): bool {
        $word = trim($word);
        if ($word === '') {
            return true;
        }

        $csvTranslation = trim((string)($targetCsvWords[$word] ?? ''));
        if ($csvTranslation !== '' && $csvTranslation !== $word) {
            return true;
        }

        $row = $this->localeDictionary->reset()
            ->where(LocaleDictionary::schema_fields_WORD, $word)
            ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $targetLocale)
            ->find()
            ->fetch();

        if (!$row->getId()) {
            return false;
        }

        $translate = trim((string)$row->getData(LocaleDictionary::schema_fields_TRANSLATE));

        return $translate !== '' && $translate !== $word;
    }

    public function countAiPendingExportPublic(string $moduleName, string $targetLocale): int
    {
        return $this->countAiPendingExport($moduleName, $targetLocale);
    }

    /**
     * One query: pending AI export counts for a module across locales.
     *
     * @param list<string> $localeCodes
     * @return array<string, int> locale_code => count
     */
    public function countAiPendingExportByLocales(string $moduleName, array $localeCodes): array
    {
        $moduleName = trim($moduleName);
        $locales = [];
        foreach ($localeCodes as $localeCode) {
            $localeCode = $this->normalizeLocale((string)$localeCode);
            if ($localeCode !== '') {
                $locales[] = $localeCode;
            }
        }
        $locales = array_values(array_unique($locales));
        if ($moduleName === '' || $locales === []) {
            return [];
        }

        try {
            $rows = $this->localeDictionary->reset()
                ->fields(LocaleDictionary::schema_fields_LOCALE_CODE . ', COUNT(*) AS cnt')
                ->where(LocaleDictionary::schema_fields_SOURCE_MODULE, $moduleName)
                ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $locales, 'IN')
                ->where(LocaleDictionary::schema_fields_IS_AI, 1)
                ->where(LocaleDictionary::schema_fields_TRANSLATE, '', '!=')
                ->where(LocaleDictionary::schema_fields_EXPORTED_AT, null)
                ->group(LocaleDictionary::schema_fields_LOCALE_CODE)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($locales as $localeCode) {
            $out[$localeCode] = 0;
        }
        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = $this->normalizeLocale((string)($row[LocaleDictionary::schema_fields_LOCALE_CODE] ?? ''));
            if ($code === '') {
                continue;
            }
            $out[$code] = (int)($row['cnt'] ?? 0);
        }

        return $out;
    }

    /**
     * Batch pending AI write-back counts across locales, keyed by source_module.
     *
     * @param list<string> $localeCodes
     * @return array<string, int>
     */
    public function countAiPendingExportTotalsByModule(array $localeCodes): array
    {
        $locales = [];
        foreach ($localeCodes as $localeCode) {
            $localeCode = $this->normalizeLocale((string)$localeCode);
            if ($localeCode !== '') {
                $locales[] = $localeCode;
            }
        }
        $locales = array_values(array_unique($locales));
        if ($locales === []) {
            return [];
        }

        try {
            $rows = $this->localeDictionary->reset()
                ->fields(LocaleDictionary::schema_fields_SOURCE_MODULE . ', COUNT(*) AS cnt')
                ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $locales, 'IN')
                ->where(LocaleDictionary::schema_fields_IS_AI, 1)
                ->where(LocaleDictionary::schema_fields_TRANSLATE, '', '!=')
                ->where(LocaleDictionary::schema_fields_EXPORTED_AT, null)
                ->where(LocaleDictionary::schema_fields_SOURCE_MODULE, '', '!=')
                ->group(LocaleDictionary::schema_fields_SOURCE_MODULE)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $totals = [];
        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $moduleName = trim((string)($row[LocaleDictionary::schema_fields_SOURCE_MODULE] ?? ''));
            if ($moduleName === '') {
                continue;
            }
            $totals[$moduleName] = (int)($row['cnt'] ?? 0);
        }

        return $totals;
    }

    private function countAiPendingExport(string $moduleName, string $targetLocale): int
    {
        return (int)$this->localeDictionary->reset()
            ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $targetLocale)
            ->where(LocaleDictionary::schema_fields_SOURCE_MODULE, $moduleName)
            ->where(LocaleDictionary::schema_fields_IS_AI, 1)
            ->where(LocaleDictionary::schema_fields_TRANSLATE, '', '!=')
            ->where(LocaleDictionary::schema_fields_EXPORTED_AT, null)
            ->count();
    }

    private function resolveModuleBasePath(string $moduleName): string
    {
        $moduleInfo = Env::getInstance()->getModuleInfo($moduleName);
        if (!is_array($moduleInfo)) {
            return '';
        }

        return rtrim((string)($moduleInfo['base_path'] ?? ''), "\\/");
    }

    /**
     * @return array<string, string>
     */
    private function readCsvWords(string $csvFile): array
    {
        return I18nCsvCodec::readWords($csvFile);
    }

    private function hasTranslatableText(string $word): bool
    {
        return (bool)preg_match('/\p{Han}/u', $word);
    }

    private function normalizeLocale(string $localeCode): string
    {
        return trim(str_replace('-', '_', $localeCode));
    }
}
