<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Mail\Service\DnsRecordAdvisor;
use Weline\Mail\Service\MailDkimLocalEnsureService;
use Weline\Mail\Service\MailOriginIpResolver;

/**
 * Aggregated contract suite (merged from 7 *ContractTest files).
 * Observe one overall pass/fail via testAllMailServiceContractsPass().
 */
final class MailServiceContractsTest extends TestCase
{
    public function testAllMailServiceContractsPass(): void
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
            'DnsRecordAdvisor::testExpectedRecordsCoverMailChecklistCodes' => function () {
            $this->assert_DnsRecordAdvisor_testExpectedRecordsCoverMailChecklistCodes();
        },
            'DnsRecordAdvisor::testAdvisorSourceValidatesTxtContentNotAnyTxt' => function () {
            $this->assert_DnsRecordAdvisor_testAdvisorSourceValidatesTxtContentNotAnyTxt();
        },
            'MailAccountEnsureDefaultSmtp::testEnsureServiceDefinesSmtpDefaultAndPersistApis' => function () {
            $this->assert_MailAccountEnsureDefaultSmtp_testEnsureServiceDefinesSmtpDefaultAndPersistApis();
        },
            'MailAccountEnsureDefaultSmtp::testMailDomainHasDefaultSmtpAccountIdColumn' => function () {
            $this->assert_MailAccountEnsureDefaultSmtp_testMailDomainHasDefaultSmtpAccountIdColumn();
        },
            'MailAccountEnsureDefaultSmtp::testQueryExposesDefaultSmtpOps' => function () {
            $this->assert_MailAccountEnsureDefaultSmtp_testQueryExposesDefaultSmtpOps();
        },
            'MailAccountEnsureDefaultSmtp::testClientSettingsPreferSmtpSubdomain' => function () {
            $this->assert_MailAccountEnsureDefaultSmtp_testClientSettingsPreferSmtpSubdomain();
        },
            'MailComposeDeeplink::testSmtpServiceExposesLocalMailboxApis' => function () {
            $this->assert_MailComposeDeeplink_testSmtpServiceExposesLocalMailboxApis();
        },
            'MailComposeDeeplink::testQueryProviderExposesResolveAndList' => function () {
            $this->assert_MailComposeDeeplink_testQueryProviderExposesResolveAndList();
        },
            'MailComposeDeeplink::testBackendComposePrefillAndSendEvent' => function () {
            $this->assert_MailComposeDeeplink_testBackendComposePrefillAndSendEvent();
        },
            'MailComposeDeeplink::testModuleVersion' => function () {
            $this->assert_MailComposeDeeplink_testModuleVersion();
        },
            'MailDkimLocalEnsureService::testEnsureGeneratesReusablePublicKey' => function () {
            $this->assert_MailDkimLocalEnsureService_testEnsureGeneratesReusablePublicKey();
        },
            'MailFrontendRegisterLogin::testCustomerRegisterExposesExtrasSlot' => function () {
            $this->assert_MailFrontendRegisterLogin_testCustomerRegisterExposesExtrasSlot();
        },
            'MailFrontendRegisterLogin::testCustomerLoginHasNoDedicatedMailLogin' => function () {
            $this->assert_MailFrontendRegisterLogin_testCustomerLoginHasNoDedicatedMailLogin();
        },
            'MailFrontendRegisterLogin::testWidgetDefaultInjectionsRegisterOnly' => function () {
            $this->assert_MailFrontendRegisterLogin_testWidgetDefaultInjectionsRegisterOnly();
        },
            'MailFrontendRegisterLogin::testFeatureConfigKeysAndDefaults' => function () {
            $this->assert_MailFrontendRegisterLogin_testFeatureConfigKeysAndDefaults();
        },
            'MailFrontendRegisterLogin::testRegisterWidgetGatesOnConfig' => function () {
            $this->assert_MailFrontendRegisterLogin_testRegisterWidgetGatesOnConfig();
        },
            'MailFrontendRegisterLogin::testCacheInvalidatorObservesResourceChanged' => function () {
            $this->assert_MailFrontendRegisterLogin_testCacheInvalidatorObservesResourceChanged();
        },
            'MailOriginIpResolver::testStoredIpv4Wins' => function () {
            $this->assert_MailOriginIpResolver_testStoredIpv4Wins();
        },
            'MailOriginIpResolver::testRejectsNonIpStoredViaFilter' => function () {
            $this->assert_MailOriginIpResolver_testRejectsNonIpStoredViaFilter();
        },
            'MailOriginIpResolver::testResolverSourcePrefersEnvThenServerIpService' => function () {
            $this->assert_MailOriginIpResolver_testResolverSourcePrefersEnvThenServerIpService();
        },
            'MailOutboundRelaySync::testSyncBuildsSenderRouteNotAuthenticatedAs' => function () {
            $this->assert_MailOutboundRelaySync_testSyncBuildsSenderRouteNotAuthenticatedAs();
        },
            'MailOutboundRelaySync::testAccountHasOutboundHostColumns' => function () {
            $this->assert_MailOutboundRelaySync_testAccountHasOutboundHostColumns();
        },
            'MailOutboundRelaySync::testEnterpriseOutboundOnAccountSmtpTabNotDomain' => function () {
            $this->assert_MailOutboundRelaySync_testEnterpriseOutboundOnAccountSmtpTabNotDomain();
        },
        ];
    }

