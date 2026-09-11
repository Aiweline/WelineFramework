<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SMTP 配置页：账户管理 / 渠道绑定用 Theme tabs 切换，禁止翻页控件。
 */
final class SmtpConfigTabsContractTest extends TestCase
{
    public function testConfigTemplateUsesThemeTabsInsteadOfPagination(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        $template = (string)file_get_contents($moduleRoot . '/view/Backend/Config.phtml');

        self::assertStringContainsString('data-w-component="tabs"', $template);
        self::assertStringContainsString('data-testid="smtp-config-tabs"', $template);
        self::assertStringContainsString('data-testid="smtp-tab-accounts"', $template);
        self::assertStringContainsString('data-testid="smtp-tab-channels"', $template);
        self::assertStringContainsString('id="smtp-panel-accounts"', $template);
        self::assertStringContainsString('id="smtp-panel-channels"', $template);
        self::assertStringContainsString('data-testid="smtp-account-management"', $template);
        self::assertStringContainsString('data-testid="smtp-channel-bindings"', $template);
        self::assertStringContainsString('btnSaveSenders', $template);

        self::assertStringNotContainsString('pagination', $template);
        self::assertStringNotContainsString('getPagination', $template);
        self::assertStringNotContainsString('w-pagination', $template);
    }
}
