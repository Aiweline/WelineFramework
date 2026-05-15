<?php
declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;
use Weline\I18n\Model\Dictionary;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;

class AiTranslationService
{
    private const DEFAULT_SCAN_PAGE_SIZE = 500;

    /**
     * @var array<string, array<string, string>>
     */
    private array $csvWordCache = [];

    /**
     * @var array<string, string>|null
     */
    private ?array $activeModuleBasePaths = null;

    /**
     * @var array<string, array<string, true>>
     */
    private array $csvTranslatedWordIndex = [];

    /**
     * @var array<string, array<string, true>>
     */
    private array $localeTranslatedWordIndex = [];

    /**
     * @var array<string, array<string, true>>
     */
    private array $generatedTranslatedWordIndex = [];

    /**
     * @var array<string, string>
     */
    private array $candidateSourceModules = [];

    public function __construct(
        private readonly Dictionary $dictionary,
        private readonly LocaleDictionary $localeDictionary,
        private readonly I18nAiTranslationAdapter $translationAdapter,
        private readonly AiTranslationPublisher $publisher
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function batchTranslateDictionary(
        string $targetLocale,
        string $sourceLocale = AiTranslationConfig::DEFAULT_SOURCE_LOCALE,
        int $batchSize = AiTranslationConfig::DEFAULT_BATCH_SIZE,
        string $strategy = AiTranslationConfig::DEFAULT_STRATEGY,
        bool $publish = true
    ): array {
        $startTime = microtime(true);
        $targetLocale = $this->normalizeLocaleCode($targetLocale);
        $sourceLocale = $this->normalizeLocaleCode($sourceLocale);
        $batchSize = max(1, min(AiTranslationConfig::MAX_BATCH_SIZE, $batchSize));

        if ($targetLocale === '' || $targetLocale === $sourceLocale) {
            return [
                'success' => true,
                'translated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'total' => 0,
                'remaining' => 0,
                'duration' => 0,
                'errors' => [],
                'message' => (string)__('目标语言为空或等于源语言，已跳过。'),
            ];
        }

        $words = $this->getUntranslatedWords($targetLocale, $batchSize, $sourceLocale);
        if ($words === []) {
            return [
                'success' => true,
                'translated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'total' => 0,
                'remaining' => 0,
                'duration' => 0,
                'errors' => [],
                'message' => (string)__('没有待翻译词。'),
            ];
        }

        $translated = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];

        try {
            $response = $this->translationAdapter->translateBatch($words, $sourceLocale, $targetLocale, $strategy);
            if (empty($response['success'])) {
                $errors = array_values(array_map('strval', (array)($response['errors'] ?? [])));
                $message = $errors ? implode('; ', $errors) : (string)__('AI 翻译服务返回失败。');

                return [
                    'success' => false,
                    'translated' => 0,
                    'skipped' => 0,
                    'failed' => count($words),
                    'total' => count($words),
                    'remaining' => $this->countUntranslatedWords($targetLocale, $sourceLocale),
                    'duration' => round(microtime(true) - $startTime, 2),
                    'errors' => $errors,
                    'message' => $message,
                ];
            }

            $translations = $this->normalizeTranslations((array)$response['translations'], $words);
            foreach ($words as $word) {
                $translation = trim((string)($translations[$word] ?? ''));
                $validationError = $this->validateTranslation($word, $translation);
                if ($validationError !== null) {
                    $failed++;
                    $errors[] = $validationError;
                    continue;
                }

                try {
                    $this->saveTranslation($word, $translation, $targetLocale, true, $this->getCandidateSourceModule($word));
                    $translated++;
                } catch (\Throwable $throwable) {
                    $failed++;
                    $errors[] = (string)__('保存翻译失败 [%{1}]: %{2}', [$word, $throwable->getMessage()]);
                }
            }

            $published = false;
            if ($publish && $translated > 0) {
                $published = $this->publisher->publishLocale($targetLocale);
                if (!$published) {
                    $errors[] = (string)__('翻译已写入词典库，但发布语言文件失败：%{1}', [$targetLocale]);
                }
            }

            $remaining = $this->countUntranslatedWords($targetLocale, $sourceLocale);
            $duration = round(microtime(true) - $startTime, 2);
            $success = $translated > 0 || $failed === 0;

            $this->sendSystemMessage(
                $success ? (string)__('I18n AI翻译完成') : (string)__('I18n AI翻译失败'),
                (string)__(
                    "目标语言：%{locale}\n本批词数：%{total}\n成功：%{translated}\n失败：%{failed}\n剩余：%{remaining}\n自动发布：%{published}\n耗时：%{duration}s",
                    [
                        'locale' => $targetLocale,
                        'total' => (string)count($words),
                        'translated' => (string)$translated,
                        'failed' => (string)$failed,
                        'remaining' => (string)$remaining,
                        'published' => $published ? 'yes' : 'no',
                        'duration' => (string)$duration,
                    ]
                ),
                $success ? 'ri-translate' : 'ri-error-warning-line'
            );

            return [
                'success' => $success,
                'translated' => $translated,
                'skipped' => $skipped,
                'failed' => $failed,
                'total' => count($words),
                'remaining' => $remaining,
                'duration' => $duration,
                'errors' => $errors,
                'message' => (string)__('成功翻译 %{1} 个词，失败 %{2} 个词。', [$translated, $failed]),
            ];
        } catch (\Throwable $throwable) {
            $message = $throwable->getMessage();
            $this->sendSystemMessage(
                (string)__('I18n AI翻译异常'),
                (string)__('目标语言：%{1}；错误：%{2}', [$targetLocale, $message]),
                'ri-alarm-warning-line'
            );

            return [
                'success' => false,
                'translated' => $translated,
                'skipped' => $skipped,
                'failed' => count($words),
                'total' => count($words),
                'remaining' => $this->countUntranslatedWords($targetLocale, $sourceLocale),
                'duration' => round(microtime(true) - $startTime, 2),
                'errors' => [$message],
                'message' => $message,
            ];
        }
    }

