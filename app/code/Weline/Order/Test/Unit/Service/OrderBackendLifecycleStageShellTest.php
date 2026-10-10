<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

require_once dirname(__DIR__) . '/bootstrap_i18n.php';

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\Data\LifecycleStageView;
use Weline\Order\Api\OrderBackendLifecycleStagePolicyInterface;
use Weline\Order\Service\OrderBackendLifecycleStagePolicyRegistry;
use Weline\Order\Service\OrderBackendLifecycleStageShell;
use Weline\Order\Service\OrderConflictException;

final class OrderBackendLifecycleStageShellTest extends TestCase
{
    public function testPresentForOrderReturnsNullWithoutPolicy(): void
    {
        $shell = new OrderBackendLifecycleStageShell(OrderBackendLifecycleStagePolicyRegistry::forTesting());
        self::assertNull($shell->presentForOrder(['order_type' => 'toc']));
    }

    public function testPresentForOrderUsesPolicy(): void
    {
        $policy = new class implements OrderBackendLifecycleStagePolicyInterface {
            public function getOrderTypeCode(): string { return 'demo'; }
            public function supports(array $orderRow): bool { return true; }
            public function present(array $orderRow): ?LifecycleStageView
            {
                return new LifecycleStageView('阶段', 'a', 'A', 'info', [
                    ['code' => 'a', 'label' => 'A', 'state' => 'current', 'tone' => 'info'],
                ]);
            }
        };
        $shell = new OrderBackendLifecycleStageShell(
            OrderBackendLifecycleStagePolicyRegistry::forTesting([$policy]),
        );
        $view = $shell->presentForOrder(['order_type' => 'demo']);
        self::assertIsArray($view);
        self::assertSame('A', $view['current_label']);
        self::assertSame('阶段', $view['title']);
    }

    public function testDuplicatePolicyCodeThrows(): void
    {
        $a = new class implements OrderBackendLifecycleStagePolicyInterface {
            public function getOrderTypeCode(): string { return 'x'; }
            public function supports(array $orderRow): bool { return true; }
            public function present(array $orderRow): ?LifecycleStageView { return null; }
        };
        $this->expectException(OrderConflictException::class);
        OrderBackendLifecycleStagePolicyRegistry::forTesting([$a, $a]);
    }
}
