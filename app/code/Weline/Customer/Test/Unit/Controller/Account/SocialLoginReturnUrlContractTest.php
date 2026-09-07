<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;

/**
 * Social login success must reuse CustomerAuthReturnUrlService like password login.
 */
final class SocialLoginReturnUrlContractTest extends TestCase
{
    public function testSocialLoginSuccessUsesFormatAuthSuccessRedirect(): void
    {
        $root = dirname(__DIR__, 4);
        $controller = (string) file_get_contents($root . '/Controller/Account/SocialLogin.php');
        self::assertStringContainsString('formatSocialLoginSuccessRedirect', $controller);
        self::assertStringContainsString('formatAuthSuccessRedirect', $controller);
        self::assertStringNotContainsString("\$target = '/customer/account';", $controller);
        self::assertStringNotContainsString("'redirect' => '/customer/account'", $controller);

        $quick = (string) file_get_contents(
            $root . '/Service/SocialLogin/SocialLoginQuickAuthService.php'
        );
        self::assertStringContainsString('formatAuthSuccessRedirect', $quick);
        self::assertStringNotContainsString("\$target = '/customer/account';", $quick);

        $auth = (string) file_get_contents($root . '/Service/CustomerAuthReturnUrlService.php');
        self::assertStringContainsString("'customer/account/social-login'", $auth);
    }
}
