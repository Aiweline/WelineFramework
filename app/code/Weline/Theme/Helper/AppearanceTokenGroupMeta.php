<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Phrase\DictionaryEvents;

/**
 * Appearance token section-group Meta keys and comment parsing.
 *
 * Comment: /* ========== Label #optional-id ========== *\/
 * Dictionary: @meta::theme.{area}.appearance.token_group.{group_id}.name
 */
final class AppearanceTokenGroupMeta
{
    public const DEFAULT_CATEGORY = '其他';
    public const MODULE = 'Weline_Theme';

    /**
     * @return array{label: string, id: string}
     */
    public static function parseSectionHeading(string $raw): array
    {
        $raw = trim($raw);
        $id = '';
        $label = $raw;
        if (preg_match('/^(.*?)\s+#([A-Za-z0-9_-]+)\s*$/u', $raw, $matches) === 1) {
            $label = trim($matches[1]);
            $id = strtolower(trim($matches[2]));
        }
        if ($label === '') {
            $label = self::DEFAULT_CATEGORY;
        }
        if ($id === '') {
            $id = self::hashGroupId($label);
        }

        return ['label' => $label, 'id' => $id];
    }

    public static function hashGroupId(string $label): string
    {
        return 'g_' . substr(sha1($label), 0, 10);
    }

    public static function isFallbackCategory(string $label): bool
    {
        $label = trim($label);

        return $label === '' || $label === self::DEFAULT_CATEGORY;
    }

    public static function metaKey(string $area, string $groupId): string
    {
        $area = trim($area) !== '' ? trim($area) : 'frontend';
        $groupId = strtolower(trim($groupId));

        return 'theme.' . $area . '.appearance.token_group.' . $groupId . '.name';
    }

    public static function dictionaryWord(string $area, string $groupId): string
    {
        return '@meta::' . self::metaKey($area, $groupId);
    }

    /**
     * Register unique section groups into the phrase dictionary (zh source).
     *
     * @param list<array<string, mixed>> $tokens
     */
    public static function registerGroupsFromTokens(string $area, array $tokens): void
    {
        $seen = [];
        $entries = [];
        foreach ($tokens as $token) {
            if (!\is_array($token)) {
                continue;
            }
            $label = trim((string)($token['category'] ?? ''));
            $id = trim((string)($token['category_id'] ?? ''));
            if (self::isFallbackCategory($label) || $id === '') {
                continue;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $entries[] = [
                'word' => self::dictionaryWord($area, $id),
                'translate' => $label,
                'module' => self::MODULE,
            ];
        }
        if ($entries === []) {
            return;
        }
        DictionaryEvents::register($entries, self::MODULE);
    }

    public static function translatedLabel(string $area, string $groupId, string $fallbackLabel): string
    {
        $translated = MetaTranslation::getTranslatedValue(
            self::metaKey($area, $groupId),
            null,
            $fallbackLabel
        );
        $translated = trim($translated);

        return $translated !== '' ? $translated : $fallbackLabel;
    }

    /**
     * Enrich parsed tokens with category_label for the current locale.
     *
     * @param list<array<string, mixed>> $tokens
     * @return list<array<string, mixed>>
     */
    public static function enrichTokensWithLabels(string $area, array $tokens): array
    {
        $labelCache = [];
        $out = [];
        foreach ($tokens as $token) {
            if (!\is_array($token)) {
                continue;
            }
            $label = trim((string)($token['category'] ?? self::DEFAULT_CATEGORY));
            $id = trim((string)($token['category_id'] ?? ''));
            if ($id === '' && !self::isFallbackCategory($label)) {
                $id = self::hashGroupId($label);
                $token['category_id'] = $id;
            }
            if (self::isFallbackCategory($label) || $id === '') {
                $token['category_label'] = $label;
            } else {
                if (!isset($labelCache[$id])) {
                    $labelCache[$id] = self::translatedLabel($area, $id, $label);
                }
                $token['category_label'] = $labelCache[$id];
            }
            $out[] = $token;
        }

        return $out;
    }
}
