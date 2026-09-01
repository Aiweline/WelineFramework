<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Registry\Service\RegistryProgress;
use Weline\Framework\System\File\Io\File;
use Weline\Framework\System\File\Scanner;

/**
 * Framework 词典编译：从模块 CSV 构建 generated/language/*。
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
     * @return array<string, array<string, string>>
     */
    public function compile(?string $moduleName = null, bool $useCache = false): array
    {
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

        $payload = [
            'module' => $moduleName,
            'locals_words' => &$localsWords,
            'words_by_module' => &$wordsByModule,
            'source_translations' => &$sourceTranslations,
            'error_count' => $errorCount,
        ];
        $this->eventsManager->dispatch(DictionaryEvents::EVENT_DICTIONARY_COMPILE, $payload);

        $this->writeGeneratedFiles($localsWords, $wordsByModule, $sourceTranslations);
        $this->restoreMemoryLimit((string)$_prevMemLimit);

        $afterPayload = [
            'module' => $moduleName,
            'locale_count' => count($localsWords),
        ];
        $this->eventsManager->dispatch(DictionaryEvents::EVENT_DICTIONARY_COMPILE_AFTER, $afterPayload);

        return $localsWords;
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

        if ($sourceTranslations !== [] || isset($localsWords[$defaultLocale])) {
            $defaultLocalWords = array_merge($sourceTranslations, $localsWords[$defaultLocale] ?? []);
            $defaultLocalFile = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
            $dir = dirname($defaultLocalFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $file = @fopen($defaultLocalFile, 'w+');
            if ($file !== false) {
                fwrite($file, '<?php return ' . var_export($defaultLocalWords, true) . ';');
                fclose($file);
            } else {
                w_log_warning(__('警告：无法创建翻译文件 %{file}', ['file' => $defaultLocalFile]), [], 'phrase');
            }
        }

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

        $wordsFile = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
        $dir = dirname($wordsFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $text = '<?php return ' . var_export($wordsByModule, true) . ';';
        if (@file_put_contents($wordsFile, $text) === false) {
            w_log_warning(__('警告：无法写入翻译文件 %{file}', ['file' => $wordsFile]), [], 'phrase');
        }

        foreach ($wordsByModule as $locale => $moduleWordsData) {
            if ($locale === 'all_words' || !is_array($moduleWordsData)) {
                continue;
            }

            $wordsFilename = Env::path_TRANSLATE_FILES_PATH . $locale . '.php';
            $wordsDir = dirname($wordsFilename);
            if (!is_dir($wordsDir)) {
                mkdir($wordsDir, 0755, true);
            }

            $file = new File();
            $file->open($wordsFilename, File::mode_w);
            try {
                $file->write('<?php return ' . var_export($moduleWordsData, true) . ';?>');
            } catch (\Throwable $throwable) {
                w_log_warning(
                    __('警告：无法写入语言文件 %{file}', ['file' => $wordsFilename]) . ': ' . $throwable->getMessage(),
                    [],
                    'phrase',
                );
            }
            $file->close();
        }

        foreach ($wordsByModule as $locale => $moduleWordsData) {
            if ($locale === 'all_words' || !is_array($moduleWordsData)) {
                continue;
            }

            $csvFilePath = dirname($wordsFile) . DS . $locale . '_total.csv';
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
