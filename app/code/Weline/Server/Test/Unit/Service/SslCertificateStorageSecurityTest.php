<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Server\Service\SslCertificateService;

final class SslCertificateStorageSecurityTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $base = \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir();
        $this->root = $base . DIRECTORY_SEPARATOR . 'wls-cert-storage-'
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

    public function testStorageSegmentRejectsTraversalAndSeparators(): void
    {
        foreach (['../outside.test', 'safe.test/../../outside', "bad\0.test"] as $domain) {
            try {
                SslCertificateService::certificateStorageSegmentForFilesystem($domain);
                self::fail('Unsafe certificate storage domain was accepted: ' . $domain);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDomainDirectorySymlinkIsRejected(): void
    {
        $outside = $this->root . DIRECTORY_SEPARATOR . 'outside';
        $base = $this->root . DIRECTORY_SEPARATOR . 'ssl';
        self::assertTrue(\mkdir($outside, 0700));
        self::assertTrue(\mkdir($base, 0700));
        if (!@\symlink($outside, $base . DIRECTORY_SEPARATOR . 'linked.example.test')) {
            self::markTestSkipped('The current platform cannot create a directory symlink.');
        }

        $this->expectException(\RuntimeException::class);
        (new CertificateStorageSecurityProbe($base))
            ->getCertificateDir('linked.example.test');
    }

    public function testAtomicCertificateWriteDoesNotFollowLeafSymlink(): void
    {
        $base = $this->root . DIRECTORY_SEPARATOR . 'ssl';
        $outside = $this->root . DIRECTORY_SEPARATOR . 'outside.pem';
        self::assertSame(8, \file_put_contents($outside, 'original'));
        $service = new CertificateStorageSecurityProbe($base);
        $directory = $service->getCertificateDir('safe.example.test');
        if (!@\symlink($outside, $directory . 'fullchain.pem')) {
            self::markTestSkipped('The current platform cannot create a file symlink.');
        }

        $write = new ReflectionMethod(SslCertificateService::class, 'writeCertificateFileAtomically');
        $write->setAccessible(true);
        try {
            $write->invoke($service, $directory . 'fullchain.pem', 'replacement', 0644);
            self::fail('Certificate write followed an existing symlink.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            self::assertInstanceOf(\RuntimeException::class, $throwable->getPrevious() ?? $throwable);
        }
        self::assertSame('original', \file_get_contents($outside));
    }

    public function testClearRefusesLinkedDomainDirectoryWithoutDeletingOutsideFiles(): void
    {
        $base = $this->root . DIRECTORY_SEPARATOR . 'ssl';
        $outside = $this->root . DIRECTORY_SEPARATOR . 'outside';
        self::assertTrue(\mkdir($base, 0700));
        self::assertTrue(\mkdir($outside, 0700));
        self::assertSame(
            8,
            \file_put_contents($outside . DIRECTORY_SEPARATOR . 'fullchain.pem', 'external'),
        );
        if (!@\symlink($outside, $base . DIRECTORY_SEPARATOR . 'victim.example.test')) {
            self::markTestSkipped('The current platform cannot create a directory symlink.');
        }

        $result = (new CertificateStorageSecurityProbe($base))
            ->clearForTest('victim.example.test');

        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $result['deleted']);
        self::assertFileExists($outside . DIRECTORY_SEPARATOR . 'fullchain.pem');
        self::assertSame(
            'external',
            \file_get_contents($outside . DIRECTORY_SEPARATOR . 'fullchain.pem'),
        );
    }

    public function testClearRefusesSpecialCertificateLeaf(): void
    {
        $service = new CertificateStorageSecurityProbe(
            $this->root . DIRECTORY_SEPARATOR . 'ssl',
        );
        $directory = $service->getCertificateDir('special.example.test');
        self::assertTrue(\mkdir($directory . 'fullchain.pem', 0700));

        $result = $service->clearForTest('special.example.test');

        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $result['deleted']);
        self::assertDirectoryExists($directory . 'fullchain.pem');
    }

    public function testBoundedEnumerationFailsInsteadOfScanningPastLimit(): void
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . 'bounded';
        self::assertTrue(\mkdir($directory, 0700));
        self::assertSame(1, \file_put_contents($directory . DIRECTORY_SEPARATOR . 'a', 'a'));
        self::assertSame(1, \file_put_contents($directory . DIRECTORY_SEPARATOR . 'b', 'b'));
        self::assertSame(1, \file_put_contents($directory . DIRECTORY_SEPARATOR . 'c', 'c'));
        $enumerate = new ReflectionMethod(SslCertificateService::class, 'boundedDirectoryEntries');
        $enumerate->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $enumerate->invoke(
            new CertificateStorageSecurityProbe($this->root . DIRECTORY_SEPARATOR . 'ssl'),
            $directory,
            2,
            'test directory',
        );
    }

    public function testCertificateRootFenceRecognizesPosixDriveAndUncRoots(): void
    {
        $isRoot = new ReflectionMethod(SslCertificateService::class, 'filesystemPathIsRoot');
        $isRoot->setAccessible(true);

        self::assertTrue($isRoot->invoke(null, '/'));
        self::assertTrue($isRoot->invoke(null, '//'));
        self::assertTrue($isRoot->invoke(null, '///'));
        self::assertTrue($isRoot->invoke(null, 'C:\\'));
        self::assertTrue($isRoot->invoke(null, '\\\\server'));
        self::assertTrue($isRoot->invoke(null, '\\\\server\\share'));
        self::assertTrue($isRoot->invoke(null, '\\\\?\\C:\\'));
        self::assertTrue($isRoot->invoke(null, '\\\\?\\UNC\\server\\share'));
        self::assertTrue($isRoot->invoke(null, '\\\\?\\UNC\\server'));
        self::assertTrue($isRoot->invoke(null, '\\\\.\\C:\\'));
        self::assertTrue($isRoot->invoke(
            null,
            '\\\\?\\Volume{A0B1C2D3-E4F5-6789-ABCD-EF0123456789}\\',
        ));
        self::assertFalse($isRoot->invoke(null, '/srv/weline/certificates'));
        self::assertFalse($isRoot->invoke(null, 'C:\\weline\\certificates'));
        self::assertFalse($isRoot->invoke(null, '\\\\?\\C:\\weline\\certificates'));
        self::assertFalse($isRoot->invoke(
            null,
            '\\\\?\\UNC\\server\\share\\certificates',
        ));
    }

    public function testHttp01ChallengeRejectsTraversalTokenBeforeWriting(): void
    {
        $webroot = $this->root . DIRECTORY_SEPARATOR . 'pub';
        self::assertTrue(\mkdir($webroot, 0700));
        $create = new ReflectionMethod(SslCertificateService::class, 'createHttpChallenge');
        $create->setAccessible(true);

        self::assertFalse($create->invoke(
            new CertificateStorageSecurityProbe($this->root . DIRECTORY_SEPARATOR . 'ssl'),
            $webroot,
            '../outside',
            'ignored',
        ));
        self::assertFileDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'outside');
    }

    public function testHttp01ChallengeRejectsLinkedWellKnownDirectory(): void
    {
        $webroot = $this->root . DIRECTORY_SEPARATOR . 'pub';
        $outside = $this->root . DIRECTORY_SEPARATOR . 'outside';
        self::assertTrue(\mkdir($webroot, 0700));
        self::assertTrue(\mkdir($outside, 0700));
        if (!@\symlink($outside, $webroot . DIRECTORY_SEPARATOR . '.well-known')) {
            self::markTestSkipped('The current platform cannot create a directory symlink.');
        }
        $create = new ReflectionMethod(SslCertificateService::class, 'createHttpChallenge');
        $create->setAccessible(true);

        self::assertFalse($create->invoke(
            new CertificateStorageSecurityProbe($this->root . DIRECTORY_SEPARATOR . 'ssl'),
            $webroot,
            'safe-token',
            'ignored',
        ));
        self::assertFileDoesNotExist($outside . DIRECTORY_SEPARATOR . 'safe-token');
    }

    public function testCertificateServiceContainsNoUnboundedScandirOrGlob(): void
    {
        $path = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Service/SslCertificateService.php';
        $source = \file_get_contents($path);
        self::assertIsString($source);
        self::assertStringNotContainsString('\\scandir(', $source);
        self::assertStringNotContainsString('\\glob(', $source);
        self::assertStringContainsString('boundedDirectoryEntries(', $source);
        self::assertStringContainsString('readRegularFileNoFollow(', $source);
    }

    public function testAcmeHttpClientIsHttpsOnlyBoundedAndDoesNotFollowRedirects(): void
    {
        $path = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Service/SslCertificateService.php';
        $lines = \file($path);
        self::assertIsArray($lines);
        $method = new ReflectionMethod(SslCertificateService::class, 'httpRequest');
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringContainsString("'https'", $source);
        self::assertStringContainsString('CURLOPT_PROTOCOLS, CURLPROTO_HTTPS', $source);
        self::assertStringContainsString('CURLOPT_FOLLOWLOCATION, false', $source);
        self::assertStringContainsString('MAX_ACME_HTTP_HEADER_BYTES', $source);
        self::assertStringContainsString('MAX_ACME_HTTP_RESPONSE_BYTES', $source);
        self::assertStringContainsString('CURLOPT_SSL_VERIFYHOST, 2', $source);
    }

    public function testBackendControllerDelegatesDeletionToCertificateLifecycle(): void
    {
        $path = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Controller/Backend/SslCertificate.php';
        $source = \file_get_contents($path);
        self::assertIsString($source);
        self::assertStringContainsString('deleteManagedCertificate(', $source);
        self::assertStringNotContainsString('\\glob(', $source);
        self::assertStringNotContainsString('\\scandir(', $source);
        self::assertStringNotContainsString('\\unlink(', $source);
    }

    public function testCertificateDeletionPublishesBeforeSourceAndRowCleanup(): void
    {
        $path = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Service/SslCertificateService.php';
        $lines = \file($path);
        self::assertIsArray($lines);
        $method = new ReflectionMethod(
            SslCertificateService::class,
            'transitionCertificateOutOfServiceLocked',
        );
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
        $facts = \strpos($source, 'setHttpsEnabled(false)');
        $endpoint = \strpos($source, 'revokeDomainFromInstanceConfigs(');
        $deactivate = \strpos($source, 'deactivateProjectCertificateGeneration(');
        $publication = \strpos($source, 'regenerateCertificateMap()');
        $sourceCleanup = \strpos($source, 'removeCertificateDirectoryPlan(');
        $rowCleanup = \strpos($source, 'delete()->fetch()');
        foreach ([$facts, $endpoint, $deactivate, $publication, $sourceCleanup, $rowCleanup] as $position) {
            self::assertIsInt($position);
        }
        self::assertTrue(
            $facts < $endpoint
            && $endpoint < $deactivate
            && $deactivate < $publication
            && $publication < $sourceCleanup
            && $sourceCleanup < $rowCleanup,
        );
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || (!\file_exists($path) && !\is_link($path))) {
            return;
        }
        if (\is_link($path) || \is_file($path)) {
            @\unlink($path);
            return;
        }
        foreach ((array)@\scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @\rmdir($path);
    }
}

final class CertificateStorageSecurityProbe extends SslCertificateService
{
    public function __construct(string $certificateBaseDirectory)
    {
        $this->certBaseDir = \rtrim($certificateBaseDirectory, '/\\') . DIRECTORY_SEPARATOR;
        $this->accountKeyPath = $this->certBaseDir . 'account.key';
    }

    /** @return array<string,mixed> */
    public function clearForTest(string $domain): array
    {
        $result = [
            'skipped' => 0,
            'deleted' => 0,
            'errors' => [],
            'deleted_domains' => [],
        ];
        $prepare = new ReflectionMethod(
            SslCertificateService::class,
            'prepareCertificateDirectoryRemoval',
        );
        $prepare->setAccessible(true);
        try {
            $prepare->invoke($this, $domain);
        } catch (\Throwable $throwable) {
            ++$result['skipped'];
            $result['errors'][] = $throwable->getMessage();
        }
        return $result;
    }
}
