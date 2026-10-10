<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Order\Service\OrderFacade;

final class OrderFacadeAdminAdjustmentTest extends TestCase
{
    public function testInterfaceDeclaresAppendAdminAdjustmentLine(): void
    {
        $iface = new \ReflectionClass(OrderFacadeInterface::class);
        self::assertTrue($iface->hasMethod('appendAdminAdjustmentLine'));
        self::assertTrue((new \ReflectionClass(OrderFacade::class))->hasMethod('appendAdminAdjustmentLine'));
    }

    public function testAppendAdminAdjustmentLinePreservesIncentiveAndRecomputesGrand(): void
    {
        $facade = OrderFacade::forTesting();
        $prop = new \ReflectionProperty($facade, 'memory');
        $prop->setAccessible(true);
        $memory = $prop->getValue($facade);
        if (!\is_array($memory)) {
            self::markTestSkipped('OrderFacade::forTesting memory unavailable');
        }
        $uuid = 'ord-admin-adj-1';
        $memory['orders'][$uuid] = [
            'order_uuid' => $uuid,
            'type_payload' => [
                'discount_lines' => [[
                    'key' => 'pmi:x',
                    'source_type' => 'payment_method_incentive',
                    'amount_minor' => -500,
                ]],
                'payment_method_incentive_amount_minor' => -500,
            ],
            'money' => [
                'currency' => 'CNY',
                'subtotal_minor' => 10000,
                'shipping_amount_minor' => 0,
                'tax_amount_minor' => 0,
                'discount_amount_minor' => 500,
                'grand_total_minor' => 9500,
                'cod_fee_amount_minor' => 0,
            ],
            'grand_total_minor' => 9500,
            'discount_amount_minor' => 500,
        ];
        $prop->setValue($facade, $memory);

        $applied = $facade->appendAdminAdjustmentLine($uuid, [
            'label' => '批量议价',
            'discount_amount_minor' => 1000,
            'note' => 'VIP',
            'admin_user_id' => 7,
        ]);

        self::assertSame(1500, (int)$applied['discount_amount_minor']);
        self::assertSame(8500, (int)$applied['grand_total_minor']);
        $sources = array_map(
            static fn (array $row): string => (string)($row['source_type'] ?? ''),
            \is_array($applied['type_payload']['discount_lines'] ?? null)
                ? $applied['type_payload']['discount_lines']
                : [],
        );
        self::assertContains('payment_method_incentive', $sources);
        self::assertContains('admin_adjustment', $sources);
        self::assertSame(1000, (int)($applied['type_payload']['admin_adjustment_amount_minor'] ?? 0));
        self::assertSame('批量议价', (string)($applied['line']['label'] ?? ''));
    }
}
