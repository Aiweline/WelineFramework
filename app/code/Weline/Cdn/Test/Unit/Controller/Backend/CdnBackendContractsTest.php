<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllCdnBackendContractsPass().
 */
final class CdnBackendContractsTest extends TestCase
{
    public function testAllCdnBackendContractsPass(): void
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
            'CdnOffcanvasResultRoute::testAccountFormAssignsBackendOffcanvasSuccessUrl' => function () {
            $this->assert_CdnOffcanvasResultRoute_testAccountFormAssignsBackendOffcanvasSuccessUrl();
        },
            'CdnOffcanvasResultRoute::testIframeTraitRedirectsToBackendOffcanvasResultActions' => function () {
            $this->assert_CdnOffcanvasResultRoute_testIframeTraitRedirectsToBackendOffcanvasResultActions();
        },
            'OauthConnectRedirect::testPostConnectThrowsRedirectExceptionForProviderUrl' => function () {
            $this->assert_OauthConnectRedirect_testPostConnectThrowsRedirectExceptionForProviderUrl();
        },
            'RulesDualView::testGetDomainRulesExposesPlannedRulesAndViewContract' => function () {
            $this->assert_RulesDualView_testGetDomainRulesExposesPlannedRulesAndViewContract();
        },
            'RulesDualView::testRuleManagerDocblocksPinDualView' => function () {
            $this->assert_RulesDualView_testRuleManagerDocblocksPinDualView();
        },
            'RulesDualView::testApiRulesIndexHintNotImpliesEdgePush' => function () {
            $this->assert_RulesDualView_testApiRulesIndexHintNotImpliesEdgePush();
        },
        ];
    }

private function assert_CdnOffcanvasResultRoute_testAccountFormAssignsBackendOffcanvasSuccessUrl(): void
{
    $source = (string) file_get_contents(BP . '/app/code/Weline/Cdn/Controller/Backend/Account.php');
    self::assertStringContainsString("getBackendUrl('component/backend/offcanvas/getSuccess')", $source);
    self::assertStringNotContainsString("getBackendUrl('component/offcanvas/success')", $source);
}

private function assert_CdnOffcanvasResultRoute_testIframeTraitRedirectsToBackendOffcanvasResultActions(): void
{
    $source = (string) file_get_contents(BP . '/app/code/Weline/Cdn/Controller/Backend/CdnBackendIframeTrait.php');
    self::assertStringContainsString('component/backend/offcanvas/getSuccess', $source);
    self::assertStringContainsString('component/backend/offcanvas/getError', $source);
    self::assertStringNotContainsString('/component/offcanvas/success', $source);
    self::assertStringNotContainsString('/component/offcanvas/error', $source);
}

private function assert_OauthConnectRedirect_testPostConnectThrowsRedirectExceptionForProviderUrl(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Controller/Backend/Oauth.php');
    self::assertStringContainsString('use Weline\Framework\Http\RedirectException;', $source);
    self::assertStringContainsString('use Weline\Framework\Http\ResponseTerminateException;', $source);
    self::assertStringContainsString('use Weline\Cdn\Api\OauthCapableProviderInterface;', $source);
    self::assertStringContainsString('startOauthAuthorization(', $source);
    self::assertStringContainsString('getOauthCapableAdapter(', $source);
    self::assertStringContainsString('throw new RedirectException($authorizationUrl, 302);', $source);
    self::assertStringContainsString('catch (ResponseTerminateException $terminate)', $source);
    self::assertStringContainsString('oauthFailureQuery', $source);
    self::assertStringContainsString('returnRouteQuery', $source);
    self::assertStringContainsString("view'] = 'domains'", $source);
    self::assertStringContainsString('oauth_adapter', $source);
    self::assertStringContainsString('oauth_cf_error', $source);
    self::assertStringNotContainsString('->redirect($authorizationUrl)', $source);
    self::assertStringNotContainsString('CloudflareOAuthService', $source);
    self::assertStringNotContainsString('Cloudflare OAuth 授权已取消', $source);
}

private function assert_RulesDualView_testGetDomainRulesExposesPlannedRulesAndViewContract(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Controller/Backend/Rules.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('planRulesForEdgePush', $src);
    self::assertStringContainsString("'plannedRules'", $src);
    self::assertStringContainsString("'view_contract'", $src);
    self::assertStringContainsString('库内 merged ≠ 边缘 plan', $src);
    self::assertStringContainsString('禁止把 mergedRules 标成已上 Cloudflare', $src);
}

private function assert_RulesDualView_testRuleManagerDocblocksPinDualView(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Service/RuleManager.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('库内展开视图（inventory）', $src);
    self::assertStringContainsString('边缘真相视图（planned）', $src);
    self::assertStringContainsString('≠ 本方法结果', $src);
}

private function assert_RulesDualView_testApiRulesIndexHintNotImpliesEdgePush(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/view/templates/backend/ApiRules/index.phtml';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('planRulesForEdgePush', $src);
    self::assertStringContainsString('不等于已上 Cloudflare', $src);
}
}
