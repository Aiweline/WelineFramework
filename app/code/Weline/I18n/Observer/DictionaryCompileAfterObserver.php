<?php

declare(strict_types=1);

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\I18n\Model\I18n;
use Weline\I18n\Parser as I18nParser;
use Weline\I18n\Service\AiTranslationPublisher;
use Weline\I18n\Service\AiTranslationQueueService;
use Weline\I18n\Service\DictionaryAreaReseedService;
use Weline\I18n\Service\DictionaryCollectBatchService;
use Weline\I18n\Service\DictionaryCollectService;
use Weline\I18n\Service\DictionaryDirtyLocaleSet;
use Weline\I18n\Service\DictionaryExportLocaleResolver;
use Weline\I18n\Service\DictionaryStaleWordPruneService;

/**
 * dictionary_compile_after：源语言入公共词典；全量时打当前批次后删「非当前批次」短语；
 * 用 DB 词典 republish 写出 generated/language/{locale}.php（含源语；唯一写入权威）；
 * 新词入队空缺翻译。
 *
 * Export 与 Collect 分层：area map 一次加载；单模块默认只导出源语+en_US；
 * --republish-all / 全量仍导出全部发布语种。
 */
class DictionaryCompileAfterObserver implements ObserverInterface
{
    public function __construct(
        private readonly DictionaryCollectService $dictionaryCollectService,
        private readonly DictionaryCollectBatchService $collectBatchService,
        private readonly DictionaryStaleWordPruneService $staleWordPruneService,
        private readonly AiTranslationQueueService $aiTranslationQueueService,
        private readonly AiTranslationPublisher $aiTranslationPublisher,
        private readonly DictionaryAreaReseedService $areaReseedService,
        private readonly DictionaryExportLocaleResolver $exportLocaleResolver,
        private readonly I18n $i18n,
    ) {
    }

