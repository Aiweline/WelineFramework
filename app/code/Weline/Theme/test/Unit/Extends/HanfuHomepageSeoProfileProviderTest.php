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

    public function testChineseRootReceivesChineseHomepageDefaults(): void
    {
        self::assertSame(
            ['title' => self::TITLE_ZH, 'description' => self::DESCRIPTION_ZH],
            $this->provide()
        );
    }

    public function testEnglishLocaleRootReceivesEnglishHomepageDefaults(): void
    {
        self::assertSame(
            ['title' => self::TITLE_EN, 'description' => self::DESCRIPTION_EN],
            $this->provide([
                'canonical_url' => 'https://p05113ef3.weline.test:9555/en_US',
                'url' => 'https://p05113ef3.weline.test:9555/en_US',
                'locale' => 'zh_Hans_CN',
            ])
        );
    }

    public function testArabicLocaleRootUsesArabicCatalogCopy(): void
    {
        self::assertSame(
            ['title' => self::TITLE_AR, 'description' => self::DESCRIPTION_AR],
            $this->provide([
                'canonical_url' => 'https://p05113ef3.weline.test:9555/ar_SA/',
                'url' => 'https://p05113ef3.weline.test:9555/ar_SA/',
                'locale' => 'ar_SA',
            ])
        );
    }

    public function testNonHomepageIsNotChanged(): void
    {
        self::assertSame([], $this->provide([
            'canonical_url' => 'https://p05113ef3.weline.test:9555/categories',
            'url' => 'https://p05113ef3.weline.test:9555/categories',
            'page_type' => 'category_list',
        ]));
    }

    public function testBodySlotIsNotChanged(): void
    {
        self::assertSame([], $this->provide(['_slot' => 'body']));
    }

    public function testMerchantCustomSeoIsPreserved(): void
    {
        self::assertSame([], $this->provide([
            'title' => 'Custom campaign title',
            'description' => 'Custom campaign description',
        ]));
    }

    public function testOnlySystemDefaultFieldIsReplaced(): void
    {
        self::assertSame(
            ['description' => self::DESCRIPTION_EN],
            $this->provide([
                'canonical_url' => 'https://p05113ef3.weline.test:9555/en_US',
                'url' => 'https://p05113ef3.weline.test:9555/en_US',
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
            'canonical_url' => 'https://p05113ef3.weline.test:9555/',
            'url' => 'https://p05113ef3.weline.test:9555/',
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
                    default => $source,
                };
            }
        );

        return (new HanfuHomepageSeoProfileProvider($resolver))->provideSeoProfile(null, $context);
    }
}
