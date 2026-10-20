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
            'MailEngineAccountDbSseSync::testSecretsPreferProjectKeyNotEtc' => function () {
            $this->assert_MailEngineAccountDbSseSync_testSecretsPreferProjectKeyNotEtc();
        },
            'MailEngineAccountDbSseSync::testPasswordSealedWritePathsAndSyncSse' => function () {
            $this->assert_MailEngineAccountDbSseSync_testPasswordSealedWritePathsAndSyncSse();
        },
            'MailEngineAccountDbSseSync::testListQueriesSelectBeforeFetchArray' => function () {
            $this->assert_MailEngineAccountDbSseSync_testListQueriesSelectBeforeFetchArray();
        },
            'MailOneClickSetup::testManagementDualPathUpsertAndSse' => function () {
            $this->assert_MailOneClickSetup_testManagementDualPathUpsertAndSse();
        },
            'MailboxClient::testInterfaceAndOpsFacadeExist' => function () {
            $this->assert_MailboxClient_testInterfaceAndOpsFacadeExist();
        },
        ];
    }

private function assert_MailEngineAccountDbSseSync_testSecretsPreferProjectKeyNotEtc(): void
{
    $src = (string) file_get_contents(BP . '/app/code/Weline/Mail/Service/MailOutboundRelaySecretService.php');
    self::assertStringContainsString('mail.secrets_key_base64', $src);
    self::assertStringContainsString('var/mail/account-secrets.key', $src);
    self::assertStringContainsString('ENV_SECRETS_KEY', $src);
    self::assertStringContainsString('consumePendingReseal', $src);
    self::assertStringContainsString('projectKey', $src);
    self::assertStringContainsString('legacyKeys', $src);
    self::assertStringContainsString('var/mail/outbound-relay.key', $src);
    // seal 首选不得是 /etc
    self::assertMatchesRegularExpression('/function seal\([\s\S]*?projectKey\(\)/', $src);
    self::assertDoesNotMatchRegularExpression(
        '/private function projectKey\(\): string\s*\{[\s\S]*?\/etc\/weline\/mail-admin\.key/',
        $src
    );
}

private function assert_MailEngineAccountDbSseSync_testPasswordSealedWritePathsAndSyncSse(): void
{
    $account = (string) file_get_contents(BP . '/app/code/Weline/Mail/Model/MailAccount.php');
    self::assertStringContainsString("schema_fields_PASSWORD_SEALED = 'password_sealed'", $account);

    $mgmt = (string) file_get_contents(BP . '/app/code/Weline/Mail/Service/MailAccountManagementService.php');
    self::assertStringContainsString('MailOutboundRelaySecretService', $mgmt);
    self::assertStringContainsString('schema_fields_PASSWORD_SEALED', $mgmt);
    self::assertStringContainsString('function createAccount', $mgmt);
    self::assertStringContainsString('function provisionOrResetPassword', $mgmt);

    $frontend = (string) file_get_contents(BP . '/app/code/Weline/Mail/Service/MailFrontendRegisterService.php');
    self::assertStringContainsString('schema_fields_PASSWORD_SEALED', $frontend);
    self::assertStringContainsString('MailOutboundRelaySecretService', $frontend);

    $sync = (string) file_get_contents(BP . '/app/code/Weline/Mail/Service/MailEngineAccountSyncService.php');
    self::assertStringContainsString('hasManagementCredential', $sync);
    self::assertStringContainsString('provisionOrResetAccount', $sync);
    self::assertStringContainsString('ensureDomain', $sync);
    self::assertStringContainsString('accountExists', $sync);
    self::assertStringContainsString('upsertReaderCredential', $sync);
    self::assertStringContainsString('function countActiveMailAccounts', $sync);
    self::assertStringNotContainsString('->assertSystemMutable(', $sync);
    self::assertStringNotContainsString('->provisionOrResetPassword(', $sync);
    self::assertStringNotContainsString('MailAccountManagementService', $sync);

    $engine = (string) file_get_contents(BP . '/app/code/Weline/Mail/Service/StalwartEngineAdapter.php');
    self::assertStringContainsString('function upsertReaderCredential', $engine);
    self::assertStringContainsString('/etc/weline/mail-reader.key', $engine);
    self::assertStringContainsString('/etc/weline/mail-reader.credential', $engine);
    self::assertStringContainsString('var/mail/mail-reader.key', $engine);
    self::assertStringContainsString('var/mail/mail-reader.credential', $engine);
    self::assertStringContainsString('function detectJmapBaseUrl', $engine);

    $jmapSession = (string) file_get_contents(BP . '/app/code/Weline/Mail/Service/Mailbox/StalwartJmapSession.php');
    self::assertStringContainsString('resolveReadableReaderCredentialPair', $jmapSession);
    self::assertStringContainsString('var/mail/mail-reader.credential', $jmapSession);
    self::assertStringContainsString('resolveWritableReaderCredentialPair', $engine);
    self::assertStringContainsString('resolveReadableReaderCredentialPair', $engine);

    $index = (string) file_get_contents(BP . '/app/code/Weline/Mail/Controller/Backend/Index.php');
    self::assertStringContainsString('function postSyncEngineAccountsFromDbStream', $index);
    self::assertStringContainsString('MailEngineAccountSyncService', $index);
    self::assertStringContainsString("sendEvent('password_once'", $index);
    self::assertStringContainsString('SseWriter', $index);
}

