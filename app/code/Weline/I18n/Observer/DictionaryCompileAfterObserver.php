<?php

declare(strict_types=1);

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\I18n\Model\I18n;
use Weline\I18n\Parser as I18nParser;
use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\AiTranslationPublisher;
use Weline\I18n\Service\AiTranslationQueueService;
use Weline\I18n\Service\DictionaryCollectService;

/**
 * dictionary_compile_after：源语言入公共词典；用 DB 词典 republish 覆盖 collect 的 CSV 覆写；新词入队空缺翻译。
 */
class DictionaryCompileAfterObserver implements ObserverInterface
{
    public function __construct(
        private readonly DictionaryCollectService $dictionaryCollectService,
        private readonly AiTranslationQueueService $aiTranslationQueueService,
        private readonly AiTranslationPublisher $aiTranslationPublisher,
        private readonly AiTranslationConfig $aiTranslationConfig,
    ) {
    }

    public function execute(Event &$event): void
    {
        $collectedWords = $event->getData('collected_words');
        $module = $event->getData('module');
        $moduleName = is_string($module) ? $module : '';
        $created = 0;

        if (is_array($collectedWords) && $collectedWords !== []) {
            $persisted = $this->dictionaryCollectService->persistCollectedWords(
                $collectedWords,
                $moduleName,
            );
            $created = (int)($persisted['count'] ?? 0);
        }

        // collect 写入的 generated/language/{locale}.php 只含模块 CSV，会冲掉已 publish 的 DB 译文。
        $this->republishEnabledLocalesFromDictionary();

        if ($created > 0) {
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

    private function republishEnabledLocalesFromDictionary(): void
    {
        try {
            $locales = $this->aiTranslationConfig->getEnabledLocaleCodes();
        } catch (\Throwable $throwable) {
            w_log_error('I18n locale republish list failed: ' . $throwable->getMessage(), [], 'i18n');

            return;
        }

        $source = $this->aiTranslationConfig->getSourceLocale();
        foreach ($locales as $localeCode) {
            $localeCode = trim((string)$localeCode);
            if ($localeCode === '' || $localeCode === $source) {
                continue;
            }
            try {
                $this->aiTranslationPublisher->publishLocale($localeCode);
            } catch (\Throwable $throwable) {
                w_log_error(
                    'I18n locale republish failed for ' . $localeCode . ': ' . $throwable->getMessage(),
                    [],
                    'i18n',
                );
            }
        }
    }
}
