<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 建站助手 SMTP 任务：探测自有/继承配置，未确认则为 doing。
 */
final class SmtpSiteSetupTaskContractTest extends TestCase
{
    public function testSmtpProvidesSetupTaskStatusViaExtends(): void
    {
        $smtpRoot = dirname(__DIR__, 2);
        $ssaRoot = dirname($smtpRoot) . '/SiteSetupAssistant';

        $interface = (string)file_get_contents(
            $ssaRoot . '/Api/SetupTaskStatusProviderInterface.php'
        );
        $collector = (string)file_get_contents(
            $ssaRoot . '/Service/SetupTaskStatusCollector.php'
        );
        $float = (string)file_get_contents(
            $ssaRoot . '/view/templates/Backend/widgets/site-setup-assistant-float.phtml'
        );
        $provider = (string)file_get_contents(
            $smtpRoot . '/extends/module/Weline_SiteSetupAssistant/SetupTaskStatus/SmtpSetupTaskStatusProvider.php'
        );
        $smtpExtends = (string)file_get_contents($smtpRoot . '/extends.php');
        $data = (string)file_get_contents($smtpRoot . '/Helper/Data.php');

        self::assertStringContainsString('interface SetupTaskStatusProviderInterface', $interface);
        self::assertStringContainsString('resolveTaskStatus', $interface);
        self::assertStringContainsString('class SetupTaskStatusCollector', $collector);
        self::assertStringContainsString('SetupTaskStatusCollector', $float);
        self::assertStringContainsString('class SmtpSetupTaskStatusProvider', $provider);
        self::assertStringContainsString('SetupTaskStatusProviderInterface::class', $smtpExtends);
        self::assertStringContainsString('smtp_setup_confirmed', $data);
        self::assertStringContainsString('isSetupConfirmed', $data);
        self::assertStringContainsString('markSetupConfirmed', $data);
        self::assertStringContainsString('resolveSendersProvenance', $provider);
        self::assertStringContainsString('inherited', $provider);
    }
}
