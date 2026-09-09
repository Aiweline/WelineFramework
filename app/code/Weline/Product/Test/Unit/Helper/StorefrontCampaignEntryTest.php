<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Product\Helper\StorefrontCampaignEntry;
use Weline\Product\Helper\StorefrontOfferDetailQuery;

final class StorefrontCampaignEntryTest extends TestCase
{
    public function testPreferredThemeIdFromParams(): void
    {
        self::assertSame(7, StorefrontCampaignEntry::preferredThemeIdFromParams([
            'promotion_theme_id' => '7',
            'campaign' => 'deals',
        ]));
        self::assertSame(0, StorefrontCampaignEntry::preferredThemeIdFromParams([]));
    }

    public function testMergeIntoQueryAndDetailParams(): void
    {
        $query = StorefrontCampaignEntry::mergeIntoQuery(['size' => 's'], 3, 'weekend');
        self::assertSame('3', $query['promotion_theme_id']);
        self::assertSame('weekend', $query['campaign']);
        self::assertSame('s', $query['size']);

        $params = StorefrontOfferDetailQuery::params([
            'promotion_theme_id' => 1,
            'campaign_page_slug' => 'deals',
        ]);
        self::assertSame('1', $params['promotion_theme_id'] ?? null);
        self::assertSame('deals', $params['campaign'] ?? null);
    }

    public function testPresentThemeOptionUsesHumanLabelAndHintName(): void
    {
        self::assertTrue(StorefrontCampaignEntry::isThemeSelectionCode('promotion_theme_id'));
        self::assertFalse(StorefrontCampaignEntry::isThemeSelectionCode('size'));

        $option = StorefrontCampaignEntry::presentThemeOption('promotion_theme_id', '3', '今日特价');
        self::assertNotNull($option);
        self::assertSame('promotion_theme_id', $option['code']);
        self::assertNotSame('', trim((string)$option['label']));
        self::assertNotSame('promotion_theme_id', $option['label']);
        self::assertContains($option['label'], ['优惠主题', 'Promotion theme']);
        self::assertSame('3', $option['value']);
        self::assertSame('今日特价', $option['value_label']);

        self::assertNull(StorefrontCampaignEntry::presentThemeOption('promotion_theme_id', '0'));
        self::assertNull(StorefrontCampaignEntry::presentThemeOption('size', 'S'));
    }
}
