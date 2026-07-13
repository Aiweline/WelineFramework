<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Localization;

use Weline\I18n\Api\Localization\Data\CountryRecord;

interface CountryRepositoryInterface
{
    /** @return list<CountryRecord> */
    public function installed(string $displayLocale): array;

    /** @return list<CountryRecord> */
    public function installedActive(string $displayLocale): array;
}
