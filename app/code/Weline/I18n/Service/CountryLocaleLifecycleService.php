<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\OS\FileHelper;
use Weline\I18n\Model\Countries;
use Weline\I18n\Model\Countries\Locale\Name as CountryLocaleName;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locale\Name as LocaleName;

class CountryLocaleLifecycleService
{
    private const DEFAULT_COUNTRY_CODE = 'CN';
    private const DEFAULT_LOCALE_CODE = 'zh_Hans_CN';

    private Countries $countries;
    private Locale $locales;
    private CountryLocaleName $countryLocaleNames;
    private LocaleName $localeNames;
    private I18n $i18n;

    public function __construct()
    {
        $this->countries = ObjectManager::getInstance(Countries::class);
        $this->locales = ObjectManager::getInstance(Locale::class);
        $this->countryLocaleNames = ObjectManager::getInstance(CountryLocaleName::class);
        $this->localeNames = ObjectManager::getInstance(LocaleName::class);
        $this->i18n = ObjectManager::getInstance(I18n::class);
    }

    public function installCountry(string $countryCode): array
    {
        $countryCode = $this->normalizeCountryCode($countryCode);
        $country = $this->ensureCountryExists($countryCode);
        $localeCodes = $this->ensureLocaleRecordsForCountry($countryCode);

        if ((int)$country->getData(Countries::schema_fields_IS_INSTALL) !== 1) {
            $country->setData(Countries::schema_fields_IS_INSTALL, 1)->save();
        }

        return $this->buildCountryPayload($countryCode, $localeCodes);
    }

    public function activateCountry(string $countryCode): array
    {
        $installed = $this->installCountry($countryCode);
        $countryCode = (string)$installed['country_code'];
        $preferredLocale = $this->findActiveInstalledLocaleCode($countryCode);

        if (!$preferredLocale) {
            $localeCodes = $this->getLocaleCodesByCountry($countryCode);
            $preferredLocale = $this->choosePreferredLocaleCode(
                $countryCode,
                $localeCodes,
                array_filter([(string)($installed['preferred_locale'] ?? ''), Cookie::getLangLocal()])
            );

            if (!$preferredLocale) {
                throw new \RuntimeException((string)__('国家 %{1} 没有可启用的地区', [$countryCode]));
            }

            $this->activateLocale($preferredLocale);
        } else {
            $country = $this->ensureCountryExists($countryCode);
            $country->setData(Countries::schema_fields_IS_INSTALL, 1)
                ->setData(Countries::schema_fields_IS_ACTIVE, 1)
                ->save();
        }

        $summary = $this->syncCountryState($countryCode);
        $summary['preferred_locale'] = $preferredLocale;

        return $summary;
    }

    public function deactivateCountry(string $countryCode): array
    {
        $countryCode = $this->normalizeCountryCode($countryCode);
        if ($this->isProtectedCountry($countryCode)) {
            throw new \RuntimeException((string)__('不允许停用中国（CN），这是系统默认国家'));
        }

        $country = $this->getCountryRecord($countryCode);
        if (!$country->getId()) {
            throw new \RuntimeException((string)__('国家不存在！国家代码：%{1}', [$countryCode]));
        }

        $this->makeLocaleModel()->reset()
            ->where(Locale::schema_fields_COUNTRY_CODE, $countryCode)
            ->update([Locale::schema_fields_IS_ACTIVE => 0])
            ->fetch();

        $country->setData(Countries::schema_fields_IS_ACTIVE, 0)->save();

        return $this->syncCountryState($countryCode);
    }

