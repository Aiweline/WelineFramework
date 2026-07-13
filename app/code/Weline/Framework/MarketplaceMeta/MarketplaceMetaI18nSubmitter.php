<?php

declare(strict_types=1);

namespace Weline\Framework\MarketplaceMeta;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class MarketplaceMetaI18nSubmitter
{
    public const EVENT_NAME = 'Weline_Framework_MarketplaceMeta::collect_translations';

    public function __construct(
        private readonly ?EventsManager $eventsManager = null
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{count:int,event:string}
     */
    public function submit(string $moduleName, array $meta): array
    {
        $translations = $this->collectTranslations($moduleName, $meta);
        if ($translations === []) {
            return [
                'count' => 0,
                'event' => self::EVENT_NAME,
            ];
        }

        $eventData = [
            'module' => $moduleName,
            'translations' => $translations,
        ];
        $this->eventsManager()->dispatch(self::EVENT_NAME, $eventData);

        return [
            'count' => count($translations),
            'event' => self::EVENT_NAME,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @return list<array{word:string,translate:string,locale:string,module:string,is_backend:int}>
     */
    private function collectTranslations(string $moduleName, array $meta): array
    {
        $sourceLocale = trim((string)($meta['i18n']['source_locale'] ?? 'zh_Hans_CN')) ?: 'zh_Hans_CN';
        $translations = [];

        $locales = is_array($meta['i18n']['locales'] ?? null) ? $meta['i18n']['locales'] : [];
        $source = is_array($locales[$sourceLocale] ?? null) ? $locales[$sourceLocale] : [];
        foreach (['display_name', 'description'] as $field) {
            $sourceWord = trim((string)($source[$field] ?? ''));
            if ($sourceWord === '') {
                continue;
            }
            $values = [];
            foreach ($locales as $locale => $localeData) {
                if (is_array($localeData) && trim((string)($localeData[$field] ?? '')) !== '') {
                    $values[(string)$locale] = trim((string)$localeData[$field]);
                }
            }
            $translations = $this->appendLocalizedTranslations($translations, $moduleName, $sourceWord, $sourceLocale, $values);
        }

        foreach ((array)($meta['tags'] ?? []) as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $labels = is_array($tag['labels'] ?? null) ? $tag['labels'] : [];
            $sourceWord = trim((string)($labels[$sourceLocale] ?? ''));
            if ($sourceWord === '') {
                continue;
            }
            $translations = $this->appendLocalizedTranslations($translations, $moduleName, $sourceWord, $sourceLocale, $labels);
        }

        $seo = is_array($meta['seo'] ?? null) ? $meta['seo'] : [];
        foreach (['title', 'description'] as $field) {
            $values = is_array($seo[$field] ?? null) ? $seo[$field] : [];
            $sourceWord = trim((string)($values[$sourceLocale] ?? ''));
            if ($sourceWord !== '') {
                $translations = $this->appendLocalizedTranslations($translations, $moduleName, $sourceWord, $sourceLocale, $values);
            }
        }

        return array_values($translations);
    }

    /**
     * @param array<string, array{word:string,translate:string,locale:string,module:string,is_backend:int}> $translations
     * @param array<string, mixed> $values
     * @return array<string, array{word:string,translate:string,locale:string,module:string,is_backend:int}>
     */
    private function appendLocalizedTranslations(
        array $translations,
        string $moduleName,
        string $sourceWord,
        string $sourceLocale,
        array $values
    ): array {
        $values[$sourceLocale] = trim((string)($values[$sourceLocale] ?? $sourceWord)) ?: $sourceWord;
        foreach ($values as $locale => $translate) {
            $locale = trim((string)$locale);
            $translate = trim((string)$translate);
            if ($locale === '' || $translate === '') {
                continue;
            }
            $key = md5($sourceWord . "\0" . $locale . "\0" . $translate);
            $translations[$key] = [
                'word' => $sourceWord,
                'translate' => $translate,
                'locale' => $locale,
                'module' => $moduleName,
                'is_backend' => 1,
            ];
        }

        return $translations;
    }

    private function eventsManager(): EventsManager
    {
        return $this->eventsManager ?? ObjectManager::getInstance(EventsManager::class);
    }
}
