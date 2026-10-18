<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Console\Setup\Upgrade;
use Weline\Framework\Setup\Lock\SetupUpgradeIntent;
use Weline\Framework\Setup\Model\ModuleTable;

/**
 * Aggregated contract suite (merged from 11 *ContractTest files).
 * Observe one overall pass/fail via testAllSetupContractsPass().
 */
final class SetupContractsTest extends TestCase
{
    public function testAllSetupContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'UpgradeRegistryDedupe::testResolveRegistryCollectScopeSkipsFullWhenMinusMUnknown' => function () {
            $this->setUp_UpgradeRegistryDedupe();
            $this->assert_UpgradeRegistryDedupe_testResolveRegistryCollectScopeSkipsFullWhenMinusMUnknown();
        },
            'UpgradeRegistryDedupe::testUpgradeSourceRemovesMidDuplicateAndSetsStep2Flag' => function () {
            $this->setUp_UpgradeRegistryDedupe();
            $this->assert_UpgradeRegistryDedupe_testUpgradeSourceRemovesMidDuplicateAndSetsStep2Flag();
        },
            'ForceMenuEventPayload::testUpgradeDoesNotSoftPullMenuXmlReader' => function () {
            $this->assert_ForceMenuEventPayload_testUpgradeDoesNotSoftPullMenuXmlReader();
        },
            'ForceMenuEventPayload::testFrameworkBackendRestAliasRemoved' => function () {
            $this->assert_ForceMenuEventPayload_testFrameworkBackendRestAliasRemoved();
        },
            'SetupUpgradeIntent::testPublishOwnsAndClearIfOwner' => function () {
            $this->setUp_SetupUpgradeIntent();
            try {
                $this->assert_SetupUpgradeIntent_testPublishOwnsAndClearIfOwner();
            } finally {
                $this->tearDown_SetupUpgradeIntent();
            }
        },
            'SetupUpgradeIntent::testDeadPidIntentIsReclaimed' => function () {
            $this->setUp_SetupUpgradeIntent();
            try {
                $this->assert_SetupUpgradeIntent_testDeadPidIntentIsReclaimed();
            } finally {
                $this->tearDown_SetupUpgradeIntent();
            }
        },
            'SetupUpgradeIntent::testExclusiveWaitOverride' => function () {
            $this->setUp_SetupUpgradeIntent();
            try {
                $this->assert_SetupUpgradeIntent_testExclusiveWaitOverride();
            } finally {
                $this->tearDown_SetupUpgradeIntent();
            }
        },
            'ModuleTableConflictIndex::testDeclaresTheCompositeUniqueConflictTarget' => function () {
            $this->assert_ModuleTableConflictIndex_testDeclaresTheCompositeUniqueConflictTarget();
        },
            'SetupSucceededReleaseHardFail::testUpgradeMarksNonZeroExitWhenSucceededReleaseThrows' => function () {
            $this->assert_SetupSucceededReleaseHardFail_testUpgradeMarksNonZeroExitWhenSucceededReleaseThrows();
        },
            'ModuleSetupStageProgress::testCommitPrintsPerModuleProgress' => function () {
            $this->assert_ModuleSetupStageProgress_testCommitPrintsPerModuleProgress();
        },
            'ModuleSetupStageProgress::testUpgradePrintsBridgeAfterFileMigrations' => function () {
            $this->assert_ModuleSetupStageProgress_testUpgradePrintsBridgeAfterFileMigrations();
        },
            'RouteUpdateBatchDefer::testRouteUpdateStageCommitFlushesDeferredAclBeforeRouterFiles' => function () {
            $this->assert_RouteUpdateBatchDefer_testRouteUpdateStageCommitFlushesDeferredAclBeforeRouterFiles();
        },
            'RouteUpdateBatchDefer::testPartialRouteCommitGuardsAgainstEmptyModuleRepopulation' => function () {
            $this->assert_RouteUpdateBatchDefer_testPartialRouteCommitGuardsAgainstEmptyModuleRepopulation();
        },
            'RouteUpdateBatchDefer::testModuleHelperDefersControllerAttributesWhenEnabled' => function () {
            $this->assert_RouteUpdateBatchDefer_testModuleHelperDefersControllerAttributesWhenEnabled();
        },
            'RouteUpdateBatchDefer::testBatchCollectQuietsPerModuleSuccessSpam' => function () {
            $this->assert_RouteUpdateBatchDefer_testBatchCollectQuietsPerModuleSuccessSpam();
        },
            'RouteUpdateBatchDefer::testAclObserverGroupsMultiModulePayload' => function () {
            $this->assert_RouteUpdateBatchDefer_testAclObserverGroupsMultiModulePayload();
        },
            'RouteUpdateBatchDefer::testUpgradeRouteCollectUsesFiberTaskBatch' => function () {
            $this->assert_RouteUpdateBatchDefer_testUpgradeRouteCollectUsesFiberTaskBatch();
        },
            'RouteUpdateBatchDefer::testSchemaDiffCollectUsesFiberMapModules' => function () {
            $this->assert_RouteUpdateBatchDefer_testSchemaDiffCollectUsesFiberMapModules();
        },
            'RouteUpdateBatchDefer::testRouteUpdateStageUsesDiskRouteBackups' => function () {
            $this->assert_RouteUpdateBatchDefer_testRouteUpdateStageUsesDiskRouteBackups();
        },
            'RouteUpdateBatchDefer::testModuleHelperChunksDeferredAclFlush' => function () {
            $this->assert_RouteUpdateBatchDefer_testModuleHelperChunksDeferredAclFlush();
        },
            'RouteUpdateBatchDefer::testRouterHelperStreamsLargeRouteFiles' => function () {
            $this->assert_RouteUpdateBatchDefer_testRouterHelperStreamsLargeRouteFiles();
        },
            'RouteUpdateBatchDefer::testRouteUpdateStageSeedsBatchFromDiskInFullMode' => function () {
            $this->assert_RouteUpdateBatchDefer_testRouteUpdateStageSeedsBatchFromDiskInFullMode();
        },
            'RouteUpdateBatchDefer::testSchemaDiffUsesSourceFingerprintSkip' => function () {
            $this->assert_RouteUpdateBatchDefer_testSchemaDiffUsesSourceFingerprintSkip();
        },
            'UpgradeMaintenanceClose::testCleanupRequiresWlsSyncBeforeClaimingClosed' => function () {
            $this->assert_UpgradeMaintenanceClose_testCleanupRequiresWlsSyncBeforeClaimingClosed();
        },
            'UpgradeMigrationsEvent::testMigrationEventIsCataloguedSubscribedAndOrdered' => function () {
            $this->assert_UpgradeMigrationsEvent_testMigrationEventIsCataloguedSubscribedAndOrdered();
        },
            'UpgradeMigrationsEvent::testUpgradeAfterPayloadIsPassedThroughAReferenceCompatibleVariable' => function () {
            $this->assert_UpgradeMigrationsEvent_testUpgradeAfterPayloadIsPassedThroughAReferenceCompatibleVariable();
        },
            'UpgradeRouteSchemaRefresh::testRouteOnlySchemaDiffUsesAHandleCreatedAfterModuleRegistryRefresh' => function () {
            $this->assert_UpgradeRouteSchemaRefresh_testRouteOnlySchemaDiffUsesAHandleCreatedAfterModuleRegistryRefresh();
        },
            'UpgradeRouteSchemaRefresh::testRegistryBootstrapSyncsCodeVersionWithoutAdvancingSetupCursor' => function () {
            $this->assert_UpgradeRouteSchemaRefresh_testRegistryBootstrapSyncsCodeVersionWithoutAdvancingSetupCursor();
        },
            'UpgradeSpeedSkip::testRouterHelperSeedsBatchFromDiskAndClearsInBatchMode' => function () {
            $this->assert_UpgradeSpeedSkip_testRouterHelperSeedsBatchFromDiskAndClearsInBatchMode();
        },
            'UpgradeSpeedSkip::testRouteUpdateStageSupportsSeedPartialAndSkipWrite' => function () {
            $this->assert_UpgradeSpeedSkip_testRouteUpdateStageSupportsSeedPartialAndSkipWrite();
        },
            'UpgradeSpeedSkip::testUpgradeWiresRouteFingerprintTouchedModulesAndForceOptimize' => function () {
            $this->assert_UpgradeSpeedSkip_testUpgradeWiresRouteFingerprintTouchedModulesAndForceOptimize();
        },
            'UpgradeSpeedSkip::testSchemaDiffAllFreshSkipAndNoPartialEmptyDeclarations' => function () {
            $this->assert_UpgradeSpeedSkip_testSchemaDiffAllFreshSkipAndNoPartialEmptyDeclarations();
        },
            'UpgradeSpeedSkip::testNotFoundObserverAlignsPartialUpgrade' => function () {
            $this->assert_UpgradeSpeedSkip_testNotFoundObserverAlignsPartialUpgrade();
        },
            'UpgradeSpeedSkip::testMaintenancePublishHashesUseStableFileFingerprintHelper' => function () {
            $this->assert_UpgradeSpeedSkip_testMaintenancePublishHashesUseStableFileFingerprintHelper();
        },
            'UpgradeSpeedSkip::testSkipRequiresDestinationArtifacts' => function () {
            $this->assert_UpgradeSpeedSkip_testSkipRequiresDestinationArtifacts();
        },
        ];
    }

