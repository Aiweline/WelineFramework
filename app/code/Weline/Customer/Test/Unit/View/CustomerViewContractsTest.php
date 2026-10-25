<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 21 *ContractTest files).
 * Observe one overall pass/fail via testAllCustomerViewContractsPass().
 */
final class CustomerViewContractsTest extends TestCase
{
    public function testAllCustomerViewContractsPass(): void
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
            'AccountAuthCopyI18n::testLoginTemplateTranslatesWidgetHeadingAndSubtitle' => function () {
            $this->assert_AccountAuthCopyI18n_testLoginTemplateTranslatesWidgetHeadingAndSubtitle();
        },
            'AccountAuthCopyI18n::testRegisterTemplateTranslatesWidgetHeadingAndSubtitle' => function () {
            $this->assert_AccountAuthCopyI18n_testRegisterTemplateTranslatesWidgetHeadingAndSubtitle();
        },
            'AccountAuthCopyI18n::testAccountLoginWidgetTranslatesConfigurableCopy' => function () {
            $this->assert_AccountAuthCopyI18n_testAccountLoginWidgetTranslatesConfigurableCopy();
        },
            'AccountAuthCopyI18n::testAccountRegisterWidgetTranslatesConfigurableCopy' => function () {
            $this->assert_AccountAuthCopyI18n_testAccountRegisterWidgetTranslatesConfigurableCopy();
        },
            'AccountAvatarFallback::testAccountIndexSsrKeepsInitialFallbackVisible' => function () {
            $this->assert_AccountAvatarFallback_testAccountIndexSsrKeepsInitialFallbackVisible();
        },
            'AccountAvatarFallback::testSidebarSsrKeepsInitialFallbackVisible' => function () {
            $this->assert_AccountAvatarFallback_testSidebarSsrKeepsInitialFallbackVisible();
        },
            'AccountAvatarFallback::testAccountIndexJsDoesNotHideFallbackBeforePhotoLoads' => function () {
            $this->assert_AccountAvatarFallback_testAccountIndexJsDoesNotHideFallbackBeforePhotoLoads();
        },
            'AccountAvatarFallback::testAccountIndexConfigEmbedsSessionUserForBrowserSync' => function () {
            $this->assert_AccountAvatarFallback_testAccountIndexConfigEmbedsSessionUserForBrowserSync();
        },
            'AccountChallengeWidget::testChallengeOwnedByCustomerLayoutAndController' => function () {
            $this->assert_AccountChallengeWidget_testChallengeOwnedByCustomerLayoutAndController();
        },
            'AccountLoginPanelOutlinedField::testLoginPanelFieldsUseStandardOutlinedFieldMarkup' => function () {
            $this->assert_AccountLoginPanelOutlinedField_testLoginPanelFieldsUseStandardOutlinedFieldMarkup();
        },
            'AccountLoginWidget::testLoginOwnedByCustomerLayoutAndController' => function () {
            $this->assert_AccountLoginWidget_testLoginOwnedByCustomerLayoutAndController();
        },
            'AccountLogoutConfirmFallback::testLogoutConfirmUsesThemeDialogNotNativeConfirm' => function () {
            $this->assert_AccountLogoutConfirmFallback_testLogoutConfirmUsesThemeDialogNotNativeConfirm();
        },
            'AccountModuleFull::testAccountModulePublishesTheFullLoaderContract' => function () {
            $this->assert_AccountModuleFull_testAccountModulePublishesTheFullLoaderContract();
        },
            'AccountModuleFull::testFrontendWelineAccountProxyExposesAuthRefreshSignal' => function () {
            $this->assert_AccountModuleFull_testFrontendWelineAccountProxyExposesAuthRefreshSignal();
        },
            'AccountPathLayoutsSlot::testPathLayoutsDeclareContentSlotsWithoutFetchingAuthShell' => function () {
            $this->assert_AccountPathLayoutsSlot_testPathLayoutsDeclareContentSlotsWithoutFetchingAuthShell();
        },
            'AccountPathLayoutsSlot::testAuthFamilyLayoutsKeepAccountAuthContentSlot' => function () {
            $this->assert_AccountPathLayoutsSlot_testAuthFamilyLayoutsKeepAccountAuthContentSlot();
        },
            'AccountPathLayoutsSlot::testLoginLayoutRendersCustomerOwnedAuthenticationContent' => function () {
            $this->assert_AccountPathLayoutsSlot_testLoginLayoutRendersCustomerOwnedAuthenticationContent();
        },
            'AccountPathLayoutsSlot::testLoginTemplateKeepsSocialProvidersSlot' => function () {
            $this->assert_AccountPathLayoutsSlot_testLoginTemplateKeepsSocialProvidersSlot();
        },
            'AccountPathLayoutsSlot::testLoginStageRendersOnlyItsOwnAuthenticationWidget' => function () {
            $this->assert_AccountPathLayoutsSlot_testLoginStageRendersOnlyItsOwnAuthenticationWidget();
        },
            'AccountPathLayoutsSlot::testHanfuLoginStageRendersOnlyItsOwnAuthenticationWidget' => function () {
            $this->assert_AccountPathLayoutsSlot_testHanfuLoginStageRendersOnlyItsOwnAuthenticationWidget();
        },
            'AccountRegisterWidget::testRegisterOwnedByCustomerLayout' => function () {
            $this->assert_AccountRegisterWidget_testRegisterOwnedByCustomerLayout();
        },
            'AccountSessionModule::testCustomerRegistersAccountModule' => function () {
            $this->assert_AccountSessionModule_testCustomerRegistersAccountModule();
        },
            'AccountSessionModule::testFrontendNoLongerOwnsAccountScriptOrRegistration' => function () {
            $this->assert_AccountSessionModule_testFrontendNoLongerOwnsAccountScriptOrRegistration();
        },
            'AccountSessionModule::testApplyHeaderSignedInTogglesAvatarImageAndFallback' => function () {
            $this->assert_AccountSessionModule_testApplyHeaderSignedInTogglesAvatarImageAndFallback();
        },
            'AccountSessionModule::testSilentBrowsePaintsFromCacheWithoutNetworkBootstrap' => function () {
            $this->assert_AccountSessionModule_testSilentBrowsePaintsFromCacheWithoutNetworkBootstrap();
        },
            'AccountSessionModule::testAuthPagesForceNetworkAndLeaveWhenSignedIn' => function () {
            $this->assert_AccountSessionModule_testAuthPagesForceNetworkAndLeaveWhenSignedIn();
        },
            'AccountSessionModule::testLogoutAuthSignalClearsSessionCacheBeforeNetwork' => function () {
            $this->assert_AccountSessionModule_testLogoutAuthSignalClearsSessionCacheBeforeNetwork();
        },
            'AccountSocialBindingsSidebar::testConnectionsGroupAndContentHooksExposeSocialLoginSection' => function () {
            $this->assert_AccountSocialBindingsSidebar_testConnectionsGroupAndContentHooksExposeSocialLoginSection();
        },
            'AccountSocialLoginWidget::testLoginFormExposesSocialProvidersSlotWithoutSiblingFetch' => function () {
            $this->assert_AccountSocialLoginWidget_testLoginFormExposesSocialProvidersSlotWithoutSiblingFetch();
        },
            'AccountSocialPlacement::testNativeSocialFormHasOnlyLayoutPlacement' => function () {
            $this->assert_AccountSocialPlacement_testNativeSocialFormHasOnlyLayoutPlacement();
        },
            'AuthTemplateLocaleRedirect::testForgotPasswordTemplateUsesUrlTagsForAllAddresses' => function () {
            $this->assert_AuthTemplateLocaleRedirect_testForgotPasswordTemplateUsesUrlTagsForAllAddresses();
        },
            'AuthTemplateLocaleRedirect::testLoginTemplateUsesUrlTagsForRegisterForgotAndSubmit' => function () {
            $this->assert_AuthTemplateLocaleRedirect_testLoginTemplateUsesUrlTagsForRegisterForgotAndSubmit();
        },
            'CheckoutSuccessGuestConvertWidget::testWidgetRegistersDefaultInjectionIntoCheckoutSuccessSlot' => function () {
            $this->assert_CheckoutSuccessGuestConvertWidget_testWidgetRegistersDefaultInjectionIntoCheckoutSuccessSlot();
        },
            'CheckoutSuccessGuestConvertWidget::testWidgetHidesWhenOrderAlreadyBound' => function () {
            $this->assert_CheckoutSuccessGuestConvertWidget_testWidgetHidesWhenOrderAlreadyBound();
        },
            'CheckoutSuccessGuestConvertWidget::testCheckoutSuccessExposesGuestAccountSlotWithoutCustomerBusiness' => function () {
            $this->assert_CheckoutSuccessGuestConvertWidget_testCheckoutSuccessExposesGuestAccountSlotWithoutCustomerBusiness();
        },
            'CustomerMountAttribute::testAccountProvidesLoginPanelAndSocialQuickSurfaces' => function () {
            $this->assert_CustomerMountAttribute_testAccountProvidesLoginPanelAndSocialQuickSurfaces();
        },
            'FooterHelpLinksWidget::testFooterMyAccountLinkRegistersDefaultInjection' => function () {
            $this->assert_FooterHelpLinksWidget_testFooterMyAccountLinkRegistersDefaultInjection();
        },
            'FooterHelpLinksWidget::testFooterMyOrdersLinkRegistersDefaultInjection' => function () {
            $this->assert_FooterHelpLinksWidget_testFooterMyOrdersLinkRegistersDefaultInjection();
        },
            'FooterHelpLinksWidget::testFooterHelpLinkTemplatesPointToAccount' => function () {
            $this->assert_FooterHelpLinksWidget_testFooterHelpLinkTemplatesPointToAccount();
        },
            'FooterSocialLoginLinkWidget::testFooterSocialLoginLinkRegistersDefaultInjection' => function () {
            $this->assert_FooterSocialLoginLinkWidget_testFooterSocialLoginLinkRegistersDefaultInjection();
        },
            'FooterSocialLoginLinkWidget::testFooterSocialLoginLinkTemplatePointsToGuide' => function () {
            $this->assert_FooterSocialLoginLinkWidget_testFooterSocialLoginLinkTemplatePointsToGuide();
        },
            'FooterSocialLoginLinkWidget::testThemeEnUsCsvTranslatesFooterSocialLoginLabel' => function () {
            $this->assert_FooterSocialLoginLinkWidget_testThemeEnUsCsvTranslatesFooterSocialLoginLabel();
        },
            'SafeStorefrontDestinationLocalePrefix::testAccountLoginJsTreatsLocaleQueryAsAlreadyPrefixed' => function () {
            $this->assert_SafeStorefrontDestinationLocalePrefix_testAccountLoginJsTreatsLocaleQueryAsAlreadyPrefixed();
        },
            'SafeStorefrontDestinationLocalePrefix::testAccountRecoveryJsTreatsLocaleQueryAsAlreadyPrefixed' => function () {
            $this->assert_SafeStorefrontDestinationLocalePrefix_testAccountRecoveryJsTreatsLocaleQueryAsAlreadyPrefixed();
        },
            'SocialLoginChooseBindHint::testChooseTemplateDistinguishesLocalAccountFromSocialEmail' => function () {
            $this->assert_SocialLoginChooseBindHint_testChooseTemplateDistinguishesLocalAccountFromSocialEmail();
        },
            'SocialLoginChooseBindHint::testChooseControllerAvoidsPrefillingMissingLocalEmail' => function () {
            $this->assert_SocialLoginChooseBindHint_testChooseControllerAvoidsPrefillingMissingLocalEmail();
        },
            'SocialLoginSystemConfigActivation::testSocialLoginTemplateDeclaresEnabledSwitchesAndCredentials' => function () {
            $this->assert_SocialLoginSystemConfigActivation_testSocialLoginTemplateDeclaresEnabledSwitchesAndCredentials();
        },
            'SocialLoginSystemConfigActivation::testSocialLoginTemplateExposesSetupGuideAdapters' => function () {
            $this->assert_SocialLoginSystemConfigActivation_testSocialLoginTemplateExposesSetupGuideAdapters();
        },
        ];
    }

