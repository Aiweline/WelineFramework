<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Affiliate\Service\AffiliateStorefrontPolicy;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllAffiliateServiceContractsPass().
 */
final class AffiliateServiceContractsTest extends TestCase
{
    public function testAllAffiliateServiceContractsPass(): void
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
            'AffiliateStorefrontSettings::testProductShareSettingsUsesSystemConfigEmbedAndMenu' => function () {
            $this->assert_AffiliateStorefrontSettings_testProductShareSettingsUsesSystemConfigEmbedAndMenu();
        },
            'AffiliateStorefrontSettings::testPolicyDefaultsEnabledAndHonorsTestingOverride' => function () {
            $this->assert_AffiliateStorefrontSettings_testPolicyDefaultsEnabledAndHonorsTestingOverride();
        },
            'AffiliateService::testOutOfScopeShareClickStillStartsAttribution' => function () {
            $this->assert_AffiliateService_testOutOfScopeShareClickStillStartsAttribution();
        },
        ];
    }

private function assert_AffiliateService_testOutOfScopeShareClickStillStartsAttribution(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Affiliate/Service/AffiliateService.php');
    $click = substr($source, (int) strpos($source, 'function recordShareClick'), 2200);
    self::assertStringContainsString('if (!$isSelfClick && $hasAffiliate)', $click);
    self::assertStringNotContainsString('if (!$isSelfClick && !$scopeMismatch)', $click);
    $order = substr($source, (int) strpos($source, 'function handleCheckoutOrderCreated'), 1800);
    self::assertStringNotContainsString('isAffiliateEffectiveInScope($affiliate)', $order);
    $register = substr($source, (int) strpos($source, 'function handleCustomerRegistered'), 1200);
    self::assertStringContainsString('isAffiliateEffectiveInScope($affiliate)', $register);
}

private function assert_AffiliateStorefrontSettings_testProductShareSettingsUsesSystemConfigEmbedAndMenu(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Affiliate';
    $declaration = (string) file_get_contents($moduleRoot . '/extends/module/Weline_SystemConfig/Config/frontend/product-share.phtml');
    self::assertStringContainsString('product_share_enabled', $declaration);
    self::assertStringContainsString('type="switch"', $declaration);
    self::assertStringContainsString('@config.acl {Weline_Affiliate::config}', $declaration);
    $policy = (string) file_get_contents($moduleRoot . '/Service/AffiliateStorefrontPolicy.php');
    self::assertStringContainsString("CONFIG_PRODUCT_SHARE_ENABLED = 'product_share_enabled'", $policy);
    self::assertStringContainsString('isProductShareEnabled', $policy);
    $configController = (string) file_get_contents($moduleRoot . '/Controller/Backend/Config.php');
    self::assertStringContainsString('SystemConfigTargetScopeService', $configController);
    self::assertStringContainsString("Weline_Affiliate::config", $configController);
    $configTemplate = (string) file_get_contents($moduleRoot . '/view/templates/backend/Config/index.phtml');
    self::assertStringContainsString('<w:config:embed', $configTemplate);
    self::assertStringContainsString('product_share_enabled', $configTemplate);
    self::assertStringContainsString('module="Weline_Affiliate"', $configTemplate);
    $menuXml = (string) file_get_contents($moduleRoot . '/etc/backend/menu.xml');
    self::assertStringContainsString('title="分销配置"', $menuXml);
    self::assertStringContainsString('*/backend/config', $menuXml);
}

private function assert_AffiliateStorefrontSettings_testPolicyDefaultsEnabledAndHonorsTestingOverride(): void
{
    $enabled = AffiliateStorefrontPolicy::forTesting([]);
    self::assertTrue($enabled->isProductShareEnabled(0, 0));
    $disabled = AffiliateStorefrontPolicy::forTesting(['global' => false]);
    self::assertFalse($disabled->isProductShareEnabled(1, 2));
}
}
