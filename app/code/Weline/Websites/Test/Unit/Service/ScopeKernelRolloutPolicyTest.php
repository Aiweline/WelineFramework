<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\FrontendWorkerScopeException;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeRolloutDecision;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\Websites\Service\ScopeKernelRolloutPolicy;

final class ScopeKernelRolloutPolicyTest extends TestCase
{
    public function testOffAndShadowNeverRequireAClientBinding(): void
    {
        $off = $this->policy(['mode' => 'off', 'allowlist' => [], 'shadow_sample_bp' => 10_000]);
        self::assertFalse($off->requiresBinding('http'));
        self::assertTrue($off->decide(0, 1, 1, 'normal', 'http')->isOff());

        $shadow = $this->policy(['mode' => 'shadow', 'allowlist' => [], 'shadow_sample_bp' => 2_500]);
        self::assertFalse($shadow->requiresBinding('http'));
        $decision = $shadow->decide(0, 1, 1, 'normal', 'http');
        self::assertTrue($decision->isShadow());
        self::assertFalse($decision->tokenEnabled);
        self::assertFalse($decision->isAuthoritative());
        self::assertSame(2_500, $decision->shadowSampleBasisPoints);
    }

    public function testAllowlistRequiresHttpsAndOnlyAuthorizesExactDevTestTuple(): void
    {
        $policy = $this->policy([
            'mode' => 'allowlist',
            'allowlist' => [['website_id' => 0, 'store_id' => 7, 'channel_id' => 9]],
            'shadow_sample_bp' => 0,
        ]);

        self::assertTrue($policy->requiresBinding('https'));
        $allowed = $policy->decide(0, 7, 9, 'test', 'https');
        self::assertTrue($allowed->tokenEnabled);
        self::assertTrue($allowed->isAuthoritative());

        $notListed = $policy->decide(0, 7, 10, 'normal', 'https');
        self::assertTrue($notListed->tokenEnabled);
        self::assertFalse($notListed->isAuthoritative());

        try {
            $policy->decide(0, 7, 9, 'normal', 'https');
            self::fail('A normal Store was accepted by the first allowlist cutover.');
        } catch (FrontendWorkerScopeException $exception) {
            self::assertSame('allowlist_requires_dev_test', $exception->reason);
            self::assertSame(503, $exception->httpStatus);
        }
    }

    public function testAllowlistAndOnFailClosedOnPlainHttp(): void
    {
        foreach (['allowlist', 'on'] as $mode) {
            $policy = $this->policy(['mode' => $mode, 'allowlist' => [], 'shadow_sample_bp' => 0]);
            try {
                $policy->requiresBinding('http');
                self::fail($mode . ' unexpectedly accepted HTTP.');
            } catch (FrontendWorkerScopeException $exception) {
                self::assertSame('scope_token_https_required', $exception->reason, $mode);
                self::assertSame(403, $exception->httpStatus, $mode);
            }
        }
    }

    public function testOnIsAuthoritativeForEveryValidTuple(): void
    {
        $policy = $this->policy(['mode' => 'on', 'allowlist' => [], 'shadow_sample_bp' => 0]);
        $decision = $policy->decide(0, 3, 4, 'normal', 'https');

        self::assertSame(FrontendWorkerScopeRolloutDecision::MODE_ON, $decision->mode);
        self::assertTrue($decision->tokenEnabled);
        self::assertTrue($decision->isAuthoritative());
    }

    /** @param array<string, mixed> $configuration */
    private function policy(array $configuration): ScopeKernelRolloutPolicy
    {
        $reader = (new \ReflectionClass(ConfigReader::class))->newInstanceWithoutConstructor();
        return ScopeKernelRolloutPolicy::forConfiguration($reader, $configuration);
    }
}
