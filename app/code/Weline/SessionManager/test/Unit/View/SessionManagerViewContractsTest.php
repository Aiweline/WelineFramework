<?php

declare(strict_types=1);

namespace Weline\SessionManager\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSessionManagerViewContractsPass().
 */
final class SessionManagerViewContractsTest extends TestCase
{
    public function testAllSessionManagerViewContractsPass(): void
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
            'DeviceManagerSurface::testStorefrontHookUsesPublishedCustomerProjectionContract' => function () {
            $this->assert_DeviceManagerSurface_testStorefrontHookUsesPublishedCustomerProjectionContract();
        },
            'DeviceManagerSurface::testAllDeviceTemplatesFollowPhtmlAndEscapingContracts' => function () {
            $this->assert_DeviceManagerSurface_testAllDeviceTemplatesFollowPhtmlAndEscapingContracts();
        },
            'DeviceManagerSurface::testBrowserLogicUsesWelineApiAndInlineConfirmationOnly' => function () {
            $this->assert_DeviceManagerSurface_testBrowserLogicUsesWelineApiAndInlineConfirmationOnly();
        },
            'DeviceManagerSurface::testSharedSurfaceUsesAreaThemeTokensAndOverridesHeadingColors' => function () {
            $this->assert_DeviceManagerSurface_testSharedSurfaceUsesAreaThemeTokensAndOverridesHeadingColors();
        },
        ];
    }

private function assert_DeviceManagerSurface_testStorefrontHookUsesPublishedCustomerProjectionContract(): void
{
    $template = $this->read_DeviceManagerSurface('view/hooks/account.sidebar.content.phtml');
    self::assertStringContainsString('AccountSidebarProjectionProviderInterface', $template);
    self::assertStringContainsString("forSections('devices')", $template);
    self::assertStringNotContainsString('Customer\Service\AccountSidebarContentGate', $template);
    self::assertStringNotContainsString('declare(strict_types=1)', $template);
}

private function assert_DeviceManagerSurface_testAllDeviceTemplatesFollowPhtmlAndEscapingContracts(): void
{
    foreach (['view/hooks/account.sidebar.group.security.phtml', 'view/hooks/account.sidebar.content.phtml', 'view/templates/backend/Device/index.phtml', 'view/templates/device/manager.phtml'] as $path) {
        self::assertStringNotContainsString('declare(strict_types=1)', $this->read_DeviceManagerSurface($path), $path);
    }
    $manager = $this->read_DeviceManagerSurface('view/templates/device/manager.phtml');
    self::assertStringContainsString('htmlspecialchars', $manager);
    self::assertStringContainsString('data-list-operation', $manager);
    self::assertStringContainsString('data-revoke-operation', $manager);
}

private function assert_DeviceManagerSurface_testBrowserLogicUsesWelineApiAndInlineConfirmationOnly(): void
{
    $script = $this->read_DeviceManagerSurface('view/statics/js/device-manager.js');
    self::assertStringContainsString("Api.resource('session_manager')", $script);
    self::assertStringContainsString('data-device-confirm', $script);
    self::assertStringContainsString('formatApiError', $script);
    self::assertStringNotContainsString('window.confirm', $script);
    self::assertStringNotContainsString('global.confirm', $script);
    self::assertDoesNotMatchRegularExpression('/\bfetch\s*\(/', $script);
    self::assertStringNotContainsString('XMLHttpRequest', $script);
    self::assertStringNotContainsString('.innerHTML', $script);
}

private function assert_DeviceManagerSurface_testSharedSurfaceUsesAreaThemeTokensAndOverridesHeadingColors(): void
{
    $css = $this->read_DeviceManagerSurface('view/statics/css/device-manager.css');
    $backend = $this->read_DeviceManagerSurface('view/templates/backend/Device/index.phtml');
    self::assertStringContainsString('--weline-theme-', $css);
    self::assertStringContainsString('[data-device-manager="backend"]', $css);
    self::assertStringContainsString('--backend-theme-surface', $css);
    self::assertStringContainsString('--backend-theme-text', $css);
    self::assertStringContainsString('.session-device-manager h2', $css);
    self::assertStringContainsString('color: var(--sdm-text)', $css);
    self::assertStringContainsString('page-title-box', $backend);
    self::assertStringContainsString('card-body', $backend);
}

private function read_DeviceManagerSurface(string $relativePath): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/SessionManager' . '/' . $relativePath;
    $content = file_get_contents($path);
    self::assertIsString($content, $path);
    return $content;
}
}
