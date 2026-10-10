<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

require_once dirname(__DIR__) . '/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\B2BOrderHang;
use Weline\B2B\Service\B2BHangMailNotifier;

final class B2BHangMailNotifierTransitionTest extends TestCase
{
    public function testSkipsWhenNoTransitionMatch(): void
    {
        $n = new B2BHangMailNotifier();
        $out = $n->notifyForTransition(
            B2BOrderHang::STATUS_AWAITING_BALANCE,
            B2BOrderHang::STATUS_AWAITING_BALANCE,
            ['order_ref' => 'ord-x'],
        );
        self::assertTrue($out['skipped'] ?? false);
    }

    public function testDepositTransitionSelectsChannelWithoutSmtp(): void
    {
        $n = new B2BHangMailNotifier();
        $out = $n->notifyForTransition(
            B2BOrderHang::STATUS_AWAITING_DEPOSIT,
            B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL,
            ['order_ref' => 'ord-missing-will-skip'],
        );
        // Without order/smtp, skipped or failed — but not success true.
        self::assertFalse($out['success']);
    }
}