    public function uninstallCountry(string $countryCode): array
    {
        $countryCode = $this->normalizeCountryCode($countryCode);
        if ($this->isProtectedCountry($countryCode)) {
            throw new \RuntimeException((string)__('不允许卸载中国（CN），这是系统默认国家'));
        }

        $country = $this->getCountryRecord($countryCode);
        if (!$country->getId()) {
            throw new \RuntimeException((string)__('国家不存在！国家代码：%{1}', [$countryCode]));
        }

        $localeCodes = $this->getLocaleCodesByCountry($countryCode);

        $this->makeLocaleModel()->reset()
            ->where(Locale::schema_fields_COUNTRY_CODE, $countryCode)
            ->update([
                Locale::schema_fields_IS_ACTIVE => 0,
                Locale::schema_fields_IS_INSTALL => 0,
            ])
            ->fetch();

        $country->setData(Countries::schema_fields_IS_ACTIVE, 0)
            ->setData(Countries::schema_fields_IS_INSTALL, 0)
            ->save();

        foreach ($localeCodes as $localeCode) {
            $this->clearLanguagePacksForLocale($localeCode);
        }
        $this->clearTranslationCaches();

        return $this->buildCountryPayload($countryCode, $localeCodes);
    }

    public function installLocale(string $localeCode): array
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        $locale = $this->ensureLocaleRecordExists($localeCode);
        $countryCode = (string)$locale->getData(Locale::schema_fields_COUNTRY_CODE);

        if ((int)$locale->getData(Locale::schema_fields_IS_INSTALL) !== 1) {
            $locale->setData(Locale::schema_fields_IS_INSTALL, 1)->save();
        }

        $country = $this->ensureCountryExists($countryCode);
        if ((int)$country->getData(Countries::schema_fields_IS_INSTALL) !== 1) {
            $country->setData(Countries::schema_fields_IS_INSTALL, 1)->save();
        }

        $countrySummary = $this->syncCountryState($countryCode);

