<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Api\StorefrontDestinationOfferFilterInterface;
use Weline\Shipping\Api\StorefrontFulfillmentCountriesInterface;
use Weline\Shipping\Service\DestinationCountryReader;
use Weline\Shipping\Service\LocationSellConfig;
use Weline\Shipping\Service\LocationSellGate;
use Weline\Shipping\Service\StorefrontOfferOriginCountryService;

final class LocationSellGateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!\function_exists('__')) {
            eval('function __(string $text, mixed ...$args): string { return $text; }');
        }
    }

    public function testNormalizeViaOriginService(): void
    {
        self::assertSame('CN', StorefrontOfferOriginCountryService::normalizeCountryCode('cn'));
        self::assertSame('US', StorefrontOfferOriginCountryService::normalizeCountryCode('US-CA'));
        self::assertSame('', StorefrontOfferOriginCountryService::normalizeCountryCode(''));
    }

    public function testAssertCountryWritableRejectsEmptyWhenGateOn(): void
    {
        $gate = $this->makeGate(
            sellOnly: true,
            tradeGate: true,
            allowed: ['US', 'CN'],
        );
        $result = $gate->assertCountryWritable('');
        self::assertFalse($result['ok'] ?? true);
        self::assertSame(LocationSellGate::ERROR_ADDRESS_NOT_FULFILLABLE, $result['error_code'] ?? '');
    }

    public function testAssertCountryWritableAllowsListedCountry(): void
    {
        $gate = $this->makeGate(
            sellOnly: true,
            tradeGate: true,
            allowed: ['US', 'CN'],
        );
        $result = $gate->assertCountryWritable('us');
        self::assertTrue($result['ok'] ?? false);
    }

    public function testAssertCountryWritableBlocksCartLockMismatch(): void
    {
        $gate = $this->makeGate(
            sellOnly: false,
            tradeGate: true,
            allowed: [],
        );
        $result = $gate->assertCountryWritable('US', 'CN');
        self::assertFalse($result['ok'] ?? true);
        self::assertSame(LocationSellGate::ERROR_CHANGE_BLOCKED_CART_LOCK, $result['error_code'] ?? '');
    }

    public function testAssertCountryWritableEmptyAllowedSet(): void
    {
        $gate = $this->makeGate(
            sellOnly: true,
            tradeGate: true,
            allowed: [],
        );
        $result = $gate->assertCountryWritable('CN');
        self::assertFalse($result['ok'] ?? true);
        self::assertSame(LocationSellGate::ERROR_COUNTRIES_EMPTY, $result['error_code'] ?? '');
    }

    public function testAssertOfferAddableRequiresDeliveryCountry(): void
    {
        $gate = $this->makeGate(
            sellOnly: false,
            tradeGate: true,
            allowed: ['CN'],
            delivery: '',
        );
        $result = $gate->assertOfferAddable(10);
        self::assertFalse($result['ok'] ?? true);
        self::assertSame(LocationSellGate::ERROR_ADDRESS_NOT_FULFILLABLE, $result['error_code'] ?? '');
    }

    /**
     * @param list<string> $allowed
     */
    private function makeGate(
        bool $sellOnly,
        bool $tradeGate,
        array $allowed,
        string $delivery = 'CN',
    ): LocationSellGate {
        $config = new class ($sellOnly, $tradeGate) extends LocationSellConfig {
            public function __construct(
                private readonly bool $sellOnlyFlag,
                private readonly bool $tradeGateFlag,
            ) {
            }

            public function isLocationSellFilterEnabled(?string $storageScope = null): bool
            {
                return $this->tradeGateFlag && !$this->sellOnlyFlag;
            }

            public function isSellOnlyFulfillmentCountriesEnabled(?string $storageScope = null): bool
            {
                return $this->sellOnlyFlag;
            }

            public function isTradeGateActive(?string $storageScope = null): bool
            {
                return $this->tradeGateFlag || $this->sellOnlyFlag;
            }
        };

        $reader = $this->createStub(DestinationCountryReader::class);
        $reader->method('currentCountryCode')->willReturn($delivery);

        $origin = $this->createStub(StorefrontOfferOriginCountryService::class);
        $origin->method('resolveForOffer')->willReturn('CN');

        $filter = $this->createStub(StorefrontDestinationOfferFilterInterface::class);
        $filter->method('isOfferSellable')->willReturn(true);

        $countries = $this->createStub(StorefrontFulfillmentCountriesInterface::class);
        $countries->method('listCountryCodes')->willReturn($allowed);

        return new LocationSellGate($config, $reader, $origin, $filter, $countries);
    }
}
