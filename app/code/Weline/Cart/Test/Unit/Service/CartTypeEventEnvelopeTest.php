<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\CommerceCartTypeInterface;
use Weline\Cart\Service\CartTypeEventEnvelope;
use Weline\Cart\Service\CommerceCartTypeRegistry;

final class CartTypeEventEnvelopeTest extends TestCase
{
    public function testAppendPreservesExistingKeysAndAddsTocPayload(): void
    {
        $registry = CommerceCartTypeRegistry::forTesting();
        $summary = [
            'cart_type' => 'toc',
            'cart_count' => 2,
            'type_payload' => [
                'discounts_applied' => true,
                'extras' => [],
            ],
        ];

        $decorated = CartTypeEventEnvelope::append([
            'summary' => $summary,
            'action' => 'add',
        ], $summary, $registry);

        self::assertSame('add', $decorated['action']);
        self::assertSame('toc', $decorated['cart_type']);
        self::assertTrue($decorated['type_payload']['discounts_applied']);
        self::assertSame($summary, $decorated['summary']);
    }

    public function testAppendTobPayloadDisablesDiscounts(): void
    {
        $tob = new class implements CommerceCartTypeInterface {
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
        $registry = CommerceCartTypeRegistry::forTesting([$tob]);

        $decorated = CartTypeEventEnvelope::append([
            'keep' => 1,
        ], [
            'cart_type' => 'tob',
            'group_id' => 'g1',
            'deposit_amount_minor' => 900,
        ], $registry);

        self::assertSame(1, $decorated['keep']);
        self::assertSame('tob', $decorated['cart_type']);
        self::assertFalse($decorated['type_payload']['discounts_applied']);
        self::assertSame('g1', $decorated['type_payload']['group_id']);
        self::assertSame(900, $decorated['type_payload']['deposit_amount_minor']);
    }

    public function testDefaultsMissingCartTypeToToc(): void
    {
        $registry = CommerceCartTypeRegistry::forTesting();
        $decorated = CartTypeEventEnvelope::append([], [], $registry);

        self::assertSame('toc', $decorated['cart_type']);
        self::assertTrue($decorated['type_payload']['discounts_applied']);
    }
}
