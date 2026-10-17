<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Registry\Service\RegistryProgress;
use Weline\Framework\System\File\Scanner;

/**
 * Framework 词典编译：从模块 CSV 构建收集侧产物（*_total.csv）。
 * `generated/language/{locale}.php` 与区袋不由本类写出——唯一写入权威是 I18n
 * `AiTranslationPublisher::publishLocale`（dictionary_compile_after 触发）。
 * 不再写 `words.php`（冗余中间快照；运行时与收集均不依赖）。
 * 源码扫描 / DB 合并由 dictionary_compile Observer（如 I18n）增强。
 */
class DictionaryCompiler
{
    public function __construct(
        private readonly Scanner $scanner,
        private readonly EventsManager $eventsManager,
    ) {
    }

    /**
     * @param array{
     *     republish_all?: bool,
     *     rewrite_csv?: bool
     * } $options
     * @return array<string, array<string, string>>
     * @throws DictionaryCollectBusyException when another collect already holds the single-flight lock
     */
    public function compile(
        ?string $moduleName = null,
        bool $useCache = false,
        bool $pruneStale = true,
        array $options = [],
    ): array {
        DictionaryCollectGate::acquire($moduleName);
        try {
            return $this->compileLocked($moduleName, $useCache, $pruneStale, $options);
        } finally {
            DictionaryCollectGate::release();
        }
    }

    /**
     * @param array{republish_all?: bool, rewrite_csv?: bool} $options
     * @return array<string, array<string, string>>
     */
    private function compileLocked(
        ?string $moduleName,
        bool $useCache,
        bool $pruneStale = true,
        array $options = [],
    ): array {
        $republishAll = ($options['republish_all'] ?? false) === true;
        $rewriteCsv = ($options['rewrite_csv'] ?? true) !== false;
        $_prevMemLimit = ini_get('memory_limit');
        $currentLimit = $this->parseMemoryLimit((string)$_prevMemLimit);
        if ($currentLimit > 0 && $currentLimit < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }

        $localsWords = [];
        $wordsByModule = ['all_words' => []];
        $sourceTranslations = [];
        $errorCount = 0;

        $csvFiles = $this->discoverModuleCsvFiles($moduleName);
        RegistryProgress::count('Phrase CSV source files', array_sum(array_map('count', $csvFiles)), 'files');

        $moduleIndex = 0;
        $moduleCount = count($csvFiles);
        $csvWordCount = 0;

        foreach ($csvFiles as $moduleKey => $localeFiles) {
            $moduleIndex++;
            RegistryProgress::module(
                'Phrase CSV read',
                $moduleIndex,
                $moduleCount,
                (string)$moduleKey,
                count($localeFiles) . ' files',
            );

            $fullModuleName = $this->resolveFullModuleName((string)$moduleKey);
            foreach ($localeFiles as $locale => $csvPath) {
                $errorCount += $this->readCsvFile(
                    (string)$csvPath,
                    (string)$locale,
                    $fullModuleName,
                    $localsWords,
                    $wordsByModule,
                    $csvWordCount,
                );
            }
        }

        RegistryProgress::count('Phrase CSV loaded', $csvWordCount, 'entries');

        if ($errorCount > 0 && php_sapi_name() === 'cli') {
            echo str_repeat('=', 80) . "\n";
            echo '共发现 ' . $errorCount . " 个问题\n";
            echo str_repeat('=', 80) . "\n\n";
        }

        unset($useCache);
        $payload = [
            'module' => $moduleName,
            'locals_words' => &$localsWords,
            'words_by_module' => &$wordsByModule,
            'source_translations' => &$sourceTranslations,
            'error_count' => $errorCount,
            'rewrite_csv' => $rewriteCsv,
        ];
        $this->eventsManager->dispatch(DictionaryEvents::EVENT_DICTIONARY_COMPILE, $payload);

        $this->writeGeneratedFiles($localsWords, $wordsByModule, $sourceTranslations);
        $this->restoreMemoryLimit((string)$_prevMemLimit);

        $afterPayload = [
            'module' => $moduleName,
            'locale_count' => count($localsWords),
            'source_translations' => $sourceTranslations,
            'collected_words' => $this->flattenSourceCollectedWords($localsWords, $sourceTranslations),
            // 全量 collect 后由 I18n Observer 按原词 diff 剥离短语冗余；单模块 / --no-prune-stale 为 false
            'prune_stale' => $pruneStale,
            'republish_all' => $republishAll,
        ];
        $this->eventsManager->dispatch(DictionaryEvents::EVENT_DICTIONARY_COMPILE_AFTER, $afterPayload);

        return $localsWords;
    }

