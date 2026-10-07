<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\StorefrontOfferOriginCountryService;

final class StorefrontOfferOriginCountryServiceTest extends TestCase
{
    public function testNormalizeCountryCodeAcceptsIso2(): void
    {
        self::assertSame('CN', StorefrontOfferOriginCountryService::normalizeCountryCode('cn'));
        self::assertSame('US', StorefrontOfferOriginCountryService::normalizeCountryCode('US-CA'));
        self::assertSame('', StorefrontOfferOriginCountryService::normalizeCountryCode(''));
        self::assertSame('', StorefrontOfferOriginCountryService::normalizeCountryCode('1'));
    }
}
