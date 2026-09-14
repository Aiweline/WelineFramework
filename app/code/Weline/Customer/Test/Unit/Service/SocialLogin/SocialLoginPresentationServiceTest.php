<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service\SocialLogin;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Service\SocialLogin\SocialLoginConfig;
use Weline\Customer\Service\SocialLogin\SocialLoginOAuthService;
use Weline\Customer\Service\SocialLogin\SocialLoginPresentationService;
use Weline\Customer\Service\SocialLogin\SocialLoginProviderCatalog;

final class SocialLoginPresentationServiceTest extends TestCase
{
    public function testDisabledWidgetProviderIsSkippedEvenWhenActive(): void
    {
        $service = new SocialLoginPresentationService(
            new SocialLoginProviderCatalog(),
            $this->configStub(static fn(string $provider): bool => true),
            $this->oauthStub()
        );

        $buttons = $service->enabledButtons([
            'enable_google' => true,
            'enable_facebook' => false,
            'enable_instagram' => '0',
        ]);

        self::assertCount(1, $buttons);
        self::assertSame('google', $buttons[0]['code']);
    }

    public function testConfiguredButNotActivatedProviderIsSkipped(): void
    {
        $service = new SocialLoginPresentationService(
            new SocialLoginProviderCatalog(),
            $this->configStub(static function (string $provider): bool {
                // facebook configured only; none activated via isActive
                return false;
            }, static function (string $provider): bool {
                return $provider === 'facebook';
            }, static function (string $provider): bool {
                return false;
            }),
            $this->oauthStub()
        );

        $buttons = $service->enabledButtons([
            'enable_google' => true,
            'enable_facebook' => true,
            'enable_instagram' => true,
        ]);

        self::assertSame([], $buttons);
    }

    public function testOnlyActiveProviderIsRendered(): void
    {
        $service = new SocialLoginPresentationService(
            new SocialLoginProviderCatalog(),
            $this->configStub(static fn(string $provider): bool => $provider === 'facebook'),
            $this->oauthStub()
        );

        $buttons = $service->enabledButtons([
            'enable_google' => true,
            'enable_facebook' => true,
            'enable_instagram' => true,
        ]);

        self::assertCount(1, $buttons);
        self::assertSame('facebook', $buttons[0]['code']);
    }

    public function testCatalogContainsGoogleFacebookInstagram(): void
    {
        $catalog = new SocialLoginProviderCatalog();
        self::assertSame(['google', 'facebook', 'instagram'], $catalog->codes());
        self::assertNotNull($catalog->definition('google'));
        self::assertNotNull($catalog->definition('facebook'));
        self::assertNotNull($catalog->definition('instagram'));
        self::assertArrayHasKey('icon_svg', $catalog->definition('google') ?? []);
        self::assertStringContainsString('svg', (string) (($catalog->definition('google') ?? [])['icon_svg'] ?? ''));
    }

    /**
     * @param callable(string):bool $isActive
     * @param callable(string):bool|null $isConfigured
     * @param callable(string):bool|null $isEnabled
     */
    private function configStub(
        callable $isActive,
        ?callable $isConfigured = null,
        ?callable $isEnabled = null
    ): SocialLoginConfig {
        return new class ($isActive, $isConfigured, $isEnabled) extends SocialLoginConfig {
            /** @var callable(string):bool */
            private $isActiveFn;
            /** @var callable(string):bool|null */
            private $isConfiguredFn;
            /** @var callable(string):bool|null */
            private $isEnabledFn;

            public function __construct(callable $isActive, ?callable $isConfigured, ?callable $isEnabled)
            {
                $this->isActiveFn = $isActive;
                $this->isConfiguredFn = $isConfigured;
                $this->isEnabledFn = $isEnabled;
            }

            public function isActive(string $provider): bool
            {
                return (bool) ($this->isActiveFn)($provider);
            }

            public function isConfigured(string $provider): bool
            {
                if ($this->isConfiguredFn !== null) {
                    return (bool) ($this->isConfiguredFn)($provider);
                }

                return $this->isActive($provider);
            }

            public function isEnabled(string $provider): bool
            {
                if ($this->isEnabledFn !== null) {
                    return (bool) ($this->isEnabledFn)($provider);
                }

                return $this->isActive($provider);
            }
        };
    }

    private function oauthStub(): SocialLoginOAuthService
    {
        return new class extends SocialLoginOAuthService {
            public function __construct()
            {
            }

            public function startUrl(string $provider, string $returnUrl = '', string $intent = 'login'): string
            {
                return '/start/' . $provider;
            }

            public function quickGoogleUrl(): string
            {
                return '/quick/google';
            }

            public function quickFacebookUrl(): string
            {
                return '/quick/facebook';
            }

            public function currentStorefrontLocalePrefix(): string
            {
                return '/en_US';
            }
        };
    }
}
