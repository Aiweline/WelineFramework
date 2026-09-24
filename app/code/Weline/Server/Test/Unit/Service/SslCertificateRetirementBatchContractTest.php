<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\SslCertificateService;

/** WS3-A: retirement replay primes cert rows with one cert_id IN (...). */
final class SslCertificateRetirementBatchContractTest extends TestCase
{
    public function testReplayPrimesCertificateBatchViaInQuery(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/SslCertificateService.php',
        );
        self::assertStringContainsString('function primeRetirementCertificateBatch', $src);
        self::assertStringContainsString('function loadRetirementCertificate', $src);
        self::assertStringContainsString("schema_fields_ID, \\array_values(\$ids), 'IN'", $src);
        self::assertStringContainsString('primeRetirementCertificateBatch($pending)', $src);
        self::assertStringContainsString('loadRetirementCertificate($certId)', $src);
        self::assertTrue(method_exists(SslCertificateService::class, 'replayPendingCertificateRetirements'));
    }
}
