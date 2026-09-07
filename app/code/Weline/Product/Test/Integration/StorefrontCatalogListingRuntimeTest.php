<?php

declare(strict_types=1);

namespace Weline\Product\Test\Integration;

use PHPUnit\Framework\TestCase;

/** Opt-in runtime regression for a catalog containing high-variant products. */
final class StorefrontCatalogListingRuntimeTest extends TestCase
{
    public function testCatalogListsImportedProductsBeyondTheFirstOfferWindow(): void
    {
        $baseUrl = rtrim((string)getenv('WELINE_STOREFRONT_BASE_URL'), '/');
        $expectedSlug = trim((string)getenv('WELINE_STOREFRONT_CATALOG_EXPECTED_SLUG'));
        $minimumProducts = (int)getenv('WELINE_STOREFRONT_CATALOG_MIN_PRODUCTS');
        if ($baseUrl === '' || $expectedSlug === '' || $minimumProducts <= 0) {
            self::markTestSkipped('Storefront catalog runtime fixture is not configured.');
        }

        $curl = curl_init($baseUrl . '/categories');
        self::assertNotFalse($curl);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_USERAGENT => 'Weline Product catalog-listing regression',
        ]);
        $html = curl_exec($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        self::assertIsString($html, $curlError);
        self::assertSame(200, $httpCode);
        preg_match_all('/data-testid="weline-product-card"[^>]*data-product-id="([1-9][0-9]*)"/s', $html, $matches);
        $productIds = array_values(array_unique(array_map('intval', $matches[1] ?? [])));

        self::assertGreaterThanOrEqual(
            $minimumProducts,
            count($productIds),
            'Catalog pagination must count products, not consume the window with variants from a few products.',
        );
        self::assertStringContainsString('/product/' . $expectedSlug, $html);
    }
}
