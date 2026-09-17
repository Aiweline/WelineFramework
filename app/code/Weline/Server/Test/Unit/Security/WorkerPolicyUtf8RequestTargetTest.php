<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\WorkerPolicyKernel;
use Weline\Server\Service\Security\SecurityProbeTokenService;

final class WorkerPolicyUtf8RequestTargetTest extends TestCase
{
    public function testInvalidUtf8PathIsDeniedWith400(): void
    {
        $kernel = WorkerPolicyKernel::instance();
        $raw = "GET /%ef%bc%5f HTTP/1.1\r\nHost: p05113ef3.test.weline.com:9555\r\n\r\n";
        $decision = $kernel->evaluate($raw, '203.0.113.10');
        self::assertFalse($decision->allowed);
        self::assertSame('request_shape:invalid_path', $decision->reason);
        self::assertIsString($decision->response);
        self::assertStringStartsWith('HTTP/1.1 400 ', (string)$decision->response);
        self::assertStringContainsString('Bad Request', (string)$decision->response);
    }

    public function testInvalidUtf8PathWithProbeTokenDoesNotBanPeer(): void
    {
        $token = (new SecurityProbeTokenService())->issue(300)['token'];
        $kernel = WorkerPolicyKernel::instance();
        $ip = '203.0.113.88';
        $raw = "GET /%ef%bc%5f HTTP/1.1\r\nHost: p05113ef3.test.weline.com:9555\r\n"
            . 'X-Weline-Security-Probe-Token: ' . $token . "\r\n\r\n";
        $decision = $kernel->evaluate($raw, $ip);
        self::assertFalse($decision->allowed);
        self::assertSame('probe:request_shape:invalid_path', $decision->reason);

        $home = $kernel->evaluate(
            "GET / HTTP/1.1\r\nHost: p05113ef3.test.weline.com:9555\r\n\r\n",
            $ip,
        );
        self::assertFalse(
            \str_contains((string)$home->reason, 'shared_ban'),
            'probe token must suppress request_shape ban, got: ' . $home->reason,
        );
    }

    public function testDoubleEncodedSqlProbeIsDenied(): void
    {
        $kernel = WorkerPolicyKernel::instance();
        // %2527 → %27 → ' ; OR 1=1 should match malicious_uri after decode variants.
        $raw = "GET /search?q=%2527%20OR%201%3D1 HTTP/1.1\r\nHost: p05113ef3.test.weline.com:9555\r\n\r\n";
        $decision = $kernel->evaluate($raw, '203.0.113.11');
        self::assertFalse($decision->allowed);
        self::assertTrue(
            \str_contains($decision->reason, 'attack')
            || \str_contains($decision->reason, 'shared_ban')
            || \str_contains($decision->reason, 'request_shape'),
            'expected attack or follow-on ban, got: ' . $decision->reason
        );
    }

    public function testValidUtf8PathIsNotShapeRejected(): void
    {
        $kernel = WorkerPolicyKernel::instance();
        $raw = "GET /zh_Hans_CN HTTP/1.1\r\nHost: p05113ef3.test.weline.com:9555\r\n\r\n";
        $decision = $kernel->evaluate($raw, '203.0.113.12');
        if (!$decision->allowed) {
            self::assertFalse(
                \str_starts_with($decision->reason, 'request_shape'),
                'valid path should not fail request_shape, got: ' . $decision->reason
            );
        } else {
            self::assertTrue($decision->allowed);
        }
    }

    public function testBareUtf8QueryIsNotShapeRejected(): void
    {
        $kernel = WorkerPolicyKernel::instance();
        $ip = '203.0.113.151';
        $kernel->clearSecurityBans($ip, false);
        // PHP parse_url() mangles bare UTF-8 queries; policy must split on '?' instead.
        $raw = "GET /catalogsearch/result?q=凤鸣在竹 HTTP/1.1\r\n"
            . "Host: p05113ef3.test.weline.com:9555\r\n"
            . "User-Agent: curl/8.7.1\r\nAccept: */*\r\n\r\n";
        $decision = $kernel->evaluate($raw, $ip);
        self::assertFalse(
            \str_starts_with((string)$decision->reason, 'request_shape'),
            'bare UTF-8 query must not be invalid_query, got: ' . $decision->reason
        );
        self::assertFalse(
            \str_contains((string)$decision->reason, 'shared_ban'),
            'bare UTF-8 query must not ban peer, got: ' . $decision->reason
        );
    }

    public function testInvalidUtf8QueryBytesStillDenied(): void
    {
        $kernel = WorkerPolicyKernel::instance();
        $ip = '203.0.113.152';
        $kernel->clearSecurityBans($ip, false);
        // Literally invalid UTF-8 in query (0xef 0xbc 0x5f), not a parse_url artifact.
        $bad = '/' . \pack('H*', 'efbc5f');
        $raw = 'GET /search?q=' . $bad . " HTTP/1.1\r\nHost: p05113ef3.test.weline.com:9555\r\n\r\n";
        $decision = $kernel->evaluate($raw, $ip);
        self::assertFalse($decision->allowed);
        self::assertSame('request_shape:invalid_query', $decision->reason);
    }

    public function testLoopbackRequestShapeDoesNotBanSite(): void
    {
        $kernel = WorkerPolicyKernel::instance();
        $kernel->clearSecurityBans('127.0.0.1', false);
        $bad = '/' . \pack('H*', 'efbc5f');
        $raw = 'GET /search?q=' . $bad . " HTTP/1.1\r\nHost: p05113ef3.test.weline.com:9555\r\n\r\n";
        $denied = $kernel->evaluate($raw, '127.0.0.1');
        self::assertFalse($denied->allowed);
        self::assertSame('request_shape:invalid_query', $denied->reason);

        $home = $kernel->evaluate(
            "GET / HTTP/1.1\r\nHost: p05113ef3.test.weline.com:9555\r\n\r\n",
            '127.0.0.1',
        );
        self::assertFalse(
            \str_contains((string)$home->reason, 'shared_ban'),
            'loopback request_shape must not shared_ban the site, got: ' . $home->reason
        );
    }
}
