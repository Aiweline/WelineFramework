<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 两步验证表单由 Theme 布局内嵌 account-challenge 部件承载；本模块只保留表单与 shell。
 */
final class AccountChallengeWidgetContractTest extends TestCase
{
    public function testChallengeShellLeavesStageToThemeInlineWidget(): void
    {
        $shell = \dirname(__DIR__, 3) . '/view/templates/frontend/account/challenge-shell.phtml';
        $challengeForm = \dirname(__DIR__, 3) . '/view/templates/frontend/account/challenge.phtml';
        $themeWidget = \dirname(__DIR__, 4) . '/Theme/view/theme/frontend/widgets/form/account-challenge/default.phtml';
        $challengeLayout = \dirname(__DIR__, 3) . '/view/theme/frontend/layouts/account/challenge.phtml';

        self::assertFileExists($shell);
        self::assertFileExists($challengeForm);
        self::assertFileExists($themeWidget);
        self::assertFileExists($challengeLayout);

        $themeSource = (string)\file_get_contents($themeWidget);
        self::assertStringContainsString('@widget.code {account-challenge}', $themeSource);
        self::assertStringContainsString('type="media_image"', $themeSource);
        self::assertStringContainsString('account-login-widget__backdrop', $themeSource);
        self::assertStringContainsString('account-login-widget__rail', $themeSource);
        self::assertStringContainsString('account-login-widget__dock', $themeSource);
        self::assertStringContainsString('Weline_Customer::templates/frontend/account/challenge.phtml', $themeSource);

        $formSource = (string)\file_get_contents($challengeForm);
        self::assertMatchesRegularExpression(
            '/data-w-component\\s*=\\s*["\']account-challenge["\']/',
            $formSource
        );
        self::assertStringContainsString('data-w-challenge-form', $formSource);
        self::assertStringContainsString('w-auth-login--amazon', $formSource);
        self::assertStringNotContainsString('linear-gradient(135deg, var(--color-primary)', $formSource);
        self::assertStringNotContainsString('AccountApi.completeChallenge', $formSource);

        $widgetPhp = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/extends/module/Weline_Widget/Weline_Theme/widget.php'
        );
        self::assertStringContainsString('account-challenge/default.phtml', $widgetPhp);

        $layoutSource = (string)\file_get_contents($challengeLayout);
        self::assertMatchesRegularExpression(
            '/<w:widget\\s+type="form"\\s+name="account-challenge"\\s*\\/>/',
            $layoutSource
        );
        self::assertStringContainsString('account-challenge-layout', $layoutSource);
        self::assertStringNotContainsString('#232f3e', $layoutSource);

        $controller = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Controller/Account/Challenge.php'
        );
        self::assertStringContainsString("layoutType = 'account.challenge'", $controller);
        self::assertStringContainsString('challenge-shell.phtml', $controller);

        $challengeJs = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/view/statics/ui/pages/weline-customer-account-challenge.js'
        );
        self::assertStringContainsString("UI.define('account-challenge'", $challengeJs);
        self::assertStringContainsString('fetch(action', $challengeJs);
        self::assertStringContainsString('credentials: \'same-origin\'', $challengeJs);
        self::assertStringNotContainsString('completeChallenge', $challengeJs);

        self::assertStringContainsString('data-w-challenge-idle-label', $formSource);
        self::assertStringContainsString('data-w-challenge-submit', $formSource);
        self::assertStringContainsString('data-w-challenge-token', $formSource);
        self::assertStringContainsString('data-challenge-token', $formSource);
        self::assertStringContainsString('getParam(\'challenge_token\')', $formSource);

        $loginCss = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/view/statics/ui/pages/weline-customer-account-login.css'
        );
        self::assertStringContainsString(
            '.w-auth-login--amazon .w-auth-login__submit [data-w-challenge-idle-label]',
            $loginCss
        );
        self::assertStringContainsString(
            '.w-auth-login--amazon .w-auth-login__submit [data-w-challenge-busy-label]',
            $loginCss
        );
        self::assertMatchesRegularExpression(
            '/\\.w-auth-login--amazon \\.w-auth-login__submit\\s*\\{[^}]*display:\\s*inline-flex;[^}]*align-items:\\s*center;/s',
            $loginCss
        );

        $welineUi = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/view/statics/ui/weline-ui.js'
        );
        self::assertMatchesRegularExpression(
            "/\\['account-challenge',\\s*'\\.\\/pages\\/weline-customer-account-challenge\\.js/",
            $welineUi
        );
        self::assertMatchesRegularExpression(
            "/\\['account-challenge',\\s*'\\.\\/pages\\/weline-customer-account-login\\.css/",
            $welineUi
        );

        self::assertStringContainsString('syncChallengeToken', $challengeJs);
        self::assertStringContainsString("formData.set('challenge_token', token)", $challengeJs);
        self::assertStringContainsString("formData.set('code', digits)", $challengeJs);
        self::assertStringContainsString('data-w-challenge-messages', $formSource);

        $challengeController = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Controller/Account/Challenge.php'
        );
        self::assertStringContainsString('getPostParams()', $challengeController);
        self::assertStringContainsString('登录验证令牌缺失，请返回登录后重试。', $challengeController);
    }
}