    /**
     * 收集原词集（默认语=中文）：中文 CSV 原词 ∪ 源码扫描原词。
     * 供 I18n persist 与短语 stale prune；不以 en_US 等其它语 CSV 键保活。
     * 不在此二源中的原词视为冗余，按原词删全部语种行（含泰语等）。
     *
     * @param array<string, array<string, string>> $localsWords
     * @param array<string, string> $sourceTranslations
     * @return array<string, string>
     */
    private function flattenSourceCollectedWords(array $localsWords, array $sourceTranslations): array
    {
        $collected = [];
        // 源码扫描原词（同样视为中文原词）
        foreach ($sourceTranslations as $word => $translate) {
            if (!is_string($word) && !is_int($word)) {
                continue;
            }
            $word = trim((string)$word);
            if ($word === '') {
                continue;
            }
            $collected[$word] = is_scalar($translate) ? (string)$translate : $word;
        }
        // 仅默认语（中文）CSV 原词；其它语 CSV 是译文袋，键应与中文原词一致，不单独扩 alive
        $defaultLocale = Env::default_LANGUAGE_CODE;
        $defaultWords = $localsWords[$defaultLocale] ?? [];
        if (is_array($defaultWords)) {
            foreach ($defaultWords as $word => $translate) {
                if (!is_string($word) && !is_int($word)) {
                    continue;
                }
                $word = trim((string)$word);
                if ($word === '') {
                    continue;
                }
                $collected[$word] = is_scalar($translate) ? (string)$translate : $word;
            }
        }

        return $collected;
    }

