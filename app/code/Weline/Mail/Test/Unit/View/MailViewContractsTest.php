<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 5 *ContractTest files).
 * Observe one overall pass/fail via testAllMailViewContractsPass().
 */
final class MailViewContractsTest extends TestCase
{
    public function testAllMailViewContractsPass(): void
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
            'EnterpriseCloudflareOauthPost::testEnterpriseDomainsPanelPostsOauthConnect' => function () {
            $this->assert_EnterpriseCloudflareOauthPost_testEnterpriseDomainsPanelPostsOauthConnect();
        },
            'EnterpriseDkimAutoNoManualField::testEnterpriseHidesManualDkimPasteAndDocumentsAutoGenerate' => function () {
            $this->assert_EnterpriseDkimAutoNoManualField_testEnterpriseHidesManualDkimPasteAndDocumentsAutoGenerate();
        },
            'EnterpriseDnsChecklistMarks::testEnterpriseShowsLiveDnsMarksAndOauthError' => function () {
            $this->assert_EnterpriseDnsChecklistMarks_testEnterpriseShowsLiveDnsMarksAndOauthError();
        },
            'EnterpriseEngineSetupGuide::testEngineSetupGuidesFirewallPortsAndAdminLogin' => function () {
            $this->assert_EnterpriseEngineSetupGuide_testEngineSetupGuidesFirewallPortsAndAdminLogin();
        },
            'MailAccountMailClient::testMailClientExposesReplyAndAccountControls' => function () {
            $this->assert_MailAccountMailClient_testMailClientExposesReplyAndAccountControls();
        },
            'MailAccountMailClient::testSidebarKeepsSingleMailEntry' => function () {
            $this->assert_MailAccountMailClient_testSidebarKeepsSingleMailEntry();
        },
        ];
    }

private function assert_EnterpriseCloudflareOauthPost_testEnterpriseDomainsPanelPostsOauthConnect(): void
{
    $path = BP . 'app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("method=\"post\" action=\"@backend-url{'cdn/backend/oauth/connect'}\"", $src);
    self::assertStringContainsString('name="adapter" value="cloudflare"', $src);
    self::assertStringContainsString('name="return_route" value="weline_mail/backend"', $src);
    self::assertStringContainsString("__('连接或重新授权 Cloudflare')", $src);
    self::assertStringNotContainsString("@backend-url{'cdn/backend/oauth/connect'|\$cloudflareConnectParams}", $src, '禁止用 GET 链接触发 OAuth connect（仅 postConnect）');
    self::assertDoesNotMatchRegularExpression('/<a[^>]+href="@backend-url\{\'cdn\/backend\/oauth\/connect\'/', $src, '禁止 <a href> 指向 oauth/connect');
}

private function assert_EnterpriseDkimAutoNoManualField_testEnterpriseHidesManualDkimPasteAndDocumentsAutoGenerate(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml');
    self::assertStringNotContainsString('DKIM 公钥（只填公钥正文）', $src);
    self::assertStringContainsString('mail-dkim-auto-note', $src);
    self::assertStringContainsString('本机自动生成', $src);
    self::assertStringContainsString("name=\"dkim_public_key\" value=\"\"", $src);
    $index = (string) file_get_contents(BP . 'app/code/Weline/Mail/Controller/Backend/Index.php');
    self::assertStringContainsString('MailDkimLocalEnsureService', $index);
}

private function assert_EnterpriseDnsChecklistMarks_testEnterpriseShowsLiveDnsMarksAndOauthError(): void
{
    $path = BP . 'app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('dns_live_checks', $src);
    self::assertStringContainsString('detected_origin_ip', $src);
    self::assertStringContainsString('mail-enterprise__dns-mark', $src);
    self::assertStringContainsString('mail-enterprise__dns-guide', $src);
    self::assertStringContainsString('<details class="mail-enterprise__dns-item"', $src);
    self::assertStringContainsString('本机邮局 IP', $src);
    self::assertStringContainsString('本机邮局公钥待生成', $src);
    self::assertStringContainsString('cloudflare_token_ready', $src);
    self::assertStringContainsString('一键同步 DNS 到 Cloudflare', $src);
    self::assertStringContainsString('mail-cf-one-click-apply', $src);
    self::assertStringContainsString('data-dns-code', $src);
    self::assertStringContainsString('data-testid="mail-oauth-error"', $src);
    self::assertStringContainsString('invalid_scope', $src);
    self::assertStringContainsString('DNS 已通过', $src);
    self::assertStringNotContainsString('→ <?= $escape($originIp ?: __(\'源站公网 IP\')) ?>', $src);
    $index = (string) file_get_contents(BP . 'app/code/Weline/Mail/Controller/Backend/Index.php');
    self::assertStringContainsString('buildLiveDnsChecks', $index);
    self::assertStringContainsString('MailOriginIpResolver', $index);
    self::assertStringContainsString('isCloudflareDefaultTokenReady', $index);
    self::assertStringContainsString("oauth_error", $index);
}

