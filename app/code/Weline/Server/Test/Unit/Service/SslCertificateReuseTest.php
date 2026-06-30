<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\SslCertificateService;

final class SslCertificateReuseTest extends TestCase
{
    public function testCanReuseConfiguredCertificateAcceptsMatchingPrivateKey(): void
    {
        $key = \openssl_pkey_new($this->opensslKeyArgs());
        if ($key === false) {
            self::markTestSkipped('OpenSSL key generation is not available in this PHP runtime.');
        }
        self::assertNotFalse($key);

        $exported = \openssl_pkey_export($key, $keyPem, null, $this->opensslExportArgs());
        self::assertTrue($exported);

        $csr = \openssl_csr_new(['commonName' => 'pre.example.com'], $key, $this->opensslExportArgs());
        self::assertNotFalse($csr);

        $cert = \openssl_csr_sign($csr, null, $key, 30, $this->opensslExportArgs());
        self::assertNotFalse($cert);

        $exportedCert = \openssl_x509_export($cert, $certPem);
        self::assertTrue($exportedCert);

        $dir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-cert-reuse-' . \str_replace('.', '', \uniqid('', true)) . DIRECTORY_SEPARATOR;
        \mkdir($dir, 0777, true);
        $certPath = $dir . 'fullchain.pem';
        $keyPath = $dir . 'privkey.pem';
        \file_put_contents($certPath, $certPem);
        \file_put_contents($keyPath, $keyPem);

        try {
            $service = new class extends SslCertificateService {
                public function __construct()
                {
                }
            };

            self::assertTrue($service->canReuseConfiguredCertificate($certPath, $keyPath));
        } finally {
            @\unlink($certPath);
            @\unlink($keyPath);
            @\rmdir($dir);
        }
    }

    private function opensslKeyArgs(): array
    {
        return $this->withOpenSslConfig([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
    }

    private function opensslExportArgs(): array
    {
        return $this->withOpenSslConfig(['digest_alg' => 'sha256']);
    }

    private function withOpenSslConfig(array $args): array
    {
        foreach ($this->openSslConfigCandidates() as $configPath) {
            if (\is_file($configPath)) {
                $args['config'] = $configPath;
                break;
            }
        }

        return $args;
    }

    /**
     * @return list<string>
     */
    private function openSslConfigCandidates(): array
    {
        return \array_values(\array_filter([
            (string)\getenv('OPENSSL_CONF'),
            \dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            'C:\Program Files\Common Files\SSL\openssl.cnf',
        ]));
    }
}
