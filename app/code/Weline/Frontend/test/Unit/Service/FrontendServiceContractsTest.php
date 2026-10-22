<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\ScopeRateLimitStateStore;
use Weline\Framework\Http\ScopeRateLimiter;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Frontend\Service\ScopeHolePunchSecurityGate;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllFrontendServiceContractsPass().
 */
final class FrontendServiceContractsTest extends TestCase
{
    public function testAllFrontendServiceContractsPass(): void
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
            'ScopeHolePunchSecurityGate::testNormalizePagePathAcceptsStorefrontShapes' => function () {
            $this->assert_ScopeHolePunchSecurityGate_testNormalizePagePathAcceptsStorefrontShapes();
        },
            'ScopeHolePunchSecurityGate::testNormalizePagePathRejectsTraversalAndJunk' => function () {
            $this->assert_ScopeHolePunchSecurityGate_testNormalizePagePathRejectsTraversalAndJunk();
        },
            'ScopeHolePunchSecurityGate::testRateLimitExhaustionReturns429Shape' => function () {
            $this->assert_ScopeHolePunchSecurityGate_testRateLimitExhaustionReturns429Shape();
        },
            'ScopeHolePunchSecurityGate::testControllerIsPostOnly' => function () {
            $this->assert_ScopeHolePunchSecurityGate_testControllerIsPostOnly();
        },
            'ScopeHolePunchSecurityGate::testOriginMatchesExpectedAcceptsWorkerListenPortVsApex' => function () {
            $this->assert_ScopeHolePunchSecurityGate_testOriginMatchesExpectedAcceptsWorkerListenPortVsApex();
        },
            'ScopeHolePunchSecurityGate::testQueryBinAndStreamReuseOriginMatchesExpected' => function () {
            $this->assert_ScopeHolePunchSecurityGate_testQueryBinAndStreamReuseOriginMatchesExpected();
        },
        ];
    }

private function assert_ScopeHolePunchSecurityGate_testNormalizePagePathAcceptsStorefrontShapes(): void
{
    $gate = $this->gate_ScopeHolePunchSecurityGate();
    self::assertSame('/', $gate->normalizePagePath(''));
    self::assertSame('/', $gate->normalizePagePath('/'));
    self::assertSame('/en/product-sku', $gate->normalizePagePath('/en/product-sku?x=1'));
    self::assertSame('/zh_Hans_CN/c', $gate->normalizePagePath('https://shop.example/zh_Hans_CN/c#frag'));
}

private function assert_ScopeHolePunchSecurityGate_testNormalizePagePathRejectsTraversalAndJunk(): void
{
    $gate = $this->gate_ScopeHolePunchSecurityGate();
    self::assertNull($gate->normalizePagePath('/../etc/passwd'));
    self::assertNull($gate->normalizePagePath('/a/../../b'));
    self::assertNull($gate->normalizePagePath("/x\x00y"));
    self::assertNull($gate->normalizePagePath('/has space'));
    self::assertNull($gate->normalizePagePath(str_repeat('/a', 2000)));
}

private function assert_ScopeHolePunchSecurityGate_testRateLimitExhaustionReturns429Shape(): void
{
    $gate = $this->gate_ScopeHolePunchSecurityGate();
    $scope = ScopeIdentity::channel(0, 'default', 'main', 'web', ScopeIdentity::MODE_TEST);
    $ip = '203.0.113.50';
    $ok = 0;
    $denied = null;
    for ($i = 0; $i < 40; $i++) {
        $result = $gate->assertRateLimit($scope, $ip);
        if (($result['ok'] ?? false) === true) {
            $ok++;
            continue;
        }
        $denied = $result;
        break;
    }
    self::assertGreaterThan(0, $ok);
    self::assertIsArray($denied);
    self::assertSame(ScopeHolePunchSecurityGate::ERROR_RATE, $denied['error']);
    self::assertSame(429, $denied['status']);
    self::assertArrayHasKey('retry_after', $denied);
}

private function assert_ScopeHolePunchSecurityGate_testControllerIsPostOnly(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/Controller/Api/ScopeBootstrap.php');
    self::assertStringContainsString('function postIndex', $src);
    self::assertStringNotContainsString('function getIndex', $src);
    self::assertStringContainsString('ScopeHolePunchSecurityGate', $src);
    $gate = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Frontend' . '/Service/ScopeHolePunchSecurityGate.php');
    self::assertStringContainsString('RequestAuthority::allowsPlainHttp', $gate);
    self::assertStringContainsString("'theme_published_version_id'", $src);
    self::assertStringContainsString('StorefrontGenerationIdentity', $src);
    self::assertStringNotContainsString('currentDeployVersion', $src);
    self::assertStringNotContainsString("'bootstrap_id'", $src);
}

private function assert_ScopeHolePunchSecurityGate_testOriginMatchesExpectedAcceptsWorkerListenPortVsApex(): void
{
    $gate = $this->gate_ScopeHolePunchSecurityGate();
    self::assertTrue($gate->originMatchesExpected('https://p05113ef3.test.weline.com:9555', 'https://p05113ef3.test.weline.com'));
    self::assertTrue($gate->originMatchesExpected('https://p05113ef3.test.weline.com', 'https://p05113ef3.test.weline.com:9555'));
    self::assertTrue($gate->originMatchesExpected('https://p05113ef3.test.weline.com', 'https://p05113ef3.test.weline.com'));
    self::assertFalse($gate->originMatchesExpected('https://evil.example.com:9555', 'https://p05113ef3.test.weline.com'));
    self::assertFalse($gate->originMatchesExpected('http://p05113ef3.test.weline.com:9555', 'https://p05113ef3.test.weline.com'));
    self::assertFalse($gate->originMatchesExpected('https://p05113ef3.test.weline.com:9555', 'https://p05113ef3.test.weline.com:9443'));
}

private function assert_ScopeHolePunchSecurityGate_testQueryBinAndStreamReuseOriginMatchesExpected(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Frontend';
    $queryBin = (string) \file_get_contents($moduleRoot . '/Controller/Api/QueryBin.php');
    $stream = (string) \file_get_contents($moduleRoot . '/Controller/Api/Stream.php');
    self::assertStringContainsString('ScopeHolePunchSecurityGate', $queryBin);
    self::assertStringContainsString('originMatchesExpected', $queryBin);
    self::assertStringContainsString('ScopeHolePunchSecurityGate', $stream);
    self::assertStringContainsString('originMatchesExpected', $stream);
    // Forbid reintroducing exact Origin string equality (apex vs :9555 false 401).
    self::assertStringNotContainsString("\\rtrim(\$origin, '/') !== \$currentOrigin", $queryBin);
    self::assertStringNotContainsString("\\rtrim(\$origin, '/') !== \$currentOrigin", $stream);
}

private function gate_ScopeHolePunchSecurityGate(): ScopeHolePunchSecurityGate
{
    // Fresh in-memory atomic double via default store when available;
    // ScopeRateLimiter construction may throw if driver missing — then skip.
    try {
        return new ScopeHolePunchSecurityGate(new ScopeRateLimiter());
    } catch (\Throwable $e) {
        self::markTestSkipped('ScopeRateLimiter unavailable: ' . $e->getMessage());
    }
}
}