private function assert_EnterpriseEngineSetupGuide_testEngineSetupGuidesFirewallPortsAndAdminLogin(): void
{
    $enterprise = (string) file_get_contents(BP . 'app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml');
    self::assertStringContainsString('data-testid="mail-enterprise-engine-setup"', $enterprise);
    self::assertStringContainsString('data-testid="mail-enterprise-firewall-ports"', $enterprise);
    self::assertStringContainsString('data-testid="mail-enterprise-firewall-ports-table"', $enterprise);
    self::assertStringContainsString('data-testid="mail-enterprise-admin-login"', $enterprise);
    self::assertStringContainsString('data-testid="mail-enterprise-acme-tls"', $enterprise);
    self::assertStringContainsString('域名池', $enterprise);
    self::assertStringContainsString('mail-smtp-domain-mode', $enterprise);
    self::assertStringContainsString('mail-smtp-pool-field', $enterprise);
    self::assertStringContainsString('mail-smtp-typed-field', $enterprise);
    self::assertStringContainsString('不必手开 Stalwart ACME', $enterprise);
    self::assertStringContainsString('云安全组 / 防火墙：先放行这些端口（必需）', $enterprise);
    self::assertStringContainsString('25/tcp', $enterprise);
    self::assertStringContainsString('587/tcp', $enterprise);
    self::assertStringContainsString('993/tcp', $enterprise);
    self::assertStringContainsString('Stalwart Admin（:8080）登录账户与初始密码', $enterprise);
    self::assertStringContainsString('journalctl -u stalwart', $enterprise);
    self::assertStringContainsString('<code>admin</code>', $enterprise);
    self::assertStringContainsString('http://127.0.0.1:8080/login', $enterprise);
    self::assertStringContainsString('ssh -L 8080:127.0.0.1:8080', $enterprise);
    $index = (string) file_get_contents(BP . 'app/code/Weline/Mail/view/templates/backend/Index/index.phtml');
    self::assertStringContainsString('data-testid="mail-index-firewall-ports"', $index);
    self::assertStringContainsString('data-testid="mail-index-admin-login"', $index);
    self::assertStringContainsString('Stalwart Admin（:8080）登录账户与初始密码', $index);
    self::assertStringContainsString('journalctl -u stalwart', $index);
    $readme = (string) file_get_contents(BP . 'app/code/Weline/Mail/doc/README.md');
    self::assertStringContainsString('云安全组 / 防火墙（入站放行，必需）', $readme);
    self::assertStringContainsString('Stalwart Admin（:8080）账户与初始密码', $readme);
    self::assertStringContainsString('journalctl -u stalwart', $readme);
}

private function assert_MailAccountMailClient_testMailClientExposesReplyAndAccountControls(): void
{
    $path = BP . 'app/code/Weline/Mail/view/templates/frontend/account/mail-client.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
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

private function assert_MailAccountMailClient_testSidebarKeepsSingleMailEntry(): void
{
    $nav = (string) file_get_contents(BP . 'app/code/Weline/Mail/view/hooks/account.sidebar.group.connections.phtml');
    $content = (string) file_get_contents(BP . 'app/code/Weline/Mail/view/hooks/account.sidebar.content.phtml');
    self::assertStringContainsString('getAccountsForCustomer', $nav);
    self::assertStringContainsString('getAccountsForCustomer', $content);
    self::assertStringContainsString("#mail", $nav);
    self::assertStringContainsString('我的邮箱', $nav);
    self::assertSame(1, substr_count($nav, 'data-section="mail"'));
    self::assertStringContainsString('mail-client.phtml', $content);
    self::assertStringNotContainsString('回复管理', $nav);
}
}