private function assert_DnsRecordAdvisor_testExpectedRecordsCoverMailChecklistCodes(): void
{
    $advisor = new DnsRecordAdvisor();
    $rows = $advisor->expectedRecords('example.com', 'mail.example.com', 'default');
    $codes = array_column($rows, 'code');
    self::assertSame(['a', 'mx', 'spf', 'dkim', 'dmarc', 'smtp_cname'], $codes);
    self::assertFalse((bool) ($rows[5]['required'] ?? true));
}

private function assert_DnsRecordAdvisor_testAdvisorSourceValidatesTxtContentNotAnyTxt(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/DnsRecordAdvisor.php');
    self::assertStringContainsString("probeTxtContains(\$host, 'v=spf1')", $src);
    self::assertStringContainsString("probeTxtContains(\$host, 'v=DMARC1')", $src);
    self::assertStringContainsString("probeTxtContains(\$host, 'p=')", $src);
    self::assertStringContainsString('dig +short', $src);
    self::assertStringContainsString('@1.1.1.1', $src);
    self::assertStringNotContainsString('return is_array($records) && !empty($records);', $src);
}

private function assert_MailAccountEnsureDefaultSmtp_testEnsureServiceDefinesSmtpDefaultAndPersistApis(): void
{
    $path = BP . '/app/code/Weline/Mail/Service/MailAccountEnsureService.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString("LOCAL_SMTP_DEFAULT = 'smtp'", $src);
    self::assertStringContainsString('function ensureDefaultSmtpAccount', $src);
    self::assertStringContainsString('function setDefaultSmtpAccount', $src);
    self::assertStringContainsString('DEFAULT_SMTP_ACCOUNT_ID', $src);
    self::assertStringContainsString('SMTP 提交', $src);
}

private function assert_MailAccountEnsureDefaultSmtp_testMailDomainHasDefaultSmtpAccountIdColumn(): void
{
    $path = BP . '/app/code/Weline/Mail/Model/MailDomain.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('default_smtp_account_id', $src);
    self::assertStringContainsString('schema_fields_DEFAULT_SMTP_ACCOUNT_ID', $src);
}

private function assert_MailAccountEnsureDefaultSmtp_testQueryExposesDefaultSmtpOps(): void
{
    $path = BP . '/app/code/Weline/Mail/extends/module/Weline_Framework/Query/MailQueryProvider.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString("'ensureDefaultSmtpAccount'", $src);
    self::assertStringContainsString("'setDefaultSmtpAccount'", $src);
}

private function assert_MailAccountEnsureDefaultSmtp_testClientSettingsPreferSmtpSubdomain(): void
{
    $path = BP . '/app/code/Weline/Mail/Service/StalwartEngineAdapter.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString("'smtp.'", $src);
    self::assertStringContainsString('$smtpHost', $src);
}

