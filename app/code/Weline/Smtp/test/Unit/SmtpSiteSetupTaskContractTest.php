<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Smtp 建站任务：继承抽象 Provider，自声明发信/模板/多语言条目并自检。
 */
final class SmtpSiteSetupTaskContractTest extends TestCase
{
    public function testSmtpSetupTaskProviderRegistersViaExtends(): void
    {
        $smtpRoot = dirname(__DIR__, 2);
        $ssaRoot = dirname($smtpRoot) . '/SiteSetupAssistant';

        $interface = (string)file_get_contents($ssaRoot . '/Api/SetupTaskProviderInterface.php');
        $abstract = (string)file_get_contents($ssaRoot . '/Api/AbstractSetupTaskProvider.php');
        $collector = (string)file_get_contents($ssaRoot . '/Service/SetupTaskCollector.php');
        $float = (string)file_get_contents(
            $ssaRoot . '/view/templates/backend/widgets/site-setup-assistant-float.phtml'
        );
        $provider = (string)file_get_contents(
            $smtpRoot . '/extends/module/Weline_SiteSetupAssistant/SetupTask/SmtpSetupTaskProvider.php'
        );
        $coverage = (string)file_get_contents($smtpRoot . '/Service/MailTemplateSetupCoverage.php');
        $smtpExtends = (string)file_get_contents($smtpRoot . '/extends.php');
        $command = (string)file_get_contents(
            dirname($smtpRoot, 4) . '/dev/ai-command/sitesetup/建站.md'
        );

        self::assertFileDoesNotExist(
            $smtpRoot . '/extends/module/Weline_SiteSetupAssistant/SetupTaskStatus/SmtpSetupTaskStatusProvider.php'
        );

        self::assertStringContainsString('interface SetupTaskProviderInterface', $interface);
        self::assertStringContainsString('abstract class AbstractSetupTaskProvider', $abstract);
        self::assertStringContainsString('class SetupTaskCollector', $collector);
        self::assertStringContainsString('SetupTaskCollector', $float);
        self::assertStringContainsString('class SmtpSetupTaskProvider', $provider);
        self::assertStringContainsString('extends AbstractSetupTaskProvider', $provider);
        self::assertStringContainsString('smtp_transport', $provider);
        self::assertStringContainsString('smtp_mail_template', $provider);
        self::assertStringContainsString('smtp_mail_template_i18n', $provider);
        self::assertStringContainsString('class MailTemplateSetupCoverage', $coverage);
        self::assertStringContainsString('SystemConfig::SCOPE_GLOBAL', $coverage);
        self::assertStringContainsString('localesByChannel', $coverage);
        self::assertStringContainsString('SetupTaskProviderInterface::class', $smtpExtends);
        self::assertStringContainsString('SmtpSetupTaskProvider::class', $smtpExtends);
        self::assertStringNotContainsString('SetupTaskStatusProviderInterface', $smtpExtends);
        self::assertStringContainsString('禁止', $command);
        self::assertStringContainsString('SetupTaskProviderInterface', $command);
        self::assertStringContainsString('AbstractSetupTaskProvider', $command);
    }
}
