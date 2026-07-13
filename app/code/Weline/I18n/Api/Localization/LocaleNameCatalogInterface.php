<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Localization;

use Weline\I18n\Api\Localization\Data\LocaleNameRecord;

interface LocaleNameCatalogInterface
{
    /** @return list<LocaleNameRecord> */
    public function all(): array;

    public function firstByLocaleCode(string $localeCode): ?LocaleNameRecord;

    public function containsLocaleCode(string $localeCode): bool;
}