private function assert_MailComposeDeeplink_testSmtpServiceExposesLocalMailboxApis(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/MailSmtpAccountService.php');
    self::assertStringContainsString('function resolveLocalMailboxByEmail', $src);
    self::assertStringContainsString('function listLocalMailboxes', $src);
    self::assertStringContainsString('function toPublicMailbox', $src);
}

private function assert_MailComposeDeeplink_testQueryProviderExposesResolveAndList(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Mail/extends/module/Weline_Framework/Query/MailQueryProvider.php');
    self::assertStringContainsString('resolveLocalMailboxByEmail', $src);
    self::assertStringContainsString('listLocalMailboxes', $src);
    self::assertStringContainsString('listThreadBySource', $src);
    self::assertStringContainsString('sendComposerMessage', $src);
    $sendPos = strpos($src, "'name' => 'sendComposerMessage'");
    self::assertNotFalse($sendPos);
    $sendSlice = substr($src, $sendPos, 700);
    self::assertStringContainsString("'frontend' => true", $sendSlice);
    self::assertStringContainsString("'mode' => 'write'", $sendSlice);
    self::assertStringContainsString("'auth' => 'backend'", $sendSlice);
    self::assertStringContainsString('Weline_Mail::mail_send_as', $sendSlice);
}

private function assert_MailComposeDeeplink_testBackendComposePrefillAndSendEvent(): void
{
    $controller = (string) file_get_contents(BP . 'app/code/Weline/Mail/Controller/Backend/Index.php');
    self::assertStringContainsString('resolveComposeContext', $controller);
    self::assertStringContainsString('canPickAnyLocalMailbox', $controller);
    self::assertStringContainsString('MailComposerService', $controller);
    self::assertStringContainsString('resolveSendAsAccountId', $controller);
    $tpl = (string) file_get_contents(BP . 'app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml');
    self::assertStringContainsString('compose_open', $tpl);
    self::assertStringContainsString('compose_source', $tpl);
    self::assertStringContainsString('name="source"', $tpl);
    self::assertStringContainsString('mail-enterprise-compose-cancel', $tpl);
    self::assertStringContainsString('composeCloseParams', $tpl);
    $mailboxStart = strpos($tpl, "if (\$view === 'mailbox')");
    $accountsStart = strpos($tpl, "elseif (\$view === 'accounts')");
    self::assertNotFalse($mailboxStart);
    self::assertNotFalse($accountsStart);
    self::assertGreaterThan($mailboxStart, $accountsStart);
    $mailboxTpl = substr($tpl, $mailboxStart, $accountsStart - $mailboxStart);
    self::assertStringNotContainsString('<details', $mailboxTpl);
    self::assertStringNotContainsString('<summary', $mailboxTpl);
    self::assertStringContainsString('if ($composeOpen)', $mailboxTpl);
    self::assertStringContainsString('id="mail-compose"', $mailboxTpl);
    self::assertStringContainsString('w:customer:admin:select', $mailboxTpl);
    self::assertStringContainsString('mail-compose-customer', $mailboxTpl);
    self::assertStringContainsString('mail-enterprise-compose-to', $mailboxTpl);
    self::assertStringContainsString('name="to_email"', $mailboxTpl);
    self::assertStringContainsString('mail-enterprise-compose-form', $mailboxTpl);
    self::assertStringContainsString('mail-enterprise-compose-send', $mailboxTpl);
    self::assertStringContainsString('sendComposerMessage', $mailboxTpl);
    self::assertStringContainsString('UI.loading', $mailboxTpl);
    self::assertStringContainsString('toast', $mailboxTpl);
    self::assertStringNotContainsString('展开写信', $mailboxTpl);
    self::assertStringNotContainsString('收起写信', $mailboxTpl);
    self::assertStringContainsString('resolveComposeCustomerPick', $controller);
    self::assertStringContainsString('compose_customer_select_value', $controller);
    $eventsSrc = (string) file_get_contents(BP . 'app/code/Weline/Mail/event.php');
    self::assertStringContainsString('Weline_Mail::mail_message_sent', $eventsSrc);
    self::assertFileExists(BP . 'app/code/Weline/Mail/doc/event/mail_message_sent.md');
}

