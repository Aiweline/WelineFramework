<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Phrase\DictionaryCompiler;
use Weline\Framework\Registry\Service\RegistryProgress;
use Weline\I18n\Model\Dictionary as WordDictionary;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;

/**
 * 词典收集：编译语言包、扫描源码、写入 i18n_dictionary / locale 表。
 */
class DictionaryCollectService
{
    public function __construct(
        private readonly DictionaryCompiler $compiler,
        private readonly I18n $i18n,
        private readonly WordDictionary $dictionary,
        private readonly LocaleDictionary $localeDictionary,
        private readonly AiTranslationQueueService $aiTranslationQueueService,
    ) {
    }

    /**
     * @param null|callable(string $message, ?int $progress): void $onProgress
     * @return array{
     *     success: bool,
     *     count: int,
     *     default_locale_count: int,
     *     queue_count: int,
     *     message: string,
     *     error?: string
     * }
     */
    public function collect(
        string $localeCode,
        ?callable $onProgress = null,
        bool $enqueueAiTranslation = true,
    ): array
    {
        if ($onProgress !== null) {
            RegistryProgress::setWebReporter(static function (string $message, ?int $progress = null) use ($onProgress): void {
                $onProgress($message, $progress);
            });
        }

        try {
            // 单次 compile：CSV 读 + 源码扫描 + persist/reseed/prune/republish 均在
            // dictionary_compile / dictionary_compile_after 内完成。禁止再经
            // getLocalsWords 触发二次 compile（同进程 Gate 重入不 BUSY，整链≈×2）。
            $this->report($onProgress, (string) __('开始收集词典（CSV + 源码扫描）…'), 5);
            $this->compiler->compile();
            $this->compiler->clearTranslationCaches();
            $compileSummary = I18n::pullCompileAfterSummary();
            $this->report($onProgress, (string) __('收集入库与分区导出已同步…'), 70);

            $defaultLanguageCode = Env::default_LANGUAGE_CODE;
            if ($localeCode === '') {
                $localeCode = $defaultLanguageCode;
            }

            $collectedCount = (int)($compileSummary['count'] ?? 0);
            $defaultLocaleCount = (int)($compileSummary['default_locale_count'] ?? 0);
            $touched = (int)($compileSummary['touched'] ?? 0);

            if ($collectedCount === 0 && $touched === 0) {
                return [
                    'success' => true,
                    'count' => 0,
                    'default_locale_count' => 0,
                    'queue_count' => 0,
                    'message' => (string) __('没有找到需要收集的词汇'),
                ];
            }

            $queued = [];
            // compile_after 已入队；此处仅在显式要求且摘要有增量时补一次（后台 SSE 路径）
            if ($enqueueAiTranslation && ($collectedCount > 0 || $touched > 0)) {
                $this->report($onProgress, (string) __('提交 AI 翻译队列…'), 90);
                $queued = $this->aiTranslationQueueService->enqueueEnabledLocales('dictionary_collect');
            }

            $this->report($onProgress, (string) __('收集完成'), 100);

            return [
                'success' => true,
                'count' => $collectedCount,
                'default_locale_count' => $defaultLocaleCount,
                'queue_count' => count($queued),
                'message' => (string) __('收集完成'),
            ];
        } catch (\Throwable $exception) {
            w_log_error('I18n dictionary collect failed: ' . $exception->getMessage(), [
                'trace' => substr($exception->getTraceAsString(), 0, 1200),
            ], 'i18n');

            return [
                'success' => false,
                'count' => 0,
                'default_locale_count' => 0,
                'queue_count' => 0,
                'message' => $exception->getMessage(),
                'error' => $exception->getMessage(),
            ];
        } finally {
            RegistryProgress::setWebReporter(null);
        }
    }

    /**
     * @param null|callable(string, ?int): array<string, mixed> $progressResolver
     */
    public function stream(SseWriter $sse, string $localeCode, ?callable $progressResolver = null): void
    {
        $sse->start();
        $sse->sendEvent('start', ['message' => (string) __('开始收集词典')]);

        $onProgress = function (string $message, ?int $progress = null) use ($sse): void {
            if (!$sse->isAlive()) {
                return;
            }

            $payload = ['message' => $message];
            if ($progress !== null) {
                $payload['progress'] = $progress;
            }
            $sse->sendEvent('progress', $payload);
        };

        $result = $this->collect($localeCode, $onProgress);

        if (!$result['success']) {
            $sse->sendEvent('failed', [
                'message' => $result['message'] ?? (string) __('收集失败'),
            ]);
            $sse->close();

            return;
        }

        $donePayload = [
            'message' => $result['message'],
            'count' => $result['count'],
            'default_locale_count' => $result['default_locale_count'],
            'queue_count' => $result['queue_count'],
            'success' => true,
        ];

        if ($progressResolver !== null) {
            $donePayload['progress'] = $progressResolver($localeCode);
        }

        $sse->complete($donePayload);
    }