private function assert_AccountAuthCopyI18n_testLoginTemplateTranslatesWidgetHeadingAndSubtitle(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/account/login.phtml');
    self::assertStringContainsString('$safe(__($heading))', $source);
    self::assertStringContainsString('$safe(__($subheading))', $source);
    self::assertDoesNotMatchRegularExpression('/w-auth-login__title[\s\S]{0,200}\$safe\(\$heading\)/', $source);
    self::assertDoesNotMatchRegularExpression('/w-auth-login__subtitle[\s\S]{0,200}\$safe\(\$subheading\)/', $source);
}

private function assert_AccountAuthCopyI18n_testRegisterTemplateTranslatesWidgetHeadingAndSubtitle(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/account/register.phtml');
    self::assertStringContainsString('$safe(__($heading))', $source);
    self::assertStringContainsString('$safe(__($subheading))', $source);
}

private function assert_AccountAuthCopyI18n_testAccountLoginWidgetTranslatesConfigurableCopy(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/widgets/form/account-login/default.phtml');
    // Defaults stay on <lang> (compile-time locale bake); only customized copy uses __().
    self::assertStringContainsString("\$title !== '登录'", $source);
    self::assertStringContainsString("setData('login_widget_title', (string)__(\$title))", $source);
    self::assertStringContainsString('<lang>欢迎回来</lang>', $source);
    self::assertStringContainsString('<lang>登录后继续浏览优惠与订单</lang>', $source);
    self::assertStringContainsString('<lang>安全登录 · 订单与优惠同步</lang>', $source);
}

private function assert_AccountAuthCopyI18n_testAccountRegisterWidgetTranslatesConfigurableCopy(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/widgets/form/account-register/default.phtml');
    self::assertStringContainsString("\$title !== '创建账户'", $source);
    self::assertStringContainsString("setData('register_widget_title', (string)__(\$title))", $source);
    self::assertStringContainsString('<lang>加入我们</lang>', $source);
}

private function assert_AccountAvatarFallback_testAccountIndexSsrKeepsInitialFallbackVisible(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/account/index.phtml');
    self::assertStringContainsString('id="avatarPreview"', $source);
    self::assertStringContainsString('id="avatarFallback"', $source);
    self::assertStringContainsString('htmlspecialchars($avatarInitial)', $source);
    self::assertStringNotContainsString('<var>avatarInitial</var>', $source);
    self::assertDoesNotMatchRegularExpression('/id="avatarPreview"[^>]*class="account-index__avatar-img"<\?= \$avatar \? \'\' : \' hidden\' \?>/', $source);
    self::assertStringContainsString('class="account-index__avatar-img" hidden', $source);
    self::assertStringNotContainsString("id=\"avatarFallback\"<?= \$avatar ? ' hidden' : '' ?>", $source);
}

private function assert_AccountAvatarFallback_testSidebarSsrKeepsInitialFallbackVisible(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/account/sidebar/side.phtml');
    self::assertStringContainsString('id="sidebarAvatarPreview"', $source);
    self::assertStringContainsString('id="sidebarAvatarFallback"', $source);
    self::assertStringContainsString('htmlspecialchars($avatarInitial)', $source);
    self::assertStringContainsString('class="account-sidebar__avatar-img" hidden', $source);
    self::assertStringNotContainsString("id=\"sidebarAvatarFallback\"<?= \$avatar ? ' hidden' : '' ?>", $source);
}

private function assert_AccountAvatarFallback_testAccountIndexJsDoesNotHideFallbackBeforePhotoLoads(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/statics/js/account-index.js');
    self::assertStringContainsString('showAvatarFallback', $js);
    self::assertStringContainsString('target.image.onload', $js);
    self::assertStringContainsString('target.fallback.hidden = false', $js);
    self::assertStringContainsString('2500', $js);
    self::assertStringNotContainsString("target.fallback.hidden = true;\n                target.image.src = avatarUrl", $js);
    // SSR avatar seeds trusted browser session so other signed-in pages paint header locally.
    self::assertStringContainsString('seedSessionAvatarFromPage', $js);
    self::assertStringContainsString('applyFrontendProfileUpdate', $js);
    self::assertStringContainsString('isBrowserSignedInSnapshot', $js);
    self::assertStringContainsString('syncPersonalCenterBrowserSession', $js);
    self::assertStringContainsString('syncSessionAtPersonalCenter', $js);
    // Parallel attribute load race: wait for account module before header sync.
    self::assertStringContainsString("Weline.load('account')", $js);
    self::assertStringContainsString('ready.__full === true', $js);
}

