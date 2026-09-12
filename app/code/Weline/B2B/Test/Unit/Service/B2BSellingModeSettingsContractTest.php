<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 批发售卖模式统一配置：SystemConfig 声明 + 设置页 config:embed + 菜单。
 */
final class B2BSellingModeSettingsContractTest extends TestCase
{
    public function testSellingModeSettingsUsesSystemConfigEmbedAndMenu(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $declaration = (string)file_get_contents(
            $moduleRoot . '/extends/module/Weline_SystemConfig/Config/frontend/selling-mode.phtml'
        );
        $configController = (string)file_get_contents($moduleRoot . '/Controller/Backend/Config.php');
        $configTemplate = (string)file_get_contents($moduleRoot . '/view/templates/Backend/Config/index.phtml');
        $menuXml = (string)file_get_contents($moduleRoot . '/etc/backend/menu.xml');
        $policy = (string)file_get_contents($moduleRoot . '/Service/SellingModePolicy.php');

        self::assertStringContainsString('selling_mode_toc_enabled', $declaration);
        self::assertStringContainsString('selling_mode_tob_enabled', $declaration);
        self::assertStringContainsString('b2b_credit_enabled', $declaration);
        self::assertStringContainsString('b2b_credit_min_cash_deposit_percent', $declaration);
        self::assertStringContainsString('定金最低现金占比', $declaration);
        self::assertStringContainsString('type="switch"', $declaration);
        self::assertStringContainsString('value-type="bool"', $declaration);
        self::assertStringContainsString('scope="global,website,store"', $declaration);
        self::assertStringContainsString('key="b2b_credit_enabled"', $declaration);
        self::assertStringContainsString('key="b2b_credit_min_cash_deposit_percent"', $declaration);
        self::assertStringContainsString('scope="global"', $declaration);
        self::assertStringContainsString('@config.area {frontend}', $declaration);
        self::assertStringContainsString('Weline_B2B::config', $declaration);

        self::assertStringContainsString('CONFIG_TOC_ENABLED = \'selling_mode_toc_enabled\'', $policy);
        self::assertStringContainsString('CONFIG_TOB_ENABLED = \'selling_mode_tob_enabled\'', $policy);

        self::assertStringContainsString('SystemConfigTargetScopeService', $configController);
        self::assertStringContainsString('Weline_B2B::config', $configController);
        self::assertStringContainsString('return $this->fetch()', $configController);
        self::assertStringNotContainsString('weline_systemconfig/backend/config', $configController);

        self::assertStringContainsString('<w:config:embed', $configTemplate);
        self::assertStringContainsString('module="Weline_B2B"', $configTemplate);
        self::assertStringContainsString('area="frontend"', $configTemplate);
        self::assertStringContainsString('<w:scope', $configTemplate);
        self::assertStringContainsString('selling_mode_toc_enabled', $configTemplate);
        self::assertStringContainsString('selling_mode_tob_enabled', $configTemplate);
        self::assertStringContainsString('b2b_credit_enabled', $configTemplate);
        self::assertStringContainsString('b2b_credit_min_cash_deposit_percent', $configTemplate);

        self::assertStringContainsString('source="Weline_B2B::config"', $menuXml);
        self::assertStringContainsString('action="*/backend/config"', $menuXml);
        self::assertStringContainsString('title="批发配置"', $menuXml);
    }
}
