<?php

declare(strict_types=1);

namespace Weline\Promotion\Service;

/** 活动主题 website / store / channel 范围匹配与优先级。 */
final class PromotionActivityThemeScopeMatcher
{
    /** @param array<string, mixed> $theme @param array{website_id:int,store_code:string,channel_code:string} $scope */
    public static function matches(array $theme, array $scope): bool
    {
        $websiteId = (int)$scope['website_id'];
        $storeCode = trim((string)$scope['store_code']);
        $channelCode = trim((string)$scope['channel_code']);

        $themeWebsiteId = (int)($theme['website_id'] ?? 0);
        $themeStoreCode = trim((string)($theme['store_code'] ?? ''));
        $themeChannelCode = trim((string)($theme['channel_code'] ?? ''));

        if ($themeWebsiteId !== $websiteId) {
            return false;
        }
        if ($storeCode === '' && $themeStoreCode !== '') {
            return false;
        }
        if ($themeStoreCode !== '' && $themeStoreCode !== $storeCode) {
            return false;
        }
        if ($channelCode === '' && $themeChannelCode !== '') {
            return false;
        }
        if ($themeChannelCode !== '' && $themeChannelCode !== $channelCode) {
            return false;
        }

        return true;
    }

    /** 范围越具体分数越高：website > store > channel。 */
    public static function specificityScore(array $theme): int
    {
        $score = 0;
        if ((int)($theme['website_id'] ?? 0) > 0) {
            $score += 100;
        }
        if (trim((string)($theme['store_code'] ?? '')) !== '') {
            $score += 10;
        }
        if (trim((string)($theme['channel_code'] ?? '')) !== '') {
            $score += 1;
        }

        return $score;
    }

    /**
     * 同一 slug 只保留当前 scope 下最具体的主题。
     *
     * @param list<array<string, mixed>> $themes
     * @return list<array<string, mixed>>
     */
    public static function dedupeByPageSlug(array $themes): array
    {
        $bestBySlug = [];
        foreach ($themes as $theme) {
            $slug = strtolower(trim((string)($theme['page_slug'] ?? '')));
            if ($slug === '') {
                continue;
            }

            $existing = $bestBySlug[$slug] ?? null;
            if (!is_array($existing)) {
                $bestBySlug[$slug] = $theme;
                continue;
            }

            $candidateScore = self::specificityScore($theme);
            $existingScore = self::specificityScore($existing);
            if ($candidateScore > $existingScore) {
                $bestBySlug[$slug] = $theme;
                continue;
            }
            if ($candidateScore === $existingScore
                && (int)($theme['sort_order'] ?? 0) < (int)($existing['sort_order'] ?? 0)) {
                $bestBySlug[$slug] = $theme;
            }
        }

        $items = array_values($bestBySlug);
        usort($items, static function (array $left, array $right): int {
            $sort = (int)($left['sort_order'] ?? 0) <=> (int)($right['sort_order'] ?? 0);
            if ($sort !== 0) {
                return $sort;
            }

            return (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0);
        });

        return $items;
    }
}