private function assert_AccountAvatarFallback_testAccountIndexConfigEmbedsSessionUserForBrowserSync(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/account/index.phtml');
    self::assertStringContainsString("'sessionUser'", $source);
    self::assertStringContainsString('getDisplayName', $source);
}

private function assert_AccountChallengeWidget_testChallengeOwnedByCustomerLayoutAndController(): void
{
    $challengeForm = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/account/challenge.phtml';
    $themeWidget = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/widgets/form/account-challenge/default.phtml';
    $challengeLayout = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/theme/frontend/layouts/account/challenge.phtml';
    self::assertFileExists($challengeForm);
    self::assertFileExists($themeWidget);
    self::assertFileExists($challengeLayout);
    $formSource = (string) \file_get_contents($challengeForm);
    self::assertMatchesRegularExpression('/data-w-component\s*=\s*["\']account-challenge["\']/', $formSource);
    self::assertStringContainsString('data-w-challenge-form', $formSource);
    self::assertStringContainsString('w-auth-login--classic', $formSource);
    self::assertStringNotContainsString('linear-gradient(135deg, var(--color-primary)', $formSource);
    self::assertStringNotContainsString('AccountApi.completeChallenge', $formSource);
    self::assertStringContainsString('data-w-challenge-idle-label', $formSource);
    self::assertStringContainsString('data-w-challenge-submit', $formSource);
    self::assertStringContainsString('data-w-challenge-token', $formSource);
    self::assertStringContainsString('data-challenge-token', $formSource);
    self::assertStringContainsString("getParam('challenge_token')", $formSource);
    $layoutSource = (string) \file_get_contents($challengeLayout);
    self::assertStringNotContainsString('foreign-theme-account-challenge', $layoutSource);
    self::assertStringNotContainsString('<w:widget type="form" name="account-challenge"', $layoutSource);
    self::assertStringContainsString("Weline_Customer::templates/frontend/account/challenge.phtml", $layoutSource);
    self::assertStringContainsString('{{meta.content}}', $layoutSource);
    self::assertStringContainsString('account-challenge-layout', $layoutSource);
    self::assertStringNotContainsString('#232f3e', $layoutSource);
    $themeSource = (string) \file_get_contents($themeWidget);
    self::assertStringContainsString('@widget.code {account-challenge}', $themeSource);
    self::assertStringContainsString('Weline_Customer::templates/frontend/account/challenge.phtml', $themeSource);
    $widgetPhp = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/extends/module/Weline_Widget/Weline_Theme/widget.php');
    self::assertStringContainsString('account-challenge/default.phtml', $widgetPhp);
    self::assertStringNotContainsString('foreign-theme-account-challenge', $widgetPhp);
    $controller = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Controller/Account/Challenge.php');
    self::assertStringContainsString("layoutType = 'account.challenge'", $controller);
    self::assertStringContainsString('Weline_Customer::templates/frontend/account/challenge.phtml', $controller);
    self::assertStringNotContainsString('challenge-shell.phtml', $controller);
    $challengeJs = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/pages/weline-customer-account-challenge.js');
    self::assertStringContainsString("UI.define('account-challenge'", $challengeJs);
    self::assertStringContainsString('fetch(action', $challengeJs);
    self::assertStringContainsString("credentials: 'same-origin'", $challengeJs);
    self::assertStringNotContainsString('completeChallenge', $challengeJs);
    $loginCss = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/pages/weline-customer-account-login.css');
    self::assertStringContainsString('.w-auth-login--classic .w-auth-login__submit [data-w-challenge-idle-label]', $loginCss);
    self::assertStringContainsString('.w-auth-login--classic .w-auth-login__submit [data-w-challenge-busy-label]', $loginCss);
    self::assertMatchesRegularExpression('/\.w-auth-login--classic \.w-auth-login__submit\s*\{[^}]*display:\s*inline-flex;[^}]*align-items:\s*center;/s', $loginCss);
    $welineUi = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/weline-ui.js');
    self::assertMatchesRegularExpression("/\\['account-challenge',\\s*'\\.\\/pages\\/weline-customer-account-challenge\\.js/", $welineUi);
    self::assertMatchesRegularExpression("/\\['account-challenge',\\s*'\\.\\/pages\\/weline-customer-account-login\\.css/", $welineUi);
}

private function assert_AccountLoginPanelOutlinedField_testLoginPanelFieldsUseStandardOutlinedFieldMarkup(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/statics/js/account-login-panel.js';
    $src = (string) file_get_contents($path);
    self::assertNotSame('', $src);
    self::assertStringContainsString("class=\"w-field__label\"", $src);
    self::assertStringContainsString('<div class="w-field"><label class="w-field__label">\' + userLabel', $src);
    self::assertStringContainsString('<div class="w-field"><label class="w-field__label">\' + passLabel', $src);
    self::assertStringNotContainsString('data-label-layout="stacked"', $src);
    self::assertStringNotContainsString('border-radius:999px', $src);
    self::assertStringNotContainsString('<div class="w-field"><label class="w-label">\' + userLabel', $src);
    self::assertStringNotContainsString('<label class="w-label" style="margin:0">\' + rememberLabel', $src);
    self::assertStringNotContainsString('<label class="weline-login-panel__remember">\' + rememberLabel', $src);
    self::assertStringContainsString('<div class="w-field weline-login-panel__remember">', $src);
    self::assertStringContainsString('<label class="w-field__label" for="weline-login-panel-remember">', $src);
    self::assertStringContainsString('data-icon="clock"', $src);
    self::assertStringContainsString('rememberLabel + \'</label>\'', $src);
    self::assertStringContainsString('weline-login-panel__remember', $src);
    self::assertStringContainsString('weline-login-panel__meta-tools', $src);
    self::assertStringContainsString('min-height:var(--weline-control-height-sm,1.75rem)', $src);
    self::assertStringContainsString("data-weline-login-panel-heading", $src);
    self::assertStringNotContainsString('display:inline-flex;align-items:center;gap:6px', $src);
    self::assertStringContainsString('hideHeading', $src);
    self::assertStringContainsString('w-auth-login__forgot-link', $src);
    self::assertStringContainsString('white-space:nowrap', $src);
    self::assertStringContainsString('withAuthRefreshSignal', $src);
    self::assertStringContainsString("searchParams.set('w_auth', '1')", $src);
    self::assertStringContainsString('markAuthPending', $src);
}

private function assert_AccountLoginWidget_testLoginOwnedByCustomerLayoutAndController(): void
{
    $loginForm = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/account/login.phtml';
    $loginLayout = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/theme/frontend/layouts/account/login/default.phtml';
    $authLayout = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/theme/frontend/layouts/account/auth.phtml';
    $customerWidgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
    $themeWidget = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/widgets/form/account-login/default.phtml';
    self::assertFileExists($loginForm);
    self::assertFileExists($loginLayout);
    self::assertFileExists($authLayout);
    self::assertFileExists($customerWidgetPhp);
    self::assertFileExists($themeWidget);
    $loginFormSource = (string) \file_get_contents($loginForm);
    self::assertMatchesRegularExpression('/data-w-component\s*=\s*["\']account-login["\']/', $loginFormSource);
    self::assertStringContainsString('data-weline-load="api,account"', $loginFormSource);
    self::assertStringContainsString('account-login-social-providers', $loginFormSource);
    $customerWidgetSource = (string) \file_get_contents($customerWidgetPhp);
    self::assertStringContainsString("'account-social-login'", $customerWidgetSource);
    self::assertStringContainsString('account-login-social-providers', $customerWidgetSource);
    foreach ([$loginLayout, $authLayout] as $layoutPath) {
        $layoutSource = (string) \file_get_contents($layoutPath);
        self::assertStringNotContainsString('foreign-theme-account-login', $layoutSource);
        self::assertStringNotContainsString('<w:widget type="form" name="account-login"', $layoutSource);
        self::assertStringContainsString("Weline_Customer::templates/frontend/account/login.phtml", $layoutSource);
        self::assertStringContainsString('{{meta.content}}', $layoutSource);
    }
    $loginLayoutSource = (string) \file_get_contents($loginLayout);
    self::assertStringContainsString('account/login', $loginLayoutSource);
    self::assertStringContainsString('__force_login_stage', $loginLayoutSource);
    // Theme 舞台部件可保留作编辑器可选，但不得再声明 required default_injections。
    $themeSource = (string) \file_get_contents($themeWidget);
    self::assertStringContainsString('@widget.code {account-login}', $themeSource);
    self::assertStringContainsString('Weline_Customer::templates/frontend/account/login.phtml', $themeSource);
    $widgetPhp = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/extends/module/Weline_Widget/Weline_Theme/widget.php');
    self::assertStringContainsString('account-login/default.phtml', $widgetPhp);
    self::assertStringNotContainsString('foreign-theme-account-login', $widgetPhp);
    $loginController = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Controller/Account/Login.php');
    self::assertStringContainsString("layoutType = 'account/login'", $loginController);
    self::assertStringContainsString('Weline_Customer::templates/frontend/account/login.phtml', $loginController);
    self::assertStringNotContainsString('login-shell.phtml', $loginController);
}

