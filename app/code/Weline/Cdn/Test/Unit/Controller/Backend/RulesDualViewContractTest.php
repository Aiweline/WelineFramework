<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * A8 双视图：边缘真相=planRulesForEdgePush；库内 merged ≠ 边缘 plan。
 */
final class RulesDualViewContractTest extends TestCase
{
    public function testGetDomainRulesExposesPlannedRulesAndViewContract(): void
    {
        $path = \dirname(__DIR__, 4) . '/Controller/Backend/Rules.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('planRulesForEdgePush', $src);
        self::assertStringContainsString("'plannedRules'", $src);
        self::assertStringContainsString("'view_contract'", $src);
        self::assertStringContainsString('库内 merged ≠ 边缘 plan', $src);
        self::assertStringContainsString('禁止把 mergedRules 标成已上 Cloudflare', $src);
    }

    public function testRuleManagerDocblocksPinDualView(): void
    {
        $path = \dirname(__DIR__, 4) . '/Service/RuleManager.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('库内展开视图（inventory）', $src);
        self::assertStringContainsString('边缘真相视图（planned）', $src);
        self::assertStringContainsString('≠ 本方法结果', $src);
    }

    public function testApiRulesIndexHintNotImpliesEdgePush(): void
    {
        $path = \dirname(__DIR__, 4) . '/view/templates/backend/ApiRules/index.phtml';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('planRulesForEdgePush', $src);
        self::assertStringContainsString('不等于已上 Cloudflare', $src);
    }
}
