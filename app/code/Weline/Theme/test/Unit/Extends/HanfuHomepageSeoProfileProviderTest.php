<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Extends;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Api\Translation\TranslationResolverInterface;
use Weline\Theme\Extends\Module\Weline_Seo\SeoProfileProvider\HanfuHomepageSeoProfileProvider;

final class HanfuHomepageSeoProfileProviderTest extends TestCase
{
    private const TITLE_ZH = '云裳汉服 · Hanfu Atelier | 水墨汉服商城首页';
    private const TITLE_EN = 'Yunshang Hanfu · Hanfu Atelier | Ink-Wash Hanfu Boutique';
    private const DESCRIPTION_ZH = '云裳汉服水墨中国风独立站，精选明制、宋制、唐制汉服与马面裙及传统配饰，覆盖日常出行、节日庆典与礼仪场合；提供形制说明、尺码参考、面料要点与搭配灵感，助你更快选到合身又得体的汉服款式。';
    private const DESCRIPTION_EN = 'Yunshang Hanfu offers Ming, Song, and Tang Hanfu, mamian skirts, and accessories for everyday wear, festivals, and ceremonies.';
    private const TITLE_AR = 'يونشانغ هانفو · مشغل الهانفو | متجر هانفو بأسلوب الحبر الصيني';
    private const DESCRIPTION_AR = 'اكتشف أزياء هانفو من عصور مينغ وسونغ وتانغ، وتنانير ماميان والإكسسوارات التقليدية للحياة اليومية والمهرجانات والمراسم.';
    private const SITE_NAME_ZH = '云裳汉服 · Hanfu Atelier';
    private const SITE_NAME_EN = 'Yunshang Hanfu · Hanfu Atelier';
    private const SITE_NAME_AR = 'يونشانغ هانفو · مشغل الهانفو';
    private const SHARE_IMAGE = '/pub/media/catalog/hanfu/r2/homepage/taoyuan-qingmeng.webp';
    private const SHARE_IMAGE_ALT_ZH = '桃园清梦米白粉色明制上衣与马面裙套装';
    private const SHARE_IMAGE_ALT_EN = 'Peach Garden Dream ivory-and-pink Ming-style top and mamian set';
    /** @var list<string> */
    private const SAME_AS = [
        'https://www.instagram.com/yunshang.hanfu',
        'https://www.pinterest.com/yunshanghanfu',
        'https://www.tiktok.com/@yunshanghanfu',
        'https://www.youtube.com/@yunshanghanfu',
    ];


    /** @return array{name:string,sameAs:list<string>} */
    private function org(string $name): array
    {
        return [
            'name' => $name,
            'sameAs' => self::SAME_AS,
        ];
    }

    public function testChineseRootReceivesChineseHomepageDefaults(): void
    {
        self::assertSame(
            [
                'site_name' => self::SITE_NAME_ZH,
                'organization' => $this->org(self::SITE_NAME_ZH),
                'page_type' => 'home',
                'title' => self::TITLE_ZH,
                'description' => self::DESCRIPTION_ZH,
                'image' => self::SHARE_IMAGE,
                'image_alt' => self::SHARE_IMAGE_ALT_ZH,
            ],
            $this->provide()
        );
    }

    public function testEnglishLocaleRootReceivesEnglishHomepageDefaults(): void
    {
        self::assertSame(
            [
                'site_name' => self::SITE_NAME_EN,
                'organization' => $this->org(self::SITE_NAME_EN),
                'page_type' => 'home',
                'title' => self::TITLE_EN,
                'description' => self::DESCRIPTION_EN,
                'image' => self::SHARE_IMAGE,
                'image_alt' => self::SHARE_IMAGE_ALT_EN,
            ],
            $this->provide([
                'canonical_url' => 'https://p05113ef3.test.weline.com:9555/en_US',
                'url' => 'https://p05113ef3.test.weline.com:9555/en_US',
                'locale' => 'zh_Hans_CN',
            ])
        );
    }

    public function testArabicLocaleRootUsesArabicCatalogCopy(): void
    {
        $profile = $this->provide([
            'canonical_url' => 'https://p05113ef3.test.weline.com:9555/ar_SA/',
            'url' => 'https://p05113ef3.test.weline.com:9555/ar_SA/',
            'locale' => 'ar_SA',
        ]);
        self::assertSame(self::SITE_NAME_AR, $profile['site_name']);
        self::assertSame($this->org(self::SITE_NAME_AR), $profile['organization']);
        self::assertSame('home', $profile['page_type']);
        self::assertSame(self::TITLE_AR, $profile['title']);
        self::assertSame(self::DESCRIPTION_AR, $profile['description']);
        self::assertSame(self::SHARE_IMAGE, $profile['image']);
        self::assertNotSame('', trim((string)$profile['image_alt']));
        self::assertNotSame(self::SHARE_IMAGE_ALT_ZH, $profile['image_alt']);
    }

