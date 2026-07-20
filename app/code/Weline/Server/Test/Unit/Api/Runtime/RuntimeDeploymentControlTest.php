<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Api\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Api\Runtime\RuntimeDeploymentControl;
use Weline\Server\Service\Control\BroadcastControlDispatchService;

final class RuntimeDeploymentControlTest extends TestCase
{
    public function testBroadcastsMaintenanceAndCodeReloadToAllInstancesByDefault(): void
    {
        $dispatch = new class extends BroadcastControlDispatchService {
            /** @var list<array{action: string, instance: ?string}> */
            public array $calls = [];

            public function __construct()
            {
            }

            public function setMaintenanceMode(
                bool $enabled,
                ?string $instanceName = null,
                float $timeout = 6.0,
            ): array {
                $this->calls[] = ['action' => $enabled ? 'maintenance_on' : 'maintenance_off', 'instance' => $instanceName];
                return ['success' => true, 'attempted' => ['default'], 'message' => 'ok'];
            }

            public function reloadAsync(
                ?string $instanceName,
                string $reloadType,
                float $timeout = 5.0,
            ): array {
                $this->calls[] = ['action' => 'reload_' . $reloadType, 'instance' => $instanceName];
                return ['success' => true, 'attempted' => ['default'], 'message' => 'ok'];
            }
        };

        $control = new RuntimeDeploymentControl($dispatch);

        self::assertTrue($control->setMaintenanceMode(true)['success']);
        self::assertTrue($control->reloadCode()['success']);
        self::assertSame([
            ['action' => 'maintenance_on', 'instance' => null],
            ['action' => 'reload_code', 'instance' => null],
        ], $dispatch->calls);
    }
}
