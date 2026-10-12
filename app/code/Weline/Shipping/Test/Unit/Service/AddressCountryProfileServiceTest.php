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

    public function testUsProfileMergesSchemaStateAndZipLabels(): void
    {
        $service = new AddressCountryProfileService();
        $profile = $service->profileFor('US');
        self::assertSame('US', $profile['country_code']);
        self::assertNotContains('district', $profile['levels']);
        self::assertSame('State', $profile['labels']['province'] ?? null);
        self::assertSame('ZIP code', $profile['labels']['postal_code'] ?? null);
        self::assertSame('First name', $profile['labels']['first_name'] ?? null);
        self::assertSame('Last name', $profile['labels']['last_name'] ?? null);
        self::assertSame('split', $profile['name_mode'] ?? null);
        self::assertContains('province', $profile['field_order']);
        self::assertContains('postal_code', $profile['field_order']);
        self::assertNotContains('district', $profile['field_order']);
    }

    public function testCnProfileKeepsChineseSchemaLabels(): void
    {
        $service = new AddressCountryProfileService();
        $profile = $service->profileFor('CN');
        self::assertSame('省份', $profile['labels']['province'] ?? null);
        self::assertSame('邮政编码', $profile['labels']['postal_code'] ?? null);
    }
}
