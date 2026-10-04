<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;

final class ManagedNginxConfigWriterWwwCanonicalRedirectTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-nginx-www-redirect-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
        if (!\defined('BP')) {
            \define('BP', $this->root);
        }
        if (!\defined('Weline\\Framework\\App\\BP')) {
            \define('Weline\\Framework\\App\\BP', $this->root);
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testApexHostIsPeeledIntoWwwCanonicalRedirectServer(): void
    {
        $sslDir = $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc'
            . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'www.example.com';
        self::assertTrue(\mkdir($sslDir, 0700, true));
        $this->writeSelfSignedPair($sslDir, ['www.example.com', 'example.com']);

        $paths = new ManagedNginxPaths($this->root, [
            'managed' => true,
            'listen_http' => 18080,
            'listen_https' => 18443,
            'server_names' => ['www.example.com', 'example.com'],
            'public_host' => 'www.example.com',
        ]);
        $paths->ensureRuntimeDirectories();
        $writer = new ManagedNginxConfigWriter($paths);
        $written = $writer->write(
            19090,
            '127.0.0.1',
            ['www.example.com', 'example.com'],
            http2Enabled: true,
            candidate: true,
        );
        $conf = \file_get_contents($written['conf']);
        self::assertIsString($conf);
        self::assertStringContainsString('server_name www.example.com;', $conf);
        self::assertStringNotContainsString('server_name www.example.com example.com;', $conf);
        self::assertStringContainsString('WLS apex->www canonical redirect', $conf);
        self::assertStringContainsString('server_name example.com;', $conf);
        self::assertStringContainsString('location ^~ /api/', $conf);
        self::assertStringContainsString(
            'return 301 https://www.example.com$request_uri;',
            $conf,
        );
        self::assertSame('www.example.com', $written['www_canonical_host'] ?? null);
        self::assertSame(['example.com'], $written['apex_redirect_names'] ?? null);
        self::assertContains('example.com', $written['server_names']);
        self::assertContains('www.example.com', $written['server_names']);
    }

    /**
     * @param list<string> $dnsNames
     */
    private function writeSelfSignedPair(string $dir, array $dnsNames): void
    {
        $keyPath = $dir . DIRECTORY_SEPARATOR . 'privkey.pem';
        $certPath = $dir . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $key = \openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);
        $csr = \openssl_csr_new([
            'commonName' => $dnsNames[0],
            'organizationName' => 'Weline Test',
            'countryName' => 'CN',
        ], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($csr);
        $cert = \openssl_csr_sign($csr, null, $key, 365, [
            'digest_alg' => 'sha256',
            'x509_extensions' => 'v3_req',
            'config' => $this->writeOpensslConfig($dnsNames),
        ], \time());
        self::assertNotFalse($cert);
        self::assertTrue(\openssl_pkey_export_to_file($key, $keyPath));
        self::assertTrue(\openssl_x509_export_to_file($cert, $certPath));
        \copy($certPath, $dir . DIRECTORY_SEPARATOR . 'cert.pem');
        \copy($certPath, $dir . DIRECTORY_SEPARATOR . 'chain.pem');
    }

    /**
     * @param list<string> $dnsNames
     */
    private function writeOpensslConfig(array $dnsNames): string
    {
        $san = [];
        foreach ($dnsNames as $i => $name) {
            $san[] = 'DNS.' . ($i + 1) . ' = ' . $name;
        }
        $cfg = $this->root . DIRECTORY_SEPARATOR . 'openssl-' . \bin2hex(\random_bytes(4)) . '.cnf';
        $body = "[ req ]\n"
            . "distinguished_name = req_distinguished_name\n"
            . "req_extensions = v3_req\n"
            . "prompt = no\n"
            . "[ req_distinguished_name ]\n"
            . "CN = " . $dnsNames[0] . "\n"
            . "[ v3_req ]\n"
            . "subjectAltName = @alt_names\n"
            . "[ alt_names ]\n"
            . \implode("\n", $san) . "\n";
        self::assertNotFalse(\file_put_contents($cfg, $body));

        return $cfg;
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($root);
    }
}
