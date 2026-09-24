<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Deploy\Service\DeployProjectCommandPolicyService;

/**
 * R1：POST_DEPLOY 白名单含 setup:upgrade / deploy:upgrade；空串规范化仍为空（默认由 Orchestrator 填）。
 */
final class DeployPostDeployPolicyContractTest extends TestCase
{
    public function testSetupUpgradeAndDeployUpgradeAreAllowed(): void
    {
        $policy = new DeployProjectCommandPolicyService();
        self::assertSame(
            'php bin/w setup:upgrade',
            $policy->normalizePostDeployCommand('php bin/w setup:upgrade')
        );
        self::assertSame(
            'php bin/w deploy:upgrade',
            $policy->normalizePostDeployCommand('php bin/w deploy:upgrade')
        );
        self::assertSame(
            'php bin/w setup:upgrade && php bin/w server:reload -r',
            $policy->normalizePostDeployCommand('php bin/w setup:upgrade && php bin/w server:reload -r')
        );
    }

    public function testEmptyNormalizesToEmptyString(): void
    {
        $policy = new DeployProjectCommandPolicyService();
        self::assertSame('', $policy->normalizePostDeployCommand(''));
    }

    public function testOrchestratorDefaultsEmptyPostDeployToSetupUpgrade(): void
    {
        $src = (string)file_get_contents(
            BP . 'app/code/Weline/Deploy/Service/DeployOrchestratorService.php'
        );
        self::assertStringContainsString("php bin/w setup:upgrade", $src);
        self::assertStringContainsString('POST_DEPLOY 为空', $src);
        self::assertStringContainsString('默认执行 setup:upgrade', $src);
    }

    public function testReleaseAfterCallsDeployFpcInvalidation(): void
    {
        $src = (string)file_get_contents(
            BP . 'app/code/Weline/Deploy/Observer/ReleaseAfter.php'
        );
        self::assertStringContainsString('DeployFpcInvalidation', $src);
        self::assertStringContainsString('afterUpgrade', $src);
        self::assertStringNotContainsString('recursiveCopy', $src);
    }

    public function testCoreUpdateDocumentsFollowUpUpgrade(): void
    {
        $src = (string)file_get_contents(
            BP . 'app/code/Weline/Deploy/Console/Update/Core.php'
        );
        self::assertStringContainsString('setup:upgrade', $src);
        self::assertStringContainsString('deploy:upgrade', $src);
        self::assertStringContainsString('不算生产静态发布完成', $src);
    }
}
