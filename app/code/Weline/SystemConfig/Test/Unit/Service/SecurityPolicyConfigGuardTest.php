<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Security\EmptySecurityHeaderPolicyOverrideProvider;
use Weline\Framework\Http\Security\InMemorySecurityPolicyLkgRepository;
use Weline\Framework\Http\Security\SecurityHeaderPolicyService;
use Weline\Framework\Http\Security\SecurityPolicyLkgGate;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\SecurityPolicyConfigGuard;

final class SecurityPolicyConfigGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::getInstance()->reload();
        Env::getInstance()->applyRuntimeConfig([
            'security' => [
                'headers' => [
                    'csp' => "default-src 'self' https://cdn.example",
                    'csp_report_only' => '',
                    'cors_origins' => 'https://shop.example https://cdn.example',
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Env::getInstance()->reload();
        parent::tearDown();
    }

    public function testMutationRequiresMatchingDurableLkg(): void
    {
        $guard = $this->guard();
        $values = [
            SecurityPolicyConfigGuard::KEY_CSP => "default-src 'self'",
            SecurityPolicyConfigGuard::KEY_CORS_ORIGINS => 'https://shop.example',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(SecurityPolicyLkgGate::ERROR_LKG_MISSING);
        $guard->assertMutation(
            SecurityPolicyConfigGuard::MODULE,
            SecurityPolicyConfigGuard::AREA,
            SystemConfig::SCOPE_GLOBAL,
            SystemConfig::LOCALE_DEFAULT,
            $values,
            [],
        );
    }

    public function testRegisteredCandidateCanActivateButDifferentDigestCannot(): void
    {
        $guard = $this->guard();
        $values = [
            SecurityPolicyConfigGuard::KEY_CSP => "default-src 'self'",
            SecurityPolicyConfigGuard::KEY_CORS_ORIGINS => 'https://shop.example',
        ];
        $record = $guard->registerLkg(
            SystemConfig::SCOPE_GLOBAL,
            SystemConfig::LOCALE_DEFAULT,
            $values,
        );

        self::assertSame(SystemConfig::SCOPE_GLOBAL, $record['scope_key']);
        $guard->assertMutation(
            SecurityPolicyConfigGuard::MODULE,
            SecurityPolicyConfigGuard::AREA,
            SystemConfig::SCOPE_GLOBAL,
            SystemConfig::LOCALE_DEFAULT,
            $values,
            [],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(SecurityPolicyLkgGate::ERROR_LKG_MISMATCH);
        $guard->assertMutation(
            SecurityPolicyConfigGuard::MODULE,
            SecurityPolicyConfigGuard::AREA,
            SystemConfig::SCOPE_GLOBAL,
            SystemConfig::LOCALE_DEFAULT,
            [
                SecurityPolicyConfigGuard::KEY_CSP => "default-src 'none'",
                SecurityPolicyConfigGuard::KEY_CORS_ORIGINS => 'https://shop.example',
            ],
            [],
        );
    }

    private function guard(): SecurityPolicyConfigGuard
    {
        $systemConfig = $this->createMock(SystemConfig::class);
        $systemConfig->method('normalizeScope')->willReturnCallback(
            static fn(?string $scope = null): string => (string)($scope ?: SystemConfig::SCOPE_GLOBAL),
        );
        $systemConfig->method('normalizeLocale')->willReturnCallback(
            static fn(?string $locale = null): string => (string)($locale ?: SystemConfig::LOCALE_DEFAULT),
        );
        $systemConfig->method('getFallbackScopes')->willReturnCallback(
            static fn(?string $scope = null): array => [(string)$scope, SystemConfig::SCOPE_GLOBAL],
        );
        $systemConfig->method('getConfig')->willReturn('');

        return new SecurityPolicyConfigGuard(
            $systemConfig,
            new SecurityHeaderPolicyService(
                lkgGate: new SecurityPolicyLkgGate(new InMemorySecurityPolicyLkgRepository()),
                overrideProvider: new EmptySecurityHeaderPolicyOverrideProvider(),
            ),
        );
    }
}
