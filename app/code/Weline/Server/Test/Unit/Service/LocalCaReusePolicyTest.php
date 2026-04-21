<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\SslCertificateService;

class LocalCaReusePolicyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!\defined('BP')) {
            \define('BP', \getcwd() . DIRECTORY_SEPARATOR);
        }
        if (!\defined('DS')) {
            \define('DS', DIRECTORY_SEPARATOR);
        }
        if (!\defined('APP_PATH')) {
            \define('APP_PATH', BP . 'app' . DS);
        }
        if (!\defined('APP_CODE_PATH')) {
            \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
        }
        if (!\defined('APP_ETC_PATH')) {
            \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
        }
        if (!\defined('DEV_PATH')) {
            \define('DEV_PATH', BP . 'dev' . DS);
        }
        if (!\defined('PUB')) {
            \define('PUB', BP . 'pub' . DS);
        }
        if (!\defined('IS_WIN')) {
            \define('IS_WIN', \PHP_OS_FAMILY === 'Windows');
        }
    }

    public function testBuildServerLeafOpenSslConfigIncludesAuthorityAccessAndCrlDistribution(): void
    {
        $service = new class extends SslCertificateService {
            public function __construct()
            {
            }

            public function exposeBuildServerLeafOpenSslConfig(
                string $domain,
                array $sanEntries,
                string $caIssuersUri,
                string $crlDistributionUri
            ): string {
                return $this->buildServerLeafOpenSslConfig($domain, $sanEntries, $caIssuersUri, $crlDistributionUri);
            }
        };

        $config = $service->exposeBuildServerLeafOpenSslConfig(
            '*.weline.test',
            ['dns' => ['*.weline.test'], 'ip' => []],
            'file:///E:/tmp/rootCA.pem',
            'file:///E:/tmp/rootCA.crl'
        );

        self::assertStringContainsString(
            'authorityInfoAccess = caIssuers;URI:file:///E:/tmp/rootCA.pem',
            $config
        );
        self::assertStringContainsString(
            'crlDistributionPoints = URI:file:///E:/tmp/rootCA.crl',
            $config
        );
    }

    public function testPrepareExistingLocalCaCertificateForReuseRejectsMissingPath(): void
    {
        $service = new class extends SslCertificateService {
            public function __construct()
            {
            }

            public function exposePrepare(string $certPath): bool
            {
                return $this->prepareExistingLocalCaCertificateForReuse($certPath);
            }
        };

        self::assertFalse($service->exposePrepare(BP . 'var' . DS . 'server' . DS . 'missing-local-ca.pem'));
    }

    public function testEnsureReusableLocalCaCertificateRegeneratesWhenExistingLeafIsNotBrowserReady(): void
    {
        $service = new class extends SslCertificateService {
            public int $generateCalls = 0;

            public function __construct()
            {
            }

            protected function prepareExistingLocalCaCertificateForReuse(string $certPath): bool
            {
                return false;
            }

            protected function shouldUseTrustedLocalCertificateAuthority(string $domain): bool
            {
                return true;
            }

            public function generateLocalCaSignedCertificate(string $domain, int $websiteId = 0, int $validDays = 825): array
            {
                $this->generateCalls++;

                return [
                    'success' => true,
                    'cert_path' => 'generated/' . $domain . '/fullchain.pem',
                    'key_path' => 'generated/' . $domain . '/privkey.pem',
                ];
            }

            public function getCertificateDir(string $domain): string
            {
                return 'generated/' . $domain . '/';
            }

            public function exposeEnsureReusable(string $domain, string $certPath, int $websiteId = 0): array
            {
                return $this->ensureReusableLocalCaCertificate($domain, $certPath, $websiteId);
            }
        };

        $result = $service->exposeEnsureReusable('*.weline.test', 'stale/fullchain.pem');

        self::assertSame(1, $service->generateCalls);
        self::assertTrue($result['usable']);
        self::assertTrue($result['regenerated']);
        self::assertSame('generated/*.weline.test/fullchain.pem', $result['cert_path']);
        self::assertSame('generated/*.weline.test/privkey.pem', $result['key_path']);
    }
}
