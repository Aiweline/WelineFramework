<?php

declare(strict_types=1);

namespace Weline\Product\Test\Integration;

use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in runtime regression for PDP slugs whose Offers sit beyond the
 * storefront catalog listing window.
 */
final class StorefrontCatalogDeepSlugRuntimeTest extends TestCase
{
    /** @throws JsonException */
    public function testDeepPublishedProductSlugReturnsItsCompleteVariantCatalog(): void
    {
        $baseUrl = rtrim((string)getenv('WELINE_STOREFRONT_BASE_URL'), '/');
        $slug = trim((string)getenv('WELINE_STOREFRONT_DEEP_SLUG'));
        $productId = (int)getenv('WELINE_STOREFRONT_DEEP_PRODUCT_ID');
        $expectedOffers = (int)getenv('WELINE_STOREFRONT_DEEP_EXPECTED_OFFERS');
        if ($baseUrl === '' || $slug === '' || $productId <= 0 || $expectedOffers <= 0) {
            self::markTestSkipped('Deep storefront slug runtime fixture is not configured.');
        }

        $curl = curl_init($baseUrl . '/product/' . rawurlencode($slug));
        self::assertNotFalse($curl);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_USERAGENT => 'Weline Product deep-slug regression',
        ]);
        $html = curl_exec($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        self::assertIsString($html, $curlError);
        self::assertSame(200, $httpCode, 'A published deep product slug must not redirect to /products/.');
        self::assertSame(1, preg_match(
            '/<script[^>]+id="product-variant-catalog-' . $productId . '"[^>]*>(.*?)<\/script>/s',
            $html,
            $match,
        ));
        $catalog = json_decode(trim((string)$match[1]), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['color', 'size'], array_column($catalog['axes'] ?? [], 'code'));
        self::assertCount($expectedOffers, $catalog['offers'] ?? []);
    }
}
