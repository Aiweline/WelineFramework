<?php
declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\Database\Transaction\Exception\UnsupportedAsyncTransactionConnectionException;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Dictionary;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;

class AiTranslationService
{
    private const DEFAULT_SCAN_PAGE_SIZE = 500;

    /**
     * One SQL page. Loop until this translation batch is full or the page comes back short.
     * Never load the whole pending set into PHP.
     */
    private const PENDING_READ_PAGE_SIZE = 100;

    /**
     * @var array<string, array<string, true>>
     */
    private array $localeTranslatedWordIndex = [];

    /**
     * @var array<string, string>
     */
    private array $candidateSourceModules = [];

    public function __construct(
        private readonly Dictionary $dictionary,
        private readonly LocaleDictionary $localeDictionary,
        private readonly I18nAiTranslationAdapter $translationAdapter,
        private readonly AiTranslationPublisher $publisher,
        private readonly AiTranslationBatchLock $batchLock,
        private readonly AiTranslationWordSkipStore $wordSkipStore,
    ) {
    }

    /**
     * @param array{
     *     word_filter?: list<string>,
     *     word_prefix?: string,
     *     allow_key_only_words?: bool,
     *     owner?: string,
     *     module_name?: string,
     *     skip_lock?: bool,
     * } $scope
     * @return array<string, mixed>
     */
    public function batchTranslateDictionary(
        string $targetLocale,
        string $sourceLocale = AiTranslationConfig::DEFAULT_SOURCE_LOCALE,
        int $batchSize = AiTranslationConfig::DEFAULT_BATCH_SIZE,
        string $strategy = AiTranslationConfig::DEFAULT_STRATEGY,
        bool $publish = true,
        array $scope = [],
    ): array {
        $startTime = microtime(true);
        $targetLocale = $this->normalizeLocaleCode($targetLocale);
        $sourceLocale = $this->normalizeLocaleCode($sourceLocale);
        $batchSize = max(1, min(AiTranslationConfig::MAX_BATCH_SIZE, $batchSize));
        $scope = $this->normalizeBatchScope($scope);

        if ($targetLocale === '') {
            return [
                'success' => true,
                'translated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'total' => 0,
                'remaining' => 0,
                'duration' => 0,
                'errors' => [],
                'message' => (string)__('目标语言为空，已跳过。'),
            ];
        }

        if ($targetLocale === $sourceLocale && !$scope['allow_key_only_words']) {
            return [
                'success' => true,
                'translated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'total' => 0,
                'remaining' => 0,
                'duration' => 0,
                'errors' => [],
                'message' => (string)__('目标语言等于源语言，已跳过。'),
            ];
        }

        $lockHandle = null;
        if (!$scope['skip_lock']) {
            $lockHandle = $this->batchLock->tryAcquire($targetLocale, (string)$scope['owner']);
            if ($lockHandle === null) {
                return [
                    'success' => true,
                    'translated' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'total' => 0,
                    'remaining' => $this->countScopedUntranslatedWords($targetLocale, $sourceLocale, $scope),
                    'duration' => round(microtime(true) - $startTime, 2),
                    'errors' => [],
                    'message' => (string)__(
                        '语言 %{1} 已有翻译批次在运行，本批已跳过以避免重复翻译。',
                        [$targetLocale],
                    ),
                ];
            }
        }

        try {
            return $this->executeBatchTranslateDictionary(
                $targetLocale,
                $sourceLocale,
                $batchSize,
                $strategy,
                $publish,
                $scope,
                $startTime,
            );
        } finally {
            $this->batchLock->release($lockHandle);
        }
    }

