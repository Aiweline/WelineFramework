<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\WorkerPolicyKernel;
use Weline\Server\Service\Security\SecurityProbeCatalog;
use Weline\Server\Service\Security\SecurityProbeTokenService;

final class SecurityProbeTokenServiceTest extends TestCase
{
    public function testIssuedTokenValidatesAndExpiresShape(): void
    {
        $service = new SecurityProbeTokenService();
        $issued = $service->issue(120);
        self::assertNotSame('', $issued['token']);
        self::assertSame(SecurityProbeCatalog::HEADER_NAME, $issued['header']);
        self::assertTrue(SecurityProbeTokenService::isValid($issued['token']));
        self::assertFalse(SecurityProbeTokenService::isValid($issued['token'] . 'x'));
        self::assertFalse(SecurityProbeTokenService::isValid(''));
    }

    public function testHeaderHasValidTokenIsCaseInsensitive(): void
    {
        $issued = (new SecurityProbeTokenService())->issue(120);
        self::assertTrue(SecurityProbeTokenService::headerHasValidToken([
            'x-weline-security-probe-token' => $issued['token'],
        ]));
        self::assertFalse(SecurityProbeTokenService::headerHasValidToken([
            'x-weline-security-probe-token' => 'bad',
        ]));
    }

    public function testProbeTokenSkipsBanButStillDeniesAttack(): void
    {
        $kernel = WorkerPolicyKernel::instance();
        $token = (new SecurityProbeTokenService())->issue(300)['token'];
        $raw = "GET /?q=UNION%20SELECT%20null-- HTTP/1.1\r\n"
            . "Host: p05113ef3.test.weline.com:9555\r\n"
            . SecurityProbeCatalog::HEADER_NAME . ": {$token}\r\n\r\n";
        $decision = $kernel->evaluate($raw, '203.0.113.80');
        self::assertFalse($decision->allowed);
        self::assertStringContainsString('probe:', $decision->reason);
        self::assertStringStartsWith('HTTP/1.1 403 ', (string)$decision->response);

        // Same IP without token should still be blocked (ban or attack deny).
        $raw2 = "GET /?q=UNION%20SELECT%20null-- HTTP/1.1\r\nHost: p05113ef3.test.weline.com:9555\r\n\r\n";
        $decision2 = $kernel->evaluate($raw2, '203.0.113.81');
        self::assertFalse($decision2->allowed);
        self::assertFalse(\str_starts_with($decision2->reason, 'probe:'));
    }

    public function testCatalogContainsControlAndDescriptions(): void
    {
        $cases = SecurityProbeCatalog::cases();
        self::assertNotEmpty($cases);
        foreach ($cases as $case) {
            self::assertNotSame('', (string)($case['id'] ?? ''));
            self::assertNotSame('', (string)($case['description'] ?? ''));
            self::assertNotEmpty($case['expect_status'] ?? []);
        }
        $ids = \array_column($cases, 'id');
        self::assertContains('control_home', $ids);
        self::assertContains('sqli_double_encoded', $ids);
        self::assertContains('sqli_union_select', $ids);
        self::assertContains('path_traversal_encoded', $ids);
        self::assertCount(20, $cases);
        self::assertSame('编码', SecurityProbeCatalog::groupLabel('encoding'));
        self::assertSame('严重', SecurityProbeCatalog::severityLabel('critical'));
    }
}
