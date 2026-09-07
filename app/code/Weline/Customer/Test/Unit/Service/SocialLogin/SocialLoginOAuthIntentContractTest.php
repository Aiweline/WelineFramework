<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service\SocialLogin;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Service\SocialLogin\SocialLoginOAuthService;

final class SocialLoginOAuthIntentContractTest extends TestCase
{
    public function testOAuthServiceDeclaresLoginAndBindIntentsAndPendingHelpers(): void
    {
        $src = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/Service/SocialLogin/SocialLoginOAuthService.php'
        );

        self::assertStringContainsString("INTENT_LOGIN = 'login'", $src);
        self::assertStringContainsString("INTENT_BIND = 'bind'", $src);
        self::assertStringContainsString('function storePending', $src);
        self::assertStringContainsString('function peekPending', $src);
        self::assertStringContainsString('function consumePending', $src);
        self::assertStringContainsString('SocialLoginTransientStore', $src);
        self::assertStringContainsString("'intent' => \$intent", $src);
        self::assertSame(SocialLoginOAuthService::INTENT_LOGIN, 'login');
        self::assertSame(SocialLoginOAuthService::INTENT_BIND, 'bind');
    }

    public function testLinkerExposesBindCreateAndUnbindApis(): void
    {
        $src = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/Service/SocialLogin/SocialLoginAccountLinker.php'
        );

        self::assertStringContainsString('function findBoundIdentity', $src);
        self::assertStringContainsString('function createFromProfile', $src);
        self::assertStringContainsString('function bindProfileToCustomer', $src);
        self::assertStringContainsString('function authenticateLocal', $src);
        self::assertStringContainsString('function unbind', $src);
        self::assertStringContainsString('function listProviderStatusForCustomer', $src);
    }
}
