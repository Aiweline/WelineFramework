<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;
use Weline\Dropship\Service\DropshipListingDraftService;
use Weline\Dropship\Service\DropshipSettings;
use Weline\Dropship\Service\DropshipWarehouseMapService;

/**
 * Draft admit rejects cross-provider snapshots without touching DB.
 */
final class DropshipListingDraftServiceTest extends TestCase
{
    public function testAdmitRejectsProviderMismatch(): void
    {
        $settings = $this->createMock(DropshipSettings::class);
        $settings->method('isPlatformEnabled')->willReturn(true);
        $map = $this->createMock(DropshipWarehouseMapService::class);
        $svc = new DropshipListingDraftService($settings, $map);

        $result = $svc->admit('fake', [
            DropshipCatalogSnapshot::fromArray([
                'provider_code' => 'cj',
                'external_spu' => 'X1',
                'title' => 'Bad',
                'origin_currency' => 'USD',
                'origin_price_minor' => 100,
                'qty' => 1,
                'shelf_status' => 'active',
            ]),
        ], ['country_code' => 'US']);

        self::assertFalse($result['ok']);
        self::assertSame('dropship_snapshot_provider_mismatch', $result['error'] ?? null);
    }

    public function testSnapshotCarriesCategoryFields(): void
    {
        $snap = DropshipCatalogSnapshot::fromArray([
            'provider_code' => 'fake',
            'external_spu' => 'S1',
            'title' => 'T',
            'origin_currency' => 'USD',
            'origin_price_minor' => 100,
            'qty' => 1,
            'shelf_status' => 'active',
            'category_id' => 'c1',
            'category_path' => 'A / B',
        ]);
        self::assertSame('c1', $snap->categoryId);
        self::assertSame('A / B', $snap->categoryPath);
        self::assertSame('c1', $snap->toArray()['category_id']);
    }

    public function testMapPullStateAndRemoveValidateProvider(): void
    {
        $settings = $this->createMock(DropshipSettings::class);
        $map = $this->createMock(DropshipWarehouseMapService::class);
        $svc = new DropshipListingDraftService($settings, $map);

        $empty = $svc->mapPullState('', ['A']);
        self::assertSame([], $empty);

        $remove = $svc->removeFromBasket('', ['A']);
        self::assertFalse($remove['ok']);
        self::assertSame('provider_code_required', $remove['error'] ?? null);

        $remove2 = $svc->removeFromBasket('fake', []);
        self::assertFalse($remove2['ok']);
        self::assertSame('spus_required', $remove2['error'] ?? null);
    }

    public function testPublishSelectedAcceptsDefaultWebsiteIdZero(): void
    {
        $settings = $this->createMock(DropshipSettings::class);
        $map = $this->createMock(DropshipWarehouseMapService::class);
        $svc = new DropshipListingDraftService($settings, $map);
        $publish = $this->createMock(\Weline\Dropship\Service\DropshipPublishService::class);

        $missingProvider = $svc->publishSelected('', [], [], ['website_id' => 0, 'store_id' => 0], $publish);
        self::assertFalse($missingProvider['ok']);
        self::assertSame(['provider_required'], $missingProvider['errors']);

        $missingScope = $svc->publishSelected('fake', [], [], [], $publish);
        self::assertFalse($missingScope['ok']);
        self::assertSame(['scope_required'], $missingScope['errors']);

        // website_id=0 is valid; empty listing/snapshots → no publish work, not a scope gate error
        $okGate = $svc->publishSelected('fake', [], [], ['website_id' => 0, 'store_id' => 0, 'channel' => 'default'], $publish);
        self::assertFalse($okGate['ok']);
        self::assertSame(0, $okGate['published']);
        self::assertSame([], $okGate['errors']);
        self::assertStringNotContainsString('scope_or_provider_required', implode(',', $okGate['errors']));
    }
}
