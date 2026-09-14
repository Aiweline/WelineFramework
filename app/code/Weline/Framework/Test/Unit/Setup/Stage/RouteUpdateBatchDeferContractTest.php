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
        self::assertMatchesRegularExpression(
            '/flushDeferredControllerAttributes\(\).*flushBatchRouters\(\)/s',
            $src
        );
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
    }
}