        return $this->buildLocalePayload($localeCode, $countrySummary);
    }

    public function activateLocale(string $localeCode): array
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        $locale = $this->ensureLocaleRecordExists($localeCode);
        $countryCode = (string)$locale->getData(Locale::schema_fields_COUNTRY_CODE);

        $locale->setData(Locale::schema_fields_IS_INSTALL, 1)
            ->setData(Locale::schema_fields_IS_ACTIVE, 1)
            ->save();

        $country = $this->ensureCountryExists($countryCode);
        $country->setData(Countries::schema_fields_IS_INSTALL, 1)
            ->setData(Countries::schema_fields_IS_ACTIVE, 1)
            ->save();

        $countrySummary = $this->syncCountryState($countryCode);

        return $this->buildLocalePayload($localeCode, $countrySummary);
    }

    public function deactivateLocale(string $localeCode): array
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        if ($this->isProtectedLocale($localeCode)) {
            throw new \RuntimeException((string)__('不允许停用 zh_Hans_CN 区域，这是系统默认区域'));
        }

        $locale = $this->getLocaleRecord($localeCode);
        if (!$locale->getId()) {
            throw new \RuntimeException((string)__('该区域不存在！区域代码：%{1}', [$localeCode]));
        }

        $countryCode = (string)$locale->getData(Locale::schema_fields_COUNTRY_CODE);
        $locale->setData(Locale::schema_fields_IS_ACTIVE, 0)->save();
        $countrySummary = $this->syncCountryState($countryCode);

        return $this->buildLocalePayload($localeCode, $countrySummary);
    }

    public function uninstallLocale(string $localeCode): array
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        if ($this->isProtectedLocale($localeCode)) {
            throw new \RuntimeException((string)__('不允许卸载 zh_Hans_CN 区域，这是系统默认区域'));
        }

        $locale = $this->getLocaleRecord($localeCode);
        if (!$locale->getId()) {
            throw new \RuntimeException((string)__('该区域不存在！区域代码：%{1}', [$localeCode]));
        }

        $countryCode = (string)$locale->getData(Locale::schema_fields_COUNTRY_CODE);
        $locale->setData(Locale::schema_fields_IS_ACTIVE, 0)
            ->setData(Locale::schema_fields_IS_INSTALL, 0)
            ->save();

        $this->clearLanguagePacksForLocale($localeCode);
        $this->clearTranslationCaches();
        $countrySummary = $this->syncCountryState($countryCode);

        return $this->buildLocalePayload($localeCode, $countrySummary);
    }

    public function syncCountryState(string $countryCode): array
    {
        $countryCode = $this->normalizeCountryCode($countryCode);
        $country = $this->ensureCountryExists($countryCode);

        if ($this->isProtectedCountry($countryCode)) {
            $defaultLocale = $this->ensureLocaleRecordExists(self::DEFAULT_LOCALE_CODE, self::DEFAULT_COUNTRY_CODE);
            if ((int)$defaultLocale->getData(Locale::schema_fields_IS_INSTALL) !== 1
                || (int)$defaultLocale->getData(Locale::schema_fields_IS_ACTIVE) !== 1) {
                $defaultLocale->setData(Locale::schema_fields_IS_INSTALL, 1)
                    ->setData(Locale::schema_fields_IS_ACTIVE, 1)
                    ->save();
            }

            if ((int)$country->getData(Countries::schema_fields_IS_INSTALL) !== 1
                || (int)$country->getData(Countries::schema_fields_IS_ACTIVE) !== 1) {
                $country->setData(Countries::schema_fields_IS_INSTALL, 1)
                    ->setData(Countries::schema_fields_IS_ACTIVE, 1)
                    ->save();
            }

            return $this->buildCountryPayload($countryCode);
        }

        $installedCount = $this->countLocalesByCountry($countryCode, true, null);
        $activeCount = $this->countLocalesByCountry($countryCode, true, true);
        $countryInstalled = (int)$country->getData(Countries::schema_fields_IS_INSTALL) === 1;

        $country->setData(Countries::schema_fields_IS_INSTALL, ($countryInstalled || $installedCount > 0) ? 1 : 0)
            ->setData(Countries::schema_fields_IS_ACTIVE, $activeCount > 0 ? 1 : 0)
            ->save();

        return $this->buildCountryPayload($countryCode);
    }

    public function getCountrySummary(string $countryCode): array
    {
        $countryCode = $this->normalizeCountryCode($countryCode);
        if (!$this->getCountryRecord($countryCode)->getId()) {
            return [
                'country_code' => $countryCode,
                'display_name' => $countryCode,
                'is_install' => 0,
                'is_active' => 0,
                'locale_count' => 0,
                'installed_locale_count' => 0,
                'active_locale_count' => 0,
                'preferred_locale' => null,
            ];
        }

        return $this->buildCountryPayload($countryCode);
    }

    public function getPreferredLocaleCode(string $countryCode): ?string
    {
        return $this->choosePreferredLocaleCode(
            $countryCode,
            $this->getLocaleCodesByCountry($countryCode),
            $this->getLocaleCodesByCountry($countryCode, true)
        );
    }

    public function getLocaleCodesByCountry(string $countryCode, bool $installedOnly = false): array
    {
        $countryCode = $this->normalizeCountryCode($countryCode);
        $localeModel = $this->makeLocaleModel();
        $localeModel->reset()->where(Locale::schema_fields_COUNTRY_CODE, $countryCode);
        if ($installedOnly) {
            $localeModel->where(Locale::schema_fields_IS_INSTALL, 1);
        }

        $items = $localeModel->select(Locale::schema_fields_CODE)->fetch()->getItems();
        $codes = [];
        foreach ($items as $item) {
            $code = (string)$item->getData(Locale::schema_fields_CODE);
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function buildCountryPayload(string $countryCode, ?array $localeCodes = null): array
    {
        $countryCode = $this->normalizeCountryCode($countryCode);
        $country = $this->getCountryRecord($countryCode);
        $localeCodes = $localeCodes ?? $this->getLocaleCodesByCountry($countryCode);

        return [
            'country_code' => $countryCode,
            'display_name' => $this->resolveCountryDisplayName($countryCode),
            'is_install' => (int)$country->getData(Countries::schema_fields_IS_INSTALL),
            'is_active' => (int)$country->getData(Countries::schema_fields_IS_ACTIVE),
            'locale_count' => count($localeCodes),
            'installed_locale_count' => $this->countLocalesByCountry($countryCode, true, null),
            'active_locale_count' => $this->countLocalesByCountry($countryCode, true, true),
            'preferred_locale' => $this->choosePreferredLocaleCode(
                $countryCode,
                $localeCodes,
                $this->getLocaleCodesByCountry($countryCode, true)
            ),
        ];
    }

    private function buildLocalePayload(string $localeCode, ?array $countrySummary = null): array
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        $locale = $this->getLocaleRecord($localeCode);
        $countryCode = (string)$locale->getData(Locale::schema_fields_COUNTRY_CODE);

        return [
            'locale_code' => $localeCode,
            'display_name' => $this->resolveLocaleDisplayName($localeCode, Cookie::getLangLocal()),
            'country_code' => $countryCode,
            'is_install' => (int)$locale->getData(Locale::schema_fields_IS_INSTALL),
            'is_active' => (int)$locale->getData(Locale::schema_fields_IS_ACTIVE),
            'country' => $countrySummary ?? ($countryCode ? $this->buildCountryPayload($countryCode) : []),
        ];
    }

    private function ensureCountryExists(string $countryCode): Countries
    {
        $countryCode = $this->normalizeCountryCode($countryCode);
        $country = $this->getCountryRecord($countryCode);
        $flag = (string)$this->i18n->getCountryFlag($countryCode);

        if (!$country->getId()) {
            if (!$this->countryCodeExists($countryCode)) {
                throw new \RuntimeException((string)__('国家不存在！国家代码：%{1}', [$countryCode]));
            }

            $country->setData(Countries::schema_fields_CODE, $countryCode)
                ->setData(Countries::schema_fields_FLAG, $flag)
                ->setData(Countries::schema_fields_IS_INSTALL, 0)
                ->setData(Countries::schema_fields_IS_ACTIVE, 0)
                ->save();
        } elseif (!$country->getData(Countries::schema_fields_FLAG) && $flag !== '') {
            $country->setData(Countries::schema_fields_FLAG, $flag)->save();
        }

        $this->ensureCountryDisplayNames($countryCode);

        return $this->getCountryRecord($countryCode);
    }

    private function ensureCountryDisplayNames(string $countryCode): void
    {
        foreach ($this->getFallbackDisplayLocaleCodes() as $displayLocaleCode) {
            $nameModel = $this->makeCountryLocaleNameModel();
            $existing = $nameModel->reset()
                ->where(CountryLocaleName::schema_fields_COUNTRY_CODE, $countryCode)
                ->where(CountryLocaleName::schema_fields_DISPLAY_LOCALE_CODE, $displayLocaleCode)
                ->find()
                ->fetch();

            if ($existing->getId()) {
                continue;
            }

            $displayName = $this->resolveCountryDisplayNameForLocale($countryCode, $displayLocaleCode);
            $nameModel->reset()->insert([
                CountryLocaleName::schema_fields_COUNTRY_CODE => $countryCode,
                CountryLocaleName::schema_fields_DISPLAY_LOCALE_CODE => $displayLocaleCode,
                CountryLocaleName::schema_fields_DISPLAY_NAME => $displayName,
            ], [
                CountryLocaleName::schema_fields_COUNTRY_CODE,
                CountryLocaleName::schema_fields_DISPLAY_LOCALE_CODE,
            ])->fetch();
        }
    }

    private function ensureLocaleRecordExists(string $localeCode, ?string $countryCode = null): Locale
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        $countryCode = $countryCode ? $this->normalizeCountryCode($countryCode) : $this->getCountryCodeFromLocale($localeCode);
        if (!$countryCode) {
            throw new \RuntimeException((string)__('无法处理区域代码 %{1}', [$localeCode]));
        }

        $this->ensureCountryExists($countryCode);
        $locale = $this->getLocaleRecord($localeCode);
        $localeCodes = Locale::extractLocaleCodes($localeCode);
        $flagData = $this->i18n->getCountryFlagWithLocal($localeCode, 42, 30);
        $flag = (string)($flagData['flag'] ?? $this->i18n->getCountryFlag($countryCode, 42, 30));

        if (!$locale->getId()) {
            $locale->setData(Locale::schema_fields_CODE, $localeCode)
                ->setData(Locale::schema_fields_COUNTRY_CODE, $countryCode)
                ->setData(Locale::schema_fields_SHORT_CODE, $localeCodes['short_code'])
                ->setData(Locale::schema_fields_ISO2, $localeCodes['iso2'])
                ->setData(Locale::schema_fields_ISO3, $localeCodes['iso3'])
                ->setData(Locale::schema_fields_FLAG, $flag)
                ->setData(Locale::schema_fields_IS_INSTALL, 0)
                ->setData(Locale::schema_fields_IS_ACTIVE, 0)
                ->save();
        } else {
            $changed = false;
            if ((string)$locale->getData(Locale::schema_fields_COUNTRY_CODE) !== $countryCode) {
                $locale->setData(Locale::schema_fields_COUNTRY_CODE, $countryCode);
                $changed = true;
            }
            foreach ([
                Locale::schema_fields_SHORT_CODE => $localeCodes['short_code'],
                Locale::schema_fields_ISO2 => $localeCodes['iso2'],
                Locale::schema_fields_ISO3 => $localeCodes['iso3'],
                Locale::schema_fields_FLAG => $flag,
            ] as $field => $value) {
                if (!$locale->getData($field) && $value !== '') {
                    $locale->setData($field, $value);
                    $changed = true;
                }
            }
            if ($changed) {
                $locale->save();
            }
        }

        $this->ensureLocaleDisplayNames([$localeCode]);

        return $this->getLocaleRecord($localeCode);
    }

    private function ensureLocaleRecordsForCountry(string $countryCode): array
    {
        $countryCode = $this->normalizeCountryCode($countryCode);
        $country = $this->i18n->getCountry($countryCode);
        $localeCodes = array_values(array_unique((array)($country['locales'] ?? [])));
        if (empty($localeCodes) && $countryCode === self::DEFAULT_COUNTRY_CODE) {
            $localeCodes = [self::DEFAULT_LOCALE_CODE];
        }

        foreach ($localeCodes as $localeCode) {
            $this->ensureLocaleRecordExists($localeCode, $countryCode);
        }

        return $localeCodes;
    }

    private function ensureLocaleDisplayNames(array $localeCodes): void
    {
        foreach ($localeCodes as $localeCode) {
            foreach ($this->getFallbackDisplayLocaleCodes() as $displayLocaleCode) {
                $nameModel = $this->makeLocaleNameModel();
                $existing = $nameModel->reset()
                    ->where(LocaleName::schema_fields_LOCALE_CODE, $localeCode)
                    ->where(LocaleName::schema_fields_DISPLAY_LOCALE_CODE, $displayLocaleCode)
                    ->find()
                    ->fetch();

                if ($existing->getId()) {
                    continue;
                }

                $nameModel->reset()->insert([
                    LocaleName::schema_fields_LOCALE_CODE => $localeCode,
                    LocaleName::schema_fields_DISPLAY_LOCALE_CODE => $displayLocaleCode,
                    LocaleName::schema_fields_DISPLAY_NAME => $this->resolveLocaleDisplayName($localeCode, $displayLocaleCode),
                ], [
                    LocaleName::schema_fields_LOCALE_CODE,
                    LocaleName::schema_fields_DISPLAY_LOCALE_CODE,
                ])->fetch();
            }
        }
    }

    private function resolveCountryDisplayName(string $countryCode): string
    {
        foreach ($this->getFallbackDisplayLocaleCodes() as $displayLocaleCode) {
            $nameModel = $this->makeCountryLocaleNameModel();
            $existing = $nameModel->reset()
                ->where(CountryLocaleName::schema_fields_COUNTRY_CODE, $countryCode)
                ->where(CountryLocaleName::schema_fields_DISPLAY_LOCALE_CODE, $displayLocaleCode)
                ->find()
                ->fetch();

            if ($existing->getId()) {
                return (string)$existing->getData(CountryLocaleName::schema_fields_DISPLAY_NAME);
            }
        }

        return $this->resolveCountryDisplayNameForLocale($countryCode, Cookie::getLangLocal());
    }

    private function resolveCountryDisplayNameForLocale(string $countryCode, string $displayLocaleCode): string
    {
        try {
            $countries = $this->i18n->getCountries($displayLocaleCode);
            if (isset($countries[$countryCode]) && $countries[$countryCode] !== '') {
                return (string)$countries[$countryCode];
            }
        } catch (\Throwable) {
        }

        try {
            if ($this->countryCodeExists($countryCode)) {
                $countries = $this->i18n->getCountries($displayLocaleCode);
                $name = (string)($countries[$countryCode] ?? '');
                if ($name !== '') {
                    return $name;
                }
            }
        } catch (\Throwable) {
        }

        try {
            return (string)($this->i18n->getCountries('en')[$countryCode] ?? $countryCode);
        } catch (\Throwable) {
            return $countryCode;
        }
    }

    private function resolveLocaleDisplayName(string $localeCode, string $displayLocaleCode): string
    {
        try {
            $name = (string)$this->i18n->getLocaleName($localeCode, $displayLocaleCode);
            if ($name !== '' && $name !== $localeCode) {
                return $name;
            }
        } catch (\Throwable) {
        }

        try {
            if ($this->i18n->localeExists($localeCode)) {
                $name = (string)$this->i18n->getLocaleName($localeCode, $displayLocaleCode);
                if ($name !== '') {
                    return $name;
                }
            }
        } catch (\Throwable) {
        }

        return $localeCode;
    }

    private function countLocalesByCountry(string $countryCode, ?bool $installed = null, ?bool $active = null): int
    {
        $localeModel = $this->makeLocaleModel()->reset()->where(Locale::schema_fields_COUNTRY_CODE, $countryCode);
        if ($installed !== null) {
            $localeModel->where(Locale::schema_fields_IS_INSTALL, $installed ? 1 : 0);
        }
        if ($active !== null) {
            $localeModel->where(Locale::schema_fields_IS_ACTIVE, $active ? 1 : 0);
        }

        return (int)$localeModel->count();
    }

    private function clearLanguagePacksForLocale(string $localeCode): void
    {
        $localeCode = $this->normalizeLocaleCode($localeCode);
        $directories = glob(Env::path_LANGUAGE_PACK . '*' . DIRECTORY_SEPARATOR . $localeCode, GLOB_ONLYDIR) ?: [];
        foreach ($directories as $directory) {
            FileHelper::removeDirectory($directory, true);
        }
    }

    private function clearTranslationCaches(): void
    {
        try {
            w_cache('i18n')->clear();
        } catch (\Throwable) {
        }

        try {
            w_cache('phrase')->clear();
        } catch (\Throwable) {
        }

        \Weline\Framework\Phrase\Parser::clearWorkerCaches();
        \Weline\I18n\Parser::clearWorkerCaches();

        $dispatchClass = '\\Weline\\Server\\Service\\Control\\BroadcastControlDispatchService';
        if (class_exists($dispatchClass)) {
            try {
                ObjectManager::getInstance($dispatchClass)->cacheClear();
            } catch (\Throwable) {
            }
        }
    }

    private function findActiveInstalledLocaleCode(string $countryCode): ?string
    {
        $items = $this->makeLocaleModel()->reset()
            ->where(Locale::schema_fields_COUNTRY_CODE, $this->normalizeCountryCode($countryCode))
            ->where(Locale::schema_fields_IS_INSTALL, 1)
            ->where(Locale::schema_fields_IS_ACTIVE, 1)
            ->select(Locale::schema_fields_CODE)
            ->fetch()
            ->getItems();

        foreach ($items as $item) {
            $code = (string)$item->getData(Locale::schema_fields_CODE);
            if ($code !== '') {
                return $code;
            }
        }

        return null;
    }

    private function choosePreferredLocaleCode(string $countryCode, array $localeCodes, array $preferredLocaleCodes = []): ?string
    {
        $localeCodes = array_values(array_unique(array_filter(array_map([$this, 'normalizeLocaleCode'], $localeCodes))));
        if (empty($localeCodes)) {
            return null;
        }

        $currentLocale = $this->normalizeLocaleCode(Cookie::getLangLocal());
        $preferredLocaleCodes = array_values(array_unique(array_filter(array_map([$this, 'normalizeLocaleCode'], array_merge([$currentLocale], $preferredLocaleCodes)))));
        foreach ($preferredLocaleCodes as $preferredLocaleCode) {
            if (in_array($preferredLocaleCode, $localeCodes, true)) {
                return $preferredLocaleCode;
            }
        }

        $currentLanguage = strtolower((string)explode('_', $currentLocale)[0]);
        if ($currentLanguage !== '') {
            foreach ($localeCodes as $localeCode) {
                $language = strtolower((string)explode('_', $localeCode)[0]);
                if ($language === $currentLanguage) {
                    return $localeCode;
                }
            }
        }

        if ($this->normalizeCountryCode($countryCode) === self::DEFAULT_COUNTRY_CODE
            && in_array(self::DEFAULT_LOCALE_CODE, $localeCodes, true)) {
            return self::DEFAULT_LOCALE_CODE;
        }

        return $localeCodes[0];
    }

    private function getCountryCodeFromLocale(string $localeCode): ?string
    {
        $parts = explode('_', $localeCode);
        $countryCode = strtoupper((string)end($parts));
        if (strlen($countryCode) === 2 && $this->countryCodeExists($countryCode)) {
            return $countryCode;
        }

        return null;
    }

    private function countryCodeExists(string $countryCode): bool
    {
        try {
            return $this->i18n->getCountry($countryCode) !== [];
        } catch (\Throwable) {
            return preg_match('/^[A-Z]{2}$/', strtoupper($countryCode)) === 1;
        }
    }

    private function getCountryRecord(string $countryCode): Countries
    {
        return $this->makeCountryModel()->reset()
            ->where(Countries::schema_fields_CODE, $this->normalizeCountryCode($countryCode))
            ->find()
            ->fetch();
    }

    private function getLocaleRecord(string $localeCode): Locale
    {
        return $this->makeLocaleModel()->reset()
            ->where(Locale::schema_fields_CODE, $this->normalizeLocaleCode($localeCode))
            ->find()
            ->fetch();
    }

    private function normalizeCountryCode(string $countryCode): string
    {
        return strtoupper(trim($countryCode));
    }

    private function normalizeLocaleCode(string $localeCode): string
    {
        return trim($localeCode);
    }

    private function getFallbackDisplayLocaleCodes(): array
    {
        return array_values(array_unique(array_filter([
            Cookie::getLangLocal(),
            'en_US',
            'en',
            self::DEFAULT_LOCALE_CODE,
        ])));
    }

    private function isProtectedCountry(string $countryCode): bool
    {
        return $this->normalizeCountryCode($countryCode) === self::DEFAULT_COUNTRY_CODE;
    }

    private function isProtectedLocale(string $localeCode): bool
    {
        return $this->normalizeLocaleCode($localeCode) === self::DEFAULT_LOCALE_CODE;
    }

    private function makeCountryModel(): Countries
    {
        return clone $this->countries;
    }

    private function makeLocaleModel(): Locale
    {
        return clone $this->locales;
    }

    private function makeCountryLocaleNameModel(): CountryLocaleName
    {
        return clone $this->countryLocaleNames;
    }

    private function makeLocaleNameModel(): LocaleName
    {
        return clone $this->localeNames;
    }
}
