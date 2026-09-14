<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Model\FreeShippingRule;
use Weline\Shipping\Service\FreeShippingRuleSeedService;

final class FreeShippingRuleSeedContractTest extends TestCase
{
    public function testModelDeclaresOriginSeedConstants(): void
    {
        self::assertSame('origin', FreeShippingRule::schema_fields_ORIGIN);
        self::assertSame('seed', FreeShippingRule::ORIGIN_SEED);
        self::assertSame('manual', FreeShippingRule::ORIGIN_MANUAL);
    }

    public function testDefaultSeedsAreInternationalThresholdTemplates(): void
    {
        self::assertTrue(class_exists(FreeShippingRuleSeedService::class));
        self::assertTrue(method_exists(FreeShippingRuleSeedService::class, 'seedDefaults'));
        self::assertTrue(method_exists(FreeShippingRuleSeedService::class, 'canonicalCodes'));

        $codes = [];
        $activeCodes = [];
        foreach (FreeShippingRuleSeedService::DEFAULT_SEEDS as $seed) {
            $code = (string)($seed['rule_code'] ?? '');
            self::assertNotSame('', $code);
            self::assertSame(FreeShippingRule::CONDITION_ORDER_AMOUNT, $seed['condition_type'] ?? null);
            $codes[] = $code;
            if (!empty($seed['is_active'])) {
                $activeCodes[] = $code;
            }
        }

        foreach (['SEED_FREE_49', 'SEED_FREE_99', 'SEED_FREE_149', 'SEED_FREE_199', 'SEED_FREE_299', 'SEED_FREE_499'] as $expected) {
            self::assertContains($expected, $codes);
        }
        self::assertSame(['SEED_FREE_99'], $activeCodes);
        self::assertSame($codes, FreeShippingRuleSeedService::canonicalCodes());
    }

    public function testAdminDeleteGateAndUiContracts(): void
    {
        $admin = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ShippingConfigurationAdminService.php');
        self::assertStringContainsString('deleteFreeShippingRule', $admin);
        self::assertStringContainsString('setFreeShippingRuleActive', $admin);
        self::assertStringContainsString('ORIGIN_SEED', $admin);
        self::assertStringContainsString('ORIGIN_MANUAL', $admin);
        self::assertStringContainsString('系统种子不可删除', $admin);
        self::assertMatchesRegularExpression(
            '/function createFreeShippingRule[\s\S]+ORIGIN_MANUAL/',
            $admin,
        );

        $ctl = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/FreeShippingRule.php');
        self::assertStringContainsString('function remove', $ctl);
        self::assertStringContainsString('function activate', $ctl);
        self::assertStringContainsString('function deactivate', $ctl);
        self::assertStringContainsString('FreeShippingRuleSeedService', $ctl);

        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/FreeShippingRule/index.phtml',
        );
        self::assertStringContainsString('free-shipping-origin-seed', $tpl);
        self::assertStringContainsString('系统种子不可删除', $tpl);
        self::assertStringContainsString('shipping/backend/freeshippingrule/remove', $tpl);
        self::assertStringContainsString('shipping/backend/freeshippingrule/activate', $tpl);
        self::assertStringContainsString('shipping/backend/freeshippingrule/deactivate', $tpl);

        $upgrade = (string)file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringContainsString('seedFreeShippingRules', $upgrade);
        self::assertStringContainsString('FreeShippingRuleSeedService', $upgrade);

        $lane = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/DefaultShippingLaneSeedService.php');
        self::assertStringContainsString('FreeShippingRuleSeedService', $lane);
        self::assertStringContainsString('seedDefaults', $lane);
    }
}