    public function testNonHomepageReplacesFrameworkBrandInGenericSeoOnly(): void
    {
        self::assertSame(
            [
                'site_name' => self::SITE_NAME_ZH,
                'organization' => $this->org(self::SITE_NAME_ZH),
                'description' => '分类 | 分类页面布局 - ' . self::SITE_NAME_ZH,
            ],
            $this->provide([
                'canonical_url' => 'https://p05113ef3.test.weline.com:9555/categories',
                'url' => 'https://p05113ef3.test.weline.com:9555/categories',
                'page_type' => 'category_list',
                'title' => '分类 | 分类页面布局',
                'description' => '分类 | 分类页面布局 - Weline Framework',
            ]),
        );
    }

    public function testChineseDefaultWebsitePlaceholderReceivesStorefrontBrand(): void
    {
        self::assertSame(
            [
                'site_name' => self::SITE_NAME_ZH,
                'organization' => $this->org(self::SITE_NAME_ZH),
                'description' => '关于我们 - ' . self::SITE_NAME_ZH,
            ],
            $this->provide([
                'site_name' => '默认网站',
                'canonical_url' => 'https://p05113ef3.test.weline.com:9555/about',
                'url' => 'https://p05113ef3.test.weline.com:9555/about',
                'page_type' => 'page',
                'title' => '关于我们',
                'description' => '关于我们 - Weline Framework',
            ]),
        );
    }

    public function testEnglishDefaultWebsitePlaceholderReceivesStorefrontBrand(): void
    {
        self::assertSame(
            [
                'site_name' => self::SITE_NAME_EN,
                'organization' => $this->org(self::SITE_NAME_EN),
                'description' => 'About us - ' . self::SITE_NAME_EN,
            ],
            $this->provide([
                'site_name' => 'Default Website',
                'canonical_url' => 'https://p05113ef3.test.weline.com:9555/en_US/about',
                'url' => 'https://p05113ef3.test.weline.com:9555/en_US/about',
                'locale' => 'en_US',
                'page_type' => 'page',
                'title' => 'About us',
                'description' => 'About us - Weline Framework',
            ]),
        );
    }

    public function testConcatenatedDefaultWebsiteStorePlaceholderReceivesStorefrontBrand(): void
    {
        self::assertSame(
            [
                'site_name' => self::SITE_NAME_ZH,
                'organization' => $this->org(self::SITE_NAME_ZH),
                'description' => '关于我们 - ' . self::SITE_NAME_ZH,
            ],
            $this->provide([
                'site_name' => '默认网站 默认店铺',
                'canonical_url' => 'https://p05113ef3.test.weline.com:9555/about',
                'url' => 'https://p05113ef3.test.weline.com:9555/about',
                'page_type' => 'page',
                'title' => '关于我们',
                'description' => '关于我们 - Weline Framework',
            ]),
        );
    }

    public function testBodySlotIsNotChanged(): void
    {
        self::assertSame([], $this->provide(['_slot' => 'body']));
    }

    public function testMerchantCustomSeoIsPreserved(): void
    {
        self::assertSame(
            [
                'site_name' => self::SITE_NAME_ZH,
                'organization' => $this->org(self::SITE_NAME_ZH),
                'page_type' => 'home',
                'image' => self::SHARE_IMAGE,
                'image_alt' => self::SHARE_IMAGE_ALT_ZH,
            ],
            $this->provide([
                'title' => 'Custom campaign title',
                'description' => 'Custom campaign description',
            ]),
        );
    }

    public function testMerchantSiteNameAndSeoArePreserved(): void
    {
        self::assertSame(
            [
                'page_type' => 'home',
                'image' => self::SHARE_IMAGE,
                'image_alt' => self::SHARE_IMAGE_ALT_ZH,
            ],
            $this->provide([
                'site_name' => 'Maison Hanfu',
                'title' => 'Custom campaign title',
                'description' => 'Custom campaign description',
            ]),
        );
    }

