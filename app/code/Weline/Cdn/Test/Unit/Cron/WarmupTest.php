<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Cron\Warmup;
use Weline\Cdn\Service\WarmupCollectService;
use Weline\Cdn\Service\WarmupRunner;
use Weline\Framework\Cache\Service\StorefrontWarmup\HostLoadSampler;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupBusyGate;
use Weline\Framework\Cron\CronTaskInterface;

class WarmupTest extends TestCase
{
    private Warmup $cron;
    private WarmupCollectService $collectService;
    private WarmupRunner $warmupRunner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collectService = $this->createMock(WarmupCollectService::class);
        $this->warmupRunner = $this->createMock(WarmupRunner::class);
        $this->cron = new Warmup(
            $this->collectService,
            $this->warmupRunner,
            new StorefrontWarmupBusyGate(new HostLoadSampler([
                'available' => true,
                'source' => 'test',
                'cores' => 1,
                'load1' => 0.0,
                'busy_ratio' => 0.0,
            ])),
        );
    }

    public function testImplementsCronTaskInterface(): void
    {
        $this->assertInstanceOf(CronTaskInterface::class, $this->cron);
        $this->assertSame('cdn_warmup', $this->cron->execute_name());
    }

    public function testExecuteCollectsThenRuns(): void
    {
        $this->collectService->expects($this->once())
            ->method('collectAllProviders')
            ->willReturn(['providers' => 2, 'inserted' => 3, 'updated' => 1, 'filtered' => 0]);
        $this->warmupRunner->expects($this->once())
            ->method('run')
            ->with(50)
            ->willReturn(['processed' => 4, 'success' => 3, 'fail' => 1, 'skipped' => 0]);

        $msg = $this->cron->execute();
        $this->assertStringContainsString('providers=2', $msg);
        $this->assertStringContainsString('success=3', $msg);
    }

    public function testExecuteYieldsWhenHostIsBusy(): void
    {
        $this->collectService->expects($this->never())->method('collectAllProviders');
        $this->warmupRunner->expects($this->never())->method('run');
        $cron = new Warmup(
            $this->collectService,
            $this->warmupRunner,
            new StorefrontWarmupBusyGate(new HostLoadSampler([
                'available' => true,
                'source' => 'test',
                'cores' => 1,
                'load1' => 8.0,
                'busy_ratio' => 1.0,
            ])),
        );
        $msg = $cron->execute();
        $this->assertStringContainsString('CDN预热让出', $msg);
        $this->assertStringContainsString('busy_ge_start', $msg);
    }
}
