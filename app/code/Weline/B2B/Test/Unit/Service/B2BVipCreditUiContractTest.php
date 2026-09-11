<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class B2BVipCreditUiContractTest extends TestCase
{
    public function testApproveDefaultsToFirstGroupOptionAndConfigEmbedsCreditSwitch(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $controlCenter = (string)file_get_contents($moduleRoot . '/view/templates/Backend/ControlCenter/index.phtml');
        $config = (string)file_get_contents($moduleRoot . '/view/templates/Backend/Config/index.phtml');
        $declaration = (string)file_get_contents(
            $moduleRoot . '/extends/module/Weline_SystemConfig/Config/frontend/selling-mode.phtml'
        );
        $switcher = (string)file_get_contents(
            $moduleRoot . '/view/templates/frontend/widgets/selling-mode-switcher.phtml'
        );
        $module = (string)file_get_contents($moduleRoot . '/etc/module.php');

        self::assertStringContainsString('b2b_credit_enabled', $declaration);
        self::assertStringContainsString('scope="global"', $declaration);
        self::assertStringContainsString('b2b_credit_enabled', $config);
        self::assertStringContainsString('defaultGroupValue', $controlCenter);
        self::assertStringContainsString('value="<?= $escape($defaultGroupValue) ?>"', $controlCenter);
        self::assertStringContainsString('credit_limit_minor', $controlCenter);
        self::assertStringContainsString('b2b-membership-tier-info', $switcher);
        self::assertStringContainsString('payment.asset_policy.Weline_B2B', $module);
        self::assertStringContainsString('2.6.34', $module);
    }
}