    public function testOnlySystemDefaultFieldIsReplaced(): void
    {
        self::assertSame(
            [
                'site_name' => self::SITE_NAME_EN,
                'organization' => $this->org(self::SITE_NAME_EN),
                'page_type' => 'home',
                'description' => self::DESCRIPTION_EN,
                'image' => self::SHARE_IMAGE,
                'image_alt' => self::SHARE_IMAGE_ALT_EN,
            ],
            $this->provide([
                'canonical_url' => 'https://p05113ef3.test.weline.com:9555/en_US',
                'url' => 'https://p05113ef3.test.weline.com:9555/en_US',
                'locale' => 'en_US',
                'title' => 'Merchant-authored title',
                'description' => 'Merchant-authored title - Weline Framework',
            ])
        );
    }

    public function testMerchantShareImageIsPreserved(): void
    {
        $profile = $this->provide([
            'image' => '/pub/media/custom-share.webp',
            'image_alt' => 'Custom share',
            'title' => 'Custom campaign title',
            'description' => 'Custom campaign description',
        ]);

        self::assertArrayNotHasKey('image', $profile);
        self::assertArrayNotHasKey('image_alt', $profile);
        self::assertSame('home', $profile['page_type']);
    }

    public function testSiteNameFrameworkDescriptionIsTreatedAsPlaceholder(): void
    {
        $profile = $this->provide([
            'description' => self::SITE_NAME_ZH . ' - Weline Framework',
        ]);

        self::assertSame(self::DESCRIPTION_ZH, $profile['description'] ?? null);
    }


    public function testCurrencyPrefixedLocaleHomepageReceivesShareImage(): void
    {
        $profile = $this->provide([
            'canonical_url' => 'https://p05113ef3.test.weline.com:9555/USD/en_US',
            'url' => 'https://p05113ef3.test.weline.com:9555/USD/en_US',
            'locale' => 'zh_Hans_CN',
            'title' => 'Home',
            'description' => 'Home - Weline Framework',
            'image' => '',
        ]);

        self::assertSame('home', $profile['page_type'] ?? null);
        self::assertSame(self::SHARE_IMAGE, $profile['image'] ?? null);
        self::assertSame(self::SHARE_IMAGE_ALT_EN, $profile['image_alt'] ?? null);
        self::assertSame(self::TITLE_EN, $profile['title'] ?? null);
    }

    public function testYunshangMerchantBrandReceivesSameAsOnListingPages(): void
    {
        $profile = $this->provide([
            'site_name' => self::SITE_NAME_ZH,
            'canonical_url' => 'https://p05113ef3.test.weline.com:9555/products/',
            'url' => 'https://p05113ef3.test.weline.com:9555/products/',
            'page_type' => 'product_list',
            'title' => '全部产品',
            'description' => '全部产品列表',
        ]);
        self::assertSame(['organization' => ['sameAs' => self::SAME_AS]], $profile);
    }

    public function testMerchantAuthoredSameAsIsPreserved(): void
    {
        $profile = $this->provide([
            'site_name' => self::SITE_NAME_ZH,
            'organization' => [
                'name' => self::SITE_NAME_ZH,
                'sameAs' => ['https://www.xiaohongshu.com/user/yunshang'],
            ],
            'canonical_url' => 'https://p05113ef3.test.weline.com:9555/products/',
            'url' => 'https://p05113ef3.test.weline.com:9555/products/',
            'page_type' => 'product_list',
            'title' => '全部产品',
            'description' => '全部产品列表',
        ]);
        self::assertSame([], $profile);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function provide(array $overrides = []): array
    {
        $context = array_replace([
            '_slot' => 'head',
            'page_type' => 'homepage',
            'site_name' => 'Weline Framework',
            'canonical_url' => 'https://p05113ef3.test.weline.com:9555/',
            'url' => 'https://p05113ef3.test.weline.com:9555/',
            'locale' => 'zh_Hans_CN',
            'title' => self::TITLE_ZH,
            'description' => self::TITLE_ZH . ' - Weline Framework',
        ], $overrides);

        $resolver = $this->createMock(TranslationResolverInterface::class);
        $resolver->method('translate')->willReturnCallback(
            static function (string $source, string $locale): string {
                if ($locale !== 'ar_SA') {
                    return $source;
                }

                return match ($source) {
                    self::TITLE_ZH => self::TITLE_AR,
                    self::DESCRIPTION_ZH => self::DESCRIPTION_AR,
                    self::SITE_NAME_ZH => self::SITE_NAME_AR,
                    default => $source,
                };
            }
        );

        return (new HanfuHomepageSeoProfileProvider($resolver))->provideSeoProfile(null, $context);
    }
}
