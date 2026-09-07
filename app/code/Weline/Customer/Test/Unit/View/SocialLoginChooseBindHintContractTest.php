<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class SocialLoginChooseBindHintContractTest extends TestCase
{
    public function testChooseTemplateDistinguishesLocalAccountFromSocialEmail(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/account/social-login-choose.phtml'
        );

        self::assertStringContainsString('bind_hint', $src);
        self::assertStringContainsString('bind_error', $src);
        self::assertStringContainsString('本站用户名或邮箱', $src);
        self::assertStringContainsString('本站密码', $src);
        self::assertStringContainsString('不是 Google 密码', $src);
    }

    public function testChooseControllerAvoidsPrefillingMissingLocalEmail(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Account/SocialLogin.php'
        );

        self::assertStringContainsString('findByEmail', $src);
        self::assertStringContainsString('bind_error', $src);
        self::assertStringContainsString("'username' => \$login", $src);
    }
}
