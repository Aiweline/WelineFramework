<?php

declare(strict_types=1);

namespace Weline\Product\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\I18n\Api\Translation\TranslationResolverInterface;

/**
 * Campaign context carried from activity shelves into PDP (query → preferred deal).
 */
final class StorefrontCampaignEntry
{
    public const QUERY_THEME_ID = 'promotion_theme_id';
    public const QUERY_CAMPAIGN = 'campaign';

    /**
     * @param array<string, mixed> $params
     */
    public static function preferredThemeIdFromParams(array $params): int
    {
        $themeId = max(0, (int)($params[self::QUERY_THEME_ID] ?? 0));
        if ($themeId > 0) {
            return $themeId;
        }

        $slug = strtolower(trim((string)($params[self::QUERY_CAMPAIGN] ?? $params['promotion'] ?? '')));
        if ($slug === '' || $slug === 'index') {
            return 0;
        }

        try {
            $themes = ObjectManager::getInstance(
                \Weline\Promotion\Service\PromotionActivityThemeService::class,
            );
            if (is_object($themes) && method_exists($themes, 'resolveActiveThemeIdByPageSlug')) {
                return max(0, (int)$themes->resolveActiveThemeIdByPageSlug($slug));
            }
        } catch (\Throwable) {
            // Promotion optional.
        }

        return 0;
    }

    /**
     * @param array<string, string> $query
     * @return array<string, string>
     */
    public static function mergeIntoQuery(array $query, int $themeId, string $pageSlug = ''): array
    {
        $themeId = max(0, $themeId);
        if ($themeId > 0) {
            $query[self::QUERY_THEME_ID] = (string)$themeId;
        }
        $pageSlug = strtolower(trim($pageSlug));
        if ($pageSlug !== '' && $pageSlug !== 'index') {
            $query[self::QUERY_CAMPAIGN] = $pageSlug;
        }

        return $query;
    }

    public static function isThemeSelectionCode(string $code): bool
    {
        return trim($code) === self::QUERY_THEME_ID;
    }

    /**
     * Human-readable campaign name for cart/checkout option chips.
     */
    public static function resolveThemeDisplayName(int $themeId, string $hint = ''): string
    {
        $hint = trim($hint);
        if ($hint !== '') {
            return $hint;
        }
        $themeId = max(0, $themeId);
        if ($themeId <= 0) {
            return '';
        }

        try {
            $themes = ObjectManager::getInstance(
                \Weline\Promotion\Service\PromotionActivityThemeService::class,
            );
            if (is_object($themes) && method_exists($themes, 'resolveStorefrontCampaignMeta')) {
                $meta = $themes->resolveStorefrontCampaignMeta($themeId);
                if (is_array($meta)) {
                    return trim((string)($meta['campaign_label'] ?? ''));
                }
            }
        } catch (\Throwable) {
            // Promotion optional.
        }

        return '';
    }

    /**
     * Present promotion_theme_id as「优惠主题 · {name}」; omit when name unknown.
     *
     * @return array{code:string,label:string,value:string,value_label:string}|null
     */
    public static function presentThemeOption(string $code, string $value, string $hint = ''): ?array
    {
        if (!self::isThemeSelectionCode($code)) {
            return null;
        }
        $themeId = max(0, (int)$value);
        $name = self::resolveThemeDisplayName($themeId, $hint);
        if ($themeId <= 0 || $name === '') {
            return null;
        }

        return [
            'code' => self::QUERY_THEME_ID,
            'label' => self::translatePhrase('优惠主题'),
            'value' => (string)$themeId,
            'value_label' => $name,
        ];
    }

    /**
     * Module CSV (Weline_Product) must win over global __(), which often misses
     * newly added Product phrases and leaves Chinese labels on en_US carts.
     */
    private static function translatePhrase(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }

        try {
            /** @var TranslationResolverInterface $resolver */
            $resolver = ObjectManager::getInstance(TranslationResolverInterface::class);
            $locale = trim(str_replace('-', '_', (string)RequestContext::getWelineUserLang()));
            if ($locale === '') {
                $locale = 'zh_Hans_CN';
            }
            $translated = trim($resolver->translate($source, $locale, ['Weline_Product']));
            if ($translated !== '') {
                return $translated;
            }
        } catch (\Throwable) {
            // Fall through to global phrase parser.
        }

        if (\function_exists('__')) {
            return (string)\__($source);
        }

        return $source;
    }
}
