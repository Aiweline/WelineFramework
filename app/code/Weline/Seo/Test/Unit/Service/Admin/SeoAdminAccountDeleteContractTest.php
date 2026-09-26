<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Admin;

use PHPUnit\Framework\TestCase;

/**
 * 账户删除：服务 + QueryProvider + 列表 UI 契约。
 */
final class SeoAdminAccountDeleteContractTest extends TestCase
{
    public function testDeleteAccountIsWiredThroughAdminStack(): void
    {
        $root = dirname(__DIR__, 4);
        $service = $root . '/Service/Admin/SeoAdminAccountService.php';
        $provider = $root . '/extends/module/Weline_Framework/Query/SeoAdminQueryProvider.php';
        $controller = $root . '/Controller/Backend/Account.php';
        $js = $root . '/view/statics/js/seo-admin.js';
        $template = $root . '/view/templates/Backend/Account/index.phtml';

        foreach ([$service, $provider, $controller, $js, $template] as $path) {
            self::assertFileExists($path);
        }

        $serviceSrc = (string) file_get_contents($service);
        $providerSrc = (string) file_get_contents($provider);
        $controllerSrc = (string) file_get_contents($controller);
        $jsSrc = (string) file_get_contents($js);
        $templateSrc = (string) file_get_contents($template);

        self::assertStringContainsString('function deleteAccount(array $params)', $serviceSrc);
        self::assertStringContainsString('unbindWebsiteAccount', $serviceSrc);
        self::assertStringContainsString('SeoWebsiteStats::schema_fields_ACCOUNT_ID', $serviceSrc);
        self::assertStringContainsString("__('账户已删除')", $serviceSrc);

        self::assertStringContainsString("'deleteAccount' => 'Weline_Seo::seo_account'", $providerSrc);
        self::assertStringContainsString("'deleteAccount' => \$this->accounts->deleteAccount(\$params)", $providerSrc);
        self::assertStringContainsString("operation('deleteAccount'", $providerSrc);

        self::assertStringContainsString("Weline_Seo::seo_account_delete", $controllerSrc);
        self::assertStringContainsString('public function delete(): string', $controllerSrc);

        self::assertStringContainsString('data-seo-delete-account', $jsSrc);
        self::assertStringContainsString('api.deleteAccount', $jsSrc);
        self::assertStringContainsString('accountConfirmDelete', $jsSrc);

        self::assertStringContainsString('data-seo-delete-account', $templateSrc);
        self::assertStringContainsString('data-testid="seo-account-add"', $templateSrc);
        self::assertStringContainsString('data-account-confirm-delete', $templateSrc);
    }
}