private function assert_MailOneClickSetup_testManagementDualPathUpsertAndSse(): void
{
    $adapter = (string) file_get_contents(BP . '/app/code/Weline/Mail/Service/StalwartManagementAdapter.php');
    self::assertStringContainsString('/etc/weline/mail-admin.key', $adapter);
    self::assertStringContainsString('/etc/weline/mail-admin.credential', $adapter);
    self::assertStringContainsString('var/mail/mail-admin.key', $adapter);
    self::assertStringContainsString('var/mail/mail-admin.credential', $adapter);
    self::assertStringContainsString('function upsertManagementCredential', $adapter);
    self::assertStringContainsString('function probeManagementApi', $adapter);
    self::assertStringContainsString('function probeManagementAuth', $adapter);
    self::assertStringContainsString('function completeBootstrapIfNeeded', $adapter);
    self::assertStringContainsString('function openManagementPassword', $adapter);
    self::assertStringContainsString('resolveReadableCredentialPair', $adapter);
    self::assertStringContainsString('resolveWritableCredentialPair', $adapter);

    $setup = (string) file_get_contents(BP . '/app/code/Weline/Mail/Service/MailOneClickSetupService.php');
    self::assertStringContainsString('function bindManagementIfNeeded', $setup);
    self::assertStringContainsString('function oneClickGlobal', $setup);
    self::assertStringContainsString('function oneClickDomain', $setup);
    // 一键必须下发库内 Hub/外投导流；禁止只录账户却丢分流。
    self::assertStringContainsString('MailOutboundRelaySyncService', $setup);
    self::assertStringContainsString('syncAll()', $setup);
    self::assertStringContainsString('relay_applied', $setup);
    self::assertStringContainsString('upsertManagementCredential', $setup);
    self::assertStringContainsString('openManagementPassword', $setup);
    self::assertStringContainsString('probeManagementApi', $setup);
    self::assertStringContainsString('completeBootstrapIfNeeded', $setup);
    self::assertStringContainsString('RESTARTED', $setup);
    self::assertStringContainsString('syncFromDatabase', $setup);
    self::assertStringContainsString('ensureContactAccount', $setup);
    self::assertStringContainsString('bind_stalwart_recovery_admin.sh', $setup);
    self::assertStringContainsString('/opt/stalwart/etc/stalwart.env', $setup);
    // HTTP 0 during restart must retry; do not treat generic「认证」as hard 401.
    self::assertStringContainsString('function isHardAuthFailure', $setup);
    self::assertStringContainsString('http 0', $setup);
    self::assertStringNotContainsString("str_contains(\$msg, '认证')", $setup);

    $engineMgmt = (string) file_get_contents(BP . '/app/code/Weline/Mail/Service/StalwartManagementAdapter.php');
    self::assertStringContainsString('管理连接失败（HTTP 0）', $engineMgmt);
    // Empty JMAP args must be JSON object {}; PHP [] → HTTP 400 notRequest on Stalwart 0.16.
    self::assertStringContainsString('new \\stdClass()', $engineMgmt);
    self::assertStringContainsString('$argumentObject', $engineMgmt);
    // Bootstrap hostname must be FQDN (not bare cloud hostname).
    self::assertStringContainsString('function resolveBootstrapHostnames', $engineMgmt);
    self::assertStringContainsString('mail.', $engineMgmt);

    $sync = (string) file_get_contents(BP . '/app/code/Weline/Mail/Service/MailEngineAccountSyncService.php');
    self::assertStringContainsString('?int $domainId = null', $sync);
    self::assertStringContainsString('function shouldSkipEngineDomain', $sync);
    self::assertStringContainsString('function shouldSkipEngineMailbox', $sync);
    self::assertStringContainsString('.invalid', $sync);
    self::assertStringContainsString('isFakeDomain', $sync);
    self::assertStringContainsString('跳过假域', $sync);

    self::assertStringContainsString('projectBasePath', $adapter);
    self::assertStringContainsString('shouldSkipEngineDomain', $setup);

    $index = (string) file_get_contents(BP . '/app/code/Weline/Mail/Controller/Backend/Index.php');
    self::assertStringContainsString('function postOneClickMailSetupStream', $index);
    self::assertStringContainsString('function postOneClickDomainSetupStream', $index);
    self::assertStringContainsString('MailOneClickSetupService', $index);
    self::assertStringContainsString("sendEvent('password_once'", $index);
    self::assertStringContainsString('PostResponseCaptureMode::None', $index);
    self::assertStringContainsString('domain_id', $index);

    $script = BP . '/app/code/Weline/Mail/env/script/bind_stalwart_recovery_admin.sh';
    self::assertFileExists($script);
    $body = (string) file_get_contents($script);
    self::assertStringContainsString('STALWART_RECOVERY_ADMIN', $body);
    self::assertStringContainsString('/opt/stalwart/etc/stalwart.env', $body);
    self::assertStringContainsString('launchctl kickstart', $body);
    self::assertStringContainsString('BOUND', $body);
    self::assertStringContainsString('RESTARTED', $body);
}

