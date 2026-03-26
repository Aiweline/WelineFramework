<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Cron\Warmup;
use Weline\Cdn\Service\WarmupProviderScanner;
use Weline\Cdn\Service\WarmupRunner;
use Weline\Framework\Event\EventsManager;

class WarmupTest extends TestCase
{
    private Warmup $cron;
    private WarmupProviderScanner $providerScanner;
    private WarmupRunner $warmupRunner;
    private EventsManager $eventsManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->providerScanner = $this->createMock(WarmupProviderScanner::class);
        $this->warmupRunner = $this->createMock(WarmupRunner::class);
        $this->eventsManager = $this->createMock(EventsManager::class);

        $this->cron = new Warmup(
            $this->providerScanner,
            $this->warmupRunner,
            $this->eventsManager
        );
    }

    public function testCronInstantiation(): void
    {
        $this->assertInstanceOf(Warmup::class, $this->cron);
    }

    public function testExecuteNoUrls(): void
    {
        $this->providerScanner->expects($this->once())
            ->method('collectUrls')
            ->willReturn([]);
        $this->eventsManager->expects($this->never())
            ->method('dispatch');
        $this->warmupRunner->expects($this->once())
            ->method('run')
            ->with(50)
            ->willReturn(['processed' => 0, 'success' => 0, 'fail' => 0]);

        $this->executeSilently();
        $this->assertTrue(true);
    }

    public function testExecuteWithUrls(): void
    {
        $urls = [
            ['url' => 'https://example.com/page1'],
            ['url' => 'https://example.com/page2'],
        ];

        $this->providerScanner->expects($this->once())
            ->method('collectUrls')
            ->willReturn($urls);
        $this->eventsManager->expects($this->once())
            ->method('dispatch')
            ->with(
                'Weline_Cdn::send_warmup',
                $this->callback(static function ($event) use ($urls): bool {
                    $data = $event->getData();

                    return $data['module'] === 'Weline_Cdn'
                        && $data['provider'] === 'scanner'
                        && $data['urls'] === $urls
                        && $data['dedupe'] === true;
                })
            );
        $this->warmupRunner->expects($this->once())
            ->method('run')
            ->with(50)
            ->willReturn(['processed' => 2, 'success' => 2, 'fail' => 0]);

        $this->executeSilently();
        $this->assertTrue(true);
    }

    public function testExecuteWarmupResults(): void
    {
        $this->providerScanner->expects($this->once())
            ->method('collectUrls')
            ->willReturn([]);
        $this->warmupRunner->expects($this->once())
            ->method('run')
            ->willReturn(['processed' => 10, 'success' => 8, 'fail' => 2]);

        $this->executeSilently();
        $this->assertTrue(true);
    }

    public function testExecuteExceptionHandling(): void
    {
        $this->providerScanner->expects($this->once())
            ->method('collectUrls')
            ->willThrowException(new \Exception('scan failed'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('scan failed');
        $this->executeSilently();
    }

    private function executeSilently(): void
    {
        ob_start();
        try {
            $this->cron->execute();
        } finally {
            ob_end_clean();
        }
    }
}
