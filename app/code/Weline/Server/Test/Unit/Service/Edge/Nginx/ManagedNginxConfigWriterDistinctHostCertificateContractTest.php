<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;

/**
 * DNS-only hosts (mail.*) with their own leaf must not share the www server{}
 * certificate — otherwise SNI presents www and browsers show「不安全」.
 *
 * Source contract (no full Env bootstrap): writer must peel uncovered hosts into
 * dedicated server blocks with their own ssl_certificate paths.
 */
final class ManagedNginxConfigWriterDistinctHostCertificateContractTest extends TestCase
{
    public function testWriterSourcePeelsDistinctCertificateHosts(): void
    {
        // __DIR__=.../Server/Test/Unit/Service/Edge/Nginx → up 5 = .../Server
        $path = \dirname(__DIR__, 5)
            . \DIRECTORY_SEPARATOR . 'Service'
            . \DIRECTORY_SEPARATOR . 'Edge'
            . \DIRECTORY_SEPARATOR . 'Nginx'
            . \DIRECTORY_SEPARATOR . 'ManagedNginxConfigWriter.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('peelDistinctCertificateHosts', $src);
        self::assertStringContainsString('buildDistinctCertificateHostServerBlocks', $src);
        self::assertStringContainsString('certificateCoversHostname', $src);
        self::assertStringContainsString('resolveHostSslMaterial', $src);
        self::assertStringContainsString('WLS distinct-certificate host', $src);
        self::assertStringContainsString('distinct_certificate_hosts', $src);
        self::assertStringContainsString('[$names, $distinctHostSsl] = $this->peelDistinctCertificateHosts($names, $ssl)', $src);
    }
}