private Upgrade $upgrade_UpgradeRegistryDedupe;

private function setUp_UpgradeRegistryDedupe(): void
{
    parent::setUp();
    /** @var Printing&MockObject $printing */
    $printing = $this->createMock(Printing::class);
    $this->upgrade_UpgradeRegistryDedupe = new Upgrade($printing);
}

private function assert_UpgradeRegistryDedupe_testResolveRegistryCollectScopeSkipsFullWhenMinusMUnknown(): void
{
    $method = new \ReflectionMethod($this->upgrade_UpgradeRegistryDedupe, 'resolveRegistryCollectScope');
    $method->setAccessible(true);
    self::assertSame(['modules' => [], 'skip_bootstrap_collect' => false], $method->invoke($this->upgrade_UpgradeRegistryDedupe, [], ['Weline_Theme']));
    self::assertSame(['modules' => ['Weline_Theme'], 'skip_bootstrap_collect' => false], $method->invoke($this->upgrade_UpgradeRegistryDedupe, ['Weline_Theme', 'Ghost'], ['Weline_Theme', 'Weline_Faq']));
    self::assertSame(['modules' => [], 'skip_bootstrap_collect' => true], $method->invoke($this->upgrade_UpgradeRegistryDedupe, ['Ghost_Module'], ['Weline_Theme']));
    self::assertSame(['modules' => ['Ghost_Module'], 'skip_bootstrap_collect' => false], $method->invoke($this->upgrade_UpgradeRegistryDedupe, ['Ghost_Module'], []));
}

