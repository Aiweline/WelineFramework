<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;

final class ProjectCertificateGenerationStoreTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $base = \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir();
        $this->root = $base . DIRECTORY_SEPARATOR . 'wls-cert-generation-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir(
            $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            0700,
            true,
        ));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testActivationPublishesImmutableSnapshotAndAdvancesOnlyOnChange(): void
    {
        $domain = 'store.example.test';
        $firstSource = $this->createCertificate($domain, 'first');
        $store = new ProjectCertificateGenerationStore($this->root);

        $first = $store->activate(
            $domain,
            $firstSource['cert'],
            $firstSource['key'],
        );
        self::assertSame(1, $first['generation']);
        self::assertFalse($first['retained_previous']);
        self::assertSame('', $first['activation_error']);
        self::assertFileExists($first['cert_path']);
        self::assertFileExists($first['key_path']);
        self::assertStringStartsWith(
            $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl/.wls-generations/snapshots/',
            $first['cert_path'],
        );
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, \fileperms($first['key_path']) & 0777);
        }

        $idempotent = $store->activate(
            $domain,
            $firstSource['cert'],
            $firstSource['key'],
        );
        self::assertSame(1, $idempotent['generation']);
        self::assertSame($first['source_digest'], $idempotent['source_digest']);
        self::assertSame($first['cert_path'], $idempotent['cert_path']);

        $secondSource = $this->createCertificate($domain, 'second');
        $second = $store->activate(
            $domain,
            $secondSource['cert'],
            $secondSource['key'],
        );
        self::assertSame(2, $second['generation']);
        self::assertNotSame($first['source_digest'], $second['source_digest']);
        self::assertSame(1, $second['previous']['generation']);
        self::assertSame($first['source_digest'], $second['previous']['source_digest']);
        self::assertFileExists($first['cert_path']);
        self::assertFileExists($second['cert_path']);
    }

    public function testInvalidRenewalRetainsPreviousValidGeneration(): void
    {
        $domain = 'retain.example.test';
        $valid = $this->createCertificate($domain, 'valid');
        $mismatch = $this->createCertificate($domain, 'mismatch');
        $store = new ProjectCertificateGenerationStore($this->root);
        $active = $store->activate($domain, $valid['cert'], $valid['key']);

        $retained = $store->activate($domain, $valid['cert'], $mismatch['key']);
        self::assertTrue($retained['retained_previous']);
        self::assertStringContainsString('do not match', $retained['activation_error']);
        self::assertSame($active['generation'], $retained['generation']);
        self::assertSame($active['source_digest'], $retained['source_digest']);
        self::assertSame($active['cert_path'], $retained['cert_path']);
        self::assertSame(
            $active['source_digest'],
            $store->active($domain)['source_digest'] ?? null,
        );
    }

    public function testWildcardRouteAcceptsOnlyTheExactWildcardSan(): void
    {
        $domain = '*.example.test';
        $source = $this->createCertificate($domain, 'wildcard');
        $store = new ProjectCertificateGenerationStore($this->root);

        $active = $store->activate($domain, $source['cert'], $source['key']);

        self::assertSame($domain, $active['domain']);
        self::assertSame(1, $active['generation']);
        self::assertFalse($active['retained_previous']);
    }

    public function testWildcardSanCannotClaimADifferentWildcardRoute(): void
    {
        $source = $this->createCertificate('*.example.test', 'wildcard-mismatch');
        $store = new ProjectCertificateGenerationStore($this->root);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Certificate SAN does not cover *.sub.example.test');
        $store->activate(
            '*.sub.example.test',
            $source['cert'],
            $source['key'],
        );
    }

    public function testSymlinkSourceCannotCreateFirstGeneration(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('symlink')) {
            self::markTestSkipped('Symbolic-link fixture requires POSIX symlink support.');
        }
        $domain = 'symlink.example.test';
        $source = $this->createCertificate($domain, 'source');
        $linked = $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl/linked.pem';
        self::assertTrue(\symlink($source['cert'], $linked));
        $store = new ProjectCertificateGenerationStore($this->root);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No valid active certificate generation');
        $store->activate($domain, $linked, $source['key']);
    }

    public function testAccessibleCertificateOutsideProjectRequiresEnrollment(): void
    {
        $domain = 'outside.example.test';
        $source = $this->createCertificate($domain, 'outside-source');
        $outside = $this->root . '-outside';
        self::assertTrue(\mkdir($outside, 0700, true));
        $certificate = $outside . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $privateKey = $outside . DIRECTORY_SEPARATOR . 'privkey.pem';
        self::assertTrue(\copy($source['cert'], $certificate));
        self::assertTrue(\copy($source['key'], $privateKey));
        self::assertTrue(\chmod($certificate, 0600));
        self::assertTrue(\chmod($privateKey, 0600));

        try {
            $store = new ProjectCertificateGenerationStore($this->root);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage(
                'Certificate source is outside every enrolled certificate root',
            );
            $store->activate($domain, $certificate, $privateKey);
        } finally {
            $this->removeTree($outside);
        }
    }

    public function testExplicitEnrollmentAllowsExternalCertificateRoot(): void
    {
        $domain = 'enrolled.example.test';
        $source = $this->createCertificate($domain, 'enrolled-source');
        $outside = $this->root . '-enrolled';
        self::assertTrue(\mkdir($outside, 0700, true));
        $certificate = $outside . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $privateKey = $outside . DIRECTORY_SEPARATOR . 'privkey.pem';
        self::assertTrue(\copy($source['cert'], $certificate));
        self::assertTrue(\copy($source['key'], $privateKey));
        self::assertTrue(\chmod($certificate, 0600));
        self::assertTrue(\chmod($privateKey, 0600));

        try {
            $active = (new ProjectCertificateGenerationStore($this->root))->activate(
                $domain,
                $certificate,
                $privateKey,
                '',
                ['external' => $outside],
            );

            self::assertSame(1, $active['generation']);
            self::assertFalse($active['retained_previous']);
        } finally {
            $this->removeTree($outside);
        }
    }

    public function testCopiedProjectRelocatesActiveSnapshotInsideItsCurrentRoot(): void
    {
        $domain = 'migrated.example.test';
        $source = $this->createCertificate($domain, 'migrated-source');
        $original = new ProjectCertificateGenerationStore($this->root);
        $activated = $original->activate($domain, $source['cert'], $source['key']);

        $migratedRoot = $this->root . '-migrated';
        self::assertTrue(\mkdir($migratedRoot . DIRECTORY_SEPARATOR . 'app/etc', 0700, true));
        $this->copyTree(
            $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            $migratedRoot . DIRECTORY_SEPARATOR . 'app/etc/ssl',
        );
        try {
            $migrated = new ProjectCertificateGenerationStore($migratedRoot);
            $active = $migrated->active($domain);

            self::assertIsArray($active);
            self::assertSame($activated['source_digest'], $active['source_digest']);
            self::assertStringStartsWith(
                $migratedRoot . DIRECTORY_SEPARATOR
                    . 'app/etc/ssl/.wls-generations/snapshots/',
                $active['cert_path'],
            );
            self::assertStringNotContainsString($this->root . DIRECTORY_SEPARATOR, $active['cert_path']);

            $idempotent = $migrated->activate(
                $domain,
                $migratedRoot . DIRECTORY_SEPARATOR
                    . 'app/etc/ssl/migrated-source/fullchain.pem',
                $migratedRoot . DIRECTORY_SEPARATOR
                    . 'app/etc/ssl/migrated-source/privkey.pem',
            );
            self::assertSame($activated['generation'], $idempotent['generation']);
            self::assertSame($active['cert_path'], $idempotent['cert_path']);
        } finally {
            $this->removeTree($migratedRoot);
        }
    }

    public function testCopiedProjectDoesNotFollowSnapshotDirectorySymlink(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('symlink')) {
            self::markTestSkipped('Symbolic-link fixture requires POSIX symlink support.');
        }
        $domain = 'migrated-symlink.example.test';
        $source = $this->createCertificate($domain, 'migrated-symlink-source');
        $original = new ProjectCertificateGenerationStore($this->root);
        $activated = $original->activate($domain, $source['cert'], $source['key']);

        $migratedRoot = $this->root . '-symlink-migrated';
        self::assertTrue(\mkdir(
            $migratedRoot . DIRECTORY_SEPARATOR . 'app/etc/ssl/.wls-generations/active',
            0700,
            true,
        ));
        self::assertTrue(\mkdir(
            $migratedRoot . DIRECTORY_SEPARATOR . 'app/etc/ssl/.wls-generations/snapshots',
            0700,
            true,
        ));
        $manifest = \substr(\hash('sha256', $domain), 0, 32) . '.json';
        self::assertTrue(\copy(
            $this->root . DIRECTORY_SEPARATOR
                . 'app/etc/ssl/.wls-generations/active/' . $manifest,
            $migratedRoot . DIRECTORY_SEPARATOR
                . 'app/etc/ssl/.wls-generations/active/' . $manifest,
        ));
        self::assertTrue(\symlink(
            \dirname($activated['cert_path']),
            $migratedRoot . DIRECTORY_SEPARATOR
                . 'app/etc/ssl/.wls-generations/snapshots/' . $activated['source_digest'],
        ));

        try {
            $migrated = new ProjectCertificateGenerationStore($migratedRoot);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Symbolic-link or non-canonical certificate paths');
            $migrated->active($domain);
        } finally {
            $this->removeTree($migratedRoot);
        }
    }

    /**
     * @return array{cert:string,key:string}
     */
    private function createCertificate(string $domain, string $name): array
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl/' . $name;
        self::assertTrue(\mkdir($directory, 0700, true));
        $config = $directory . DIRECTORY_SEPARATOR . 'openssl.cnf';
        self::assertNotFalse(\file_put_contents($config, <<<CONF
[req]
distinguished_name = dn
prompt = no
req_extensions = server_ext
x509_extensions = server_ext

[dn]
CN = {$domain}

[server_ext]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
DNS.1 = {$domain}
CONF
        ));
        $arguments = [
            'config' => $config,
            'digest_alg' => 'sha256',
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'req_extensions' => 'server_ext',
            'x509_extensions' => 'server_ext',
        ];
        $key = \openssl_pkey_new($arguments);
        self::assertNotFalse($key);
        $request = \openssl_csr_new(['commonName' => $domain], $key, $arguments);
        self::assertNotFalse($request);
        $certificate = \openssl_csr_sign($request, null, $key, 30, $arguments);
        self::assertNotFalse($certificate);
        self::assertTrue(\openssl_x509_export($certificate, $certificatePem));
        self::assertTrue(\openssl_pkey_export($key, $keyPem, null, $arguments));

        $certificatePath = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $keyPath = $directory . DIRECTORY_SEPARATOR . 'privkey.pem';
        self::assertNotFalse(\file_put_contents($certificatePath, $certificatePem));
        self::assertNotFalse(\file_put_contents($keyPath, $keyPem));
        self::assertTrue(\chmod($certificatePath, 0600));
        self::assertTrue(\chmod($keyPath, 0600));
        return ['cert' => $certificatePath, 'key' => $keyPath];
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

    private function copyTree(string $source, string $target): void
    {
        self::assertTrue(\mkdir($target, 0700, true));
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $relative = \substr($item->getPathname(), \strlen($source) + 1);
            $destination = $target . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                self::assertTrue(\mkdir($destination, 0700, true));
                continue;
            }
            self::assertTrue(\copy($item->getPathname(), $destination));
            self::assertTrue(\chmod($destination, $item->getPerms() & 0777));
        }
    }
}
