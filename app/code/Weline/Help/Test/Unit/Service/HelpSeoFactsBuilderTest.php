<?php

declare(strict_types=1);

namespace Weline\Help\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class HelpSeoFactsBuilderTest extends TestCase
{
    public function testHubProfileContractIncludesFaqsAndFaqPageType(): void
    {
        $facts = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/HelpSeoFactsBuilder.php');
        $hub = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/HelpHubContent.php');
        self::assertStringContainsString("'page_type' => 'faq'", $facts);
        self::assertStringContainsString("'page_type' => 'help_article'", $facts);
        self::assertStringContainsString("'image'", $facts);
        self::assertStringContainsString("'image_alt'", $facts);
        self::assertStringContainsString('yunshang-logo', $facts);
        self::assertStringContainsString('seoFaqs', $facts);
        self::assertStringContainsString("'question'", $hub);
        self::assertStringContainsString("'answer'", $hub);
        self::assertGreaterThanOrEqual(8, substr_count($hub, "'q' =>"));
        self::assertStringContainsString('guide/shipping', $hub);
        self::assertStringContainsString("'url' => 'privacy'", $hub);
        self::assertStringContainsString('支持中英文浏览', $facts);
        self::assertGreaterThanOrEqual(50, mb_strlen('查找订单进度、物流配送、退换货、支付发票与账户问题的自助指南，快速解决汉服购物常见疑问，支持中英文浏览。'));
    }

    public function testSeoProfileProviderAndPageKindExist(): void
    {
        self::assertFileExists(dirname(__DIR__, 3) . '/extends/module/Weline_Seo/SeoProfileProvider/HelpSeoProfileProvider.php');
        self::assertFileExists(dirname(__DIR__, 3) . '/extends/module/Weline_Cms/PageKind/HelpPageKindProvider.php');
        $kind = (string)file_get_contents(dirname(__DIR__, 3) . '/extends/module/Weline_Cms/PageKind/HelpPageKindProvider.php');
        self::assertStringContainsString("return 'help'", $kind);
        self::assertStringContainsString('PAGE_TYPE_FAQ', $kind);
    }
}
