<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationCatalog;
use Weline\Shipping\Model\FreeShippingConditionType;
use Weline\Shipping\Model\FreeShippingConditionType\LocalDescription as FreeShippingConditionTypeLocalDescription;
use Weline\Shipping\Model\FreeShippingRule;
use Weline\Shipping\Service\FreeShippingConditionTypeAdminService;

final class FreeShippingConditionTypeLocalModelContractTest extends TestCase
{
    public function testLocalDescriptionExtendsLocalModelAndMapsParent(): void
    {
        self::assertTrue(is_subclass_of(FreeShippingConditionTypeLocalDescription::class, LocalModel::class));
        self::assertSame(FreeShippingConditionType::schema_fields_ID, FreeShippingConditionTypeLocalDescription::schema_fields_ID);
        self::assertSame(
            FreeShippingConditionType::schema_fields_CONDITION_NAME,
            FreeShippingConditionTypeLocalDescription::schema_fields_CONDITION_NAME,
        );
        self::assertSame(
            FreeShippingConditionType::schema_fields_CONDITION_NAME,
            FreeShippingConditionTypeLocalDescription::schema_fields_name,
        );
        self::assertSame('w_shipping_free_condition_type_local', FreeShippingConditionTypeLocalDescription::schema_table);
    }

    public function testCatalogDiscoversConditionTypeLocal(): void
    {
        // 轻量契约：完整 LocalModelTranslationCatalog 扫描依赖 APP_* 常量；无宿主 bootstrap 时退回结构断言。
        require_once dirname(__DIR__) . '/bootstrap.php';
        if (!\defined('APP_CODE_PATH') || !\defined('APP_ETC_PATH')) {
            self::assertTrue(class_exists(FreeShippingConditionTypeLocalDescription::class));
            self::assertTrue(is_subclass_of(FreeShippingConditionTypeLocalDescription::class, LocalModel::class));
            self::assertSame(
                FreeShippingConditionType::schema_fields_ID,
                FreeShippingConditionTypeLocalDescription::schema_primary_key,
            );

            return;
        }

        $catalog = new LocalModelTranslationCatalog();
        $byClass = [];
        foreach ($catalog->descriptors() as $descriptor) {
            $byClass[(string)$descriptor['local_model']] = $descriptor;
        }

        self::assertArrayHasKey(FreeShippingConditionTypeLocalDescription::class, $byClass);
        self::assertSame(FreeShippingConditionType::class, $byClass[FreeShippingConditionTypeLocalDescription::class]['parent_model']);
        self::assertContains(
            FreeShippingConditionType::schema_fields_CONDITION_NAME,
            $byClass[FreeShippingConditionTypeLocalDescription::class]['fields'],
        );
    }

    public function testAdminServiceSeedsAndUiContracts(): void
    {
        $svc = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/FreeShippingConditionTypeAdminService.php');
        self::assertStringContainsString('DEFAULT_SEEDS', $svc);
        self::assertStringContainsString('CONDITION_ORDER_AMOUNT', $svc);
        self::assertStringContainsString('CONDITION_REGION', $svc);
        self::assertStringContainsString('CONDITION_MIXED', $svc);
        self::assertStringContainsString('订单金额', $svc);
        self::assertStringContainsString('LocalModelTranslationQueueService', $svc);
        self::assertTrue(method_exists(FreeShippingConditionTypeAdminService::class, 'seedDefaults'));
        self::assertTrue(method_exists(FreeShippingConditionTypeAdminService::class, 'mapByCode'));
        self::assertTrue(method_exists(FreeShippingConditionTypeAdminService::class, 'labelForCode'));

        $ctl = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/FreeShippingRule.php');
        self::assertStringContainsString('FreeShippingConditionTypeAdminService', $ctl);
        self::assertStringContainsString('page_section', $ctl);
        self::assertStringContainsString('condition_types_by_code', $ctl);

        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/FreeShippingRule/index.phtml',
        );
        self::assertStringContainsString('Weline\\Shipping\\Model\\FreeShippingConditionType\\LocalDescription', $tpl);
        self::assertStringContainsString('field="condition_name"', $tpl);
        self::assertStringContainsString('bindRuntimeRecordId', $tpl);
        self::assertStringContainsString('shipping-free-condition-type-card', $tpl);
        self::assertStringContainsString('shipping-free-rule-page-tabs', $tpl);
        self::assertStringContainsString('shipping-free-rule-tab-conditions', $tpl);
        self::assertStringContainsString('shipping-free-rule-condition-type', $tpl);
        self::assertStringNotContainsString(
            'getData(FreeShippingRule::schema_fields_CONDITION_TYPE)) ?></td>',
            $tpl,
        );

        $upgrade = (string)file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringContainsString('FreeShippingConditionType::class', $upgrade);
        self::assertStringContainsString('seedFreeShippingConditionTypes', $upgrade);
    }
}
