<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\AddressCountryProfileService;

final class AddressCountryProfileServiceTest extends TestCase
{
    public function testChinaIncludesDistrictLevel(): void
    {
        $service = new AddressCountryProfileService();
        $profile = $service->profileFor('CN');
        self::assertContains('district', $profile['levels']);
        self::assertTrue($profile['autocomplete']);
    }

    public function testDefaultStopsAtCity(): void
    {
        $service = new AddressCountryProfileService();
        $profile = $service->profileFor('ZZ');
        self::assertSame(['country', 'province', 'city'], $profile['levels']);
        self::assertNotContains('district', $profile['levels']);
    }

    public function testFranceIncludesDistrictLevel(): void
    {
        $service = new AddressCountryProfileService();
        $profile = $service->profileFor('FR');
        self::assertContains('district', $profile['levels']);
    }
}
