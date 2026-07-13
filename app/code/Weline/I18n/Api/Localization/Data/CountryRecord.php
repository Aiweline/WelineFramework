<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Localization\Data;

/** Immutable country projection for cross-module reads. */
final readonly class CountryRecord
{
    public function __construct(
        public string $code,
        public string $displayLocale,
        public string $displayName,
        public string $flag,
        public bool $active,
        public bool $installed,
    ) {
    }
}
