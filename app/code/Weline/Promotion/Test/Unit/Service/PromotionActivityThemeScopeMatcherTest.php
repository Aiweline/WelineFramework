<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Promotion\Service\PromotionActivityThemeScopeMatcher;

final class PromotionActivityThemeScopeMatcherTest extends TestCase
{
    public function testThemeRequiresExactWebsiteMatch(): void
    {
        self::assertTrue(PromotionActivityThemeScopeMatcher::matches(
            ['website_id' => 0, 'store_code' => '', 'channel_code' => ''],
            ['website_id' => 0, 'store_code' => 'default', 'channel_code' => 'web'],
        ));
        self::assertFalse(PromotionActivityThemeScopeMatcher::matches(
            ['website_id' => 0, 'store_code' => '', 'channel_code' => ''],
            ['website_id' => 2, 'store_code' => 'default', 'channel_code' => 'web'],
        ));
        self::assertTrue(PromotionActivityThemeScopeMatcher::matches(
            ['website_id' => 2, 'store_code' => '', 'channel_code' => ''],
            ['website_id' => 2, 'store_code' => 'default', 'channel_code' => 'web'],
        ));
        self::assertFalse(PromotionActivityThemeScopeMatcher::matches(
            ['website_id' => 1, 'store_code' => '', 'channel_code' => ''],
            ['website_id' => 2, 'store_code' => 'default', 'channel_code' => 'web'],
        ));
    }

    public function testStoreSpecificThemeHiddenWhenScopeHasNoStore(): void
    {
        self::assertFalse(PromotionActivityThemeScopeMatcher::matches(
            ['website_id' => 1, 'store_code' => 'default', 'channel_code' => ''],
            ['website_id' => 1, 'store_code' => '', 'channel_code' => ''],
        ));
    }

    public function testChannelThemeRequiresMatchingStoreAndChannel(): void
    {
        self::assertTrue(PromotionActivityThemeScopeMatcher::matches(
            ['website_id' => 1, 'store_code' => 'default', 'channel_code' => 'app'],
            ['website_id' => 1, 'store_code' => 'default', 'channel_code' => 'app'],
        ));
        self::assertFalse(PromotionActivityThemeScopeMatcher::matches(
            ['website_id' => 1, 'store_code' => 'default', 'channel_code' => 'app'],
            ['website_id' => 1, 'store_code' => 'default', 'channel_code' => 'web'],
        ));
    }

    public function testDedupeKeepsMostSpecificThemeForSameSlug(): void
    {
        $items = PromotionActivityThemeScopeMatcher::dedupeByPageSlug([
            [
                'id' => 1,
                'page_slug' => 'deals',
                'website_id' => 1,
                'store_code' => '',
                'channel_code' => '',
                'sort_order' => 10,
            ],
            [
                'id' => 2,
                'page_slug' => 'deals',
                'website_id' => 1,
                'store_code' => 'default',
                'channel_code' => '',
                'sort_order' => 20,
            ],
        ]);

        self::assertCount(1, $items);
        self::assertSame(2, (int)$items[0]['id']);
    }
}
