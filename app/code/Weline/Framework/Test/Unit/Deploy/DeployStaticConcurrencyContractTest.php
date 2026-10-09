<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Console\Console\Deploy\Upgrade;

final class DeployStaticConcurrencyContractTest extends TestCase
{
    public function testUpgradeExposesModuleStaticProcessPool(): void
    {
        $path = dirname(__DIR__, 3) . '/Console/Console/Deploy/Upgrade.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('WELINE_DEPLOY_STATIC_CONCURRENCY', $src);
        self::assertStringContainsString('publishModuleJobs', $src);
        self::assertStringContainsString('publishOneModuleJob', $src);
        self::assertStringContainsString('publish-module-static-job.php', $src);
        self::assertStringContainsString('静态资源部署进程池', $src);
    }

    public function testConcurrencyClamp(): void
    {
        $ref = new \ReflectionClass(Upgrade::class);
        self::assertSame(10, $ref->getConstant('DEFAULT_STATIC_CONCURRENCY'));
        self::assertSame(32, $ref->getConstant('MAX_STATIC_CONCURRENCY'));
    }

    public function testWorkerScriptExists(): void
    {
        $script = dirname(__DIR__, 3) . '/Deploy/bin/publish-module-static-job.php';
        self::assertFileExists($script);
        $src = (string)file_get_contents($script);
        self::assertStringContainsString('publishOneModuleJob', $src);
    }
}
