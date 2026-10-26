<?php

declare(strict_types=1);

namespace Weline\Social\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Mail\Model\MailAccount;
use Weline\Social\Service\SocialPlatformSignupUrl;
use Weline\Social\Service\SocialScopeMailboxService;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllSocialServiceContractsPass().
 */
final class SocialServiceContractsTest extends TestCase
{
    public function testAllSocialServiceContractsPass(): void
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
            'SocialPlatformAppConfigWebsiteScope::testAppConfigDeclaresWebsiteScopeHelpers' => function () {
            $this->assert_SocialPlatformAppConfigWebsiteScope_testAppConfigDeclaresWebsiteScopeHelpers();
        },
            'SocialPlatformAppConfigWebsiteScope::testProvidersResolveAppConfigFromAccountContext' => function () {
            $this->assert_SocialPlatformAppConfigWebsiteScope_testProvidersResolveAppConfigFromAccountContext();
        },
            'SocialWebsiteScopeBind::testOauthServiceMergesScopeBindAndReturnQuery' => function () {
            $this->assert_SocialWebsiteScopeBind_testOauthServiceMergesScopeBindAndReturnQuery();
        },
            'SocialWebsiteScopeBind::testAttachAccountToScopeMergesExistingIds' => function () {
            $this->assert_SocialWebsiteScopeBind_testAttachAccountToScopeMergesExistingIds();
        },
            'SocialWebsiteScopeBind::testOauthCallbackRedirectsWithScopeQuery' => function () {
            $this->assert_SocialWebsiteScopeBind_testOauthCallbackRedirectsWithScopeQuery();
        },
            'SocialWebsiteScopeBind::testListWidgetAccountsAcceptsWebsiteIdFilter' => function () {
            $this->assert_SocialWebsiteScopeBind_testListWidgetAccountsAcceptsWebsiteIdFilter();
        },
            'SocialWebsiteScopeBind::testAdminOneClickAuthPassesScopePayload' => function () {
            $this->assert_SocialWebsiteScopeBind_testAdminOneClickAuthPassesScopePayload();
        },
            'SocialWebsiteScopeBind::testWidgetPassesCurrentWebsiteId' => function () {
            $this->assert_SocialWebsiteScopeBind_testWidgetPassesCurrentWebsiteId();
        },
            'SocialPlatformSignupUrl::testBuildsHttpsEmailUrlAndRejectsUnsafe' => function () {
            $this->assert_SocialPlatformSignupUrl_testBuildsHttpsEmailUrlAndRejectsUnsafe();
        },
            'SocialScopeMailbox::testSocialLocalPartIsMailSystemAccount' => function () {
            $this->assert_SocialScopeMailbox_testSocialLocalPartIsMailSystemAccount();
        },
        ];
    }

private function assert_SocialPlatformAppConfigWebsiteScope_testAppConfigDeclaresWebsiteScopeHelpers(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/Service/SocialPlatformAppConfig.php');
    self::assertStringContainsString('function scopeForWebsite(int $websiteId, string $websiteCode = \'\'): string', $src);
    self::assertStringContainsString('function getPlatformAppForWebsite(', $src);
    self::assertStringContainsString('function getPlatformAppFromContext(', $src);
    self::assertStringContainsString('default.default.default', $src);
    self::assertStringContainsString('.default.default', $src);
    self::assertStringContainsString("'id-' . \$websiteId", $src);
}

private function assert_SocialPlatformAppConfigWebsiteScope_testProvidersResolveAppConfigFromAccountContext(): void
{
    $facebook = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/Platform/Social/FacebookProvider.php');
    self::assertStringContainsString("resolveAppConfig('facebook', \$accountContext)", $facebook);
    self::assertStringContainsString("resolveAppConfig('facebook'", $facebook);
    $abstract = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/Platform/AbstractSocialPlatformProvider.php');
    self::assertStringContainsString('function resolveAppConfig(', $abstract);
    self::assertStringContainsString('getPlatformAppFromContext', $abstract);
}

