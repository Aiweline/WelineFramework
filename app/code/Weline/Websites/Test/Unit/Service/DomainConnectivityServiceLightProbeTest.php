<?php
declare(strict_types=1);

namespace {
    if (!\function_exists('__')) {
        function __($text, $args = null): string
        {
            if (!\is_array($args)) {
                return (string) $text;
            }

            $replacements = [];
            foreach (\array_values($args) as $index => $value) {
                $replacements['%{' . ($index + 1) . '}'] = (string) $value;
            }

            return \strtr((string) $text, $replacements);
        }
    }
}

namespace Weline\Websites\Test\Unit\Service {
    use PHPUnit\Framework\TestCase;
    use Weline\Websites\Service\DomainConnectivityService;

    /**
     * 轻探测：返回结构 / 失败不抛异常 / 证书 notAfter 解析；DNS 与 TLS 由桩替代，不连网
     */
    final class DomainConnectivityServiceLightProbeTest extends TestCase
    {
        public function testReturnStructureAndKeys(): void
        {
            $result = (new DomainConnectivityService())->probeLight('  ');

            $this->assertSame(['status', 'checked_at', 'detail', 'cert_expires_at', 'cert_days_left'], \array_keys($result));
            $this->assertSame('error', $result['status']);
            $this->assertNull($result['cert_expires_at']);
            $this->assertNull($result['cert_days_left']);
            $this->assertNotSame('', $result['checked_at']);
        }

        public function testDnsFailureReturnsErrorWithoutThrowing(): void
        {
            $service = new class extends DomainConnectivityService {
                protected function lightResolveIps(string $domain): array
                {
                    return [];
                }
            };

            $result = $service->probeLight('no-dns.example.com');

            $this->assertSame('error', $result['status']);
            $this->assertStringContainsString('DNS', $result['detail']);
        }

        public function testTlsFailureReturnsErrorWithoutCertificate(): void
        {
            $service = new class extends DomainConnectivityService {
                protected function lightResolveIps(string $domain): array
                {
                    return ['127.0.0.1'];
                }

                protected function lightTlsHandshake(string $domain): array
                {
                    return ['ok' => false, 'error' => 'TLS 握手失败', 'certificate' => null];
                }
            };

            $result = $service->probeLight('tls-down.example.com');

            $this->assertSame('error', $result['status']);
            $this->assertStringContainsString('TLS 握手失败', $result['detail']);
            $this->assertNull($result['cert_days_left']);
        }

        public function testCertificateNotAfterIsParsedIntoDaysLeft(): void
        {
            $key = \openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
            $this->assertNotFalse($key, 'openssl 生成私钥失败');
            $csr = \openssl_csr_new(['commonName' => 'example.com'], $key);
            $this->assertNotFalse($csr, 'openssl 生成 CSR 失败');
            $certificate = \openssl_csr_sign($csr, null, $key, 30);
            $this->assertNotFalse($certificate, 'openssl 签发测试证书失败');

            $service = new class ($certificate) extends DomainConnectivityService {
                public function __construct(private mixed $certificate)
                {
                }

                protected function lightResolveIps(string $domain): array
                {
                    return ['127.0.0.1'];
                }

                protected function lightTlsHandshake(string $domain): array
                {
                    return ['ok' => true, 'error' => '', 'certificate' => $this->certificate];
                }
            };

            $result = $service->probeLight('example.com');

            $this->assertSame('ok', $result['status']);
            $this->assertNotNull($result['cert_expires_at']);
            $this->assertIsInt($result['cert_days_left']);
            $this->assertGreaterThanOrEqual(28, $result['cert_days_left']);
            $this->assertLessThanOrEqual(30, $result['cert_days_left']);
        }
    }
}
