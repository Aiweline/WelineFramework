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
            'EnterpriseHtmlReaderPreview::testReaderUsesSandboxedHtmlIframeWithoutScripts' => function () {
            $this->assert_EnterpriseHtmlReaderPreview_testReaderUsesSandboxedHtmlIframeWithoutScripts();
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
    self::assertStringContainsString('configure-cloudflare-dns-stream', $src);
    self::assertStringContainsString('data-mail-cf-dns-sse', $src);
    self::assertStringContainsString('mailCfDnsConsumeSse', $src);
    self::assertStringContainsString('text/event-stream', $src);
    self::assertStringContainsString('data-mail-domain-delete', $src);
    self::assertStringContainsString('mail-domain-delete-', $src);
    self::assertStringContainsString('deleteMailboxDomain', $src);
    self::assertStringContainsString('网站域名本身不会被删除', $src);
    self::assertStringContainsString('不删网站域名', $src);
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
    self::assertStringContainsString('自动导入 Stalwart', $enterprise);
    self::assertStringContainsString('mail-smtp-domain-mode', $enterprise);
    self::assertStringContainsString('mail-smtp-pool-field', $enterprise);
    self::assertStringContainsString('mail-smtp-typed-field', $enterprise);
    self::assertStringContainsString('mail-smtp-new-toggle', $enterprise);
    self::assertStringContainsString('mail-smtp-service-search', $enterprise);
    self::assertStringContainsString('mail-smtp-create-panel', $enterprise);
    self::assertStringContainsString('mail-smtp-service-card', $enterprise);
    self::assertStringContainsString('mail-smtp-service-toggle-', $enterprise);
    self::assertStringContainsString('data-smtp-card-actions', $enterprise);
    self::assertStringContainsString('展开或收起 Smtp 服务配置', $enterprise);
    self::assertStringContainsString('.mail-smtp-service-card:not([open]) > .w-card__body', $enterprise);
    self::assertStringContainsString('mail-smtp-mode-badge', $enterprise);
    self::assertStringContainsString('mail-smtp-health-badge', $enterprise);
    self::assertStringContainsString('data-is-local', $enterprise);
    self::assertStringContainsString("health_applicable", $enterprise);
    self::assertStringContainsString("is_local", $enterprise);
    self::assertStringContainsString('mail-smtp-health-alert-', $enterprise);
    self::assertStringContainsString('mail-smtp-service-card__signals', $enterprise);
    self::assertStringContainsString("smtp_local_health_by_service", $enterprise);
    self::assertStringContainsString("__('本机登记')", $enterprise);
    self::assertStringContainsString("__('健康未校验')", $enterprise);
    self::assertStringContainsString("__('已启用')", $enterprise);
    self::assertStringContainsString("__('已停用')", $enterprise);
    self::assertStringContainsString("MODE_SELF ? 'info' : 'primary'", $enterprise);
    self::assertStringNotContainsString("MODE_SELF ? 'success'", $enterprise);
    self::assertStringNotContainsString("MODE_SELF ? __('本机启用')", $enterprise);
    // UA/[hidden] 允许 display:none !important；其它业务视觉禁止
    $enterpriseSansHiddenImportant = (string) preg_replace(
        '/\[hidden\]\s*\{[^}]*!important[^}]*\}/',
        '',
        $enterprise
    );
    self::assertStringNotContainsString('!important', $enterpriseSansHiddenImportant);
    self::assertStringContainsString('模式标「本机登记」', $enterprise);
    self::assertStringContainsString('蓝标「外部填入」', $enterprise);
    self::assertStringNotContainsString('绿标「本机启用」', $enterprise);
    self::assertStringContainsString('自动创建系统账号 smtp@根域', $enterprise);
    self::assertStringContainsString('data-smtp-system-account', $enterprise);
    self::assertStringContainsString('mail-smtp-system-badge', $enterprise);
    self::assertStringContainsString('不可删除 / 不可改密', $enterprise);
    self::assertStringContainsString('重置系统代理账户', $enterprise);
    self::assertStringContainsString('reset-smtp-system-accounts', $enterprise);
    self::assertStringContainsString('mail-smtp-reset-system-accounts', $enterprise);
    self::assertStringNotContainsString('reveal-smtp-service-account-password', $enterprise);
    self::assertStringContainsString('reset-smtp-service-account-password', $enterprise);
    self::assertStringContainsString('update-smtp-service-account', $enterprise);
    self::assertStringContainsString('smtp-service-account-delete-stream', $enterprise);
    self::assertStringContainsString('smtp-service-delete-stream', $enterprise);
    self::assertStringContainsString('data-smtp-delete-sse', $enterprise);
    self::assertStringContainsString('mail-smtp-delete-account-', $enterprise);
    self::assertStringContainsString('mail-smtp-delete-service-form-', $enterprise);
    self::assertStringContainsString('mailSmtpBindDeleteSse', $enterprise);
    self::assertStringContainsString('从数据库同步到本机引擎', $enterprise);
    self::assertStringContainsString('mail-sync-engine-accounts-from-db', $enterprise);
    self::assertStringContainsString('sync-engine-accounts-from-db-stream', $enterprise);
    self::assertStringContainsString('data-mail-sync-engine-from-db-sse', $enterprise);
    self::assertStringContainsString('password_once', $enterprise);
    self::assertStringContainsString('一键配置邮局', $enterprise);
    self::assertStringContainsString('一键配置本域', $enterprise);
    self::assertStringContainsString('mail-one-click-setup-global', $enterprise);
    self::assertStringContainsString('mail-one-click-setup-domain-', $enterprise);
    self::assertStringContainsString('one-click-mail-setup-stream', $enterprise);
    self::assertStringContainsString('one-click-domain-setup-stream', $enterprise);
    self::assertStringContainsString('data-mail-one-click-setup-sse', $enterprise);
    self::assertStringContainsString('domainOneClickNeeded', $enterprise);
    self::assertStringContainsString('mailOneClickBind', $enterprise);
    self::assertStringContainsString('配置中…', $enterprise);
    self::assertStringContainsString('配置失败', $enterprise);
    self::assertStringContainsString('下属全部 %{1} 个账户', $enterprise);
    self::assertStringContainsString('将同时从本机 Stalwart 移除这些账户', $enterprise);
    self::assertStringContainsString('mail-account-filter', $enterprise);
    self::assertStringContainsString('mail-account-filter-q', $enterprise);
    self::assertStringContainsString('mail-account-batch-delete', $enterprise);
    self::assertStringContainsString('mail-account-create-open', $enterprise);
    self::assertStringContainsString('mail-account-create-dialog', $enterprise);
    self::assertStringContainsString('mail-account-system-hint', $enterprise);
    self::assertStringContainsString('mail-account-system-badge-', $enterprise);
    self::assertStringContainsString('data-mail-system-account', $enterprise);
    self::assertStringContainsString('不可删改', $enterprise);
    self::assertStringContainsString('data-account-edit', $enterprise);
    self::assertStringContainsString('data-account-delete', $enterprise);
    self::assertStringContainsString('updateMailboxAccount', $enterprise);
    self::assertStringContainsString('setMailboxAccountStatus', $enterprise);
    self::assertStringContainsString('batchDeleteMailboxAccounts', $enterprise);
    self::assertStringNotContainsString("weline_mail/backend/index/set-account-status", $enterprise);
    self::assertStringContainsString('account_filter_q', (string) file_get_contents(BP . 'app/code/Weline/Mail/Controller/Backend/Index.php'));
    self::assertStringContainsString('listAccounts', (string) file_get_contents(BP . 'app/code/Weline/Mail/Controller/Backend/Index.php'));
    self::assertStringContainsString('mail-smtp-edit-account-', $enterprise);
    self::assertStringContainsString('增加本机账户', $enterprise);
    self::assertStringContainsString('sync-smtp-service-account-to-stalwart', $enterprise);
    self::assertStringContainsString('mail-smtp-sync-stalwart-', $enterprise);
    self::assertStringContainsString('写入本机 Stalwart', $enterprise);
    self::assertStringContainsString('从 Stalwart 同步账号', $enterprise);
    self::assertStringContainsString('import-smtp-accounts-from-stalwart', $enterprise);
    self::assertStringContainsString('import-smtp-services-from-mail-domains', $enterprise);
    self::assertStringContainsString('mail-smtp-sync-from-mail-domains', $enterprise);
    self::assertStringContainsString('从邮件服务同步', $enterprise);
    self::assertStringContainsString('mail-smtp-stalwart-status-', $enterprise);
    self::assertStringContainsString('已写入', $enterprise);
    self::assertStringContainsString('未写入', $enterprise);
    $indexCtrl = (string) file_get_contents(BP . 'app/code/Weline/Mail/Controller/Backend/Index.php');
    self::assertStringContainsString('postResetSmtpSystemAccounts', $indexCtrl);
    self::assertStringContainsString('repairForService', $indexCtrl);
    self::assertStringContainsString('smtp_system_completeness_by_service', $indexCtrl);
    self::assertStringContainsString('postSyncSmtpServiceAccountToStalwart', $indexCtrl);
    self::assertStringContainsString('postImportSmtpAccountsFromStalwart', $indexCtrl);
    self::assertStringContainsString('postImportSmtpServicesFromMailDomains', $indexCtrl);
    self::assertStringContainsString('syncAccountToStalwart', $indexCtrl);
    self::assertStringContainsString('importAccountsFromStalwart', $indexCtrl);
    self::assertStringContainsString('importSelfServicesFromMailDomains', $indexCtrl);
    self::assertStringContainsString('probeStalwartPresenceForService', $indexCtrl);
    self::assertStringContainsString('smtp_stalwart_by_service', $indexCtrl);
    self::assertStringContainsString('SmtpLocalDomainHealthService', $indexCtrl);
    self::assertStringContainsString("assign('smtp_local_health_by_service'", $indexCtrl);
    self::assertStringContainsString('summarizeForServices', $indexCtrl);
    self::assertStringContainsString('smtp_can_view_passwords', (string) file_get_contents(BP . 'app/code/Weline/Mail/Controller/Backend/Index.php'));
    self::assertStringContainsString('mail_accounts_password', (string) file_get_contents(BP . 'app/code/Weline/Mail/Controller/Backend/Index.php'));
    self::assertStringContainsString('mail-smtp-password-cell-', $enterprise);
    self::assertStringContainsString('data-mail-smtp-copy', $enterprise);
    self::assertStringContainsString('复制账号', $enterprise);
    self::assertStringContainsString('复制密码', $enterprise);
    self::assertStringContainsString('data-mail-async-row', $enterprise);
    self::assertStringContainsString('mailbox-message', $enterprise);
    self::assertStringContainsString("\$this->getBackendUrl('weline_mail/backend/index/mailbox-message')", $enterprise);
    self::assertStringContainsString('mailbox-batch', $enterprise);
    self::assertStringContainsString('batchBusy', $enterprise);
    self::assertStringContainsString('batchLoadingMessage', $enterprise);
    self::assertStringContainsString('正在删除邮件，请稍候…', $enterprise);
    self::assertStringContainsString('loading.with', $enterprise);
    self::assertStringContainsString('syncSelectAllState', $enterprise);
    self::assertStringContainsString('setBatchControlsDisabled', $enterprise);
    self::assertStringContainsString('mailbox-search', $enterprise);
    self::assertStringContainsString('mailbox-list', $enterprise);
    self::assertStringContainsString('mailbox-compose-prefill', $enterprise);
    self::assertStringContainsString('data-testid="mail-enterprise-list-toolbar"', $enterprise);
    self::assertStringContainsString('data-testid="mail-enterprise-live-status"', $enterprise);
    self::assertStringContainsString('refreshMailboxList', $enterprise);
    self::assertStringContainsString('POLL_MS', $enterprise);
    self::assertStringContainsString("refreshMailboxList({ force: true", $enterprise);
    self::assertDoesNotMatchRegularExpression(
        '/\[data-mail-refresh\][\s\S]{0,180}window\.location\.reload\(\)/',
        $enterprise
    );
    self::assertStringContainsString('mail-enterprise__row-main', $enterprise);
    self::assertStringContainsString('data-testid="mail-enterprise-fake-mailbox-banner"', $enterprise);
    self::assertStringContainsString('data-testid="mail-enterprise-switch-real-account"', $enterprise);
    self::assertStringContainsString('preferred_real_account_id', $enterprise);
    self::assertStringContainsString('data-testid="mail-enterprise-fake-compose-banner"', $enterprise);
    self::assertStringContainsString('selected_mailbox_is_fake', $enterprise);
    self::assertStringContainsString('data-testid="mail-enterprise-reader-actions"', $enterprise);
    $indexCtrlMailbox = (string) file_get_contents(BP . 'app/code/Weline/Mail/Controller/Backend/Index.php');
    self::assertStringContainsString("assign('selected_mailbox_is_fake'", $indexCtrlMailbox);
    self::assertStringContainsString('orderMailboxAccountsPreferReal', $indexCtrlMailbox);
    self::assertStringContainsString('preferred_real_account_id', $indexCtrlMailbox);
    self::assertStringContainsString("'trash' => __('废纸篓')", $enterprise);
    self::assertStringNotContainsString('$this->_url->getBackendUrl', $enterprise);
    // 绕过 Backend fetch worker MAX_IN_FLIGHT=1（连点会报 too many in-flight）。
    self::assertStringContainsString('AbortController', $enterprise);
    self::assertStringContainsString('__welineNativeFetch', $enterprise);
    self::assertStringContainsString('AbortError', $enterprise);
    $indexCtrl = (string) file_get_contents(BP . 'app/code/Weline/Mail/Controller/Backend/Index.php');
    self::assertStringContainsString('function getMailboxMessage', $indexCtrl);
    self::assertStringContainsString('function postMailboxBatch', $indexCtrl);
    self::assertStringContainsString('function getMailboxSearch', $indexCtrl);
    self::assertStringContainsString('function getMailboxList', $indexCtrl);
    self::assertStringContainsString('function getMailboxComposePrefill', $indexCtrl);
    self::assertStringContainsString('MailboxOpsService', $indexCtrl);
    self::assertStringNotContainsString('StalwartEngineAdapter::class', $indexCtrl);
    self::assertStringNotContainsString('listMailboxMessages', $indexCtrl);
    self::assertStringContainsString('PostResponseTaskQueue::enqueue', $indexCtrl);
    self::assertStringContainsString('mail-outbound-relay-sync', $indexCtrl);
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

private function assert_EnterpriseHtmlReaderPreview_testReaderUsesSandboxedHtmlIframeWithoutScripts(): void
{
    $enterprise = (string) file_get_contents(BP . 'app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml');
    self::assertStringContainsString('data-testid="mail-enterprise-reader-html"', $enterprise);
    self::assertStringContainsString('sandbox=""', $enterprise);
    self::assertStringContainsString('srcdoc=', $enterprise);
    self::assertStringContainsString('body_is_html', $enterprise);
    self::assertStringContainsString('html_body', $enterprise);
    self::assertStringContainsString('mail-enterprise__unread-dot', $enterprise);
    self::assertStringContainsString('mail-enterprise__party', $enterprise);
    self::assertStringContainsString('formatMailListTime', $enterprise);
    self::assertStringContainsString('looksLikeHtml', $enterprise);
    self::assertStringContainsString('markSeenQuiet', $enterprise);
    self::assertStringContainsString('syncMenuAfterRead', $enterprise);
    self::assertStringContainsString('syncMenuAfterUnread', $enterprise);
    self::assertStringContainsString('noteViewed', $enterprise);
    self::assertStringContainsString('releaseViewed', $enterprise);
    self::assertStringContainsString('weline_mail_compose_prefill_v1', $enterprise);
    self::assertStringContainsString('show=unread', $enterprise);
    self::assertStringContainsString('请先选择一封邮件再回复', $enterprise);
    self::assertStringContainsString('mail-enterprise-show-all', $enterprise);
    self::assertStringContainsString('mailbox_list_unread_only', $enterprise);
    self::assertStringContainsString('data-mail-unread-only', $enterprise);
    self::assertStringContainsString('renderReaderBody', $enterprise);
    self::assertStringContainsString('data-testid="mail-enterprise-inline-compose"', $enterprise);
    self::assertStringContainsString('data-mail-inline-actions', $enterprise);
    self::assertStringContainsString('resolveInlineQuoteText', $enterprise);
    self::assertStringContainsString('data-mail-reader-bound', $enterprise);
    // 发送/舍弃在回复顶栏右上，禁止底栏再放一套。
    self::assertMatchesRegularExpression(
        '/mail-enterprise__inline-compose-bar[\s\S]*?data-mail-inline-send[\s\S]*?data-mail-inline-quote/s',
        $enterprise,
        'inline send must sit in compose bar before quote'
    );
    self::assertStringContainsString('openInlineCompose', $enterprise);
    self::assertStringContainsString('sendInlineCompose', $enterprise);
    self::assertStringContainsString('sendComposerMessage', $enterprise);
    self::assertStringNotContainsString('allow-scripts', $enterprise);
    self::assertStringContainsString('data-mail-body-format="html"', $enterprise);
    $session = (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/Mailbox/StalwartJmapSession.php');
    self::assertStringContainsString('MailBodyPresentation::fromJmapMessage', $session);
    self::assertStringContainsString("'body_is_html'", $session);
    $helper = (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/Mailbox/MailBodyPresentation.php');
    self::assertStringContainsString('sanitizeHtml', $helper);
    self::assertStringContainsString('looksLikeHtml($text)', $helper);
    self::assertStringContainsString('gmailQuotePlain', $helper);
    self::assertStringContainsString('plainThreadToGmailHtml', $helper);
    self::assertStringContainsString('mail-thread', $helper);
    self::assertStringContainsString('THREAD_SEP', $helper);
    self::assertStringContainsString('mail-enterprise__reader-scroll', $enterprise);
    $qqHtml = "<html><head><meta http-equiv='Content-Type' content='text/html; charset=UTF-8'></head><body><p>hi</p></body></html>";
    $presented = \Weline\Mail\Service\Mailbox\MailBodyPresentation::fromJmapMessage([
        'textBody' => [['partId' => '1']],
        'htmlBody' => [],
        'bodyValues' => ['1' => ['value' => $qqHtml]],
        'preview' => '',
    ], true);
    self::assertTrue($presented['body_is_html']);
    self::assertNotSame('', $presented['html_body']);
    self::assertStringNotContainsString('<html>', $presented['body']);
    self::assertStringContainsString('looksLikeHtml', $helper);
    self::assertStringContainsString('(script|iframe|object|embed|link|meta|base)', $helper);
    self::assertStringContainsString('javascript', $helper);
    $messy = "知道了知道了\n---------- 原始邮件 ----------\n发件人: a@b.com\n日期: 2026-10-19T06:32:10Z\n主题: Re:test\n\n好吧，这是测试";
    $threadHtml = \Weline\Mail\Service\Mailbox\MailBodyPresentation::plainThreadToGmailHtml($messy);
    self::assertStringContainsString('mail-thread', $threadHtml);
    self::assertStringContainsString('mail-thread__sep', $threadHtml);
    self::assertStringContainsString('知道了知道了', $threadHtml);
    self::assertStringContainsString('引用', $threadHtml);
    self::assertStringNotContainsString('<br><br><br>', $threadHtml);
    $qqSparse = "好吧\n\n\n\nFrom\n\n\n<a@b.com>\n\n\nDate\n\n\n10/19/2026 14:31\n\n\nSubject\n\n\nt\n\n\nbody";
    $qqHtml = \Weline\Mail\Service\Mailbox\MailBodyPresentation::plainThreadToGmailHtml(
        "top\n---------- Replied Message ----------\n" . $qqSparse
    );
    self::assertStringContainsString('mail-thread__sep', $qqHtml);
    self::assertStringContainsString('a@b.com', $qqHtml);
    self::assertStringNotContainsString('From<br>', $qqHtml);
    $quote = \Weline\Mail\Service\Mailbox\MailBodyPresentation::gmailQuotePlain([
        'from' => 'a@b.com',
        'received_at' => '2026-10-19T06:32:10Z',
        'body' => "hello\nworld",
    ]);
    self::assertStringContainsString('引用', $quote);
    self::assertStringContainsString('> hello', $quote);
    self::assertStringContainsString('> world', $quote);
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