private function assert_SocialWebsiteScopeBind_testOauthServiceMergesScopeBindAndReturnQuery(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/Service/SocialOauthService.php');
    self::assertStringContainsString('getPlatformAppFromContext', $src);
    self::assertStringContainsString('attachAccountToScope', $src);
    self::assertStringContainsString('return_query', $src);
    self::assertStringContainsString('app_config', $src);
}

private function assert_SocialWebsiteScopeBind_testAttachAccountToScopeMergesExistingIds(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/Service/SocialWebsiteAccountService.php');
    self::assertStringContainsString('function attachAccountToScope', $src);
    self::assertStringContainsString('getScopeDefaultAccounts', $src);
    self::assertStringContainsString('saveScopeAccountDefaults', $src);
    self::assertStringContainsString('if (!\in_array($accountId, $ids, true))', $src);
}

private function assert_SocialWebsiteScopeBind_testOauthCallbackRedirectsWithScopeQuery(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/Controller/Backend/Oauth.php');
    self::assertStringContainsString('return_query', $src);
    self::assertStringContainsString('socialReturnUrl', $src);
    self::assertStringContainsString('getBackendUrl(\'weline_social/backend/social\', $clean)', $src);
}

private function assert_SocialWebsiteScopeBind_testListWidgetAccountsAcceptsWebsiteIdFilter(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/Service/SocialAccountService.php');
    self::assertStringContainsString("array_key_exists('website_id', \$filters)", $src);
    self::assertStringContainsString('activeWidgetAccountIdsForWebsite', $src);
    self::assertStringContainsString('SCOPE_TYPE_WEBSITE', $src);
}

private function assert_SocialWebsiteScopeBind_testAdminOneClickAuthPassesScopePayload(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/view/templates/backend/Social/index.phtml');
    self::assertStringContainsString('scopePayload(currentScope)', $src);
    self::assertStringContainsString("callSocial('startAuthorization'", $src);
    self::assertStringContainsString('选择网站范围（仅支持站点）', $src);
}

private function assert_SocialWebsiteScopeBind_testWidgetPassesCurrentWebsiteId(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/view/theme/frontend/widgets/social/social-accounts/default.phtml');
    self::assertStringContainsString('RequestContext::websiteId()', $src);
    self::assertStringContainsString("'website_id' => \$websiteId", $src);
}

private function assert_SocialPlatformSignupUrl_testBuildsHttpsEmailUrlAndRejectsUnsafe(): void
{
    $email = 'social-demo@example.com';
    $built = SocialPlatformSignupUrl::build('facebook', $email);
    self::assertStringStartsWith('https://www.facebook.com/r.php?email=', $built);
    self::assertStringContainsString(rawurlencode($email), $built);
    self::assertSame('', SocialPlatformSignupUrl::build('fake_browser', $email));
    self::assertSame('', SocialPlatformSignupUrl::build('facebook', $email, 'http://evil.example/signup?email={email}'));
    self::assertSame(
        'https://x.com/i/flow/signup',
        SocialPlatformSignupUrl::build('x', $email)
    );
    $mailbox = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/Service/SocialScopeMailboxService.php');
    $provider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/extends/module/Weline_Framework/Query/SocialManagementQueryProvider.php');
    self::assertStringContainsString('function probeScopeSocialMailbox', $mailbox);
    self::assertStringContainsString('contact@', $mailbox);
    self::assertStringContainsString('contact_missing', $mailbox);
    self::assertStringContainsString("'probeScopeSocialMailbox'", $provider);
}

private function assert_SocialScopeMailbox_testSocialLocalPartIsMailSystemAccount(): void
{
    self::assertSame('social', SocialScopeMailboxService::LOCAL_PREFIX);
    self::assertSame('social-ugc', SocialScopeMailboxService::LOCAL_PART);
    $local = SocialScopeMailboxService::LOCAL_PART;
    self::assertTrue(MailAccount::isSocialMailboxLocalPart($local));
    self::assertTrue(MailAccount::isGeneratedSystemLocalPart($local));
    self::assertFalse(MailAccount::isReservedSystemLocalPart($local));
    $ensure = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Mail/Service/MailAccountEnsureService.php');
    self::assertStringContainsString('isSocialMailboxLocalPart', $ensure);
    self::assertStringContainsString('schema_fields_IS_SYSTEM', $ensure);
}
}
