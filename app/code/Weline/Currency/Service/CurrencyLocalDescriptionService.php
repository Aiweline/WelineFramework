<?php

declare(strict_types=1);

namespace Weline\Currency\Service;

use Weline\Currency\Model\Currency;
use Weline\Currency\Model\Currency\LocalDescription;
use Weline\I18n\Model\I18n;
use Weline\I18n\Service\ActiveLocaleCodeProvider;

class CurrencyLocalDescriptionService
{
    private const DEFAULT_LOCALE_CODES = ['zh_Hans_CN', 'en_US'];

    public function __construct(
        private readonly LocalDescription $localDescription,
        private readonly ActiveLocaleCodeProvider $activeLocaleCodeProvider,
        private readonly I18n $i18n,
    ) {
    }

    /**
     * @return array<int, array{code: string, name: string}>
     */
    public function getAvailableLocales(string $displayLocale = 'zh_Hans_CN'): array
    {
        try {
            $codes = $this->activeLocaleCodeProvider->getInstalledActiveCodes();
        } catch (\Throwable) {
            $codes = [];
        }

        if ($codes === []) {
            $codes = self::DEFAULT_LOCALE_CODES;
        }

        try {
            $names = $this->i18n->getLocals($displayLocale);
        } catch (\Throwable) {
            $names = [];
        }

        $locales = [];
        $seen = [];
        foreach ($codes as $code) {
            $code = trim((string)$code);
            if (!$this->isValidLocaleCode($code)) {
                continue;
            }

            $key = strtolower($code);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $locales[] = [
                'code' => $code,
                'name' => (string)($names[$code] ?? $code),
            ];
        }

        return $locales;
    }

    /**
     * @return array<string, string>
     */
    public function getLocalNames(int $currencyId): array
    {
        if ($currencyId <= 0) {
            return [];
        }

        $rows = $this->localDescription->clear()
            ->where(LocalDescription::schema_fields_ID, $currencyId)
            ->select()
            ->fetchArray();

        $names = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $localeCode = trim((string)($row[LocalDescription::schema_fields_local_code] ?? ''));
            if (!$this->isValidLocaleCode($localeCode)) {
                continue;
            }

            $names[$localeCode] = (string)($row[LocalDescription::schema_fields_name] ?? '');
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $localNames
     */
    public function saveLocalNames(Currency $currency, array $localNames): void
    {
        $currencyId = (int)$currency->getId();
        if ($currencyId <= 0) {
            return;
        }

        foreach ($this->normalizeLocalNames($localNames) as $localeCode => $name) {
            if ($name === '') {
                $this->deleteLocalName($currencyId, $localeCode);
                continue;
            }

            $this->localDescription->clear()
                ->setData([
                    LocalDescription::schema_fields_ID => $currencyId,
                    LocalDescription::schema_fields_local_code => $localeCode,
                    LocalDescription::schema_fields_name => $name,
                ])
                ->forceCheck(true, [
                    LocalDescription::schema_fields_ID,
                    LocalDescription::schema_fields_local_code,
                ])
                ->save();
        }

        w_cache('currency')->clear();
    }

    public function deleteLocalNames(int $currencyId): void
    {
        if ($currencyId <= 0) {
            return;
        }

        $this->localDescription->clear()
            ->where(LocalDescription::schema_fields_ID, $currencyId)
            ->delete();

        w_cache('currency')->clear();
    }

    /**
     * @param array<string, mixed> $localNames
     * @return array<string, string>
     */
    public function normalizeLocalNames(array $localNames): array
    {
        $normalized = [];
        foreach ($localNames as $localeCode => $name) {
            $localeCode = trim((string)$localeCode);
            if (!$this->isValidLocaleCode($localeCode)) {
                continue;
            }

            $normalized[$localeCode] = trim((string)$name);
        }

        ksort($normalized);

        return $normalized;
    }

    private function deleteLocalName(int $currencyId, string $localeCode): void
    {
        $this->localDescription->clear()
            ->where(LocalDescription::schema_fields_ID, $currencyId)
            ->where(LocalDescription::schema_fields_local_code, $localeCode)
            ->delete();
    }

    private function isValidLocaleCode(string $localeCode): bool
    {
        return (bool)preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,8}){0,3}$/', $localeCode);
    }
}