private function assert_AccountLogoutConfirmFallback_testLogoutConfirmUsesThemeDialogNotNativeConfirm(): void
{
    $file = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/statics/js/account-logout.js';
    self::assertFileExists($file);
    $content = (string) file_get_contents($file);
    self::assertStringContainsString('function getThemeNoticeApi()', $content);
    self::assertStringContainsString('function getThemeUiDialogConfirm()', $content);
    self::assertStringContainsString('Weline.UI.dialog.confirm', $content);
    self::assertStringContainsString('never use window.confirm', $content);
    self::assertStringNotContainsString('window.confirm(', $content);
    self::assertStringNotContainsString('Notice confirmation is unavailable; logout cancelled.', $content);
    self::assertStringContainsString('clearFrontendSessionCache', $content);
    self::assertStringContainsString('weline_frontend_session_user', $content);
    self::assertStringContainsString('weline:account:frontend:logout', $content);
}

private function assert_AccountModuleFull_testAccountModulePublishesTheFullLoaderContract(): void
{
    $moduleFile = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Frontend/view/statics/js/weline-api-account.js';
    self::assertFileExists($moduleFile);
    $content = (string) file_get_contents($moduleFile);
    self::assertStringContainsString("const AccountModule = {\n        __full: true,", $content);
    self::assertStringContainsString('window.WelineAccountModule = AccountModule;', $content);
    self::assertStringContainsString('handleAuthRefreshSignal: () => accountManager.handleAuthRefreshSignal()', $content);
    self::assertStringContainsString('startOnlineKeepalive: () => accountManager.startOnlineKeepalive()', $content);
    self::assertStringContainsString('bootstrapHeaderAuthRefresh', $content);
    self::assertStringContainsString('bootstrapOnlineKeepalive', $content);
    self::assertStringContainsString('navigator.onLine', $content);
    self::assertStringContainsString("data-auth-state') === 'guest'", $content);
    self::assertStringContainsString('applyHeaderSignedIn', $content);
    self::assertStringContainsString('resolveHeaderDisplayName', $content);
    self::assertStringContainsString('user.display_name', $content);
    self::assertStringContainsString('applyHeaderMenuAuth', $content);
    self::assertStringContainsString('data-account-menu-auth', $content);
    self::assertStringContainsString('syncHeaderAccountChrome', $content);
    self::assertStringContainsString('headerMenuAuthMismatch', $content);
    self::assertStringContainsString('weline:account:frontend:login', $content);
}

private function assert_AccountModuleFull_testFrontendWelineAccountProxyExposesAuthRefreshSignal(): void
{
    $welineFile = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Frontend/view/statics/js/weline.js';
    self::assertFileExists($welineFile);
    $content = (string) file_get_contents($welineFile);
    self::assertStringContainsString('handleAuthRefreshSignal: async () => {', $content);
    self::assertStringContainsString("moduleLoader.loadModule('account')", $content);
}

/** @return list<string> */
private function pathActions_AccountPathLayoutsSlot(): array
{
    return ['login', 'register', 'forgot-password', 'set-password', 'social-login', 'logout', 'orders', 'profile'];
}

private function assert_AccountPathLayoutsSlot_testPathLayoutsDeclareContentSlotsWithoutFetchingAuthShell(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/theme/frontend/layouts/account';
    foreach ($this->pathActions_AccountPathLayoutsSlot() as $action) {
        $path = $base . '/' . $action . '/default.phtml';
        self::assertFileExists($path, $action);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('<w:slot id="content"', $src, $action . ' must declare content slot in-file');
        self::assertStringNotContainsString("fetch('Weline_Customer::theme/frontend/layouts/account/auth.phtml')", $src, $action . ' must not thin-wrap auth shell');
        self::assertStringNotContainsString('fetch("Weline_Customer::theme/frontend/layouts/account/auth.phtml")', $src, $action . ' must not thin-wrap auth shell');
    }
}

private function assert_AccountPathLayoutsSlot_testAuthFamilyLayoutsKeepAccountAuthContentSlot(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/theme/frontend/layouts/account';
    foreach (['login', 'register', 'forgot-password', 'set-password', 'social-login'] as $action) {
        $src = (string) file_get_contents($base . '/' . $action . '/default.phtml');
        self::assertStringContainsString('<w:slot id="account-auth-content"', $src, $action . ' must keep account-auth-content slot');
        self::assertStringContainsString('seo::footer', $src, $action);
    }
}

private function assert_AccountPathLayoutsSlot_testLoginLayoutRendersCustomerOwnedAuthenticationContent(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/theme/frontend/layouts/account/login/default.phtml');
    self::assertStringNotContainsString('foreign-theme-account-login', $src);
    self::assertStringNotContainsString('foreign-theme-account-register', $src);
    self::assertStringNotContainsString('<w:widget type="form" name="account-login"', $src);
    self::assertStringNotContainsString('<w:widget type="form" name="account-register"', $src);
    self::assertStringContainsString('{{meta.content}}', $src);
    self::assertStringContainsString("Weline_Customer::templates/frontend/account/login.phtml", $src);
    self::assertStringContainsString("__force_login_stage'] = true", $src);
}

private function assert_AccountPathLayoutsSlot_testLoginTemplateKeepsSocialProvidersSlot(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/account/login.phtml');
    self::assertStringContainsString('<w:slot id="account-login-social-providers"', $src);
}

private function assert_AccountPathLayoutsSlot_testLoginStageRendersOnlyItsOwnAuthenticationWidget(): void
{
    $this->assertLoginStageHasOneAuthenticationWidget_AccountPathLayoutsSlot('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/theme/frontend/layouts/account/login/default.phtml');
}

private function assert_AccountPathLayoutsSlot_testHanfuLoginStageRendersOnlyItsOwnAuthenticationWidget(): void
{
    $path = '/Users/weline/Project/Official/框架' . '/app/design/Weline/hanfu/frontend/layouts/account/login/default.phtml';
    if (!is_file($path)) {
        self::markTestSkipped('Hanfu design theme is installed separately from the framework repository.');
    }
    $this->assertLoginStageHasOneAuthenticationWidget_AccountPathLayoutsSlot($path);
}

private function assertLoginStageHasOneAuthenticationWidget_AccountPathLayoutsSlot(string $path): void
{
    $src = (string) file_get_contents($path);
    $start = strpos($src, '<main ');
    $end = strpos($src, '</main>', $start);
    self::assertNotFalse($start);
    self::assertNotFalse($end);
    $stage = substr($src, $start, $end + strlen('</main>') - $start);
    self::assertStringNotContainsString('foreign-theme-account-', $stage, $path);
    self::assertStringNotContainsString('<w:widget type="form" name="account-login"', $stage, $path);
    self::assertStringContainsString("Weline_Customer::templates/frontend/account/login.phtml", $stage, $path . ': login stage must fall back to Customer login.phtml');
}

