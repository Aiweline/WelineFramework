<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PromotionStorefrontPageServiceOfferDeepLinkContractTest extends TestCase
{
    public function testNormalizeItemsDeepLinksPdpWithStorefrontOfferDetailQuery(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/PromotionStorefrontPageService.php';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString(
            'use Weline\\Product\\Helper\\StorefrontOfferDetailQuery;',
            $content,
        );
        self::assertStringContainsString('class_exists(StorefrontOfferDetailQuery::class)', $content);
        self::assertStringContainsString('StorefrontOfferDetailQuery::params($item)', $content);
        self::assertStringContainsString('http_build_query($detailQuery)', $content);
        self::assertStringContainsString(
            "\$url .= (str_contains(\$url, '?') ? '&' : '?') . http_build_query(\$detailQuery);",
            $content,
        );
    }
}
