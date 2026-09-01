<?php

declare(strict_types=1);

namespace Weline\Affiliate\Service;

use Weline\Affiliate\Model\Affiliate;

/** 分销账户 website / store / channel 范围匹配与优先级。 */
final class AffiliateScopeMatcher
{
    /** @param array<string, mixed> $affiliate @param array{website_id:int,store_code:string,channel_code:string} $scope */
    public static function matches(array $affiliate, array $scope): bool
    {
        $websiteId = (int) $scope['website_id'];
        $storeCode = trim((string) $scope['store_code']);
        $channelCode = trim((string) $scope['channel_code']);

        $affiliateWebsiteId = (int) ($affiliate[Affiliate::schema_fields_WEBSITE_ID] ?? $affiliate['website_id'] ?? 0);
        $affiliateStoreCode = trim((string) ($affiliate[Affiliate::schema_fields_STORE_CODE] ?? $affiliate['store_code'] ?? ''));
        $affiliateChannelCode = trim((string) ($affiliate[Affiliate::schema_fields_CHANNEL_CODE] ?? $affiliate['channel_code'] ?? ''));

        if ($affiliateWebsiteId > 0 && $affiliateWebsiteId !== $websiteId) {
            return false;
        }
        if ($storeCode === '' && $affiliateStoreCode !== '') {
            return false;
        }
        if ($affiliateStoreCode !== '' && $affiliateStoreCode !== $storeCode) {
            return false;
        }
        if ($channelCode === '' && $affiliateChannelCode !== '') {
            return false;
        }
        if ($affiliateChannelCode !== '' && $affiliateChannelCode !== $channelCode) {
            return false;
        }

        return true;
    }

    /** 范围越具体分数越高：website > store > channel。 */
    public static function specificityScore(array $affiliate): int
    {
        $score = 0;
        if ((int) ($affiliate[Affiliate::schema_fields_WEBSITE_ID] ?? $affiliate['website_id'] ?? 0) > 0) {
            $score += 100;
        }
        if (trim((string) ($affiliate[Affiliate::schema_fields_STORE_CODE] ?? $affiliate['store_code'] ?? '')) !== '') {
            $score += 10;
        }
        if (trim((string) ($affiliate[Affiliate::schema_fields_CHANNEL_CODE] ?? $affiliate['channel_code'] ?? '')) !== '') {
            $score += 1;
        }

        return $score;
    }
}
