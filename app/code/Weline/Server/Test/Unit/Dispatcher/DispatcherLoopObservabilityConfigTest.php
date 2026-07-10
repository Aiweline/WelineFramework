<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\Dispatcher;
use Weline\Server\Dispatcher\PassthroughCore;

class DispatcherLoopObservabilityConfigTest extends TestCase
{
    public function testConfigureStoresMainLoopUnblockedLogEvery(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['configure'])
            ->getMock();

        $core->expects(self::once())
            ->method('configure')
            ->with(self::callback(static function (array $config): bool {
                return ($config['main_loop_unblocked_log_every'] ?? null) === 2048;
            }));

        $this->setProperty($dispatcher, 'passthroughCore', $core);

        $dispatcher->configure([
            'main_loop_unblocked_log_every' => 2048,
        ]);

        self::assertSame(2048, $this->getProperty($dispatcher, 'mainLoopUnblockedLogEvery'));
    }

    public function testConfigureStoresMainLoopUnblockedLogIntervalSeconds(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['configure'])
            ->getMock();

        $core->expects(self::once())->method('configure');

        $this->setProperty($dispatcher, 'passthroughCore', $core);

        $dispatcher->configure([
            'main_loop_unblocked_log_interval_sec' => 12.5,
        ]);

        self::assertSame(12.5, $this->getProperty($dispatcher, 'mainLoopUnblockedLogIntervalSec'));
    }

    public function testConfigureClampsNegativeMainLoopUnblockedLogEveryToZero(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['configure'])
            ->getMock();

        $core->expects(self::once())->method('configure');

        $this->setProperty($dispatcher, 'passthroughCore', $core);

        $dispatcher->configure([
            'main_loop_unblocked_log_every' => -1,
        ]);

        self::assertSame(0, $this->getProperty($dispatcher, 'mainLoopUnblockedLogEvery'));
    }

    public function testDispatcherMainLoopProcessesNetworkBeforeControlPlaneMaintenance(): void
    {
        $source = \file_get_contents(
            BP . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline'
            . DIRECTORY_SEPARATOR . 'Server' . DIRECTORY_SEPARATOR . 'Dispatcher' . DIRECTORY_SEPARATOR . 'Dispatcher.php'
        );

        self::assertIsString($source);

        $selectPos = \strpos($source, '$this->selectAndProcess();');
        $masterCheckPos = \strpos($source, '$this->checkMasterPidAlive();');
        $deferredPos = \strpos($source, '$this->pumpDeferredWorkerPoolJobs(true);');

        self::assertNotFalse($selectPos, 'Dispatcher main loop should process socket IO.');
        self::assertNotFalse($masterCheckPos, 'Dispatcher main loop should still run Master guard.');
        self::assertNotFalse($deferredPos, 'Dispatcher deferred worker jobs should respect pending accepts.');
        self::assertLessThan($masterCheckPos, $selectPos, 'Socket IO must run before control-plane checks.');
        self::assertLessThan($deferredPos, $selectPos, 'Socket IO must run before deferred worker jobs.');
    }

    private function newDispatcherWithoutConstructor(): Dispatcher
    {
        $reflector = new \ReflectionClass(Dispatcher::class);
        /** @var Dispatcher $dispatcher */
        $dispatcher = $reflector->newInstanceWithoutConstructor();
        return $dispatcher;
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($target, $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }

    private function getProperty(object $target, string $name): mixed
    {
        $property = new \ReflectionProperty($target, $name);
        $property->setAccessible(true);
        return $property->getValue($target);
    }
}
