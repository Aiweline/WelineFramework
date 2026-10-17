<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 6 *ContractTest files).
 * Observe one overall pass/fail via testAllCustomerAccountContractsPass().
 */
final class CustomerAccountContractsTest extends TestCase
{
    public function testAllCustomerAccountContractsPass(): void
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
            'AccountIndexLoginReferer::testGuestRedirectPassesRefererViaParamsNotPreEncodedQuery' => function () {
            $this->assert_AccountIndexLoginReferer_testGuestRedirectPassesRefererViaParamsNotPreEncodedQuery();
        },
            'AccountIndexPageTitle::testAccountDashboardPublishesTheDocumentTitleToTheTheme' => function () {
            $this->assert_AccountIndexPageTitle_testAccountDashboardPublishesTheDocumentTitleToTheTheme();
        },
            'AuthRedirectTerminateRethrow::testForgotPasswordRethrowsResponseTerminateException' => function () {
            $this->assert_AuthRedirectTerminateRethrow_testForgotPasswordRethrowsResponseTerminateException();
        },
            'AuthRedirectTerminateRethrow::testRegisterRethrowsResponseTerminateException' => function () {
            $this->assert_AuthRedirectTerminateRethrow_testRegisterRethrowsResponseTerminateException();
        },
            'AuthRedirectTerminateRethrow::testSocialLoginRethrowsResponseTerminateException' => function () {
            $this->assert_AuthRedirectTerminateRethrow_testSocialLoginRethrowsResponseTerminateException();
        },
            'LogoutRedirect::testLogoutRedirectUsesUrlGeneratorNotHardcodedPath' => function () {
            $this->assert_LogoutRedirect_testLogoutRedirectUsesUrlGeneratorNotHardcodedPath();
        },
            'LogoutRedirect::testAccountQueryLogoutUsesUrlGenerator' => function () {
            $this->assert_LogoutRedirect_testAccountQueryLogoutUsesUrlGenerator();
        },
            'LogoutRedirect::testStorefrontLogoutAnchorsUseUrlTag' => function () {
            $this->assert_LogoutRedirect_testStorefrontLogoutAnchorsUseUrlTag();
        },
            'SocialLoginMaintenanceProbe::testStartAndCallbackSkipMaintenanceRecoveryProbes' => function () {
            $this->assert_SocialLoginMaintenanceProbe_testStartAndCallbackSkipMaintenanceRecoveryProbes();
        },
            'SocialLoginReturnUrl::testSocialLoginSuccessUsesFormatAuthSuccessRedirect' => function () {
            $this->assert_SocialLoginReturnUrl_testSocialLoginSuccessUsesFormatAuthSuccessRedirect();
        },
        ];
    }

private function assert_AccountIndexLoginReferer_testGuestRedirectPassesRefererViaParamsNotPreEncodedQuery(): void
{
    $controllerPath = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Controller/Account/Index.php';
    self::assertFileExists($controllerPath);
    $source = (string) \file_get_contents($controllerPath);
    self::assertStringContainsString("\$this->redirect('/customer/account/login', ['referer' => \$currentUrl])", $source);
    self::assertStringNotContainsString("login?referer=' . urlencode(", $source);
    self::assertStringNotContainsString('login?referer=" . urlencode(', $source);
}

private function assert_AccountIndexPageTitle_testAccountDashboardPublishesTheDocumentTitleToTheTheme(): void
{
    $controllerPath = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Controller/Account/Index.php';
    self::assertFileExists($controllerPath);
    $controller = (string) \file_get_contents($controllerPath);
    self::assertStringContainsString("\$this->request->setGet('theme_page_title', (string)__('个人中心'))", $controller);
    self::assertStringContainsString("\$this->assign('page_title', __('个人中心'))", $controller);
}

private function assert_AuthRedirectTerminateRethrow_testForgotPasswordRethrowsResponseTerminateException(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Controller/Account/ForgotPassword.php');
    self::assertStringContainsString('use Weline\Framework\Http\ResponseTerminateException;', $src);
    self::assertSame(2, substr_count($src, 'catch (ResponseTerminateException $terminate)'));
    self::assertStringContainsString('throw $terminate;', $src);
    self::assertStringNotContainsString("return \$this->redirect('/customer/account/login');\n        } catch (\\Throwable", $src);
}

private function assert_AuthRedirectTerminateRethrow_testRegisterRethrowsResponseTerminateException(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Controller/Account/Register.php');
    self::assertStringContainsString('use Weline\Framework\Http\ResponseTerminateException;', $src);
    self::assertStringContainsString('catch (ResponseTerminateException $terminate)', $src);
    self::assertStringContainsString('throw $terminate;', $src);
    self::assertLessThan(strpos($src, 'MessageManager::success(__(\'注册成功，欢迎加入。\'))'), strpos($src, 'catch (ResponseTerminateException $terminate)'));
}

