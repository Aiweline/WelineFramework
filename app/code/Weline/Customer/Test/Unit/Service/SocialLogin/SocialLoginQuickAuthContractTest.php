<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service\SocialLogin;

use PHPUnit\Framework\TestCase;

final class SocialLoginQuickAuthContractTest extends TestCase
{
    public function testQuickAuthServiceAndEndpointsExist(): void
    {
        $root = dirname(__DIR__, 4);
        self::assertFileExists($root . '/Service/SocialLogin/SocialLoginQuickAuthService.php');
        self::assertFileExists($root . '/view/statics/js/account-social-quick.js');
        self::assertFileExists($root . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');

        $controller = (string) file_get_contents($root . '/Controller/Account/SocialLogin.php');
        self::assertStringContainsString('function postQuickGoogle', $controller);
        self::assertStringContainsString('function postQuickFacebook', $controller);

        $config = (string) file_get_contents($root . '/Service/SocialLogin/SocialLoginConfig.php');
        self::assertStringContainsString('function isQuickPromptEnabled', $config);

        $widget = (string) file_get_contents($root . '/view/templates/frontend/widgets/account-social-login.phtml');
        self::assertStringContainsString('data-social-quick-config', $widget);
        self::assertStringContainsString('type="application/json"', $widget);
        self::assertStringContainsString('account-social-quick.js', $widget);
        self::assertStringContainsString("BP . '/app/code/Weline/Customer/view/statics/js/account-social-quick.js'", $widget);
        self::assertStringContainsString('data-no-extract="true"', $widget);
        self::assertStringContainsString('data-w-social-quick-boot', $widget);

        // Theme layouts must NOT SSR One Tap — account JS + Query own it.
        $hook = (string) file_get_contents($root . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
        self::assertStringContainsString('account.socialQuickPrompt', $hook);
        self::assertStringNotContainsString('data-w-component="social-login-quick-prompt"', $hook);
        self::assertStringNotContainsString('account-social-quick.js', $hook);
        self::assertStringContainsString('return;', $hook);

        $homeHook = (string) file_get_contents($root . '/view/hooks/Weline_Theme/frontend/layouts/homepage/body-end.phtml');
        self::assertStringContainsString('account.socialQuickPrompt', $homeHook);
        self::assertStringNotContainsString('data-social-quick-config', $homeHook);

        $query = (string) file_get_contents(
            $root . '/extends/module/Weline_Framework/Query/AccountQueryProvider.php'
        );
        self::assertStringContainsString("'socialQuickPrompt'", $query);
        self::assertStringContainsString('function socialQuickPrompt', $query);
        self::assertStringContainsString('quickPromptBootstrap', $query);

        $presentation = (string) file_get_contents(
            $root . '/Service/SocialLogin/SocialLoginPresentationService.php'
        );
        self::assertStringContainsString("'oauth'", $presentation);
        self::assertStringContainsString("'i18n'", $presentation);
        self::assertStringContainsString("__('快捷登录')", $presentation);
        self::assertStringContainsString('startUrl(\'facebook\'', $presentation);

        $modules = (string) file_get_contents($root . '/view/statics/frontend/weline.modules.js');
        self::assertStringContainsString('customerSocialQuick', $modules);
        self::assertStringContainsString('WelineSocialQuick', $modules);

        $js = (string) file_get_contents($root . '/view/statics/js/account-social-quick.js');
        self::assertStringContainsString('auto_select: false', $js);
        self::assertStringNotContainsString('FB.getLoginStatus', $js);
        self::assertStringNotContainsString('auto_select: true', $js);
        self::assertStringContainsString('isAccountHomePath', $js);
        self::assertStringContainsString('currentStorefrontPath', $js);
        self::assertStringContainsString('checkFrontendUserLogin', $js);
        // Single chooser: no native One Tap / FedCM auto stack beside the bar.
        self::assertStringContainsString('startChooserOnly', $js);
        self::assertStringContainsString('chooser_only', $js);
        self::assertStringContainsString('suppressed_login_widget', $js);
        self::assertStringNotContainsString('accounts.id.prompt(', $js);
        self::assertStringNotContainsString('autoPrompt: true', $js);
        self::assertStringNotContainsString('auth.statusChange', $js);
        self::assertStringNotContainsString('FB.login(', $js);
        self::assertStringContainsString('cfg.oauth', $js);
        self::assertStringContainsString('social-login/start', $js);
        self::assertStringContainsString('data-w-social-quick-fallback-ui', $js);
        self::assertStringContainsString('MOUNT_SOCIAL_QUICK', $js);
        self::assertStringContainsString('customer/social-quick', $js);
        self::assertStringContainsString('mountIntoHost', $js);
        self::assertStringContainsString('scanMountHosts', $js);
        self::assertStringContainsString('revealVisibleFallback', $js);
        self::assertStringContainsString('weline-social-quick-bar__btn--google', $js);
        self::assertStringContainsString('weline-social-quick-bar__btn--facebook', $js);
        self::assertStringContainsString('providerButtonHtml', $js);
        self::assertStringContainsString('providerIconSvg', $js);
        self::assertStringContainsString('withStorefrontLocalePrefix', $js);
        self::assertStringContainsString('cfg.locale_prefix', $js);
        self::assertStringContainsString('stripStorefrontLocalePrefix', $js);
        self::assertStringContainsString('pagePrefix', $js);
        self::assertStringContainsString('resolveProviderOauthStart', $js);
        self::assertStringContainsString("toLowerCase() === 'google'", $js);
        self::assertStringContainsString('align-items:stretch', $js);
        self::assertStringNotContainsString('accounts.id.renderButton', $js);
        self::assertStringNotContainsString('measureQuickBarActionWidth', $js);
        self::assertStringContainsString('Never call prompt()', $js);
        self::assertStringContainsString('data-social-quick-config', $js);
        self::assertStringContainsString('whenReady', $js);
        self::assertStringContainsString('data-weline-script-ready', $js);
        self::assertStringNotContainsString("s.crossOrigin = 'anonymous'", $js);
        self::assertStringContainsString("querySelector('[data-social-quick]')", $js);
        self::assertStringContainsString('social-login-quick-prompt', $js);
        self::assertStringContainsString('WelineSocialQuick', $js);
        self::assertStringContainsString('i18nLabel', $js);
        self::assertStringContainsString('cfg.i18n', $js);
        self::assertStringContainsString('startFromConfig', $js);
        self::assertStringContainsString('skipLoginCheck', $js);
        self::assertFileExists($root . '/view/hooks/Weline_Theme/frontend/layouts/homepage/body-end.phtml');
    }
}
