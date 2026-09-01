<?php

declare(strict_types=1);

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\AiTranslationService;

/**
 * dictionary_translate：AI 批量翻译 → DB → publish locale 文件。
 */
class DictionaryTranslateObserver implements ObserverInterface
{
    public function __construct(
        private readonly AiTranslationService $translationService,
        private readonly AiTranslationConfig $aiConfig,
    ) {
    }

    public function execute(Event &$event): void
    {
        $targetLocale = trim((string)$event->getData('target_locale'));
        if ($targetLocale === '') {
            return;
        }

        $sourceLocale = trim((string)($event->getData('source_locale') ?? ''));
        if ($sourceLocale === '') {
            $sourceLocale = $this->aiConfig->getSourceLocale();
        }
        if ($sourceLocale === '') {
            $sourceLocale = AiTranslationConfig::DEFAULT_SOURCE_LOCALE;
        }
        $module = trim((string)($event->getData('module') ?? ''));
        $publish = (bool)($event->getData('publish') ?? true);
        $batchSize = (int)($event->getData('batch_size') ?? AiTranslationConfig::DEFAULT_BATCH_SIZE);
        $strategy = (string)($event->getData('strategy') ?? AiTranslationConfig::DEFAULT_STRATEGY);

        $scope = [
            'word_prefix' => trim((string)($event->getData('word_prefix') ?? '')),
            'owner' => $module !== '' ? $module : trim((string)($event->getData('owner') ?? '')),
            'module_name' => $module !== '' ? $module : trim((string)($event->getData('module_name') ?? '')),
            'skip_lock' => !empty($event->getData('skip_lock')),
            'allow_key_only_words' => !empty($event->getData('allow_key_only_words')),
        ];

        $wordFilter = $event->getData('word_filter') ?? $event->getData('words');
        if (is_array($wordFilter)) {
            $scope['word_filter'] = $wordFilter;
        }

        $entries = $event->getData('entries');
        if (is_array($entries) && $entries !== []) {
            $words = [];
            foreach ($entries as $entry) {
                if (is_array($entry) && isset($entry['word'])) {
                    $words[] = (string)$entry['word'];
                }
            }
            if ($words !== []) {
                $scope['word_filter'] = array_values(array_unique(array_merge(
                    (array)($scope['word_filter'] ?? []),
                    $words,
                )));
            }
        }

        $result = $this->translationService->batchTranslateDictionary(
            $targetLocale,
            $sourceLocale,
            max(1, $batchSize),
            $strategy,
            $publish,
            $scope,
        );

        $data = $event->getData();
        if (is_array($data)) {
            $data['result'] = $result;
            $event->setData($data);
        }
    }
}
