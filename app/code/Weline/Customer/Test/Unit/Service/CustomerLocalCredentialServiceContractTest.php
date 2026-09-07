<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class CustomerLocalCredentialServiceContractTest extends TestCase
{
    public function testCredentialServiceIsSharedByLoginAndSocialBind(): void
    {
        $service = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/CustomerLocalCredentialService.php'
        );
        $linker = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/SocialLogin/SocialLoginAccountLinker.php'
        );
        $login = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Account/Login.php'
        );

        self::assertStringContainsString('function authenticate', $service);
        self::assertStringContainsString('password_verify', $service);
        self::assertStringContainsString('CustomerLocalCredentialService', $linker);
        self::assertStringContainsString('CustomerLocalCredentialService', $login);
        self::assertStringNotContainsString('password_verify($password, (string) $user->getPassword())', $linker);
    }
}
