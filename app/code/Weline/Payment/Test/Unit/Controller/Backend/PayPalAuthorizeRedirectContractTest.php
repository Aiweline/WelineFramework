<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * 一键授权不得把 RedirectException(302) 当成业务错误 toast；OAuth 须直跳网关。
 */
final class PayPalAuthorizeRedirectContractTest extends TestCase
{
    public function testConnectAuthorizeRethrowsTerminateAndUsesRedirectExceptionForOauth(): void
    {
        $controller = dirname(__DIR__, 4) . '/Controller/Backend/Connect.php';
        self::assertFileExists($controller);
        $src = (string) file_get_contents($controller);

        self::assertStringContainsString('use Weline\\Framework\\Http\\RedirectException;', $src);
        self::assertStringContainsString('use Weline\\Framework\\Http\\ResponseTerminateException;', $src);
        self::assertStringContainsString('throw new RedirectException((string) $started[\'authorization_url\'], 302);', $src);
        self::assertStringContainsString('catch (ResponseTerminateException $exception)', $src);
        self::assertStringContainsString('throw $exception;', $src);
        self::assertStringContainsString('catch (\\Throwable $exception)', $src);
        self::assertStringContainsString('method_code', $src);
        self::assertStringContainsString('SystemConfigTargetScopeService', $src);
        self::assertStringContainsString('storage_scope', $src);
        self::assertStringContainsString('substr_count($scope, \'.\') === 2', $src);

        $terminatePos = strpos($src, 'catch (ResponseTerminateException $exception)');
        $throwablePos = strpos($src, 'catch (\\Throwable $exception)');
        self::assertNotFalse($terminatePos);
        self::assertNotFalse($throwablePos);
        self::assertLessThan($throwablePos, $terminatePos);
    }

    public function testLegacyPayPalAuthorizeIsThinAliasToConnect(): void
    {
        $controller = dirname(__DIR__, 4) . '/Controller/Backend/PayPal.php';
        $src = (string) file_get_contents($controller);
        self::assertStringContainsString('payment/backend/connect/', $src);
        self::assertStringContainsString("'method_code' => 'paypal'", $src);
        self::assertStringNotContainsString('PayPalOAuthService', $src);
        self::assertStringNotContainsString('throw new RedirectException', $src);
    }
}
