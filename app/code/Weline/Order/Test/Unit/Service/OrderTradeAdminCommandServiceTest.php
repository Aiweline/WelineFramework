<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\OrderRefundCoordinator;
use Weline\Order\Service\OrderTradeAdminCommandException;
use Weline\Order\Service\OrderTradeAdminCommandService;

final class OrderTradeAdminCommandServiceTest extends TestCase
{
    public function testShipmentCommandUsesStableServerHashAndPreservesReplay(): void
    {
        $seen = [];
        $service = new OrderTradeAdminCommandService(
            shipmentCommand: static function (
                string $unitUuid,
                int $qty,
                int $version,
                string $idempotencyKey,
                string $requestHash,
            ) use (&$seen): array {
                $seen[] = compact(
                    'unitUuid',
                    'qty',
                    'version',
                    'idempotencyKey',
                    'requestHash',
                );

                return [
                    'ok' => true,
                    'replayed' => count($seen) > 1,
                    'fulfilled_qty_minor' => 1,
                ];
            },
        );
        $uuid = '00000000-0000-4000-8000-000000000101';
        $first = $service->ship($uuid, 1, 0, 'ship-idem-101');
        $replay = $service->ship($uuid, 1, 0, 'ship-idem-101');

        self::assertFalse($first['replayed']);
        self::assertTrue($replay['replayed']);
        self::assertSame($seen[0]['requestHash'], $seen[1]['requestHash']);
        self::assertSame($first['request_hash'], $replay['request_hash']);
        self::assertSame(64, strlen($first['request_hash']));
    }

    public function testRefundCommandForwardsOnlyCanonicalItemQuantity(): void
    {
        $seen = [];
        $service = new OrderTradeAdminCommandService(
            refundCommand: static function (
                string $orderUuid,
                string $idempotencyKey,
                int $clientHint,
                array $items,
                int $shippingMinor,
                string $reason,
            ) use (&$seen): array {
                $seen = compact(
                    'orderUuid',
                    'idempotencyKey',
                    'clientHint',
                    'items',
                    'shippingMinor',
                    'reason',
                );

                return [
                    'ok' => true,
                    'error_code' => null,
                    'case' => ['refund_case_uuid' => 'case-101'],
                    'payment' => ['status' => 'requested'],
                    'replayed' => false,
                ];
            },
        );
        $service->refund(
            '00000000-0000-4000-8000-000000000201',
            '00000000-0000-4000-8000-000000000202',
            2,
            0,
            ' browser reason ',
            'refund-idem-101',
        );

        self::assertSame(0, $seen['clientHint']);
        self::assertSame([[
            'item_uuid' => '00000000-0000-4000-8000-000000000202',
            'qty_minor' => 2,
        ]], $seen['items']);
        self::assertSame('browser reason', $seen['reason']);
    }

    public function testRefundFailureAndInvalidIdentityFailClosed(): void
    {
        $service = new OrderTradeAdminCommandService(
            refundCommand: static fn(): array => [
                'ok' => false,
                'error_code' => OrderRefundCoordinator::ERROR_AMOUNT_EXCEEDS,
                'case' => null,
                'payment' => null,
            ],
        );

        try {
            $service->refund(
                '00000000-0000-4000-8000-000000000301',
                '00000000-0000-4000-8000-000000000302',
                1,
                0,
                'reason',
                'refund-idem-102',
            );
            self::fail('Domain rejection must not be converted into success.');
        } catch (OrderTradeAdminCommandException $exception) {
            self::assertSame(OrderRefundCoordinator::ERROR_AMOUNT_EXCEEDS, $exception->errorCode());
        }

        $this->expectException(OrderTradeAdminCommandException::class);
        $service->invoice('../unsafe outbox');
    }

    public function testInvoiceCommandProcessesTheExactOutboxIdentity(): void
    {
        $seen = '';
        $service = new OrderTradeAdminCommandService(
            invoiceCommand: static function (string $outboxCode) use (&$seen): array {
                $seen = $outboxCode;

                return ['ok' => true, 'replayed' => true, 'effect' => ['outbox_code' => $outboxCode]];
            },
        );
        $result = $service->invoice('po_r43_invoice_101');

        self::assertSame('po_r43_invoice_101', $seen);
        self::assertTrue($result['replayed']);
    }
}
