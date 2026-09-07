<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service\SocialLogin;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Extends\Module\Weline_Customer\SocialLoginProvider\FacebookProvider;
use Weline\Customer\Extends\Module\Weline_Customer\SocialLoginProvider\GoogleProvider;
use Weline\Customer\Extends\Module\Weline_Customer\SocialLoginProvider\InstagramProvider;
use Weline\Customer\Interface\SocialLoginProviderInterface;
use Weline\Customer\Service\SocialLogin\AbstractSocialLoginProvider;
use Weline\Customer\Service\SocialLogin\SocialLoginProviderCatalog;

/**
 * Built-in social login providers must implement the extends contract.
 */
final class SocialLoginProviderExtendsContractTest extends TestCase
{
    public function testExtendsPointDeclaresSocialLoginProvider(): void
    {
        $extends = include \dirname(__DIR__, 4) . '/extends.php';
        self::assertIsArray($extends);
        self::assertSame('module', $extends['type'] ?? null);
        self::assertArrayHasKey('SocialLoginProvider', $extends['extends'] ?? []);
        $point = $extends['extends']['SocialLoginProvider'];
        self::assertSame(
            'extends/module/Weline_Customer/SocialLoginProvider',
            $point['path'] ?? null
        );
        self::assertSame(
            SocialLoginProviderInterface::class,
            $point['interface'] ?? null
        );
        self::assertTrue((bool) ($point['multiple'] ?? false));
    }

    public function testBuiltinProvidersLiveUnderExtendsPathAndImplementInterface(): void
    {
        $base = \dirname(__DIR__, 4) . '/extends/module/Weline_Customer/SocialLoginProvider';
        foreach (['GoogleProvider.php', 'FacebookProvider.php', 'InstagramProvider.php'] as $file) {
            self::assertFileExists($base . '/' . $file);
        }

        $providers = [
            new GoogleProvider(),
            new FacebookProvider(),
            new InstagramProvider(),
        ];
        foreach ($providers as $provider) {
            self::assertInstanceOf(SocialLoginProviderInterface::class, $provider);
            self::assertInstanceOf(AbstractSocialLoginProvider::class, $provider);
            self::assertNotSame('', $provider->getCode());
            self::assertNotSame('', $provider->getLabel());
            self::assertNotSame('', $provider->getSummary());
            self::assertNotSame('', $provider->getGuideTitle());
            self::assertNotSame('', $provider->getPolicyTitle());
            self::assertSame('guide', $provider->getGuideTemplateCode());
            self::assertSame('policy', $provider->getPolicyTemplateCode());
            self::assertSame(
                'guide/social-login/' . $provider->getCode() . '/policy',
                $provider->getStorefrontPrivacyPolicyPath()
            );
            self::assertSame('terms', $provider->getStorefrontTermsPath());
            self::assertSame(
                'guide/social-login/' . $provider->getCode() . '/policy#data-deletion',
                $provider->getStorefrontDataDeletionPath()
            );
            self::assertNotSame('', $provider->getBrandClass());
            self::assertStringContainsString('account-social-login__mark', $provider->getIconSvgMarkup());
            self::assertStringContainsString('https://', $provider->buildAuthorizationUrl(
                'client-id',
                'https://example.test/callback',
                'state-token'
            ));
            $guide = \dirname(__DIR__, 4) . '/view/templates/Frontend/guide/social-login/'
                . $provider->getCode() . '/guide.phtml';
            $policy = \dirname(__DIR__, 4) . '/view/templates/Frontend/guide/social-login/'
                . $provider->getCode() . '/policy.phtml';
            self::assertFileExists($guide);
            self::assertFileExists($policy);
        }

        $catalog = new SocialLoginProviderCatalog();
        self::assertSame(['google', 'facebook', 'instagram'], $catalog->codes());
        self::assertInstanceOf(GoogleProvider::class, $catalog->get('google'));
        self::assertInstanceOf(FacebookProvider::class, $catalog->get('facebook'));
        self::assertInstanceOf(InstagramProvider::class, $catalog->get('instagram'));
    }

    public function testInstagramProviderUsesInstagramLoginOAuthNotBasicDisplay(): void
    {
        $provider = new InstagramProvider();
        $url = $provider->buildAuthorizationUrl(
            'ig-app-id',
            'https://example.test/customer/account/social-login/callback',
            'state-token'
        );
        self::assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $url);
        self::assertStringContainsString('scope=instagram_business_basic', $url);
        self::assertStringNotContainsString('user_profile', $url);
        self::assertStringNotContainsString('user_media', $url);
        self::assertStringNotContainsString('api.instagram.com/oauth/authorize', $url);

        $src = (string) file_get_contents(
            \dirname(__DIR__, 4) . '/extends/module/Weline_Customer/SocialLoginProvider/InstagramProvider.php'
        );
        self::assertStringContainsString("private const SCOPES = 'instagram_business_basic';", $src);
        self::assertStringContainsString('https://www.instagram.com/oauth/authorize', $src);
        self::assertStringNotContainsString('user_profile,user_media', $src);
    }
}
