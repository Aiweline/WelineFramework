<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Controller\Router;

final class PaymentGuideRouteTableTest extends TestCase
{
    public function testGuidePaymentIndexRouteRewritesToPaymentModule(): void
    {
        $path = 'guide/payment';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('payment/frontend/guide/payment/index', $path);
        self::assertSame('Weline_Payment', $rule['module'] ?? null);
    }

    public function testGuidePaymentProviderRouteRewritesMethodCode(): void
    {
        $path = 'guide/payment/paypal';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('payment/frontend/guide/payment/view', $path);
        self::assertSame('Weline_Payment', $rule['module'] ?? null);
        self::assertSame('paypal', \Weline\Framework\Context::current()->get('input.query.method_code'));
    }

    public function testGuidePaymentPolicyRouteRewritesMethodCode(): void
    {
        $path = 'guide/payment/fake_card/policy';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('payment/frontend/guide/payment/policy', $path);
        self::assertSame('Weline_Payment', $rule['module'] ?? null);
        self::assertSame('fake_card', \Weline\Framework\Context::current()->get('input.query.method_code'));
    }
}