private function assert_AccountRegisterWidget_testRegisterOwnedByCustomerLayout(): void
{
    $registerForm = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/account/register.phtml';
    $themeWidget = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/widgets/form/account-register/default.phtml';
    $authLayout = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/theme/frontend/layouts/account/auth.phtml';
    $registerLayout = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/theme/frontend/layouts/account/register/default.phtml';
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/extends/module/Weline_Widget/Weline_Theme/widget.php';
    self::assertFileExists($registerForm);
    self::assertFileExists($themeWidget);
    self::assertFileExists($authLayout);
    self::assertFileExists($registerLayout);
    self::assertFileExists($widgetPhp);
    $formSource = (string) \file_get_contents($registerForm);
    self::assertStringContainsString('w-auth-login--classic', $formSource);
    self::assertStringContainsString('data-w-component="account-register"', $formSource);
    self::assertStringContainsString('data-customer-register-form', $formSource);
    self::assertStringNotContainsString('auth-form__icon', $formSource);
    foreach ([$authLayout, $registerLayout] as $layoutPath) {
        $layoutSource = (string) \file_get_contents($layoutPath);
        self::assertStringNotContainsString('foreign-theme-account-register', $layoutSource);
        self::assertStringNotContainsString('<w:widget type="form" name="account-register"', $layoutSource);
        self::assertStringContainsString("Weline_Customer::templates/frontend/account/register.phtml", $layoutSource);
        self::assertStringContainsString('{{meta.content}}', $layoutSource);
    }
    $themeSource = (string) \file_get_contents($themeWidget);
    self::assertStringContainsString('@widget.code {account-register}', $themeSource);
    self::assertStringContainsString('Weline_Customer::templates/frontend/account/register.phtml', $themeSource);
    $widgetSource = (string) \file_get_contents($widgetPhp);
    self::assertStringContainsString('account-register/default.phtml', $widgetSource);
    self::assertStringNotContainsString('foreign-theme-account-register', $widgetSource);
}

