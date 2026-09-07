<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class SocialLoginGuideRouterContractTest extends TestCase
{
    public function testCustomerRouterOwnsGuideSocialLoginPaths(): void
    {
        $src = (string) \file_get_contents(\dirname(__DIR__, 3) . '/Controller/Router.php');
        self::assertStringContainsString("guide/social-login", $src);
        self::assertStringContainsString('customer/frontend/guide/social-login', $src);
        self::assertStringContainsString('customer/frontend/guide/social-login/view', $src);
        self::assertStringContainsString('customer/frontend/guide/social-login/policy', $src);
        self::assertStringNotContainsString('customer/frontend/guide/social-login/index', $src);
        self::assertStringContainsString('provider_code', $src);
    }

    public function testGuideControllerAndTemplatesExist(): void
    {
        $root = \dirname(__DIR__, 3);
        self::assertFileExists($root . '/Controller/Frontend/Guide/SocialLogin.php');
        self::assertFileExists($root . '/Service/SocialLogin/SocialLoginGuideRegistry.php');
        self::assertFileExists($root . '/view/templates/Frontend/guide/social-login/index.phtml');
        self::assertFileExists($root . '/view/templates/Frontend/guide/social-login/partials/sidebar.phtml');
        foreach (['google', 'facebook', 'instagram'] as $code) {
            self::assertFileExists($root . '/view/templates/Frontend/guide/social-login/' . $code . '/guide.phtml');
            self::assertFileExists($root . '/view/templates/Frontend/guide/social-login/' . $code . '/policy.phtml');
        }
    }
}
