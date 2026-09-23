<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 账户表单内可用 WebsiteSelect 多选绑定站点。
 */
final class AccountFormWebsiteBindingSelectContractTest extends TestCase
{
    public function testAccountFormExposesMultiWebsiteSelectBinding(): void
    {
        $root = dirname(__DIR__, 3);
        $template = $root . '/view/templates/Backend/Account/form.phtml';
        $js = $root . '/view/statics/js/seo-admin.js';
        $service = $root . '/Service/Admin/SeoAdminAccountService.php';
        $provider = $root . '/extends/module/Weline_Framework/Query/SeoAdminQueryProvider.php';

        self::assertFileExists($template);
        self::assertFileExists($js);
        self::assertFileExists($service);
        self::assertFileExists($provider);

        $templateSrc = (string) file_get_contents($template);
        $jsSrc = (string) file_get_contents($js);
        $serviceSrc = (string) file_get_contents($service);
        $providerSrc = (string) file_get_contents($provider);

        self::assertStringContainsString('data-seo-account-website-bindings', $templateSrc);
        self::assertStringContainsString('seo_account_bound_websites', $templateSrc);
        self::assertStringContainsString('w:websites:website:select', $templateSrc);
        self::assertStringContainsString('multiple="true"', $templateSrc);
        self::assertStringContainsString('boundWebsiteIdsValue', $templateSrc);

        self::assertStringContainsString('readAccountBoundWebsiteIds', $jsSrc);
        self::assertStringContainsString('payload.website_ids', $jsSrc);
        self::assertStringContainsString('seo_account_bound_websites', $jsSrc);

        self::assertStringContainsString("array_key_exists('website_ids', \$params)", $serviceSrc);
        self::assertStringContainsString('saveAccountWebsiteBindings', $serviceSrc);
        self::assertStringContainsString("'website_ids' => ['type' => 'list'", $providerSrc);
    }
}