private function assert_UpgradeRegistryDedupe_testUpgradeSourceRemovesMidDuplicateAndSetsStep2Flag(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Console/Setup/Upgrade.php');
    self::assertIsString($src);
    self::assertStringNotContainsString("section('setup:upgrade module registry incremental refresh')", $src);
    self::assertStringContainsString('跳过重复的中段注册表增量', $src);
    self::assertStringContainsString("section('setup:upgrade step 2 incremental registry refresh')", $src);
    self::assertMatchesRegularExpression('/setup:upgrade step 2 (?:incremental|full) registry refresh[\s\S]{0,1500}registryCollectedInThisRun\s*=\s*true/', $src);
    self::assertStringContainsString('route-only registry incremental', $src);
}

private function assert_ForceMenuEventPayload_testUpgradeDoesNotSoftPullMenuXmlReader(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Console/Setup/Upgrade.php');
    self::assertStringNotContainsString('MenuXmlReader::', $src);
    self::assertStringContainsString('withForceMenuEventData', $src);
    self::assertStringContainsString("'force_menu'", $src);
    self::assertStringContainsString('force_menu_release', $src);
}

private function assert_ForceMenuEventPayload_testFrameworkBackendRestAliasRemoved(): void
{
    $alias = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/App/Controller/BackendRestController.php';
    self::assertFileDoesNotExist($alias);
    $frameworkQueryAlias = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Controller/Backend/Api/Query.php');
    self::assertStringContainsString('Weline\Backend\Controller\Api\Query', $frameworkQueryAlias);
    $query = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Backend/Controller/Api/Query.php');
    self::assertStringContainsString('Weline\Backend\Controller\BackendRestController', $query);
    self::assertStringNotContainsString('Framework\App\Controller\BackendRestController', $query);
}

private function setUpBeforeClass_SetupUpgradeIntent(): void
{
    parent::setUpBeforeClass();
    if (!\defined('BP')) {
        $dir = __DIR__;
        while ($dir !== \dirname($dir)) {
            if (\is_file($dir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'w')) {
                \define('BP', $dir . DIRECTORY_SEPARATOR);
                break;
            }
            $dir = \dirname($dir);
        }
    }
    self::assertTrue(\defined('BP'), 'BP must resolve to repository root');
}

