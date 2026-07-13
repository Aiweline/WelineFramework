<?php

declare(strict_types=1);

namespace Weline\I18n\Service\Repository;

use Weline\I18n\Api\Localization\Data\LocaleRecord;
use Weline\I18n\Api\Localization\LocaleRepositoryInterface;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locals;

final class LocaleRepository implements LocaleRepositoryInterface
{
    public function __construct(
        private readonly Locals $locals,
        private readonly I18n $i18n,
    ) {
    }

    public function installedActive(string $displayLocale): array
    {
        $rows = $this->locals->reset()
            ->where(Locals::schema_fields_IS_INSTALL, 1)
            ->where(Locals::schema_fields_IS_ACTIVE, 1)
            ->order(Locals::schema_fields_CODE, 'ASC')
            ->select()
            ->fetchArray();
        $names = $this->i18n->getLocals($displayLocale);
        $records = [];
        $seen = [];
        foreach ($rows as $locale) {
            if (!is_array($locale)) {
                continue;
            }
            $code = trim((string)($locale[Locals::schema_fields_CODE] ?? ''));
            $key = strtolower($code);
            if ($code === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $records[] = new LocaleRecord(
                code: $code,
                displayLocale: $displayLocale,
                displayName: (string)($names[$code] ?? $locale[Locals::schema_fields_NAME] ?? $code),
                flag: (string)($locale[Locals::schema_fields_FLAG] ?? ''),
                active: (bool)($locale[Locals::schema_fields_IS_ACTIVE] ?? false),
                installed: (bool)($locale[Locals::schema_fields_IS_INSTALL] ?? false),
            );
        }

        return $records;
    }

    public function resolveCode(string $localeCode, string $fallback = 'zh_Hans_CN'): string
    {
        $resolved = trim($this->i18n->getLocalByCode($localeCode));
        if ($resolved !== '') {
            return $resolved;
        }

        $resolved = trim($this->i18n->getLocalByCode($fallback));
        return $resolved !== '' ? $resolved : $fallback;
    }
}
