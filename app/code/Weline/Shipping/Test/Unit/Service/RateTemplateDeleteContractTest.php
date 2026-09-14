<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Model\RateTemplate;

/** 费用模板：自建可删、系统种子不可删。 */
final class RateTemplateDeleteContractTest extends TestCase
{
    public function testSeedCodeDetection(): void
    {
        self::assertTrue(RateTemplate::isSeedTemplateCode('SEED_TPL_DOMESTIC'));
        self::assertTrue(RateTemplate::isSeedTemplateCode('seed_tpl_europe'));
        self::assertFalse(RateTemplate::isSeedTemplateCode('TEST'));
        self::assertFalse(RateTemplate::isSeedTemplateCode('r43store_c1d7adba892e'));
    }

    public function testAdminServiceExposesDeleteRateTemplate(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ShippingConfigurationAdminService.php'
        );
        self::assertStringContainsString('function deleteRateTemplate', $src);
        self::assertStringContainsString('系统种子不可删除', $src);
        self::assertStringContainsString('RATE_TEMPLATE_ID', $src);
        self::assertStringContainsString('仍有配送服务引用该费用模板', $src);
    }

    public function testControllerExposesRemoveAction(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/RateTemplate.php'
        );
        self::assertStringContainsString('function remove', $src);
        self::assertStringContainsString('rate_template_remove', $src);
        self::assertStringContainsString('deleteRateTemplate', $src);
    }
}
