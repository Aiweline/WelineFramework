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
            BP . '/app/code/Weline/Customer/Controller/Account/ForgotPassword.php'
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
            BP . '/app/code/Weline/Customer/Controller/Account/Register.php'
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
}
