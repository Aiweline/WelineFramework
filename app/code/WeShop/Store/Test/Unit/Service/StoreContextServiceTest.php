<?php

declare(strict_types=1);

namespace WeShop\Store\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Store\Model\Store;
use WeShop\Store\Service\StoreContextService;

class StoreContextServiceTest extends TestCase
{
    public function testGetCurrentStorePrefersWebsiteLocaleAndCurrencyMatch(): void
    {
        $storeModel = $this->createMock(Store::class);
        $storeModel->expects($this->once())
            ->method('getStoresByWebsiteId')
            ->with(7)
            ->willReturn([
                [
                    Store::schema_fields_WEBSITE_ID => 7,
                    Store::schema_fields_NAME => 'US Store',
                    Store::schema_fields_LOCAL => 'en_US',
                    Store::schema_fields_CURRENCY => 'USD',
                    Store::schema_fields_SORT_ORDER => 2,
                ],
                [
                    Store::schema_fields_WEBSITE_ID => 7,
                    Store::schema_fields_NAME => 'German Store',
                    Store::schema_fields_LOCAL => 'de_DE',
                    Store::schema_fields_CURRENCY => 'EUR',
                    Store::schema_fields_SORT_ORDER => 5,
                ],
            ]);
        $storeModel->expects($this->never())->method('getEnabledStores');

        $service = new class($storeModel) extends StoreContextService {
            protected function resolveWebsiteId(): int
            {
                return 7;
            }

            protected function resolveLocale(): string
            {
                return 'de_DE';
            }

            protected function resolveCurrency(): string
            {
                return 'EUR';
            }
        };

        $result = $service->getCurrentStore();

        $this->assertIsArray($result);
        $this->assertSame('German Store', $result[Store::schema_fields_NAME]);
    }

    public function testGetCurrentStoreFallsBackToEnabledStoresWhenWebsiteHasNoStores(): void
    {
        $storeModel = $this->createMock(Store::class);
        $storeModel->expects($this->once())
            ->method('getStoresByWebsiteId')
            ->with(9)
            ->willReturn([]);
        $storeModel->expects($this->once())
            ->method('getEnabledStores')
            ->willReturn([
                [
                    Store::schema_fields_WEBSITE_ID => 1,
                    Store::schema_fields_NAME => 'Global Default',
                    Store::schema_fields_CURRENCY => 'USD',
                    Store::schema_fields_SORT_ORDER => 10,
                ],
            ]);

        $service = new class($storeModel) extends StoreContextService {
            protected function resolveWebsiteId(): int
            {
                return 9;
            }

            protected function resolveLocale(): string
            {
                return 'en_US';
            }

            protected function resolveCurrency(): string
            {
                return 'USD';
            }
        };

        $result = $service->getCurrentStore();

        $this->assertIsArray($result);
        $this->assertSame('Global Default', $result[Store::schema_fields_NAME]);
    }

    public function testGetCurrentStoreFallsBackToLanguageMatchWhenRegionDiffers(): void
    {
        $storeModel = $this->createMock(Store::class);
        $storeModel->expects($this->once())
            ->method('getStoresByWebsiteId')
            ->with(3)
            ->willReturn([
                [
                    Store::schema_fields_WEBSITE_ID => 3,
                    Store::schema_fields_NAME => 'UK Store',
                    Store::schema_fields_LOCAL => 'en_GB',
                    Store::schema_fields_CURRENCY => 'GBP',
                    Store::schema_fields_SORT_ORDER => 10,
                ],
                [
                    Store::schema_fields_WEBSITE_ID => 3,
                    Store::schema_fields_NAME => 'French Store',
                    Store::schema_fields_LOCAL => 'fr_FR',
                    Store::schema_fields_CURRENCY => 'EUR',
                    Store::schema_fields_SORT_ORDER => 1,
                ],
            ]);

        $service = new class($storeModel) extends StoreContextService {
            protected function resolveWebsiteId(): int
            {
                return 3;
            }

            protected function resolveLocale(): string
            {
                return 'en_US';
            }

            protected function resolveCurrency(): string
            {
                return 'GBP';
            }
        };

        $result = $service->getCurrentStore();

        $this->assertIsArray($result);
        $this->assertSame('UK Store', $result[Store::schema_fields_NAME]);
    }
}
