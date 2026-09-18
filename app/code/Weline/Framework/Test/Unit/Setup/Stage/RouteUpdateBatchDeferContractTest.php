<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup\Stage;

use PHPUnit\Framework\TestCase;

/**
 * Contract: full route_update collects in memory then writes once at commit.
 */
final class RouteUpdateBatchDeferContractTest extends TestCase
{
    public function testRouteUpdateStageCommitFlushesDeferredAclBeforeRouterFiles(): void
    {
        $stageFile = dirname(__DIR__, 4) . '/Setup/Stage/RouteUpdateStage.php';
        $src = (string)file_get_contents($stageFile);
        self::assertStringContainsString('enableDeferControllerAttributes', $src);
        self::assertStringContainsString('flushDeferredControllerAttributes', $src);
        self::assertStringContainsString('route_update：正在批量写入控制器 ACL', $src);
        self::assertStringContainsString('route_update：正在落盘路由文件', $src);
        self::assertMatchesRegularExpression(
            '/flushDeferredControllerAttributes\(\).*flushBatchRouters\(\)/s',
            $src
        );
        // orphan 依赖 registry：commit 不得在 after_route_collection 前 clear
        self::assertStringContainsString('禁止在此处 clear CollectedAclSourceIdsRegistry', $src);
        self::assertStringNotContainsString('CollectedAclSourceIdsRegistry::clear()', $src);
        self::assertStringNotContainsString('LiveSourceSet::clear()', $src);
    }

    public function testModuleHelperDefersControllerAttributesWhenEnabled(): void
    {
        $helperFile = dirname(__DIR__, 4) . '/Module/Helper/Data.php';
        $src = (string)file_get_contents($helperFile);
        self::assertStringContainsString('deferControllerAttributes', $src);
        self::assertStringContainsString('deferredControllerAttributesEvents', $src);
        self::assertStringContainsString('flushDeferredControllerAttributes', $src);
    }

    public function testBatchCollectQuietsPerModuleSuccessSpam(): void
    {
        $handleFile = dirname(__DIR__, 4) . '/Module/Handle.php';
        $src = (string)file_get_contents($handleFile);
        self::assertStringContainsString('路由扫描进度', $src);
        self::assertStringContainsString('isBatchMode()', $src);
        self::assertStringContainsString('isDeferControllerAttributes()', $src);
    }

    public function testAclObserverGroupsMultiModulePayload(): void
    {
        $obs = dirname(__DIR__, 5) . '/Acl/Observer/ControllerAttributes.php';
        $src = (string)file_get_contents($obs);
        self::assertStringContainsString('processModuleControllerAttributes', $src);
        self::assertStringContainsString('$byModule', $src);
        self::assertStringContainsString('FiberTaskBatch', $src);
        self::assertStringContainsString('mapModules', $src);
        self::assertStringContainsString('WELINE_ACL_FIBER_CONCURRENCY', $src);
        self::assertStringContainsString('flushAllPendingAclsBatched', $src);
        self::assertStringContainsString('releaseWorkingMemory', $src);
        self::assertStringContainsString('ACL 收集', $src);
        self::assertStringContainsString('upsertAclRowsChunked', $src);
    }

    public function testUpgradeRouteCollectUsesFiberTaskBatch(): void
    {
        $upgradeFile = dirname(__DIR__, 4) . '/Setup/Console/Setup/Upgrade.php';
        $src = (string)file_get_contents($upgradeFile);
        self::assertStringContainsString('FiberTaskBatch', $src);
        self::assertStringContainsString('mapModules', $src);
        self::assertStringContainsString('WELINE_ROUTE_FIBER_CONCURRENCY', $src);
        self::assertStringContainsString('WELINE_SETUP_FIBER_CONCURRENCY', $src);
        self::assertStringContainsString('路由 Fiber 收集', $src);
        self::assertStringContainsString('module-setup-collect', $src);
        self::assertStringContainsString('db-task-collect', $src);
    }

    public function testSchemaDiffCollectUsesFiberMapModules(): void
    {
        $file = dirname(__DIR__, 4) . '/Setup/Stage/SchemaDiffStage.php';
        $src = (string)file_get_contents($file);
        self::assertStringContainsString('FiberTaskBatch', $src);
        self::assertStringContainsString('mapModules', $src);
        self::assertStringContainsString('schema-diff-collect', $src);
        self::assertStringContainsString("keep_results' => false", $src);
    }

    public function testRouteUpdateStageUsesDiskRouteBackups(): void
    {
        $stageFile = dirname(__DIR__, 4) . '/Setup/Stage/RouteUpdateStage.php';
        $src = (string)file_get_contents($stageFile);
        self::assertStringContainsString('originalRouteBackupFiles', $src);
        self::assertStringContainsString('discardOriginalRouteBackups', $src);
        self::assertStringNotContainsString('private array $originalRouteData', $src);
    }

    public function testModuleHelperChunksDeferredAclFlush(): void
    {
        $helperFile = dirname(__DIR__, 4) . '/Module/Helper/Data.php';
        $src = (string)file_get_contents($helperFile);
        self::assertStringContainsString('DEFER_ACL_FLUSH_CHUNK', $src);
        self::assertStringContainsString('flushDeferredControllerAttributesChunk', $src);
    }

    public function testRouterHelperStreamsLargeRouteFiles(): void
    {
        $helperFile = dirname(__DIR__, 4) . '/Router/Helper/Data.php';
        $src = (string)file_get_contents($helperFile);
        self::assertStringContainsString('fwrite($fh', $src);
        self::assertStringContainsString('写入路由文件', $src);
        self::assertStringContainsString('function seedBatchFromDisk', $src);
        self::assertStringNotContainsString('AtomicCompiledFilePublisher', $src);
    }

    public function testRouteUpdateStageSeedsBatchFromDiskInFullMode(): void
    {
        $stageFile = dirname(__DIR__, 4) . '/Setup/Stage/RouteUpdateStage.php';
        $src = (string)file_get_contents($stageFile);
        self::assertStringContainsString('seedBatchFromDisk', $src);
        self::assertStringContainsString('seed_partial', $src);
        self::assertStringContainsString('skip_route_stage', $src);
    }

    public function testSchemaDiffUsesSourceFingerprintSkip(): void
    {
        $file = dirname(__DIR__, 4) . '/Setup/Stage/SchemaDiffStage.php';
        $src = (string)file_get_contents($file);
        self::assertStringContainsString('SetupSourceFingerprint', $src);
        self::assertStringContainsString('schema:', $src);
        self::assertStringContainsString("keep_results' => false", $src);
    }
}
