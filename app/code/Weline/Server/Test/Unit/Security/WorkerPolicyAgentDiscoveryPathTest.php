<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\WorkerPolicyKernel;

/**
 * ARD/Lighthouse validators do not send a browser UA. Agent discovery documents are
 * advertised in the page head and must stay fetchable, while ordinary pages keep the
 * crawler_block protection.
 */
final class WorkerPolicyAgentDiscoveryPathTest extends TestCase
{
    public function testArdManifestAllowsNonBrowserUserAgent(): void
    {
        $kernel = WorkerPolicyKernel::instance();
        $ip = '203.0.113.211';
        $kernel->clearSecurityBans($ip, false);
        $raw = "GET /.well-known/ard.json HTTP/1.1\r\n"
            . "Host: p05113ef3.test.weline.com:9555\r\n"
            . "User-Agent: curl/8.7.1\r\n\r\n";
        $decision = $kernel->evaluate($raw, $ip);
        self::assertTrue(
            $decision->allowed,
            'agent discovery manifest must not be crawler_blocked, got: ' . $decision->reason
        );
    }

    public function testAiCatalogManifestAllowsNonBrowserUserAgent(): void
    {
        $kernel = WorkerPolicyKernel::instance();
        $ip = '203.0.113.212';
        $kernel->clearSecurityBans($ip, false);
        $raw = "GET /.well-known/ai-catalog.json HTTP/1.1\r\n"
            . "Host: p05113ef3.test.weline.com:9555\r\n"
            . "User-Agent: curl/8.7.1\r\n\r\n";
        $decision = $kernel->evaluate($raw, $ip);
        self::assertTrue(
            $decision->allowed,
            'ai-catalog manifest must not be crawler_blocked, got: ' . $decision->reason
        );
    }

    public function testOrdinaryPageStillCrawlerBlockedForBareCurl(): void
    {
        $kernel = WorkerPolicyKernel::instance();
        $ip = '203.0.113.213';
        $kernel->clearSecurityBans($ip, false);
        $raw = "GET / HTTP/1.1\r\n"
            . "Host: p05113ef3.test.weline.com:9555\r\n"
            . "User-Agent: curl/8.7.1\r\n\r\n";
        $decision = $kernel->evaluate($raw, $ip);
        self::assertFalse($decision->allowed, 'bare curl on a page must stay blocked');
        self::assertSame(
            'server.request.attack_guard',
            (string)$decision->reason,
            'unexpected deny reason: ' . $decision->reason
        );
    }
}
