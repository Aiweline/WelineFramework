<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Extends;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Api\Translation\TranslationResolverInterface;
use Weline\Theme\Extends\Module\Weline_Seo\SeoProfileProvider\HanfuHomepageSeoProfileProvider;
use Weline\Theme\Helper\SiteBrand;
use Weline\Websites\Service\WebsiteBrandIdentitySeedService;

final class HanfuHomepageSeoProfileProviderTest extends TestCase
{
    private const PAGE_LABEL_ZH = '首页';
    private const PAGE_LABEL_EN = 'Home';
    private const SITE_NAME_ZH = WebsiteBrandIdentitySeedService::SEED_NAME;
    private const SITE_NAME_EN = "Chang'an Hanfu · Hanfu Atelier";
    private const SITE_NAME_AR = 'تشانغآن هانفو · مشغل الهانفو';
    private const TITLE_ZH = self::SITE_NAME_ZH . ' | ' . self::PAGE_LABEL_ZH;
    private const TITLE_EN = self::SITE_NAME_EN . ' | ' . self::PAGE_LABEL_EN;
    private const TITLE_AR = self::SITE_NAME_AR . ' | ' . self::PAGE_LABEL_ZH;
    private const DESCRIPTION_ZH = WebsiteBrandIdentitySeedService::SEED_DESCRIPTION;
    private const DESCRIPTION_EN = 'Discover Ming, Song, and Tang dynasty Hanfu, mamian skirts, and traditional accessories for everyday wear, festivals, and ceremonies.';
    private const DESCRIPTION_AR = 'اكتشف أزياء هانفو من عصور مينغ وسونغ وتانغ، وتنانير ماميان والإكسسوارات التقليدية للحياة اليومية والمهرجانات والمراسم.';

    /** @return array{name:string} */
    private function org(string $name): array
    {
        return ['name' => $name];
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
            ],
            $this->provide([
                'canonical_url' => 'https://p05113ef3.test.weline.com:9555/en_US',
                'url' => 'https://p05113ef3.test.weline.com:9555/en_US',
                'locale' => 'zh_Hans_CN',
            ])
        );
    }

    public function testArabicLocaleRootUsesTranslatedPageLabelAndDescription(): void
    {
        $profile = $this->provide([
            'canonical_url' => 'https://p05113ef3.test.weline.com:9555/ar_SA/',
            'url' => 'https://p05113ef3.test.weline.com:9555/ar_SA/',
            'locale' => 'ar_SA',
        ]);
        self::assertSame(self::SITE_NAME_AR, $profile['site_name']);
        self::assertSame($this->org(self::SITE_NAME_AR), $profile['organization']);
        self::assertSame('home', $profile['page_type']);
        self::assertSame(self::SITE_NAME_AR . ' | الصفحة الرئيسية', $profile['title']);
        self::assertSame(self::DESCRIPTION_AR, $profile['description']);
        self::assertArrayNotHasKey('image', $profile);
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

    public function testMerchantCustomSeoIsPreservedWithoutInjectedShareAssets(): void
    {
        self::assertSame(
            [
                'site_name' => self::SITE_NAME_ZH,
                'organization' => $this->org(self::SITE_NAME_ZH),
                'page_type' => 'home',
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

    public function testMerchantShareImageIsLeftUntouched(): void
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

    public function testCurrencyPrefixedLocaleHomepageReceivesNeutralTitle(): void
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
        self::assertArrayNotHasKey('image', $profile);
        self::assertSame(self::TITLE_EN, $profile['title'] ?? null);
    }

    public function testListingPagesDoNotInjectHardcodedSameAs(): void
    {
        $profile = $this->provide([
            'site_name' => self::SITE_NAME_ZH,
            'canonical_url' => 'https://p05113ef3.test.weline.com:9555/products/',
            'url' => 'https://p05113ef3.test.weline.com:9555/products/',
            'page_type' => 'products',
            'title' => '全部产品',
            'description' => '全部产品列表',
        ]);
        self::assertSame([], $profile);
    }

    public function testMerchantAuthoredSameAsIsLeftUntouched(): void
    {
        $profile = $this->provide([
            'site_name' => self::SITE_NAME_ZH,
            'organization' => [
                'name' => self::SITE_NAME_ZH,
                'sameAs' => ['https://www.xiaohongshu.com/user/changan'],
            ],
            'canonical_url' => 'https://p05113ef3.test.weline.com:9555/products/',
            'url' => 'https://p05113ef3.test.weline.com:9555/products/',
            'page_type' => 'products',
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
                    self::PAGE_LABEL_ZH => 'الصفحة الرئيسية',
                    self::DESCRIPTION_ZH => self::DESCRIPTION_AR,
                    self::SITE_NAME_ZH => self::SITE_NAME_AR,
                    default => $source,
                };
            }
        );

        $locale = strtolower(str_replace('_', '-', (string)($context['locale'] ?? '')));
        $url = (string)($context['canonical_url'] ?? $context['url'] ?? '');
        if (preg_match('#/(?:[A-Z]{3}/)?([a-z]{2}(?:[-_][a-z]{2,4}){1,2})(?:/|$)#i', $url, $m) === 1) {
            $locale = strtolower(str_replace('_', '-', $m[1]));
        }
        $isChinese = $locale === '' || str_starts_with($locale, 'zh');
        $isArabic = str_starts_with($locale, 'ar');
        $siteBrand = $this->createMock(SiteBrand::class);
        $siteBrand->method('resolveFrontendSiteName')->willReturnCallback(
            static function (string $configured = '') use ($isArabic, $isChinese): string {
                $configured = trim($configured);
                if ($configured !== ''
                    && !in_array($configured, [
                        '',
                        'Weline',
                        'Weline Framework',
                        '韦林',
                        '默认网站',
                        '默认网站 默认店铺',
                        'Default Website',
                        'Default Website Default Store',
                    ], true)
                ) {
                    return $configured;
                }

                return $isArabic ? self::SITE_NAME_AR : ($isChinese ? self::SITE_NAME_ZH : self::SITE_NAME_EN);
            }
        );
        $siteBrand->method('resolveFrontendSiteDescription')->willReturn(
            $isArabic ? self::DESCRIPTION_AR : ($isChinese ? self::DESCRIPTION_ZH : self::DESCRIPTION_EN)
        );

        return (new HanfuHomepageSeoProfileProvider($resolver, $siteBrand))->provideSeoProfile(null, $context);
    }
}
