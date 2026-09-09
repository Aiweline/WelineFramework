<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Promotion\Service\PromotionActivityThemeScopeMatcher;

final class PromotionActivityThemeScopeFilterTest extends TestCase
{
    public function testListApplicableFilterRequiresExactWebsite(): void
    {
        $scope = ['website_id' => 1, 'store_code' => 'default', 'channel_code' => 'web'];
        $themes = [
            ['page_slug' => 'deals', 'website_id' => 0, 'store_code' => '', 'channel_code' => ''],
            ['page_slug' => 'sale', 'website_id' => 1, 'store_code' => 'default', 'channel_code' => ''],
            ['page_slug' => 'wedding', 'website_id' => 2, 'store_code' => '', 'channel_code' => ''],
        ];

        $matched = array_values(array_filter(
            $themes,
            static fn (array $theme): bool => PromotionActivityThemeScopeMatcher::matches($theme, $scope),
        ));

        self::assertCount(1, $matched);
        self::assertSame('sale', $matched[0]['page_slug']);
    }

    public function testChannelScopedThemeOnlyMatchesExactChannel(): void
    {
        $theme = ['website_id' => 1, 'store_code' => 'default', 'channel_code' => 'app'];
        $webScope = ['website_id' => 1, 'store_code' => 'default', 'channel_code' => 'web'];
        $appScope = ['website_id' => 1, 'store_code' => 'default', 'channel_code' => 'app'];

        self::assertFalse(PromotionActivityThemeScopeMatcher::matches($theme, $webScope));
        self::assertTrue(PromotionActivityThemeScopeMatcher::matches($theme, $appScope));
    }
}