    public function execute(Event &$event): void
    {
        $collectedWords = $event->getData('collected_words');
        $module = $event->getData('module');
        $moduleName = is_string($module) ? $module : '';
        $pruneStale = $event->getData('prune_stale');
        $pruneStale = $pruneStale !== false && $pruneStale !== 0 && $pruneStale !== '0';
        $republishAll = $event->getData('republish_all');
        $republishAll = $republishAll === true || $republishAll === 1 || $republishAll === '1';
        $created = 0;
        $touched = 0;

        $fullCollectPrune = $moduleName === ''
            && $pruneStale
            && is_array($collectedWords)
            && $collectedWords !== [];

        if (is_array($collectedWords) && $collectedWords !== []) {
            $batch = null;
            if ($fullCollectPrune) {
                $batch = $this->collectBatchService->allocateNextBatch();
            }
            // 收集期区证据收口（frontend∩backend→shared）；须与 compile Observer 同一 I18n 证据集
            $wordAreas = [];
            try {
                $wordAreas = $this->i18n->pullClassifiedCompileWordAreas();
            } catch (\Throwable) {
                $wordAreas = [];
            }
            // 全量+剥离：一次带批次 persist（分块打标减压）；单模块/--no-prune-stale：不打批次
            $persisted = $this->dictionaryCollectService->persistCollectedWords(
                $collectedWords,
                $moduleName,
                null,
                $batch,
                $wordAreas,
            );
            $created = (int)($persisted['count'] ?? 0);
            $touched = (int)($persisted['touched'] ?? 0);
            $defaultLocaleCount = (int)($persisted['default_locale_count'] ?? 0);

            // 全量才重铺 area；单模块只走 persist 内单调升级，避免整表 demote
            if ($moduleName === '') {
                $this->areaReseedService->reseedFromPathEvidence(
                    $wordAreas,
                    is_array($collectedWords) ? $collectedWords : [],
                );
            }

            I18n::recordCompileAfterSummary([
                'count' => $created,
                'default_locale_count' => $defaultLocaleCount,
                'touched' => $touched,
            ]);

            if ($fullCollectPrune && $batch !== null && $batch > 0) {
                try {
                    $prune = $this->staleWordPruneService->pruneNotCurrentBatch($batch, true);
                    if (($prune['applied'] ?? false) === true) {
                        $this->collectBatchService->commitBatch($batch);
                        w_log_info('I18n phrase stale prune (not current batch)', [
                            'current_batch' => $batch,
                            'alive' => (int)($prune['alive'] ?? 0),
                            'stale' => (int)($prune['stale_words'] ?? 0),
                            'deleted_base' => (int)($prune['deleted_base'] ?? 0),
                            'deleted_locale' => (int)($prune['deleted_locale'] ?? 0),
                        ], 'i18n');
                    } elseif (($prune['aborted_reason'] ?? '') !== '') {
                        w_log_warning('I18n phrase stale prune skipped', [
                            'reason' => (string)$prune['aborted_reason'],
                            'current_batch' => $batch,
                            'alive' => (int)($prune['alive'] ?? 0),
                            'phrase_base' => (int)($prune['phrase_base'] ?? 0),
                        ], 'i18n');
                    } else {
                        // 无旧词可删：仍提交水位，表示本轮收集批次已完成
                        $this->collectBatchService->commitBatch($batch);
                    }
                } catch (\Throwable $throwable) {
                    w_log_error('I18n phrase stale prune failed: ' . $throwable->getMessage(), [], 'i18n');
                }
            }

            $exportLocales = $this->exportLocaleResolver->resolveForCollect(
                $moduleName !== '' ? $moduleName : null,
                $republishAll,
            );
            DictionaryDirtyLocaleSet::markMany($exportLocales);
        }

        // Framework Compiler 不再写 {locale}.php；此处为唯一导出入口（含源语）。
        $this->republishEnabledLocalesFromDictionary($moduleName, $republishAll);

        if ($created > 0 || $touched > 0) {
            try {
                $this->aiTranslationQueueService->enqueueEnabledLocales('dictionary_compile_after');
            } catch (\Throwable $throwable) {
                w_log_error('I18n AI translation enqueue failed: ' . $throwable->getMessage(), [], 'i18n');
            }
        }

        I18n::clearLocalWordsCache();

        try {
            w_cache('i18n')->clear();
        } catch (\Throwable) {
        }

        I18nParser::clearWorkerCaches();
    }

    private function republishEnabledLocalesFromDictionary(string $moduleName, bool $republishAll): void
    {
        $locales = DictionaryDirtyLocaleSet::drain();
        if ($locales === []) {
            $locales = $this->exportLocaleResolver->resolveForCollect(
                $moduleName !== '' ? $moduleName : null,
                $republishAll,
            );
        }

        if ($locales === []) {
            return;
        }

        $prevMem = (string)\ini_get('memory_limit');
        $raw = \trim($prevMem);
        if ($raw !== '' && $raw !== '-1') {
            $unit = \strtoupper(\substr($raw, -1));
            $number = (float)$raw;
            $bytes = match ($unit) {
                'G' => (int)($number * 1024 * 1024 * 1024),
                'M' => (int)($number * 1024 * 1024),
                'K' => (int)($number * 1024),
                default => (int)$number,
            };
            if ($bytes > 0 && $bytes < 512 * 1024 * 1024) {
                @\ini_set('memory_limit', '512M');
            }
        }

        try {
            // area map 一次；各 locale 复用，避免 O(locales×N_base)
            $stats = $this->aiTranslationPublisher->publishLocales($locales);
            w_log_info('I18n dictionary compile_after export', [
                'module' => $moduleName,
                'republish_all' => $republishAll,
                'locale_count' => count($locales),
                'published' => (int)($stats['published'] ?? 0),
                'unchanged' => (int)($stats['unchanged'] ?? 0),
                'failed' => (int)($stats['failed'] ?? 0),
            ], 'i18n');
        } finally {
            if ($prevMem !== '') {
                @\ini_set('memory_limit', $prevMem);
            }
        }
    }
}
