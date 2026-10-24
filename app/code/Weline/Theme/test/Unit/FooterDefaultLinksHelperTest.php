<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\FooterDefaultLinksHelper;
use Weline\Theme\Helper\SocialIconHelper;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeDesignDataResolver;

final class FooterDefaultLinksHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::removeInstance(ThemeDesignDataResolver::class);
        parent::tearDown();
    }

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

    public function testWidgetLinkGroupsUseNeutralLabelsWithoutDuplicatingExtensionLinks(): void
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

    public function testDefaultSocialItemsComeFromTheBoundThemeDataPack(): void
    {
        $this->bindSocialPack([
            ['name' => 'YouTube', 'icon' => 'fab fa-youtube', 'url' => 'https://www.youtube.com/@theme-brand'],
            ['name' => 'Pinterest', 'icon' => 'fab fa-pinterest', 'url' => '#'],
            ['name' => 'Unsafe', 'icon' => 'fas fa-link', 'url' => 'javascript:alert(1)'],
            ['name' => 'Bluesky', 'icon' => 'fab fa-bluesky', 'url' => 'https://bsky.app/profile/theme-brand.bsky.social'],
        ]);

        self::assertSame(['YouTube', 'Bluesky'], array_column(FooterDefaultLinksHelper::defaultSocialItems(), 'name'));
        self::assertSame(
            ['https://www.youtube.com/@theme-brand', 'https://bsky.app/profile/theme-brand.bsky.social'],
            FooterDefaultLinksHelper::defaultSameAsUrls(),
        );
    }

    public function testThemeWithoutSocialPackAdvertisesNothing(): void
    {
        $this->bindSocialPack(null);

        self::assertSame([], FooterDefaultLinksHelper::defaultSocialItems());
        self::assertSame([], FooterDefaultLinksHelper::defaultSameAsUrls());
        // SEO extract path must not invent brand defaults from empty / placeholder rows.
        self::assertSame([], FooterDefaultLinksHelper::actionableSocialItems([]));
        self::assertSame([], FooterDefaultLinksHelper::actionableSocialItems([
            ['name' => 'Pinterest', 'icon' => 'fab fa-pinterest', 'url' => '#'],
        ]));
    }

    public function testHelperCarriesNoSiteOrBrandProfiles(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/Helper/FooterDefaultLinksHelper.php');

        foreach (['hanfu', 'daocharms', 'grocery', '61595373054093'] as $needle) {
            self::assertStringNotContainsStringIgnoringCase($needle, $source, $needle);
        }
    }

    public function testResolveSocialPlatformCodeMapsOfficialProfiles(): void
    {
        self::assertSame('youtube', FooterDefaultLinksHelper::resolveSocialPlatformCode([
            'name' => 'YouTube',
            'icon' => 'fab fa-youtube',
            'url' => 'https://www.youtube.com/@changanhanfu',
        ]));
        self::assertSame('x', FooterDefaultLinksHelper::resolveSocialPlatformCode([
            'name' => 'X',
            'icon' => 'fab fa-twitter',
            'url' => 'https://x.com/changanhanfu',
        ]));
        self::assertSame('instagram', FooterDefaultLinksHelper::resolveSocialPlatformCode([
            'name' => 'Instagram',
            'url' => 'https://www.instagram.com/changanhanfu/',
        ]));
        self::assertSame('tiktok', FooterDefaultLinksHelper::resolveSocialPlatformCode([
            'name' => 'TikTok',
            'url' => 'https://www.tiktok.com/@changanhanfu_hq',
        ]));
        self::assertSame('facebook', FooterDefaultLinksHelper::resolveSocialPlatformCode([
            'name' => 'Facebook',
            'url' => 'https://www.facebook.com/profile.php?id=61595373054093',
        ]));
        self::assertSame('pinterest', FooterDefaultLinksHelper::resolveSocialPlatformCode([
            'name' => 'Pinterest',
            'url' => 'https://www.pinterest.com/changanhanfu/',
        ]));
        // Bluesky has no FontAwesome class in the pinned icon set, so it must resolve to a
        // platform code that SocialIconHelper can actually render an SVG for.
        self::assertSame('bluesky', FooterDefaultLinksHelper::resolveSocialPlatformCode([
            'name' => 'Bluesky',
            'icon' => 'fab fa-bluesky',
            'url' => 'https://bsky.app/profile/changanhanfu.bsky.social',
        ]));
        self::assertNotNull(SocialIconHelper::getIcon('bluesky'));
        self::assertSame('#0285ff', SocialIconHelper::getColor('bluesky'));
    }

    public function testNormalizeSocialItemsOnlyReturnsActionableProfileLinks(): void
    {
        $this->bindSocialPack([
            ['name' => 'YouTube', 'icon' => 'fab fa-youtube', 'url' => 'https://www.youtube.com/@theme-brand'],
        ]);
        $defaults = FooterDefaultLinksHelper::defaultSocialItems();
        self::assertNotSame([], $defaults);
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

    /**
     * @param list<array<string,string>>|null $socialItems null = theme ships no pack
     */
    private function bindSocialPack(?array $socialItems): void
    {
        $pack = $socialItems === null ? [] : ['social_items' => $socialItems];
        $resolver = new class($pack) extends ThemeDesignDataResolver {
            /** @param array<string,mixed> $pack */
            public function __construct(private readonly array $pack)
            {
            }

            public function load(string $key, ?WelineTheme $theme = null): array
            {
                return $key === 'storefront-defaults' ? $this->pack : [];
            }
        };
        ObjectManager::setInstance(ThemeDesignDataResolver::class, $resolver);
    }
}
