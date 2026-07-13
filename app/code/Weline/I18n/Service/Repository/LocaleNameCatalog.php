<?php

declare(strict_types=1);

namespace Weline\I18n\Service\Repository;

use Weline\I18n\Api\Localization\Data\LocaleNameRecord;
use Weline\I18n\Api\Localization\LocaleNameCatalogInterface;
use Weline\I18n\Model\Locale\Name;

final class LocaleNameCatalog implements LocaleNameCatalogInterface
{
    public function __construct(private readonly Name $localeName)
    {
    }

    public function all(): array
    {
        $rows = $this->localeName->reset()->select()->fetchArray();
        $records = [];
        foreach ((array)$rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $records[] = $this->record($row);
        }

        return $records;
    }

    public function firstByLocaleCode(string $localeCode): ?LocaleNameRecord
    {
        $row = $this->localeName->reset()
            ->where(Name::schema_fields_LOCALE_CODE, $localeCode)
            ->find()
            ->fetchArray();

        return \is_array($row) && $row !== [] ? $this->record($row) : null;
    }

    public function containsLocaleCode(string $localeCode): bool
    {
        return $this->firstByLocaleCode($localeCode) instanceof LocaleNameRecord;
    }

    /** @param array<string, mixed> $row */
    private function record(array $row): LocaleNameRecord
    {
        return new LocaleNameRecord(
            localeCode: (string)($row[Name::schema_fields_LOCALE_CODE] ?? ''),
            displayLocaleCode: (string)($row[Name::schema_fields_DISPLAY_LOCALE_CODE] ?? ''),
            displayName: (string)($row[Name::schema_fields_DISPLAY_NAME] ?? ''),
        );
    }
}