private function setUp_SetupUpgradeIntent(): void
{
    parent::setUp();
    SetupUpgradeIntent::setTestExclusiveWaitMilliseconds(null);
    SetupUpgradeIntent::clearIfOwner();
    $path = SetupUpgradeIntent::path();
    if (is_file($path)) {
        @unlink($path);
    }
}

private function tearDown_SetupUpgradeIntent(): void
{
    SetupUpgradeIntent::clearIfOwner();
    $path = SetupUpgradeIntent::path();
    if (is_file($path)) {
        @unlink($path);
    }
    SetupUpgradeIntent::setTestExclusiveWaitMilliseconds(null);
    parent::tearDown();
}

private function assert_SetupUpgradeIntent_testPublishOwnsAndClearIfOwner(): void
{
    self::assertFalse(SetupUpgradeIntent::isActive());
    self::assertTrue(SetupUpgradeIntent::publish('setup:upgrade'));
    self::assertTrue(SetupUpgradeIntent::isActive());
    self::assertTrue(SetupUpgradeIntent::isOwnedByCurrentProcess());
    self::assertTrue(SetupUpgradeIntent::shouldYield());
    SetupUpgradeIntent::clearIfOwner();
    self::assertFalse(SetupUpgradeIntent::isActive());
    self::assertFileDoesNotExist(SetupUpgradeIntent::path());
}

private function assert_SetupUpgradeIntent_testDeadPidIntentIsReclaimed(): void
{
    $path = SetupUpgradeIntent::path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode(['pid' => 999999991, 'started_at' => microtime(true), 'command' => 'setup:upgrade'], JSON_UNESCAPED_UNICODE) . "\n");
    self::assertFalse(SetupUpgradeIntent::isActive());
    self::assertFileDoesNotExist($path);
}

private function assert_SetupUpgradeIntent_testExclusiveWaitOverride(): void
{
    SetupUpgradeIntent::setTestExclusiveWaitMilliseconds(1234);
    self::assertSame(1234, SetupUpgradeIntent::exclusiveWaitMilliseconds());
    SetupUpgradeIntent::setTestExclusiveWaitMilliseconds(null);
    self::assertSame(SetupUpgradeIntent::DEFAULT_EXCLUSIVE_WAIT_MS, SetupUpgradeIntent::exclusiveWaitMilliseconds());
}

private function assert_ModuleTableConflictIndex_testDeclaresTheCompositeUniqueConflictTarget(): void
{
    $indexes = array_map(static fn(\ReflectionAttribute $attribute): Index => $attribute->newInstance(), (new \ReflectionClass(ModuleTable::class))->getAttributes(Index::class));
    $hasConflictTarget = false;
    foreach ($indexes as $index) {
        if (strtoupper($index->type) === 'UNIQUE' && array_values((array) $index->columns) === ['name', 'model']) {
            $hasConflictTarget = true;
            break;
        }
    }
    self::assertTrue($hasConflictTarget);
}

private function assert_SetupSucceededReleaseHardFail_testUpgradeMarksNonZeroExitWhenSucceededReleaseThrows(): void
{
    // Test/Unit/Setup → Framework root (3 levels), then Setup console.
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Console/Setup/Upgrade.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('setup 成功释放 schema_diff operation_id 绑定失败', $source);
    self::assertStringContainsString('$setupReleaseFailed', $source);
    self::assertStringContainsString('$exitCode = $exitCode === 0 ? 1 : $exitCode', $source);
    self::assertStringContainsString('B1: release failure must not look like a green upgrade', $source);
    // Must not treat release failure as a soft warning-only success path.
    $warningOnly = preg_match('/catch\s*\(\s*\\\\Throwable\s+\$releaseError\s*\)\s*\{[^}]*printing->warning\([^;]*释放 schema_diff operation_id/', $source);
    self::assertSame(0, $warningOnly);
    self::assertStringContainsString('printing->error', $source);
}

private function assert_ModuleSetupStageProgress_testCommitPrintsPerModuleProgress(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Stage/ModuleSetupStage.php');
    self::assertStringContainsString('ModuleSetup [%{i}/%{total}] Install %{module}', $src);
    self::assertStringContainsString('ModuleSetup [%{i}/%{total}] Upgrade %{module}', $src);
    self::assertStringContainsString('模块安装/升级阶段开始', $src);
    self::assertStringContainsString('flushCli', $src);
}

