<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\CommerceOrderTypeInterface;
use Weline\Order\Service\CommerceOrderTypeRegistry;
use Weline\Order\Service\OrderTypeEventEnvelope;

final class OrderTypeEventEnvelopeTest extends TestCase
{
    public function testAppendPreservesExistingKeysAndAddsTocPayload(): void
    {
        $registry = CommerceOrderTypeRegistry::forTesting();
        $decorated = OrderTypeEventEnvelope::append([
            'order' => [
                'order_type' => 'toc',
                'order_id' => 42,
            ],
            'order_id' => 42,
            'legacy_flag' => true,
        ], $registry);

        self::assertTrue($decorated['legacy_flag']);
        self::assertSame(42, $decorated['order_id']);
        self::assertSame('toc', $decorated['order_type']);
        self::assertTrue($decorated['type_payload']['discounts_applied']);
        self::assertArrayNotHasKey('hang_status', $decorated['type_payload']);
    }

    public function testAppendTobPayloadIncludesAvailableHangAndDepositFields(): void
    {
        $tob = new class implements CommerceOrderTypeInterface {
            public function getCode(): string
            {
                return 'tob';
            }

            public function getLabel(): string
            {
                return '批发';
            }

            public function getBadgeTone(): string
            {
                return 'warning';
            }

            public function requiresCustomerLogin(): bool
            {
                return true;
            }

            public function disablesStorefrontDiscounts(): bool
            {
                return true;
            }
        };
        $registry = CommerceOrderTypeRegistry::forTesting([$tob]);

        $decorated = OrderTypeEventEnvelope::append([
            'order' => [
                'order_type' => 'tob',
                'hang_status' => 'awaiting_deposit',
                'group_id' => 'g-dealer',
                'deposit_amount_minor' => 3000,
                'deposit_ratio_bps' => 3000,
            ],
            'order_id' => 7,
        ], $registry);

        self::assertSame('tob', $decorated['order_type']);
        self::assertFalse($decorated['type_payload']['discounts_applied']);
        self::assertSame('awaiting_deposit', $decorated['type_payload']['hang_status']);
        self::assertSame('g-dealer', $decorated['type_payload']['group_id']);
        self::assertSame(3000, $decorated['type_payload']['deposit_amount_minor']);
        self::assertSame(3000, $decorated['type_payload']['deposit_ratio_bps']);
        self::assertSame(7, $decorated['order_id']);
    }

    public function testUnregisteredHistoricalTobStillEmitsTypeWithoutRemovingKeys(): void
    {
        $registry = CommerceOrderTypeRegistry::forTesting();
        $decorated = OrderTypeEventEnvelope::append([
            'order' => [
                'order_type' => 'tob',
                'hang_status' => 'awaiting_balance',
            ],
            'keep_me' => 'yes',
        ], $registry);

        self::assertSame('yes', $decorated['keep_me']);
        self::assertSame('tob', $decorated['order_type']);
        self::assertFalse($decorated['type_payload']['discounts_applied']);
        self::assertSame('awaiting_balance', $decorated['type_payload']['hang_status']);
    }
}
