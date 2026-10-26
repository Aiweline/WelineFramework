<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Affiliate\Service\AffiliateRuleService;

final class AffiliateRuleServiceTest extends TestCase
{
    public function testDefaultsWhenNoScopeConfig(): void
    {
        $rules = AffiliateRuleService::forTesting([])->resolve('demo.main.web');

        self::assertNotSame('', trim($rules['title']));
        self::assertSame(0.10, $rules['commission_rate']);
        self::assertSame('10%', $rules['commission_rate_label']);
        self::assertNotSame('', trim($rules['level_name']));
        self::assertSame('demo.main.web', $rules['scope']);
        self::assertNotEmpty($rules['lines']);
    }

    public function testChannelOverridesStoreOverridesWebsiteOverridesGlobal(): void
    {
        $rules = AffiliateRuleService::forTesting([
            'default.default.default' => [
                'rule_title' => 'Global title',
                'rule_summary' => 'Global line',
                'default_commission_rate' => '0.05',
                'level_name' => 'Global level',
            ],
            'demo.default.default' => [
                'rule_title' => 'Website title',
                'rule_summary' => 'Website line',
            ],
            'demo.main.default' => [
                'rule_title' => 'Store title',
                'level_name' => 'Store level',
            ],
            'demo.main.web' => [
                'rule_title' => 'Channel title',
            ],
        ])->resolve('demo.main.web');

        self::assertSame('Channel title', $rules['title']);
        self::assertSame('Store level', $rules['level_name']);
        self::assertSame('Website line', $rules['summary']);
        self::assertSame(0.05, $rules['commission_rate']);
        self::assertSame('5%', $rules['commission_rate_label']);
    }

    public function testWebsiteRuleCascadesToStoreWhenLowerScopeHasNoOverride(): void
    {
        $rules = AffiliateRuleService::forTesting([
            'demo.default.default' => [
                'rule_title' => 'Website title',
                'rule_summary' => 'Website line',
                'level_name' => 'Website level',
            ],
        ])->resolve('demo.main.web');

        self::assertSame('Website title', $rules['title']);
        self::assertSame('Website line', $rules['summary']);
        self::assertSame('Website level', $rules['level_name']);
    }

    public function testRateNormalizationAcceptsPercentAndDecimal(): void
    {
        self::assertSame(0.15, AffiliateRuleService::normalizeRate('15%'));
        self::assertSame(0.15, AffiliateRuleService::normalizeRate('0.15'));
        self::assertSame(1.0, AffiliateRuleService::normalizeRate('120'));
        self::assertSame(0.10, AffiliateRuleService::normalizeRate('not-a-rate'));
    }

    public function testSummaryLinesStripBulletsAndBlankLines(): void
    {
        self::assertSame(
            ['One', 'Two', 'Three'],
            AffiliateRuleService::splitLines("- One\n\n* Two\n• Three"),
        );
    }
}
