<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

/**
 * 本进程 collect/翻译写路径登记脏 locale；Export 只 publish 脏集时可 drain。
 * 全量 collect 仍由 DictionaryExportLocaleResolver 直接给全集，不必依赖本袋。
 */
final class DictionaryDirtyLocaleSet
{
    /** @var array<string, true> */
    private static array $locales = [];

    public static function mark(string $localeCode): void
    {
        $localeCode = trim(str_replace('-', '_', $localeCode));
        if ($localeCode === '') {
            return;
        }
        self::$locales[$localeCode] = true;
    }

    /**
     * @param list<string> $localeCodes
     */
    public static function markMany(array $localeCodes): void
    {
        foreach ($localeCodes as $localeCode) {
            self::mark((string)$localeCode);
        }
    }

    /**
     * @return list<string>
     */
    public static function peek(): array
    {
        return array_keys(self::$locales);
    }

    /**
     * @return list<string>
     */
    public static function drain(): array
    {
        $out = array_keys(self::$locales);
        self::$locales = [];

        return $out;
    }

    public static function clear(): void
    {
        self::$locales = [];
    }
}
