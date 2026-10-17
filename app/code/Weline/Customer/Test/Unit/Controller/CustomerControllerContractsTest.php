<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllCustomerControllerContractsPass().
 */
final class CustomerControllerContractsTest extends TestCase
{
    public function testAllCustomerControllerContractsPass(): void
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
            'RegisterAliasRedirect::testRouterDeclaresCreateAliasPermanentRedirect' => function () {
            $this->assert_RegisterAliasRedirect_testRouterDeclaresCreateAliasPermanentRedirect();
        },
            'SocialLoginGuideRouter::testCustomerRouterOwnsGuideSocialLoginPaths' => function () {
            $this->assert_SocialLoginGuideRouter_testCustomerRouterOwnsGuideSocialLoginPaths();
        },
            'SocialLoginGuideRouter::testGuideControllerAndTemplatesExist' => function () {
            $this->assert_SocialLoginGuideRouter_testGuideControllerAndTemplatesExist();
        },
        ];
    }

private function assert_RegisterAliasRedirect_testRouterDeclaresCreateAliasPermanentRedirect(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Controller/Router.php';
    self::assertFileExists($path);
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString("'customer/account/create'", $source);
    self::assertStringContainsString('ResponseTerminateException(301', $source);
    self::assertStringContainsString('customer/account/register', $source);
}

private function assert_SocialLoginGuideRouter_testCustomerRouterOwnsGuideSocialLoginPaths(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/Controller/Router.php');
    self::assertStringContainsString("guide/social-login", $src);
    self::assertStringContainsString('customer/frontend/guide/social-login', $src);
    self::assertStringContainsString('customer/frontend/guide/social-login/view', $src);
    self::assertStringContainsString('customer/frontend/guide/social-login/policy', $src);
    self::assertStringNotContainsString('customer/frontend/guide/social-login/index', $src);
    self::assertStringContainsString('provider_code', $src);
}

private function assert_SocialLoginGuideRouter_testGuideControllerAndTemplatesExist(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Customer';
    self::assertFileExists($root . '/Controller/Frontend/Guide/SocialLogin.php');
    self::assertFileExists($root . '/Service/SocialLogin/SocialLoginGuideRegistry.php');
    self::assertFileExists($root . '/view/templates/frontend/guide/social-login/index.phtml');
    self::assertFileExists($root . '/view/templates/frontend/guide/social-login/partials/sidebar.phtml');
    foreach (['google', 'facebook', 'instagram'] as $code) {
        self::assertFileExists($root . '/view/templates/frontend/guide/social-login/' . $code . '/guide.phtml');
        self::assertFileExists($root . '/view/templates/frontend/guide/social-login/' . $code . '/policy.phtml');
    }
}
}
