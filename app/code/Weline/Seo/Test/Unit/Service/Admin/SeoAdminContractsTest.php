<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Admin;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllSeoAdminContractsPass().
 */
final class SeoAdminContractsTest extends TestCase
{
    public function testAllSeoAdminContractsPass(): void
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
            'SeoAdminAccountDelete::testDeleteAccountIsWiredThroughAdminStack' => function () {
            $this->assert_SeoAdminAccountDelete_testDeleteAccountIsWiredThroughAdminStack();
        },
            'SeoAdminAccountDownloadConfigJson::testDownloadAccountConfigJsonIsWiredThroughAdminStack' => function () {
            $this->assert_SeoAdminAccountDownloadConfigJson_testDownloadAccountConfigJsonIsWiredThroughAdminStack();
        },
            'SeoAdminAccountSaveWebsiteBindings::testSaveAccountAssignsTransactionResultBeforeBindingSync' => function () {
            $this->assert_SeoAdminAccountSaveWebsiteBindings_testSaveAccountAssignsTransactionResultBeforeBindingSync();
        },
            'SeoAdminAccountSaveWebsiteBindings::testFrontendKeepsWebsiteIdZeroWhenReadingBoundIds' => function () {
            $this->assert_SeoAdminAccountSaveWebsiteBindings_testFrontendKeepsWebsiteIdZeroWhenReadingBoundIds();
        },
        ];
    }

private function assert_SeoAdminAccountDelete_testDeleteAccountIsWiredThroughAdminStack(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $service = $root . '/Service/Admin/SeoAdminAccountService.php';
    $provider = $root . '/extends/module/Weline_Framework/Query/SeoAdminQueryProvider.php';
    $controller = $root . '/Controller/Backend/Account.php';
    $js = $root . '/view/statics/js/seo-admin.js';
    $template = $root . '/view/templates/backend/Account/index.phtml';
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

private function assert_SeoAdminAccountDownloadConfigJson_testDownloadAccountConfigJsonIsWiredThroughAdminStack(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $service = $root . '/Service/Admin/SeoAdminAccountService.php';
    $provider = $root . '/extends/module/Weline_Framework/Query/SeoAdminQueryProvider.php';
    $template = $root . '/view/templates/backend/Account/form.phtml';
    foreach ([$service, $provider, $template] as $path) {
        self::assertFileExists($path);
    }
    $serviceSrc = (string) file_get_contents($service);
    $providerSrc = (string) file_get_contents($provider);
    $templateSrc = (string) file_get_contents($template);
    self::assertStringContainsString('function downloadAccountConfigJson(array $params)', $serviceSrc);
    self::assertStringContainsString("__('凭证已准备下载')", $serviceSrc);
    self::assertStringContainsString("'downloadAccountConfigJson' => 'Weline_Seo::seo_account'", $providerSrc);
    self::assertStringContainsString("'downloadAccountConfigJson' => \$this->accounts->downloadAccountConfigJson(\$params)", $providerSrc);
    self::assertStringContainsString("operation('downloadAccountConfigJson'", $providerSrc);
    self::assertStringContainsString('data-seo-json-download-for', $templateSrc);
    self::assertStringContainsString('handleJsonFileDownload', $templateSrc);
    self::assertStringContainsString('downloadAccountConfigJson', $templateSrc);
    self::assertStringContainsString("setAttribute('data-testid', 'seo-json-download-' + key)", $templateSrc);
}

private function assert_SeoAdminAccountSaveWebsiteBindings_testSaveAccountAssignsTransactionResultBeforeBindingSync(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Service/Admin/SeoAdminAccountService.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    if (preg_match('/public function saveAccount\(array \$params\): array\s*\{(.*?)\n    public function /s', $src, $matches) !== 1) {
        self::fail('saveAccount 方法体未定位到下一个 public function');
    }
    $saveBody = (string)$matches[1];
    self::assertMatchesRegularExpression('/\$saved\s*=\s*\$this->transactions->run\(/', $saveBody, 'saveAccount 必须先把事务结果赋给 $saved，再同步 website_ids');
    self::assertStringNotContainsString("return \$this->transactions->run(\$this->accounts->getConnection()", $saveBody, '禁止 saveAccount 提前 return 事务结果导致 website_ids 绑定死代码');
    self::assertStringContainsString("array_key_exists('website_ids', \$params)", $saveBody);
    self::assertStringContainsString('saveAccountWebsiteBindings($savedAccountId, $websiteIds)', $saveBody);
    self::assertStringContainsString('bound_website_ids', $saveBody);
    self::assertStringContainsString('账户已保存，但站点绑定未同步成功，请重试保存。', $saveBody);
}

private function assert_SeoAdminAccountSaveWebsiteBindings_testFrontendKeepsWebsiteIdZeroWhenReadingBoundIds(): void
{
    $js = '/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/view/statics/js/seo-admin.js';
    self::assertFileExists($js);
    $src = (string) file_get_contents($js);
    self::assertStringContainsString('readAccountBoundWebsiteIds', $src);
    self::assertStringContainsString('id < 0', $src);
    self::assertStringContainsString('website_id=0', $src);
    self::assertStringContainsString('payload.website_ids', $src);
    self::assertStringContainsString('bound_website_ids', $src);
    self::assertStringNotContainsString('if (!id)', $src);
}
}
