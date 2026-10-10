<?php

declare(strict_types=1);

namespace Weline\Social\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class SocialWebsiteScopeBindContractTest extends TestCase
{
    public function testOauthServiceMergesScopeBindAndReturnQuery(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/SocialOauthService.php');
        self::assertStringContainsString('getPlatformAppFromContext', $src);
        self::assertStringContainsString('attachAccountToScope', $src);
        self::assertStringContainsString('return_query', $src);
        self::assertStringContainsString('app_config', $src);
    }

    public function testAttachAccountToScopeMergesExistingIds(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/SocialWebsiteAccountService.php');
        self::assertStringContainsString('function attachAccountToScope', $src);
        self::assertStringContainsString('getScopeDefaultAccounts', $src);
        self::assertStringContainsString('saveScopeAccountDefaults', $src);
        self::assertStringContainsString('if (!\\in_array($accountId, $ids, true))', $src);
    }

    public function testOauthCallbackRedirectsWithScopeQuery(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/Oauth.php');
        self::assertStringContainsString('return_query', $src);
        self::assertStringContainsString('socialReturnUrl', $src);
        self::assertStringContainsString('getBackendUrl(\'weline_social/backend/social\', $clean)', $src);
    }

    public function testListWidgetAccountsAcceptsWebsiteIdFilter(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/SocialAccountService.php');
        self::assertStringContainsString("array_key_exists('website_id', \$filters)", $src);
        self::assertStringContainsString('activeWidgetAccountIdsForWebsite', $src);
        self::assertStringContainsString('SCOPE_TYPE_WEBSITE', $src);
    }

    public function testAdminOneClickAuthPassesScopePayload(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/Backend/Social/index.phtml');
        self::assertStringContainsString('scopePayload(currentScope)', $src);
        self::assertStringContainsString("callSocial('startAuthorization'", $src);
        self::assertStringContainsString('选择网站范围（仅支持站点）', $src);
    }

    public function testWidgetPassesCurrentWebsiteId(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/widgets/social/social-accounts/default.phtml'
        );
        self::assertStringContainsString('RequestContext::websiteId()', $src);
        self::assertStringContainsString("'website_id' => \$websiteId", $src);
    }
}
