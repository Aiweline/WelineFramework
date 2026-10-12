<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\View;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class EnterpriseEngineSetupGuideContractTest extends TestCase
{
    public function testEngineSetupGuidesFirewallPortsAndAdminLogin(): void
    {
        $enterprise = (string)file_get_contents(BP . 'app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml');
        self::assertStringContainsString('data-testid="mail-enterprise-engine-setup"', $enterprise);
        self::assertStringContainsString('data-testid="mail-enterprise-firewall-ports"', $enterprise);
        self::assertStringContainsString('data-testid="mail-enterprise-firewall-ports-table"', $enterprise);
        self::assertStringContainsString('data-testid="mail-enterprise-admin-login"', $enterprise);
        self::assertStringContainsString('云安全组 / 防火墙：先放行这些端口（必需）', $enterprise);
        self::assertStringContainsString('25/tcp', $enterprise);
        self::assertStringContainsString('587/tcp', $enterprise);
        self::assertStringContainsString('993/tcp', $enterprise);
        self::assertStringContainsString('Stalwart Admin（:8080）登录账户与初始密码', $enterprise);
        self::assertStringContainsString('journalctl -u stalwart', $enterprise);
        self::assertStringContainsString('<code>admin</code>', $enterprise);
        self::assertStringContainsString('http://127.0.0.1:8080/login', $enterprise);
        self::assertStringContainsString('ssh -L 8080:127.0.0.1:8080', $enterprise);

        $index = (string)file_get_contents(BP . 'app/code/Weline/Mail/view/templates/backend/Index/index.phtml');
        self::assertStringContainsString('data-testid="mail-index-firewall-ports"', $index);
        self::assertStringContainsString('data-testid="mail-index-admin-login"', $index);
        self::assertStringContainsString('Stalwart Admin（:8080）登录账户与初始密码', $index);
        self::assertStringContainsString('journalctl -u stalwart', $index);

        $readme = (string)file_get_contents(BP . 'app/code/Weline/Mail/doc/README.md');
        self::assertStringContainsString('云安全组 / 防火墙（入站放行，必需）', $readme);
        self::assertStringContainsString('Stalwart Admin（:8080）账户与初始密码', $readme);
        self::assertStringContainsString('journalctl -u stalwart', $readme);
    }
}
