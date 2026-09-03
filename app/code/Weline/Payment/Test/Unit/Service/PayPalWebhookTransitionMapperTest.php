<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PayPalWebhookTransitionMapper;

final class PayPalWebhookTransitionMapperTest extends TestCase
{
    public function testCaptureCompletedMapsToPaid(): void
    {
        $mapper = new PayPalWebhookTransitionMapper();
        self::assertSame('paid', $mapper->mapStatusTransition('PAYMENT.CAPTURE.COMPLETED'));
    }

    public function testDisputeCreatedMapsToDisputed(): void
    {
        $mapper = new PayPalWebhookTransitionMapper();
        self::assertSame('disputed', $mapper->mapStatusTransition('CUSTOMER.DISPUTE.CREATED'));
    }

    public function testRefundMapsToRefunded(): void
    {
        $mapper = new PayPalWebhookTransitionMapper();
        self::assertSame('refunded', $mapper->mapStatusTransition('PAYMENT.CAPTURE.REFUNDED'));
    }

    public function testDisputeResolvedSellerFavorMapsToPaid(): void
    {
        $mapper = new PayPalWebhookTransitionMapper();
        self::assertSame(
            'paid',
            $mapper->mapStatusTransition('CUSTOMER.DISPUTE.RESOLVED', [
                'resource' => ['dispute_outcome' => ['outcome_code' => 'RESOLVED_SELLER_FAVOUR']],
            ]),
        );
    }

    public function testSideEffectNotificationDetection(): void
    {
        $mapper = new PayPalWebhookTransitionMapper();
        self::assertTrue($mapper->isSideEffectNotification('CUSTOMER.DISPUTE.CREATED'));
        self::assertTrue($mapper->isSideEffectNotification('PAYMENT.CAPTURE.REFUNDED'));
        self::assertFalse($mapper->isSideEffectNotification('PAYMENT.CAPTURE.COMPLETED'));
    }
}