private function assert_ModuleSetupStageProgress_testUpgradePrintsBridgeAfterFileMigrations(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Console/Setup/Upgrade.php');
    self::assertStringContainsString('模块文件迁移检查结束，进入 ModuleSetup', $src);
    self::assertStringContainsString('待执行任务 %{2} 个', $src);
}

private function assert_RouteUpdateBatchDefer_testRouteUpdateStageCommitFlushesDeferredAclBeforeRouterFiles(): void
{
    $stageFile = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Stage/RouteUpdateStage.php';
    $src = (string) file_get_contents($stageFile);
    self::assertStringContainsString('enableDeferControllerAttributes', $src);
    self::assertStringContainsString('flushDeferredControllerAttributes', $src);
    self::assertStringContainsString('route_update：正在批量写入控制器 ACL', $src);
    self::assertStringContainsString('route_update：正在落盘路由文件', $src);
    self::assertMatchesRegularExpression('/flushDeferredControllerAttributes\(\).*flushBatchRouters\(\)/s', $src);
    // orphan 依赖 registry：commit 不得在 after_route_collection 前 clear
    self::assertStringContainsString('禁止在此处 clear CollectedAclSourceIdsRegistry', $src);
    self::assertStringNotContainsString('CollectedAclSourceIdsRegistry::clear()', $src);
    self::assertStringNotContainsString('LiveSourceSet::clear()', $src);
}

private function assert_RouteUpdateBatchDefer_testPartialRouteCommitGuardsAgainstEmptyModuleRepopulation(): void
{
    $stageFile = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Stage/RouteUpdateStage.php';
    $src = (string) file_get_contents($stageFile);
    self::assertStringContainsString('assertPartialClearedModulesRepopulated', $src);
    self::assertStringContainsString('countModuleRoutesInBackupFiles', $src);
    self::assertStringContainsString('countModuleRoutesOnDisk', $src);
    self::assertStringContainsString('增量路由更新后模块路由仍为空', $src);
    self::assertMatchesRegularExpression('/if \(\$isPartial\) \{\s*\/\/ 门禁：清理前有路由的模块.*?assertPartialClearedModulesRepopulated/s', $src);
}

private function assert_RouteUpdateBatchDefer_testModuleHelperDefersControllerAttributesWhenEnabled(): void
{
    $helperFile = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Module/Helper/Data.php';
    $src = (string) file_get_contents($helperFile);
    self::assertStringContainsString('deferControllerAttributes', $src);
    self::assertStringContainsString('deferredControllerAttributesEvents', $src);
    self::assertStringContainsString('flushDeferredControllerAttributes', $src);
}

private function assert_RouteUpdateBatchDefer_testBatchCollectQuietsPerModuleSuccessSpam(): void
{
    $handleFile = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Module/Handle.php';
    $src = (string) file_get_contents($handleFile);
    self::assertStringContainsString('路由扫描进度', $src);
    self::assertStringContainsString('isBatchMode()', $src);
    self::assertStringContainsString('isDeferControllerAttributes()', $src);
}

