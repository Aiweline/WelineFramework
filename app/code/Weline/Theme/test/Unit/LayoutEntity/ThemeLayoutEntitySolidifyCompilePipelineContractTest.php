<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyCompilePipeline;

final class ThemeLayoutEntitySolidifyCompilePipelineContractTest extends TestCase
{
    public function testPipelineExposesDualPoolsAndWorkers(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySolidifyCompilePipeline.php';
        $src = file_get_contents($path);
        self::assertIsString($src);
        self::assertStringContainsString('WELINE_THEME_SOLIDIFY_CONCURRENCY', $src);
        self::assertStringContainsString('WELINE_THEME_COMPILE_CONCURRENCY', $src);
        self::assertStringContainsString('HostProcessPoolPolicy', $src);
        self::assertStringContainsString('resolveConcurrencyDecision', $src);
        self::assertStringContainsString('formatPoolDecisionNote', $src);
        self::assertStringContainsString('solidify_source=%s compile_source=%s', $src);
        self::assertStringContainsString('solidify-identity-job.php', $src);
        self::assertStringContainsString('compile-identity-job.php', $src);
        self::assertStringContainsString('WELINE_TEMPLATE_COMPILE_NESTED', $src);
        self::assertStringContainsString('runSolidifyJob', $src);
        self::assertStringContainsString('runCompileJob', $src);
        self::assertStringContainsString('isInsideWlsWorker', $src);
        self::assertStringContainsString('rollbackPromote', $src);
        self::assertStringContainsString('findSolidifyQueueIndex', $src);
        self::assertStringContainsString('collectBusyOwnerHashes', $src);
        self::assertStringContainsString('flushPendingRollbacks', $src);
        self::assertStringContainsString('terminateRunningWorkers', $src);
        self::assertStringContainsString('terminated_by_parent', $src);
        self::assertStringContainsString('ownerHasRunningWorker', $src);
        self::assertStringContainsString('ownerHash', $src);
        self::assertStringContainsString('pendingRollbacks', $src);
        self::assertStringContainsString('deploy_staging_root', $src);
        self::assertStringContainsString('applyDeployStagingRootFromJob', $src);
        self::assertStringContainsString('workerEnvironment', $src);
        self::assertStringContainsString('expectedPhaseTotal', $src);
        self::assertStringContainsString("'done'", $src);
        self::assertStringContainsString('formatPipelineProgressLabel', $src);
        self::assertStringContainsString('themes=%s', $src);
        self::assertStringContainsString('s=%d/%d c=%d/%d', $src);
        // After proc_get_status reaps, trust exitcode — never use proc_close() return as exit.
        self::assertStringContainsString("array_key_exists('exitcode', \$status)", $src);
        self::assertStringContainsString("'on_progress'", $src);
        self::assertStringNotContainsString('Fiber::', $src);
        // Forbid naive identities×2 denominator (drafts never compile).
        self::assertStringNotContainsString('$total * 2', $src);
    }

    public function testConcurrencyClampCeilingConstants(): void
    {
        $ref = new \ReflectionClass(ThemeLayoutEntitySolidifyCompilePipeline::class);
        self::assertSame(10, $ref->getConstant('DEFAULT_CONCURRENCY'));
        self::assertSame(32, $ref->getConstant('MAX_CONCURRENCY'));
    }

    public function testWorkerScriptsExist(): void
    {
        $base = dirname(__DIR__, 3) . '/bin';
        self::assertFileExists($base . '/solidify-identity-job.php');
        self::assertFileExists($base . '/compile-identity-job.php');
        $solidify = (string)file_get_contents($base . '/solidify-identity-job.php');
        $compile = (string)file_get_contents($base . '/compile-identity-job.php');
        self::assertStringContainsString('runSolidifyJob', $solidify);
        self::assertStringContainsString('runCompileJob', $compile);
        self::assertStringContainsString('WELINE_TEMPLATE_COMPILE_NESTED', $compile);
    }

    public function testBatchPublisherCompileOptionAndPromoteSplit(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityBatchPublisher.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("options['compile']", $src);
        self::assertStringContainsString('function promote(', $src);
        self::assertStringContainsString('function rollbackPromote(', $src);
    }

    public function testBakeCoordinatorDelegatesToPipeline(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('ThemeLayoutEntitySolidifyCompilePipeline', $src);
        self::assertStringContainsString('solidifyPendingIdentity', $src);
        self::assertStringContainsString('collectRebakeWorkItems', $src);
        self::assertStringContainsString('rebakeThemesAfterInjectionCollect', $src);
        self::assertStringContainsString('Flatten multi-theme upgrade', $src);
    }
}
