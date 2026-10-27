<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\SslCertificateService;

require_once \dirname(__DIR__, 7) . '/app/bootstrap_phpunit.php';

final class StartPublicHostDnsGateTest extends TestCase
{
    private const HOST = 'www.example.com';
    private const CF_EDGE_IPS = ['172.67.162.81', '104.21.58.174', '2606:4700:3033::ac43:a251'];
    private const SERVER_IPS = ['203.0.113.10'];

    public function testCloudflareProxiedHostWithoutExplicitAllowlistStaysBlocked(): void
    {
        $result = $this->validate([], self::CF_EDGE_IPS);

        self::assertFalse($result['success']);
        self::assertStringContainsString('cdn_proxied_hosts', (string)$result['message']);
    }

    public function testExplicitlyAllowlistedCloudflareProxiedHostIsSkipped(): void
    {
        $result = $this->validate([self::HOST], self::CF_EDGE_IPS);

        self::assertTrue($result['success']);
        self::assertTrue($result['skipped']);
        self::assertTrue($result['proxied']);
        self::assertSame(self::CF_EDGE_IPS, $result['resolved_ips']);
    }

    public function testAllowlistSupportsWildcardSubdomains(): void
    {
        $result = $this->validate(['*.example.com'], self::CF_EDGE_IPS);

        self::assertTrue($result['success']);
        self::assertTrue($result['proxied']);
    }

    public function testAllowlistDoesNotSkipNonCloudflareResolution(): void
    {
        $result = $this->validate([self::HOST], ['198.51.100.7']);

        self::assertFalse($result['success']);
        self::assertStringContainsString('未解析到当前服务器 IP', (string)$result['message']);
    }

    public function testServerIpMatchStillSucceedsWithoutAllowlist(): void
    {
        $result = $this->validate([], self::SERVER_IPS);

        self::assertTrue($result['success']);
        self::assertSame(self::SERVER_IPS, $result['server_ips']);
        self::assertArrayNotHasKey('proxied', $result);
    }

    /**
     * @param list<string> $allowedHosts
     * @param list<string> $resolvedIps
     * @return array<string,mixed>
     */
    private function validate(array $allowedHosts, array $resolvedIps): array
    {
        $start = new class($allowedHosts, $resolvedIps) extends Start {
            /** @param list<string> $allowedHosts @param list<string> $resolvedIps */
            public function __construct(private array $allowedHosts, private array $resolvedIps)
            {
            }

            protected function getEnvConfig(): array
            {
                return [
                    'wls' => [
                        'host_guard' => [
                            'cdn_proxied_hosts' => $this->allowedHosts,
                        ],
                    ],
                ];
            }

            protected function resolvePublicHostIps(string $host): array
            {
                unset($host);

                return $this->resolvedIps;
            }

            protected function detectCurrentServerIps(): array
            {
                return ['203.0.113.10'];
            }
        };

        $sslService = new class extends SslCertificateService {
            public function __construct()
            {
            }

            public function isLocalDomain(string $domain): bool
            {
                unset($domain);

                return false;
            }
        };

        return $this->invokeProtected($start, 'validatePublicHostResolvesToCurrentServer', self::HOST, $sslService);
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
