<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Security;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Security\ContentSecurityPolicyNormalizer;
use Weline\Framework\Http\Security\CspSourceContribution;
use Weline\Framework\Http\Security\CspSourceContributionRegistry;

final class CspSourceContributionRegistryTest extends TestCase
{
    public function testUnionMergesDirectiveSources(): void
    {
        $n = new ContentSecurityPolicyNormalizer();
        $merged = $n->union(
            "script-src 'self' https://a.example",
            "script-src https://b.example; img-src https:",
        );
        self::assertSame(
            "img-src https:; script-src 'self' https://a.example https://b.example",
            $merged,
        );
    }

    public function testForcedContributionsAggregateAsAppDefaults(): void
    {
        $registry = new CspSourceContributionRegistry(
            forcedContributions: [
                new CspSourceContribution([
                    'script-src' => ['https://sdk.fixture.example'],
                    'connect-src' => ['https://sdk.fixture.example'],
                ]),
            ],
        );
        $policy = $registry->aggregatePolicy();
        self::assertStringContainsString('https://sdk.fixture.example', $policy);
        self::assertSame($policy, $registry->appDefaultPolicy());
        self::assertSame($policy, $registry->aggregatePolicy());
    }

    public function testEmptyAggregateIsNotPermanentlyCached(): void
    {
        $registry = new CspSourceContributionRegistry(
            forcedContributions: [
                new CspSourceContribution([
                    'script-src' => ['https://www.recaptcha.net'],
                ]),
            ],
        );
        $first = $registry->aggregatePolicy();
        self::assertStringContainsString('https://www.recaptcha.net', $first);
        self::assertSame($first, $registry->aggregatePolicy());
    }

    public function testEmptyForcedArrayDoesNotSkipExtendsLoading(): void
    {
        $withEmpty = new CspSourceContributionRegistry(forcedContributions: []);
        $withNull = new CspSourceContributionRegistry(forcedContributions: null);
        $ref = new \ReflectionClass(CspSourceContributionRegistry::class);
        $method = $ref->getMethod('contributions');
        $method->setAccessible(true);
        self::assertSame(
            \array_map(static fn (CspSourceContribution $c): array => $c->directives, $method->invoke($withNull)),
            \array_map(static fn (CspSourceContribution $c): array => $c->directives, $method->invoke($withEmpty)),
        );
    }

    public function testContainsAllRejectsMissingAppDefaultSource(): void
    {
        $n = new ContentSecurityPolicyNormalizer();
        self::assertTrue($n->containsAll(
            "script-src 'self' https://sdk.fixture.example",
            'script-src https://sdk.fixture.example',
        ));
        self::assertFalse($n->containsAll(
            "script-src 'self'",
            'script-src https://sdk.fixture.example',
        ));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(ContentSecurityPolicyNormalizer::ERROR_APP_DEFAULTS);
        $n->assertContainsAppDefaults(
            "script-src 'self'",
            'script-src https://sdk.fixture.example',
        );
    }
}
