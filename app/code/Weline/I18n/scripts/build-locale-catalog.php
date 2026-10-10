#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Dev-only: extract Symfony/ICU locale catalog into I18n/data/locale-catalog/*.tsv.gz.
 * Runtime Install/lifecycle must read the pack only — never call this in production paths.
 *
 * Usage: php app/code/Weline/I18n/scripts/build-locale-catalog.php
 */

use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Intl\Locales;

$root = dirname(__DIR__, 4);
if (!is_file($root . '/vendor/autoload.php')) {
    $root = dirname(__DIR__, 5);
}
require $root . '/vendor/autoload.php';

if (!class_exists(Locales::class)) {
    fwrite(STDERR, "symfony/intl is build-time only. Install with: composer require --dev symfony/intl:^5.2\n");
    exit(1);
}

$outDir = dirname(__DIR__) . '/data/locale-catalog';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(1);
}

if (!extension_loaded('intl')) {
    fwrite(STDERR, "ext-intl required to build a complete catalog\n");
    exit(1);
}

@ini_set('memory_limit', '1024M');

$started = microtime(true);
$locales = array_values(Locales::getLocales());
sort($locales);
$countries = array_values(Countries::getCountryCodes());
sort($countries);
$languages = class_exists(Languages::class) ? array_values(Languages::getLanguageCodes()) : [];
sort($languages);

writeGzTsv($outDir . '/countries.tsv.gz', ['code'], array_map(static fn (string $c): array => [$c], $countries));
writeGzTsv($outDir . '/languages.tsv.gz', ['code'], array_map(static fn (string $c): array => [$c], $languages));

$localeRows = [];
foreach ($locales as $localeCode) {
    $countryCode = countryFromLocale($localeCode);
    $localeRows[] = [
        $localeCode,
        $countryCode,
        shortCodeFromLocale($localeCode),
        iso2FromLocale($localeCode),
        '',
    ];
}
writeGzTsv($outDir . '/locales.tsv.gz', ['code', 'country_code', 'short_code', 'iso2', 'iso3'], $localeRows);

$localeNameOk = 0;
$localeNameFail = 0;
$localeNamesPath = $outDir . '/locale-names.tsv.gz';
$localeGz = gzopen($localeNamesPath, 'wb9');
if ($localeGz === false) {
    throw new RuntimeException('Cannot write ' . $localeNamesPath);
}
gzwrite($localeGz, "locale_code\tdisplay_locale_code\tname\n");
foreach ($locales as $localeCode) {
    foreach ($locales as $display) {
        $name = localeName($localeCode, $display);
        if ($name === '') {
            ++$localeNameFail;
            continue;
        }
        gzwrite($localeGz, tsvLine([$localeCode, $display, $name]));
        ++$localeNameOk;
    }
}
gzclose($localeGz);

$countryNameOk = 0;
$countryNameFail = 0;
$countryNamesPath = $outDir . '/country-names.tsv.gz';
$countryGz = gzopen($countryNamesPath, 'wb9');
if ($countryGz === false) {
    throw new RuntimeException('Cannot write ' . $countryNamesPath);
}
gzwrite($countryGz, "country_code\tdisplay_locale_code\tname\n");
foreach ($countries as $countryCode) {
    foreach ($locales as $display) {
        $name = countryName($countryCode, $display);
        if ($name === '') {
            ++$countryNameFail;
            continue;
        }
        gzwrite($countryGz, tsvLine([$countryCode, $display, $name]));
        ++$countryNameOk;
    }
}
gzclose($countryGz);

$manifest = [
    'schema' => 'weline-i18n-locale-catalog.v1',
    'generated_at' => gmdate('c'),
    'source' => [
        'symfony_intl' => 'Locales/Countries/Languages',
        'ext_intl' => true,
        'icu_version' => defined('INTL_ICU_VERSION') ? (string)INTL_ICU_VERSION : null,
    ],
    'counts' => [
        'countries' => count($countries),
        'locales' => count($locales),
        'languages' => count($languages),
        'locale_names' => $localeNameOk,
        'locale_names_skipped' => $localeNameFail,
        'country_names' => $countryNameOk,
        'country_names_skipped' => $countryNameFail,
    ],
    'files' => [
        'countries.tsv.gz',
        'locales.tsv.gz',
        'languages.tsv.gz',
        'locale-names.tsv.gz',
        'country-names.tsv.gz',
    ],
    'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
];
foreach ($manifest['files'] as $file) {
    $path = $outDir . '/' . $file;
    $manifest['sha256'][$file] = hash_file('sha256', $path);
    $manifest['bytes'][$file] = filesize($path);
}
file_put_contents($outDir . '/MANIFEST.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

function writeGzTsv(string $path, array $header, array $rows): void
{
    $gz = gzopen($path, 'wb9');
    if ($gz === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
    gzwrite($gz, implode("\t", $header) . "\n");
    foreach ($rows as $row) {
        gzwrite($gz, tsvLine($row));
    }
    gzclose($gz);
}

/** @param list<string> $row */
function tsvLine(array $row): string
{
    $cols = [];
    foreach ($row as $cell) {
        $cols[] = str_replace(["\t", "\n", "\r"], [' ', ' ', ''], (string)$cell);
    }

    return implode("\t", $cols) . "\n";
}

function countryFromLocale(string $localeCode): string
{
    $parts = explode('_', str_replace('-', '_', $localeCode));
    $last = (string)end($parts);
    if (strlen($last) === 2 && preg_match('/^[A-Za-z]{2}$/', $last) === 1) {
        return strtoupper($last);
    }

    return '';
}

function shortCodeFromLocale(string $localeCode): string
{
    $parts = explode('_', str_replace('-', '_', trim($localeCode)));
    $lang = strtoupper((string)($parts[0] ?? ''));
    if ($lang === '') {
        return '';
    }
    if (isset($parts[1]) && strlen((string)$parts[1]) === 2) {
        return strtoupper((string)$parts[1]);
    }

    return $lang;
}

function iso2FromLocale(string $localeCode): string
{
    $parts = explode('_', str_replace('-', '_', trim($localeCode)));
    $lang = strtolower((string)($parts[0] ?? ''));
    if ($lang === '' || strlen($lang) !== 2) {
        return '';
    }

    return strtoupper($lang);
}

function localeName(string $localeCode, string $display): string
{
    try {
        $name = trim((string)Locales::getName($localeCode, $display));
        if ($name !== '' && $name !== $localeCode) {
            return $name;
        }
    } catch (Throwable) {
    }
    try {
        return trim((string)Locales::getName($localeCode, 'en'));
    } catch (Throwable) {
        return $localeCode;
    }
}

function countryName(string $countryCode, string $display): string
{
    try {
        $name = trim((string)Countries::getName($countryCode, $display));
        if ($name !== '') {
            return $name;
        }
    } catch (Throwable) {
    }
    try {
        return trim((string)Countries::getName($countryCode, 'en'));
    } catch (Throwable) {
        return $countryCode;
    }
}
