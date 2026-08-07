<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\VerifiedPersistentFileLock;
use Weline\Server\Session\Server\SharedStateTokenStore;

final class SharedStateTokenStoreTest extends TestCase
{
    private string $directory;

    private string $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = \sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'wls-shared-token-store-'
            . \getmypid()
            . '-'
            . \bin2hex(\random_bytes(6));
        self::assertTrue(@\mkdir($this->directory, 0700, true));
        $this->target = $this->directory . DIRECTORY_SEPARATOR . 'session_server.20970.token';
    }

    protected function tearDown(): void
    {
        $entries = @\scandir($this->directory);
        if (\is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $this->directory . DIRECTORY_SEPARATOR . $entry;
                \is_dir($path) && !\is_link($path) ? @\rmdir($path) : @\unlink($path);
            }
        }
        @\rmdir($this->directory);
        parent::tearDown();
    }

    public function testPublishReadAndCompareAndRemoveAreGenerationSafe(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $first = \str_repeat('a', 64);
        $second = \str_repeat('b', 64);

        $store->publish($first, 101);
        self::assertSame(['secret' => $first, 'version' => 101], $store->read());

        $store->publish($second, 102);
        self::assertFalse($store->removeIfMatches($first, 101));
        self::assertSame(['secret' => $second, 'version' => 102], $store->read());

        self::assertTrue($store->removeIfMatches($second, 102));
        self::assertNull($store->read());
        self::assertFileExists($store->publicationLockPath());
    }

    public function testPublishRejectsLowerGenerationAfterHigherGenerationWasObserved(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $first = \str_repeat('a', 64);

        $store->publish($first, 10);

        try {
            $store->publish(\str_repeat('b', 64), 9);
            self::fail('A lower capability generation must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('lower', \strtolower($exception->getMessage()));
        }

        self::assertSame(['secret' => $first, 'version' => 10], $store->read());
    }

    public function testPublishRejectsDifferentSecretAtSameGeneration(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $first = \str_repeat('c', 64);

        $store->publish($first, 11);

        try {
            $store->publish(\str_repeat('d', 64), 11);
            self::fail('A same-generation capability fork must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('same generation', \strtolower($exception->getMessage()));
        }

        self::assertSame(['secret' => $first, 'version' => 11], $store->read());
    }

    public function testPublishIsIdempotentForSameGenerationSecretAndAuthority(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $secret = \str_repeat('e', 64);

        $store->publish($secret, 12);
        $store->publish($secret, 12);

        self::assertSame(['secret' => $secret, 'version' => 12], $store->read());
    }

    public function testActiveCapabilityPathRejectsDifferentEndpointAuthority(): void
    {
        $first = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $first->publish(\str_repeat('f', 64), 20);
        $otherAuthority = $this->authority();
        $otherAuthority['instance'] = 'other-session-instance';
        $second = new SharedStateTokenStore($this->target, 0.25, $otherAuthority);

        try {
            $second->publish(\str_repeat('1', 64), 21);
            self::fail('Different active endpoints must not share one capability path.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('authority', \strtolower($exception->getMessage()));
        }

        self::assertSame(
            ['secret' => \str_repeat('f', 64), 'version' => 20],
            $first->read(),
        );
    }

    public function testCapabilityEnvelopeCannotBeCopiedToAnotherTokenPath(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $store->publish(\str_repeat('7', 64), 21);
        $copyPath = $this->directory . DIRECTORY_SEPARATOR . 'session_server.20970-copy.token';
        self::assertTrue(\copy($this->target, $copyPath));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($copyPath, 0600));
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('authority');
        SharedStateTokenStore::readPath($copyPath, $this->authority());
    }

    public function testInactiveCapabilityPathCanBeClaimedByAnotherEndpointAtHigherGeneration(): void
    {
        $first = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $firstSecret = \str_repeat('8', 64);
        $first->publish($firstSecret, 22);
        self::assertTrue($first->removeIfMatches($firstSecret, 22));
        $nextAuthority = $this->authority();
        $nextAuthority['port'] = 20971;
        $nextAuthority['instance'] = 'shared-session-20971';
        $next = new SharedStateTokenStore($this->target, 0.25, $nextAuthority);

        $published = $next->publishNext(\str_repeat('9', 64));

        self::assertSame(24, $published['version']);
        self::assertSame($published, $next->read());
    }

    public function testRemovedCapabilityRetainsGenerationFence(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $secret = \str_repeat('2', 64);
        $store->publish($secret, 30);
        self::assertTrue($store->removeIfMatches($secret, 30));

        try {
            $store->publish(\str_repeat('3', 64), 29);
            self::fail('Capability removal must retain the durable generation fence.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('lower', \strtolower($exception->getMessage()));
        }

        self::assertNull($store->read());
    }

    public function testInactiveTombstoneIsNotResurrectedByRetainedActiveBackup(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $secret = \str_repeat('a', 64);
        $store->publish($secret, 60);
        $activeEnvelope = (string)\file_get_contents($this->target);
        self::assertTrue($store->removeIfMatches($secret, 60));
        $tombstone = SharedStateTokenStore::readCapabilityStatePath($this->target);
        self::assertIsArray($tombstone);
        self::assertFalse($tombstone['active']);
        self::assertSame(61, $tombstone['version']);
        $this->writeRetainedBackup($activeEnvelope, '1');

        self::assertFalse($store->removeIfMatches($secret, 60));

        $after = SharedStateTokenStore::readCapabilityStatePath($this->target);
        self::assertIsArray($after);
        self::assertFalse($after['active'], 'Recovery must never resurrect a retired capability.');
        self::assertSame(61, $after['version']);
        self::assertNull($store->read());
    }

    public function testMissingTombstoneIsReconstructedInsteadOfRestoringStaleActiveBackup(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $secret = \str_repeat('1', 64);
        $store->publish($secret, 65);
        $activeEnvelope = (string)\file_get_contents($this->target);
        self::assertTrue($store->removeIfMatches($secret, 65));
        self::assertTrue(\unlink($this->target));
        $this->writeRetainedBackup($activeEnvelope, '3');

        self::assertFalse($store->removeIfMatches($secret, 65));

        $after = SharedStateTokenStore::readCapabilityStatePath($this->target);
        self::assertIsArray($after);
        self::assertFalse(
            $after['active'],
            'The durable inactive fence must dominate an older retained active backup.',
        );
        self::assertSame(66, $after['version']);
        self::assertNull($store->read());
    }

    public function testRecoveryDoesNotRestoreBackupThatForksDurableGenerationLedger(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $store->publish(\str_repeat('4', 64), 68);
        self::assertTrue(\unlink($this->target));
        $fork = $this->tokenEnvelope(\str_repeat('5', 64), 68);
        $backup = $this->writeRetainedBackup($fork, '4');

        try {
            $store->publishNext(\str_repeat('6', 64));
            self::fail('A same-generation recovery fork must fail before target mutation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('generation', \strtolower($exception->getMessage()));
        }

        self::assertFileDoesNotExist(
            $this->target,
            'A rejected recovery fork must not become the reader-visible capability.',
        );
        self::assertFileExists($backup);
    }

    public function testPublisherCanAdvancePastBackupOlderThanDurableActiveFence(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $store->publish(\str_repeat('7', 64), 75);
        self::assertTrue(\unlink($this->target));
        $backup = $this->writeRetainedBackup(
            $this->tokenEnvelope(\str_repeat('8', 64), 74),
            '5',
        );

        $published = $store->publishNext(\str_repeat('9', 64));

        self::assertSame(76, $published['version']);
        self::assertSame($published, $store->read());
        self::assertFileDoesNotExist($backup);
    }

    public function testRetainedBackupFromRetiredAuthorityDoesNotBlockNewEndpointClaim(): void
    {
        $first = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $oldSecret = \str_repeat('b', 64);
        $first->publish($oldSecret, 70);
        $activeEnvelope = (string)\file_get_contents($this->target);
        self::assertTrue($first->removeIfMatches($oldSecret, 70));
        $this->writeRetainedBackup($activeEnvelope, '2');
        $nextAuthority = $this->authority();
        $nextAuthority['port'] = 20971;
        $nextAuthority['instance'] = 'session_server@loopback:20971';
        $next = new SharedStateTokenStore($this->target, 0.25, $nextAuthority);
        $nextSecret = \str_repeat('c', 64);

        $published = $next->publishNext($nextSecret);

        self::assertSame(72, $published['version']);
        self::assertSame($nextSecret, $published['secret']);
        self::assertSame($published, $next->read());
    }

    public function testMalformedStableLockPayloadCannotPermanentlyBlockGenerationProgress(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $firstSecret = \str_repeat('d', 64);
        $store->publish($firstSecret, 80);
        self::assertSame(
            15,
            \file_put_contents($store->publicationLockPath(), '{"token_state":'),
        );
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($store->publicationLockPath(), 0600));
        }

        $published = $store->publishNext(\str_repeat('e', 64));

        self::assertSame(81, $published['version']);
        self::assertSame($published, $store->read());
        self::assertFileExists($store->generationLedgerPath());
    }

    public function testSeparateGenerationLedgerRejectsRollbackWhenTokenTargetIsMissing(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $store->publish(\str_repeat('f', 64), 90);
        self::assertFileExists($store->generationLedgerPath());
        self::assertTrue(\unlink($this->target));

        try {
            $store->publish(\str_repeat('0', 64), 89);
            self::fail('A missing token target must not erase its durable generation fence.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('lower', \strtolower($exception->getMessage()));
        }

        self::assertFileDoesNotExist($this->target);
    }

    public function testNextGenerationFailsClosedWhenFenceIsExhausted(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $secret = \str_repeat('5', 64);
        $store->publish($secret, PHP_INT_MAX);

        try {
            $store->publishNext(\str_repeat('6', 64));
            self::fail('An exhausted capability generation must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('exhausted', \strtolower($exception->getMessage()));
        }

        self::assertSame(
            ['secret' => $secret, 'version' => PHP_INT_MAX],
            $store->read(),
        );
    }

    public function testLegacyTokenEnvelopeFailsClosed(): void
    {
        self::assertSame(67, \file_put_contents($this->target, \str_repeat('4', 64) . ':31'));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($this->target, 0600));
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('legacy');
        (new SharedStateTokenStore($this->target, 0.25, $this->authority()))->read();
    }

    /** @return array{role:string,host:string,port:int,instance:string} */
    private function authority(): array
    {
        return [
            'role' => 'session_server',
            'host' => '127.0.0.1',
            'port' => 20970,
            'instance' => 'shared-session-20970',
        ];
    }

    private function tokenEnvelope(string $secret, int $generation): string
    {
        $authority = [
            'role' => 'session_server',
            'host' => 'loopback',
            'port' => 20970,
            'instance' => 'shared-session-20970',
            'token_path_sha256' => \hash(
                'sha256',
                \str_replace('\\', '/', (string)\realpath($this->directory))
                    . '/'
                    . \basename($this->target),
            ),
        ];
        $base = [
            'schema' => 'wls-shared-state-token/2',
            'state' => 'active',
            'generation' => $generation,
            'authority' => $authority,
            'secret' => $secret,
        ];

        return $this->canonicalJson([
            ...$base,
            'digest' => \hash('sha256', $this->canonicalJson($base)),
        ]);
    }

    /** @param array<string,mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!\is_array($item)) {
                return $item;
            }
            if (!\array_is_list($item)) {
                \ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }

            return $item;
        };

        return \json_encode(
            $normalize($value),
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        );
    }

    private function writeRetainedBackup(string $contents, string $nibble): string
    {
        $backup = $this->target . '.wls-backup-' . \str_repeat($nibble, 16);
        self::assertSame(\strlen($contents), \file_put_contents($backup, $contents));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($backup, 0600));
        }

        return $backup;
    }

    public function testPublicationLockWaitIsBoundedAndDoesNotDeleteStableLock(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.05, $this->authority());
        $lockPath = $store->publicationLockPath();
        $held = VerifiedPersistentFileLock::acquire(
            $lockPath,
            0.25,
            static fn (): array => ['purpose' => 'test-held-token-lock'],
        );
        self::assertIsResource($held);

        $started = \hrtime(true);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('token publication lock');
            $store->publish(\str_repeat('c', 64), 103);
        } finally {
            $elapsed = (\hrtime(true) - $started) / 1_000_000_000;
            @\flock($held, LOCK_UN);
            @\fclose($held);
            self::assertLessThan(0.20, $elapsed);
            self::assertFileExists($lockPath);
        }
    }

    public function testPublicationSealsExistingTokenDirectoryPermissions(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX directory mode behavior is verified on Unix.');
        }
        self::assertTrue(\chmod($this->directory, 0755));

        (new SharedStateTokenStore($this->target, 0.25, $this->authority()))->publish(
            \str_repeat('c', 64),
            104,
        );

        \clearstatcache(true, $this->directory);
        $status = \lstat($this->directory);
        self::assertIsArray($status);
        self::assertSame(0700, ((int)$status['mode']) & 0777);
    }

    public function testPublishRecoversExactRetainedArtifactsBeforeReplacingToken(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $old = $this->tokenEnvelope(\str_repeat('d', 64), 201);
        self::assertSame(\strlen($old), \file_put_contents($this->target, $old));
        $staging = $this->target . '.tmp-' . \str_repeat('a', 24);
        $backup = $this->target . '.wls-backup-' . \str_repeat('b', 16);
        $staged = $this->tokenEnvelope(\str_repeat('e', 64), 202);
        self::assertSame(\strlen($staged), \file_put_contents($staging, $staged));
        self::assertSame(\strlen($old), \file_put_contents($backup, $old));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($staging, 0600));
            self::assertTrue(\chmod($backup, 0600));
        }

        $store->publish(\str_repeat('f', 64), 203);

        self::assertSame(
            ['secret' => \str_repeat('f', 64), 'version' => 203],
            $store->read(),
        );
        self::assertFileDoesNotExist($staging);
        self::assertFileDoesNotExist($backup);
    }

    public function testPublishRecoversCommittedBackupWhenTargetIsMissing(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $backup = $this->target . '.wls-backup-' . \str_repeat('c', 16);
        $backupEnvelope = $this->tokenEnvelope(\str_repeat('7', 64), 204);
        self::assertSame(
            \strlen($backupEnvelope),
            \file_put_contents($backup, $backupEnvelope),
        );
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($backup, 0600));
        }

        $store->publish(\str_repeat('8', 64), 205);

        self::assertSame(
            ['secret' => \str_repeat('8', 64), 'version' => 205],
            $store->read(),
        );
        self::assertFileDoesNotExist($backup);
    }

    public function testPublishRollsBackCorruptTargetToBackupBeforeNewGeneration(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        self::assertSame(7, \file_put_contents($this->target, 'corrupt'));
        $backup = $this->target . '.wls-backup-' . \str_repeat('d', 16);
        $backupEnvelope = $this->tokenEnvelope(\str_repeat('9', 64), 206);
        self::assertSame(
            \strlen($backupEnvelope),
            \file_put_contents($backup, $backupEnvelope),
        );
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($backup, 0600));
        }

        $store->publish(\str_repeat('a', 64), 207);

        self::assertSame(
            ['secret' => \str_repeat('a', 64), 'version' => 207],
            $store->read(),
        );
        self::assertFileDoesNotExist($backup);
    }

    public function testPublisherRetiresUncommittedFirstPublicationStaging(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $staging = $this->target . '.tmp-' . \str_repeat('e', 24);
        self::assertSame(7, \file_put_contents($staging, 'partial'));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($staging, 0600));
        }

        $store->publish(\str_repeat('b', 64), 208);

        self::assertSame(
            ['secret' => \str_repeat('b', 64), 'version' => 208],
            $store->read(),
        );
        self::assertFileDoesNotExist($staging);
    }

    public function testInvalidBackupIsPreservedWithoutPublishingOverIt(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $backup = $this->target . '.wls-backup-' . \str_repeat('f', 16);
        self::assertSame(7, \file_put_contents($backup, 'invalid'));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($backup, 0600));
        }

        try {
            $store->publish(\str_repeat('c', 64), 209);
            self::fail('Invalid committed token backup must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('valid committed backup', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->target);
        self::assertSame('invalid', \file_get_contents($backup));
    }

    public function testValidTargetDoesNotAuthorizeDeletingInvalidBackupEvidence(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $store->publish(\str_repeat('d', 64), 210);
        $baseline = (string) \file_get_contents($this->target);
        $backup = $this->target . '.wls-backup-' . \str_repeat('0', 16);
        self::assertSame(7, \file_put_contents($backup, 'invalid'));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($backup, 0600));
        }

        try {
            $store->publish(\str_repeat('e', 64), 211);
            self::fail('Invalid token backup evidence must not be silently deleted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('invalid committed backup', $exception->getMessage());
        }

        self::assertSame($baseline, \file_get_contents($this->target));
        self::assertSame('invalid', \file_get_contents($backup));
    }

    public function testMalformedReservedArtifactFailsClosedBeforeMutation(): void
    {
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());
        $store->publish(\str_repeat('1', 64), 301);
        $baseline = (string) \file_get_contents($this->target);
        $malformed = $this->target . '.tmp-not-canonical';
        self::assertSame(8, \file_put_contents($malformed, 'evidence'));

        try {
            $store->publish(\str_repeat('2', 64), 302);
            self::fail('Malformed reserved token artifact must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('malformed', \strtolower($exception->getMessage()));
        }

        self::assertSame($baseline, \file_get_contents($this->target));
        self::assertSame('evidence', \file_get_contents($malformed));
    }

    public function testLinkTargetsAndRecoveryArtifactsAreRejected(): void
    {
        if (!\function_exists('symlink')) {
            self::markTestSkipped('symlink() is unavailable.');
        }
        $outside = $this->directory . DIRECTORY_SEPARATOR . 'outside';
        self::assertSame(68, \file_put_contents($outside, \str_repeat('3', 64) . ':401'));
        self::assertTrue(@\symlink($outside, $this->target));
        $store = new SharedStateTokenStore($this->target, 0.25, $this->authority());

        $this->expectException(\RuntimeException::class);
        $store->publish(\str_repeat('4', 64), 402);
    }

    public function testRuntimeUsesTokenStoreInsteadOfInPlaceTokenWrites(): void
    {
        $root = \dirname(__DIR__, 3);
        $server = (string) \file_get_contents($root . '/Session/Server/SessionServer.php');
        $probe = (string) \file_get_contents($root . '/Service/SharedStateProtocolProbe.php');
        $pooled = (string) \file_get_contents($root . '/Shared/Connection/PooledConnection.php');

        self::assertStringContainsString('SharedStateTokenStore', $server);
        self::assertStringNotContainsString('file_put_contents($this->tokenFilePath', $server);
        self::assertStringNotContainsString('unlink($this->tokenFilePath)', $server);
        self::assertStringNotContainsString('@\\exec(', $server);
        self::assertStringNotContainsString('sudo -n', $server);
        self::assertStringContainsString('SharedStateTokenStore::readPath', $probe);
        self::assertStringContainsString('SharedStateTokenStore::readCapabilityStatePath', $pooled);
    }
}
