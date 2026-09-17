<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\HeaderPolicyLinksHelper;

final class HeaderPolicyLinksHelperTest extends TestCase
{
    public function testDefaultLinksIncludeAboutTermsAndAllPolicyLayouts(): void
    {
        $links = HeaderPolicyLinksHelper::defaultLinks();
        $byKey = [];
        foreach ($links as $link) {
            $byKey[(string)$link['key']] = $link;
        }

        self::assertSame('/about', $byKey['about']['url'] ?? null);
        self::assertSame('关于我们', $byKey['about']['label'] ?? null);
        self::assertSame('/terms', $byKey['terms']['url'] ?? null);
        self::assertSame('/policy/privacy', $byKey['policy_privacy']['url'] ?? null);
        self::assertSame('/policy/cookie', $byKey['policy_cookie']['url'] ?? null);
        self::assertSame('/policy/refund', $byKey['policy_refund']['url'] ?? null);
        self::assertSame('/policy/shipping', $byKey['policy_shipping']['url'] ?? null);
        self::assertSame('/policy/disclaimer', $byKey['policy_disclaimer']['url'] ?? null);
        self::assertSame('/policy/term-condition', $byKey['policy_term_condition']['url'] ?? null);
        self::assertSame('/policy/accessibility', $byKey['policy_accessibility']['url'] ?? null);
        self::assertArrayNotHasKey('policy_default', $byKey);

        foreach ($links as $link) {
            self::assertTrue((bool)$link['enabled']);
        }
    }

    public function testDiscoverPolicyOptionsSkipsDefaultShell(): void
    {
        $options = HeaderPolicyLinksHelper::discoverPolicyOptions();
        self::assertArrayNotHasKey('default', $options);
        self::assertArrayHasKey('privacy', $options);
        self::assertArrayHasKey('shipping', $options);
        self::assertArrayHasKey('accessibility', $options);
    }

    public function testNormalizeRenderableLinksSkipsDisabledAndFallsBackToDefaults(): void
    {
        self::assertNotEmpty(HeaderPolicyLinksHelper::normalizeRenderableLinks(null));
        self::assertNotEmpty(HeaderPolicyLinksHelper::normalizeRenderableLinks([]));

        $rendered = HeaderPolicyLinksHelper::normalizeRenderableLinks([
            ['key' => 'about', 'enabled' => true, 'label' => '关于我们', 'url' => '/about'],
            ['key' => 'cookie', 'enabled' => false, 'label' => 'Cookie 政策', 'url' => '/policy/cookie'],
            ['key' => 'custom', 'enabled' => '1', 'label' => '尺码指南', 'url' => '/guide/size'],
        ]);

        self::assertCount(2, $rendered);
        self::assertSame(['about', 'custom'], array_column($rendered, 'key'));
        self::assertSame('/guide/size', $rendered[1]['url']);
    }

    public function testMenuGroupsSplitAboutAndShoppingPolicies(): void
    {
        $links = HeaderPolicyLinksHelper::normalizeRenderableLinks(null);
        $inline = HeaderPolicyLinksHelper::inlineLinks($links);
        $groups = HeaderPolicyLinksHelper::menuGroups($links);

        self::assertSame(['关于我们'], array_column($inline, 'label'));
        self::assertSame(['/about'], array_column($inline, 'url'));

        self::assertGreaterThanOrEqual(2, count($groups));
        self::assertSame('legal', $groups[0]['key']);
        self::assertSame('法律与隐私', $groups[0]['title']);
        self::assertContains('使用条件', array_column($groups[0]['items'], 'label'));
        self::assertContains('隐私政策', array_column($groups[0]['items'], 'label'));

        $fulfillment = null;
        foreach ($groups as $group) {
            if (($group['key'] ?? '') === 'fulfillment') {
                $fulfillment = $group;
                break;
            }
        }
        self::assertNotNull($fulfillment);
        self::assertSame('配送与售后', $fulfillment['title']);
        self::assertContains('配送政策', array_column($fulfillment['items'], 'label'));
        self::assertContains('退款政策', array_column($fulfillment['items'], 'label'));

        foreach ($groups as $group) {
            self::assertNotContains('关于我们', array_column($group['items'], 'label'));
        }
    }
}
