<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PromotionSeoFactsBuilderTest extends TestCase
{
    public function testSourceContractLocksProductListOwnership(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/PromotionSeoFactsBuilder.php');
        self::assertStringContainsString("'page_type' => 'products'", $src);
        self::assertStringContainsString("'promotion_page_slug'", $src);
        self::assertStringContainsString('itemListFromCards', $src);
        self::assertStringContainsString('withHomeBreadcrumb', $src);
        self::assertStringContainsString('itemListFromEntryCards', $src);
        self::assertStringContainsString("'robots' => 'index,follow'", $src);
        self::assertStringContainsString("'changefreq' => 'daily'", $src);
    }
}
