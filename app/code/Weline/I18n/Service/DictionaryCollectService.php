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
    public function collect(string $localeCode, ?callable $onProgress = null): array
    {
        if ($onProgress !== null) {
            RegistryProgress::setWebReporter(static function (string $message, ?int $progress = null) use ($onProgress): void {
                $onProgress($message, $progress);
            });
        }

        try {
            $this->report($onProgress, (string) __('开始扫描模块 CSV 语言包…'), 5);
            $this->compiler->compile();
            $this->compiler->clearTranslationCaches();

            $this->report($onProgress, (string) __('扫描源码 __()/lang 词条…'), 35);
            $this->i18n->convertToLanguageFile(false);

            $defaultLanguageCode = Env::default_LANGUAGE_CODE;
            if ($localeCode === '') {
                $localeCode = $defaultLanguageCode;
            }

            $words = $this->normalizeCollectedWords($this->i18n->getLocalWords($defaultLanguageCode));
            $validatedWords = [];
            foreach ($words as $word => $translate) {
                $validatedWords[WordDictionary::assertWord($word)] = $translate;
            }
            $words = $validatedWords;

            if ($words === []) {
                return [
                    'success' => true,
                    'count' => 0,
                    'default_locale_count' => 0,
                    'queue_count' => 0,
                    'message' => (string) __('没有找到需要收集的词汇'),
                ];
            }

            $this->report(
                $onProgress,
                (string) __('写入词典表，共 %{1} 条待处理…', [count($words)]),
                55,
            );

            $persisted = $this->persistCollectedWords($words, '', $onProgress);
            $collectedCount = (int) $persisted['count'];
            $defaultLocaleCount = (int) $persisted['default_locale_count'];

            $queued = [];
            if ($collectedCount > 0) {
                $this->report($onProgress, (string) __('提交 AI 翻译队列…'), 95);
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
     *
     * @param array<string, string> $words
     * @return array{count: int, default_locale_count: int}
     */
    public function persistCollectedWords(array $words, string $moduleName = '', ?callable $onProgress = null): array
    {
        $words = $this->normalizeCollectedWords($words);
        $validatedWords = [];
        foreach ($words as $word => $translate) {
            try {
                $validatedWords[WordDictionary::assertWord((string)$word)] = $translate;
            } catch (\Throwable) {
                continue;
            }
        }
        $words = $validatedWords;
        if ($words === []) {
            return ['count' => 0, 'default_locale_count' => 0];
        }

        $defaultLanguageCode = Env::default_LANGUAGE_CODE;
        $moduleName = trim($moduleName);
        $wordCountBefore = (int) $this->dictionary->reset()->count();
        $defaultLocaleCountBefore = (int) $this->localeDictionary->reset()
            ->where($this->localeDictionary::schema_fields_LOCALE_CODE, $defaultLanguageCode)
            ->count();

        $wordKeys = array_keys($words);
        $existingRecords = [];
        foreach (array_chunk($wordKeys, 200) as $wordChunk) {
            $records = $this->dictionary->reset()
                ->where($this->dictionary::schema_fields_WORD, $wordChunk, 'IN')
                ->select()
                ->fetchArray();
            foreach ((array) $records as $record) {
                $word = (string) ($record[$this->dictionary::schema_fields_WORD] ?? '');
                if ($word !== '') {
                    $existingRecords[$word] = true;
                }
            }
        }

        $insertData = [];
        foreach ($words as $word => $translate) {
            if (!isset($existingRecords[$word])) {
                $insertData[] = [
                    $this->dictionary::schema_fields_WORD => $word,
                    $this->dictionary::schema_fields_IS_BACKEND => 0,
                    $this->dictionary::schema_fields_MODULE => $moduleName,
                ];
            }
        }

        if ($insertData !== []) {
            $this->report($onProgress, (string) __('批量插入新词条…'), 70);
            foreach (array_chunk($insertData, 999) as $insertDataItem) {
                $this->dictionary->reset()
                    ->insert($insertDataItem, $this->dictionary::schema_fields_WORD)
                    ->fetch();
            }
        }

        $this->report($onProgress, (string) __('同步默认语言译文…'), 85);
        foreach (array_chunk($wordKeys, 200) as $wordChunk) {
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
