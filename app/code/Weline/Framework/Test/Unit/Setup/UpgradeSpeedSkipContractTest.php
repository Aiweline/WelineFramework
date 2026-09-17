<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;

/**
 * setup:upgrade 跳过未变更：P0 路由 seed / ACL touched / 概览 / force-optimize 契约。
 */
final class UpgradeSpeedSkipContractTest extends TestCase
{
    public function testRouterHelperSeedsBatchFromDiskAndClearsInBatchMode(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Router/Helper/Data.php'
        );
        self::assertStringContainsString('function seedBatchFromDisk', $src);
        self::assertStringContainsString('批量模式：只改内存 batch', $src);
    }

    public function testRouteUpdateStageSupportsSeedPartialAndSkipWrite(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Setup/Stage/RouteUpdateStage.php'
        );
        self::assertStringContainsString('seed_partial', $src);
        self::assertStringContainsString('seedBatchFromDisk', $src);
        self::assertStringContainsString('skip_route_stage', $src);
    }

    public function testUpgradeWiresRouteFingerprintTouchedModulesAndForceOptimize(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Setup/Console/Setup/Upgrade.php'
        );
        self::assertStringContainsString('resolveRouteFingerprintPlan', $src);
        self::assertStringContainsString('seed_partial', $src);
        self::assertStringContainsString("'touched_modules' => \$touchedForAcl", $src);
        self::assertStringContainsString('force-optimize', $src);
        self::assertStringContainsString('OPTIMIZE_STAMP_KEY', $src);
        self::assertStringContainsString('seedBatchFromDisk', $src);
        self::assertStringContainsString('SetupUpgradeMetrics', $src);
        self::assertStringContainsString('printOverview', $src);
    }

    public function testSchemaDiffAllFreshSkipAndNoPartialEmptyDeclarations(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Setup/Stage/SchemaDiffStage.php'
        );
        self::assertStringContainsString('全部模块 Model 源指纹未变', $src);
        self::assertStringContainsString('wasSourceFingerprintSkipped', $src);
        self::assertStringNotContainsString("\$bag['skipped'] = true", $src);
    }

    public function testNotFoundObserverAlignsPartialUpgrade(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/Observer/SetupUpgradeAfterPublishNotFoundStatic.php'
        );
        self::assertStringContainsString('is_partial_upgrade', $src);
    }

    public function testStaticPublishHashesUseStableFileFingerprintHelper(): void
    {
        $src404 = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/Service/StorefrontNotFoundStaticGenerator.php'
        );
        self::assertStringContainsString('computePublishInputHash', $src404);
        self::assertStringContainsString('StaticErrorPagePublishFingerprint', $src404);
        self::assertStringContainsString('trySkipEntirePublishAll', $src404);
        self::assertStringNotContainsString('DictionaryCacheNamespace::fingerprint', $src404);

        $srcMaint = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Maintenance/Service/MaintenanceStaticGenerator.php'
        );
        self::assertStringContainsString('computeMaintenancePublishInputHash', $srcMaint);
        self::assertStringContainsString('trySkipEntirePublishAll', $srcMaint);

        $srcFp = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Http/StaticErrorPagePublishFingerprint.php'
        );
        self::assertStringContainsString('404v5|', $srcFp);
        self::assertStringContainsString('maintv3|', $srcFp);
        self::assertStringContainsString('localeDictionaryToken', $srcFp);
        self::assertStringContainsString('mergeUpdates', (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Setup/Service/SetupSourceFingerprint.php'
        ));
    }

    public function testSkipRequiresDestinationArtifacts(): void
    {
        $upgrade = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Setup/Console/Setup/Upgrade.php'
        );
        self::assertStringContainsString('routeArtifactsPresent', $upgrade);
        self::assertStringContainsString('路由磁盘产物缺失或为空', $upgrade);

        $menu = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Backend/Config/MenuXmlReader.php'
        );
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

        $fp = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Setup/Service/SetupSourceFingerprint.php'
        );
        self::assertStringContainsString('forgetByPrefix', $fp);
        self::assertStringContainsString('磁盘仓被外部删除时', $fp);

        $phrase = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/I18n/Observer/SetupUpgradeCollectTranslations.php'
        );
        self::assertStringContainsString('phraseArtifactsPresent', $phrase);

        $schema = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Setup/Stage/SchemaDiffStage.php'
        );
        self::assertStringContainsString('schemaCheckpointsPresent', $schema);

        $staticFp = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Http/StaticErrorPagePublishFingerprint.php'
        );
        self::assertStringContainsString('is_file($primaryPath)', $staticFp);
    }
}