private function accountJs_AccountSessionModule(): string
{
    return (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/statics/js/account-session.js');
}

private function assert_AccountSessionModule_testCustomerRegistersAccountModule(): void
{
    $modules = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/statics/frontend/weline.modules.js');
    self::assertStringContainsString('account:', $modules);
    self::assertStringContainsString('Weline_Customer::js/account-session.js', $modules);
    self::assertStringContainsString('globalVar: "WelineAccountModule"', $modules);
    self::assertStringContainsString('load: "defer"', $modules);
}

private function assert_AccountSessionModule_testFrontendNoLongerOwnsAccountScriptOrRegistration(): void
{
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline' . '/Frontend/view/statics/js/weline-api-account.js');
    $frontendModules = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Frontend/view/statics/frontend/weline.modules.js');
    self::assertStringNotContainsString('welineApiAccount', $frontendModules);
    self::assertStringNotContainsString('weline-api-account.js', $frontendModules);
    self::assertStringNotContainsString('account: "welineApiAccount"', $frontendModules);
}

private function assert_AccountSessionModule_testApplyHeaderSignedInTogglesAvatarImageAndFallback(): void
{
    $js = $this->accountJs_AccountSessionModule();
    self::assertStringContainsString('data-account-avatar-fallback', $js);
    self::assertStringContainsString('paintHeaderAccountAvatars', $js);
    self::assertStringContainsString('data-account-avatar-wrap', $js);
    self::assertStringContainsString('avatar.hidden = false', $js);
    self::assertStringContainsString('avatarFallback.hidden = true', $js);
    self::assertStringContainsString('avatar.removeAttribute(\'src\')', $js);
    self::assertStringContainsString('setAccountAvatarFallbackLabel', $js);
    self::assertStringContainsString('data-account-avatar-initial', $js);
    self::assertStringContainsString('data-has-initial', $js);
    // Header chrome: eager load + complete cache reveal (lazy+[hidden] never fetches).
    self::assertStringContainsString("setAttribute('loading', 'eager')", $js);
    self::assertStringContainsString('avatar.complete && avatar.naturalWidth > 0', $js);
    self::assertStringContainsString('data:image/', $js);
    self::assertStringNotContainsString("setAttribute('loading', 'lazy')", $js);
    self::assertStringContainsString('maybeStartSocialQuickPrompt', $js);
    self::assertStringContainsString('socialQuickPrompt', $js);
    self::assertStringContainsString('customerSocialQuick', $js);
    self::assertStringContainsString('suppressed_login_widget', $js);
}

private function assert_AccountSessionModule_testSilentBrowsePaintsFromCacheWithoutNetworkBootstrap(): void
{
    $js = $this->accountJs_AccountSessionModule();
    self::assertStringContainsString('frontendSessionUserKey', $js);
    self::assertStringContainsString('weline_frontend_session_user', $js);
    self::assertStringContainsString('paintOnly', $js);
    self::assertStringContainsString("'paint_only'", $js);
    self::assertStringContainsString('isThemeEditorPreview', $js);
    self::assertStringContainsString('handleAuthRefreshSignal()', $js);
    self::assertStringContainsString('ensureLogin', $js);
    self::assertStringContainsString('customer/login-panel', $js);
    // Bootstrap uses handleAuthRefreshSignal (paint-only unless editor/w_auth/auth page).
    self::assertStringContainsString('accountManager.handleAuthRefreshSignal()', $js);
    self::assertStringNotContainsString('bootstrapOnlineKeepalive', $js);
    self::assertStringNotContainsString("reason: 'already_aligned'", $js);
    // Silent keepalive disabled; near-expiry one-shot JS check only.
    self::assertStringContainsString('Silent keepalive disabled', $js);
    self::assertStringContainsString('isNearSessionExpiry', $js);
    self::assertStringContainsString('scheduleNearExpirySessionCheck', $js);
    self::assertStringContainsString('renewSessionNearExpiry', $js);
    self::assertStringContainsString('临近退出', $js);
    self::assertStringContainsString('fromAuthSignal: true', $js);
    self::assertStringContainsString('isLogoutAuthSignal', $js);
    self::assertStringContainsString('skipGuestNegativeCache', $js);
    self::assertStringContainsString('optimistic_keep_login_signal', $js);
    self::assertStringContainsString('isTrustedSignedInCache', $js);
    self::assertStringContainsString('isBrowserSignedInSnapshot', $js);
    self::assertStringContainsString('syncSessionAtPersonalCenter', $js);
    self::assertStringContainsString('preserved: true', $js);
    self::assertStringContainsString('skipGuestNegativeCache: true', $js);
    self::assertStringContainsString('must NOT bounce to login', $js);
    self::assertStringContainsString('个人中心由 account-index.js 同步会话', $js);
    // Seed SSR sessionUser on personal center before paintOnly (parallel-load race belt).
    self::assertStringContainsString('seedPersonalCenterSessionFromSsr', $js);
    self::assertStringContainsString('weline-account-index-config', $js);
    self::assertStringNotContainsString('isStorefrontAccountArea', $js);
    self::assertStringNotContainsString('onAccountArea', $js);
    self::assertStringNotContainsString('bindAccountChromeInteraction', $js);
    self::assertStringNotContainsString('reconcileSignedInChromeNavigation', $js);
    // Signed-in browser snapshot skips account.current; force only at allowed stages.
    self::assertStringContainsString('forceNetwork', $js);
    self::assertStringContainsString('opts.force === true', $js);
    self::assertStringNotContainsString('opts.force !== false', $js);
    self::assertStringContainsString('paintHeaderGuestChrome', $js);
    self::assertStringContainsString('resolveUserIdentity(rawUser)', $js);
    self::assertStringContainsString('guest current returns', $js);
    self::assertStringContainsString('success:true with isLogin:false', $js);
    self::assertStringContainsString('result.isLogin || result.logged_in', $js);
    self::assertStringContainsString('&& this.resolveUserIdentity(rawUser)', $js);
    self::assertStringNotContainsString('result.isLogin || result.logged_in || result.success', $js);
    self::assertStringNotContainsString('guest current uses success:false', $js);
}

private function assert_AccountSessionModule_testAuthPagesForceNetworkAndLeaveWhenSignedIn(): void
{
    $js = $this->accountJs_AccountSessionModule();
    self::assertStringContainsString('isStorefrontAuthPage', $js);
    self::assertStringContainsString('leaveAuthPageIfSignedIn', $js);
    self::assertStringContainsString('resolveSignedInLeaveUrl', $js);
    self::assertStringContainsString('customer/account/login', $js);
    self::assertStringContainsString('customer/account/register', $js);
    self::assertStringContainsString('onAuthPage', $js);
    self::assertStringContainsString('applyFrontendSessionSnapshot', $js);
    self::assertMatchesRegularExpression('/leaveAuthPageIfSignedIn\(true\)/', $js);
    self::assertStringContainsString('location.replace', $js);
}

private function assert_AccountSessionModule_testLogoutAuthSignalClearsSessionCacheBeforeNetwork(): void
{
    $js = $this->accountJs_AccountSessionModule();
    self::assertStringContainsString('isLogoutAuthSignal()', $js);
    self::assertStringContainsString('isLoginAuthSignal()', $js);
    self::assertStringContainsString('beginAuthBoundary', $js);
    self::assertStringContainsString('msUntilSessionRenew', $js);
    self::assertStringContainsString('resolveUserIdentity', $js);
    self::assertStringContainsString('prevId === nextId', $js);
    self::assertStringContainsString("get('w_auth') === '0'", $js);
    self::assertStringContainsString("get('w_auth') === '1'", $js);
    self::assertStringContainsString('bindFrontendSessionStorageSync', $js);
    self::assertStringContainsString('applyFrontendProfileUpdate', $js);
    self::assertStringContainsString('applyFrontendSessionSnapshot', $js);
    self::assertStringContainsString("addEventListener('storage'", $js);
}

private function assert_AccountSocialBindingsSidebar_testConnectionsGroupAndContentHooksExposeSocialLoginSection(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Customer';
    $nav = $moduleRoot . '/view/hooks/account.sidebar.group.connections.phtml';
    $content = $moduleRoot . '/view/hooks/account.sidebar.content.phtml';
    $bindings = $moduleRoot . '/view/templates/frontend/account/social-bindings.phtml';
    $choose = $moduleRoot . '/view/templates/frontend/account/social-login-choose.phtml';
    self::assertFileExists($nav);
    self::assertFileExists($content);
    self::assertFileExists($bindings);
    self::assertFileExists($choose);
    $navSource = (string) \file_get_contents($nav);
    self::assertStringContainsString('data-section="social-login"', $navSource);
    self::assertStringContainsString('data-account-nav-parent="connections"', $navSource);
    self::assertStringContainsString('#social-login', $navSource);
    $contentSource = (string) \file_get_contents($content);
    self::assertStringContainsString("forSections('social-login')", $contentSource);
    self::assertStringContainsString('data-account-section="social-login"', $contentSource);
    self::assertStringContainsString('social-bindings.phtml', $contentSource);
    $bindingsSource = (string) \file_get_contents($bindings);
    self::assertStringContainsString('customer/account/social-login/unbind', $bindingsSource);
    self::assertStringContainsString('data-customer-social-bindings', $bindingsSource);
    self::assertStringContainsString('data-customer-social-unbind-confirm', $bindingsSource);
    self::assertStringContainsString('getFormKey', $bindingsSource);
    self::assertStringContainsString('@frontend-url{\'customer/account/social-login/unbind\'}', $bindingsSource);
    self::assertStringContainsString('icon_svg', $bindingsSource);
    self::assertStringContainsString('account-social-bindings.css', $bindingsSource);
    $hookSource = (string) \file_get_contents($content);
    self::assertStringContainsString('getData(\'user\')', $hookSource);
    self::assertStringContainsString('Customer', $hookSource);
    $js = (string) \file_get_contents($moduleRoot . '/view/statics/js/account-index.js');
    self::assertStringContainsString('data-customer-social-unbind-confirm', $js);
    $linker = (string) \file_get_contents($moduleRoot . '/Service/SocialLogin/SocialLoginAccountLinker.php');
    self::assertStringContainsString('isKnown($provider)', $linker);
    self::assertStringContainsString("'icon_svg'", $linker);
    self::assertStringContainsString('当前账户未绑定该社媒提供方', $linker);
    $chooseSource = (string) \file_get_contents($choose);
    self::assertStringContainsString('customer/account/social-login/bind-existing', $chooseSource);
    self::assertStringContainsString('customer/account/social-login/create-new', $chooseSource);
    self::assertStringContainsString('绑定已有账户', $chooseSource);
    self::assertStringContainsString('新建账户', $chooseSource);
}

private function assert_AccountSocialLoginWidget_testLoginFormExposesSocialProvidersSlotWithoutSiblingFetch(): void
{
    $loginForm = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/account/login.phtml';
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
    $widgetTpl = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/widgets/account-social-login.phtml';
    self::assertFileExists($loginForm);
    self::assertFileExists($widgetPhp);
    self::assertFileExists($widgetTpl);
    $loginSource = (string) \file_get_contents($loginForm);
    self::assertStringContainsString('<w:slot id="account-login-social-providers"', $loginSource);
    self::assertStringContainsString('CustomerAuthReturnUrlService', $loginSource);
    self::assertStringContainsString('data-w-auth-return', $loginSource);
    self::assertStringContainsString('w-auth-login__social--quick', $loginSource);
    self::assertStringContainsString('快捷登录', $loginSource);
    self::assertStringContainsString('multiple="false"', $loginSource);
    // Slot-only: do not fetch the widget beside the declared slot (stacked with required injection).
    self::assertStringNotContainsString("fetch('Weline_Customer::templates/frontend/widgets/account-social-login.phtml')", $loginSource);
    self::assertStringNotContainsString('social-login-fetch-error', $loginSource);
    $quickPos = strpos($loginSource, 'w-auth-login__social--quick');
    $formPos = strpos($loginSource, 'id="loginForm"');
    self::assertNotFalse($quickPos);
    self::assertNotFalse($formPos);
    self::assertLessThan($formPos, $quickPos, 'Quick social login must appear above the account password form');
    self::assertStringContainsString('Weline_Customer::frontend::account::login::providers', $loginSource);
    $widgetSource = (string) \file_get_contents($widgetPhp);
    self::assertStringContainsString("'account-social-login'", $widgetSource);
    self::assertStringContainsString("'enable_google'", $widgetSource);
    self::assertStringContainsString("'enable_facebook'", $widgetSource);
    self::assertStringContainsString("'enable_instagram'", $widgetSource);
    self::assertStringContainsString('account-login-social-providers', $widgetSource);
    self::assertStringContainsString('default_injections', $widgetSource);
    self::assertStringContainsString("'placement' => 'injection'", $widgetSource);
    $tpl = (string) \file_get_contents($widgetTpl);
    self::assertStringContainsString("\$this->getData('redirect_url')", $tpl);
    self::assertStringContainsString('CustomerAuthReturnUrlService', $tpl);
    self::assertStringContainsString('getParam(\'redirect_url\')', $tpl);
    self::assertStringContainsString('@widget.code {account-social-login}', $tpl);
    self::assertStringContainsString('data-widget-code="account-social-login"', $tpl);
    self::assertStringContainsString('data-w-component="account-social-login"', $tpl);
    self::assertStringContainsString('enable_google', $tpl);
    self::assertStringContainsString('enable_facebook', $tpl);
    self::assertStringContainsString('enable_instagram', $tpl);
    self::assertStringContainsString('account-social-login__list--logos', $tpl);
    self::assertStringContainsString('account-social-login__btn--logo', $tpl);
    self::assertStringContainsString('aria-label=', $tpl);
    self::assertStringContainsString("button['icon_svg']", $tpl);
    self::assertStringContainsString('role="list"', $tpl);
    self::assertStringNotContainsString('auth-form__submit', $tpl);
    self::assertStringNotContainsString('<ul class="account-social-login__list', $tpl);
    self::assertStringNotContainsString('<w:icon', $tpl);
}

private function assert_AccountSocialPlacement_testNativeSocialFormHasOnlyLayoutPlacement(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
    $tpl = 'Weline_Customer::templates/frontend/widgets/account-social-login.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/widgets/account-social-login.phtml');
    self::assertStringContainsString('@widget.code {account-social-login}', $src);
    self::assertStringContainsString('@widget.placement {layout}', $src);
    self::assertStringContainsString('@widget.default_injections {[]}', $src);
    self::assertStringContainsString('@param enable_google {default=true', $src);
    self::assertStringContainsString('@param enable_facebook {default=true', $src);
    self::assertStringContainsString('@param enable_instagram {default=true', $src);
}

