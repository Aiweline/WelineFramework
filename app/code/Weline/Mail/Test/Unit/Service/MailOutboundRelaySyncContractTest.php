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
        self::assertStringContainsString('weline-outbound-', $src);
        self::assertStringContainsString('is_local_domain', $src);
    }

    public function testDomainAndAccountHaveOutboundColumns(): void
    {
        $domain = (string) \file_get_contents(BP . '/app/code/Weline/Mail/Model/MailDomain.php');
        $account = (string) \file_get_contents(BP . '/app/code/Weline/Mail/Model/MailAccount.php');
        self::assertStringContainsString('outbound_relay_host', $domain);
        self::assertStringContainsString('outbound_relay_enabled', $account);
    }

    public function testEnterpriseHasOutboundUiNotDefaultSmtpBind(): void
    {
        $src = (string) \file_get_contents(
            BP . '/app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml'
        );
        self::assertStringContainsString('mail-domain-outbound-', $src);
        self::assertStringContainsString('save-outbound-relay', $src);
        self::assertStringNotContainsString('set-default-smtp-account', $src);
    }
}