private function assert_MailEngineAccountDbSseSync_testListQueriesSelectBeforeFetchArray(): void
{
    $sync = (string) file_get_contents(BP . '/app/code/Weline/Mail/Service/MailEngineAccountSyncService.php');
    // 每个 fetchArray 列表链须紧邻 select()（对齐 MailOutboundRelaySync / RouteRule）
    $offset = 0;
    $fetchCount = 0;
    while (($pos = strpos($sync, '->fetchArray()', $offset)) !== false) {
        $fetchCount++;
        $before = substr($sync, max(0, $pos - 48), 48);
        self::assertStringContainsString(
            '->select()',
            $before,
            'list query must call select() immediately before fetchArray()'
        );
        $offset = $pos + 1;
    }
    self::assertGreaterThanOrEqual(5, $fetchCount);
    self::assertStringContainsString('function countActiveMailAccounts', $sync);
}

private function assert_MailboxClient_testInterfaceAndOpsFacadeExist(): void
{
    self::assertFileExists(BP . 'app/code/Weline/Mail/Service/Mailbox/MailboxClientInterface.php');
    self::assertFileExists(BP . 'app/code/Weline/Mail/Service/Mailbox/MailboxFolder.php');
    self::assertFileExists(BP . 'app/code/Weline/Mail/Service/Mailbox/StalwartJmapSession.php');
    $jmapSession = (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/Mailbox/StalwartJmapSession.php');
    self::assertStringContainsString('isValidReaderUsername', $jmapSession);
    self::assertStringContainsString('mailbox%admin', $jmapSession);
    self::assertFileExists(BP . 'app/code/Weline/Mail/Service/Mailbox/StalwartMailboxClient.php');
    self::assertFileExists(BP . 'app/code/Weline/Mail/Service/Mailbox/FakeMailboxClient.php');
    self::assertFileExists(BP . 'app/code/Weline/Mail/Service/Mailbox/MailboxOpsService.php');
    $iface = (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/Mailbox/MailboxClientInterface.php');
    self::assertStringContainsString('function moveToTrash', $iface);
    self::assertStringContainsString('function restoreToInbox', $iface);
    self::assertStringContainsString('function destroy', $iface);
    self::assertStringContainsString('function uploadBlob', $iface);
    self::assertStringContainsString('function countUnreadInbox', $iface);
    self::assertStringContainsString('bool $unreadOnly = false', $iface);
    self::assertStringContainsString('hasKeyword', (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/Mailbox/StalwartMailboxClient.php'));
    $ops = (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/Mailbox/MailboxOpsService.php');
    self::assertStringContainsString('function buildComposePrefill', $ops);
    self::assertStringContainsString('function mapInboxUnreadCounts', $ops);
    self::assertStringContainsString('function countUnreadInbox', $ops);
    self::assertStringContainsString('function listForWorkspace', $ops);
    $ordered = \Weline\Mail\Service\Mailbox\MailboxOpsService::orderUnreadFirst([
        ['id' => 'read', 'unread' => false],
        ['id' => 'unread', 'unread' => true],
    ]);
    self::assertSame('unread', $ordered[0]['id'] ?? null);
    $pinned = \Weline\Mail\Service\Mailbox\MailboxOpsService::prependInboxUnread(
        [['id' => 'in', 'unread' => true, 'folder' => 'sent']],
        [['id' => 'out', 'unread' => false, 'folder' => 'sent'], ['id' => 'in', 'unread' => false, 'folder' => 'sent']]
    );
    self::assertSame(['in', 'out'], array_column($pinned, 'id'));
    self::assertSame('inbox', $pinned[0]['folder'] ?? null);
    self::assertStringContainsString('function workspace', $ops);
    self::assertStringContainsString('function annotateSentReplies', $ops);
    $annotated = \Weline\Mail\Service\Mailbox\MailboxOpsService::annotateSentReplies(
        [
            ['id' => 'sent-1', 'thread_id' => 't1', 'subject' => '测试长安汉服', 'unread' => false, 'folder' => 'sent'],
            ['id' => 'sent-2', 'thread_id' => '', 'subject' => '另一封', 'unread' => false, 'folder' => 'sent'],
        ],
        [
            ['id' => 'in-1', 'thread_id' => 't1', 'subject' => 'Re: 测试长安汉服', 'unread' => true, 'folder' => 'inbox'],
            ['id' => 'in-2', 'thread_id' => '', 'subject' => '回复: 另一封', 'unread' => false, 'folder' => 'inbox'],
        ]
    );
    self::assertSame(1, (int)($annotated[0]['reply_count'] ?? 0));
    self::assertTrue((bool)($annotated[0]['unread'] ?? false));
    self::assertSame(1, (int)($annotated[1]['reply_count'] ?? 0));
    self::assertFalse((bool)($annotated[1]['unread'] ?? true));
    self::assertSame(1, \Weline\Mail\Service\Mailbox\MailboxOpsService::countSignaledRows($annotated));
    $liveClient = (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/Mailbox/StalwartMailboxClient.php');
    self::assertStringContainsString('function countUnreadInbox', $liveClient);
    self::assertStringContainsString('unreadEmails', $liveClient);
    $fakeClient = (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/Mailbox/FakeMailboxClient.php');
    self::assertStringContainsString('function countUnreadInbox', $fakeClient);
    $smtp = (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/MailSmtpAccountService.php');
    self::assertStringContainsString('MailboxOpsService', $smtp);
    self::assertStringNotContainsString('->sendMessage(', $smtp);
    $adapter = (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/StalwartEngineAdapter.php');
    self::assertStringContainsString('StalwartMailboxClient', $adapter);
    self::assertStringContainsString('@deprecated Use MailboxOpsService', $adapter);
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
    $sendEnd = strpos($src, "'name' => 'resolveActiveMailDomain'", $sendPos);
    self::assertNotFalse($sendEnd);
    $sendSlice = substr($src, $sendPos, $sendEnd - $sendPos);
    self::assertStringContainsString("'frontend' => true", $sendSlice);
    self::assertStringContainsString("'mode' => 'write'", $sendSlice);
    self::assertStringContainsString("'auth' => 'backend'", $sendSlice);
    self::assertStringContainsString('Weline_Mail::mail_send_as', $sendSlice);
    self::assertStringContainsString("'name' => 'cc'", $sendSlice);
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
    self::assertSame('0.2.50', $module['version'] ?? null);
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
    self::assertStringContainsString('weline-outbound-rcpt-', $src);
    self::assertStringContainsString('is_local_domain', $src);
    self::assertStringContainsString('schema_fields_OUTBOUND_RELAY_HOST', $src);
    self::assertStringContainsString('MailOutboundRouteRule', $src);
    // fetch() 行可能不是 Model；必须 fetchArray，禁止在 accounts 循环里 instanceof 跳过 → 空路由直连 MX。
    self::assertStringContainsString('fetchArray()', $src);
    self::assertDoesNotMatchRegularExpression(
        '/foreach\s*\(\s*\$accounts.*?instanceof\s+MailAccount/s',
        $src,
        'accounts loop must not filter with instanceof MailAccount'
    );
    // 库内 Hub：密封优先；解不开回退同址邮箱口令并重封；禁止静默空 authSecret。
    self::assertStringContainsString('resolveRelayAuthPassword', $src);
    self::assertStringContainsString('openMailAccountPassword', $src);
    self::assertStringContainsString('persistPendingReseal', $src);
    self::assertStringContainsString('consumePendingReseal', $src);
    self::assertStringContainsString('schema_fields_PASSWORD_SEALED', $src);
    // 固定用户名服务商（Resend/SendGrid）：禁止把显示名写进 AUTH。
    self::assertStringContainsString('normalizeFixedSmtpUsername', $src);
    self::assertStringContainsString('normalizeUsernameForHost', $src);
    // 下发后 AUTH 探针：Hub 密码错不得假成功。
    self::assertStringContainsString('assertAuthenticatedRelaysReachable', $src);
    self::assertStringContainsString('probeSmtpAuthLogin', $src);
    self::assertStringContainsString('AUTH LOGIN', $src);
    self::assertStringContainsString('$port === 25', $src);
    self::assertStringContainsString('is_local_domain(rcpt_domain)', $src);
    self::assertStringContainsString("rcpt_domain == '", $src);
    self::assertStringContainsString('(object)$matchMap', $src);
    // A1：拼接段必须 local → foreach rcptMatches → foreach senderMatches（禁止旧版 sender 先、local 殿后）。
    self::assertMatchesRegularExpression(
        '/\$match\s*=\s*\[\];.*?is_local_domain\(rcpt_domain\).*?foreach\s*\(\s*\$rcptMatches.*?foreach\s*\(\s*\$senderMatches/s',
        $src,
        'match assembly must be local then rcpt then sender'
    );
    self::assertStringNotContainsString(
        "\$match[] = [\n            'if' => \"sender == '",
        $src
    );
    // A2：导流 → 自家域名 Smtp → 才 mx；hub 不得抬成全局 else。
    self::assertStringNotContainsString('$primaryRelayName', $src);
    self::assertStringContainsString('resolveDefaultSelfSmtpRelay', $src);
    self::assertStringContainsString('normalizeSubmissionPort', $src);
    self::assertStringContainsString('weline-outbound-self-', $src);
    self::assertStringContainsString('weline-outbound-domain-', $src);
    self::assertStringContainsString('buildDomainSmtpSenderMatches', $src);
    self::assertStringContainsString('isLocalOutboundHost', $src);
    self::assertStringContainsString('resolveReachableSubmissionEndpoint', $src);
    self::assertStringContainsString('sender_domain ==', $src);
    self::assertStringContainsString('mergeHubBindingsIntoPayload', $src);
    self::assertStringContainsString('preferConfiguredHubOverMxElse', $src);
    self::assertStringContainsString('weline-hub-', $src);
    self::assertStringContainsString('收件域导流', $src);
    self::assertStringContainsString('再没有 → 才兜底 mx', $src);
    // 禁止 Hub/QQ 作 else / retry 兜底；QQ 仅 sender 绑定。本域 mail./smtp. 禁止无认证自环。
    self::assertStringContainsString('禁止把 else 抬成任何 weline-hub-', $src);
    self::assertStringContainsString('禁止 MODE_EXTERNAL Hub', $src);
    self::assertStringContainsString('isOwnDomainSubmissionHost', $src);
    self::assertStringNotContainsString('retry_num > 0', $src);
    self::assertStringNotContainsString('firstHubBoundRcptRouteName', $src);
    self::assertStringContainsString('本域 mail./smtp.', $src);
    self::assertStringNotContainsString('\$fallbackExternal', $src);
    self::assertStringContainsString('// A1：local → 导流规则（rcpt / sender）', $src);
    self::assertStringContainsString("\$rcptMatches", $src);
    self::assertStringContainsString("\$senderMatches", $src);
    $adapter = (string) \file_get_contents(BP . '/app/code/Weline/Mail/Service/StalwartManagementAdapter.php');
    self::assertStringContainsString('x:MtaRoute/set', $adapter);
    self::assertStringContainsString('x:MtaOutboundStrategy/set', $adapter);
    self::assertStringContainsString('ReloadSettings', $adapter);
    self::assertStringContainsString('normalizeRelayRoute', $adapter);
    self::assertStringContainsString('normalizeStrategyRouteExpression', $adapter);
    self::assertStringContainsString('listRelayRouteNamesByPrefix', $adapter);
    self::assertStringContainsString('applyQqHubEnvelopeFromRewriteIfElse', $adapter);
    self::assertStringContainsString('applyQqHubHeaderFromAlignSieve', $adapter);
    self::assertStringContainsString('clearQqHubHeaderFromAlignSieve', $adapter);
    self::assertStringContainsString('weline-qq-align-from', $adapter);
    self::assertStringContainsString('keep Header From as brand', $adapter);
    self::assertStringContainsString('Reply-To only if missing', $adapter);
    self::assertStringNotContainsString('inappropriate words', $adapter);
    $sync = (string) \file_get_contents(BP . '/app/code/Weline/Mail/Service/MailOutboundRelaySyncService.php');
    self::assertStringContainsString('不得作全局 else', $sync);
    self::assertStringContainsString('不得用 retry_num 顶替', $sync);
    self::assertStringContainsString('x:SieveSystemScript/set', $adapter);
    self::assertStringContainsString('x:MtaStageData/set', $adapter);
    self::assertStringContainsString('x:MtaStageMail/set', $adapter);
    self::assertStringContainsString('authUsername=""', $adapter);

    $model = (string) \file_get_contents(BP . '/app/code/Weline/Mail/Model/MailOutboundRouteRule.php');
    self::assertStringContainsString('weline_mail_outbound_route_rule', $model);
    self::assertStringContainsString('match_domain', $model);
    self::assertStringContainsString('relay_password_sealed', $model);

    $qp = (string) \file_get_contents(BP . '/app/code/Weline/Mail/extends/module/Weline_Framework/Query/MailQueryProvider.php');
    self::assertStringContainsString('listOutboundRouteRules', $qp);
    self::assertStringContainsString('saveOutboundRouteRule', $qp);
    self::assertStringContainsString('batchDeleteOutboundRouteRules', $qp);
    self::assertStringContainsString('createMailboxAccount', $qp);
    self::assertStringContainsString('createMailboxDomain', $qp);
    self::assertStringContainsString('deleteMailboxDomain', $qp);
    self::assertStringContainsString('resetMailboxPassword', $qp);
    self::assertStringContainsString('setMailboxOutboundRelay', $qp);
    self::assertStringContainsString('createSmtpServiceAccount', $qp);

    $admin = (string) file_get_contents(BP . '/app/code/Weline/Mail/Service/MailDomainAdminService.php');
    self::assertStringContainsString('function deleteDomain', $admin);
    self::assertStringContainsString('function normalizeMailboxDomainName', $admin);
    self::assertStringContainsString("str_starts_with(\$domain, 'www.')", $admin);
    self::assertStringContainsString('网站域名本身未删除', $admin);

    $enterprise = (string) file_get_contents(BP . 'app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml');
    self::assertStringContainsString("'routes' => __('收件域导流')", $enterprise);
    self::assertStringContainsString('mail-enterprise-outbound-routes', $enterprise);
    self::assertStringContainsString('mail-outbound-routes-mutex-hint', $enterprise);
    self::assertStringContainsString('saveOutboundRouteRule', $enterprise);
    self::assertStringContainsString('mail-outbound-route-dialog', $enterprise);
    self::assertStringContainsString('data-route-add', $enterprise);
    self::assertStringNotContainsString('mail-outbound-route-form-card', $enterprise);
    self::assertStringNotContainsString('form.scrollIntoView', $enterprise);
    self::assertStringContainsString('mail-account-create-dialog', $enterprise);
    self::assertStringContainsString('createMailboxAccount', $enterprise);
    self::assertStringContainsString('data-account-create-open', $enterprise);
    self::assertStringContainsString('mail-account-reset-dialog', $enterprise);
    self::assertStringContainsString('mail-account-outbound-dialog', $enterprise);
    self::assertStringContainsString('mail-domain-create-dialog', $enterprise);
    self::assertStringContainsString('mail-smtp-add-dialog', $enterprise);
    self::assertStringContainsString('createMailboxDomain', $enterprise);
    self::assertStringContainsString('deleteMailboxDomain', $enterprise);
    self::assertStringContainsString('data-mail-domain-delete', $enterprise);
    self::assertStringContainsString('mail-domain-delete-', $enterprise);
    self::assertStringContainsString('resetMailboxPassword', $enterprise);
    self::assertStringContainsString('setMailboxOutboundRelay', $enterprise);
    self::assertStringContainsString('createSmtpServiceAccount', $enterprise);
    self::assertStringNotContainsString("action=\"@backend-url{'weline_mail/backend/index/create-account'}\"", $enterprise);
    self::assertStringNotContainsString("action=\"@backend-url{'weline_mail/backend/index/create-domain'}\"", $enterprise);
    self::assertStringNotContainsString("action=\"@backend-url{'weline_mail/backend/index/reset-account-password'}\"", $enterprise);
    self::assertStringNotContainsString('details open data-testid="mail-smtp-add-account-', $enterprise);
}

private function assert_MailOutboundRelaySync_testAccountHasOutboundHostColumns(): void
{
    $account = (string) \file_get_contents(BP . '/app/code/Weline/Mail/Model/MailAccount.php');
    self::assertStringContainsString('outbound_relay_enabled', $account);
    self::assertStringContainsString('outbound_relay_host', $account);
    self::assertStringContainsString('outbound_relay_password_sealed', $account);
    $enterprise = (string) file_get_contents(BP . 'app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml');
    self::assertStringContainsString('outbound_smtp_pick', $enterprise);
    self::assertStringContainsString('选择 Smtp 账户/服务', $enterprise);
    $relay = (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/MailAccountOutboundRelayService.php');
    self::assertStringContainsString('resolveOutboundPick', $relay);
    self::assertStringContainsString('normalizeUsernameForHost', $relay);
    $index = (string) file_get_contents(BP . 'app/code/Weline/Mail/Controller/Backend/Index.php');
    self::assertStringContainsString('MailAccountOutboundRelayService', $index);
    self::assertStringContainsString('listOutboundPicks', $index);
}

private function assert_MailOutboundRelaySync_testEnterpriseOutboundOnAccountSmtpTabNotDomain(): void
{
    $src = (string) \file_get_contents(BP . '/app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml');
    self::assertStringContainsString('mail-account-outbound-', $src);
    self::assertStringContainsString('mail-smtp-create-self', $src);
    self::assertStringContainsString('mail-smtp-create-external', $src);
    self::assertStringContainsString('去用户与账号绑定外投', $src);
    self::assertStringContainsString('mail-smtp-edit-external-', $src);
    self::assertStringContainsString('smtp-service-delete-stream', $src);
    self::assertStringContainsString('data-smtp-delete-sse', $src);
    self::assertStringContainsString('mailSmtpConsumeSse', $src);
    self::assertStringContainsString('text/event-stream', $src);
    self::assertStringContainsString('update-smtp-service-external', $src);
    self::assertStringContainsString("data-tone=\"primary\"", $src);
    self::assertStringContainsString('mail-smtp-external-edit-form', $src);
    self::assertStringContainsString('__welineNativeFetch', $src);
    self::assertStringContainsString("'smtp' => __('Smtp')", $src);
    $index = (string) \file_get_contents(BP . '/app/code/Weline/Mail/Controller/Backend/Index.php');
    self::assertStringContainsString('postUpdateSmtpServiceExternal', $index);
    self::assertStringContainsString('postDeleteSmtpService', $index);
    self::assertStringContainsString('function postSmtpServiceDeleteStream', $index);
    self::assertStringContainsString('function postSmtpServiceAccountDeleteStream', $index);
    self::assertStringContainsString('function postConfigureCloudflareDnsStream', $index);
    self::assertStringContainsString('function runConfigureCloudflareDns', $index);
    self::assertStringContainsString('SseWriter', $index);
    $catalog = (string) \file_get_contents(BP . '/app/code/Weline/Smtp/Service/SmtpServiceCatalogService.php');
    self::assertStringContainsString('function updateExternalService', $catalog);
    self::assertStringContainsString('function deleteService', $catalog);
    self::assertStringContainsString('emitDeleteProgress', $catalog);
    self::assertStringContainsString('?callable $onProgress = null', $catalog);
    self::assertStringNotContainsString('mail-domain-outbound-', $src);
    self::assertStringNotContainsString('set-default-smtp-account', $src);
}
}
