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
    }

    public function testBackendComposePrefillAndSendEvent(): void
    {
        $controller = (string)file_get_contents(BP . 'app/code/Weline/Mail/Controller/Backend/Index.php');
        self::assertStringContainsString('resolveComposeContext', $controller);
        self::assertStringContainsString('canPickAnyLocalMailbox', $controller);
        self::assertStringContainsString('MailComposerService', $controller);
        self::assertStringContainsString('resolveSendAsAccountId', $controller);

        $tpl = (string)file_get_contents(BP . 'app/code/Weline/Mail/view/templates/Backend/Index/enterprise.phtml');
        self::assertStringContainsString('compose_open', $tpl);
        self::assertStringContainsString('compose_source', $tpl);
        self::assertStringContainsString('name="source"', $tpl);

        $eventsSrc = (string)file_get_contents(BP . 'app/code/Weline/Mail/event.php');
        self::assertStringContainsString('Weline_Mail::mail_message_sent', $eventsSrc);
        self::assertFileExists(BP . 'app/code/Weline/Mail/doc/event/mail_message_sent.md');
    }

    public function testModuleVersion(): void
    {
        $module = include BP . 'app/code/Weline/Mail/etc/module.php';
        self::assertSame('0.2.1', $module['version'] ?? null);
    }
}