private function assert_MailComposeDeeplink_testModuleVersion(): void
{
    $module = include BP . 'app/code/Weline/Mail/etc/module.php';
    self::assertSame('0.2.20', $module['version'] ?? null);
}

private function assert_MailDkimLocalEnsureService_testEnsureGeneratesReusablePublicKey(): void
{
    $svc = new MailDkimLocalEnsureService();
    $domain = 'dkim-auto-' . bin2hex(random_bytes(4)) . '.example.com';
    $first = $svc->ensure($domain, 'default');
    self::assertTrue($first['created']);
    self::assertNotSame('', $first['public_key']);
    self::assertGreaterThan(32, strlen(base64_decode($first['public_key'], true) ?: ''));
    self::assertFileExists($first['private_key_path']);
    $second = $svc->ensure($domain, 'default');
    self::assertFalse($second['created']);
    self::assertSame($first['public_key'], $second['public_key']);
    @unlink($first['private_key_path']);
}

private function assert_MailFrontendRegisterLogin_testCustomerRegisterExposesExtrasSlot(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Customer/view/templates/frontend/account/register.phtml');
    self::assertStringContainsString('account-register-extras', $src);
    self::assertStringNotContainsString("->fetch('Weline_Mail::", $src);
    self::assertSame(1, substr_count($src, '<w:slot id="account-register-extras"'));
    self::assertSame(1, substr_count($src, '<w:slot id="account-mail-register-panel"'));
    self::assertStringContainsString('ob_start()', $src);
    self::assertStringContainsString('ob_get_clean()', $src);
    self::assertStringContainsString('showRegisterTabs', $src);
    self::assertStringContainsString('mailRegisterPanelHtml', $src);
    self::assertStringContainsString('data-w-component="tabs"', $src);
    self::assertStringContainsString('customer-register-panel', $src);
    self::assertStringContainsString('mail-register-panel', $src);
    self::assertStringContainsString('captcha="off"', $src);
    $panelEcho = strpos($src, '<?= $mailRegisterPanelHtml ?>');
    $formClose = strpos($src, '</w:form>');
    self::assertNotFalse($panelEcho);
    self::assertNotFalse($formClose);
    self::assertGreaterThan($formClose, $panelEcho, 'Mail panel HTML must render after main register form');
}

private function assert_MailFrontendRegisterLogin_testCustomerLoginHasNoDedicatedMailLogin(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Customer/view/templates/frontend/account/login.phtml');
    self::assertStringNotContainsString('account-mail-login', $src);
    self::assertStringNotContainsString('Weline_Mail::templates/frontend/widgets/account-mail-login', $src);
    self::assertStringContainsString('account-login-social-providers', $src);
    self::assertFileDoesNotExist(BP . 'app/code/Weline/Mail/view/templates/frontend/widgets/account-mail-login.phtml');
    self::assertFileDoesNotExist(BP . 'app/code/Weline/Mail/Service/MailFrontendLoginService.php');
}

private function assert_MailFrontendRegisterLogin_testWidgetDefaultInjectionsRegisterOnly(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Mail' . '/extends/module/Weline_Widget/Weline_Mail/widget.php';
    $tpl = 'Weline_Mail::templates/frontend/widgets/account-mail-register.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Mail' . '/view/templates/frontend/widgets/account-mail-register.phtml');
    self::assertStringContainsString('@widget.code {account-mail-register}', $src);
    self::assertStringContainsString('@widget.slot {account-mail-register-panel}', $src);
    self::assertStringContainsString('@widget.placement {injection}', $src);
    self::assertStringContainsString('"layout_type":"account/register"', $src);
    self::assertStringContainsString('"slot":"account-mail-register-panel"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_MailFrontendRegisterLogin_testFeatureConfigKeysAndDefaults(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/MailFrontendFeatureConfig.php');
    self::assertStringContainsString('mail/frontend_register/enabled', $src);
    self::assertStringContainsString('mail/frontend_register/aux_email_verify', $src);
    self::assertStringNotContainsString('mail/frontend_login/enabled', $src);
    self::assertStringContainsString('return $this->bool(self::KEY_REGISTER_ENABLED, false)', $src);
    self::assertStringContainsString('return $this->bool(self::KEY_AUX_VERIFY, true)', $src);
    $cfgTpl = (string) file_get_contents(BP . 'app/code/Weline/Mail/extends/module/Weline_SystemConfig/Config/frontend/mail-frontend.phtml');
    self::assertStringNotContainsString('mail/frontend_login/enabled', $cfgTpl);
}

