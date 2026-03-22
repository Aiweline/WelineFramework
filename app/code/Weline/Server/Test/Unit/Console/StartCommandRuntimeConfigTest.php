<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\MasterProcess;

class StartCommandRuntimeConfigTest extends TestCase
{
    public function testConfigureMasterRuntimeKeepsFrontendWorkerTopology(): void
    {
        $start = new Start();
        $start->__init();
        $master = new MasterProcess();

        $method = new \ReflectionMethod(Start::class, 'configureMasterRuntime');
        $method->setAccessible(true);

        $result = $method->invoke(
            $start,
            $master,
            true,
            2,
            10000,
            22081,
            MasterProcess::MODE_LEGACY,
            12081
        );

        self::assertSame($master, $result);
        self::assertTrue($this->readProperty($master, 'dispatcherEnabled'));
        self::assertSame(2, $this->readProperty($master, 'workerCount'));
        self::assertSame(22080, $this->readProperty($master, 'workerBasePort'));
        self::assertSame(22081, $this->readProperty($master, 'workerPort'));
        self::assertSame(MasterProcess::MODE_LEGACY, $this->readProperty($master, 'mode'));
        self::assertSame(12081, $this->readProperty($master, 'mainPort'));
    }

    private function readProperty(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }
}
