<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;

/**
 * RedirectException extends ResponseTerminateException; catching bare Throwable around
 * $this->redirect() turns "Response terminate with status 302" into MessageManager flash.
 */
class AuthRedirectTerminateRethrowContractTest extends TestCase
{
    public function testForgotPasswordRethrowsResponseTerminateException(): void
    {
        $src = (string)file_get_contents(
            \dirname(__DIR__, 4) . '/Controller/Account/ForgotPassword.php'
        );

        self::assertStringContainsString(
            'use Weline\\Framework\\Http\\ResponseTerminateException;',
            $src
        );
        self::assertSame(2, substr_count($src, 'catch (ResponseTerminateException $terminate)'));
        self::assertStringContainsString('throw $terminate;', $src);
        self::assertStringNotContainsString(
            "return \$this->redirect('/customer/account/login');\n        } catch (\\Throwable",
            $src
        );
    }

    public function testRegisterRethrowsResponseTerminateException(): void
    {
        $src = (string)file_get_contents(
            \dirname(__DIR__, 4) . '/Controller/Account/Register.php'
        );

        self::assertStringContainsString(
            'use Weline\\Framework\\Http\\ResponseTerminateException;',
            $src
        );
        self::assertStringContainsString('catch (ResponseTerminateException $terminate)', $src);
        self::assertStringContainsString('throw $terminate;', $src);
        self::assertLessThan(
            strpos($src, 'MessageManager::success(__(\'注册成功，欢迎加入。\'))'),
            strpos($src, 'catch (ResponseTerminateException $terminate)')
        );
    }

    public function testSocialLoginRethrowsResponseTerminateException(): void
    {
        $src = (string)file_get_contents(
            \dirname(__DIR__, 4) . '/Controller/Account/SocialLogin.php'
        );

        self::assertStringContainsString(
            'use Weline\\Framework\\Http\\ResponseTerminateException;',
            $src
        );
        self::assertGreaterThanOrEqual(2, substr_count($src, 'catch (ResponseTerminateException $terminate)'));
        self::assertStringContainsString('throw $terminate;', $src);
        self::assertStringContainsString('use Weline\\Framework\\Http\\RedirectException;', $src);
        self::assertStringContainsString(
            "throw new RedirectException((string) \$started['authorization_url'], 302);",
            $src
        );
        self::assertStringContainsString('getChoose', $src);
        self::assertStringContainsString('postBindExisting', $src);
        self::assertStringContainsString('postCreateNew', $src);
        self::assertStringContainsString('postUnbind', $src);
        self::assertStringContainsString('INTENT_BIND', $src);
        self::assertStringContainsString('storePending', $src);
    }
}
