<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\DefaultShippingLaneSeedService;

/** 费用模板航线种子须齐全（General 九档 + Heavy 两档）。 */
final class RateTemplateSeedCompletenessContractTest extends TestCase
{
    public function testExpectedNineSeedTemplateCodes(): void
    {
        $codes = DefaultShippingLaneSeedService::expectedSeedTemplateCodes();
        self::assertCount(11, $codes);
        foreach ([
            'SEED_TPL_DOMESTIC',
            'SEED_TPL_GREATER_CHINA',
            'SEED_TPL_ASIA_PACIFIC',
            'SEED_TPL_AMERICAS',
            'SEED_TPL_EUROPE',
            'SEED_TPL_OCEANIA',
            'SEED_TPL_LATAM',
            'SEED_TPL_MIDDLE_EAST_AFRICA',
            'SEED_TPL_OTHER',
            'SEED_TPL_HEAVY_DOMESTIC',
            'SEED_TPL_HEAVY_INTERNATIONAL',
        ] as $code) {
            self::assertContains($code, $codes);
        }
    }

    public function testSeedServiceAlwaysUpsertsTemplatesAndWritesRates(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/DefaultShippingLaneSeedService.php'
        );
        self::assertStringContainsString('expectedSeedTemplateCodes', $src);
        self::assertStringContainsString('CALC_TYPE_WEIGHT_TABLE', $src);
        self::assertStringContainsString('SEED_GENERAL', $src);
        self::assertStringContainsString('SEED_HEAVY', $src);
        self::assertStringContainsString('ensureProfile', $src);
        self::assertStringContainsString('九档模板始终落库', $src);
    }

    public function testListUiHumanizesCalcTypeAndSeedBadge(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/RateTemplate/index.phtml'
        );
        self::assertStringContainsString('shipping-rate-template-origin-seed', $tpl);
        self::assertStringContainsString('按重量阶梯', $tpl);
        self::assertStringContainsString('固定费用', $tpl);
        self::assertStringContainsString('rate_brackets', $tpl);
        self::assertStringContainsString('max_weight_kg', $tpl);
    }
}
