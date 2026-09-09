<?php
declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\Event\EventsManager;

class I18nAiTranslationAdapter
{
    public function __construct(
        private readonly EventsManager $eventsManager
    ) {
    }

    /**
     * @param list<string> $words
     * @param string $concurrencyLane Empty uses AI default lane (dictionary/shared).
     *                                 Pass "local-model" for LocalModel so it can
     *                                 run parallel with dictionary AI translation.
     * @return array{success: bool, translations: array<string, string>, errors: list<string>}
     */
    public function translateBatch(
        array $words,
        string $sourceLocale,
        string $targetLocale,
        string $strategy = AiTranslationConfig::DEFAULT_STRATEGY,
        string $concurrencyLane = '',
    ): array {
        $wordMap = [];
        foreach ($words as $word) {
            $word = (string)$word;
            if ($word !== '') {
                $wordMap[$word] = $word;
            }
        }

        if ($wordMap === []) {
            return ['success' => true, 'translations' => [], 'errors' => []];
        }

        $eventData = [
            'words' => $wordMap,
            'target_locale' => $targetLocale,
            'source_locale' => $sourceLocale,
            'strategy' => $strategy,
            'concurrency_lane' => $concurrencyLane,
            'translations' => [],
            'errors' => [],
            'success' => false,
        ];

        $this->eventsManager->dispatch('Weline_I18n::machine_translate', $eventData);

        return [
            'success' => !empty($eventData['success']),
            'translations' => is_array($eventData['translations'] ?? null) ? $eventData['translations'] : [],
            'errors' => array_values(array_map('strval', (array)($eventData['errors'] ?? []))),
        ];
    }
}
