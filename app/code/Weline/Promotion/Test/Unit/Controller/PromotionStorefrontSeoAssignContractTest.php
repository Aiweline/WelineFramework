<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class PromotionStorefrontSeoAssignContractTest extends TestCase
{
    public function testPageServicePublishesSeoWithoutOverwritingUiPageType(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/PromotionStorefrontPageService.php';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('PromotionSeoFactsBuilder', $src);
        self::assertStringContainsString("\$pageData['seo'] = \$this->seoFacts()->buildListingProfile", $src);
        self::assertStringContainsString("'page_type' => \$pageType,", $src);
        self::assertStringNotContainsString("'page_type' => 'product_list'", $src);
    }

    public function testControllerAssignsSeoBag(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Index.php';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("assign('seo'", $src);
        self::assertStringContainsString("\$data['seo']", $src);
        self::assertStringNotContainsString("setGet('page_type', 'product_list')", $src);
    }

    public function testSeoExtendsExist(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists(
            $root . '/extends/module/Weline_Seo/SeoProfileProvider/PromotionSeoProfileProvider.php'
        );
        self::assertFileExists(
            $root . '/extends/module/Weline_Seo/SitemapUrlProvider/PromotionSitemapUrlProvider.php'
        );
        self::assertFileExists($root . '/Service/PromotionSeoFactsBuilder.php');
    }
}
