<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Translation;

interface DictionaryRepositoryInterface
{
    public function getEntry(string $word, string $localeCode): ?DictionaryEntry;

    /**
     * @param list<string> $words
     * @return array<string, DictionaryEntry> Entries keyed by source word.
     */
    public function getEntries(array $words, string $localeCode): array;

    /** @return list<DictionaryEntry> */
    public function listByWordPrefix(string $prefix): array;

    public function upsert(string $word, string $localeCode, string $translation): bool;

    public function deleteEntry(string $word, string $localeCode): bool;
}
