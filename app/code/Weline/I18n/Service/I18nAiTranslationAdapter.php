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

    /**
     * One dictionary word → many target locales in as few model calls as the AI side packs.
     *
     * @param list<string> $targetLocales
     * @return array{success: bool, translations: array<string, string>, errors: list<string>}
     *         translations keyed by locale_code
     */
    public function translateWordToLocales(
        string $word,
        array $targetLocales,
        string $sourceLocale,
        string $strategy = AiTranslationConfig::DEFAULT_STRATEGY,
        string $concurrencyLane = '',
    ): array {
        $word = trim($word);
        $locales = [];
        foreach ($targetLocales as $locale) {
            $locale = trim((string)$locale);
            if ($locale !== '' && !isset($locales[$locale])) {
                $locales[$locale] = $locale;
            }
        }
        $targetLocales = array_values($locales);
        if ($word === '' || $targetLocales === []) {
            return ['success' => true, 'translations' => [], 'errors' => []];
        }

        $eventData = [
            'mode' => 'multi_locale',
            'word' => $word,
            'words' => [$word => $word],
            'target_locales' => $targetLocales,
            'target_locale' => $targetLocales[0],
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
