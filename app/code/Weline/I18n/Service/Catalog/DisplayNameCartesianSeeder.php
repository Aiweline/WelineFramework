<?php

declare(strict_types=1);

namespace Weline\I18n\Service\Catalog;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Countries;
use Weline\I18n\Model\Countries\Locale\Name as CountryLocaleName;
use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locale\Name as LocaleName;
use Weline\I18n\Model\Locals;
use Weline\I18n\Service\ActiveLocaleCodeProvider;

/**
 * Lazy Cartesian display-name seeder: slices names from the on-disk pack into DB
 * when a locale is installed/activated. Does not store flags on Locals rows.
 */
final class DisplayNameCartesianSeeder
{
    public const BASELINE_DISPLAY_LOCALES = ['zh_Hans_CN', 'en_US'];

    public function __construct(
        private readonly LocaleCatalogPackReader $pack,
        private readonly ActiveLocaleCodeProvider $activeLocales,
    ) {
    }

    /** @return list<string> */
    public function displayAxis(): array
    {
        $codes = $this->activeLocales->getInstalledActiveCodes();
        foreach (self::BASELINE_DISPLAY_LOCALES as $baseline) {
            $codes[] = $baseline;
        }
        $out = [];
        foreach ($codes as $code) {
            $code = trim((string)$code);
            if ($code !== '') {
                $out[$code] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Module Install: countries + locale inventory + baseline display names only.
     */
    public function seedModuleInstallInventory(bool $seedFlags = true): void
    {
        $this->pack->assertPackReady();
        $this->seedCountryEntities($seedFlags);
        $this->seedLocaleInventory();
        foreach (self::BASELINE_DISPLAY_LOCALES as $display) {
            $this->seedAllLocaleNamesForDisplay($display);
            $this->seedAllCountryNamesForDisplay($display);
        }
        foreach (self::BASELINE_DISPLAY_LOCALES as $localeCode) {
            $this->ensureLocaleLocalSelfRow($localeCode, true, true);
        }
    }

    /**
     * Upgrade / gap-fill: insert any pack countries/locales missing from DB.
     * Does not wipe or reset install/active flags on existing rows.
     */
    public function syncInventoryGaps(bool $seedFlags = false): void
    {
        $this->pack->assertPackReady();
        $this->seedCountryEntities($seedFlags);
        $this->seedLocaleInventory();
    }

    /**
     * Backend install/activate locale L: slice Cartesian names from pack into DB.
     * Batches inserts; optional $onProgress receives percent/current/total/message each batch.
     *
     * @param callable(array{current:int,total:int,percent:int,message:string,phase:string}):void|null $onProgress
     */
    public function expandForInstalledLocale(string $localeCode, ?callable $onProgress = null): void
    {
        $localeCode = trim($localeCode);
        if ($localeCode === '') {
            return;
        }
        $this->pack->assertPackReady();
        $axis = $this->displayAxis();
        if (!in_array($localeCode, $axis, true)) {
            $axis[] = $localeCode;
        }

        $wantedLocales = [];
        foreach ($axis as $code) {
            $wantedLocales[$code] = true;
        }
        $wantedLocales[$localeCode] = true;

        $axisCount = count($axis);
        // Two Cartesian passes + country-name finish.
        $totalSteps = max(1, ($axisCount * 2) + 1);
        $step = 0;
        $report = static function (string $phase, string $message) use (&$step, $totalSteps, $onProgress): void {
            $step++;
            if ($onProgress === null) {
                return;
            }
            $onProgress([
                'phase' => $phase,
                'current' => $step,
                'total' => $totalSteps,
                'percent' => (int)min(100, round(100 * $step / $totalSteps)),
                'message' => $message,
            ]);
        };

        $localeNameRows = [];
        $flushLocaleNames = function () use (&$localeNameRows): void {
            $this->flushLocaleNameRows($localeNameRows);
        };

        // L as entity × each display in axis
        $namesAcross = $this->pack->localeNamesForCodeAcrossDisplays($localeCode, $axis);
        foreach ($axis as $display) {
            $name = $namesAcross[$display] ?? $this->pack->localeName($localeCode, $display);
            if ($name === '') {
                $name = $localeCode;
            }
            $localeNameRows[] = [
                LocaleName::schema_fields_LOCALE_CODE => $localeCode,
                LocaleName::schema_fields_DISPLAY_LOCALE_CODE => $display,
                LocaleName::schema_fields_DISPLAY_NAME => $name,
            ];
            // Locals keeps per-row upsert so install/active flags are never wiped by name-only batch.
            $this->upsertLocalsName($localeCode, $display, $name);
            if (count($localeNameRows) >= 50) {
                $flushLocaleNames();
            }
            $report('entity_as_display', (string)__('写入展示名 %{1} × %{2}', [$localeCode, $display]));
        }
        $flushLocaleNames();

        // each entity in axis as display = L
        $namesInL = $this->pack->localeNamesForDisplay($localeCode, $wantedLocales);
        foreach ($axis as $entity) {
            $name = $namesInL[$entity] ?? $this->pack->localeName($entity, $localeCode);
            if ($name === '') {
                $name = $entity;
            }
            $localeNameRows[] = [
                LocaleName::schema_fields_LOCALE_CODE => $entity,
                LocaleName::schema_fields_DISPLAY_LOCALE_CODE => $localeCode,
                LocaleName::schema_fields_DISPLAY_NAME => $name,
            ];
            $this->upsertLocalsName($entity, $localeCode, $name);
            if (count($localeNameRows) >= 50) {
                $flushLocaleNames();
            }
            $report('display_as_entity', (string)__('写入展示名 %{1} × %{2}', [$entity, $localeCode]));
        }
        $flushLocaleNames();

        $meta = $this->pack->localeMetaMap()[$localeCode] ?? null;
        $countryCode = strtoupper((string)($meta['country_code'] ?? ''));
        if ($countryCode !== '') {
            $countryRows = [];
            $cName = $this->pack->countryName($countryCode, $localeCode);
            if ($cName === '') {
                $cName = $countryCode;
            }
            $countryRows[] = [
                CountryLocaleName::schema_fields_COUNTRY_CODE => $countryCode,
                CountryLocaleName::schema_fields_DISPLAY_LOCALE_CODE => $localeCode,
                CountryLocaleName::schema_fields_DISPLAY_NAME => $cName,
            ];
            foreach (self::BASELINE_DISPLAY_LOCALES as $display) {
                $bn = $this->pack->countryName($countryCode, $display);
                if ($bn !== '') {
                    $countryRows[] = [
                        CountryLocaleName::schema_fields_COUNTRY_CODE => $countryCode,
                        CountryLocaleName::schema_fields_DISPLAY_LOCALE_CODE => $display,
                        CountryLocaleName::schema_fields_DISPLAY_NAME => $bn,
                    ];
                }
            }
            $this->flushCountryNameRows($countryRows);
        }
        $report('country', (string)__('国家展示名已写入'));
    }

    /** @param list<array<string, mixed>> $rows */
    private function flushLocaleNameRows(array &$rows): void
    {
        if ($rows === []) {
            return;
        }
        /** @var LocaleName $model */
        $model = (clone ObjectManager::getInstance(LocaleName::class))->clear();
        $model->insert($rows, [
            LocaleName::schema_fields_LOCALE_CODE,
            LocaleName::schema_fields_DISPLAY_LOCALE_CODE,
        ])->fetch();
        $rows = [];
    }

    /** @param list<array<string, mixed>> $rows */
    private function flushCountryNameRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        /** @var CountryLocaleName $model */
        $model = (clone ObjectManager::getInstance(CountryLocaleName::class))->clear();
        $model->insert($rows, [
            CountryLocaleName::schema_fields_COUNTRY_CODE,
            CountryLocaleName::schema_fields_DISPLAY_LOCALE_CODE,
        ])->fetch();
    }

    public function seedAllLocaleNamesForDisplay(string $displayLocale): void
    {
        foreach ($this->pack->localeNamesForDisplay($displayLocale, null) as $code => $name) {
            $this->upsertLocaleName($code, $displayLocale, $name);
            $this->upsertLocalsName($code, $displayLocale, $name);
        }
    }

    public function seedAllCountryNamesForDisplay(string $displayLocale): void
    {
        foreach ($this->pack->countryNamesForDisplay($displayLocale, null) as $code => $name) {
            $this->upsertCountryName($code, $displayLocale, $name);
        }
    }

    private function seedCountryEntities(bool $seedFlags): void
    {
        /** @var Countries $countries */
        // clone+clear: joinModel(class-string) bindQuery's OM singletons; clearQuery alone leaves _bind_query.
        $countries = (clone ObjectManager::getInstance(Countries::class))->clear();
        $have = [];
        try {
            foreach ($countries->select(Countries::schema_fields_CODE)->fetchArray() as $row) {
                $code = strtoupper(trim((string)($row[Countries::schema_fields_CODE] ?? '')));
                if ($code !== '') {
                    $have[$code] = true;
                }
            }
        } catch (\Throwable) {
            $have = [];
        }
        $i18n = ObjectManager::getInstance(\Weline\I18n\Model\I18n::class);
        $rows = [];
        foreach ($this->pack->countryCodes() as $code) {
            $code = strtoupper(trim((string)$code));
            if ($code === '' || isset($have[$code])) {
                continue;
            }
            $flag = '';
            if ($seedFlags) {
                try {
                    $flag = (string)$i18n->getCountryFlag($code);
                } catch (\Throwable) {
                    $flag = '';
                }
            }
            $rows[] = [
                Countries::schema_fields_CODE => $code,
                Countries::schema_fields_FLAG => $flag,
                Countries::schema_fields_IS_ACTIVE => 0,
                // Inventory row only; install/activate is lifecycle-owned.
                Countries::schema_fields_IS_INSTALL => 0,
            ];
            $have[$code] = true;
            if (count($rows) >= 100) {
                $countries->clear()->insert($rows, Countries::schema_fields_CODE)->fetch();
                $rows = [];
            }
        }
        if ($rows !== []) {
            $countries->clear()->insert($rows, Countries::schema_fields_CODE)->fetch();
        }
    }

    private function seedLocaleInventory(): void
    {
        /** @var Locale $localeModel */
        $localeModel = (clone ObjectManager::getInstance(Locale::class))->clear();
        $have = [];
        try {
            foreach ($localeModel->select(Locale::schema_fields_CODE)->fetchArray() as $row) {
                $code = trim((string)($row[Locale::schema_fields_CODE] ?? ''));
                if ($code !== '') {
                    $have[$code] = true;
                }
            }
        } catch (\Throwable) {
            $have = [];
        }
        $rows = [];
        foreach ($this->pack->localeMetaMap() as $code => $meta) {
            $code = trim((string)$code);
            $country = (string)($meta['country_code'] ?? '');
            if ($code === '' || $country === '' || isset($have[$code])) {
                continue;
            }
            $rows[] = [
                Locale::schema_fields_CODE => $code,
                Locale::schema_fields_COUNTRY_CODE => $country,
                Locale::schema_fields_SHORT_CODE => (string)($meta['short_code'] ?? ''),
                Locale::schema_fields_ISO2 => (string)($meta['iso2'] ?? ''),
                Locale::schema_fields_ISO3 => (string)($meta['iso3'] ?? ''),
                Locale::schema_fields_IS_ACTIVE => 0,
                Locale::schema_fields_IS_INSTALL => 0,
                Locale::schema_fields_FLAG => '',
            ];
            $have[$code] = true;
            if (count($rows) >= 100) {
                $localeModel->clear()->insert($rows, Locale::schema_fields_CODE)->fetch();
                $rows = [];
            }
        }
        if ($rows !== []) {
            $localeModel->clear()->insert($rows, Locale::schema_fields_CODE)->fetch();
        }
    }

    private function ensureLocaleLocalSelfRow(string $localeCode, bool $installed, bool $active): void
    {
        $name = $this->pack->localeName($localeCode, $localeCode);
        if ($name === '') {
            $name = $localeCode;
        }
        $this->upsertLocalsName($localeCode, $localeCode, $name, $installed, $active);
        $this->upsertLocaleName($localeCode, $localeCode, $name);
    }

    private function upsertLocaleName(string $localeCode, string $displayLocale, string $name): void
    {
        /** @var LocaleName $model */
        $model = (clone ObjectManager::getInstance(LocaleName::class))->clear();
        $model->insert([
            LocaleName::schema_fields_LOCALE_CODE => $localeCode,
            LocaleName::schema_fields_DISPLAY_LOCALE_CODE => $displayLocale,
            LocaleName::schema_fields_DISPLAY_NAME => $name,
        ], [
            LocaleName::schema_fields_LOCALE_CODE,
            LocaleName::schema_fields_DISPLAY_LOCALE_CODE,
        ])->fetch();
    }

    private function upsertCountryName(string $countryCode, string $displayLocale, string $name): void
    {
        /** @var CountryLocaleName $model */
        $model = (clone ObjectManager::getInstance(CountryLocaleName::class))->clear();
        $model->insert([
            CountryLocaleName::schema_fields_COUNTRY_CODE => $countryCode,
            CountryLocaleName::schema_fields_DISPLAY_LOCALE_CODE => $displayLocale,
            CountryLocaleName::schema_fields_DISPLAY_NAME => $name,
        ], [
            CountryLocaleName::schema_fields_COUNTRY_CODE,
            CountryLocaleName::schema_fields_DISPLAY_LOCALE_CODE,
        ])->fetch();
    }

    private function upsertLocalsName(
        string $code,
        string $targetCode,
        string $name,
        ?bool $installed = null,
        ?bool $active = null,
    ): void {
        /** @var Locals $locals */
        $locals = (clone ObjectManager::getInstance(Locals::class))->clear();
        $existing = $locals
            ->where(Locals::schema_fields_CODE, $code)
            ->where(Locals::schema_fields_TARGET_CODE, $targetCode)
            ->find()
            ->fetch();
        $row = [
            Locals::schema_fields_CODE => $code,
            Locals::schema_fields_TARGET_CODE => $targetCode,
            Locals::schema_fields_NAME => $name,
            Locals::schema_fields_FLAG => '',
        ];
        if ($existing->getId()) {
            if ($installed !== null) {
                $row[Locals::schema_fields_IS_INSTALL] = $installed ? 1 : 0;
            }
            if ($active !== null) {
                $row[Locals::schema_fields_IS_ACTIVE] = ($installed && $active) ? 1 : 0;
            }
            $locals->clear()
                ->where(Locals::schema_fields_CODE, $code)
                ->where(Locals::schema_fields_TARGET_CODE, $targetCode)
                ->update($row)
                ->fetch();

            return;
        }
        $row[Locals::schema_fields_IS_INSTALL] = $installed === null ? 0 : ($installed ? 1 : 0);
        $row[Locals::schema_fields_IS_ACTIVE] = ($installed && $active) ? 1 : 0;
        $locals->clear()->insert([$row], [
            Locals::schema_fields_CODE,
            Locals::schema_fields_TARGET_CODE,
        ])->fetch();
    }
}
