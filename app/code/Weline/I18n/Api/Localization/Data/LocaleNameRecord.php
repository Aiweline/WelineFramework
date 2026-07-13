<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Localization\Data;

/** Immutable locale display-name row for cross-module reads. */
final readonly class LocaleNameRecord
{
    public function __construct(
        public string $localeCode,
        public string $displayLocaleCode,
        public string $displayName,
    ) {
    }
}
