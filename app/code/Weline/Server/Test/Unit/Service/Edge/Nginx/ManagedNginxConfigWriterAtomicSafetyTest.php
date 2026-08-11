<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;

final class ManagedNginxConfigWriterAtomicSafetyTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-nginx-writer-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testLegacyWriteRejectsActiveConfigSymlinkWithoutTouchingItsTarget(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX symlink fixture; Windows reparse coverage runs in native CI.');
        }
        $paths = $this->paths('legacy');
        $outside = $this->root . DIRECTORY_SEPARATOR . 'outside.conf';
        self::assertSame(17, \file_put_contents($outside, "preserve-outside\n"));
        self::assertTrue(\symlink($outside, $paths->confFile()));

        $failure = null;
        try {
            (new ManagedNginxConfigWriter($paths))->write(19010, candidate: false);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString(
            'active config',
            \strtolower($failure->getMessage()),
        );
        self::assertSame("preserve-outside\n", \file_get_contents($outside));
        self::assertTrue(\is_link($paths->confFile()));
    }

    public function testLegacyWriteCommitsThePreviousGenerationAsLastKnownGood(): void
    {
        $paths = $this->paths('legacy-last-good');
        $writer = new ManagedNginxConfigWriter($paths);
        $first = $writer->write(19016, candidate: false);
        $firstContents = \file_get_contents($first['conf']);
        self::assertIsString($firstContents);

        $second = $writer->write(19016, candidate: false);
        $secondContents = \file_get_contents($second['conf']);
        self::assertIsString($secondContents);
        self::assertNotSame($firstContents, $secondContents);

        $lastGood = $paths->confFile() . '.last-good';
        self::assertFileExists($lastGood);
        self::assertSame($firstContents, \file_get_contents($lastGood));
    }

    public function testMimeSourceSymlinkIsRejectedBeforeCandidatePublication(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX symlink fixture; Windows reparse coverage runs in native CI.');
        }
        $paths = $this->paths('mime-source-link');
        $sourceDirectory = $paths->installRoot() . DIRECTORY_SEPARATOR . 'conf';
        self::assertTrue(\mkdir($sourceDirectory, 0700, true));
        $outside = $this->root . DIRECTORY_SEPARATOR . 'outside-mime.types';
        self::assertNotFalse(\file_put_contents($outside, $this->mimeContents()));
        self::assertTrue(\symlink(
            $outside,
            $sourceDirectory . DIRECTORY_SEPARATOR . 'mime.types',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MIME types source');
        (new ManagedNginxConfigWriter($paths))->write(19011, candidate: true);
    }

    public function testMimeDependencyIsContentAddressedAndReadBackBeforeUse(): void
    {
        $paths = $this->paths('mime-addressed');
        $sourceDirectory = $paths->installRoot() . DIRECTORY_SEPARATOR . 'conf';
        self::assertTrue(\mkdir($sourceDirectory, 0700, true));
        $contents = $this->mimeContents();
        self::assertSame(
            \strlen($contents),
            \file_put_contents(
                $sourceDirectory . DIRECTORY_SEPARATOR . 'mime.types',
                $contents,
            ),
        );

        $result = (new ManagedNginxConfigWriter($paths))->write(
            19012,
            candidate: true,
        );
        $digest = \hash('sha256', $contents);
        $snapshot = $paths->confDir() . DIRECTORY_SEPARATOR
            . 'mime.' . $digest . '.types';
        $config = \file_get_contents($result['conf']);
        self::assertIsString($config);
        self::assertFileExists($snapshot);
        self::assertSame($contents, \file_get_contents($snapshot));
        self::assertStringContainsString(
            'include       "' . \str_replace('\\', '/', $snapshot) . '";',
            $config,
        );
        self::assertFileDoesNotExist(
            $paths->confDir() . DIRECTORY_SEPARATOR . 'mime.types',
        );
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0444, \fileperms($snapshot) & 0777);
        }
    }

    public function testMimeContentAddressCollisionIsRejectedWithoutOverwrite(): void
    {
        $paths = $this->paths('mime-collision');
        $sourceDirectory = $paths->installRoot() . DIRECTORY_SEPARATOR . 'conf';
        self::assertTrue(\mkdir($sourceDirectory, 0700, true));
        $contents = $this->mimeContents();
        self::assertSame(
            \strlen($contents),
            \file_put_contents(
                $sourceDirectory . DIRECTORY_SEPARATOR . 'mime.types',
                $contents,
            ),
        );
        $snapshot = $paths->confDir() . DIRECTORY_SEPARATOR
            . 'mime.' . \hash('sha256', $contents) . '.types';
        $collision = 'untrusted-mime-collision';
        self::assertSame(\strlen($collision), \file_put_contents($snapshot, $collision));

        $failure = null;
        try {
            (new ManagedNginxConfigWriter($paths))->write(19013, candidate: true);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString(
            'content-addressed',
            \strtolower($failure->getMessage()),
        );
        self::assertSame($collision, \file_get_contents($snapshot));
    }

    public function testMimeRecoveryBackupIsCollectedAfterExactReadback(): void
    {
        $paths = $this->paths('mime-recovery');
        $sourceDirectory = $paths->installRoot() . DIRECTORY_SEPARATOR . 'conf';
        self::assertTrue(\mkdir($sourceDirectory, 0700, true));
        $contents = $this->mimeContents();
        self::assertSame(
            \strlen($contents),
            \file_put_contents(
                $sourceDirectory . DIRECTORY_SEPARATOR . 'mime.types',
                $contents,
            ),
        );
        $writer = new ManagedNginxConfigWriter($paths);
        $writer->write(19014, candidate: true);
        $snapshot = $paths->confDir() . DIRECTORY_SEPARATOR
            . 'mime.' . \hash('sha256', $contents) . '.types';
        $backup = $snapshot . '.wls-backup-' . \str_repeat('a', 16);
        self::assertSame(8, \file_put_contents($backup, 'previous'));

        $writer->write(19014, candidate: true);

        self::assertFileDoesNotExist($backup);
        self::assertSame($contents, \file_get_contents($snapshot));
    }

    public function testMimeSourceParentSymlinkIsRejected(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX symlink fixture; Windows reparse coverage runs in native CI.');
        }
        $paths = $this->paths('mime-parent-link');
        $installRoot = $paths->installRoot();
        $outsideDirectory = $this->root . DIRECTORY_SEPARATOR . 'outside-mime-directory';
        self::assertTrue(\mkdir($installRoot, 0700, true));
        self::assertTrue(\mkdir($outsideDirectory, 0700, true));
        self::assertNotFalse(\file_put_contents(
            $outsideDirectory . DIRECTORY_SEPARATOR . 'mime.types',
            $this->mimeContents(),
        ));
        self::assertTrue(\symlink(
            $outsideDirectory,
            $installRoot . DIRECTORY_SEPARATOR . 'conf',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MIME types source');
        (new ManagedNginxConfigWriter($paths))->write(19015, candidate: true);
    }

    public function testLocalizedTlsSourceSymlinkIsRejected(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX symlink fixture; Windows reparse coverage runs in native CI.');
        }
        $paths = $this->paths('tls-source-link');
        $outsideCertificate = $this->root . DIRECTORY_SEPARATOR . 'outside-cert.pem';
        $sourceCertificate = $this->root . DIRECTORY_SEPARATOR . 'source-cert.pem';
        $sourceKey = $this->root . DIRECTORY_SEPARATOR . 'source-key.pem';
        self::assertNotFalse(\file_put_contents($outsideCertificate, "certificate\n"));
        self::assertNotFalse(\file_put_contents($sourceKey, "private-key\n"));
        self::assertTrue(\symlink($outsideCertificate, $sourceCertificate));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TLS certificate source');
        $this->localizeTls($paths, $sourceCertificate, $sourceKey);
    }

    public function testLocalizedTlsSourceParentSymlinkIsRejected(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX symlink fixture; Windows reparse coverage runs in native CI.');
        }
        $paths = $this->paths('tls-source-parent-link');
        $outsideDirectory = $this->root . DIRECTORY_SEPARATOR . 'outside-tls-directory';
        $linkedDirectory = $this->root . DIRECTORY_SEPARATOR . 'linked-tls-directory';
        self::assertTrue(\mkdir($outsideDirectory, 0700, true));
        self::assertNotFalse(\file_put_contents(
            $outsideDirectory . DIRECTORY_SEPARATOR . 'fullchain.pem',
            "certificate\n",
        ));
        self::assertNotFalse(\file_put_contents(
            $outsideDirectory . DIRECTORY_SEPARATOR . 'privkey.pem',
            "private-key\n",
        ));
        self::assertTrue(\symlink($outsideDirectory, $linkedDirectory));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TLS certificate source');
        $this->localizeTls(
            $paths,
            $linkedDirectory . DIRECTORY_SEPARATOR . 'fullchain.pem',
            $linkedDirectory . DIRECTORY_SEPARATOR . 'privkey.pem',
        );
    }

    public function testLocalizedTlsContentAddressCollisionIsRejectedWithoutOverwrite(): void
    {
        $paths = $this->paths('tls-collision');
        $certificateContents = "certificate\n";
        $keyContents = "private-key\n";
        $sourceCertificate = $this->root . DIRECTORY_SEPARATOR . 'collision-cert.pem';
        $sourceKey = $this->root . DIRECTORY_SEPARATOR . 'collision-key.pem';
        self::assertSame(
            \strlen($certificateContents),
            \file_put_contents($sourceCertificate, $certificateContents),
        );
        self::assertSame(
            \strlen($keyContents),
            \file_put_contents($sourceKey, $keyContents),
        );
        $certSha = \hash('sha256', $certificateContents);
        $keySha = \hash('sha256', $keyContents);
        $identity = \substr(\hash('sha256', $certSha . "\0" . $keySha), 0, 32);
        $directory = $paths->confDir() . DIRECTORY_SEPARATOR . 'certs';
        self::assertTrue(\mkdir($directory, 0700, true));
        $collision = $directory . DIRECTORY_SEPARATOR . $identity . '-fullchain.pem';
        self::assertSame(19, \file_put_contents($collision, "untrusted-collision"));

        $failure = null;
        try {
            $this->localizeTls($paths, $sourceCertificate, $sourceKey);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString(
            'content-addressed',
            \strtolower($failure->getMessage()),
        );
        self::assertSame('untrusted-collision', \file_get_contents($collision));
    }

    public function testLocalizedTlsMaterialIsImmutableAndReadBack(): void
    {
        $paths = $this->paths('tls-addressed');
        $certificateContents = "certificate\n";
        $keyContents = "private-key\n";
        $sourceCertificate = $this->root . DIRECTORY_SEPARATOR . 'addressed-cert.pem';
        $sourceKey = $this->root . DIRECTORY_SEPARATOR . 'addressed-key.pem';
        self::assertSame(
            \strlen($certificateContents),
            \file_put_contents($sourceCertificate, $certificateContents),
        );
        self::assertSame(
            \strlen($keyContents),
            \file_put_contents($sourceKey, $keyContents),
        );

        $localized = $this->localizeTls($paths, $sourceCertificate, $sourceKey);

        self::assertSame($certificateContents, \file_get_contents($localized['cert']));
        self::assertSame($keyContents, \file_get_contents($localized['key']));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0444, \fileperms($localized['cert']) & 0777);
            self::assertSame(0400, \fileperms($localized['key']) & 0777);
        }
    }

    public function testGenerationAwareWriterIgnoresMutableCertificateSourceAfterActivation(): void
    {
        $domain = 'immutable-edge.example.test';
        $paths = $this->paths('tls-generation');
        $source = $this->createCertificate($domain, 'tls-generation-source');
        $replacement = $this->createCertificate(
            'wrong-san.example.test',
            'tls-generation-replacement',
        );
        $active = (new ProjectCertificateGenerationStore($this->root))->activate(
            $domain,
            $source['cert'],
            $source['key'],
            '',
            [],
            null,
            ProjectCertificateGenerationStore::TRUST_PROFILE_TEST,
            ProjectCertificateGenerationStore::PROVIDER_EXTERNAL,
        );
        $writer = new ManagedNginxConfigWriter($paths);
        $first = $writer->write(
            19017,
            serverNames: [$domain],
            http2Enabled: true,
            candidate: true,
            certificateGeneration: $active,
        );
        $firstConfig = \file_get_contents($first['conf']);
        self::assertIsString($firstConfig);
        self::assertTrue($first['certificate_generation_managed']);
        self::assertSame($domain, $first['certificate_domain']);
        self::assertSame((int)$active['generation'], $first['certificate_generation']);
        self::assertStringContainsString(
            'ssl_certificate     "'
                . \str_replace('\\', '/', (string)$active['cert_path']) . '";',
            $firstConfig,
        );
        self::assertStringContainsString(
            'ssl_certificate_key "'
                . \str_replace('\\', '/', (string)$active['key_path']) . '";',
            $firstConfig,
        );

        // Replace the mutable fact-source leaves with a different, internally
        // valid key pair and SAN after activation. WLS 2.0 must keep rendering
        // the already-validated immutable selector, never follow this swap.
        self::assertNotFalse(\copy($replacement['cert'], $source['cert']));
        self::assertNotFalse(\copy($replacement['key'], $source['key']));
        $second = $writer->write(
            19017,
            serverNames: [$domain],
            http2Enabled: true,
            candidate: true,
            certificateGeneration: $active,
        );
        $secondConfig = \file_get_contents($second['conf']);
        self::assertIsString($secondConfig);
        self::assertStringContainsString(
            'ssl_certificate     "'
                . \str_replace('\\', '/', (string)$active['cert_path']) . '";',
            $secondConfig,
        );
        self::assertSame(
            (string)$active['leaf_fingerprint_sha256'],
            $second['ssl_certificate_sha256'],
        );

        $changedExpected = $active;
        $changedExpected['cert_path'] = $source['cert'];
        try {
            $writer->write(
                19017,
                serverNames: [$domain],
                http2Enabled: true,
                candidate: true,
                certificateGeneration: $changedExpected,
            );
            self::fail('A changed certificate-generation after-image was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'after-image changed',
                $exception->getMessage(),
            );
        }
    }

    private function paths(string $scope): ManagedNginxPaths
    {
        $paths = new ManagedNginxPaths($this->root, [
            'runtime_root' => $scope . '-runtime',
            'install_root' => $scope . '-install',
            'listen_http' => 18100,
            'listen_https' => 18500,
        ]);
        $paths->ensureRuntimeDirectories();
        return $paths;
    }

    /** @return array{cert:string,key:string} */
    private function localizeTls(
        ManagedNginxPaths $paths,
        string $certificate,
        string $key,
    ): array {
        $writer = new ManagedNginxConfigWriter($paths);
        // Exercise the platform-independent implementation used by the
        // Windows-only localization branch while running POSIX unit CI.
        $method = new \ReflectionMethod($writer, 'stageLocalizedSslMaterial');
        $result = $method->invoke($writer, $certificate, $key);
        self::assertIsArray($result);
        return $result;
    }

    private function mimeContents(): string
    {
        return "types {\n    text/html html htm;\n    text/css css;\n}\n";
    }

    /** @return array{cert:string,key:string} */
    private function createCertificate(string $domain, string $name): array
    {
        if (!\extension_loaded('openssl')) {
            self::markTestSkipped('The OpenSSL extension is required.');
        }
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
}
