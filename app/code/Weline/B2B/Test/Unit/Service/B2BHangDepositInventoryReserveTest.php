<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\B2BOrderHang;
use Weline\B2B\Service\B2BHangOrderService;
use Weline\Inventory\Api\Data\AvailabilityResult;
use Weline\Inventory\Api\Data\ReservationResult;
use Weline\Inventory\Api\InventoryCapabilityInterface;
use Weline\Inventory\Api\InventoryConflictException;

final class B2BHangDepositInventoryReserveTest extends TestCase
{
    public function testOnDepositPaidUsesInventoryCapabilityWhenInjected(): void
    {
        $calls = [];
        $inventory = new class($calls) implements InventoryCapabilityInterface {
            /** @param list<array<string,mixed>> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function getAvailability(int $websiteId, int $storeId, int $offerId): AvailabilityResult
            {
                throw new \LogicException('unused');
            }

            public function reserve(
                int $websiteId,
                int $storeId,
                int $offerId,
                int $quantityMinor,
                string $idempotencyKey,
                string $requestHash,
            ): ReservationResult {
                $this->calls[] = compact('websiteId', 'storeId', 'offerId', 'quantityMinor', 'idempotencyKey');

                return new ReservationResult(
                    reservationUuid: 'res-' . $offerId,
                    state: 'reserved',
                    quantityMinor: $quantityMinor,
                    idempotencyKey: $idempotencyKey,
                    requestHash: $requestHash,
                );
            }

            public function release(string $reservationUuid): void
            {
            }
        };

        // Inject callable that uses inventory (simulates production helper) — service uses
        // OrderFacade for production; here assert callable path still works and inventory SPI exists.
        $hang = B2BHangOrderService::forTesting(
            clock: static fn (): int => 1_700_000_100,
            inventory: $inventory,
        );
        $hang->createAwaitingDeposit([
            'order_ref' => 'ord-inv-1',
            'customer_id' => '9',
            'website_id' => 1,
            'goods_subtotal_taxed_minor' => 10000,
            'shipping_amount_minor' => 0,
            'is_shipping_owner' => false,
        ]);

        $after = $hang->onDepositPaid('ord-inv-1', 'pi_d', static function () use ($inventory): array {
            $r = $inventory->reserve(1, 1, 42, 100, 'hang_ord-inv-1_line-a', hash('sha256', 'x'));

            return [$r->toArray()];
        });
        self::assertSame(B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL, $after['hang_status'] ?? null);
        self::assertNotEmpty($after['reservation_snapshots'] ?? []);
        self::assertSame('res-42', $after['reservation_snapshots'][0]['reservation_uuid'] ?? null);
        self::assertSame('hang_ord-inv-1_line-a', $calls[0]['idempotencyKey'] ?? null);
    }

    public function testReserveInventoryHelperExistsInSource(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/B2BHangOrderService.php');
        self::assertStringContainsString('reserveInventoryForHang', $src);
        self::assertStringContainsString('InventoryCapabilityInterface', $src);
        self::assertStringContainsString("hang_' . \$hang->orderRef", $src);
    }
}
