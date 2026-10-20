<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllMailUnitContractsPass().
 */
final class MailUnitContractsTest extends TestCase
{
    public function testAllMailUnitContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'MailSiteSetupTask::testMailSetupTaskProviderRegistersViaExtends' => function () {
            $this->assert_MailSiteSetupTask_testMailSetupTaskProviderRegistersViaExtends();
        },
            'MailInboxMenuSignal::testUnreadMailPublishesMenuDeepLink' => function () {
            $this->assert_MailInboxMenuSignal_testUnreadMailPublishesMenuDeepLink();
        },
        ];
    }

private function assert_MailSiteSetupTask_testMailSetupTaskProviderRegistersViaExtends(): void
{
    $mailRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Mail';
    $provider = (string) file_get_contents($mailRoot . '/extends/module/Weline_SiteSetupAssistant/SetupTask/MailSetupTaskProvider.php');
    $extends = (string) file_get_contents($mailRoot . '/extends.php');
    self::assertStringContainsString('class MailSetupTaskProvider', $provider);
    self::assertStringContainsString('extends AbstractSetupTaskProvider', $provider);
    self::assertStringContainsString('mail_engine', $provider);
    self::assertStringContainsString('mail_domain', $provider);
    self::assertStringContainsString('mail_dns', $provider);
    self::assertStringContainsString('mail_account', $provider);
    self::assertStringContainsString('mail_smtp_handoff', $provider);
    self::assertStringContainsString('ensure_mail=1', $provider);
    self::assertStringContainsString('contact@', $provider);
    self::assertStringContainsString('MailSmtpBootstrapService', $provider);
    self::assertStringContainsString('can_bootstrap', $provider);
    self::assertStringContainsString('stalwart-mail-server', $provider);
    self::assertStringContainsString('环境修复', $provider);
    self::assertStringContainsString('勿手搓二进制', $provider);
    self::assertStringContainsString('SetupTaskProviderInterface::class', $extends);
    self::assertStringContainsString('MailSetupTaskProvider::class', $extends);
}

private function assert_MailInboxMenuSignal_testUnreadMailPublishesMenuDeepLink(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Mail';
    $provider = (string) file_get_contents($root . '/extends/module/Weline_Admin/BackendMenuSignalProvider/MailInboxMenuSignalProvider.php');
    $publisher = (string) file_get_contents($root . '/Service/Mailbox/MailMenuSignalPublisher.php');
    $controller = (string) file_get_contents($root . '/Controller/Backend/Index.php');
    $inbox = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Admin/Service/BackendMenuSignalInbox.php');
    self::assertStringContainsString('MailMenuSignalPublisher::MENU_SOURCE_ID', $provider);
    self::assertStringContainsString("'unread' => true", $publisher);
    self::assertStringContainsString("MENU_SOURCE_ID = 'Weline_Mail::mail'", $publisher);
    self::assertStringContainsString("getBackendUrl('weline_mail/backend'", $publisher);
    self::assertStringContainsString("'message' => \$messageId", $publisher);
    self::assertStringContainsString('publishLiveUnread', $publisher);
    self::assertStringContainsString('touchMailMenuSignal', $controller);
    self::assertStringContainsString('function markUnread', $inbox);
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Admin/view/statics/js/backend-menu-signals.js');
    self::assertStringNotContainsString('weline_mail/backend', $js);
}
}
