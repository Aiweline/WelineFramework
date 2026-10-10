<?php

declare(strict_types=1);

namespace Weline\I18n\Service\Catalog;

/**
 * Reads the committed locale-catalog seed under I18n/data/locale-catalog.
 * Used only by Install / lifecycle name seeding — storefront hot paths must read DB.
 */
final class LocaleCatalogPackReader
{
    public const MANIFEST = 'MANIFEST.json';

    private string $packDir;

    /** @var list<string>|null */
    private ?array $localeCodes = null;

    /** @var list<string>|null */
    private ?array $countryCodes = null;

    /** @var array<string, array{country_code:string,short_code:string,iso2:string,iso3:string}>|null */
    private ?array $localeMeta = null;

    public function __construct(?string $packDir = null)
    {
        $this->packDir = $packDir ?? (dirname(__DIR__, 2) . '/data/locale-catalog');
    }

    public function packDir(): string
    {
        return $this->packDir;
    }

    public function assertPackReady(): void
    {
        $manifest = $this->packDir . '/' . self::MANIFEST;
        if (!is_file($manifest)) {
            throw new \RuntimeException('I18n locale catalog pack missing: ' . $manifest);
        }
        foreach (['countries.tsv.gz', 'locales.tsv.gz', 'locale-names.tsv.gz', 'country-names.tsv.gz'] as $file) {
            if (!is_file($this->packDir . '/' . $file)) {
                throw new \RuntimeException('I18n locale catalog pack incomplete: ' . $file);
            }
        }
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        $raw = file_get_contents($this->packDir . '/' . self::MANIFEST);
        if ($raw === false) {
            throw new \RuntimeException('Cannot read locale catalog MANIFEST');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid locale catalog MANIFEST JSON');
        }

        return $decoded;
    }

    /** @return list<string> */
    public function countryCodes(): array
    {
        if ($this->countryCodes !== null) {
            return $this->countryCodes;
        }
        $codes = [];
        foreach ($this->readTsvGz('countries.tsv.gz') as $row) {
            $code = strtoupper(trim((string)($row['code'] ?? '')));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $this->countryCodes = array_values(array_unique($codes));
    }

    /** @return list<string> */
    public function localeCodes(): array
    {
        if ($this->localeCodes !== null) {
            return $this->localeCodes;
        }
        $codes = [];
        foreach ($this->localeMetaMap() as $code => $_) {
            $codes[] = $code;
        }

        return $this->localeCodes = $codes;
    }

    /** @return list<string> */
    public function localeCodesForCountry(string $countryCode): array
    {
        $countryCode = strtoupper(trim($countryCode));
        if ($countryCode === '') {
            return [];
        }
        $codes = [];
        foreach ($this->localeMetaMap() as $code => $meta) {
            if (($meta['country_code'] ?? '') === $countryCode) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return array<string, array{country_code:string,short_code:string,iso2:string,iso3:string}>
     */
    public function localeMetaMap(): array
    {
        if ($this->localeMeta !== null) {
            return $this->localeMeta;
        }
        $map = [];
        foreach ($this->readTsvGz('locales.tsv.gz') as $row) {
            $code = trim((string)($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $map[$code] = [
                'country_code' => strtoupper(trim((string)($row['country_code'] ?? ''))),
                'short_code' => trim((string)($row['short_code'] ?? '')),
                'iso2' => strtoupper(trim((string)($row['iso2'] ?? ''))),
                'iso3' => strtoupper(trim((string)($row['iso3'] ?? ''))),
            ];
        }

        return $this->localeMeta = $map;
    }

    public function localeName(string $localeCode, string $displayLocale): string
    {
        $wanted = [$localeCode => true];
        $names = $this->localeNamesForDisplay($displayLocale, $wanted);

        return $names[$localeCode] ?? '';
    }

    public function countryName(string $countryCode, string $displayLocale): string
    {
        $countryCode = strtoupper(trim($countryCode));
        $wanted = [$countryCode => true];
        $names = $this->countryNamesForDisplay($displayLocale, $wanted);

        return $names[$countryCode] ?? '';
    }

    /**
     * @param array<string, bool>|null $wantedLocaleCodes null = all for display
     * @return array<string, string> locale_code => name
     */
    public function localeNamesForDisplay(string $displayLocale, ?array $wantedLocaleCodes = null): array
    {
        $displayLocale = trim($displayLocale);
        $out = [];
        foreach ($this->readTsvGz('locale-names.tsv.gz') as $row) {
            if (trim((string)($row['display_locale_code'] ?? '')) !== $displayLocale) {
                continue;
            }
            $code = trim((string)($row['locale_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if ($wantedLocaleCodes !== null && !isset($wantedLocaleCodes[$code])) {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '') {
                $out[$code] = $name;
            }
        }

        return $out;
    }

    /**
     * @param array<string, bool>|null $wantedCountryCodes
     * @return array<string, string> country_code => name
     */
    public function countryNamesForDisplay(string $displayLocale, ?array $wantedCountryCodes = null): array
    {
        $displayLocale = trim($displayLocale);
        $out = [];
        foreach ($this->readTsvGz('country-names.tsv.gz') as $row) {
            if (trim((string)($row['display_locale_code'] ?? '')) !== $displayLocale) {
                continue;
            }
            $code = strtoupper(trim((string)($row['country_code'] ?? '')));
            if ($code === '') {
                continue;
            }
            if ($wantedCountryCodes !== null && !isset($wantedCountryCodes[$code])) {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '') {
                $out[$code] = $name;
            }
        }

        return $out;
    }

    /**
     * Names of one locale entity across many display locales (stream once).
     *
     * @param list<string> $displayLocales
     * @return array<string, string> display_locale => name
     */
    public function localeNamesForCodeAcrossDisplays(string $localeCode, array $displayLocales): array
    {
        $localeCode = trim($localeCode);
        $wantedDisplays = [];
        foreach ($displayLocales as $d) {
            $d = trim((string)$d);
            if ($d !== '') {
                $wantedDisplays[$d] = true;
            }
        }
        if ($localeCode === '' || $wantedDisplays === []) {
            return [];
        }
        $out = [];
        foreach ($this->readTsvGz('locale-names.tsv.gz') as $row) {
            if (trim((string)($row['locale_code'] ?? '')) !== $localeCode) {
                continue;
            }
            $display = trim((string)($row['display_locale_code'] ?? ''));
            if (!isset($wantedDisplays[$display])) {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '') {
                $out[$display] = $name;
            }
        }

        return $out;
    }

    /**
     * @return \Generator<int, array<string, string>>
     */
    private function readTsvGz(string $relative): \Generator
    {
        $path = $this->packDir . '/' . $relative;
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('Cannot open ' . $path);
        }
        $headerLine = gzgets($gz);
        if ($headerLine === false) {
            gzclose($gz);

            return;
        }
        $headers = str_getcsv(rtrim($headerLine, "\r\n"), "\t");
        while (($line = gzgets($gz)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            $cols = str_getcsv($line, "\t");
            $row = [];
            foreach ($headers as $i => $key) {
                $row[(string)$key] = (string)($cols[$i] ?? '');
            }
            yield $row;
        }
        gzclose($gz);
    }
}
