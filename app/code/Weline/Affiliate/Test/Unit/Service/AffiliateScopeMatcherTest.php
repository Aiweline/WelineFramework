<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Affiliate\Service\AffiliateScopeMatcher;

class AffiliateScopeMatcherTest extends TestCase
{
    public function testGlobalAffiliateMatchesAnyScope(): void
    {
        $this->assertTrue(AffiliateScopeMatcher::matches(
            ['website_id' => 0, 'store_code' => '', 'channel_code' => ''],
            ['website_id' => 2, 'store_code' => 'default', 'channel_code' => 'web']
        ));
    }

    public function testWebsiteScopedAffiliateRequiresMatchingWebsite(): void
    {
        $this->assertTrue(AffiliateScopeMatcher::matches(
            ['website_id' => 1, 'store_code' => '', 'channel_code' => ''],
            ['website_id' => 1, 'store_code' => 'default', 'channel_code' => 'web']
        ));
        $this->assertFalse(AffiliateScopeMatcher::matches(
            ['website_id' => 1, 'store_code' => '', 'channel_code' => ''],
            ['website_id' => 2, 'store_code' => 'default', 'channel_code' => 'web']
        ));
    }

    public function testSpecificityScorePrefersMoreSpecificScope(): void
    {
        $this->assertGreaterThan(
            AffiliateScopeMatcher::specificityScore(['website_id' => 1, 'store_code' => '', 'channel_code' => '']),
            AffiliateScopeMatcher::specificityScore(['website_id' => 1, 'store_code' => 'default', 'channel_code' => 'web'])
        );
    }
}