private function assert_AuthRedirectTerminateRethrow_testSocialLoginRethrowsResponseTerminateException(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Controller/Account/SocialLogin.php');
    self::assertStringContainsString('use Weline\Framework\Http\ResponseTerminateException;', $src);
    self::assertGreaterThanOrEqual(2, substr_count($src, 'catch (ResponseTerminateException $terminate)'));
    self::assertStringContainsString('throw $terminate;', $src);
    self::assertStringContainsString('use Weline\Framework\Http\RedirectException;', $src);
    self::assertStringContainsString("throw new RedirectException((string) \$started['authorization_url'], 302);", $src);
    self::assertStringContainsString('getChoose', $src);
    self::assertStringContainsString('postBindExisting', $src);
    self::assertStringContainsString('postCreateNew', $src);
    self::assertStringContainsString('postUnbind', $src);
    self::assertStringContainsString('INTENT_BIND', $src);
    self::assertStringContainsString('storePending', $src);
}

private function assert_LogoutRedirect_testLogoutRedirectUsesUrlGeneratorNotHardcodedPath(): void
{
    $file = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Controller/Account/Logout.php';
    self::assertFileExists($file);
    $src = (string) file_get_contents($file);
    self::assertStringContainsString("getUrl('customer/account/login'", $src);
    self::assertStringContainsString("redirect('customer/account/login'", $src);
    self::assertStringContainsString('AUTH_REFRESH_QUERY', $src);
    self::assertStringContainsString('AUTH_REFRESH_LOGOUT_VALUE', $src);
    self::assertStringNotContainsString("formatAuthInvalidRedirect('/customer/account/login')", $src);
}

private function assert_LogoutRedirect_testAccountQueryLogoutUsesUrlGenerator(): void
{
    $file = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/extends/module/Weline_Framework/Query/AccountQueryProvider.php';
    self::assertFileExists($file);
    $src = (string) file_get_contents($file);
    self::assertStringContainsString("getUrl('customer/account/login'", $src);
    self::assertStringContainsString('AUTH_REFRESH_QUERY', $src);
    self::assertStringContainsString('AUTH_REFRESH_LOGOUT_VALUE', $src);
}

private function assert_LogoutRedirect_testStorefrontLogoutAnchorsUseUrlTag(): void
{
    $roots = ['/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/account/sidebar/side.phtml', '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/widgets/header/account/default.phtml', '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/hooks/header-account.phtml'];
    foreach ($roots as $file) {
        self::assertFileExists($file, $file);
        $src = (string) file_get_contents($file);
        self::assertStringContainsString("@url{'customer/account/logout'}", $src, $file);
        self::assertStringNotContainsString('href="/customer/account/logout"', $src, $file);
    }
}

private function assert_SocialLoginMaintenanceProbe_testStartAndCallbackSkipMaintenanceRecoveryProbes(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Controller/Account/SocialLogin.php');
    self::assertStringContainsString('isMaintenanceRecoveryProbe', $src);
    self::assertStringContainsString('respondOAuthProbeSkip', $src);
    self::assertStringContainsString('_maintenance_recovery_probe', $src);
    self::assertStringContainsString('HTTP_X_MAINTENANCE_RECOVERY_CHECK', $src);
    self::assertStringContainsString("\$method === 'HEAD'", $src);
    $startPos = strpos($src, 'public function getStart()');
    $callbackPos = strpos($src, 'public function getCallback()');
    self::assertNotFalse($startPos);
    self::assertNotFalse($callbackPos);
    $startChunk = substr($src, $startPos, 400);
    $callbackChunk = substr($src, $callbackPos, 500);
    self::assertStringContainsString('isMaintenanceRecoveryProbe()', $startChunk);
    self::assertStringContainsString('respondOAuthProbeSkip()', $startChunk);
    self::assertStringContainsString('isMaintenanceRecoveryProbe()', $callbackChunk);
    self::assertStringContainsString('respondOAuthProbeSkip()', $callbackChunk);
}

private function assert_SocialLoginReturnUrl_testSocialLoginSuccessUsesFormatAuthSuccessRedirect(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Customer';
    $controller = (string) file_get_contents($root . '/Controller/Account/SocialLogin.php');
    self::assertStringContainsString('formatSocialLoginSuccessRedirect', $controller);
    self::assertStringContainsString('formatAuthSuccessRedirect', $controller);
    self::assertStringContainsString('forceLocalizationPrefix', $controller);
    self::assertStringContainsString('SocialLoginOAuthFailedException', $controller);
    self::assertStringContainsString('buildAuthPageUrl', $controller);
    self::assertStringNotContainsString("\$target = '/customer/account';", $controller);
    self::assertStringNotContainsString("'redirect' => '/customer/account'", $controller);
    $quick = (string) file_get_contents($root . '/Service/SocialLogin/SocialLoginQuickAuthService.php');
    self::assertStringContainsString('formatAuthSuccessRedirect', $quick);
    self::assertStringNotContainsString("\$target = '/customer/account';", $quick);
    $auth = (string) file_get_contents($root . '/Service/CustomerAuthReturnUrlService.php');
    self::assertStringContainsString("'customer/account/social-login'", $auth);
}
}
