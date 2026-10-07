<?php

declare(strict_types=1);

namespace Weline\Currency\Service;

use Weline\Currency\Model\Currency;
use Weline\Currency\Model\Currency\LocalDescription;
use Weline\Framework\Cache\Service\ScopeSharedMemo;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\I18n\Api\Localization\LocaleRepositoryInterface;

class CurrencyLocalDescriptionService
{
    private const DEFAULT_LOCALE_CODES = ['zh_Hans_CN', 'en_US'];
    private const PROCESS_BAG_MAX = 128;

    /** @var array<int, array<string, string>> currency_id → locale → name */
    private static array $processLocalNamesByCurrencyId = [];

    public function __construct(
        private readonly LocalDescription $localDescription,
        private readonly RuntimeProviderResolver $runtimeProviders,
    ) {
    }

    public static function clearProcessCache(): void
    {
        self::$processLocalNamesByCurrencyId = [];
        ScopeSharedMemo::purgeProcessPrefix('currency.local_names.');
    }

    /**
     * @return array<int, array{code: string, name: string}>
     */
    public function getAvailableLocales(string $displayLocale = 'zh_Hans_CN'): array
    {
        try {
            $records = $this->localeRepository()->installedActive($displayLocale);
        } catch (\Throwable) {
            $records = [];
        }

        $locales = [];
        $seen = [];
        foreach ($records as $record) {
            $code = trim($record->code);
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
                'name' => $record->displayName !== '' ? $record->displayName : $code,
            ];
        }

        if ($locales === []) {
            foreach (self::DEFAULT_LOCALE_CODES as $code) {
                $locales[] = ['code' => $code, 'name' => $code];
            }
        }

        return $locales;
    }

    private function localeRepository(): LocaleRepositoryInterface
    {
        $repository = $this->runtimeProviders->resolve(LocaleRepositoryInterface::class);
        if (!$repository instanceof LocaleRepositoryInterface) {
            throw new \RuntimeException('Weline_I18n locale repository provider is unavailable.');
        }

        return $repository;
    }

    /**
     * @return array<string, string>
     */
    public function getLocalNames(int $currencyId): array
    {
        if ($currencyId <= 0) {
            return [];
        }
        if (\array_key_exists($currencyId, self::$processLocalNamesByCurrencyId)) {
            return self::$processLocalNamesByCurrencyId[$currencyId];
        }

        $names = ScopeSharedMemo::rememberScoped(
            'currency',
            'currency.local_names.' . $currencyId,
            function () use ($currencyId): array {
                $rows = $this->localDescription->clear()
                    ->where(LocalDescription::schema_fields_ID, $currencyId)
                    ->select()
                    ->fetchArray();

                $out = [];
                foreach ($rows as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }

                    $localeCode = \trim((string)($row[LocalDescription::schema_fields_local_code] ?? ''));
                    if (!$this->isValidLocaleCode($localeCode)) {
                        continue;
                    }

                    $out[$localeCode] = (string)($row[LocalDescription::schema_fields_name] ?? '');
                }

                return $out;
            },
            ScopeIdentity::global(),
            600,
        );
        if (!\is_array($names)) {
            $names = [];
        }

        if (!\array_key_exists($currencyId, self::$processLocalNamesByCurrencyId)
            && \count(self::$processLocalNamesByCurrencyId) >= self::PROCESS_BAG_MAX
        ) {
            $first = \array_key_first(self::$processLocalNamesByCurrencyId);
            if ($first !== null) {
                unset(self::$processLocalNamesByCurrencyId[$first]);
            }
        }
        self::$processLocalNamesByCurrencyId[$currencyId] = $names;

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

        self::clearProcessCache();
        \Weline\Currency\Data\CurrencyData::clearCache();
        \Weline\Currency\Helper\CurrencySymbol::clearProcessCache();
    }

    public function deleteLocalNames(int $currencyId): void
    {
        if ($currencyId <= 0) {
            return;
        }

        $this->localDescription->clear()
            ->where(LocalDescription::schema_fields_ID, $currencyId)
            ->delete();

        self::clearProcessCache();
        \Weline\Currency\Data\CurrencyData::clearCache();
        \Weline\Currency\Helper\CurrencySymbol::clearProcessCache();
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
