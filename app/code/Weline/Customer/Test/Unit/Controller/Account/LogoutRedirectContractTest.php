<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;

final class LogoutRedirectContractTest extends TestCase
{
    public function testLogoutRedirectUsesUrlGeneratorNotHardcodedPath(): void
    {
        $file = dirname(__DIR__, 4) . '/Controller/Account/Logout.php';
        self::assertFileExists($file);
        $src = (string)file_get_contents($file);

        self::assertStringContainsString("getUrl('customer/account/login'", $src);
        self::assertStringContainsString("redirect('customer/account/login'", $src);
        self::assertStringContainsString('AUTH_REFRESH_QUERY', $src);
        self::assertStringContainsString('AUTH_REFRESH_LOGOUT_VALUE', $src);
        self::assertStringNotContainsString(
            "formatAuthInvalidRedirect('/customer/account/login')",
            $src
        );
    }

    public function testAccountQueryLogoutUsesUrlGenerator(): void
    {
        $file = dirname(__DIR__, 4)
            . '/extends/module/Weline_Framework/Query/AccountQueryProvider.php';
        self::assertFileExists($file);
        $src = (string)file_get_contents($file);

        self::assertStringContainsString("getUrl('customer/account/login'", $src);
        self::assertStringContainsString('AUTH_REFRESH_QUERY', $src);
        self::assertStringContainsString('AUTH_REFRESH_LOGOUT_VALUE', $src);
    }

    public function testStorefrontLogoutAnchorsUseUrlTag(): void
    {
        $roots = [
            dirname(__DIR__, 4) . '/view/templates/frontend/account/sidebar/side.phtml',
            dirname(__DIR__, 5) . '/Theme/view/theme/frontend/widgets/header/account/default.phtml',
            dirname(__DIR__, 5) . '/Theme/view/hooks/header-account.phtml',
        ];
        foreach ($roots as $file) {
            self::assertFileExists($file, $file);
            $src = (string)file_get_contents($file);
            self::assertStringContainsString("@url{'customer/account/logout'}", $src, $file);
            self::assertStringNotContainsString('href="/customer/account/logout"', $src, $file);
        }
    }
}
