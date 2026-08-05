<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Security;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Security\ContentSecurityPolicyNormalizer;
use Weline\Framework\Http\Security\EmptySecurityHeaderPolicyOverrideProvider;
use Weline\Framework\Http\Security\InMemorySecurityPolicyLkgRepository;
use Weline\Framework\Http\Security\SecurityHeaderPolicyService;
use Weline\Framework\Http\Security\SecurityPolicyLkgGate;

/**
 * TEST-P1D-01 / TEST-SEC-08 子集：CSP 只收紧、弱值拒绝、LKG 阻断。
 */
final class SecurityHeaderPolicyServiceTest extends TestCase
{
    private SecurityHeaderPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SecurityHeaderPolicyService(
            lkgGate: new SecurityPolicyLkgGate(new InMemorySecurityPolicyLkgRepository()),
            overrideProvider: new EmptySecurityHeaderPolicyOverrideProvider(),
        );
        $this->service->lkgGate()->clear();
        Env::getInstance()->reload();
    }

    protected function tearDown(): void
    {
        $this->service->lkgGate()->clear();
        Env::getInstance()->reload();
        parent::tearDown();
    }

    public function testWeakerChildCspRejectedWithFixedError(): void
    {
        Env::getInstance()->applyRuntimeConfig([
            'security' => [
                'headers' => [
                    'csp' => "default-src 'self'",
                ],
            ],
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(ContentSecurityPolicyNormalizer::ERROR_WEAKER);
        $this->service->assertOverrideNotWeaker([
            'csp' => "default-src 'self' https://evil.example",
        ]);
    }

    public function testTighterChildCspAcceptedAndIntersected(): void
    {
        Env::getInstance()->applyRuntimeConfig([
            'security' => [
                'headers' => [
                    'csp' => "default-src 'self' https://cdn.example",
                ],
            ],
        ]);
        $this->service->assertOverrideNotWeaker([
            'csp' => "default-src 'self'",
        ]);
        $effective = $this->service->resolveEffective([
            'csp' => "default-src 'self'",
        ]);
        self::assertSame("default-src 'self'", $effective['csp']);
    }

    public function testActivateWithoutLkgBlocked(): void
    {
        Env::getInstance()->applyRuntimeConfig([
            'security' => [
                'headers' => [
                    'csp' => "default-src 'self'",
                ],
            ],
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(SecurityPolicyLkgGate::ERROR_LKG_MISSING);
        $this->service->assertCanActivate(['csp' => "default-src 'self'"]);
    }

    public function testActivateRequiresMatchingVerifiedLkg(): void
    {
        Env::getInstance()->applyRuntimeConfig([
            'security' => [
                'headers' => [
                    'csp' => "default-src 'self'",
                ],
            ],
        ]);
        $this->service->registerLkg();
        $this->service->assertCanActivate([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(SecurityPolicyLkgGate::ERROR_LKG_MISMATCH);
        // 不同策略 digest → 拒绝跨版本/未验证切换
        $this->service->assertCanActivate([
            'csp' => "default-src 'none'",
        ]);
    }

    public function testCanonicalNormalizeIsStable(): void
    {
        $n = new ContentSecurityPolicyNormalizer();
        $a = $n->canonicalize("script-src 'self'; default-src 'self'");
        $b = $n->canonicalize("default-src 'self'; script-src 'self'");
        self::assertSame($a, $b);
        self::assertSame("default-src 'self'; script-src 'self'", $a);
    }

    public function testVerifiedLkgIsSharedAcrossGateInstances(): void
    {
        $repository = new InMemorySecurityPolicyLkgRepository();
        $first = new SecurityPolicyLkgGate($repository);
        $second = new SecurityPolicyLkgGate($repository);

        $first->verifyAndStore("default-src 'self'", scopeKey: 'store-a');
        $second->assertCanActivate("default-src 'self'", scopeKey: 'store-a');

        self::assertSame('store-a', $second->getVerified('store-a')['scope_key'] ?? null);
    }
}
