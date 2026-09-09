<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Model\EmbargoRegion;
use Weline\Shipping\Service\EmbargoAdminService;
use Weline\Shipping\Service\SystemEmbargoAdminService;

final class SystemEmbargoAdminServiceContractTest extends TestCase
{
    public function testModelDeclaresNaturalUniqueAndSystemScope(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/EmbargoRegion.php');
        self::assertStringContainsString('uk_embargo_natural', $src);
        self::assertStringContainsString("SCOPE_SYSTEM = 'system'", $src);
        self::assertStringContainsString('reason_code', $src);
        self::assertStringContainsString('disabled_by', $src);
        self::assertStringContainsString('disabled_at', $src);
    }

    public function testReplaceForScopeRejectsSystem(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/EmbargoAdminService.php');
        self::assertStringContainsString('ERROR_REPLACE_FORBIDDEN', $src);
        self::assertStringContainsString('SCOPE_SYSTEM', $src);
        self::assertSame(
            'system_embargo_replace_forbidden',
            SystemEmbargoAdminService::ERROR_REPLACE_FORBIDDEN,
        );
        self::assertSame(
            'system_embargo_delete_forbidden',
            SystemEmbargoAdminService::ERROR_DELETE_FORBIDDEN,
        );
    }

    public function testAdminServiceHasNoDeleteApi(): void
    {
        $ref = new \ReflectionClass(SystemEmbargoAdminService::class);
        self::assertTrue($ref->hasMethod('listAll'));
        self::assertTrue($ref->hasMethod('addCountry'));
        self::assertTrue($ref->hasMethod('addRegion'));
        self::assertTrue($ref->hasMethod('delete'));
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/SystemEmbargoAdminService.php');
        self::assertStringContainsString("'already_active'", $src);
        self::assertStringContainsString("'reactivated'", $src);
        self::assertStringContainsString("'created'", $src);
        $ctl = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/SystemEmbargo.php');
        self::assertStringContainsString('无需重复新增', $ctl);
        self::assertStringContainsString('function remove', $ctl);
        self::assertStringNotContainsString('public function delete()', $ctl);
        self::assertStringNotContainsString('function postDelete', $ctl);
        self::assertTrue($ref->hasMethod('deactivate'));
        self::assertTrue($ref->hasMethod('activate'));
        self::assertTrue($ref->hasMethod('seedFromRows'));
        self::assertTrue($ref->hasMethod('purgeNonCanonicalSeeds'));
        self::assertTrue($ref->hasMethod('loadCanonicalSeedCountries'));
        self::assertFalse($ref->hasMethod('replaceForScope'));
        self::assertStringContainsString('shipping.system_embargo.deactivate', $src);
        self::assertStringContainsString('shipping.system_embargo.activate', $src);
        self::assertStringContainsString('reason_label', $src);
        self::assertStringContainsString('country_name', $src);
        self::assertStringContainsString('EmbargoReasonAdminService', $src);
        self::assertStringContainsString('purge_non_canonical_seed', $src);
        $publisher = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/SystemEmbargoResourceChangePublisher.php'
        );
        self::assertStringContainsString("RESOURCE_TYPE = 'shipping_embargo'", $publisher);
        self::assertStringContainsString('w_changed($change)', $publisher);
        self::assertStringContainsString("global('shipping', ['embargo'])", $publisher);
    }

    public function testSeedFileHasNoDomesticSurchargeProvinces(): void
    {
        $tsv = (string)file_get_contents(
            dirname(__DIR__, 3) . '/data/system-embargo/countries.tsv',
        );
        self::assertStringContainsString("AQ\t", $tsv);
        self::assertStringContainsString("KP\t", $tsv);
        self::assertStringNotContainsString("CN\t", $tsv);
        self::assertDoesNotMatchRegularExpression('/^\s*CN\b/m', $tsv);
        self::assertStringNotContainsString('新疆', $tsv);
        self::assertStringNotContainsString('西藏', $tsv);
        self::assertStringNotContainsString('Xinjiang', $tsv);
        self::assertDoesNotMatchRegularExpression('/\bCN-XJ\b|\bCN-XZ\b/i', $tsv);
    }

    public function testManagerTabsIncludeSystemEmbargo(): void
    {
        $manager = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/Manager.php');
        self::assertStringContainsString("'systemembargo'", $manager);
        self::assertStringContainsString('shipping/backend/systemembargo', $manager);
        self::assertStringContainsString('return $this->redirect', $manager);
        self::assertStringNotContainsString('<iframe', $manager);

        $tabs = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/partials/manager-tabs.phtml',
        );
        self::assertStringContainsString('systemembargo', $tabs);
        self::assertStringContainsString('shipping/backend/systemembargo', $tabs);
        self::assertStringContainsString('w-tabs__tab', $tabs);
        self::assertStringNotContainsString('<iframe', $tabs);

        $managerTpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Manager/index.phtml',
        );
        self::assertStringNotContainsString('<iframe', $managerTpl);

        $controller = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/SystemEmbargo.php',
        );
        self::assertStringContainsString('ShippingBackendEmbedTrait', $controller);
        self::assertStringContainsString('getMessageManager()', $controller);
        self::assertStringContainsString('addSuccess', $controller);
        self::assertStringContainsString('addError', $controller);
        self::assertDoesNotMatchRegularExpression('/\$this->getMessage\s*\(/', $controller);
        self::assertStringNotContainsString('backendUrl(', $controller);
        $menu = (string)file_get_contents(dirname(__DIR__, 3) . '/etc/backend/menu.xml');
        self::assertStringContainsString('Weline_Shipping::system_embargo', $menu);
        self::assertStringContainsString('shipping/backend/systemembargo/index', $menu);
        self::assertStringContainsString('title="系统禁运"', $menu);

        $embargoTpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/SystemEmbargo/index.phtml',
        );
        self::assertStringContainsString('manager-tabs.phtml', $embargoTpl);
        self::assertStringContainsString("'systemembargo'", $embargoTpl);
    }

    public function testDeactivateConfirmMentionsSiteWide(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/SystemEmbargo/index.phtml',
        );
        self::assertStringContainsString('全站生效', $tpl);
        self::assertStringContainsString('系统种子不可删除', $tpl);
        self::assertStringContainsString('system-embargo-delete', $tpl);
        self::assertStringContainsString('system-embargo-origin-seed', $tpl);
        self::assertStringContainsString('shipping/backend/systemembargo/remove', $tpl);
        self::assertStringNotContainsString('shipping/backend/systemembargo/delete', $tpl);
        self::assertStringNotContainsString('>不允许删除条目', $tpl);
        self::assertStringContainsString('<w:theme:address', $tpl);
        self::assertStringContainsString('levels="country|province"', $tpl);
        self::assertStringContainsString('cascade="true"', $tpl);
        self::assertStringContainsString('district="false"', $tpl);
        self::assertStringContainsString('system-embargo-country', $tpl);
        self::assertStringContainsString('province_region_id', $tpl);
        self::assertStringContainsString('新增禁运', $tpl);
        self::assertStringNotContainsString('system-embargo-cc', $tpl);
        self::assertStringNotContainsString('ISO 国家码', $tpl);
        self::assertStringContainsString('system-embargo-add-dialog', $tpl);
        self::assertStringContainsString('data-w-action="dialog.open"', $tpl);
        self::assertStringContainsString('system-embargo-open-add', $tpl);
        self::assertStringContainsString('w-dialog', $tpl);
        self::assertStringContainsString('system-embargo-region-label', $tpl);
        self::assertStringContainsString('system-embargo-reason-label', $tpl);
        self::assertStringContainsString('reason_label', $tpl);
        self::assertStringContainsString('system-embargo-reason-card', $tpl);
        self::assertStringContainsString('EmbargoReason\\LocalDescription', $tpl);
        self::assertStringContainsString('reason_options', $tpl);
        self::assertStringContainsString('systemembargo/reasonSave', $tpl);
    }

    public function testEmbargoServiceMessageForSystem(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/EmbargoService.php');
        self::assertStringContainsString('SCOPE_SYSTEM', $src);
        self::assertStringContainsString("'系统'", $src);
    }

    public function testConfigVersionIncludesReachability(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ShippingServiceManager.php',
        );
        self::assertStringContainsString('reachabilityConfigFacts', $src);
        self::assertStringContainsString('preferredShippingProfileCodes', $src);
        self::assertStringContainsString("'reachability'", $src);
    }

    public function testEmbargoAdminServiceClassExists(): void
    {
        self::assertTrue(class_exists(EmbargoAdminService::class));
        self::assertTrue(class_exists(EmbargoRegion::class));
    }
}
