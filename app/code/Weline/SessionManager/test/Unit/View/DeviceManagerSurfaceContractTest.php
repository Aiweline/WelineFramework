<?php

declare(strict_types=1);

namespace Weline\SessionManager\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class DeviceManagerSurfaceContractTest extends TestCase
{
    public function testStorefrontHookUsesPublishedCustomerProjectionContract(): void
    {
        $template = $this->read('view/hooks/account.sidebar.content.phtml');

        self::assertStringContainsString('AccountSidebarProjectionProviderInterface', $template);
        self::assertStringContainsString("forSections('devices')", $template);
        self::assertStringNotContainsString('Customer\\Service\\AccountSidebarContentGate', $template);
        self::assertStringNotContainsString('declare(strict_types=1)', $template);
    }

    public function testAllDeviceTemplatesFollowPhtmlAndEscapingContracts(): void
    {
        foreach ([
            'view/hooks/account.sidebar.phtml',
            'view/hooks/account.sidebar.content.phtml',
            'view/templates/Backend/Device/index.phtml',
            'view/templates/device/manager.phtml',
        ] as $path) {
            self::assertStringNotContainsString('declare(strict_types=1)', $this->read($path), $path);
        }

        $manager = $this->read('view/templates/device/manager.phtml');
        self::assertStringContainsString('htmlspecialchars', $manager);
        self::assertStringContainsString('data-list-operation', $manager);
        self::assertStringContainsString('data-revoke-operation', $manager);
    }

    public function testBrowserLogicUsesWelineApiAndInlineConfirmationOnly(): void
    {
        $script = $this->read('view/statics/js/device-manager.js');

        self::assertStringContainsString("Api.resource('session_manager')", $script);
        self::assertStringContainsString('data-device-confirm', $script);
        self::assertStringContainsString('formatApiError', $script);
        self::assertStringNotContainsString('window.confirm', $script);
        self::assertStringNotContainsString('global.confirm', $script);
        self::assertDoesNotMatchRegularExpression('/\\bfetch\\s*\\(/', $script);
        self::assertStringNotContainsString('XMLHttpRequest', $script);
        self::assertStringNotContainsString('.innerHTML', $script);
    }

    public function testSharedSurfaceUsesAreaThemeTokensAndOverridesHeadingColors(): void
    {
        $css = $this->read('view/statics/css/device-manager.css');
        $backend = $this->read('view/templates/Backend/Device/index.phtml');

        self::assertStringContainsString('--weline-theme-', $css);
        self::assertStringContainsString('[data-device-manager="backend"]', $css);
        self::assertStringContainsString('--backend-theme-surface', $css);
        self::assertStringContainsString('--backend-theme-text', $css);
        self::assertStringContainsString('.session-device-manager h2', $css);
        self::assertStringContainsString('color: var(--sdm-text)', $css);
        self::assertStringContainsString('page-title-box', $backend);
        self::assertStringContainsString('card-body', $backend);
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relativePath;
        $content = file_get_contents($path);
        self::assertIsString($content, $path);
        return $content;
    }
}
