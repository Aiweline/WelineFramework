<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Affiliate\Service\AffiliateStorefrontPolicy;

/**
 * 分销店面展示统一配置：SystemConfig 声明 + 设置页 config:embed + 菜单 + Policy 键。
 */
final class AffiliateStorefrontSettingsContractTest extends TestCase
{
    public function testProductShareSettingsUsesSystemConfigEmbedAndMenu(): void
    {
        $moduleRoot = dirname(__DIR__, 3);

        $declaration = (string)file_get_contents(
            $moduleRoot . '/extends/module/Weline_SystemConfig/Config/frontend/product-share.phtml'
        );
        self::assertStringContainsString('product_share_enabled', $declaration);
        self::assertStringContainsString('type="switch"', $declaration);
        self::assertStringContainsString('@config.acl {Weline_Affiliate::config}', $declaration);

        $policy = (string)file_get_contents($moduleRoot . '/Service/AffiliateStorefrontPolicy.php');
        self::assertStringContainsString("CONFIG_PRODUCT_SHARE_ENABLED = 'product_share_enabled'", $policy);
        self::assertStringContainsString('isProductShareEnabled', $policy);

        $configController = (string)file_get_contents($moduleRoot . '/Controller/Backend/Config.php');
        self::assertStringContainsString('SystemConfigTargetScopeService', $configController);
        self::assertStringContainsString("Weline_Affiliate::config", $configController);

        $configTemplate = (string)file_get_contents($moduleRoot . '/view/templates/Backend/Config/index.phtml');
        self::assertStringContainsString('<w:config:embed', $configTemplate);
        self::assertStringContainsString('product_share_enabled', $configTemplate);
        self::assertStringContainsString('module="Weline_Affiliate"', $configTemplate);

        $menuXml = (string)file_get_contents($moduleRoot . '/etc/backend/menu.xml');
        self::assertStringContainsString('title="分销配置"', $menuXml);
        self::assertStringContainsString('*/backend/config', $menuXml);
    }

    public function testPolicyDefaultsEnabledAndHonorsTestingOverride(): void
    {
        $enabled = AffiliateStorefrontPolicy::forTesting([]);
        self::assertTrue($enabled->isProductShareEnabled(0, 0));

        $disabled = AffiliateStorefrontPolicy::forTesting([
            'global' => false,
        ]);
        self::assertFalse($disabled->isProductShareEnabled(1, 2));
    }
}
