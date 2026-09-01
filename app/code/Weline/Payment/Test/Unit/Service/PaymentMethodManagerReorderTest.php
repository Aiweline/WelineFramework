<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PaymentMethodManager;
use Weline\Payment\Service\PaymentProviderScanner;
use Weline\Payment\Service\PaymentScopeConfigService;

final class PaymentMethodManagerReorderTest extends TestCase
{
    public function testReorderRejectsEmptyOrderedCodesWithoutTouchingStore(): void
    {
        $manager = new PaymentMethodManager(
            $this->createMock(PaymentProviderScanner::class),
            $this->createMock(ObjectManager::class),
            new PaymentScopeConfigService(),
        );

        $result = $manager->reorderMethodsForScope(
            ['', '  '],
            ['scope' => 'default.default.default'],
        );

        self::assertFalse($result['success']);
        self::assertSame([], $result['sort_orders']);
        self::assertNotSame('', $result['message']);
    }
}
