<?php

declare(strict_types=1);

namespace WeShop\Store\Test\Unit\Extends\Module\Weline_Framework\Query;

use PHPUnit\Framework\TestCase;
use WeShop\Store\Extends\Module\Weline_Framework\Query\StoreQueryProvider;
use WeShop\Store\Model\Store;
use WeShop\Store\Service\StoreContextService;

class StoreQueryProviderTest extends TestCase
{
    public function testExecuteReturnsCurrentStoreFromContextService(): void
    {
        $storeModel = $this->createMock(Store::class);

        $storeContextService = $this->createMock(StoreContextService::class);
        $storeContextService->expects($this->once())
            ->method('getCurrentStore')
            ->with(7, 'de_DE', 'EUR')
            ->willReturn([
                Store::schema_fields_ID => 12,
                Store::schema_fields_NAME => 'German Store',
                Store::schema_fields_CURRENCY => 'EUR',
            ]);

        $provider = new StoreQueryProvider($storeModel, $storeContextService);

        $result = $provider->execute('getCurrentStore', [
            'website_id' => 7,
            'locale' => 'de_DE',
            'currency' => 'EUR',
        ]);

        $this->assertSame(12, $result[Store::schema_fields_ID]);
        $this->assertSame('German Store', $result[Store::schema_fields_NAME]);
        $this->assertSame('EUR', $result[Store::schema_fields_CURRENCY]);
    }

    public function testExecuteReturnsStoreByIdAsNormalizedArray(): void
    {
        $store = $this->createMock(Store::class);
        $store->expects($this->once())
            ->method('load')
            ->with(5)
            ->willReturnSelf();
        $store->expects($this->exactly(2))
            ->method('getId')
            ->willReturn(5);
        $values = [
            Store::schema_fields_NAME => 'Flagship',
            Store::schema_fields_CODE => 'flagship',
            Store::schema_fields_WEBSITE_ID => 3,
            Store::schema_fields_STATUS => Store::STATUS_ENABLED,
            Store::schema_fields_DESCRIPTION => 'Primary store',
            Store::schema_fields_ADDRESS => 'Berlin',
            Store::schema_fields_META_TITLE => 'Flagship',
            Store::schema_fields_META_DESCRIPTION => 'Flagship description',
            Store::schema_fields_META_KEYWORDS => 'flagship',
            Store::schema_fields_LOCAL => 'de_DE',
            Store::schema_fields_CURRENCY => 'EUR',
            Store::schema_fields_LATITUDE => '52.5200',
            Store::schema_fields_LONGITUDE => '13.4050',
            Store::schema_fields_SORT_ORDER => 1,
        ];
        $store->expects($this->exactly(14))
            ->method('getData')
            ->willReturnCallback(static function (string $key, mixed $default = null) use ($values): mixed {
                return $values[$key] ?? $default;
            });

        $storeContextService = $this->createMock(StoreContextService::class);
        $storeContextService->expects($this->never())->method('getCurrentStore');

        $provider = new StoreQueryProvider($store, $storeContextService);

        $result = $provider->execute('getStoreById', ['store_id' => 5]);

        $this->assertSame(5, $result['store_id']);
        $this->assertSame('Flagship', $result['name']);
        $this->assertSame('EUR', $result['currency']);
    }
}
