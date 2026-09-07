<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 承运商支持范围：默认「当前选择覆盖 Provider 默认」，勾选框默认 checked。
 */
final class CarrierCoverageKeepCurrentContractTest extends TestCase
{
    public function testEditTemplateDefaultsToKeepCurrentSelection(): void
    {
        $base = dirname(__DIR__, 3);
        $tpl = (string)file_get_contents($base . '/view/templates/Backend/Carrier/edit.phtml');
        self::assertStringContainsString('name="coverage_keep_current"', $tpl);
        self::assertStringContainsString('id="coverage_keep_current" checked', $tpl);
        self::assertStringContainsString('data-testid="shipping-carrier-coverage-caution"', $tpl);
        self::assertStringContainsString('data-tone="warning"', $tpl);
        self::assertStringContainsString('w-alert__content', $tpl);
        self::assertStringContainsString('w-alert__title', $tpl);
        self::assertStringContainsString('<w:icon name="warning" size="sm"></w:icon>', $tpl);
        self::assertStringContainsString('谨慎操作', $tpl);
        self::assertStringContainsString('window.confirm', $tpl);
        self::assertStringContainsString('data-coverage-baseline', $tpl);
        self::assertStringContainsString('费用模板只算运费金额，不含到达地区', $tpl);

        $ctrl = (string)file_get_contents($base . '/Controller/Backend/Carrier.php');
        self::assertStringContainsString("getParam('coverage_keep_current', 1)", $ctrl);
        self::assertStringContainsString('if (!$keepCurrent)', $ctrl);
        self::assertStringContainsString('applyProviderDefaults', $ctrl);
        self::assertStringNotContainsString("getParam('coverage_apply_defaults'", $ctrl);
    }
}
