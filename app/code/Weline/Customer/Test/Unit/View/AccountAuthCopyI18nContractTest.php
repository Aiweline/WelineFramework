<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 登录/注册表单标题与副标题在 Theme 部件注入中文源文案时，必须走 __()，
 * 否则英文 locale 会原样输出「登录」「使用您的账户继续购物」等中文。
 */
final class AccountAuthCopyI18nContractTest extends TestCase
{
    public function testLoginTemplateTranslatesWidgetHeadingAndSubtitle(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/frontend/account/login.phtml'
        );

        self::assertStringContainsString('$safe(__($heading))', $source);
        self::assertStringContainsString('$safe(__($subheading))', $source);
        self::assertDoesNotMatchRegularExpression(
            '/w-auth-login__title[\s\S]{0,200}\$safe\(\$heading\)/',
            $source
        );
        self::assertDoesNotMatchRegularExpression(
            '/w-auth-login__subtitle[\s\S]{0,200}\$safe\(\$subheading\)/',
            $source
        );
    }

    public function testRegisterTemplateTranslatesWidgetHeadingAndSubtitle(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/frontend/account/register.phtml'
        );

        self::assertStringContainsString('$safe(__($heading))', $source);
        self::assertStringContainsString('$safe(__($subheading))', $source);
    }

    public function testAccountLoginWidgetTranslatesConfigurableCopy(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/view/theme/frontend/widgets/form/account-login/default.phtml'
        );

        // Defaults stay on <lang> (compile-time locale bake); only customized copy uses __().
        self::assertStringContainsString("\$title !== '登录'", $source);
        self::assertStringContainsString("setData('login_widget_title', (string)__(\$title))", $source);
        self::assertStringContainsString('<lang>欢迎回来</lang>', $source);
        self::assertStringContainsString('<lang>登录后继续浏览优惠与订单</lang>', $source);
        self::assertStringContainsString('<lang>安全登录 · 订单与优惠同步</lang>', $source);
    }

    public function testAccountRegisterWidgetTranslatesConfigurableCopy(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/view/theme/frontend/widgets/form/account-register/default.phtml'
        );

        self::assertStringContainsString("\$title !== '创建账户'", $source);
        self::assertStringContainsString("setData('register_widget_title', (string)__(\$title))", $source);
        self::assertStringContainsString('<lang>加入我们</lang>', $source);
    }
}