    public static function clearTranslationCaches(): void
    {
        try {
            w_cache('phrase')->clear();
        } catch (\Throwable) {
        }

        Parser::clearWorkerCaches();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function discoverModuleCsvFiles(?string $moduleName): array
    {
        $result = [];
        foreach (Env::getInstance()->getActiveModules() as $module) {
            if ($moduleName !== null && ($module['name'] ?? '') !== $moduleName) {
                continue;
            }

            $basePath = (string)($module['base_path'] ?? '');
            if ($basePath === '' || !is_dir($basePath . 'i18n')) {
                continue;
            }

            $files = [];
            $this->scanner->globFile($basePath . 'i18n', $files, '.csv');
            $localeFiles = [];
            foreach ($files as $file) {
                $locale = pathinfo($file, PATHINFO_FILENAME);
                if ($locale !== '') {
                    $localeFiles[$locale] = $file;
                }
            }

            if ($localeFiles !== []) {
                $result[$module['name'] ?? basename(rtrim($basePath, DS))] = $localeFiles;
            }
        }

        return $result;
    }

    /**
     * @param array<string, array<string, string>> $localsWords
     * @param array<string, mixed> $wordsByModule
     */
    private function readCsvFile(
        string $csvPath,
        string $locale,
        string $fullModuleName,
        array &$localsWords,
        array &$wordsByModule,
        int &$csvWordCount,
    ): int {
        $errors = 0;
        $firstError = true;
        $handle = @fopen($csvPath, 'r');
        if ($handle === false) {
            $this->reportCsvIssue($csvPath, 0, '无法打开文件', $firstError);
            return 1;
        }

        $isUtf8 = false;
        $line = 1;
        $relativePath = ltrim(str_replace(BP, '', $csvPath), '/');

        while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
            $data = $this->normalizeCsvRow($data, $line);
            if ($this->isEffectivelyEmptyCsvRow($data)) {
                $line++;
                continue;
            }

            if (!isset($data[0]) || $data[0] === '') {
                $this->reportCsvIssue($relativePath, $line, '没有翻译原文', $firstError);
                $errors++;
                $line++;
                continue;
            }
            if (!isset($data[1])) {
                $this->reportCsvIssue($relativePath, $line, '没有翻译内容', $firstError);
                $errors++;
                $line++;
                continue;
            }

            $wordModule = isset($data[2]) && trim((string)$data[2]) !== ''
                ? trim((string)$data[2])
                : $fullModuleName;

            if (!$isUtf8) {
                if (md5(mb_convert_encoding((string)$data[0], 'utf-8', 'utf-8')) !== md5((string)$data[0])) {
                    $this->reportCsvIssue($relativePath, $line, '编码不是UTF-8', $firstError);
                    $errors++;
                    $line++;
                    continue;
                }
                $isUtf8 = true;
            }

            $localsWords[$locale] ??= [];
            $localsWords[$locale][(string)$data[0]] = (string)$data[1];
            $csvWordCount++;

            if (DictionaryWordValidator::isValidTranslationString((string)$data[0])) {
                $wordsByModule[$locale] ??= [];
                $wordsByModule[$locale][$wordModule] ??= [];
                $wordsByModule[$locale][$wordModule][(string)$data[0]] = (string)$data[1];
            }

            $line++;
        }

        fclose($handle);

        return $errors;
    }

    /**
     * @param array<string, array<string, string>> $localsWords
     * @param array<string, mixed> $wordsByModule
     * @param array<string, string> $sourceTranslations
     */
    private function writeGeneratedFiles(array $localsWords, array $wordsByModule, array $sourceTranslations): void
    {
        $defaultLocale = Env::default_LANGUAGE_CODE;

        if ($sourceTranslations !== [] && isset($localsWords[$defaultLocale])) {
            $localsWords[$defaultLocale] = array_merge($sourceTranslations, $localsWords[$defaultLocale]);
        }

        foreach ($sourceTranslations as $word => $translate) {
            if (
                DictionaryWordValidator::isValidTranslationString((string)$word)
                && !$this->hasModuleTranslation($wordsByModule, $defaultLocale, (string)$word)
                && !isset($wordsByModule['all_words'][$word])
            ) {
                $wordsByModule['all_words'][(string)$word] = (string)$translate;
            }
        }

        if ($localsWords === []) {
            return;
        }

        foreach (array_keys($localsWords) as $locale) {
            $wordsByModule[$locale] ??= [];
        }

        foreach ($localsWords as $locale => $words) {
            $wordsByModule[$locale] ??= [];
            foreach ($words as $word => $translate) {
                if (!DictionaryWordValidator::isValidTranslationString((string)$word)) {
                    continue;
                }
                if (
                    !$this->hasModuleTranslation($wordsByModule, (string)$locale, (string)$word)
                    && !isset($wordsByModule['all_words'][$word])
                ) {
                    $wordsByModule['all_words'][(string)$word] = (string)$translate;
                }
            }
        }

        // 不写 generated/language/words.php（无运行时/收集消费者）。
        // 不写 generated/language/{locale}.php：避免与 AiTranslationPublisher 双写/冲库。
        // locale 袋由 I18n dictionary_compile_after → publishLocale 从系统词典导出。

        $languageDir = Env::path_TRANSLATE_FILES_PATH;
        if (!is_dir($languageDir)) {
            mkdir($languageDir, 0755, true);
        }

        foreach ($wordsByModule as $locale => $moduleWordsData) {
            if ($locale === 'all_words' || !is_array($moduleWordsData)) {
                continue;
            }

            $csvFilePath = $languageDir . $locale . '_total.csv';
            $csvHandle = @fopen($csvFilePath, 'w+');
            if ($csvHandle === false) {
                continue;
            }

            if (isset($wordsByModule['all_words']) && is_array($wordsByModule['all_words'])) {
                foreach ($wordsByModule['all_words'] as $word => $translate) {
                    fputcsv($csvHandle, [$word, $translate, ''], ',', '"', '\\');
                }
            }
            foreach ($moduleWordsData as $modName => $words) {
                if (!is_array($words)) {
                    continue;
                }
                foreach ($words as $word => $translate) {
                    fputcsv($csvHandle, [$word, $translate, $modName], ',', '"', '\\');
                }
            }
            fclose($csvHandle);
        }
    }