    /**
     * 把源语言词写入 i18n_dictionary + 默认 locale 词典（只插缺失，不覆盖已有译文）。
     * $collectBatch > 0 时分块打上当前批次（跳过 @meta::；减压：避免整集对账）。
     *
     * @param array<string, string> $words
     * @param array<string, string|null> $wordAreas word => frontend|backend|shared；缺省=本批无 exclusive 证据（不抬升已有区）
     * @return array{count: int, default_locale_count: int, touched: int, collect_batch: int}
     */
    public function persistCollectedWords(
        array $words,
        string $moduleName = '',
        ?callable $onProgress = null,
        ?int $collectBatch = null,
        array $wordAreas = [],
    ): array {
        $words = $this->normalizeCollectedWords($words);
        $validatedWords = [];
        /** @var array<string, string|null> $validatedAreas */
        $validatedAreas = [];
        foreach ($words as $word => $translate) {
            try {
                $safe = WordDictionary::assertWord((string)$word);
                $validatedWords[$safe] = $translate;
                $hasKey = \array_key_exists((string)$word, $wordAreas) || \array_key_exists($safe, $wordAreas);
                if (!$hasKey) {
                    $validatedAreas[$safe] = null;
                } else {
                    $raw = $wordAreas[(string)$word] ?? $wordAreas[$safe];
                    $validatedAreas[$safe] = $raw === null || $raw === DictionaryAreaPolicy::EVIDENCE_NONE
                        ? null
                        : DictionaryAreaPolicy::normalize((string)$raw);
                }
            } catch (\Throwable) {
                continue;
            }
        }
        $words = $validatedWords;
        if ($words === []) {
            return ['count' => 0, 'default_locale_count' => 0, 'touched' => 0, 'collect_batch' => 0];
        }

        $batch = max(0, (int)($collectBatch ?? 0));
        $defaultLanguageCode = Env::default_LANGUAGE_CODE;
        $moduleName = trim($moduleName);
        $wordCountBefore = (int) $this->dictionary->reset()->count();
        $defaultLocaleCountBefore = (int) $this->localeDictionary->reset()
            ->where($this->localeDictionary::schema_fields_LOCALE_CODE, $defaultLanguageCode)
            ->count();

        $wordKeys = array_keys($words);
        $existingRecords = [];
        foreach (array_chunk($wordKeys, 500) as $wordChunk) {
            $records = $this->dictionary->reset()
                ->where($this->dictionary::schema_fields_WORD, $wordChunk, 'IN')
                ->select()
                ->fetchArray();
            foreach ((array) $records as $record) {
                $word = (string) ($record[$this->dictionary::schema_fields_WORD] ?? '');
                if ($word !== '') {
                    $existingRecords[$word] = $record;
                }
            }
        }

        $insertData = [];
        $existingToMark = [];
        $areaUpgrades = [];
        foreach ($words as $word => $translate) {
            if (str_starts_with($word, DictionaryStaleWordPruneService::META_PREFIX)) {
                continue;
            }
            $targetArea = $validatedAreas[$word] ?? null;
            if (!isset($existingRecords[$word])) {
                $insertArea = $targetArea ?? DictionaryAreaPolicy::AREA_SHARED;
                $row = [
                    $this->dictionary::schema_fields_WORD => $word,
                    $this->dictionary::schema_fields_AREA => $insertArea,
                    $this->dictionary::schema_fields_IS_BACKEND => $insertArea === DictionaryAreaPolicy::AREA_BACKEND ? 1 : 0,
                    $this->dictionary::schema_fields_MODULE => $moduleName,
                ];
                if ($batch > 0) {
                    $row[$this->dictionary::schema_fields_COLLECT_BATCH] = $batch;
                }
                $insertData[] = $row;
            } else {
                $currentArea = (string)($existingRecords[$word][$this->dictionary::schema_fields_AREA] ?? '');
                if ($currentArea === '') {
                    $currentArea = DictionaryAreaPolicy::fromLegacyIsBackend(
                        $existingRecords[$word][$this->dictionary::schema_fields_IS_BACKEND] ?? 0
                    );
                }
                // 本批无 exclusive 证据：不改 area（避免 Service/CSV 把 FE/BE 抬成 shared）
                if ($targetArea !== null) {
                    $nextArea = DictionaryAreaPolicy::applyClassified($currentArea, $targetArea);
                    if ($nextArea !== DictionaryAreaPolicy::normalize($currentArea)) {
                        $areaUpgrades[$word] = $nextArea;
                    }
                }
                if ($batch > 0) {
                    $existingToMark[] = $word;
                }
            }
        }
        $touched = count($insertData) + count($existingToMark);

        if ($insertData !== []) {
            $this->report($onProgress, (string) __('批量插入新词条…'), 70);
            foreach (array_chunk($insertData, 999) as $insertDataItem) {
                $this->dictionary->reset()
                    ->insert($insertDataItem, $this->dictionary::schema_fields_WORD)
                    ->fetch();
            }
        }

        // 单调升级 area（只升不降）；按目标区分块 UPDATE
        if ($areaUpgrades !== []) {
            $byArea = [];
            foreach ($areaUpgrades as $word => $area) {
                $byArea[$area][] = $word;
            }
            foreach ($byArea as $area => $areaWords) {
                foreach (array_chunk($areaWords, 500) as $wordChunk) {
                    $this->dictionary->reset()
                        ->where($this->dictionary::schema_fields_WORD, $wordChunk, 'IN')
                        ->update([
                            $this->dictionary::schema_fields_AREA => $area,
                            $this->dictionary::schema_fields_IS_BACKEND => $area === DictionaryAreaPolicy::AREA_BACKEND ? 1 : 0,
                        ])
                        ->fetch();
                }
            }
        }

        // 分块 UPDATE 已存在行的批次标记（流水线减压：不整表、不对新插入行重复写）
        if ($existingToMark !== []) {
            $this->report($onProgress, (string) __('标记当前收集批次…'), 78);
            foreach (array_chunk($existingToMark, 500) as $wordChunk) {
                $this->dictionary->reset()
                    ->where($this->dictionary::schema_fields_WORD, $wordChunk, 'IN')
                    ->update([$this->dictionary::schema_fields_COLLECT_BATCH => $batch])
                    ->fetch();
            }
        }

        $this->report($onProgress, (string) __('同步默认语言译文…'), 85);
        foreach (array_chunk($wordKeys, 500) as $wordChunk) {
            $existingLocaleWords = [];
            $localeRecords = $this->localeDictionary->reset()
                ->where($this->localeDictionary::schema_fields_LOCALE_CODE, $defaultLanguageCode)
                ->where($this->localeDictionary::schema_fields_WORD, $wordChunk, 'IN')
                ->select()
                ->fetchArray();
            foreach ((array) $localeRecords as $record) {
                $word = (string) ($record[$this->localeDictionary::schema_fields_WORD] ?? '');
                if ($word !== '') {
                    $existingLocaleWords[$word] = true;
                }
            }

            $defaultLocaleRows = [];
            foreach ($wordChunk as $word) {
                if (isset($existingLocaleWords[$word])) {
                    continue;
                }
                $defaultLocaleRows[] = [
                    $this->localeDictionary::schema_fields_WORD => $word,
                    $this->localeDictionary::schema_fields_LOCALE_CODE => $defaultLanguageCode,
                    $this->localeDictionary::schema_fields_TRANSLATE => $words[$word] ?? $word,
                    $this->localeDictionary::schema_fields_MD5 => $this->localeDictionary->getMd5($word, $defaultLanguageCode),
                ];
            }

            if ($defaultLocaleRows !== []) {
                $this->localeDictionary->reset()
                    ->insert($defaultLocaleRows, $this->localeDictionary::schema_fields_MD5)
                    ->fetch();
            }
        }

        return [
            'count' => max(0, (int) $this->dictionary->reset()->count() - $wordCountBefore),
            'default_locale_count' => max(
                0,
                (int) $this->localeDictionary->reset()
                    ->where($this->localeDictionary::schema_fields_LOCALE_CODE, $defaultLanguageCode)
                    ->count() - $defaultLocaleCountBefore,
            ),
            'touched' => $touched,
            'collect_batch' => $batch,
        ];
    }

    /**
     * @param null|callable(string, ?int): void $onProgress
     */
    private function report(?callable $onProgress, string $message, ?int $progress = null): void
    {
        if ($onProgress !== null) {
            $onProgress($message, $progress);
        }
    }

    /**
     * @param array<mixed, mixed> $words
     * @return array<string, string>
     */
    private function normalizeCollectedWords(array $words): array
    {
        $normalized = [];

        foreach ($words as $word => $translate) {
            if (!is_string($word) && !is_int($word)) {
                continue;
            }

            if (!is_scalar($translate) && $translate !== null) {
                continue;
            }

            $word = trim((string) $word);
            if ($word === '') {
                continue;
            }

            $translate = $translate === null ? '' : (string) $translate;
            $normalized[$word] = trim($translate) === '' ? $word : $translate;
        }

        return $normalized;
    }
}
