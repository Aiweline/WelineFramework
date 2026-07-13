<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Translation;

/**
 * Immutable dictionary data transferred across module boundaries.
 */
final readonly class DictionaryEntry
{
    public function __construct(
        public string $word,
        public string $localeCode,
        public string $translation,
    ) {
    }
}