    private function resolveFullModuleName(string $moduleName): string
    {
        if (str_starts_with($moduleName, 'Weline_')) {
            return $moduleName;
        }

        try {
            $moduleInfo = Env::getInstance()->getModuleInfo($moduleName);
            if ($moduleInfo && isset($moduleInfo['name'])) {
                return (string)$moduleInfo['name'];
            }
        } catch (\Throwable) {
        }

        return 'Weline_' . $moduleName;
    }

    /**
     * @param array<string, mixed> $wordsByModule
     */
    private function hasModuleTranslation(array $wordsByModule, string $locale, string $word): bool
    {
        foreach (($wordsByModule[$locale] ?? []) as $moduleWords) {
            if (is_array($moduleWords) && array_key_exists($word, $moduleWords)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $data
     * @return array<int, mixed>
     */
    private function normalizeCsvRow(array $data, int $line): array
    {
        foreach ($data as $index => $value) {
            if (!is_string($value)) {
                continue;
            }
            $data[$index] = $this->normalizeCsvCell($value, $line === 1 && $index === 0);
        }

        return $data;
    }

    /**
     * @param array<int, mixed> $data
     */
    private function isEffectivelyEmptyCsvRow(array $data): bool
    {
        foreach ($data as $index => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            if ($this->normalizeCsvCell((string)$value, (int)$index === 0) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeCsvCell(string $value, bool $stripBom = false): string
    {
        if ($stripBom) {
            $value = $this->stripUtf8Bom($value);
        }

        return trim($value);
    }

    private function stripUtf8Bom(string $value): string
    {
        if (strncmp($value, "\xEF\xBB\xBF", 3) === 0) {
            return substr($value, 3);
        }

        return preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
    }

    private function reportCsvIssue(string $path, int $line, string $message, bool &$firstError): void
    {
        if ($firstError && php_sapi_name() === 'cli') {
            echo "\n" . str_repeat('=', 80) . "\n";
            echo "i18n 文件格式问题\n";
            echo str_repeat('=', 80) . "\n";
            $firstError = false;
        }

        $location = $line > 0 ? $path . ':' . $line : $path;
        if (php_sapi_name() === 'cli') {
            echo $location . '  【' . $message . "】\n";
        } else {
            w_log_warning($location . '  【' . $message . '】', [], 'phrase');
        }
    }

    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '-1') {
            return -1;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int)$limit;
        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function restoreMemoryLimit(string $prevMemLimit): void
    {
        $restoreLimit = $prevMemLimit !== '' ? $prevMemLimit : '128M';
        $currentUsage = memory_get_usage(true);
        $restoreLimitBytes = $this->parseMemoryLimit($restoreLimit);
        if ($restoreLimitBytes > 0 && $restoreLimitBytes < $currentUsage) {
            $restoreLimit = max($restoreLimitBytes, (int)($currentUsage * 1.5));
            $restoreLimit = (int)ceil($restoreLimit / 1024 / 1024) . 'M';
        }
        @ini_set('memory_limit', $restoreLimit);
    }
}
