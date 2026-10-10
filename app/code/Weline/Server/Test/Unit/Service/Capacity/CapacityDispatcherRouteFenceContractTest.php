<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Capacity;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;

/**
 * Wave2 G1: lock SET_ROUTE_TABLE source order / symbol presence for capacity scale fence.
 * Full Dispatcher runtime topology is dual-topology evidence (Direct local gap noted in seat report).
 */
final class CapacityDispatcherRouteFenceContractTest extends TestCase
{
    public function testOrchestratorCapacityFenceUsesSetRouteTableAuthority(): void
    {
        $orchestrator = \dirname(__DIR__, 4) . '/Service/ServiceOrchestrator.php';
        $applier = \dirname(__DIR__, 4) . '/Service/Capacity/CapacityScaleApplier.php';
        self::assertFileExists($orchestrator);
        self::assertFileExists($applier);
        $src = (string)\file_get_contents($orchestrator);
        $applierSrc = (string)\file_get_contents($applier);

        self::assertStringContainsString('publishCapacityScaleRouteFence', $src);
        self::assertStringContainsString('syncDispatcherFullWorkerPoolFromRegistry', $src);
        self::assertStringContainsString('before_scale_down', $src);
        self::assertStringContainsString('after_scale_down', $src);
        self::assertStringContainsString('after_scale_up', $src);
        self::assertStringContainsString('workerRoutePublishSuppressedInstanceIds', $src);

        // Applier publishes fence before drain execute on scale-down.
        $beforePos = \strpos($applierSrc, "publish('before_scale_down')");
        $executePos = \strpos($applierSrc, '$execute(');
        self::assertNotFalse($beforePos);
        self::assertNotFalse($executePos);
        self::assertLessThan($executePos, $beforePos, 'SET_ROUTE_TABLE fence must precede drain execute');

        self::assertSame('set_route_table', ControlMessage::TYPE_SET_ROUTE_TABLE);
        self::assertTrue(\method_exists(ControlMessage::class, 'setRouteTable'));
    }

    public function testScalingControllerAutoScaleDisabledDualBrain(): void
    {
        $file = \dirname(__DIR__, 4) . '/Service/ScalingController.php';
        $src = (string)\file_get_contents($file);
        self::assertStringContainsString('dual-brain disabled', $src);
        self::assertMatchesRegularExpression(
            '/function\s+autoScale\s*\([^)]*\)\s*:\s*\?array\s*\{[^}]*return\s+null;/s',
            $src
        );
    }
}
