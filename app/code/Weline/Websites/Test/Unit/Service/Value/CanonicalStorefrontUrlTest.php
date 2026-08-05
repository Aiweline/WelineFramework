<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\Value;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\Value\CanonicalStorefrontUrl;

final class CanonicalStorefrontUrlTest extends TestCase
{
    public function testBareAndSingleWwwHostsShareOriginButSchemeAndPortRemainStrict(): void
    {
        $bare = CanonicalStorefrontUrl::fromStoreUrl('https://example.test/shop');
        $www = CanonicalStorefrontUrl::fromRequestUrl('https://www.example.test/shop/item');

        self::assertTrue($bare->sameOrigin($www));
        self::assertTrue(CanonicalStorefrontUrl::fromStoreUrl('https://www.example.test/shop')->sameOrigin(
            CanonicalStorefrontUrl::fromRequestUrl('https://example.test/shop'),
        ));
        self::assertFalse($bare->sameOrigin(
            CanonicalStorefrontUrl::fromRequestUrl('http://www.example.test/shop'),
        ));
        self::assertFalse($bare->sameOrigin(
            CanonicalStorefrontUrl::fromRequestUrl('https://www.example.test:8443/shop'),
        ));
        self::assertFalse($bare->sameOrigin(
            CanonicalStorefrontUrl::fromRequestUrl('https://www.www.example.test/shop'),
        ));
    }

    public function testPublicPathBoundaryHelperMatchesRootExactAndDescendantsOnly(): void
    {
        self::assertTrue(CanonicalStorefrontUrl::matchesPathSegmentBoundary('/', '/anything'));
        self::assertTrue(CanonicalStorefrontUrl::matchesPathSegmentBoundary('/shop/', '/shop'));
        self::assertTrue(CanonicalStorefrontUrl::matchesPathSegmentBoundary('/shop', '/shop/item/'));
        self::assertTrue(CanonicalStorefrontUrl::matchesPathSegmentBoundary('/%73hop', '/shop/item'));
        self::assertSame('/shop', CanonicalStorefrontUrl::canonicalPath('/%73hop/'));
        self::assertFalse(CanonicalStorefrontUrl::matchesPathSegmentBoundary('/shop', '/shopper'));
        self::assertFalse(CanonicalStorefrontUrl::matchesPathSegmentBoundary('/shop', '/shop-sale'));
    }

    public function testRequestQueryNeverParticipatesInStorePathMatching(): void
    {
        $store = CanonicalStorefrontUrl::fromStoreUrl('https://example.test/shop');
        $request = CanonicalStorefrontUrl::fromRequestUrl(
            'https://example.test/shop/item?__store=other&next=%2Foutside',
        );

        self::assertSame('/shop/item', $request->path);
        self::assertSame('https://example.test', $request->originString());
        self::assertTrue($store->matchesRequestPath($request));
    }

    public function testEncodedPathSeparatorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CanonicalStorefrontUrl::matchesPathSegmentBoundary('/shop', '/shop%2Fother');
    }
}
