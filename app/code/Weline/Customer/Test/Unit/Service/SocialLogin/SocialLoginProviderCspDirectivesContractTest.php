<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service\SocialLogin;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Extends\Module\Weline_Customer\SocialLoginProvider\FacebookProvider;
use Weline\Customer\Extends\Module\Weline_Customer\SocialLoginProvider\GoogleProvider;
use Weline\Customer\Extends\Module\Weline_Customer\SocialLoginProvider\InstagramProvider;
use Weline\Customer\Extends\Module\Weline_Framework\Security\Csp\SocialLoginVendorsCsp;

final class SocialLoginProviderCspDirectivesContractTest extends TestCase
{
    public function testBuiltInProvidersDeclareCspDirectives(): void
    {
        $google = (new GoogleProvider())->cspDirectives();
        self::assertContains('https://accounts.google.com', $google['script-src'] ?? []);
        self::assertContains('https://apis.google.com', $google['script-src'] ?? []);
        self::assertContains('https://accounts.google.com', $google['frame-src'] ?? []);
        self::assertContains('https://oauth2.googleapis.com', $google['connect-src'] ?? []);
        self::assertContains('https://openidconnect.googleapis.com', $google['connect-src'] ?? []);

        $facebook = (new FacebookProvider())->cspDirectives();
        self::assertContains('https://connect.facebook.net', $facebook['script-src'] ?? []);
        self::assertContains('https://www.facebook.com', $facebook['frame-src'] ?? []);
        self::assertContains('https://graph.facebook.com', $facebook['connect-src'] ?? []);

        $instagram = (new InstagramProvider())->cspDirectives();
        self::assertContains('https://www.instagram.com', $instagram['frame-src'] ?? []);
        self::assertContains('https://api.instagram.com', $instagram['connect-src'] ?? []);
        self::assertContains('https://graph.instagram.com', $instagram['connect-src'] ?? []);
    }

    public function testSocialLoginVendorsCspAggregatesProviders(): void
    {
        $contribution = (new SocialLoginVendorsCsp(static fn (): array => [
            new GoogleProvider(),
            new FacebookProvider(),
            new InstagramProvider(),
        ]))->contribution();

        $script = $contribution->directives['script-src'] ?? [];
        $frame = $contribution->directives['frame-src'] ?? [];
        $connect = $contribution->directives['connect-src'] ?? [];
        self::assertContains('https://accounts.google.com', $script);
        self::assertContains('https://connect.facebook.net', $script);
        self::assertContains('https://www.instagram.com', $frame);
        self::assertContains('https://graph.instagram.com', $connect);
    }

    public function testInterfaceAndAbstractDeclareCspDirectives(): void
    {
        $iface = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Interface/SocialLoginProviderInterface.php'
        );
        $abstract = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Service/SocialLogin/AbstractSocialLoginProvider.php'
        );
        self::assertStringContainsString('function cspDirectives(): array', $iface);
        self::assertStringContainsString('function cspDirectives(): array', $abstract);
    }
}
