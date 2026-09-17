<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\PixelConversionDedupeService;

/**
 * 转化去重：业务键解析与事件白名单匹配（不依赖 DB）。
 */
final class PixelConversionDedupeServiceTest extends TestCase
{
    private PixelConversionDedupeService $svc;

    protected function setUp(): void
    {
        $this->svc = new PixelConversionDedupeService();
    }

    public function testResolveBusinessKeyPriority(): void
    {
        self::assertSame('TXN-1', $this->svc->resolveBusinessKey([
            'transaction_id' => 'TXN-1',
            'order_uuid' => 'uuid-2',
        ]));
        self::assertSame('uuid-2', $this->svc->resolveBusinessKey([
            'order_uuid' => 'uuid-2',
            'order_id' => 'ORD-3',
        ]));
        self::assertSame('ORD-3', $this->svc->resolveBusinessKey([
            'order_id' => 'ORD-3',
            'checkout_group_uuid' => 'cg-4',
        ]));
        self::assertSame('cg-4', $this->svc->resolveBusinessKey([
            'checkout_group_uuid' => 'cg-4',
        ]));
        self::assertSame('PAY-9', $this->svc->resolveBusinessKey([
            'transaction_no' => 'PAY-9',
        ]));
        self::assertSame('', $this->svc->resolveBusinessKey([
            'event' => 'checkout_success',
        ]));
    }

    public function testResolveBusinessKeyFromEcommerceBag(): void
    {
        self::assertSame('TXN-E', $this->svc->resolveBusinessKey([
            'additionalInfo' => [
                'ecommerce' => [
                    'transaction_id' => 'TXN-E',
                ],
            ],
        ]));
    }

    public function testEventMatchesAllowlistAndWildcard(): void
    {
        $events = ['payment_success', 'checkout_success', 'checkout_failure', '*_checkout_success'];
        self::assertTrue($this->svc->eventMatches('payment_success', $events));
        self::assertTrue($this->svc->eventMatches('checkout_success', $events));
        self::assertTrue($this->svc->eventMatches('express_pay_checkout_success', $events));
        self::assertFalse($this->svc->eventMatches('page_view', $events));
        self::assertFalse($this->svc->eventMatches('begin_checkout', $events));
    }
}
