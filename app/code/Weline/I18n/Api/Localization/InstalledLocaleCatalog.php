<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Localization;

use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locale\Name;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;

/**
 * Public, deterministic catalog of globally installed locales.
 */
final class InstalledLocaleCatalog implements LocaleCatalogInterface
{
    public function __construct(
        private readonly Locale $locale,
        private readonly Name $localeName,
    ) {
    }

    /**
     * @return list<array{code:string,name:string}>
     */
    public function list(string $displayLocale): array
    {
        $localeRows = $this->locale->reset()
            ->where(Locale::schema_fields_IS_INSTALL, 1)
            ->order(Locale::schema_fields_CODE, 'ASC')
            ->select()
            ->fetchArray();

        $codes = [];
        foreach ((array)$localeRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = (string)($row[Locale::schema_fields_CODE] ?? '');
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        if ($codes === []) {
            return [];
        }

        $nameRows = $this->localeName->reset()
            ->where(Name::schema_fields_LOCALE_CODE, $codes, 'IN')
            ->where(Name::schema_fields_DISPLAY_LOCALE_CODE, $displayLocale)
            ->select()
            ->fetchArray();
        $names = [];
        foreach ((array)$nameRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = (string)($row[Name::schema_fields_LOCALE_CODE] ?? '');
            if ($code !== '') {
                $names[$code] = (string)($row[Name::schema_fields_DISPLAY_NAME] ?? '');
            }
        }

        $result = [];
        foreach ($codes as $code) {
            $result[] = [
                'code' => $code,
                'name' => \array_key_exists($code, $names) ? $names[$code] : $code,
            ];
        }
        return $result;
    }

    public function all(string $displayLocale): array
    {
        $rows = $this->locale->reset()
            ->select()
            ->fetchArray();
        $result = [];
        foreach ((array)$rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = (string)($row[Locale::schema_fields_CODE] ?? '');
            if ($code === '') {
                continue;
            }
            // 保留 Locale Model::getName() 的既有输出；当前 schema 没有 name 字段。
            $result[] = ['code' => $code, 'name' => isset($row['name']) ? (string)$row['name'] : null];
        }
        return $result;
    }

    public function installed(string $displayLocale, int $flagWidth = 20, int $flagHeight = 15): array
    {
        $rows = $this->locale->reset()
            ->where(Locale::schema_fields_IS_ACTIVE, 1)
            ->where(Locale::schema_fields_IS_INSTALL, 1)
            ->order(Locale::schema_fields_CODE, 'ASC')
            ->select()
            ->fetchArray();
        /** @var I18n $i18n */
        $i18n = ObjectManager::getInstance(I18n::class);
        $rowsByCode = [];
        foreach ((array)$rows as $row) {
            if (\is_array($row)) {
                $rowsByCode[(string)($row[Locale::schema_fields_CODE] ?? '')] = $row;
            }
        }
        $result = [];
        foreach ($this->basicList((array)$rows, $displayLocale) as $locale) {
            $row = $rowsByCode[$locale['code']] ?? null;
            $countryCode = \is_array($row) ? (string)($row[Locale::schema_fields_COUNTRY_CODE] ?? '') : '';
            $selfName = $i18n->getLocaleName($locale['code'], $locale['code']);
            $resolvedName = $i18n->getLocaleName($locale['code'], $displayLocale);
            $name = $resolvedName !== '' ? $resolvedName : $locale['name'];
            if ($selfName !== '' && $selfName !== $name) {
                $name .= '(' . $selfName . ')';
            }
            $result[] = [
                'code' => $locale['code'],
                'name' => $name,
                'flag' => $countryCode !== ''
                    ? $i18n->getCountryFlag($countryCode, $flagWidth, $flagHeight)
                    : (\is_array($row) ? (string)($row[Locale::schema_fields_FLAG] ?? '') : ''),
            ];
        }
        return $result;
    }

    public function installedPackages(
        string $displayLocale,
        int $flagWidth = 24,
        int $flagHeight = 18,
        bool $autoSize = false,
    ): array {
        /** @var I18n $i18n */
        $i18n = ObjectManager::getInstance(I18n::class);
        $runtimeLocales = $i18n->getLocalesWithFlagsDisplaySelf(
            $displayLocale,
            $flagWidth,
            $flagHeight,
            true,
            $autoSize,
        );

        $result = [];
        foreach ($runtimeLocales as $code => $locale) {
            if (!\is_array($locale) || (string)$code === '') {
                continue;
            }
            $result[] = [
                'code' => (string)$code,
                'name' => (string)($locale['name'] ?? $code),
                'flag' => (string)($locale['flag'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{code:string,name:string}>
     */
    private function basicList(array $rows, string $displayLocale): array
    {
        $codes = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = (string)($row[Locale::schema_fields_CODE] ?? '');
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        if ($codes === []) {
            return [];
        }
        $nameRows = $this->localeName->reset()
            ->where(Name::schema_fields_LOCALE_CODE, $codes, 'IN')
            ->where(Name::schema_fields_DISPLAY_LOCALE_CODE, $displayLocale)
            ->select()
            ->fetchArray();
        $names = [];
        foreach ((array)$nameRows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = (string)($row[Name::schema_fields_LOCALE_CODE] ?? '');
            if ($code !== '') {
                $names[$code] = (string)($row[Name::schema_fields_DISPLAY_NAME] ?? '');
            }
        }
        return \array_map(
            static fn(string $code): array => ['code' => $code, 'name' => $names[$code] ?? $code],
            $codes,
        );
    }
}
