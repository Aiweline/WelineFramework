<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\OrderFacade;

final class OrderFacadeTobHangPayableTest extends TestCase
{
    public function testMergeTypePayloadOnInterface(): void
    {
        $ref = new \ReflectionClass(OrderFacade::class);
        self::assertTrue($ref->hasMethod('mergeTypePayload'));
        self::assertTrue($ref->hasMethod('reviseTobHangPayable'));
        $iface = new \ReflectionClass(\Weline\Order\Api\OrderFacadeInterface::class);
        self::assertTrue($iface->hasMethod('mergeTypePayload'));
        self::assertTrue($iface->hasMethod('reviseTobHangPayable'));
    }

    public function testReviseTobHangPayableUpdatesMemoryGrandTotalAndPayload(): void
    {
        $facade = OrderFacade::forTesting();
        // Seed via reflection memory if forTesting exposes it; otherwise skip when empty.
        $prop = new \ReflectionProperty($facade, 'memory');
        $prop->setAccessible(true);
        $memory = $prop->getValue($facade);
        if (!is_array($memory)) {
            self::markTestSkipped('OrderFacade::forTesting memory unavailable');
        }
        $uuid = 'ord-revise-1';
        $memory['orders'][$uuid] = [
            'order_uuid' => $uuid,
            'type_payload' => [
                'deposit_amount_minor' => 3000,
                'balance_amount_minor' => 7000,
            ],
            'money' => ['grand_total_minor' => 10000],
            'grand_total_minor' => 10000,
        ];
        $prop->setValue($facade, $memory);

        $merged = $facade->reviseTobHangPayable($uuid, [
            'balance_amount_minor' => 5000,
            'revision_version' => 2,
            'revision_pending' => false,
            'audit' => ['reason' => 'merchant_propose'],
        ]);
        self::assertSame(5000, (int)($merged['balance_amount_minor'] ?? 0));
        self::assertSame(8000, (int)($merged['payable_grand_total_minor'] ?? 0));
        self::assertFalse((bool)($merged['hang_revision_pending'] ?? true));

        $memory = $prop->getValue($facade);
        self::assertSame(8000, (int)($memory['orders'][$uuid]['grand_total_minor'] ?? 0));
        self::assertSame(8000, (int)($memory['orders'][$uuid]['money']['grand_total_minor'] ?? 0));
    }
}
