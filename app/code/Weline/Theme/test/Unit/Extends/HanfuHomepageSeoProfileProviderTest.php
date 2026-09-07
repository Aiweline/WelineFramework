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
    private const DESCRIPTION_ZH = '云裳汉服水墨中国风独立站，精选明制、宋制、唐制汉服、马面裙与传统配饰，服务日常、节庆与礼仪场景。';
    private const DESCRIPTION_EN = 'Discover Ming, Song, and Tang dynasty Hanfu, mamian skirts, and traditional accessories for everyday wear, festivals, and ceremonies.';
    private const TITLE_AR = 'يونشانغ هانفو · مشغل الهانفو | متجر هانفو بأسلوب الحبر الصيني';
    private const DESCRIPTION_AR = 'اكتشف أزياء هانفو من عصور مينغ وسونغ وتانغ، وتنانير ماميان والإكسسوارات التقليدية للحياة اليومية والمهرجانات والمراسم.';
    private const SITE_NAME_ZH = '云裳汉服 · Hanfu Atelier';
    private const SITE_NAME_EN = 'Yunshang Hanfu · Hanfu Atelier';
    private const SITE_NAME_AR = 'يونشانغ هانفو · مشغل الهانفو';

    public function testChineseRootReceivesChineseHomepageDefaults(): void
    {
        self::assertSame(
            ['site_name' => self::SITE_NAME_ZH, 'title' => self::TITLE_ZH, 'description' => self::DESCRIPTION_ZH],
            $this->provide()
        );
    }

    public function testEnglishLocaleRootReceivesEnglishHomepageDefaults(): void
    {
        self::assertSame(
            ['site_name' => self::SITE_NAME_EN, 'title' => self::TITLE_EN, 'description' => self::DESCRIPTION_EN],
            $this->provide([
                'canonical_url' => 'https://p05113ef3.test.weline.com:9555/en_US',
                'url' => 'https://p05113ef3.test.weline.com:9555/en_US',
                'locale' => 'zh_Hans_CN',
            ])
        );
    }

    public function testArabicLocaleRootUsesArabicCatalogCopy(): void
    {
        self::assertSame(
            ['site_name' => self::SITE_NAME_AR, 'title' => self::TITLE_AR, 'description' => self::DESCRIPTION_AR],
            $this->provide([
                'canonical_url' => 'https://p05113ef3.test.weline.com:9555/ar_SA/',
                'url' => 'https://p05113ef3.test.weline.com:9555/ar_SA/',
                'locale' => 'ar_SA',
            ])
        );
    }

    public function testNonHomepageReplacesFrameworkBrandInGenericSeoOnly(): void
    {
        self::assertSame(
            [
                'site_name' => self::SITE_NAME_ZH,
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

    public function testBodySlotIsNotChanged(): void
    {
        self::assertSame([], $this->provide(['_slot' => 'body']));
    }

    public function testMerchantCustomSeoIsPreserved(): void
    {
        self::assertSame(
            ['site_name' => self::SITE_NAME_ZH],
            $this->provide([
                'title' => 'Custom campaign title',
                'description' => 'Custom campaign description',
            ]),
        );
    }

    public function testMerchantSiteNameAndSeoArePreserved(): void
    {
        self::assertSame([], $this->provide([
            'site_name' => 'Maison Hanfu',
            'title' => 'Custom campaign title',
            'description' => 'Custom campaign description',
        ]));
    }

    public function testOnlySystemDefaultFieldIsReplaced(): void
    {
        self::assertSame(
            ['site_name' => self::SITE_NAME_EN, 'description' => self::DESCRIPTION_EN],
            $this->provide([
                'canonical_url' => 'https://p05113ef3.test.weline.com:9555/en_US',
                'url' => 'https://p05113ef3.test.weline.com:9555/en_US',
                'locale' => 'en_US',
                'title' => 'Merchant-authored title',
                'description' => 'Merchant-authored title - Weline Framework',
            ])
        );
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