private function assert_MailFrontendRegisterLogin_testRegisterWidgetGatesOnConfig(): void
{
    $tpl = (string) file_get_contents(BP . 'app/code/Weline/Mail/view/templates/frontend/widgets/account-mail-register.phtml');
    self::assertStringContainsString('isRegisterEnabled', $tpl);
    self::assertStringContainsString('isAuxEmailVerifyEnabled', $tpl);
    self::assertStringContainsString('mail_register_variant', $tpl);
    self::assertStringContainsString('w-auth-login__mail-panel', $tpl);
    self::assertStringNotContainsString('<details', $tpl);
}

private function assert_MailFrontendRegisterLogin_testCacheInvalidatorObservesResourceChanged(): void
{
    self::assertFileExists(BP . 'app/code/Weline/Mail/Observer/MailFrontendConfigResourceChanged.php');
    $event = (string) file_get_contents(BP . 'app/code/Weline/Mail/etc/event.xml');
    self::assertStringContainsString('MailFrontendConfigResourceChanged', $event);
    self::assertStringContainsString('resource_changed', $event);
}

private function assert_MailOriginIpResolver_testStoredIpv4Wins(): void
{
    $resolver = new MailOriginIpResolver();
    self::assertSame('203.0.113.10', $resolver->resolve('203.0.113.10'));
}

private function assert_MailOriginIpResolver_testRejectsNonIpStoredViaFilter(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/MailOriginIpResolver.php');
    self::assertStringContainsString('FILTER_VALIDATE_IP', $src);
    self::assertStringContainsString('FILTER_FLAG_IPV4', $src);
    // 非法存值不会 early-return，会落到 env / ServerIpService（需完整引导，本契约只锁校验门）
    self::assertMatchesRegularExpression('/if \(\$stored !== \'\' && \$this->isIpv4\(\$stored\)\)/', $src);
}

private function assert_MailOriginIpResolver_testResolverSourcePrefersEnvThenServerIpService(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/MailOriginIpResolver.php');
    self::assertStringContainsString("Env::get('wls.public_ip')", $src);
    self::assertStringContainsString('ServerIpService', $src);
    self::assertStringContainsString('getPublicIpv4(true)', $src);
}

private function assert_MailOutboundRelaySync_testSyncBuildsSenderRouteNotAuthenticatedAs(): void
{
    $path = BP . '/app/code/Weline/Mail/Service/MailOutboundRelaySyncService.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString("sender == '", $src);
    self::assertStringNotContainsString('authenticated_as', $src);
    self::assertStringContainsString('weline-outbound-acct-', $src);
    self::assertStringContainsString('is_local_domain', $src);
    self::assertStringContainsString('schema_fields_OUTBOUND_RELAY_HOST', $src);
}

private function assert_MailOutboundRelaySync_testAccountHasOutboundHostColumns(): void
{
    $account = (string) \file_get_contents(BP . '/app/code/Weline/Mail/Model/MailAccount.php');
    self::assertStringContainsString('outbound_relay_enabled', $account);
    self::assertStringContainsString('outbound_relay_host', $account);
    self::assertStringContainsString('outbound_relay_password_sealed', $account);
}

private function assert_MailOutboundRelaySync_testEnterpriseOutboundOnAccountSmtpTabNotDomain(): void
{
    $src = (string) \file_get_contents(BP . '/app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml');
    self::assertStringContainsString('mail-account-outbound-', $src);
    self::assertStringContainsString('mail-smtp-create-self', $src);
    self::assertStringContainsString('mail-smtp-create-external', $src);
    self::assertStringContainsString("'smtp' => __('Smtp')", $src);
    self::assertStringNotContainsString('mail-domain-outbound-', $src);
    self::assertStringNotContainsString('set-default-smtp-account', $src);
}
}
