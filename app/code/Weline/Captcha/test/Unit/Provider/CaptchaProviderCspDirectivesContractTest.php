<?php

declare(strict_types=1);

namespace Weline\Captcha\test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Captcha\Extends\Module\Weline_Framework\Security\Csp\CaptchaVendorsCsp;
use Weline\Captcha\Interface\VerificationProviderInterface;
use Weline\Captcha\Provider\GoogleRecaptchaEnterprise;
use Weline\Captcha\Provider\LocalImageCaptcha;
use Weline\Captcha\Provider\TencentCaptcha;

final class CaptchaProviderCspDirectivesContractTest extends TestCase
{
    public function testBuiltInProvidersDeclareCspDirectives(): void
    {
        /** @var GoogleRecaptchaEnterprise $google */
        $google = (new \ReflectionClass(GoogleRecaptchaEnterprise::class))->newInstanceWithoutConstructor();
        $g = $google->cspDirectives();
        self::assertContains('https://www.google.com', $g['script-src'] ?? []);
        self::assertContains('https://www.gstatic.com', $g['script-src'] ?? []);
        self::assertContains('https://www.recaptcha.net', $g['script-src'] ?? []);
        self::assertContains('https://www.google.com', $g['frame-src'] ?? []);
        self::assertContains('https://recaptcha.google.com', $g['frame-src'] ?? []);
        self::assertContains('https://www.google.com', $g['connect-src'] ?? []);
        self::assertContains('https://recaptchaenterprise.googleapis.com', $g['connect-src'] ?? []);
        self::assertContains('https://www.gstatic.com', $g['img-src'] ?? []);

        /** @var TencentCaptcha $tencent */
        $tencent = (new \ReflectionClass(TencentCaptcha::class))->newInstanceWithoutConstructor();
        $t = $tencent->cspDirectives();
        self::assertContains('https://turing.captcha.qcloud.com', $t['script-src'] ?? []);
        self::assertContains('https://captcha.tencentcloudapi.com', $t['connect-src'] ?? []);

        /** @var LocalImageCaptcha $local */
        $local = (new \ReflectionClass(LocalImageCaptcha::class))->newInstanceWithoutConstructor();
        self::assertSame([], $local->cspDirectives());
    }

    public function testCaptchaVendorsCspAggregatesRegistryProviders(): void
    {
        $providers = [
            new class implements VerificationProviderInterface {
                public function code(): string
                {
                    return 'google_enterprise';
                }

                public function render(array $context): string
                {
                    return '';
                }

                public function verify(array $submission, string $intent, string $hostname, ?string $ip = null): bool
                {
                    return false;
                }

                public function cspDirectives(): array
                {
                    return ['script-src' => ['https://www.recaptcha.net']];
                }
            },
            new class implements VerificationProviderInterface {
                public function code(): string
                {
                    return 'tencent_captcha';
                }

                public function render(array $context): string
                {
                    return '';
                }

                public function verify(array $submission, string $intent, string $hostname, ?string $ip = null): bool
                {
                    return false;
                }

                public function cspDirectives(): array
                {
                    return ['script-src' => ['https://turing.captcha.qcloud.com']];
                }
            },
        ];

        $contribution = (new CaptchaVendorsCsp(static fn (): array => $providers))->contribution();
        $script = $contribution->directives['script-src'] ?? [];
        self::assertContains('https://www.recaptcha.net', $script);
        self::assertContains('https://turing.captcha.qcloud.com', $script);
    }
}
