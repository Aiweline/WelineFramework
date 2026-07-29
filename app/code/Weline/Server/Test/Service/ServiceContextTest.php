<?php
declare(strict_types=1);

namespace Weline\Server\Test\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Runtime\EffectiveTopology;
use Weline\Server\Service\Runtime\RequestedTopology;
use Weline\Server\Service\Runtime\RuntimeSelection;

class ServiceContextTest extends TestCase
{
    private ServiceContext $context;

    protected function setUp(): void
    {
        $this->context = new ServiceContext(
            instanceName: 'test-instance',
            epoch: 1,
            controlPort: 19000,
            masterPid: 12345,
            host: '0.0.0.0',
            mainPort: 8080,
            sslEnabled: true,
            sslCert: '/path/to/cert.pem',
            sslKey: '/path/to/key.pem',
            runtimeSelection: new RuntimeSelection(
                requestedTopology: RequestedTopology::Dispatcher,
                effectiveTopology: EffectiveTopology::Dispatcher,
                source: 'unit',
                osFamily: 'Linux',
                eventLoopDriver: 'event',
                sslEngine: 'stream',
                listenerMode: 'single',
                policyCompatible: true,
                reasonCodes: ['unit_fixture'],
                reason: 'Service context test runtime selection.',
            ),
            daemon: false,
            debug: true,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'edge' => ['adapter' => 'wls'],
                    'worker_count' => 4,
                    'worker_base_port' => 10443,
                    'worker_memory_limit' => '512m',
                    'dispatcher_memory_limit' => '1G',
                ],
                'database' => [
                    'host' => 'localhost',
                ],
            ],
        );
    }

    public function testBasicProperties(): void
    {
        $this->assertEquals('test-instance', $this->context->instanceName);
        $this->assertEquals(19000, $this->context->controlPort);
        $this->assertEquals(12345, $this->context->masterPid);
        $this->assertEquals('0.0.0.0', $this->context->host);
        $this->assertEquals(8080, $this->context->mainPort);
        $this->assertTrue($this->context->sslEnabled);
        $this->assertSame(
            EffectiveTopology::Dispatcher,
            $this->context->runtimeSelection->effectiveTopology,
        );
        $this->assertFalse($this->context->daemon);
        $this->assertTrue($this->context->debug);
        $this->assertFalse($this->context->windowMode);
    }

    public function testGetConfig(): void
    {
        $this->assertEquals(4, $this->context->getConfig('wls.worker_count'));
        $this->assertEquals(10443, $this->context->getConfig('wls.worker_base_port'));
        $this->assertEquals('localhost', $this->context->getConfig('database.host'));
    }

    public function testGetConfigDefault(): void
    {
        $this->assertNull($this->context->getConfig('nonexistent.key'));
        $this->assertEquals('default', $this->context->getConfig('nonexistent.key', 'default'));
    }

    public function testGetWorkerBasePort(): void
    {
        $this->assertEquals(10443, $this->context->getWorkerBasePort());
    }

    public function testGetWorkerCount(): void
    {
        $this->assertEquals(4, $this->context->getWorkerCount());
    }

    public function testGetMemoryLimits(): void
    {
        $this->assertSame('512M', $this->context->getWorkerMemoryLimit());
        $this->assertSame('1G', $this->context->getDispatcherMemoryLimit());
        $this->assertSame('384M', ServiceContext::normalizeMemoryLimit('384'));
        $this->assertSame('-1', ServiceContext::normalizeMemoryLimit('-1'));
    }
}
