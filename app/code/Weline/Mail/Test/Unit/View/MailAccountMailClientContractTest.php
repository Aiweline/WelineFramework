<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\View;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class MailAccountMailClientContractTest extends TestCase
{
    public function testMailClientExposesReplyAndAccountControls(): void
    {
        $path = BP . 'app/code/Weline/Mail/view/templates/frontend/account/mail-client.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('data-w-component="account-mail-client"', $src);
        self::assertStringContainsString('getAccountsForCustomer', $src);
        self::assertStringContainsString('mail_compose', $src);
        self::assertStringContainsString('mail_to', $src);
        self::assertStringContainsString('mail_subject', $src);
        self::assertStringContainsString('mail_body', $src);
        self::assertStringContainsString('data-mail-action="reply"', $src);
        self::assertStringContainsString("__('回复')", $src);
        self::assertStringContainsString("weline_mail/frontend/account/mail/suspend", $src);
        self::assertStringContainsString("weline_mail/frontend/account/mail/resume", $src);
        self::assertStringContainsString('data-mail-action="suspend"', $src);
        self::assertStringContainsString('data-mail-action="resume"', $src);
        self::assertStringContainsString('#mail', $src);
    }

    public function testSidebarKeepsSingleMailEntry(): void
    {
        $nav = (string)file_get_contents(BP . 'app/code/Weline/Mail/view/hooks/account.sidebar.group.connections.phtml');
        $content = (string)file_get_contents(BP . 'app/code/Weline/Mail/view/hooks/account.sidebar.content.phtml');
        self::assertStringContainsString('getAccountsForCustomer', $nav);
        self::assertStringContainsString('getAccountsForCustomer', $content);
        self::assertStringContainsString("#mail", $nav);
        self::assertStringContainsString('我的邮箱', $nav);
        self::assertSame(1, substr_count($nav, 'data-section="mail"'));
        self::assertStringContainsString('mail-client.phtml', $content);
        self::assertStringNotContainsString('回复管理', $nav);
    }
}
