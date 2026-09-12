<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\StorefrontCategoryPublicFilter;

final class StorefrontCategoryPublicFilterTest extends TestCase
{
    public function testHidesLegacyShellOrganizationPaths(): void
    {
        self::assertTrue(StorefrontCategoryPublicFilter::isShellOrganizationPath('sourcing'));
        self::assertTrue(StorefrontCategoryPublicFilter::isShellOrganizationPath('/sourcing/cj'));
        self::assertFalse(StorefrontCategoryPublicFilter::isShellOrganizationPath('sourcing/cj/home-storage'));
        self::assertFalse(StorefrontCategoryPublicFilter::isShellOrganizationPath('home-storage'));
    }

    public function testHidesResellerBrandLabels(): void
    {
        self::assertTrue(StorefrontCategoryPublicFilter::isResellerBrandLabel('货源商城'));
        self::assertTrue(StorefrontCategoryPublicFilter::isResellerBrandLabel('CJ货源'));
        self::assertFalse(StorefrontCategoryPublicFilter::isResellerBrandLabel('家居收纳'));
    }
}
