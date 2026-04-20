<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Service\Control\HybridControlPlaneServer;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServiceOrchestrator;
use Weline\Server\Supervisor\Endpoint\ControlEndpointResolver;

final class MasterProcessControlPlaneRuntimeTest extends TestCase
{
    public function testSaveMasterInfoPersistsHybridSupervisorRuntimeMetadata(): void
    {
        $instanceName = 'ut-master-runtime-' . \bin2hex(\random_bytes(4));
        $master = new MasterProcess();

        $legacy = new MasterControlServer();
        self::assertTrue($legacy->start('127.0.0.1', 0));
        $hybrid = new HybridControlPlaneServer(
            legacyServer: $legacy,
            endpointResolver: new ControlEndpointResolver(BP, 28600, 1000),
            supervisorEnabled: true,
            channelId: 'channel-' . $instanceName,
        );
        $hybrid->setExpectedInstanceCode($instanceName);
        $hybrid->onMessage(static function (): void {});
        $hybrid->onDisconnect(static function (): void {});
        self::assertTrue($hybrid->start('127.0.0.1', 0));

        $this->writePrivate($master, 'instanceName', $instanceName);
        $this->writePrivate($master, 'mode', MasterProcess::MODE_LEGACY);
        $this->writePrivate($master, 'mainPort', 18080);
        $this->writePrivate($master, 'controlPort', $hybrid->getPort());
        $this->writePrivate($master, 'orchestrator', new class($hybrid) extends ServiceOrchestrator {
            public function __construct(private readonly HybridControlPlaneServer $server) {}
            public function getControlServer(): HybridControlPlaneServer
            {
                return $this->server;
            }
        });

        try {
            $master->saveMasterInfo('bootstrapping');
            $instanceFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json';
            self::assertFileExists($instanceFile);
            $data = \json_decode((string)\file_get_contents($instanceFile), true);
            self::assertIsArray($data);
            self::assertSame('hybrid', $data['control_plane_mode'] ?? null);
            self::assertTrue((bool)($data['supervisor_enabled'] ?? false));
            self::assertSame('channel-' . $instanceName, $data['supervisor_channel'] ?? null);
            self::assertIsString($data['supervisor_endpoint'] ?? null);
            self::assertNotSame('', $data['supervisor_endpoint'] ?? '');
            self::assertSame($hybrid->getPort(), $data['control_port'] ?? null);
        } finally {
            $hybrid->close();
            $instanceFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json';
            if (\is_file($instanceFile)) {
                @\unlink($instanceFile);
            }
        }
    }

    private function writePrivate(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}
