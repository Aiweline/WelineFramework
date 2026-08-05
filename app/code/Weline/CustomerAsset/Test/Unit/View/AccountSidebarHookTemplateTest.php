<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountSidebarHookTemplateTest extends TestCase
{
    public function testHooksUseOfficialProjectionAndReadOnlyAccountContract(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $sidebarPath = $moduleRoot . '/view/hooks/account.sidebar.phtml';
        $contentPath = $moduleRoot . '/view/hooks/account.sidebar.content.phtml';

        self::assertFileExists($sidebarPath);
        self::assertFileExists($contentPath);
        $sidebar = (string)file_get_contents($sidebarPath);
        $content = (string)file_get_contents($contentPath);

        self::assertStringContainsString('data-section="assets"', $sidebar);
        self::assertStringContainsString('#assets', $sidebar);
        self::assertStringContainsString('data-account-nav-link="true"', $sidebar);
        self::assertStringContainsString(
            'AccountSidebarProjectionProviderInterface',
            $content,
        );
        self::assertStringContainsString("forSections('assets')", $content);
        self::assertStringContainsString('AccountAssetPresenter', $content);
        self::assertStringContainsString('data-account-section="assets"', $content);
        self::assertStringContainsString(
            'weline-code="customer_asset.hook.account_sidebar_content.assets_section"',
            $content,
        );
        self::assertStringContainsString('prefers-reduced-motion', $content);
        self::assertStringNotContainsString('Customer::class', $content);
        self::assertStringNotContainsString('SessionFactory', $content);
        self::assertStringNotContainsString('fetch(', $content);
        self::assertStringNotContainsString('XMLHttpRequest', $content);
        self::assertStringNotContainsString('axios', $content);
        self::assertStringNotContainsString('<w:form', $content);
    }
}
