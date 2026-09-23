<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 新建 SEO 账户：平台支持时 Sitemap/URL 定时自动选项默认勾选。
 */
final class AccountFormAutoSubmitDefaultsContractTest extends TestCase
{
    public function testNewAccountFormDefaultsCronSwitchesOn(): void
    {
        $root = dirname(__DIR__, 3);
        $template = $root . '/view/templates/Backend/Account/form.phtml';
        $model = $root . '/Model/SeoAccount.php';
        $adminService = $root . '/Service/Admin/SeoAdminAccountService.php';

        self::assertFileExists($template);
        self::assertFileExists($model);
        self::assertFileExists($adminService);

        $templateSrc = (string) file_get_contents($template);
        $modelSrc = (string) file_get_contents($model);
        $serviceSrc = (string) file_get_contents($adminService);

        self::assertStringContainsString(
            'ENABLE_CRON_SITEMAP) : 1;',
            $templateSrc,
            '新建账户表单应默认勾选 Sitemap 定时提交'
        );
        self::assertStringContainsString(
            'ENABLE_CRON_PUSH_URLS) : 1;',
            $templateSrc,
            '新建账户表单应默认勾选 URL 定时推送'
        );
        self::assertStringContainsString(
            'name="enable_cron_sitemap" value="0"',
            $templateSrc,
            '未勾选时需 hidden=0 才能显式关闭'
        );
        self::assertStringContainsString(
            'wasUnsupported',
            $templateSrc,
            '从不支持切回可支持时应恢复默认勾选'
        );

        self::assertMatchesRegularExpression(
            '/default:\s*1,\s*comment:\s*\'是否启用Sitemap定时提交\'/',
            $modelSrc
        );

        self::assertStringContainsString(
            "array_key_exists('enable_cron_sitemap', \$params)",
            $serviceSrc
        );
        self::assertStringContainsString(
            "? !empty(\$params['enable_cron_sitemap']) : 1)",
            $serviceSrc
        );
    }
}
