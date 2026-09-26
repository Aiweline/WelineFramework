<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Mail 建站任务：自建邮局深度指引 + 交给 Smtp 自管。
 */
final class MailSiteSetupTaskContractTest extends TestCase
{
    public function testMailSetupTaskProviderRegistersViaExtends(): void
    {
        $mailRoot = dirname(__DIR__, 2);
        $provider = (string)file_get_contents(
            $mailRoot . '/extends/module/Weline_SiteSetupAssistant/SetupTask/MailSetupTaskProvider.php'
        );
        $extends = (string)file_get_contents($mailRoot . '/extends.php');

        self::assertStringContainsString('class MailSetupTaskProvider', $provider);
        self::assertStringContainsString('extends AbstractSetupTaskProvider', $provider);
        self::assertStringContainsString('mail_engine', $provider);
        self::assertStringContainsString('mail_domain', $provider);
        self::assertStringContainsString('mail_dns', $provider);
        self::assertStringContainsString('mail_account', $provider);
        self::assertStringContainsString('mail_smtp_handoff', $provider);
        self::assertStringContainsString('ensure_mail=1', $provider);
        self::assertStringContainsString('SetupTaskProviderInterface::class', $extends);
        self::assertStringContainsString('MailSetupTaskProvider::class', $extends);
    }
}
