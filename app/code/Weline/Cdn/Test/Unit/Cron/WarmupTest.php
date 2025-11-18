<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Cron\Warmup;
use Weline\Cdn\Service\WarmupProviderScanner;
use Weline\Cdn\Service\WarmupRunner;
use Weline\Framework\Event\EventsManager;

/**
 * Warmup定时任务单元测试
 */
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

    /**
     * 测试：定时任务实例化
     */
    public function testCronInstantiation(): void
    {
        $this->assertInstanceOf(Warmup::class, $this->cron);
    }

    /**
     * 测试：执行任务（无URL）
     */
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
            ->willReturn([
                'processed' => 0,
                'success' => 0,
                'fail' => 0
            ]);
        
        $this->cron->execute();
        
        // 如果没有抛出异常，测试通过
        $this->assertTrue(true);
    }

    /**
     * 测试：执行任务（有URL）
     */
    public function testExecuteWithUrls(): void
    {
        $urls = [
            ['url' => 'https://example.com/page1'],
            ['url' => 'https://example.com/page2']
        ];
        
        $this->providerScanner->expects($this->once())
            ->method('collectUrls')
            ->willReturn($urls);
        
        $this->eventsManager->expects($this->once())
            ->method('dispatch')
            ->with(
                'Weline_Cdn::send_warmup',
                $this->callback(function ($event) use ($urls) {
                    $data = $event->getData();
                    return $data['module'] === 'Weline_Cdn' &&
                           $data['provider'] === 'scanner' &&
                           $data['urls'] === $urls &&
                           $data['dedupe'] === true;
                })
            );
        
        $this->warmupRunner->expects($this->once())
            ->method('run')
            ->with(50)
            ->willReturn([
                'processed' => 2,
                'success' => 2,
                'fail' => 0
            ]);
        
        $this->cron->execute();
        
        $this->assertTrue(true);
    }

    /**
     * 测试：执行任务（预热结果）
     */
    public function testExecuteWarmupResults(): void
    {
        $this->providerScanner->expects($this->once())
            ->method('collectUrls')
            ->willReturn([]);
        
        $this->warmupRunner->expects($this->once())
            ->method('run')
            ->willReturn([
                'processed' => 10,
                'success' => 8,
                'fail' => 2
            ]);
        
        $this->cron->execute();
        
        $this->assertTrue(true);
    }

    /**
     * 测试：执行任务（异常处理）
     */
    public function testExecuteExceptionHandling(): void
    {
        $this->providerScanner->expects($this->once())
            ->method('collectUrls')
            ->willThrowException(new \Exception('扫描失败'));
        
        // 验证异常不会导致程序崩溃
        try {
            $this->cron->execute();
            $this->fail('应该抛出异常');
        } catch (\Exception $e) {
            $this->assertEquals('扫描失败', $e->getMessage());
        }
    }
}

