<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Localization;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\BatchGlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\GlobalDictionaryModuleBagCache;
use Weline\Framework\Phrase\GlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\ModuleGlobalDictionaryProviderInterface;
use Weline\Framework\Runtime\Runtime;
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
            // Module-scoped rows; globals (NULL/empty source_module) merged below — IN never matches NULL.
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
        // Phrase module IN (...) never matches NULL source_module; flat merge once (not per-module).
        if ($modules !== []) {
            foreach ($this->globalWords($locale) as $word => $translate) {
                if (!isset($words[$word]) || $words[$word] === $word) {
                    $words[$word] = $translate;
                }
            }
        }

        return $words;
    }

    /** Reserved module key for NULL/empty source_module rows (Phrase always fetches this layer once). */
    public const NULL_SOURCE_MODULE_KEY = ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY;

    /**
     * Dictionary rows with NULL/empty source_module are site-global fills (e.g. remediate packs).
     *
     * @return array<string, string>
     */
    private function globalWords(string $locale): array
    {
        $locale = \trim($locale);
        if ($locale === '') {
            return [];
        }

        // Worker process bag only (never Shared Memory / RequestContext whole-locale bags).
        $process = GlobalDictionaryModuleBagCache::getNullSource($locale);
        if (\is_array($process)) {
            return $process;
        }

        // Persistent Workers: never materialize whole-locale NULL-source bags.
        // generated/language/{locale}.php is 8–11MiB; full PDO NULL scans are the same cliff.
        // Storefront misses use exactWords / template prefetchTemplateWords (com bake).
        if (Runtime::isPersistent()) {
            return [];
        }

        // Non-persistent (CLI / tip-warm / collect): deploy artifact then DB stream.
        $fromFile = $this->loadLocaleFileWords($locale);
        if ($fromFile !== null) {
            GlobalDictionaryModuleBagCache::putNullSource($locale, $fromFile);

            return $fromFile;
        }

        $pdoWords = [];
        try {
            $model = ObjectManager::getInstance(Dictionary::class)->reset();
            if (!\method_exists($model, 'getConnection') || !\method_exists($model, 'getTable')) {
                return [];
            }
            $connector = $model->getConnection()->getConnector();
            $table = $model->getTable();
            $localeSql = \str_replace("'", "''", $locale);
            // Stream via fetchIterator — full global fills exceed unbounded fetchArray threshold.
            $sql = 'SELECT word, translate FROM ' . $table
                . " WHERE locale_code = '{$localeSql}' AND translate <> ''"
                . ' AND (source_module IS NULL OR source_module = \'\')';
            foreach ($connector->query($sql)->fetchIterator() as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $word = $row['word'] ?? ($row[Dictionary::schema_fields_WORD] ?? '');
                $translate = $row['translate'] ?? ($row[Dictionary::schema_fields_TRANSLATE] ?? '');
                if (\is_string($word) && \is_string($translate) && $word !== '' && $translate !== '') {
                    $pdoWords[$word] = $translate;
                }
            }
        } catch (\Throwable) {
            // Fall through to empty — module-scoped path still works.
        }

        // Process bag only — never phrase Shared Memory (frame_too_large on 8–11MiB locales).
        GlobalDictionaryModuleBagCache::putNullSource($locale, $pdoWords);

        return $pdoWords;
    }

    /**
     * @return array<string, string>|null null when locale file missing/unreadable
     */
    private function loadLocaleFileWords(string $locale): ?array
    {
        try {
            $words = \Weline\I18n\Service\LocaleAreaDictionaryLoader::loadMerged($locale);
        } catch (\Throwable) {
            return null;
        }

        return $words === [] ? null : $words;
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
        $wantGlobal = \in_array(self::NULL_SOURCE_MODULE_KEY, $modules, true);
        $scoped = \array_values(\array_filter(
            $modules,
            static fn(string $module): bool => $module !== self::NULL_SOURCE_MODULE_KEY,
        ));
        $maps = \array_fill_keys($modules, []);
        if ($scoped !== []) {
            $query = (clone ObjectManager::getInstance(Dictionary::class))->reset()
                ->where(Dictionary::schema_fields_LOCALE_CODE, $locale)
                ->where(Dictionary::schema_fields_TRANSLATE, '', '!=')
                ->where(Dictionary::schema_fields_SOURCE_MODULE, $scoped, 'IN');
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
        }
        if ($wantGlobal) {
            $maps[self::NULL_SOURCE_MODULE_KEY] = $this->globalWords($locale);
        }
        return $maps;
    }
}
