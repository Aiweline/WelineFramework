<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Server\Observer\CliCommandExecutedObserver;
use Weline\Server\Service\Control\BroadcastControlDispatchService;

final class CliCommandExecutedObserverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Env::getInstance()->setConfig('wls', [
            'reload_prefixes' => [
                'code' => ['setup:', 'command:'],
                'cache' => ['cache:'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        ObjectManager::removeInstance(BroadcastControlDispatchService::class);
        ObjectManager::removeInstance(Printing::class);
        parent::tearDown();
    }

    public function testCodeCommandDispatchesReloadAsync(): void
    {
        $dispatchService = new class extends BroadcastControlDispatchService {
            public int $reloadCalls = 0;

            public function __construct()
            {
            }

            public function reloadAsync(?string $instanceName, string $reloadType, float $timeout = 3.0): array
            {
                $this->reloadCalls++;

                return [
                    'success' => true,
                    'attempted' => ['verify_http'],
                    'succeeded' => ['verify_http'],
                    'failed_by_instance' => [],
                    'message' => 'ok',
                ];
            }
        };

        $printing = $this->getMockBuilder(Printing::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['note'])
            ->getMock();
        $printing->expects($this->once())->method('note');

        ObjectManager::setInstance(BroadcastControlDispatchService::class, $dispatchService);
        ObjectManager::setInstance(Printing::class, $printing);

        $observer = new CliCommandExecutedObserver();
        $event = new Event(['data' => ['command' => 'setup:upgrade']]);
        $observer->execute($event);

        $this->assertSame(1, $dispatchService->reloadCalls);
    }

    public function testCacheCommandSkipsDuplicateDispatch(): void
    {
        $dispatchService = new class extends BroadcastControlDispatchService {
            public int $reloadCalls = 0;
            public int $cacheCalls = 0;

            public function __construct()
            {
            }

            public function reloadAsync(?string $instanceName, string $reloadType, float $timeout = 3.0): array
            {
                $this->reloadCalls++;

                return [
                    'success' => true,
                    'attempted' => [],
                    'succeeded' => [],
                    'failed_by_instance' => [],
                    'message' => 'ok',
                ];
            }

            public function cacheClear(?string $instanceName = null, float $timeout = 3.0): array
            {
                $this->cacheCalls++;

                return [
                    'success' => true,
                    'attempted' => [],
                    'succeeded' => [],
                    'failed_by_instance' => [],
                    'message' => 'ok',
                ];
            }
        };

        $printing = $this->getMockBuilder(Printing::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['note'])
            ->getMock();
        $printing->expects($this->never())->method('note');

        ObjectManager::setInstance(BroadcastControlDispatchService::class, $dispatchService);
        ObjectManager::setInstance(Printing::class, $printing);

        $observer = new CliCommandExecutedObserver();
        $event = new Event(['data' => ['command' => 'cache:clear -f']]);
        $observer->execute($event);

        $this->assertSame(0, $dispatchService->reloadCalls);
        $this->assertSame(0, $dispatchService->cacheCalls);
    }
}
