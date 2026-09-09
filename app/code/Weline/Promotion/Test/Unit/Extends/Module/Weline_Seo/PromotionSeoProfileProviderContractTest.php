<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Extends\Module\Weline_Seo;

use PHPUnit\Framework\TestCase;
use Weline\Promotion\Extends\Module\Weline_Seo\SeoProfileProvider\PromotionSeoProfileProvider;
use Weline\Seo\Interface\SeoProfileProviderInterface;

final class PromotionSeoProfileProviderContractTest extends TestCase
{
    public function testProviderFileAndInterfaceContract(): void
    {
        $root = dirname(__DIR__, 5);
        $path = $root . '/extends/module/Weline_Seo/SeoProfileProvider/PromotionSeoProfileProvider.php';
        self::assertFileExists($path);
        self::assertFileExists(
            $root . '/extends/module/Weline_Seo/SitemapUrlProvider/PromotionSitemapUrlProvider.php'
        );

        $src = (string)file_get_contents($path);
        self::assertStringContainsString('implements SeoProfileProviderInterface', $src);
        self::assertStringContainsString("\$slot !== 'head'", $src);
        self::assertStringContainsString('claimsPromotion', $src);
        self::assertStringContainsString('product_list', $src);
        self::assertStringContainsString('promotion/', $src);

        self::assertTrue(is_a(PromotionSeoProfileProvider::class, SeoProfileProviderInterface::class, true));
    }
}
