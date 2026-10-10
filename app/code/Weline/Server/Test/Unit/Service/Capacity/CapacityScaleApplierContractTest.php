<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Capacity;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Capacity\CapacityIntent;
use Weline\Server\Service\Capacity\CapacityScaleApplier;

final class CapacityScaleApplierContractTest extends TestCase
{
    protected function setUp(): void
    {
        CapacityScaleApplier::resetForTests();
    }

    public function testAppliesRuntimeDesiredWithoutTouchingBaselineSemantics(): void
    {
        $desired = 2;
        $persisted = null;
        $applier = new CapacityScaleApplier();
        $intent = CapacityIntent::scaleTo('default', 3, 'workers_busy_host_headroom');
        $result = $applier->apply($intent, [
            'instance' => 'default',
            'current_desired' => $desired,
            'baseline' => 2,
            'max_workers' => 8,
            'min_workers' => 1,
            'skip_budget_gate' => true,
            'set_desired' => static function (int $n) use (&$desired): void {
                $desired = $n;
            },
            'persist_runtime' => static function (int $n) use (&$persisted): void {
                $persisted = $n;
            },
            'execute' => static fn (): bool => true,
        ]);
        self::assertTrue($result['applied']);
        self::assertSame(3, $desired);
        self::assertSame(3, $persisted);
        self::assertSame(3, (int)$result['to']);
        self::assertSame(2, (int)$result['from']);
    }

    public function testClampsDownToBaseline(): void
    {
        $desired = 4;
        $applier = new CapacityScaleApplier();
        $intent = CapacityIntent::scaleTo('default', 1, 'idle_revert_baseline');
        $result = $applier->apply($intent, [
            'instance' => 'default',
            'current_desired' => $desired,
            'baseline' => 2,
            'max_workers' => 8,
            'skip_budget_gate' => true,
            'set_desired' => static function (int $n) use (&$desired): void {
                $desired = $n;
            },
            'persist_runtime' => static function (): void {
            },
            'execute' => static fn (): bool => true,
        ]);
        self::assertTrue($result['applied']);
        self::assertSame(2, $desired, 'must not shrink below baseline');
    }

    public function testOpsMutexNoops(): void
    {
        $applier = new CapacityScaleApplier();
        $result = $applier->apply(
            CapacityIntent::scaleTo('default', 3, 'workers_busy_host_headroom'),
            [
                'instance' => 'default',
                'current_desired' => 2,
                'baseline' => 2,
                'max_workers' => 8,
                'ops_mutex' => true,
                'skip_budget_gate' => true,
            ]
        );
        self::assertFalse($result['applied']);
        self::assertSame('ops_mutex', $result['reason']);
    }

    public function testBudgetGateNoopsOnScaleUp(): void
    {
        $applier = new CapacityScaleApplier();
        $result = $applier->apply(
            CapacityIntent::scaleTo('default', 3, 'workers_busy_host_headroom'),
            [
                'instance' => 'default',
                'current_desired' => 2,
                'baseline' => 2,
                'max_workers' => 8,
                'env' => [
                    'wls' => ['fiber' => ['max_active' => 12]],
                    'db' => ['master' => ['pool_size' => 4]],
                ],
                'pg_max_connections' => 200,
            ]
        );
        self::assertFalse($result['applied']);
        self::assertSame('db_budget', $result['reason']);
    }

    public function testPressurePriorityReasonPreserved(): void
    {
        $desired = 3;
        $applier = new CapacityScaleApplier();
        $result = $applier->apply(
            CapacityIntent::scaleTo(
                'default',
                2,
                'pressure_reclaim',
                priority: CapacityScaleApplier::PRIORITY_PRESSURE_RECLAIM,
            ),
            [
                'instance' => 'default',
                'current_desired' => $desired,
                'baseline' => 2,
                'max_workers' => 8,
                'skip_budget_gate' => true,
                'set_desired' => static function (int $n) use (&$desired): void {
                    $desired = $n;
                },
                'persist_runtime' => static function (): void {
                },
                'execute' => static fn (): bool => true,
            ]
        );
        self::assertTrue($result['applied']);
        self::assertSame('pressure_reclaim', $result['reason']);
    }
}
