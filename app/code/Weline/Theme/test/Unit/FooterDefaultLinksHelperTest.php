<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\FooterDefaultLinksHelper;

final class FooterDefaultLinksHelperTest extends TestCase
{
    public function testRelativeFooterLinksKeepTheirQueryAndAnchorWhileTheTaglibAddsTheActivePrefixOnce(): void
    {
        foreach ([
            '/guide/shipping?country=US#customs',
            '/USD/en_US/guide/shipping?country=US#customs',
        ] as $url) {
            self::assertSame(
                ['url' => '', 'url_path' => 'guide/shipping?country=US#customs'],
                FooterDefaultLinksHelper::splitUrlForTaglib($url, '/USD/en_US'),
            );
        }
    }

    public function testFooterExternalContactAndSamePageLinksRemainDirectUrls(): void
    {
        foreach ([
            'https://partner.example/policy?ref=hanfu#terms',
            '//partner.example/policy',
            'mailto:service@example.com',
            'tel:+861234567890',
            '#contact-service',
            '?preferences=1',
        ] as $url) {
            self::assertSame(
                ['url' => $url, 'url_path' => ''],
                FooterDefaultLinksHelper::splitUrlForTaglib($url, '/en_US'),
            );
        }
    }

    public function testPartialLinkGroupsUseTextItems(): void
    {
        $groups = FooterDefaultLinksHelper::partialLinkGroups();

        $this->assertNotEmpty($groups);
        $this->assertArrayHasKey('title', $groups[0]);
        $this->assertArrayHasKey('items', $groups[0]);
        $this->assertArrayHasKey('text', $groups[0]['items'][0]);
        $this->assertArrayHasKey('url', $groups[0]['items'][0]);
    }

    public function testWidgetLinkGroupsUseHanfuAtelierLabelsWithoutDuplicatingExtensionLinks(): void
    {
        $groups = FooterDefaultLinksHelper::widgetLinkGroups();

        $this->assertNotEmpty($groups);
        $this->assertCount(4, $groups);
        $this->assertSame('关于我们', $groups[0]['title']);
        $this->assertArrayHasKey('links', $groups[0]);
        $this->assertArrayHasKey('label', $groups[0]['links'][0]);
        $this->assertArrayHasKey('url', $groups[0]['links'][0]);
        $this->assertCount(1, $groups[0]['links']);
        $this->assertSame('关于我们', $groups[0]['links'][0]['label']);

        $this->assertSame('定制与合作', $groups[1]['title']);
        $partnerLabels = array_column($groups[1]['links'], 'label');
        $this->assertSame(['供应商合作'], $partnerLabels);
        $this->assertSame(['/inquiry/suppliers'], array_column($groups[1]['links'], 'url'));

        $this->assertSame('支付与账户', $groups[2]['title']);
        $this->assertSame([], $groups[2]['links']);
        $this->assertSame('帮助中心', $groups[3]['title']);
        $this->assertSame([], $groups[3]['links']);
    }

    public function testDefaultSocialChannelsFitAnInternationalHanfuStore(): void
    {
        $items = FooterDefaultLinksHelper::defaultSocialItems();

        $this->assertSame(
            ['Instagram', 'Pinterest', 'TikTok', 'YouTube'],
            array_column($items, 'name'),
        );
        $this->assertSame(
            ['fab fa-instagram', 'fab fa-pinterest', 'fab fa-tiktok', 'fab fa-youtube'],
            array_column($items, 'icon'),
        );
        foreach ($items as $item) {
            $this->assertMatchesRegularExpression('#^https://#', (string)$item['url']);
        }
        $this->assertSame(
            [
                'https://www.instagram.com/changan.hanfu',
                'https://www.pinterest.com/changanhanfu',
                'https://www.tiktok.com/@changan.hanfu',
                'https://www.youtube.com/@changanhanfu',
            ],
            FooterDefaultLinksHelper::defaultSameAsUrls(),
        );
    }

    public function testNormalizeSocialItemsOnlyReturnsActionableProfileLinks(): void
    {
        $defaults = FooterDefaultLinksHelper::defaultSocialItems();
        self::assertSame($defaults, FooterDefaultLinksHelper::normalizeSocialItems(null));
        self::assertSame(
            $defaults,
            FooterDefaultLinksHelper::normalizeSocialItems([
                ['name' => 'Pinterest', 'icon' => 'fab fa-pinterest', 'url' => '#'],
                ['name' => 'TikTok', 'icon' => 'fab fa-tiktok', 'url' => ''],
            ]),
        );

        self::assertSame(
            [[
                'name' => 'Instagram',
                'icon' => 'fab fa-instagram',
                'url' => 'https://www.instagram.com/changan-hanfu',
            ]],
            FooterDefaultLinksHelper::normalizeSocialItems([
                ['name' => 'Instagram', 'icon' => 'fab fa-instagram', 'url' => 'https://www.instagram.com/changan-hanfu'],
                ['name' => 'Pinterest', 'icon' => 'fab fa-pinterest', 'url' => '#'],
                ['name' => 'TikTok', 'icon' => 'fab fa-tiktok', 'url' => ''],
                ['name' => 'Unsafe', 'icon' => 'fas fa-link', 'url' => 'javascript:alert(1)'],
            ]),
        );
    }

    public function testLegalLinksPresent(): void
    {
        $links = FooterDefaultLinksHelper::legalLinks();

        $this->assertNotEmpty($links);
        $this->assertArrayHasKey('text', $links[0]);
        $this->assertArrayHasKey('url', $links[0]);

        $byText = [];
        foreach ($links as $link) {
            $byText[(string)$link['text']] = (string)$link['url'];
        }
        $this->assertSame('/terms', $byText['使用条件'] ?? null);
        $this->assertSame('/policy/privacy', $byText['隐私声明'] ?? null);
        $this->assertSame('/policy/cookie', $byText['Cookie 政策'] ?? null);
        $this->assertArrayNotHasKey('广告偏好', $byText);
    }
}
