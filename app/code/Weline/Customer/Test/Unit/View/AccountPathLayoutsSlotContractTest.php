<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Path-aligned account layouts must declare editor slots in-file（禁止薄包装 fetch auth 丢 slot）。
 */
final class AccountPathLayoutsSlotContractTest extends TestCase
{
    /** @return list<string> */
    private function pathActions(): array
    {
        return [
            'login',
            'register',
            'forgot-password',
            'set-password',
            'social-login',
            'logout',
            'orders',
            'profile',
        ];
    }

    public function testPathLayoutsDeclareContentSlotsWithoutFetchingAuthShell(): void
    {
        $base = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/account';
        foreach ($this->pathActions() as $action) {
            $path = $base . '/' . $action . '/default.phtml';
            self::assertFileExists($path, $action);
            $src = (string) file_get_contents($path);
            self::assertStringContainsString(
                '<w:slot id="content"',
                $src,
                $action . ' must declare content slot in-file'
            );
            self::assertStringNotContainsString(
                "fetch('Weline_Customer::theme/frontend/layouts/account/auth.phtml')",
                $src,
                $action . ' must not thin-wrap auth shell'
            );
            self::assertStringNotContainsString(
                'fetch("Weline_Customer::theme/frontend/layouts/account/auth.phtml")',
                $src,
                $action . ' must not thin-wrap auth shell'
            );
        }
    }

    public function testAuthFamilyLayoutsKeepAccountAuthContentSlot(): void
    {
        $base = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/account';
        foreach (['login', 'register', 'forgot-password', 'set-password', 'social-login'] as $action) {
            $src = (string) file_get_contents($base . '/' . $action . '/default.phtml');
            self::assertStringContainsString(
                '<w:slot id="account-auth-content"',
                $src,
                $action . ' must keep account-auth-content slot'
            );
            self::assertStringContainsString('seo::footer', $src, $action);
        }
    }

    public function testLoginLayoutRendersCustomerOwnedAuthenticationContent(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/layouts/account/login/default.phtml'
        );
        self::assertStringNotContainsString('foreign-theme-account-login', $src);
        self::assertStringNotContainsString('foreign-theme-account-register', $src);
        self::assertStringNotContainsString('<w:widget type="form" name="account-login"', $src);
        self::assertStringNotContainsString('<w:widget type="form" name="account-register"', $src);
        self::assertStringContainsString('{{meta.content}}', $src);
        self::assertStringContainsString(
            "Weline_Customer::templates/frontend/account/login.phtml",
            $src
        );
        self::assertStringContainsString("__force_login_stage'] = true", $src);
    }

    public function testLoginTemplateKeepsSocialProvidersSlot(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/account/login.phtml'
        );
        self::assertStringContainsString('<w:slot id="account-login-social-providers"', $src);
    }

    public function testLoginStageRendersOnlyItsOwnAuthenticationWidget(): void
    {
        $this->assertLoginStageHasOneAuthenticationWidget(
            dirname(__DIR__, 3) . '/view/theme/frontend/layouts/account/login/default.phtml'
        );
    }

    public function testHanfuLoginStageRendersOnlyItsOwnAuthenticationWidget(): void
    {
        $path = dirname(__DIR__, 7) . '/app/design/Weline/hanfu/frontend/layouts/account/login/default.phtml';
        if (!is_file($path)) {
            self::markTestSkipped('Hanfu design theme is installed separately from the framework repository.');
        }
        $this->assertLoginStageHasOneAuthenticationWidget($path);
    }

    private function assertLoginStageHasOneAuthenticationWidget(string $path): void
    {
        $src = (string) file_get_contents($path);
        $start = strpos($src, '<main ');
        $end = strpos($src, '</main>', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $stage = substr($src, $start, $end + strlen('</main>') - $start);
        self::assertStringNotContainsString('foreign-theme-account-', $stage, $path);
        self::assertStringNotContainsString('<w:widget type="form" name="account-login"', $stage, $path);
        self::assertStringContainsString(
            "Weline_Customer::templates/frontend/account/login.phtml",
            $stage,
            $path . ': login stage must fall back to Customer login.phtml'
        );
    }
}
