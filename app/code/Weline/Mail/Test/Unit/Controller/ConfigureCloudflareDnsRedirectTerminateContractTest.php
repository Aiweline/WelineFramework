<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/**
 * respondFormResult → redirect() 抛 ResponseTerminateException(302)；
 * 若被 catch (\Throwable) 吞掉会 flash「Response terminate with status 302」。
 */
final class ConfigureCloudflareDnsRedirectTerminateContractTest extends TestCase
{
    public function testPostConfigureCloudflareDnsRethrowsResponseTerminateException(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Mail/Controller/Backend/Index.php');
        self::assertStringContainsString(
            'use Weline\\Framework\\Http\\ResponseTerminateException;',
            $src
        );
        self::assertStringContainsString('catch (ResponseTerminateException $terminate)', $src);
        self::assertStringContainsString('throw $terminate;', $src);
        self::assertLessThan(
            (int)strpos($src, 'Cloudflare DNS 配置失败：%{1}'),
            (int)strpos($src, 'catch (ResponseTerminateException $terminate)')
        );
    }
}
