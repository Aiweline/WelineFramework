<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Console\Console\Deploy\Upgrade;
use Weline\Framework\Deploy\HostProcessPoolPolicy;

final class DeployStaticConcurrencyContractTest extends TestCase
{
    public function testUpgradeExposesModuleStaticProcessPool(): void
    {
        $path = dirname(__DIR__, 3) . '/Console/Console/Deploy/Upgrade.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('WELINE_DEPLOY_STATIC_CONCURRENCY', $src);
        self::assertStringContainsString('HostProcessPoolPolicy', $src);
        self::assertStringContainsString('resolveStaticConcurrencyDecision', $src);
        self::assertStringContainsString('publishModuleJobs', $src);
        self::assertStringContainsString('publishOneModuleJob', $src);
        self::assertStringContainsString('publish-module-static-job.php', $src);
        self::assertStringContainsString('静态资源部署进程池', $src);
        self::assertStringContainsString('source=%s cpus=%d mem_avail_mb=%s', $src);
    }

    public function testConcurrencyClampCeilingConstants(): void
    {
        $ref = new \ReflectionClass(Upgrade::class);
        self::assertSame(10, $ref->getConstant('DEFAULT_STATIC_CONCURRENCY'));
        self::assertSame(32, $ref->getConstant('MAX_STATIC_CONCURRENCY'));
    }

    public function testResolveUsesHostPolicyWhenEnvUnset(): void
    {
        $prev = getenv(Upgrade::ENV_STATIC_CONCURRENCY);
        putenv(Upgrade::ENV_STATIC_CONCURRENCY);
        unset($_ENV[Upgrade::ENV_STATIC_CONCURRENCY], $_SERVER[Upgrade::ENV_STATIC_CONCURRENCY]);
        try {
            $upgrade = (new \ReflectionClass(Upgrade::class))->newInstanceWithoutConstructor();
            $decision = $upgrade->resolveStaticConcurrencyDecision(null);
            self::assertSame('host_auto', $decision['source']);
            self::assertGreaterThanOrEqual(1, $decision['concurrency']);
            self::assertLessThanOrEqual(Upgrade::MAX_STATIC_CONCURRENCY, $decision['concurrency']);
            self::assertSame(4, $upgrade->resolveStaticConcurrency(4));
        } finally {
            if ($prev === false) {
                putenv(Upgrade::ENV_STATIC_CONCURRENCY);
            } else {
                putenv(Upgrade::ENV_STATIC_CONCURRENCY . '=' . $prev);
                $_ENV[Upgrade::ENV_STATIC_CONCURRENCY] = $prev;
            }
        }
    }

    public function testWorkerScriptExists(): void
    {
        $script = dirname(__DIR__, 3) . '/Deploy/bin/publish-module-static-job.php';
        self::assertFileExists($script);
        $src = (string)file_get_contents($script);
        self::assertStringContainsString('publishOneModuleJob', $src);
    }

    public function testHostProcessPoolPolicyClassExists(): void
    {
        self::assertTrue(class_exists(HostProcessPoolPolicy::class));
    }
}
