<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Meta\Api\Data\MetaConfigRecord;

/**
 * Merges meta config records from one scope chain into a locale-aware map.
 */
final class ScopedMetaConfigMap
{
    /**
     * @param list<MetaConfigRecord> $records
     * @param list<string> $readChain
     * @return array<string, string>
     */
    public static function merge(array $records, array $readChain, string $requestedLocale): array
    {
        $recordsByScope = [];
        foreach ($records as $record) {
            if ($record instanceof MetaConfigRecord) {
                $recordsByScope[$record->scope][] = $record;
            }
        }

        $values = [];
        // Merge farthest to nearest so each child owns only the keys it
        // actually provides; empty/false/zero remain real values.
        foreach (array_reverse($readChain) as $scope) {
            $values = array_replace($values, self::resolve($recordsByScope[$scope] ?? [], $requestedLocale));
        }

        return $values;
    }

    /**
     * @param list<MetaConfigRecord> $records
     * @return array<string, string>
     */
    private static function resolve(array $records, string $requestedLocale): array
    {
        $values = [];
        $ranks = [];

        foreach ($records as $record) {
            $rank = self::localeRank($record->locale, $requestedLocale);
            if ($rank === null || (isset($ranks[$record->configKey]) && $rank >= $ranks[$record->configKey])) {
                continue;
            }
            $ranks[$record->configKey] = $rank;
            $values[$record->configKey] = $record->value;
        }

        return $values;
    }

    private static function localeRank(?string $recordLocale, string $requestedLocale): ?int
    {
        $locales = array_values(array_unique([$requestedLocale, 'zh_Hans_CN'], SORT_STRING));
        $locales[] = null;
        foreach ($locales as $rank => $locale) {
            if ($recordLocale === $locale) {
                return $rank;
            }
        }

        return null;
    }
}
