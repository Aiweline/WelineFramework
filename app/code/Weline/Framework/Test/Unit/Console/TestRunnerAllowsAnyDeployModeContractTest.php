<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Console;

use PHPUnit\Framework\TestCase;

/**
 * e2e / phpunit / 后台测试队列不得因 system.deploy=prod|dev 拒跑。
 */
final class TestRunnerAllowsAnyDeployModeContractTest extends TestCase
{
    /** @return list<string> */
    private function runnerSources(): array
    {
        // __DIR__ = …/Framework/Test/Unit/Console → Test root = dirname×2
        $testRoot = dirname(__DIR__, 2);
        $frameworkRoot = dirname($testRoot);

        return [
            $testRoot . '/Console/E2e/Run.php',
            $testRoot . '/Console/PhpUnit/Run.php',
            $testRoot . '/Console/PhpUnit/Status.php',
            $testRoot . '/Console/PhpUnit/Stop.php',
            $testRoot . '/Service/TestRunService.php',
            $frameworkRoot . '/UnitTest/Console/E2e/Run.php',
            $frameworkRoot . '/UnitTest/Console/PhpUnit/Status.php',
            $frameworkRoot . '/UnitTest/Console/PhpUnit/Stop.php',
        ];
    }

    public function testRunnersDoNotGateOnDeployMode(): void
    {
        foreach ($this->runnerSources() as $path) {
            self::assertFileExists($path);
            $src = (string)file_get_contents($path);
            self::assertStringNotContainsString(
                "Env::system('deploy') !== 'dev'",
                $src,
                $path . ' must not refuse non-dev deploy'
            );
            self::assertStringNotContainsString(
                '非开发环境禁止运行',
                $src,
                $path . ' must not show deploy-mode forbid message'
            );
            self::assertStringNotContainsString(
                'assertDevDeploy',
                $src,
                $path . ' must not call assertDevDeploy'
            );
        }
    }
}
