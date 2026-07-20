<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Localization;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\GlobalDictionaryProviderInterface;
use Weline\I18n\Model\Locale\Dictionary;

final class GlobalDictionaryProvider implements GlobalDictionaryProviderInterface
{
    public function word(string $locale, string $word): ?string
    {
        $word = \trim($word);
        if ($word === '') {
            return null;
        }

        $entry = ObjectManager::getInstance(Dictionary::class)->getEntry($word, $locale);
        $translation = \trim((string)($entry?->translation ?? ''));

        return $translation !== '' && $translation !== $word ? $translation : null;
    }

    public function words(string $locale, array $modules = []): array
    {
        $modules = \array_values(\array_unique(\array_filter(
            \array_map(static fn(mixed $module): string => \trim((string)$module), $modules),
            static fn(string $module): bool => $module !== '',
        )));

        $query = ObjectManager::getInstance(Dictionary::class)->reset()
            ->where(Dictionary::schema_fields_LOCALE_CODE, $locale)
            ->where(Dictionary::schema_fields_TRANSLATE, '', '!=');
        if ($modules !== []) {
            $query->where(Dictionary::schema_fields_SOURCE_MODULE, $modules, 'IN');
        }

        $rows = $query->select()->fetchArray();
        $words = [];
        foreach ($rows as $row) {
            $word = $row[Dictionary::schema_fields_WORD] ?? '';
            $translate = $row[Dictionary::schema_fields_TRANSLATE] ?? '';
            if (is_string($word) && is_string($translate) && $word !== '' && $translate !== '') {
                $words[$word] = $translate;
            }
        }
        return $words;
    }
}
