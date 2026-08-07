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

    public function testRetirementIntentPrecedesPostgresqlRevocationAndAllCleanupStages(): void
    {
        $path = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Service/SslCertificateService.php';
        $lines = \file($path);
        self::assertIsArray($lines);
        $methodSource = static function (string $methodName) use ($lines): string {
            $method = new ReflectionMethod(
                SslCertificateService::class,
                $methodName,
            );
            return \implode('', \array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));
        };
        $prepare = $methodSource('transitionCertificateOutOfServiceLocked');
        $intentPrepare = \strpos($prepare, 'prepareCertificateRetirement(');
        $databaseFact = \strpos($prepare, 'setHttpsEnabled(false)');
        $businessCommit = \strpos($prepare, 'advanceRetirementPhase(');
        foreach ([$intentPrepare, $databaseFact, $businessCommit] as $position) {
            self::assertIsInt($position);
        }
        self::assertTrue($intentPrepare < $databaseFact && $databaseFact < $businessCommit);
        self::assertStringContainsString('$deadlineMonotonic', $prepare);

        $entry = $methodSource('transitionCertificateOutOfService');
        self::assertStringContainsString('+ 75.0', $entry);
        self::assertStringContainsString(
            '$this->completeCertificateOutOfService(',
            $entry,
        );
        self::assertStringContainsString('$deadlineMonotonic,', $entry);

        $resume = $methodSource('resumeCertificateRetirementIntent');
        $runtime = \strpos($resume, 'RETIREMENT_PHASE_RUNTIME_PENDING');
        $legacy = \strpos($resume, 'RETIREMENT_PHASE_RUNTIME_RETIRED');
        $endpoint = \strpos($resume, 'RETIREMENT_PHASE_LEGACY_RETIRED');
        $sourceCleanup = \strpos($resume, 'RETIREMENT_PHASE_ENDPOINT_RETIRED');
        $rowCleanup = \strpos($resume, 'RETIREMENT_PHASE_SOURCE_RETIRED');
        $event = \strpos($resume, 'RETIREMENT_PHASE_DATABASE_RETIRED');
        foreach ([$runtime, $legacy, $endpoint, $sourceCleanup, $rowCleanup, $event] as $position) {
            self::assertIsInt($position);
        }
        self::assertTrue($runtime < $legacy
            && $legacy < $endpoint
            && $endpoint < $sourceCleanup
            && $sourceCleanup < $rowCleanup
            && $rowCleanup < $event);
    }

    public function testLegacyRetirementProvesExactWorkersAndTcpUdpListenersBeforeCleanup(): void
    {
        $path = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Service/SslCertificateService.php';
        $lines = \file($path);
        self::assertIsArray($lines);
        $method = new ReflectionMethod(
            SslCertificateService::class,
            'retireLegacyNginxCertificateGeneration',
        );
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
        $stop = \strpos($source, '$managed->stop($deadlineMonotonic)');
        $map = \strpos(
            $source,
            '$this->writeLegacyCertificateCompatibilityMap($deadlineMonotonic)',
        );
        $after = \strpos(
            $source,
            '$after = $managed->retirementSnapshot($deadlineMonotonic)',
        );

        self::assertIsInt($stop);
        self::assertIsInt($map);
        self::assertIsInt($after);
        self::assertTrue($stop < $map && $map < $after);
        self::assertStringContainsString('NginxChildProcessProbe::workerPids(', $source);
        self::assertStringContainsString("foreach (['tcp', 'udp'] as \$transport)", $source);
        self::assertStringContainsString('STREAM_SERVER_BIND | STREAM_SERVER_LISTEN', $source);
        self::assertStringContainsString("'retired_worker_pids'", $source);
        self::assertStringContainsString("(?:ssl|quic)", $source);
    }

    public function testRetirementReplayIsGloballyBoundedAndEventOrderedBeforeReenable(): void
    {
        $path = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Service/SslCertificateService.php';
        $lines = \file($path);
        self::assertIsArray($lines);
        $methodSource = static function (string $methodName) use ($lines): string {
            $method = new ReflectionMethod(SslCertificateService::class, $methodName);
            return \implode('', \array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));
        };
        $replay = $methodSource('replayPendingCertificateRetirements');
        self::assertStringContainsString('withRetirementReplayLease(', $replay);
        self::assertStringContainsString('pendingRetirementBatch(', $replay);
        self::assertStringContainsString('$maximumIntents,', $replay);
        self::assertStringContainsString('$deadline,', $replay);
        self::assertStringContainsString(
            'advanceRetirementReplayCursor($intent, $deadline)',
            $replay,
        );
        self::assertStringContainsString('$deadline', $replay);

        $resume = $methodSource('resumeCertificateRetirementIntent');
        self::assertStringContainsString(
            '$store->retirementIntent($domain, $deadlineMonotonic)',
            $resume,
        );
        self::assertStringContainsString(
            "['wls_revocation_intent' => \$intent]",
            $resume,
        );
        self::assertStringContainsString('$deadlineMonotonic,', $resume);

        $boundedLock = $methodSource('withCertificateRetirementLifecycleLock');
        self::assertStringContainsString('$remaining / 2.0', $boundedLock);
        self::assertStringContainsString(
            'withCertificateLifecycleLock(',
            $boundedLock,
        );
        self::assertStringContainsString('$waitTimeout', $boundedLock);

        $event = $methodSource('dispatchDurableCertificateRetirementEvent');
        foreach ([
            "'retirement_generation'",
            "'retirement_intent_id'",
            "'retirement_event_id'",
            "'retirement_operation'",
        ] as $field) {
            self::assertStringContainsString($field, $event);
        }

        $enable = $methodSource('enableManagedCertificateLocked');
        $pending = \strpos($enable, '$pendingRetirement =');
        $databaseEnable = \strpos($enable, 'setStatus(SslCertificate::STATUS_ACTIVE)');
        self::assertIsInt($pending);
        self::assertIsInt($databaseEnable);
        self::assertLessThan($databaseEnable, $pending);

        $endpoint = $methodSource('revokeDomainFromEndpointPayload');
        self::assertStringContainsString("\$data['ssl_enabled'] = false", $endpoint);
        self::assertStringContainsString("\$data['ssl_cert'] = ''", $endpoint);
        self::assertStringContainsString("\$data['ssl_key'] = ''", $endpoint);
    }

    public function testCertificateWritersCannotCrossAnExplicitPendingRetirement(): void
    {
        $path = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Service/SslCertificateService.php';
        $lines = \file($path);
        self::assertIsArray($lines);
        $methodSource = static function (string $methodName) use ($lines): string {
            $method = new ReflectionMethod(SslCertificateService::class, $methodName);
            return \implode('', \array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));
        };

        foreach ([
            'applyWildcardToSubdomainIfExists',
            'generateLocalCaSignedCertificate',
            'generateSelfSignedCertificate',
            'reconcileCertificateFiles',
            'regenerateCertificateMap',
            'syncCertificateRecordFromFiles',
            'syncWildcardToSubdomains',
        ] as $writer) {
            self::assertStringContainsString(
                'withCertificateLifecycleLock(',
                $methodSource($writer),
                $writer,
            );
        }
        foreach ([
            'applyWildcardToSubdomainIfExistsLocked',
            'generateLocalCaSignedCertificateLocked',
            'generateSelfSignedCertificateLocked',
            'syncCertificateRecordFromFilesLocked',
            'acquireSslIssuanceLockUnlocked',
            'importManualCertificateLocked',
            'syncWildcardToSubdomains',
        ] as $writer) {
            self::assertStringContainsString(
                'assertCertificateMutationNotBlockedByRetirement(',
                $methodSource($writer),
                $writer,
            );
        }
    }

    public function testCertificateEndpointRetirementReusesCanonicalAtomicWriters(): void
    {
        $path = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Service/SslCertificateService.php';
        $lines = \file($path);
        self::assertIsArray($lines);
        $method = new ReflectionMethod(
            SslCertificateService::class,
            'revokeDomainFromInstanceConfigs',
        );
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringContainsString(
            'ServerInstanceManager::updateJsonFileAtomically(',
            $source,
        );
        self::assertStringContainsString(
            'GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(',
            $source,
        );
        self::assertStringContainsString(
            '$this->decodeCertificateEndpointRecord($candidate)',
            $source,
        );
        self::assertStringContainsString(
            '$this->assertSslStateTargetMode(',
            $source,
        );
        self::assertStringContainsString('$lockBudget', $source);
    }

    public function testDeletionPlanRejectsLeafReplacementAfterPreflight(): void
    {
        $service = new CertificateStorageSecurityProbe(
            $this->root . DIRECTORY_SEPARATOR . 'ssl',
        );
        $directory = $service->getCertificateDir('replace.example.test');
        $leaf = $directory . 'fullchain.pem';
        self::assertSame(5, \file_put_contents($leaf, 'first'));
        $prepare = new ReflectionMethod(
            SslCertificateService::class,
            'prepareCertificateDirectoryRemoval',
        );
        $prepare->setAccessible(true);
        $remove = new ReflectionMethod(
            SslCertificateService::class,
            'removeCertificateDirectoryPlan',
        );
        $remove->setAccessible(true);
        $deadline = (\hrtime(true) / 1_000_000_000) + 5.0;
        $plan = $prepare->invoke($service, 'replace.example.test', $deadline);
        self::assertSame(6, \file_put_contents($leaf, 'second'));

        try {
            $remove->invoke($service, $plan, $deadline);
            self::fail('Replacing a planned certificate leaf must abort deletion.');
        } catch (\Throwable $throwable) {
            self::assertStringContainsString('identity changed', $throwable->getMessage());
        }
        self::assertFileExists($leaf);
        self::assertSame('second', \file_get_contents($leaf));
    }

    public function testDeletionPlanRejectsLeafDisappearanceAfterPreflight(): void
    {
        $service = new CertificateStorageSecurityProbe(
            $this->root . DIRECTORY_SEPARATOR . 'ssl',
        );
        $directory = $service->getCertificateDir('missing.example.test');
        $leaf = $directory . 'fullchain.pem';
        self::assertSame(5, \file_put_contents($leaf, 'first'));
        $prepare = new ReflectionMethod(
            SslCertificateService::class,
            'prepareCertificateDirectoryRemoval',
        );
        $prepare->setAccessible(true);
        $remove = new ReflectionMethod(
            SslCertificateService::class,
            'removeCertificateDirectoryPlan',
        );
        $remove->setAccessible(true);
        $deadline = (\hrtime(true) / 1_000_000_000) + 5.0;
        $plan = $prepare->invoke($service, 'missing.example.test', $deadline);
        self::assertTrue(\unlink($leaf));

        try {
            $remove->invoke($service, $plan, $deadline);
            self::fail('A disappearing planned certificate leaf must abort deletion.');
        } catch (\Throwable $throwable) {
            self::assertStringContainsString('identity changed', $throwable->getMessage());
        }
        self::assertDirectoryExists(\rtrim($directory, '/\\'));
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
