<?php
declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class MailOutboundRelaySyncContractTest extends TestCase
{
    public function testSyncBuildsSenderRouteNotAuthenticatedAs(): void
    {
        $path = BP . '/app/code/Weline/Mail/Service/MailOutboundRelaySyncService.php';
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString("sender == '", $src);
        self::assertStringNotContainsString('authenticated_as', $src);
        self::assertStringContainsString('weline-outbound-acct-', $src);
        self::assertStringContainsString('is_local_domain', $src);
        self::assertStringContainsString('schema_fields_OUTBOUND_RELAY_HOST', $src);
    }

    public function testAccountHasOutboundHostColumns(): void
    {
        $account = (string) \file_get_contents(BP . '/app/code/Weline/Mail/Model/MailAccount.php');
        self::assertStringContainsString('outbound_relay_enabled', $account);
        self::assertStringContainsString('outbound_relay_host', $account);
        self::assertStringContainsString('outbound_relay_password_sealed', $account);
    }

    public function testEnterpriseOutboundOnAccountSmtpTabNotDomain(): void
    {
        $src = (string) \file_get_contents(
            BP . '/app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml'
        );
        self::assertStringContainsString('mail-account-outbound-', $src);
        self::assertStringContainsString('mail-smtp-create-self', $src);
        self::assertStringContainsString('mail-smtp-create-external', $src);
        self::assertStringContainsString("'smtp' => __('Smtp')", $src);
        self::assertStringNotContainsString('mail-domain-outbound-', $src);
        self::assertStringNotContainsString('set-default-smtp-account', $src);
    }
}
