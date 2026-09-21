<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Checkout\Service\InMemoryCheckoutSessionStore;
use Weline\Order\Service\ContinuePayUrlBuilder;

final class ContinuePayUrlBuilderTest extends TestCase
{
    public function testBuildsCapabilityUrlWhenSubmittedTokenExists(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $store->put('qt_abc', [
            'state' => CheckoutSession::STATE_SUBMITTED,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'idempotency_key' => 'order-idem-cap-1',
            'submitted_result' => [
                'order_uuids' => ['ord-cap-1'],
                'checkout_group_uuid' => 'group-cap-1',
            ],
            'payment_result' => [
                'outcome' => 'failed',
                'recoverable' => true,
                'transactions' => [
                    ['method_code' => 'fake_card', 'status' => 'failed'],
                ],
            ],
        ], gmdate('Y-m-d H:i:s', time() + 86400));

        self::assertSame('qt_abc', $store->findSubmittedTokenByOrderUuid('ord-cap-1'));

        $builder = new ContinuePayUrlBuilder($store, 'https://shop.example.test');
        $result = $builder->build('ord-cap-1', null, null);
        self::assertTrue($result['reachable']);
        self::assertSame('qt_abc', $result['checkout_token']);
        self::assertStringContainsString('/checkout', $result['continue_pay_url']);
        self::assertStringContainsString('#payment-recovery?', $result['continue_pay_url']);
        self::assertStringContainsString('quote_token=qt_abc', $result['continue_pay_url']);
        self::assertStringContainsString('order_uuid=ord-cap-1', $result['continue_pay_url']);
        self::assertStringContainsString('payment_method=fake_card', $result['continue_pay_url']);
    }

    public function testGuestWithoutTokenIsUnreachable(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $builder = new ContinuePayUrlBuilder($store, 'https://shop.example.test');
        $result = $builder->build('ord-missing', null, null);
        self::assertFalse($result['reachable']);
        self::assertSame('', $result['continue_pay_url']);
    }

    public function testLoggedInFallbackWithoutToken(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $builder = new ContinuePayUrlBuilder($store, 'https://shop.example.test');
        $result = $builder->build('ord-login', 42, null);
        self::assertTrue($result['reachable']);
        self::assertStringContainsString('/customer/account/index', $result['continue_pay_url']);
    }
}
