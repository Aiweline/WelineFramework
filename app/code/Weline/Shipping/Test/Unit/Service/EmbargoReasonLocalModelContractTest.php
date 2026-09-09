<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationCatalog;
use Weline\Shipping\Model\EmbargoReason;
use Weline\Shipping\Model\EmbargoReason\LocalDescription as EmbargoReasonLocalDescription;
use Weline\Shipping\Service\EmbargoReasonAdminService;

final class EmbargoReasonLocalModelContractTest extends TestCase
{
    public function testLocalDescriptionExtendsLocalModelAndMapsParent(): void
    {
        self::assertTrue(is_subclass_of(EmbargoReasonLocalDescription::class, LocalModel::class));
        self::assertSame(EmbargoReason::schema_fields_ID, EmbargoReasonLocalDescription::schema_fields_ID);
        self::assertSame(
            EmbargoReason::schema_fields_REASON_NAME,
            EmbargoReasonLocalDescription::schema_fields_REASON_NAME,
        );
        self::assertSame(
            EmbargoReason::schema_fields_REASON_NAME,
            EmbargoReasonLocalDescription::schema_fields_name,
        );
        self::assertSame('w_shipping_embargo_reason_local', EmbargoReasonLocalDescription::schema_table);
    }

    public function testCatalogDiscoversEmbargoReasonLocal(): void
    {
        require_once dirname(__DIR__) . '/bootstrap.php';

        $catalog = new LocalModelTranslationCatalog();
        $byClass = [];
        foreach ($catalog->descriptors() as $descriptor) {
            $byClass[(string)$descriptor['local_model']] = $descriptor;
        }

        self::assertArrayHasKey(EmbargoReasonLocalDescription::class, $byClass);
        self::assertSame(EmbargoReason::class, $byClass[EmbargoReasonLocalDescription::class]['parent_model']);
        self::assertContains(
            EmbargoReason::schema_fields_REASON_NAME,
            $byClass[EmbargoReasonLocalDescription::class]['fields'],
        );
    }

    public function testAdminServiceSeedsAndUiContracts(): void
    {
        $svc = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/EmbargoReasonAdminService.php');
        self::assertStringContainsString('DEFAULT_SEEDS', $svc);
        self::assertStringContainsString('territory', $svc);
        self::assertStringContainsString('no_commerce', $svc);
        self::assertStringContainsString('LocalModelTranslationQueueService', $svc);
        self::assertTrue(method_exists(EmbargoReasonAdminService::class, 'seedDefaults'));
        self::assertTrue(method_exists(EmbargoReasonAdminService::class, 'listActiveOptions'));
        self::assertTrue(method_exists(EmbargoReasonAdminService::class, 'labelForCode'));

        $ctl = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/SystemEmbargo.php');
        self::assertStringContainsString('function reasonSave', $ctl);
        self::assertStringContainsString('function reasonRemove', $ctl);
        self::assertStringContainsString('reason_options', $ctl);

        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/SystemEmbargo/index.phtml',
        );
        self::assertStringContainsString('system-embargo-reason-card', $tpl);
        self::assertStringContainsString('system-embargo-tabs', $tpl);
        self::assertStringContainsString('system-embargo-tab-reasons', $tpl);
        self::assertStringContainsString('Weline\\Shipping\\Model\\EmbargoReason\\LocalDescription', $tpl);
        self::assertStringContainsString('shipping/backend/systemembargo/reasonSave', $tpl);
        self::assertStringContainsString('shipping/backend/systemembargo/reasonRemove', $tpl);
        self::assertStringContainsString('reason_options', $tpl);
        self::assertStringContainsString("['tab' => 'reasons']", $ctl);

        $upgrade = (string)file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringContainsString('EmbargoReason::class', $upgrade);
        self::assertStringContainsString('seedEmbargoReasons', $upgrade);
    }
}
