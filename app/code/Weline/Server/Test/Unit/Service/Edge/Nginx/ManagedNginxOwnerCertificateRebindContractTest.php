<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

/**
 * Legacy owners (certificate_generation_managed=false) must rebind to an
 * active project certificate generation on reload instead of hard-failing,
 * otherwise Edge conf bumps (|fpc2) can never land.
 */
final class ManagedNginxOwnerCertificateRebindContractTest extends TestCase
{
    public function testResolveOwnerCertificateGenerationRebindsUnmanagedOwner(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5) . '/Service/Edge/Nginx/ManagedNginxService.php'
        );
        self::assertStringContainsString(
            'resolveActiveCertificateGenerationForOwner',
            $source,
        );
        self::assertStringContainsString(
            'Owner started on mutable app/etc/ssl',
            $source,
        );
        // Hard refuse without rebind attempt must not remain the only path.
        $methodPos = \strpos($source, 'function resolveOwnerCertificateGeneration(');
        self::assertNotFalse($methodPos);
        $next = \strpos($source, 'function resolveActiveCertificateGenerationForOwner(', $methodPos);
        self::assertNotFalse($next);
        $body = \substr($source, $methodPos, $next - $methodPos);
        self::assertStringContainsString(
            'resolveActiveCertificateGenerationForOwner',
            $body,
        );
        self::assertStringNotContainsString(
            'Managed Nginx owner is not bound to an immutable certificate generation.',
            $body,
        );
    }

    public function testUnmanagedOwnerRebindsToProjectActiveGenerationWhenPresent(): void
    {
        $domain = 'p05113ef3.test.weline.com';
        $store = new ProjectCertificateGenerationStore();
        try {
            $active = $store->active($domain);
        } catch (\Throwable) {
            self::markTestSkipped('ProjectCertificateGenerationStore unavailable.');
        }
        if (!\is_array($active) || (int)($active['generation'] ?? 0) < 1) {
            self::markTestSkipped('No active certificate generation for ' . $domain);
        }

        $service = ManagedNginxService::fromEnv();
        $method = new \ReflectionMethod($service, 'resolveOwnerCertificateGeneration');
        $method->setAccessible(true);
        $resolved = $method->invoke($service, [
            'certificate_generation_managed' => false,
            'certificate_domain' => '',
            'certificate_generation' => 0,
            'server_names' => [$domain],
        ]);

        self::assertIsArray($resolved);
        self::assertSame(
            (int)$active['generation'],
            (int)($resolved['generation'] ?? 0),
        );
        self::assertSame(
            \strtolower((string)$active['source_digest']),
            \strtolower((string)($resolved['source_digest'] ?? '')),
        );
    }
}
