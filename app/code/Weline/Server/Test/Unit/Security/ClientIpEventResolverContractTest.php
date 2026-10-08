<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\ClientIpEventResolver;

final class ClientIpEventResolverContractTest extends TestCase
{
    public function testEventNameIsServerOwnedSecurityResolveClientIp(): void
    {
        self::assertSame(
            'Weline_Server::security::resolve_client_ip',
            ClientIpEventResolver::EVENT,
        );
    }

    public function testUntrustedProxyNeverDispatchesAndKeepsDefaultIp(): void
    {
        $out = ClientIpEventResolver::refine(
            '203.0.113.9',
            false,
            '198.51.100.1',
            ['cf-connecting-ip' => '203.0.113.50'],
            ['127.0.0.0/8'],
        );

        self::assertSame('203.0.113.9', $out['ip']);
        self::assertNull($out['resolved_by']);
    }

    public function testWorkerPolicyKernelCallsClientIpEventResolverAfterIdentity(): void
    {
        $src = (string)\file_get_contents(
            BP . '/app/code/Weline/Server/Security/WorkerPolicyKernel.php',
        );
        self::assertStringContainsString('ClientIpEventResolver::refine(', $src);
        self::assertStringContainsString("\$clientIp = \$refined['ip'];", $src);
        self::assertStringNotContainsString('CF-Connecting-IP', $src);
        self::assertStringNotContainsString('cf-connecting-ip', $src);
    }

    public function testManagedNginxForwardsCfConnectingIpHeader(): void
    {
        $src = (string)\file_get_contents(
            BP . '/app/code/Weline/Server/Service/Edge/Nginx/ManagedNginxConfigWriter.php',
        );
        self::assertStringContainsString(
            'proxy_set_header CF-Connecting-IP \$http_cf_connecting_ip;',
            $src,
        );
        self::assertGreaterThanOrEqual(
            7,
            \substr_count($src, 'proxy_set_header CF-Connecting-IP \$http_cf_connecting_ip;'),
        );
    }

    public function testEventRegisteredInServerEventPhp(): void
    {
        $events = include BP . '/app/code/Weline/Server/event.php';
        self::assertArrayHasKey(ClientIpEventResolver::EVENT, $events);
        self::assertSame(
            'security/解析客户端IP.md',
            $events[ClientIpEventResolver::EVENT]['doc'] ?? null,
        );
    }
}
