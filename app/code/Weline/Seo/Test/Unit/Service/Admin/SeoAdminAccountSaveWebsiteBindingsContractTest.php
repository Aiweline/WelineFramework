<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Admin;

use PHPUnit\Framework\TestCase;

/**
 * 账户保存必须同步 website_ids（含默认站 website_id=0），禁止提前 return 丢掉绑定。
 */
final class SeoAdminAccountSaveWebsiteBindingsContractTest extends TestCase
{
    public function testSaveAccountAssignsTransactionResultBeforeBindingSync(): void
    {
        $path = dirname(__DIR__, 4) . '/Service/Admin/SeoAdminAccountService.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertMatchesRegularExpression(
            '/\$saved\s*=\s*\$this->transactions->run\(/',
            $src,
            'saveAccount 必须先把事务结果赋给 $saved，再同步 website_ids'
        );
        self::assertStringNotContainsString(
            "return \$this->transactions->run(\$this->accounts->getConnection()",
            $src,
            '禁止提前 return 事务结果导致 website_ids 绑定死代码'
        );
        self::assertStringContainsString("array_key_exists('website_ids', \$params)", $src);
        self::assertStringContainsString('saveAccountWebsiteBindings($savedAccountId, $websiteIds)', $src);
        self::assertStringContainsString('bound_website_ids', $src);
        self::assertStringContainsString('账户已保存，但站点绑定未同步成功，请重试保存。', $src);
    }

    public function testFrontendKeepsWebsiteIdZeroWhenReadingBoundIds(): void
    {
        $js = dirname(__DIR__, 4) . '/view/statics/js/seo-admin.js';
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