    /**
     * @return list<string>
     */
    public function getUntranslatedWords(
        string $targetLocale,
        int $limit,
        string $sourceLocale = AiTranslationConfig::DEFAULT_SOURCE_LOCALE
    ): array
    {
        $targetLocale = $this->normalizeLocaleCode($targetLocale);
        $sourceLocale = $this->normalizeLocaleCode($sourceLocale);
        $limit = max(1, min(AiTranslationConfig::MAX_BATCH_SIZE, $limit));
        $words = [];

        foreach ($this->collectCandidateWords($targetLocale, $sourceLocale) as $word) {
            $word = (string)$word;
            if (!$this->shouldTranslateWord($word, $targetLocale)) {
                continue;
            }
            $words[] = $word;
            if (count($words) >= $limit) {
                break;
            }
        }

        return $words;
    }

    public function countUntranslatedWords(
        string $targetLocale,
        string $sourceLocale = AiTranslationConfig::DEFAULT_SOURCE_LOCALE
    ): int
    {
        $targetLocale = $this->normalizeLocaleCode($targetLocale);
        $sourceLocale = $this->normalizeLocaleCode($sourceLocale);
        $missing = 0;

        foreach ($this->collectCandidateWords($targetLocale, $sourceLocale) as $word) {
            $word = (string)$word;
            if ($this->shouldTranslateWord($word, $targetLocale)) {
                $missing++;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function collectCandidateWords(string $targetLocale, string $sourceLocale): array
    {
        $candidates = [];
        $this->appendBackendMenuWords($candidates, $targetLocale);
        $this->appendModuleCsvWords($candidates, $targetLocale, $sourceLocale);
        $this->appendDictionaryWords($candidates);

        return array_values(array_map('strval', array_keys($candidates)));
    }

    /**
     * @param array<string, string> $candidates
     */
    private function appendDictionaryWords(array &$candidates): void
    {
        $page = 1;
        while (true) {
            $rows = $this->dictionary->clear()->reset()
                ->pagination($page, self::DEFAULT_SCAN_PAGE_SIZE)
                ->select()
                ->fetchArray();

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $word = trim((string)($row[Dictionary::schema_fields_WORD] ?? ''));
                if ($word !== '') {
                    $module = trim((string)($row[Dictionary::schema_fields_MODULE] ?? ''));
                    $this->addCandidate($candidates, $word, $module);
                }
            }

            if (count($rows) < self::DEFAULT_SCAN_PAGE_SIZE) {
                break;
            }
            $page++;
        }
    }

    /**
     * @param array<string, string> $candidates
     */
    private function appendModuleCsvWords(array &$candidates, string $targetLocale, string $sourceLocale): void
    {
        foreach ($this->getActiveModuleBasePaths() as $basePath => $moduleName) {
            $sourceWords = $this->readCsvWords($basePath . DS . 'i18n' . DS . $sourceLocale . '.csv');
            $targetWords = $this->readCsvWords($basePath . DS . 'i18n' . DS . $targetLocale . '.csv');

            foreach (array_keys($sourceWords + $targetWords) as $word) {
                $target = trim((string)($targetWords[$word] ?? ''));
                if ($target === '' || $target === $word) {
                    $this->addCandidate($candidates, (string)$word, $moduleName);
                }
            }
        }
    }

    /**
     * @param array<string, string> $candidates
     */
    private function appendBackendMenuWords(array &$candidates, string $targetLocale): void
    {
        foreach ($this->getActiveModuleBasePaths() as $basePath => $moduleName) {
            $menuFile = $basePath . DS . 'etc' . DS . 'backend' . DS . 'menu.xml';
            if (!is_file($menuFile)) {
                continue;
            }

            $targetWords = $this->readCsvWords($basePath . DS . 'i18n' . DS . $targetLocale . '.csv');
            try {
                $xml = simplexml_load_file($menuFile);
            } catch (\Throwable) {
                $xml = false;
            }
            if (!$xml) {
                continue;
            }

            foreach ((array)$xml->xpath('//menu[@title]') as $menuNode) {
                $attributes = $menuNode->attributes();
                $word = trim((string)($attributes['title'] ?? ''));
                $target = trim((string)($targetWords[$word] ?? ''));
                if ($word !== '' && ($target === '' || $target === $word)) {
                    $this->addCandidate($candidates, $word, $moduleName);
                }
            }
        }
    }

    /**
     * @param array<string, string> $candidates
     */
    private function addCandidate(array &$candidates, string $word, string $moduleName = ''): void
    {
        $word = trim($word);
        if ($word === '') {
            return;
        }

        if (!isset($candidates[$word])) {
            $candidates[$word] = $moduleName;
        } elseif ($candidates[$word] === '' && $moduleName !== '') {
            $candidates[$word] = $moduleName;
        }

        if (!isset($this->candidateSourceModules[$word]) || $this->candidateSourceModules[$word] === '') {
            $this->candidateSourceModules[$word] = $moduleName;
        }
    }

    private function getCandidateSourceModule(string $word): string
    {
        return (string)($this->candidateSourceModules[$word] ?? '');
    }

    /**
     * @return array<string, string>
     */
    private function getActiveModuleBasePaths(): array
    {
        if ($this->activeModuleBasePaths !== null) {
            return $this->activeModuleBasePaths;
        }

        $paths = [];
        foreach (Env::getInstance()->getActiveModules() as $moduleKey => $module) {
            $basePath = is_array($module) ? (string)($module['base_path'] ?? '') : '';
            if ($basePath !== '' && is_dir($basePath)) {
                $moduleName = is_array($module) ? (string)($module['name'] ?? $moduleKey) : (string)$moduleKey;
                $paths[rtrim($basePath, "\\/")] = $moduleName;
            }
        }
        $this->activeModuleBasePaths = $paths;

        return $paths;
    }

    /**
     * @return array<string, string>
     */
    private function readCsvWords(string $csvFile): array
    {
        $cacheKey = str_replace('\\', '/', $csvFile);
        if (isset($this->csvWordCache[$cacheKey])) {
            return $this->csvWordCache[$cacheKey];
        }

        $this->csvWordCache[$cacheKey] = [];
        if (!is_file($csvFile)) {
            return [];
        }

        $handle = @fopen($csvFile, 'r');
        if ($handle === false) {
            return [];
        }

        while (($row = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
            $word = trim((string)($row[0] ?? ''));
            $translate = trim((string)($row[1] ?? ''));
            if ($word !== '') {
                $this->csvWordCache[$cacheKey][$word] = $translate;
            }
        }
        fclose($handle);

        return $this->csvWordCache[$cacheKey];
    }

    private function hasCsvTranslation(string $word, string $localeCode): bool
    {
        $index = $this->getCsvTranslatedWordIndex($localeCode);

        return isset($index[$word]);
    }

    /**
     * @return array<string, true>
     */
    private function getCsvTranslatedWordIndex(string $localeCode): array
    {
        if (isset($this->csvTranslatedWordIndex[$localeCode])) {
            return $this->csvTranslatedWordIndex[$localeCode];
        }

        $this->csvTranslatedWordIndex[$localeCode] = [];
        foreach ($this->getActiveModuleBasePaths() as $basePath => $moduleName) {
            $words = $this->readCsvWords($basePath . DS . 'i18n' . DS . $localeCode . '.csv');
            foreach ($words as $word => $translate) {
                if ($translate !== '' && $translate !== $word) {
                    $this->csvTranslatedWordIndex[$localeCode][$word] = true;
                }
            }
        }

        return $this->csvTranslatedWordIndex[$localeCode];
    }

    /**
     * @return array<string, mixed>
     */
    public function importFromCsv(string $csvFilePath, string $localeCode): array
    {
        $startTime = microtime(true);
        $localeCode = $this->normalizeLocaleCode($localeCode);
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        try {
            if (!is_file($csvFilePath)) {
                throw new \RuntimeException((string)__('CSV文件不存在：%{1}', [$csvFilePath]));
            }

            $handle = fopen($csvFilePath, 'r');
            if ($handle === false) {
                throw new \RuntimeException((string)__('无法打开CSV文件：%{1}', [$csvFilePath]));
            }

            $line = 0;
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if (count($row) < 2) {
                    $skipped++;
                    continue;
                }

                $word = trim((string)($row[0] ?? ''));
                $translation = trim((string)($row[1] ?? ''));
                if ($word === '' || $translation === '') {
                    $skipped++;
                    continue;
                }

                try {
                    if ($this->translationExists($word, $localeCode)) {
                        $skipped++;
                        continue;
                    }
                    $this->saveTranslation($word, $translation, $localeCode, false, '');
                    $imported++;
                } catch (\Throwable $throwable) {
                    $failed++;
                    $errors[] = (string)__('第 %{1} 行导入失败：%{2}', [$line, $throwable->getMessage()]);
                }
            }
            fclose($handle);

            return [
                'success' => true,
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'total' => $imported + $skipped + $failed,
                'duration' => round(microtime(true) - $startTime, 2),
                'errors' => $errors,
                'message' => (string)__('成功导入 %{1} 条翻译。', [$imported]),
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'total' => $imported + $skipped + $failed,
                'duration' => round(microtime(true) - $startTime, 2),
                'errors' => [$throwable->getMessage()],
                'message' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function importModuleCsvFiles(string $localeCode): array
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        $files = $this->findCsvFiles(BP . '/app/code', $localeCode);
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($files as $file) {
            $result = $this->importFromCsv($file, $localeCode);
            $imported += (int)($result['imported'] ?? 0);
            $skipped += (int)($result['skipped'] ?? 0);
            $failed += (int)($result['failed'] ?? 0);
            $errors = array_merge($errors, (array)($result['errors'] ?? []));
        }

        return [
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => $failed,
            'files' => count($files),
            'errors' => $errors,
            'message' => (string)__('处理 %{1} 个CSV文件，导入 %{2} 条翻译。', [count($files), $imported]),
        ];
    }

    private function translationExists(string $word, string $localeCode): bool
    {
        $index = $this->getLocaleTranslatedWordIndex($localeCode);

        return isset($index[$word]);
    }

    private function shouldTranslateWord(string $word, string $targetLocale): bool
    {
        return $this->hasTranslatableText($word)
            && !$this->translationExists($word, $targetLocale)
            && !$this->hasGeneratedTranslation($word, $targetLocale)
            && !$this->hasCsvTranslation($word, $targetLocale);
    }

    private function hasTranslatableText(string $word): bool
    {
        return (bool)preg_match('/\p{Han}/u', $word);
    }

    /**
     * @return array<string, true>
     */
    private function getLocaleTranslatedWordIndex(string $localeCode): array
    {
        if (isset($this->localeTranslatedWordIndex[$localeCode])) {
            return $this->localeTranslatedWordIndex[$localeCode];
        }

        $this->localeTranslatedWordIndex[$localeCode] = [];
        $rows = $this->localeDictionary->clear()->reset()
            ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)
            ->select()
            ->fetchArray();

        foreach ((array)$rows as $row) {
            $word = trim((string)($row[LocaleDictionary::schema_fields_WORD] ?? ''));
            $translate = trim((string)($row[LocaleDictionary::schema_fields_TRANSLATE] ?? ''));
            if ($word !== '' && $translate !== '' && $translate !== $word) {
                $this->localeTranslatedWordIndex[$localeCode][$word] = true;
            }
        }

        return $this->localeTranslatedWordIndex[$localeCode];
    }

    private function hasGeneratedTranslation(string $word, string $localeCode): bool
    {
        $index = $this->getGeneratedTranslatedWordIndex($localeCode);

        return isset($index[$word]);
    }

    /**
     * @return array<string, true>
     */
    private function getGeneratedTranslatedWordIndex(string $localeCode): array
    {
        if (isset($this->generatedTranslatedWordIndex[$localeCode])) {
            return $this->generatedTranslatedWordIndex[$localeCode];
        }

        $this->generatedTranslatedWordIndex[$localeCode] = [];
        $localeFile = BP . DS . 'generated' . DS . 'language' . DS . $localeCode . '.php';
        if (!is_file($localeFile)) {
            return [];
        }

        $words = include $localeFile;
        if (!is_array($words)) {
            return [];
        }

        $this->flattenGeneratedTranslations($words, $this->generatedTranslatedWordIndex[$localeCode]);

        return $this->generatedTranslatedWordIndex[$localeCode];
    }

    /**
     * @param array<mixed> $words
     * @param array<string, true> $index
     */
    private function flattenGeneratedTranslations(array $words, array &$index): void
    {
        foreach ($words as $word => $translation) {
            if (is_array($translation)) {
                $this->flattenGeneratedTranslations($translation, $index);
                continue;
            }
            if (
                is_string($word)
                && is_string($translation)
                && $word !== ''
                && $translation !== ''
                && $translation !== $word
            ) {
                $index[$word] = true;
            }
        }
    }

    private function saveTranslation(
        string $word,
        string $translation,
        string $localeCode,
        bool $isAi = false,
        string $sourceModule = ''
    ): void
    {
        $md5 = LocaleDictionary::generateMd5($word, $localeCode);
        $existing = $this->localeDictionary->clear()->reset()
            ->where(LocaleDictionary::schema_fields_MD5, $md5)
            ->find()
            ->fetch();

        if ((int)$existing->getId() > 0) {
            $patch = [LocaleDictionary::schema_fields_TRANSLATE => $translation];
            if ($isAi) {
                $patch[LocaleDictionary::schema_fields_IS_AI] = 1;
                if ($sourceModule !== '') {
                    $patch[LocaleDictionary::schema_fields_SOURCE_MODULE] = $sourceModule;
                }
            }
            $this->localeDictionary->clear()->reset()
                ->where(LocaleDictionary::schema_fields_MD5, $md5)
                ->update($patch)
                ->fetch();
            $this->localeTranslatedWordIndex[$localeCode][$word] = true;
            return;
        }

        $this->localeDictionary->clear()->reset()
            ->insert([
                LocaleDictionary::schema_fields_MD5 => $md5,
                LocaleDictionary::schema_fields_WORD => $word,
                LocaleDictionary::schema_fields_LOCALE_CODE => $localeCode,
                LocaleDictionary::schema_fields_TRANSLATE => $translation,
                LocaleDictionary::schema_fields_IS_AI => $isAi ? 1 : 0,
                LocaleDictionary::schema_fields_SOURCE_MODULE => $sourceModule,
            ], LocaleDictionary::schema_fields_MD5)
            ->fetch();
        $this->localeTranslatedWordIndex[$localeCode][$word] = true;
    }

    /**
     * @param array<mixed> $translations
     * @param list<string> $words
     * @return array<string, string>
     */
    private function normalizeTranslations(array $translations, array $words): array
    {
        $normalized = [];
        foreach ($translations as $key => $translation) {
            if (is_string($key) && in_array($key, $words, true)) {
                $normalized[$key] = (string)$translation;
            }
        }

        if (count($normalized) === count($words)) {
            return $normalized;
        }

        $values = array_values($translations);
        foreach ($words as $index => $word) {
            if (!isset($normalized[$word]) && array_key_exists($index, $values)) {
                $normalized[$word] = (string)$values[$index];
            }
        }

        return $normalized;
    }

    private function validateTranslation(string $word, string $translation): ?string
    {
        if ($translation === '') {
            return (string)__('翻译为空，已跳过：%{1}', [$word]);
        }
        if ($translation === $word) {
            return (string)__('翻译结果与原文相同，已跳过：%{1}', [$word]);
        }

        foreach ($this->tokenPatterns() as $pattern) {
            $sourceTokens = $this->extractTokens($pattern, $word);
            if ($sourceTokens === []) {
                continue;
            }
            $targetTokens = $this->extractTokens($pattern, $translation);
            if ($sourceTokens !== $targetTokens) {
                return (string)__('翻译丢失结构化占位符，已跳过：%{1}', [$word]);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function tokenPatterns(): array
    {
        return [
            '/%\{[A-Za-z0-9_]+\}/u',
            '/\{\{[^}]+\}\}/u',
            '/\{%[^%]+%\}/u',
            '/<\/?[A-Za-z][^>]*>/u',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function extractTokens(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $matches);
        $tokens = array_count_values($matches[0] ?? []);
        ksort($tokens);

        return $tokens;
    }

    /**
     * @return list<string>
     */
    private function findCsvFiles(string $basePath, string $localeCode): array
    {
        if (!is_dir($basePath)) {
            return [];
        }

        $files = [];
        $filename = $localeCode . '.csv';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== $filename) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/i18n/')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function normalizeLocaleCode(string $localeCode): string
    {
        return trim(str_replace('-', '_', $localeCode));
    }

    private function sendSystemMessage(string $title, string $content, string $icon = 'ri-translate'): void
    {
        try {
            w_msg('ai_translation', 'info', $title, $content, [
                'icon' => $icon,
                'source_module' => 'Weline_I18n',
            ]);
        } catch (\Throwable $throwable) {
            w_log_error('I18n AI translation message failed: ' . $throwable->getMessage(), [], 'i18n');
        }
    }
}