private function authTemplateProvider_AuthTemplateLocaleRedirect(): array
{
    return ['register' => ['register.phtml'], 'login' => ['login.phtml'], 'forgot password' => ['forgot-password.phtml']];
}

private function assert_AuthTemplateLocaleRedirect_testForgotPasswordTemplateUsesUrlTagsForAllAddresses(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/account/forgot-password.phtml');
    self::assertStringContainsString("@url{'customer/account/login'}", $template);
    self::assertStringContainsString("@url{'customer/account/forgot-password'}", $template);
    self::assertStringContainsString("@url{'customer/account/forgot-password/reset-password'}", $template);
    self::assertStringContainsString('name="reset_url"', $template);
    self::assertStringContainsString('data-login-url="@url{\'customer/account/login\'}"', $template);
    self::assertStringNotContainsString('action="/customer/account/forgot-password', $template);
    self::assertStringNotContainsString('href="/customer/account/login"', $template);
}

private function assert_AuthTemplateLocaleRedirect_testLoginTemplateUsesUrlTagsForRegisterForgotAndSubmit(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/account/login.phtml');
    self::assertStringContainsString("action=\"@url{'customer/account/login'}\"", $template);
    self::assertStringContainsString("@url{'customer/account/register'}", $template);
    self::assertStringContainsString("@url{'customer/account/forgot-password'}", $template);
    self::assertStringContainsString("getFormKey('customer/account/login')", $template);
}

private function assert_CheckoutSuccessGuestConvertWidget_testWidgetRegistersDefaultInjectionIntoCheckoutSuccessSlot(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
    $tpl = 'Weline_Customer::templates/frontend/widgets/checkout-success-guest-convert.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/widgets/checkout-success-guest-convert.phtml');
    self::assertStringContainsString('@widget.code {checkout-success-guest-convert}', $src);
    self::assertStringContainsString('@widget.slot {checkout-success-guest-account}', $src);
    self::assertStringContainsString('"layout_type":"checkout"', $src);
    self::assertStringContainsString('"slot":"checkout-success-guest-account"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_CheckoutSuccessGuestConvertWidget_testWidgetHidesWhenOrderAlreadyBound(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/widgets/checkout-success-guest-convert.phtml');
    self::assertStringContainsString("already_bound", $src);
    self::assertStringContainsString('data-testid="checkout-success-guest-convert"', $src);
    self::assertStringContainsString('data-testid="checkout-success-guest-convert-empty"', $src);
    self::assertStringContainsString('hidden', $src);
    self::assertStringContainsString('WidgetHtmlHealthInspector', $src);
    self::assertStringContainsString('convertGuestCheckout', $src);
    self::assertStringContainsString('GuestCheckoutConvertService', $src);
}

private function assert_CheckoutSuccessGuestConvertWidget_testCheckoutSuccessExposesGuestAccountSlotWithoutCustomerBusiness(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Checkout/view/frontend/checkout/success.phtml');
    self::assertStringContainsString('<w:slot id="checkout-success-guest-account"', $src);
    self::assertStringContainsString('weline-code="checkout.success.guest_account"', $src);
    self::assertStringNotContainsString('name="checkout-success-guest-convert"', $src);
    self::assertStringNotContainsString('<w:widget type="content" name="checkout-success-guest-convert"', $src);
    self::assertStringContainsString('Do not nest <w:widget>', $src);
    self::assertStringNotContainsString('GuestCheckoutConvertService', $src);
    self::assertStringNotContainsString('登录并保存订单', $src);
}

private function assert_CustomerMountAttribute_testAccountProvidesLoginPanelAndSocialQuickSurfaces(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Customer';
    self::assertFileDoesNotExist($root . '/Taglib/CustomerMount.php');
    self::assertFileExists($root . '/doc/挂载面.md');
    self::assertFileExists($root . '/view/statics/js/account-login-panel.js');
    $doc = (string) file_get_contents($root . '/doc/挂载面.md');
    self::assertStringContainsString('customer/login-panel', $doc);
    self::assertStringContainsString('customer/social-quick', $doc);
    self::assertStringContainsString('Weline.mount', $doc);
    $session = (string) file_get_contents($root . '/view/statics/js/account-session.js');
    self::assertStringContainsString("provide('customer/login-panel'", $session);
    self::assertStringContainsString("provide('customer/social-quick'", $session);
    self::assertStringContainsString('[data-weline-mount^="customer/"]', $session);
    $panel = (string) file_get_contents($root . '/view/statics/js/account-login-panel.js');
    self::assertStringContainsString("MOUNT_LOGIN_PANEL = 'customer/login-panel'", $panel);
    self::assertStringContainsString('data-w-login-form', $panel);
    self::assertStringContainsString('customer/social-quick', $panel);
    self::assertStringContainsString('weline-login-panel', $panel);
    $modules = (string) file_get_contents($root . '/view/statics/frontend/weline.modules.js');
    self::assertStringContainsString('customerLoginPanel', $modules);
    self::assertStringContainsString('account-login-panel.js', $modules);
}

private function assert_FooterHelpLinksWidget_testFooterMyAccountLinkRegistersDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
    $tpl = 'Weline_Customer::templates/frontend/widgets/footer-my-account-link.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/widgets/footer-my-account-link.phtml');
    self::assertStringContainsString('@widget.code {footer-my-account-link}', $src);
    self::assertStringContainsString('@widget.slot {footer-help-links}', $src);
    self::assertStringContainsString('"layout_type":"homepage"', $src);
    self::assertStringContainsString('"slot":"footer-help-links"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_FooterHelpLinksWidget_testFooterMyOrdersLinkRegistersDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
    $tpl = 'Weline_Customer::templates/frontend/widgets/footer-my-orders-link.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/widgets/footer-my-orders-link.phtml');
    self::assertStringContainsString('@widget.code {footer-my-orders-link}', $src);
    self::assertStringContainsString('@widget.slot {footer-help-links}', $src);
    self::assertStringContainsString('"slot":"footer-help-links"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_FooterHelpLinksWidget_testFooterHelpLinkTemplatesPointToAccount(): void
{
    $account = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/widgets/footer-my-account-link.phtml');
    $orders = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/widgets/footer-my-orders-link.phtml');
    self::assertStringContainsString('data-testid="footer-my-account-link"', $account);
    self::assertStringContainsString("@url{'customer/account/index'}", $account);
    self::assertStringContainsString('footer-help-links', $account);
    self::assertStringContainsString('data-testid="footer-my-orders-link"', $orders);
    self::assertStringContainsString("@url{'customer/account/index'}#orders", $orders);
    self::assertStringContainsString('footer-help-links', $orders);
}

private function assert_FooterSocialLoginLinkWidget_testFooterSocialLoginLinkRegistersDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/extends/module/Weline_Widget/Weline_Customer/widget.php';
    $tpl = 'Weline_Customer::templates/frontend/widgets/footer-social-login-link.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/widgets/footer-social-login-link.phtml');
    self::assertStringContainsString('@widget.code {footer-social-login-link}', $src);
    self::assertStringContainsString('@widget.slot {footer-payment-account-links}', $src);
    self::assertStringContainsString('"slot":"footer-payment-account-links"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_FooterSocialLoginLinkWidget_testFooterSocialLoginLinkTemplatePointsToGuide(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/widgets/footer-social-login-link.phtml');
    self::assertStringContainsString('data-testid="footer-social-login-link"', $template);
    self::assertStringContainsString("@url{'guide/social-login'}", $template);
    self::assertStringContainsString('footer-payment-account-links', $template);
    self::assertStringContainsString('社媒登录', $template);
}

private function assert_FooterSocialLoginLinkWidget_testThemeEnUsCsvTranslatesFooterSocialLoginLabel(): void
{
    $csv = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/i18n/en_US.csv';
    self::assertFileExists($csv);
    $catalog = [];
    $handle = fopen($csv, 'rb');
    self::assertIsResource($handle);
    while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
        $source = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($row[0] ?? ''));
        if ($source === '') {
            continue;
        }
        $catalog[$source] = (string) ($row[1] ?? '');
    }
    fclose($handle);
    self::assertSame('Social Login', $catalog['社媒登录'] ?? null);
}

