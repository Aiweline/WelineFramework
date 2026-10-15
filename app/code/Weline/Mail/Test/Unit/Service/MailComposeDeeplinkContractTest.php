<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class MailComposeDeeplinkContractTest extends TestCase
{
    public function testSmtpServiceExposesLocalMailboxApis(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Mail/Service/MailSmtpAccountService.php');
        self::assertStringContainsString('function resolveLocalMailboxByEmail', $src);
        self::assertStringContainsString('function listLocalMailboxes', $src);
        self::assertStringContainsString('function toPublicMailbox', $src);
    }

    public function testQueryProviderExposesResolveAndList(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Mail/extends/module/Weline_Framework/Query/MailQueryProvider.php');
        self::assertStringContainsString('resolveLocalMailboxByEmail', $src);
        self::assertStringContainsString('listLocalMailboxes', $src);
        self::assertStringContainsString('listThreadBySource', $src);
        self::assertStringContainsString('sendComposerMessage', $src);
        $sendPos = strpos($src, "'name' => 'sendComposerMessage'");
        self::assertNotFalse($sendPos);
        $sendSlice = substr($src, $sendPos, 700);
        self::assertStringContainsString("'frontend' => true", $sendSlice);
        self::assertStringContainsString("'mode' => 'write'", $sendSlice);
        self::assertStringContainsString("'auth' => 'backend'", $sendSlice);
        self::assertStringContainsString('Weline_Mail::mail_send_as', $sendSlice);
    }

    public function testBackendComposePrefillAndSendEvent(): void
    {
        $controller = (string)file_get_contents(BP . 'app/code/Weline/Mail/Controller/Backend/Index.php');
        self::assertStringContainsString('resolveComposeContext', $controller);
        self::assertStringContainsString('canPickAnyLocalMailbox', $controller);
        self::assertStringContainsString('MailComposerService', $controller);
        self::assertStringContainsString('resolveSendAsAccountId', $controller);

        $tpl = (string)file_get_contents(BP . 'app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml');
        self::assertStringContainsString('compose_open', $tpl);
        self::assertStringContainsString('compose_source', $tpl);
        self::assertStringContainsString('name="source"', $tpl);
        self::assertStringContainsString('mail-enterprise-compose-cancel', $tpl);
        self::assertStringContainsString('composeCloseParams', $tpl);

        $mailboxStart = strpos($tpl, "if (\$view === 'mailbox')");
        $accountsStart = strpos($tpl, "elseif (\$view === 'accounts')");
        self::assertNotFalse($mailboxStart);
        self::assertNotFalse($accountsStart);
        self::assertGreaterThan($mailboxStart, $accountsStart);
        $mailboxTpl = substr($tpl, $mailboxStart, $accountsStart - $mailboxStart);
        self::assertStringNotContainsString('<details', $mailboxTpl);
        self::assertStringNotContainsString('<summary', $mailboxTpl);
        self::assertStringContainsString('if ($composeOpen)', $mailboxTpl);
        self::assertStringContainsString('id="mail-compose"', $mailboxTpl);
        self::assertStringContainsString('w:customer:admin:select', $mailboxTpl);
        self::assertStringContainsString('mail-compose-customer', $mailboxTpl);
        self::assertStringContainsString('mail-enterprise-compose-to', $mailboxTpl);
        self::assertStringContainsString('name="to_email"', $mailboxTpl);
        self::assertStringContainsString('mail-enterprise-compose-form', $mailboxTpl);
        self::assertStringContainsString('mail-enterprise-compose-send', $mailboxTpl);
        self::assertStringContainsString('sendComposerMessage', $mailboxTpl);
        self::assertStringContainsString('UI.loading', $mailboxTpl);
        self::assertStringContainsString('toast', $mailboxTpl);
        self::assertStringNotContainsString('展开写信', $mailboxTpl);
        self::assertStringNotContainsString('收起写信', $mailboxTpl);

        self::assertStringContainsString('resolveComposeCustomerPick', $controller);
        self::assertStringContainsString('compose_customer_select_value', $controller);

        $eventsSrc = (string)file_get_contents(BP . 'app/code/Weline/Mail/event.php');
        self::assertStringContainsString('Weline_Mail::mail_message_sent', $eventsSrc);
        self::assertFileExists(BP . 'app/code/Weline/Mail/doc/event/mail_message_sent.md');
    }

    public function testModuleVersion(): void
    {
        $module = include BP . 'app/code/Weline/Mail/etc/module.php';
        self::assertSame('0.2.20', $module['version'] ?? null);
    }
}
