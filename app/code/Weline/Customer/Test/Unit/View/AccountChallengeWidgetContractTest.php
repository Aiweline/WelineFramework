<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 两步验证表单归属 Customer；Theme 舞台部件可选，不得作为店面必装注入。
 */
final class AccountChallengeWidgetContractTest extends TestCase
{
    public function testChallengeOwnedByCustomerLayoutAndController(): void
    {
        $challengeForm = \dirname(__DIR__, 3) . '/view/templates/frontend/account/challenge.phtml';
        $themeWidget = \dirname(__DIR__, 4) . '/Theme/view/theme/frontend/widgets/form/account-challenge/default.phtml';
        $challengeLayout = \dirname(__DIR__, 3) . '/view/theme/frontend/layouts/account/challenge.phtml';

        self::assertFileExists($challengeForm);
        self::assertFileExists($themeWidget);
        self::assertFileExists($challengeLayout);

        $formSource = (string) \file_get_contents($challengeForm);
        self::assertMatchesRegularExpression(
            '/data-w-component\\s*=\\s*["\']account-challenge["\']/',
            $formSource
        );
        self::assertStringContainsString('data-w-challenge-form', $formSource);
        self::assertStringContainsString('w-auth-login--amazon', $formSource);
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
        self::assertStringContainsString(
            "Weline_Customer::templates/frontend/account/challenge.phtml",
            $layoutSource
        );
        self::assertStringContainsString('{{meta.content}}', $layoutSource);
        self::assertStringContainsString('account-challenge-layout', $layoutSource);
        self::assertStringNotContainsString('#232f3e', $layoutSource);

        $themeSource = (string) \file_get_contents($themeWidget);
        self::assertStringContainsString('@widget.code {account-challenge}', $themeSource);
        self::assertStringContainsString(
            'Weline_Customer::templates/frontend/account/challenge.phtml',
            $themeSource
        );

        $widgetPhp = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/extends/module/Weline_Widget/Weline_Theme/widget.php'
        );
        self::assertStringContainsString('account-challenge/default.phtml', $widgetPhp);
        self::assertStringNotContainsString('foreign-theme-account-challenge', $widgetPhp);

        $controller = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/Controller/Account/Challenge.php'
        );
        self::assertStringContainsString("layoutType = 'account.challenge'", $controller);
        self::assertStringContainsString(
            'Weline_Customer::templates/frontend/account/challenge.phtml',
            $controller
        );
        self::assertStringNotContainsString('challenge-shell.phtml', $controller);

        $challengeJs = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/view/statics/ui/pages/weline-customer-account-challenge.js'
        );
        self::assertStringContainsString("UI.define('account-challenge'", $challengeJs);
        self::assertStringContainsString('fetch(action', $challengeJs);
        self::assertStringContainsString("credentials: 'same-origin'", $challengeJs);
        self::assertStringNotContainsString('completeChallenge', $challengeJs);

        $loginCss = (string) \file_get_contents(
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

        $welineUi = (string) \file_get_contents(
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
    }
}
