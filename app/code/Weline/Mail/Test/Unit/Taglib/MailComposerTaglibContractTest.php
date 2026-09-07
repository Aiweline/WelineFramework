<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class MailComposerTaglibContractTest extends TestCase
{
    public function testTaglibImplementsMailComposer(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Mail/Taglib/MailComposer.php');
        self::assertStringContainsString("return 'mail-composer'", $src);
        self::assertStringContainsString('TaglibInterface', $src);
        self::assertStringNotContainsString('@static(', $src);
        self::assertStringContainsString('resolveStatic', $src);
        self::assertStringContainsString('fetchTagSource', $src);
    }

    public function testJsApiAndStaticsExist(): void
    {
        self::assertFileExists(BP . 'app/code/Weline/Mail/view/statics/js/mail-composer.js');
        self::assertFileExists(BP . 'app/code/Weline/Mail/view/statics/css/mail-composer.css');
        $js = (string)file_get_contents(BP . 'app/code/Weline/Mail/view/statics/js/mail-composer.js');
        self::assertStringContainsString('WelineMailComposer', $js);
        self::assertStringContainsString('open:', $js);
    }

    public function testComposerServiceAndControllers(): void
    {
        self::assertFileExists(BP . 'app/code/Weline/Mail/Service/MailComposerService.php');
        $svc = (string)file_get_contents(BP . 'app/code/Weline/Mail/Service/MailComposerService.php');
        self::assertStringContainsString('listThreadBySource', $svc);
        self::assertStringContainsString('mail_message_sent', $svc);
        self::assertFileExists(BP . 'app/code/Weline/Mail/Controller/Backend/Composer.php');
        self::assertFileExists(BP . 'app/code/Weline/Mail/Controller/Frontend/Composer.php');
    }

    public function testSceneMapListsMailComposer(): void
    {
        $map = (string)file_get_contents(BP . 'app/code/Weline/Taglib/doc/场景映射表.md');
        self::assertStringContainsString('mail-composer', $map);
        self::assertStringContainsString('企业邮箱', $map);
    }

    public function testModuleVersion(): void
    {
        $module = include BP . 'app/code/Weline/Mail/etc/module.php';
        self::assertSame('0.2.1', $module['version'] ?? null);
    }
}
