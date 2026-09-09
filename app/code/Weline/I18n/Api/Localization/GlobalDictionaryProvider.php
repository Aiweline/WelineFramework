<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Localization;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\BatchGlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\GlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\ModuleGlobalDictionaryProviderInterface;
use Weline\I18n\Model\Locale\Dictionary;

final class GlobalDictionaryProvider implements GlobalDictionaryProviderInterface, BatchGlobalDictionaryProviderInterface, ModuleGlobalDictionaryProviderInterface
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

    public function exactWords(string $locale, array $words): array
    {
        $normalized = \array_values(\array_unique(\array_filter(\array_map('trim', $words),
            static fn(string $word): bool => $word !== '',
        )));
        if ($normalized === []) {
            return [];
        }

        $entries = ObjectManager::getInstance(Dictionary::class)->getEntries($normalized, $locale);
        $translations = [];
        foreach ($words as $word) {
            $key = \trim($word);
            $translation = \trim((string)($entries[$key]?->translation ?? ''));
            if ($translation !== '' && $translation !== $key) {
                $translations[$word] = $translation;
            }
        }
        return $translations;
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

        $words = [];
        foreach ($query->select()->fetchIterator() as $row) {
            $word = $row[Dictionary::schema_fields_WORD] ?? '';
            $translate = $row[Dictionary::schema_fields_TRANSLATE] ?? '';
            if (is_string($word) && is_string($translate) && $word !== '' && $translate !== '') {
                $words[$word] = $translate;
            }
        }
        return $words;
    }

    public function wordsByModule(string $locale, array $modules): array
    {
        $modules = \array_values(\array_unique(\array_filter(
            \array_map(static fn(mixed $module): string => \trim((string)$module), $modules),
            static fn(string $module): bool => $module !== '',
        )));
        if ($modules === []) {
            return [];
        }
        $maps = \array_fill_keys($modules, []);
        $query = (clone ObjectManager::getInstance(Dictionary::class))->reset()
            ->where(Dictionary::schema_fields_LOCALE_CODE, $locale)
            ->where(Dictionary::schema_fields_TRANSLATE, '', '!=')
            ->where(Dictionary::schema_fields_SOURCE_MODULE, $modules, 'IN');
        foreach ($query->select()->fetchIterator() as $row) {
            $module = $row[Dictionary::schema_fields_SOURCE_MODULE] ?? '';
            $word = $row[Dictionary::schema_fields_WORD] ?? '';
            $translation = $row[Dictionary::schema_fields_TRANSLATE] ?? '';
            if (isset($maps[$module]) && \is_string($word) && \is_string($translation)
                && $word !== '' && $translation !== ''
            ) {
                $maps[$module][$word] = $translation;
            }
        }
        return $maps;
    }
}