    /**
     * @param array{
     *     word_filter: list<string>,
     *     word_prefix: string,
     *     allow_key_only_words: bool,
     *     owner: string,
     *     skip_lock: bool,
     * } $scope
     * @return array<string, mixed>
     */
    private function executeBatchTranslateDictionary(
        string $targetLocale,
        string $sourceLocale,
        int $batchSize,
        string $strategy,
        bool $publish,
        array $scope,
        float $startTime,
    ): array {
        $words = $this->resolveBatchWords($targetLocale, $sourceLocale, $batchSize, $scope);
        if ($words === []) {
            return [
                'success' => true,
                'translated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'total' => 0,
                'remaining' => 0,
                'duration' => round(microtime(true) - $startTime, 2),
                'errors' => [],
                'message' => (string)__('没有待翻译词。'),
            ];
        }

        $translated = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];

        try {
            $response = $this->translationAdapter->translateBatch(
                $words,
                $sourceLocale,
                $targetLocale,
                $strategy,
                'dictionary',
            );
            if (empty($response['success'])) {
                $errors = array_values(array_map('strval', (array)($response['errors'] ?? [])));
                $message = $errors ? implode('; ', $errors) : (string)__('AI 翻译服务返回失败。');

                return [
                    'success' => false,
                    'translated' => 0,
                    'skipped' => 0,
                    'failed' => count($words),
                    'total' => count($words),
                    'remaining' => $this->countScopedUntranslatedWords($targetLocale, $sourceLocale, $scope),
                    'duration' => round(microtime(true) - $startTime, 2),
                    'errors' => $errors,
                    'message' => $message,
                ];
            }

            $translations = $this->normalizeTranslations((array)$response['translations'], $words);
            foreach ($words as $word) {
                if ($this->wordSkipStore->shouldSkip($targetLocale, $word)) {
                    $skipped++;
                    $errors[] = (string)__(
                        '多次失败已绕过：%{1}',
                        [$word],
                    );
                    continue;
                }
                $translation = trim((string)($translations[$word] ?? ''));
                // 乱码只跳过本条，不把整批标失败，其余词继续保存。
                if (I18nCsvCodec::isGarbledText($word) || I18nCsvCodec::isGarbledText($translation)) {
                    $skipped++;
                    $errors[] = (string)__('译文乱码，已跳过本条（不影响本批其余词）：%{1}', [$word]);
                    continue;
                }
                $validationError = $this->validateTranslation($word, $translation);
                if ($validationError !== null) {
                    $recorded = $this->wordSkipStore->recordFailure($targetLocale, $word, $validationError);
                    if ($recorded['skipped']) {
                        $skipped++;
                        $errors[] = (string)__(
                            '多次失败已绕过（%{count}次）：%{word}；原因：%{reason}',
                            [
                                'count' => (string)$recorded['count'],
                                'word' => $word,
                                'reason' => (string)$recorded['reason'],
                            ],
                        );
                    } else {
                        $failed++;
                        $errors[] = $validationError;
                    }
                    continue;
                }

                try {
                    $sourceModule = $this->getCandidateSourceModule($word);
                    if ($sourceModule === '' && $scope['module_name'] !== '') {
                        $sourceModule = $scope['module_name'];
                    }
                    $this->saveTranslation($word, $translation, $targetLocale, true, $sourceModule);
                    $this->wordSkipStore->clearSuccess($targetLocale, $word);
                    $translated++;
                } catch (\Throwable $throwable) {
                    $saveError = (string)__('保存翻译失败 [%{1}]: %{2}', [$word, $throwable->getMessage()]);
                    $recorded = $this->wordSkipStore->recordFailure($targetLocale, $word, $saveError);
                    if ($recorded['skipped']) {
                        $skipped++;
                        $errors[] = (string)__(
                            '多次失败已绕过（%{count}次）：%{word}；原因：%{reason}',
                            [
                                'count' => (string)$recorded['count'],
                                'word' => $word,
                                'reason' => (string)$recorded['reason'],
                            ],
                        );
                    } else {
                        $failed++;
                        $errors[] = $saveError;
                    }
                }
            }

            $published = false;
            if ($publish && $translated > 0) {
                $published = $this->publisher->publishLocale($targetLocale);
                if (!$published) {
                    $errors[] = (string)__('翻译已写入词典库，但发布语言文件失败：%{1}', [$targetLocale]);
                }
            }

            $remaining = $this->countScopedUntranslatedWords($targetLocale, $sourceLocale, $scope);
            $duration = round(microtime(true) - $startTime, 2);
            // 乱码计入 skipped，不抬高 failed，避免单条乱码把整批 success 打成 false。
            $success = $translated > 0 || $failed === 0;

            $this->sendSystemMessage(
                $success ? (string)__('I18n AI翻译完成') : (string)__('I18n AI翻译失败'),
                (string)__(
                    "目标语言：%{locale}\n本批词数：%{total}\n成功：%{translated}\n跳过：%{skipped}\n失败：%{failed}\n剩余：%{remaining}\n自动发布：%{published}\n耗时：%{duration}s",
                    [
                        'locale' => $targetLocale,
                        'total' => (string)count($words),
                        'translated' => (string)$translated,
                        'skipped' => (string)$skipped,
                        'failed' => (string)$failed,
                        'remaining' => (string)$remaining,
                        'published' => $published ? 'yes' : 'no',
                        'duration' => (string)$duration,
                    ]
                ),
                $success ? 'language' : 'warning'
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
                'message' => (string)__(
                    '成功翻译 %{1} 个词，跳过 %{2} 个词（含乱码单条），失败 %{3} 个词。',
                    [$translated, $skipped, $failed]
                ),
            ];
        } catch (\Throwable $throwable) {
            $message = $throwable->getMessage();
            $this->sendSystemMessage(
                (string)__('I18n AI翻译异常'),
                (string)__('目标语言：%{1}；错误：%{2}', [$targetLocale, $message]),
                'warning'
            );

            return [
                'success' => false,
                'translated' => $translated,
                'skipped' => $skipped,
                'failed' => count($words),
                'total' => count($words),
                'remaining' => $this->countScopedUntranslatedWords($targetLocale, $sourceLocale, $scope),
                'duration' => round(microtime(true) - $startTime, 2),
                'errors' => [$message],
                'message' => $message,
            ];
        }
    }

    /**
     * @param array{
     *     word_filter: list<string>,
     *     word_prefix: string,
     *     allow_key_only_words: bool,
     *     owner: string,
     *     skip_lock: bool,
     * } $scope
     * @return list<string>
     */
    private function resolveBatchWords(
        string $targetLocale,
        string $sourceLocale,
        int $batchSize,
        array $scope,
    ): array {
        if ($scope['word_filter'] !== []) {
            $words = [];
            foreach ($scope['word_filter'] as $word) {
                $word = trim((string)$word);
                if ($word === '') {
                    continue;
                }
                if (!$this->shouldTranslateWord($word, $targetLocale, $scope['allow_key_only_words'])) {
                    continue;
                }
                $words[] = $word;
                if (count($words) >= $batchSize) {
                    break;
                }
            }

            return $words;
        }

        return $this->getUntranslatedWords(
            $targetLocale,
            $batchSize,
            $sourceLocale,
            $scope['word_prefix'],
            $scope['allow_key_only_words'],
        );
    }

    /**
     * @param array{
     *     word_filter?: list<string>,
     *     word_prefix?: string,
     *     allow_key_only_words?: bool,
     *     owner?: string,
     *     module_name?: string,
     *     skip_lock?: bool,
     * } $scope
     * @return array{
     *     word_filter: list<string>,
     *     word_prefix: string,
     *     allow_key_only_words: bool,
     *     owner: string,
     *     skip_lock: bool,
     * }
     */
    private function normalizeBatchScope(array $scope): array
    {
        $wordFilter = [];
        if (isset($scope['word_filter']) && is_array($scope['word_filter'])) {
            foreach ($scope['word_filter'] as $word) {
                $word = trim((string)$word);
                if ($word !== '') {
                    $wordFilter[] = $word;
                }
            }
        } elseif (isset($scope['words']) && is_array($scope['words'])) {
            foreach ($scope['words'] as $word) {
                $word = trim((string)$word);
                if ($word !== '') {
                    $wordFilter[] = $word;
                }
            }
        }

        return [
            'word_filter' => array_values(array_unique($wordFilter)),
            'word_prefix' => trim((string)($scope['word_prefix'] ?? '')),
            'allow_key_only_words' => !empty($scope['allow_key_only_words'])
                || (string)($scope['domain'] ?? '') === 'google_taxonomy',
            'owner' => trim((string)($scope['owner'] ?? '')),
            'module_name' => trim((string)($scope['module_name'] ?? '')),
            'skip_lock' => !empty($scope['skip_lock']),
        ];
    }

    /**
     * @param array{
     *     word_filter: list<string>,
     *     word_prefix: string,
     *     allow_key_only_words: bool,
     *     owner: string,
     *     skip_lock: bool,
     * } $scope
     */
    private function countScopedUntranslatedWords(
        string $targetLocale,
        string $sourceLocale,
        array $scope,
    ): int {
        if ($scope['word_filter'] !== []) {
            $missing = 0;
            foreach ($scope['word_filter'] as $word) {
                $word = trim((string)$word);
                if ($word !== '' && $this->shouldTranslateWord($word, $targetLocale, $scope['allow_key_only_words'])) {
                    $missing++;
                }
            }

            return $missing;
        }

        return $this->countUntranslatedWords(
            $targetLocale,
            $sourceLocale,
            $scope['word_prefix'],
            $scope['allow_key_only_words'],
        );
    }

    /**
     * @return list<string>
     */
    public function getUntranslatedWords(
        string $targetLocale,
        int $limit,
        string $sourceLocale = AiTranslationConfig::DEFAULT_SOURCE_LOCALE,
        string $wordPrefix = '',
        bool $allowKeyOnlyWords = false,
    ): array
    {
        $targetLocale = $this->normalizeLocaleCode($targetLocale);
        $sourceLocale = $this->normalizeLocaleCode($sourceLocale);
        if ($targetLocale === '' || ($targetLocale === $sourceLocale && !$allowKeyOnlyWords)) {
            return [];
        }

        $limit = max(1, min(AiTranslationConfig::MAX_BATCH_SIZE, $limit));
        $wordPrefix = trim($wordPrefix);
        $this->candidateSourceModules = [];
        $words = [];
        $offset = 0;
        $pageSize = self::PENDING_READ_PAGE_SIZE;
        // Skip-store can hide the first rows of a page. Bound the walk; do not scan the table.
        $maxPages = (int)ceil(($limit + AiTranslationWordSkipStore::MAX_ENTRIES_PER_LOCALE) / $pageSize) + 1;

        for ($page = 0; $page < $maxPages && count($words) < $limit; $page++) {
            $rows = $this->queryPendingDictionaryRows(
                $targetLocale,
                $wordPrefix,
                $allowKeyOnlyWords,
                $pageSize,
                $offset,
            );
            if ($rows === []) {
                break;
            }
            $offset += count($rows);
            foreach ($rows as $row) {
                $word = trim((string)($row['word'] ?? ''));
                if ($word === '' || $this->wordSkipStore->shouldSkip($targetLocale, $word)) {
                    continue;
                }
                $this->candidateSourceModules[$word] = trim((string)($row['module'] ?? ''));
                $words[] = $word;
                if (count($words) >= $limit) {
                    break;
                }
            }
            if (count($rows) < $pageSize) {
                break;
            }
        }

        return $words;
    }

    public function countUntranslatedWords(
        string $targetLocale,
        string $sourceLocale = AiTranslationConfig::DEFAULT_SOURCE_LOCALE,
        string $wordPrefix = '',
        bool $allowKeyOnlyWords = false,
    ): int
    {
        $targetLocale = $this->normalizeLocaleCode($targetLocale);
        $sourceLocale = $this->normalizeLocaleCode($sourceLocale);
        if ($targetLocale === '' || ($targetLocale === $sourceLocale && !$allowKeyOnlyWords)) {
            return 0;
        }

        $pageSize = self::PENDING_READ_PAGE_SIZE;
        $total = 0;
        $offset = 0;
        $maxOffset = $pageSize * 2000;

        while ($offset <= $maxOffset) {
            $rows = $this->queryPendingDictionaryRows(
                $targetLocale,
                trim($wordPrefix),
                $allowKeyOnlyWords,
                $pageSize,
                $offset,
            );
            $count = count($rows);
            $total += $count;
            if ($count < $pageSize) {
                break;
            }
            $offset += $count;
        }

        return $total;
    }

    /**
     * Next untranslated dictionary rows only. Word collection belongs to i18n:collect.
     *
     * @return list<array{word:string,module:string}>
     */
    private function queryPendingDictionaryRows(
        string $targetLocale,
        string $wordPrefix,
        bool $allowKeyOnlyWords,
        int $limit,
        int $offset,
    ): array {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $sql = 'SELECT d.word AS word, d.module AS module FROM '
            . $this->dictionary->getTable()
            . ' d WHERE '
            . $this->pendingDictionaryWhereSql($targetLocale, $wordPrefix, $allowKeyOnlyWords)
            . ' ORDER BY d.word LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = [];
        foreach ($this->fetchSqlRows($sql) as $row) {
            $rows[] = [
                'word' => (string)($row['word'] ?? $row['WORD'] ?? ''),
                'module' => (string)($row['module'] ?? $row['MODULE'] ?? ''),
            ];
        }

        return $rows;
    }

    private function pendingDictionaryWhereSql(
        string $targetLocale,
        string $wordPrefix,
        bool $allowKeyOnlyWords,
    ): string {
        $locale = $this->sqlQuote($targetLocale);
        $where = 'NOT EXISTS (SELECT 1 FROM '
            . $this->localeDictionary->getTable()
            . ' l WHERE l.locale_code = ' . $locale
            . ' AND l.word = d.word AND TRIM(l.translate) <> \'\' AND l.translate <> d.word)';

        if ($wordPrefix !== '') {
            $where .= ' AND d.word LIKE ' . $this->sqlQuote($this->escapeLike($wordPrefix) . '%');
        } else {
            $where .= ' AND d.word NOT LIKE ' . $this->sqlQuote('@meta::%');
        }

        $han = "d.word ~ '[" . "\u{4e00}" . "-" . "\u{9fff}" . "]'";
        if ($allowKeyOnlyWords) {
            $where .= ' AND (' . $han . ' OR d.word LIKE ' . $this->sqlQuote('google_taxonomy.%') . ')';
        } else {
            $where .= ' AND ' . $han;
        }

        return $where;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchSqlRows(string $sql): array
    {
        $statement = $this->dictionary->getConnection()->getConnector()->getLink()->query($sql);
        if ($statement === false) {
            return [];
        }
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    private function sqlQuote(string $value): string
    {
        $quoted = $this->dictionary->getConnection()->getConnector()->getLink()->quote($value);
        if (!is_string($quoted) || $quoted === '') {
            throw new \RuntimeException('Failed to quote SQL value');
        }

        return $quoted;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function getCandidateSourceModule(string $word): string
    {
        return (string)($this->candidateSourceModules[$word] ?? '');
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
                // Collect placeholders copy CJK source into non-zh CSV; importing
                // them as "translations" blocks AI and masks real dictionary copy.
                if ($word === $translation
                    && !str_starts_with(strtolower($localeCode), 'zh')
                    && preg_match('/[\x{4e00}-\x{9fff}]/u', $word) === 1
                ) {
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

    private function shouldTranslateWord(
        string $word,
        string $targetLocale,
        bool $allowKeyOnlyWords = false,
    ): bool
    {
        $hasTranslatableText = $this->hasTranslatableText($word)
            || ($allowKeyOnlyWords && str_starts_with($word, 'google_taxonomy.'));

        // 是否已译只看 DB locale 词典；不加载 generated/language 或模块 CSV。
        // Exhausted failure words are cached-skipped so queues stop re-picking them.
        return $hasTranslatableText
            && !$this->translationExists($word, $targetLocale)
            && !$this->wordSkipStore->shouldSkip($targetLocale, $word);
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
        $page = 1;
        while (true) {
            $offset = ($page - 1) * self::DEFAULT_SCAN_PAGE_SIZE;
            $rows = $this->localeDictionary->clear()->reset()
                ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)
                ->limit(self::DEFAULT_SCAN_PAGE_SIZE, $offset)
                ->select()
                ->fetchArray();

            if (empty($rows)) {
                break;
            }

            foreach ((array)$rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $word = trim((string)($row[LocaleDictionary::schema_fields_WORD] ?? ''));
                $translate = trim((string)($row[LocaleDictionary::schema_fields_TRANSLATE] ?? ''));
                if ($word !== '' && $translate !== '' && $translate !== $word) {
                    $this->localeTranslatedWordIndex[$localeCode][$word] = true;
                }
            }

            if (count($rows) < self::DEFAULT_SCAN_PAGE_SIZE) {
                break;
            }
            $page++;
        }

        return $this->localeTranslatedWordIndex[$localeCode];
    }

    private function saveTranslation(
        string $word,
        string $translation,
        string $localeCode,
        bool $isAi = false,
        string $sourceModule = ''
    ): void
    {
        $connection = $this->localeDictionary->getConnection();
        $publisher = ObjectManager::getInstance(I18nResourceChangePublisher::class);
        if (TransactionContext::logicalConnectionKey($connection->getConnector())
            !== TransactionContext::logicalConnectionKey($publisher->connection()->getConnector())) {
            throw new UnsupportedAsyncTransactionConnectionException(__('词典写入与资源变更必须使用同一逻辑数据库连接'));
        }
        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        $transactions->run($connection, function () use ($word, $translation, $localeCode, $isAi, $sourceModule, $publisher, $transactions, $connection): void {
            $md5 = LocaleDictionary::generateMd5($word, $localeCode);
            $existing = $this->localeDictionary->clear()->reset()
                ->where(LocaleDictionary::schema_fields_MD5, $md5)
                ->find()
                ->fetch();

            if ($existing->getId()) {
                $patch = [LocaleDictionary::schema_fields_TRANSLATE => $translation];
                if ($isAi) {
                    $patch[LocaleDictionary::schema_fields_IS_AI] = 1;
                    if ($sourceModule !== '') {
                        $patch[LocaleDictionary::schema_fields_SOURCE_MODULE] = $sourceModule;
                    }
                }
                $written = $this->localeDictionary->clear()->reset()
                    ->where(LocaleDictionary::schema_fields_MD5, $md5)
                    ->update($patch)
                    ->fetch();
            } else {
                $written = $this->localeDictionary->clear()->reset()
                    ->insert([
                        LocaleDictionary::schema_fields_MD5 => $md5,
                        LocaleDictionary::schema_fields_WORD => $word,
                        LocaleDictionary::schema_fields_LOCALE_CODE => $localeCode,
                        LocaleDictionary::schema_fields_TRANSLATE => $translation,
                        LocaleDictionary::schema_fields_IS_AI => $isAi ? 1 : 0,
                        LocaleDictionary::schema_fields_SOURCE_MODULE => $sourceModule,
                    ], LocaleDictionary::schema_fields_MD5)
                    ->fetch();
            }
            if ($written === false) {
                throw new \RuntimeException(__('词典翻译写入失败'));
            }
            $publisher->publishAction('dictionary-ai-save', ['word' => $word, 'locale_code' => $localeCode], $localeCode);
            $transactions->afterCommit($connection, 'i18n.ai-word-index.' . $md5, function () use ($localeCode, $word): void {
                $this->localeTranslatedWordIndex[$localeCode][$word] = true;
            });
        });
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
        if (I18nCsvCodec::isGarbledText($word) || I18nCsvCodec::isGarbledText($translation)) {
            return (string)__('译文乱码，已跳过本条：%{1}', [$word]);
        }
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

    private function sendSystemMessage(string $title, string $content, string $icon = 'language'): void
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