private function assert_SafeStorefrontDestinationLocalePrefix_testAccountLoginJsTreatsLocaleQueryAsAlreadyPrefixed(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/statics/js/account-login.js';
    $src = (string) file_get_contents($path);
    self::assertNotSame('', $src);
    self::assertStringContainsString('destination.startsWith(`${prefix}?`)', $src);
    self::assertStringContainsString('destination.startsWith(`${prefix}#`)', $src);
    self::assertStringContainsString('never emit /bg_BG/bg_BG', $src);
}

private function assert_SafeStorefrontDestinationLocalePrefix_testAccountRecoveryJsTreatsLocaleQueryAsAlreadyPrefixed(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/statics/js/account-recovery.js';
    $src = (string) file_get_contents($path);
    self::assertNotSame('', $src);
    self::assertStringContainsString('destination.startsWith(`${prefix}?`)', $src);
    self::assertStringContainsString('destination.startsWith(`${prefix}#`)', $src);
    self::assertStringContainsString('never emit /bg_BG/bg_BG', $src);
}

private function assert_SocialLoginChooseBindHint_testChooseTemplateDistinguishesLocalAccountFromSocialEmail(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/account/social-login-choose.phtml');
    self::assertStringContainsString('bind_hint', $src);
    self::assertStringContainsString('bind_error', $src);
    self::assertStringContainsString('本站用户名或邮箱', $src);
    self::assertStringContainsString('本站密码', $src);
    self::assertStringContainsString('不是 Google 密码', $src);
}

private function assert_SocialLoginChooseBindHint_testChooseControllerAvoidsPrefillingMissingLocalEmail(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Controller/Account/SocialLogin.php');
    self::assertStringContainsString('findByEmail', $src);
    self::assertStringContainsString('bind_error', $src);
    self::assertStringContainsString("'username' => \$login", $src);
}

private function assert_SocialLoginSystemConfigActivation_testSocialLoginTemplateDeclaresEnabledSwitchesAndCredentials(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/extends/module/Weline_SystemConfig/Config/frontend/social-login.phtml';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    foreach (['google', 'facebook', 'instagram'] as $provider) {
        self::assertStringContainsString('customer/social_login/' . $provider . '/client_id', $src);
        self::assertStringContainsString('customer/social_login/' . $provider . '/client_secret', $src);
        self::assertStringContainsString('customer/social_login/' . $provider . '/enabled', $src);
    }
    self::assertStringContainsString('激活前台入口', $src);
    self::assertStringContainsString('未配置凭据时开启无效', $src);
    self::assertStringContainsString('default="0"', $src);
}

private function assert_SocialLoginSystemConfigActivation_testSocialLoginTemplateExposesSetupGuideAdapters(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/extends/module/Weline_SystemConfig/Config/frontend/social-login.phtml';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('两种配置方式', $src);
    self::assertStringContainsString('方式 A（推荐 · 上传 JSON 解析）', $src);
    self::assertStringContainsString('选择后会立即解析填入', $src);
    self::assertStringContainsString('方式 B（逐项填写）', $src);
    self::assertStringContainsString('customer/social_login/google/oauth_client_json', $src);
    self::assertStringContainsString('type="import_file"', $src);
    self::assertStringContainsString('client_secret_', $src);
    self::assertStringContainsString('customer.social_login.google.console', $src);
    self::assertStringContainsString('customer.social_login.google.origin', $src);
    self::assertStringContainsString('customer.social_login.google.callback', $src);
    self::assertStringContainsString('callback-as-origin="true"', $src);
    self::assertStringContainsString('callback-path="customer/account/social-login/callback"', $src);
    self::assertStringContainsString('callback-append-scope="false"', $src);
    self::assertStringContainsString('不得含语言/货币路径前缀', $src);
    self::assertStringContainsString('已获授权的 JavaScript 来源', $src);
    self::assertStringContainsString('已获授权的重定向 URI', $src);
    self::assertStringContainsString('console.cloud.google.com/apis/credentials', $src);
    self::assertStringContainsString('developers.facebook.com/apps', $src);
    self::assertStringContainsString('配置指南', $src);
    self::assertStringContainsString('精选/全部列表里通常看不到', $src);
    self::assertStringContainsString('其他', $src);
    self::assertStringContainsString('消费者', $src);
    self::assertStringContainsString('创建一个没有用例的应用', $src);
    self::assertStringContainsString('Facebook Login for Business', $src);
    self::assertStringContainsString('customer.social_login.facebook.callback', $src);
    self::assertStringContainsString('customer.social_login.facebook.js_sdk_domain', $src);
    self::assertStringContainsString('customer.social_login.facebook.app_domain', $src);
    self::assertStringContainsString('customer.social_login.facebook.privacy_policy', $src);
    self::assertStringContainsString('customer.social_login.facebook.terms', $src);
    self::assertStringContainsString('customer.social_login.facebook.data_deletion', $src);
    self::assertStringContainsString('callback-as-host="true"', $src);
    self::assertStringContainsString('callback-path="guide/social-login/facebook/policy"', $src);
    self::assertStringContainsString('callback-path="guide/social-login/facebook/policy#data-deletion"', $src);
    self::assertStringContainsString('callback-path="terms"', $src);
    self::assertStringContainsString('应用域名', $src);
    self::assertStringContainsString('隐私政策网址', $src);
    self::assertStringContainsString('服务条款网址', $src);
    self::assertStringContainsString('用户数据删除说明网址', $src);
    self::assertStringContainsString('Valid OAuth Redirect URIs', $src);
    self::assertStringContainsString('Allowed Domains for the JavaScript SDK', $src);
    self::assertStringContainsString('跳转 URI 验证器', $src);
    self::assertStringContainsString('使用 JavaScript SDK 登录', $src);
    self::assertStringContainsString('本站请求的权限（必须）', $src);
    self::assertStringContainsString('email,public_profile', $src);
    self::assertStringContainsString('public_profile', $src);
    self::assertStringContainsString('姓名和头像', $src);
    self::assertStringContainsString('登录审核', $src);
    self::assertStringContainsString('Advanced Access', $src);
    self::assertStringContainsString('未登录 Facebook One Tap', $src);
    self::assertStringContainsString('需点击', $src);
    self::assertStringContainsString('fedCM.autoPrompt', $src);
    self::assertStringContainsString('未登录 One Tap（需点击确认）', $src);
    self::assertStringContainsString('customer/social_login/http_proxy', $src);
    $facebookGuide = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/guide/social-login/facebook/guide.phtml';
    self::assertFileExists($facebookGuide);
    $guideSrc = (string) \file_get_contents($facebookGuide);
    self::assertStringContainsString('customer.social_login.guide.facebook.permissions', $guideSrc);
    self::assertStringContainsString('商户须开通的 Meta 权限', $guideSrc);
    self::assertStringContainsString('public_profile', $guideSrc);
    self::assertStringContainsString('email', $guideSrc);
    self::assertStringContainsString('提交登录审核', $guideSrc);
    self::assertStringContainsString('看不到 Facebook One Tap', $guideSrc);
    self::assertStringContainsString('FedCM', $guideSrc);
    self::assertStringContainsString('customer/social_login/http_proxy_type', $src);
    self::assertStringContainsString('出站代理', $src);
    self::assertStringContainsString('customer.social_login.instagram.console', $src);
    self::assertStringContainsString('Instagram API with Instagram Login', $src);
    self::assertStringContainsString('API setup with Instagram login', $src);
    self::assertStringContainsString('instagram_business_basic', $src);
    self::assertStringContainsString('Instagram App ID', $src);
    self::assertStringContainsString('Instagram App Secret', $src);
    self::assertStringContainsString('Business / Creator', $src);
    self::assertStringNotContainsString('Instagram Login / Basic Display', $src);
    self::assertStringNotContainsString('Basic Display / Instagram Login', $src);
    $instagramGuide = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/view/templates/frontend/guide/social-login/instagram/guide.phtml';
    self::assertFileExists($instagramGuide);
    $igGuideSrc = (string) \file_get_contents($instagramGuide);
    self::assertStringContainsString('Instagram 专业账户', $igGuideSrc);
    self::assertStringContainsString('Instagram API with Instagram Login', $igGuideSrc);
    self::assertStringNotContainsString('Instagram Basic/', $igGuideSrc);
}
}
