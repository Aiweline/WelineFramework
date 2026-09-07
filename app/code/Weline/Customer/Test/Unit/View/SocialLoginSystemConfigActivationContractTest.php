<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * SystemConfig social-login template: activation + setup guide adapters.
 */
final class SocialLoginSystemConfigActivationContractTest extends TestCase
{
    public function testSocialLoginTemplateDeclaresEnabledSwitchesAndCredentials(): void
    {
        $path = \dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/frontend/social-login.phtml';
        self::assertFileExists($path);
        $src = (string) \file_get_contents($path);

        foreach (['google', 'facebook', 'instagram'] as $provider) {
            self::assertStringContainsString(
                'customer/social_login/' . $provider . '/client_id',
                $src
            );
            self::assertStringContainsString(
                'customer/social_login/' . $provider . '/client_secret',
                $src
            );
            self::assertStringContainsString(
                'customer/social_login/' . $provider . '/enabled',
                $src
            );
        }
        self::assertStringContainsString('激活前台入口', $src);
        self::assertStringContainsString('未配置凭据时开启无效', $src);
        self::assertStringContainsString('default="0"', $src);
    }

    public function testSocialLoginTemplateExposesSetupGuideAdapters(): void
    {
        $path = \dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/frontend/social-login.phtml';
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

        $facebookGuide = \dirname(__DIR__, 3) . '/view/templates/Frontend/guide/social-login/facebook/guide.phtml';
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

        $instagramGuide = \dirname(__DIR__, 3) . '/view/templates/Frontend/guide/social-login/instagram/guide.phtml';
        self::assertFileExists($instagramGuide);
        $igGuideSrc = (string) \file_get_contents($instagramGuide);
        self::assertStringContainsString('Instagram 专业账户', $igGuideSrc);
        self::assertStringContainsString('Instagram API with Instagram Login', $igGuideSrc);
        self::assertStringNotContainsString('Instagram Basic/', $igGuideSrc);
    }
}
