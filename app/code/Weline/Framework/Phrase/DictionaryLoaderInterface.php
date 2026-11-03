<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

/**
 * Optional merged area-dictionary loader supplied by the i18n module.
 */
interface DictionaryLoaderInterface
{
    /**
     * Shared bag + the given area's bag only when $area is given; a null area
     * loads shared + all area bags of the locale. Never the legacy whole-locale bag.
     *
     * @return array<string, string>
     */
    public function loadMerged(string $lang, ?string $area = null, bool $sharedOnly = false): array;
}
