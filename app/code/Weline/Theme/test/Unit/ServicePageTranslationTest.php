<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\I18n\Service\TranslationResolver;

final class ServicePageTranslationTest extends TestCase
{
    #[DataProvider('pageLabels')]
    public function testServicePageLabelsResolveInTheRequestedLocale(string $source, string $english): void
    {
        $dictionary = $this->createStub(DictionaryRepositoryInterface::class);
        $resolver = new TranslationResolver(dictionaryRepository: $dictionary);

        self::assertSame($english, $resolver->translate($source, 'en_US', ['Weline_Theme']));
        self::assertSame($source, $resolver->translate($source, 'zh_Hans_CN', ['Weline_Theme']));
    }

    public static function pageLabels(): array
    {
        return [
            'help topics' => ['热门帮助主题', 'Popular help topics'],
            'shipping and returns layout' => ['指南文档布局', 'Guide Page'],
            'payment layout' => ['支付方式指南布局', 'Payment Guide Page'],
            'promotions layout' => ['促销活动默认布局', 'Promotions Page'],
            'default storefront layout' => ['Weline UI 2.0 前台默认布局', 'Default Storefront Layout'],
            'terms layout' => ['服务条款页面布局', 'Terms of Service Page'],
            'privacy layout' => ['隐私政策页面', 'Privacy Policy Page'],
            'cookie layout' => ['Cookie 政策页面', 'Cookie Policy Page'],
        ];
    }
}
