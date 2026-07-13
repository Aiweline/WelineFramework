<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Localization;

use Weline\I18n\Api\Localization\Data\LocaleRecord;

interface LocaleRepositoryInterface
{
    /** @return list<LocaleRecord> */
    public function installedActive(string $displayLocale): array;

    /** Resolve aliases such as zh-CN to the runtime's canonical locale code. */
    public function resolveCode(string $localeCode, string $fallback = 'zh_Hans_CN'): string;
}
