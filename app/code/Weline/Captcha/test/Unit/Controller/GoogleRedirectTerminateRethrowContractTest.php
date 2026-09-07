<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * redirect() throws ResponseTerminateException; bare catch (\Throwable) turns a
 * successful Google OAuth redirect into a false failure back to SystemConfig.
 */
final class GoogleRedirectTerminateRethrowContractTest extends TestCase
{
    public function testAuthorizeRethrowsResponseTerminateException(): void
    {
        $src = (string)file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/Google.php');
        self::assertStringContainsString('use Weline\\Framework\\Http\\RedirectException;', $src);
        self::assertStringContainsString('use Weline\\Framework\\Http\\ResponseTerminateException;', $src);
        self::assertStringContainsString("throw new RedirectException((string)\$result['authorization_url'], 302);", $src);
        self::assertStringContainsString('catch (ResponseTerminateException $terminate)', $src);
        self::assertStringContainsString('throw $terminate;', $src);
        self::assertStringNotContainsString(
            "return \$this->redirect(\$result['authorization_url']);",
            $src,
        );
    }
}