private function assert_RouteUpdateBatchDefer_testAclObserverGroupsMultiModulePayload(): void
{
    $obs = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Acl/Observer/ControllerAttributes.php';
    $src = (string) file_get_contents($obs);
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

private function assert_RouteUpdateBatchDefer_testUpgradeRouteCollectUsesFiberTaskBatch(): void
{
    $upgradeFile = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Console/Setup/Upgrade.php';
    $src = (string) file_get_contents($upgradeFile);
    self::assertStringContainsString('FiberTaskBatch', $src);
    self::assertStringContainsString('mapModules', $src);
    self::assertStringContainsString('WELINE_ROUTE_FIBER_CONCURRENCY', $src);
    self::assertStringContainsString('WELINE_SETUP_FIBER_CONCURRENCY', $src);
    self::assertStringContainsString('路由 Fiber 收集', $src);
    self::assertStringContainsString('module-setup-collect', $src);
    self::assertStringContainsString('db-task-collect', $src);
}

private function assert_RouteUpdateBatchDefer_testSchemaDiffCollectUsesFiberMapModules(): void
{
    $file = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Stage/SchemaDiffStage.php';
    $src = (string) file_get_contents($file);
    self::assertStringContainsString('FiberTaskBatch', $src);
    self::assertStringContainsString('mapModules', $src);
    self::assertStringContainsString('schema-diff-collect', $src);
    self::assertStringContainsString("keep_results' => false", $src);
}

private function assert_RouteUpdateBatchDefer_testRouteUpdateStageUsesDiskRouteBackups(): void
{
    $stageFile = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Stage/RouteUpdateStage.php';
    $src = (string) file_get_contents($stageFile);
    self::assertStringContainsString('originalRouteBackupFiles', $src);
    self::assertStringContainsString('discardOriginalRouteBackups', $src);
    self::assertStringNotContainsString('private array $originalRouteData', $src);
}

private function assert_RouteUpdateBatchDefer_testModuleHelperChunksDeferredAclFlush(): void
{
    $helperFile = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Module/Helper/Data.php';
    $src = (string) file_get_contents($helperFile);
    self::assertStringContainsString('DEFER_ACL_FLUSH_CHUNK', $src);
    self::assertStringContainsString('flushDeferredControllerAttributesChunk', $src);
    self::assertStringContainsString('禁止清空 collected_route_registrations', $src);
    self::assertDoesNotMatchRegularExpression(
        '/flushDeferredControllerAttributesInternal.*?collected_route_registrations\s*=\s*\[\s*\]/s',
        $src,
        'ACL mid-scan flush must not wipe in-flight route registrations'
    );
}

private function assert_RouteUpdateBatchDefer_testRouterHelperStreamsLargeRouteFiles(): void
{
    $helperFile = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Router/Helper/Data.php';
    $src = (string) file_get_contents($helperFile);
    self::assertStringContainsString('fwrite($fh', $src);
    self::assertStringContainsString('写入路由文件', $src);
    self::assertStringContainsString('function seedBatchFromDisk', $src);
    self::assertStringNotContainsString('AtomicCompiledFilePublisher', $src);
}

private function assert_RouteUpdateBatchDefer_testRouteUpdateStageSeedsBatchFromDiskInFullMode(): void
{
    $stageFile = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Stage/RouteUpdateStage.php';
    $src = (string) file_get_contents($stageFile);
    self::assertStringContainsString('seedBatchFromDisk', $src);
    self::assertStringContainsString('seed_partial', $src);
    self::assertStringContainsString('skip_route_stage', $src);
}

private function assert_RouteUpdateBatchDefer_testSchemaDiffUsesSourceFingerprintSkip(): void
{
    $file = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Stage/SchemaDiffStage.php';
    $src = (string) file_get_contents($file);
    self::assertStringContainsString('SetupSourceFingerprint', $src);
    self::assertStringContainsString('schema:', $src);
    self::assertStringContainsString("keep_results' => false", $src);
}

private function assert_UpgradeMaintenanceClose_testCleanupRequiresWlsSyncBeforeClaimingClosed(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Console/Setup/Upgrade.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('private function syncWlsMaintenanceMode(bool $enabled): bool', $src);
    self::assertStringContainsString('$wlsOk = $this->syncWlsMaintenanceMode(false)', $src);
    self::assertStringContainsString('$shouldClearMaintenance = $maintenanceEnabled', $src);
    self::assertStringContainsString("getConfig('system.maintenance', false)", $src);
    self::assertStringContainsString('框架维护标志已关闭，但 WLS Worker 门禁未完全同步', $src);
    self::assertStringNotContainsString('$this->notifyWlsReload()', $src);
}

private function assert_UpgradeMigrationsEvent_testMigrationEventIsCataloguedSubscribedAndOrdered(): void
{
    $frameworkRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Framework';
    $databaseRoot = \dirname($frameworkRoot) . '/Database';
    $catalog = (string) \file_get_contents($frameworkRoot . '/event.php');
    $upgrade = (string) \file_get_contents($frameworkRoot . '/Setup/Console/Setup/Upgrade.php');
    $databaseEvents = (string) \file_get_contents($databaseRoot . '/etc/event.xml');
    self::assertStringContainsString("'Weline_Framework_Setup::upgrade_migrations' => [", $catalog);
    self::assertSame(1, \preg_match('/<event name="Weline_Framework_Setup::upgrade_migrations">.*?' . 'Weline\\\\Database\\\\Observer\\\\SetupUpgradeObserver.*?<\/event>/s', $databaseEvents));
    self::assertStringContainsString("\$eventsManager->dispatch('Weline_Framework_Setup::upgrade_migrations'", $upgrade);
    $migrationCall = \strpos($upgrade, '$this->runDatabaseFileMigrations($args, $argsModule);');
    self::assertIsInt($migrationCall);
    $schemaCommit = \strrpos(\substr($upgrade, 0, $migrationCall), '$schemaDiffStage->commit();');
    $moduleSetupCommit = \strpos($upgrade, '$moduleSetupStage->commit();', $migrationCall);
    self::assertIsInt($schemaCommit);
    self::assertIsInt($moduleSetupCommit);
    self::assertLessThan($migrationCall, $schemaCommit);
    self::assertLessThan($moduleSetupCommit, $migrationCall);
}

private function assert_UpgradeMigrationsEvent_testUpgradeAfterPayloadIsPassedThroughAReferenceCompatibleVariable(): void
{
    $frameworkRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Framework';
    $upgrade = (string) \file_get_contents($frameworkRoot . '/Setup/Console/Setup/Upgrade.php');
    self::assertSame(2, \substr_count($upgrade, '$upgradeAfterPayload = ['));
    self::assertSame(2, \substr_count($upgrade, "\$eventsManager->dispatch('Weline_Framework_Setup::upgrade_after', \$upgradeAfterPayload);"));
    self::assertStringNotContainsString("\$eventsManager->dispatch('Weline_Framework_Setup::upgrade_after', [", $upgrade);
}

private function assert_UpgradeRouteSchemaRefresh_testRouteOnlySchemaDiffUsesAHandleCreatedAfterModuleRegistryRefresh(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php');
    $routeBranch = strpos($source, 'if ($doRoute) {');
    $refresh = strpos($source, 'Env::getInstance()->getModuleList(true);', $routeBranch);
    $discard = strpos($source, 'ObjectManager::removeInstance(Handle::class);', $refresh);
    $handle = strpos($source, '$routeModuleHandle = ObjectManager::getInstance(Handle::class);', $discard);
    $schemaPrepare = strpos($source, '$schemaDiffStage->prepare($setupStageContext);', $handle);
    self::assertIsInt($routeBranch);
    self::assertIsInt($refresh);
    self::assertIsInt($discard);
    self::assertIsInt($handle);
    self::assertIsInt($schemaPrepare);
    self::assertLessThan($discard, $refresh);
    self::assertLessThan($handle, $discard);
    self::assertLessThan($schemaPrepare, $handle);
}

private function assert_UpgradeRouteSchemaRefresh_testRegistryBootstrapSyncsCodeVersionWithoutAdvancingSetupCursor(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php');
    $bootstrap = strpos($source, 'private function preRegisterDiscoveredModulesForRegistryBootstrap');
    $manifest = strpos($source, "\$manifestFile = \$localBasePath . 'etc'", $bootstrap);
    $versionWrite = strpos($source, "\$currentModules[\$moduleName]['version'] = \$codeVersion;", $manifest);
    $moduleWrite = strpos($source, '$moduleData->updateModules($currentModules);', $versionWrite);
    self::assertIsInt($bootstrap);
    self::assertIsInt($manifest);
    self::assertIsInt($versionWrite);
    self::assertIsInt($moduleWrite);
    self::assertLessThan($versionWrite, $manifest);
    self::assertLessThan($moduleWrite, $versionWrite);
    self::assertStringNotContainsString("\$currentModules[\$moduleName]['setup_version'] = \$codeVersion;", substr($source, $bootstrap, $moduleWrite - $bootstrap));
}

private function assert_UpgradeSpeedSkip_testRouterHelperSeedsBatchFromDiskAndClearsInBatchMode(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Router/Helper/Data.php');
    self::assertStringContainsString('function seedBatchFromDisk', $src);
    self::assertStringContainsString('批量模式：只改内存 batch', $src);
}

private function assert_UpgradeSpeedSkip_testRouteUpdateStageSupportsSeedPartialAndSkipWrite(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Stage/RouteUpdateStage.php');
    self::assertStringContainsString('seed_partial', $src);
    self::assertStringContainsString('seedBatchFromDisk', $src);
    self::assertStringContainsString('skip_route_stage', $src);
}

private function assert_UpgradeSpeedSkip_testUpgradeWiresRouteFingerprintTouchedModulesAndForceOptimize(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Console/Setup/Upgrade.php');
    self::assertStringContainsString('resolveRouteFingerprintPlan', $src);
    self::assertStringContainsString('seed_partial', $src);
    self::assertStringContainsString("'touched_modules' => \$touchedForAcl", $src);
    self::assertStringContainsString('flushDeferredControllerAttributes', $src);
    self::assertStringContainsString('orphan 前落盘暂存控制器 ACL', $src);
    self::assertMatchesRegularExpression('/flushDeferredControllerAttributes\(\).*after_route_collection/s', $src);
    self::assertStringContainsString('force-optimize', $src);
    self::assertStringContainsString('OPTIMIZE_STAMP_KEY', $src);
    self::assertStringContainsString('seedBatchFromDisk', $src);
    self::assertStringContainsString('SetupUpgradeMetrics', $src);
    self::assertStringContainsString('printOverview', $src);
}

private function assert_UpgradeSpeedSkip_testSchemaDiffAllFreshSkipAndNoPartialEmptyDeclarations(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Stage/SchemaDiffStage.php');
    self::assertStringContainsString('全部模块 Model 源指纹未变', $src);
    self::assertStringContainsString('wasSourceFingerprintSkipped', $src);
    self::assertStringNotContainsString("\$bag['skipped'] = true", $src);
}

private function assert_UpgradeSpeedSkip_testNotFoundObserverAlignsPartialUpgrade(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/Observer/SetupUpgradeAfterPublishNotFoundStatic.php');
    self::assertStringContainsString('is_partial_upgrade', $src);
}

private function assert_UpgradeSpeedSkip_testMaintenancePublishHashesUseStableFileFingerprintHelper(): void
{
    $srcMaint = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Maintenance/Service/MaintenanceStaticGenerator.php');
    self::assertStringContainsString('computeMaintenancePublishInputHash', $srcMaint);
    self::assertStringContainsString('trySkipEntirePublishAll', $srcMaint);
    $srcFp = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/StaticErrorPagePublishFingerprint.php');
    self::assertStringContainsString('maintv3|', $srcFp);
    self::assertStringContainsString('localeDictionaryToken', $srcFp);
    self::assertStringContainsString('mergeUpdates', (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Service/SetupSourceFingerprint.php'));
}

private function assert_UpgradeSpeedSkip_testSkipRequiresDestinationArtifacts(): void
{
    $upgrade = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Console/Setup/Upgrade.php');
    self::assertStringContainsString('routeArtifactsPresent', $upgrade);
    self::assertStringContainsString('路由磁盘产物缺失或为空', $upgrade);
    self::assertStringContainsString('detectPcRouteOrphanModules', $upgrade);
    self::assertStringContainsString('moduleHasPcControllerCandidates', $upgrade);
    self::assertStringContainsString('PC 路由孤儿模块', $upgrade);
    $menu = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Backend/Config/MenuXmlReader.php');
    self::assertStringContainsString('destinationMenusPresent', $menu);
    self::assertStringContainsString('菜单 ACL 产物为空', $menu);
    self::assertStringContainsString('requestForceFull', $menu);
    self::assertStringContainsString('forceFullSticky', $menu);
    self::assertStringContainsString('beginForceFullHold', $menu);
    self::assertStringContainsString('commitPendingFingerprints', $menu);
    self::assertStringContainsString('pendingFingerprints', $menu);
    self::assertStringContainsString('menu:dest:', $menu);
    self::assertStringContainsString('destinationFingerprintForModule', $menu);
    self::assertStringContainsString('assertForceMenuBeforeCollect', $upgrade);
    self::assertStringContainsString('force-menu', $upgrade);
    self::assertStringContainsString('MenuXmlReader::requestForceFull', $upgrade);
    $fp = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Service/SetupSourceFingerprint.php');
    self::assertStringContainsString('forgetByPrefix', $fp);
    self::assertStringContainsString('磁盘仓被外部删除时', $fp);
    $phrase = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/I18n/Observer/SetupUpgradeCollectTranslations.php');
    self::assertStringContainsString('phraseArtifactsPresent', $phrase);
    $schema = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Setup/Stage/SchemaDiffStage.php');
    self::assertStringContainsString('schemaCheckpointsPresent', $schema);
    $staticFp = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/StaticErrorPagePublishFingerprint.php');
    self::assertStringContainsString('is_file($primaryPath)', $staticFp);
}
}
