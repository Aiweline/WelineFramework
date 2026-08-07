<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxConfigPublication;

final class NginxConfigPublicationTest extends TestCase
{
    private string $root = '';
    private string $active = '';
    private NginxConfigPublication $publication;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-nginx-publication-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $canonicalRoot = \realpath($this->root);
        self::assertIsString($canonicalRoot);
        $this->root = $canonicalRoot;
        $this->active = $this->root . DIRECTORY_SEPARATOR . 'nginx.conf';
        $this->publication = new NginxConfigPublication($this->active, 'test nginx');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testCandidatePublishAndRollbackRestorePreviousActive(): void
    {
        self::assertSame(10, \file_put_contents($this->active, 'old-config'));
        $candidate = $this->publication->stageCandidate('new-config');
        $published = $this->publication->publishCandidate($candidate, \str_repeat('a', 32));

        self::assertSame('new-config', \file_get_contents($this->active));
        self::assertIsString($published['rollback']);
        self::assertSame('old-config', \file_get_contents($published['rollback']));

        $this->publication->rollbackPublished($published['rollback']);
        self::assertSame('old-config', \file_get_contents($this->active));
        self::assertFileDoesNotExist($published['rollback']);
    }

    public function testCommitPreservesPreviousGenerationAsLastGood(): void
    {
        self::assertSame(10, \file_put_contents($this->active, 'old-config'));
        $candidate = $this->publication->stageCandidate('new-config');
        $published = $this->publication->publishCandidate($candidate, \str_repeat('b', 32));
        $rollback = $published['rollback'];
        self::assertIsString($rollback);

        self::assertTrue($this->publication->commitPublished($rollback));
        self::assertSame('new-config', \file_get_contents($this->active));
        self::assertSame('old-config', \file_get_contents($this->active . '.last-good'));
        self::assertFileDoesNotExist($rollback);
    }

    public function testCommitCollectsItsRollbackAtomicTemporary(): void
    {
        self::assertSame(10, \file_put_contents($this->active, 'old-config'));
        $candidate = $this->publication->stageCandidate('new-config');
        $published = $this->publication->publishCandidate($candidate, \str_repeat('4', 32));
        $rollback = $published['rollback'];
        self::assertIsString($rollback);
        $temporary = $rollback . '.tmp-' . \str_repeat('d', 24);
        self::assertSame(0, \file_put_contents($temporary, ''));

        self::assertTrue($this->publication->commitPublished($rollback));

        self::assertFileDoesNotExist($rollback);
        self::assertFileDoesNotExist($temporary);
    }

    public function testRollbackCollectsItsAtomicTemporary(): void
    {
        self::assertSame(10, \file_put_contents($this->active, 'old-config'));
        $candidate = $this->publication->stageCandidate('new-config');
        $published = $this->publication->publishCandidate($candidate, \str_repeat('5', 32));
        $rollback = $published['rollback'];
        self::assertIsString($rollback);
        $temporary = $rollback . '.tmp-' . \str_repeat('e', 24);
        self::assertSame(0, \file_put_contents($temporary, ''));

        $this->publication->rollbackPublished($rollback);

        self::assertSame('old-config', \file_get_contents($this->active));
        self::assertFileDoesNotExist($rollback);
        self::assertFileDoesNotExist($temporary);
    }

    public function testResolvedRollbackTemporaryCleanupIsTransactionExact(): void
    {
        $rollback = $this->publication->rollbackPathForTransaction(\str_repeat('1', 32));
        $temporary = $rollback . '.tmp-' . \str_repeat('a', 24);
        $foreign = $this->publication->rollbackPathForTransaction(\str_repeat('2', 32))
            . '.tmp-' . \str_repeat('b', 24);
        self::assertSame(0, \file_put_contents($temporary, ''));
        self::assertSame(7, \file_put_contents($foreign, 'foreign'));

        $this->publication->cleanupResolvedRollbackTemporaries($rollback);

        self::assertFileDoesNotExist($temporary);
        self::assertSame('foreign', \file_get_contents($foreign));
    }

    public function testUnresolvedRollbackTargetPreservesItsAtomicTemporary(): void
    {
        $rollback = $this->publication->rollbackPathForTransaction(\str_repeat('3', 32));
        $temporary = $rollback . '.tmp-' . \str_repeat('c', 24);
        self::assertSame(8, \file_put_contents($rollback, 'rollback'));
        self::assertSame(0, \file_put_contents($temporary, ''));

        try {
            $this->publication->cleanupResolvedRollbackTemporaries($rollback);
            self::fail('Rollback staging evidence must remain until the transaction is resolved.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('has not been resolved', $exception->getMessage());
        }

        self::assertFileExists($rollback);
        self::assertFileExists($temporary);
    }

    public function testInvalidCandidateNeverChangesActiveConfig(): void
    {
        self::assertSame(10, \file_put_contents($this->active, 'old-config'));
        $outside = $this->root . DIRECTORY_SEPARATOR . 'outside.conf';
        self::assertSame(10, \file_put_contents($outside, 'bad-config'));

        try {
            $this->publication->publishCandidate($outside, \str_repeat('c', 32));
            self::fail('A candidate outside the generated transaction scope must fail.');
        } catch (\InvalidArgumentException) {
            self::assertSame('old-config', \file_get_contents($this->active));
            self::assertSame('bad-config', \file_get_contents($outside));
        }
    }

    public function testInterruptedMissingActiveRecoversLastGood(): void
    {
        self::assertSame(10, \file_put_contents($this->active, 'old-config'));
        $candidate = $this->publication->stageCandidate('new-config');
        $published = $this->publication->publishCandidate($candidate, \str_repeat('d', 32));
        self::assertTrue($this->publication->commitPublished($published['rollback']));
        self::assertTrue(\unlink($this->active));

        $this->publication->recoverInterruptedPublication();

        self::assertSame('old-config', \file_get_contents($this->active));
    }

    public function testInterruptedLegacyRollbackCommitsThePreviousConfigAsLastGood(): void
    {
        self::assertSame(10, \file_put_contents($this->active, 'old-config'));
        $candidate = $this->publication->stageCandidate('new-config');
        $published = $this->publication->publishCandidate(
            $candidate,
            \str_repeat('6', 32),
        );
        $rollback = $published['rollback'];
        self::assertIsString($rollback);

        $this->publication->recoverInterruptedPublication();

        self::assertSame('new-config', \file_get_contents($this->active));
        self::assertSame('old-config', \file_get_contents($this->active . '.last-good'));
        self::assertFileDoesNotExist($rollback);
    }

    public function testInterruptedLegacyRollbackRestoresADeletedActiveConfig(): void
    {
        self::assertSame(10, \file_put_contents($this->active, 'old-config'));
        $candidate = $this->publication->stageCandidate('new-config');
        $published = $this->publication->publishCandidate(
            $candidate,
            \str_repeat('7', 32),
        );
        $rollback = $published['rollback'];
        self::assertIsString($rollback);
        self::assertTrue(\unlink($this->active));

        $this->publication->recoverInterruptedPublication();

        self::assertSame('old-config', \file_get_contents($this->active));
        self::assertFileDoesNotExist($rollback);
    }

    public function testAmbiguousInterruptedRollbacksFailBeforeAnyRecoveryMutation(): void
    {
        self::assertSame(13, \file_put_contents($this->active, 'active-config'));
        $first = $this->publication->rollbackPathForTransaction(\str_repeat('8', 32));
        $second = $this->publication->rollbackPathForTransaction(\str_repeat('9', 32));
        self::assertSame(5, \file_put_contents($first, 'first'));
        self::assertSame(6, \file_put_contents($second, 'second'));

        try {
            $this->publication->recoverInterruptedPublication();
            self::fail('Multiple untracked rollback generations must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('ambiguous', $exception->getMessage());
        }

        self::assertSame('active-config', \file_get_contents($this->active));
        self::assertSame('first', \file_get_contents($first));
        self::assertSame('second', \file_get_contents($second));
    }

    public function testResolvedOrphanRollbackTemporaryIsCollectedBeforeNewPublication(): void
    {
        self::assertSame(13, \file_put_contents($this->active, 'active-config'));
        $rollback = $this->publication->rollbackPathForTransaction(\str_repeat('a', 32));
        $temporary = $rollback . '.tmp-' . \str_repeat('1', 24);
        self::assertSame(0, \file_put_contents($temporary, ''));

        $this->publication->recoverInterruptedPublication();

        self::assertFileDoesNotExist($temporary);
        self::assertSame('active-config', \file_get_contents($this->active));
    }

    public function testMalformedRollbackArtifactPreservesTheCompleteRecoverySet(): void
    {
        self::assertSame(13, \file_put_contents($this->active, 'active-config'));
        $rollback = $this->publication->rollbackPathForTransaction(\str_repeat('b', 32));
        $temporary = $rollback . '.tmp-' . \str_repeat('2', 24);
        $malformed = $this->active . '.rollback.malformed';
        self::assertSame(0, \file_put_contents($temporary, ''));
        self::assertSame(9, \file_put_contents($malformed, 'malformed'));

        try {
            $this->publication->recoverInterruptedPublication();
            self::fail('A malformed rollback leaf must fail before cleanup.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('malformed reserved leaf', $exception->getMessage());
        }

        self::assertFileExists($temporary);
        self::assertFileExists($malformed);
        self::assertSame('active-config', \file_get_contents($this->active));
    }

    public function testWindowsRollbackNamespaceRejectsCaseAliasedReservedLeaf(): void
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Windows case-insensitive rollback namespace contract.');
        }
        self::assertSame(13, \file_put_contents($this->active, 'active-config'));
        $aliased = \dirname($this->active) . DIRECTORY_SEPARATOR
            . \strtoupper(\basename($this->active)) . '.ROLLBACK.' . \str_repeat('c', 32);
        self::assertSame(8, \file_put_contents($aliased, 'rollback'));

        try {
            $this->publication->recoverInterruptedPublication();
            self::fail('A Windows case alias of the rollback namespace must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('malformed reserved leaf', $exception->getMessage());
        }

        self::assertFileExists($aliased);
        self::assertSame('active-config', \file_get_contents($this->active));
    }

    public function testRecoveryCollectsExactOrphanCandidatesButPreservesUnknownFiles(): void
    {
        self::assertSame(13, \file_put_contents($this->active, 'active-config'));
        $first = $this->publication->stageCandidate('first-candidate');
        $second = $this->publication->stageCandidate('second-candidate');
        $unknown = $this->root . DIRECTORY_SEPARATOR . 'foreign.candidate.1.aaaaaaaa';
        self::assertSame(7, \file_put_contents($unknown, 'foreign'));

        $this->publication->recoverInterruptedPublication();

        self::assertFileDoesNotExist($first);
        self::assertFileDoesNotExist($second);
        self::assertSame('foreign', \file_get_contents($unknown));
        self::assertSame('active-config', \file_get_contents($this->active));
    }

    public function testMalformedReservedCandidatePreservesTheCompleteRecoverySet(): void
    {
        self::assertSame(13, \file_put_contents($this->active, 'active-config'));
        $valid = $this->publication->stageCandidate('valid-candidate');
        $malformed = $this->active . '.candidate.bad';
        self::assertSame(9, \file_put_contents($malformed, 'malformed'));

        try {
            $this->publication->recoverInterruptedPublication();
            self::fail('A malformed reserved candidate leaf must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'malformed reserved leaf',
                $exception->getMessage(),
            );
        }

        self::assertFileExists($valid);
        self::assertFileExists($malformed);
        self::assertSame('active-config', \file_get_contents($this->active));
    }

    public function testRecoveryCollectsAnExactPartialAtomicCandidateTemporary(): void
    {
        self::assertSame(13, \file_put_contents($this->active, 'active-config'));
        $candidate = $this->publication->stageCandidate('valid-candidate');
        $temporary = $this->active . '.candidate.123.aaaaaaaa.tmp-'
            . \str_repeat('b', 24);
        self::assertSame(0, \file_put_contents($temporary, ''));

        $this->publication->recoverInterruptedPublication();

        self::assertFileDoesNotExist($candidate);
        self::assertFileDoesNotExist($temporary);
        self::assertSame('active-config', \file_get_contents($this->active));
    }

    public function testRecoveryCollectsRejectedRollbackDebrisAndItsAtomicTemporary(): void
    {
        self::assertSame(13, \file_put_contents($this->active, 'active-config'));
        $rejected = $this->active . '.rejected.123.aaaaaaaa';
        $temporary = $rejected . '.tmp-' . \str_repeat('b', 24);
        self::assertSame(15, \file_put_contents($rejected, 'rejected-config'));
        self::assertSame(0, \file_put_contents($temporary, ''));

        $this->publication->recoverInterruptedPublication();

        self::assertFileDoesNotExist($rejected);
        self::assertFileDoesNotExist($temporary);
        self::assertSame('active-config', \file_get_contents($this->active));
    }

    public function testUnsafeLaterCandidatePreservesAnEarlierValidCandidate(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Creating an unprivileged file symlink is not portable on Windows.');
        }
        self::assertSame(13, \file_put_contents($this->active, 'active-config'));
        $valid = $this->publication->stageCandidate('valid-candidate');
        $linked = $this->active . '.candidate.999999.aaaaaaaa';
        self::assertTrue(\symlink($valid, $linked));

        try {
            $this->publication->recoverInterruptedPublication();
            self::fail('An unsafe later candidate must fail before any cleanup.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'regular non-linked file',
                $exception->getMessage(),
            );
        }

        self::assertFileExists($valid);
        self::assertTrue(\is_link($linked));
    }

    public function testCandidateQuotaFailurePreservesEveryCandidate(): void
    {
        self::assertSame(13, \file_put_contents($this->active, 'active-config'));
        $candidates = [];
        for ($index = 1; $index <= 65; ++$index) {
            $candidate = $this->active . '.candidate.' . $index . '.'
                . \str_pad(\dechex($index), 8, '0', STR_PAD_LEFT);
            self::assertSame(1, \file_put_contents($candidate, 'x'));
            $candidates[] = $candidate;
        }

        try {
            $this->publication->recoverInterruptedPublication();
            self::fail('Recovery must remain bounded before deleting any candidate.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'orphan candidate quota is exhausted',
                $exception->getMessage(),
            );
        }

        foreach ($candidates as $candidate) {
            self::assertFileExists($candidate);
        }
    }

    public function testFirstPublicationRollbackRemovesRejectedCandidate(): void
    {
        $candidate = $this->publication->stageCandidate('first-config');
        $published = $this->publication->publishCandidate($candidate, \str_repeat('e', 32));
        self::assertNull($published['rollback']);
        self::assertSame('first-config', \file_get_contents($this->active));

        $this->publication->rollbackPublished(null);

        self::assertFileDoesNotExist($this->active);
    }

    public function testRetainedActiveAndLastGoodBackupsAreCollectedOnlyAfterValidation(): void
    {
        self::assertSame(12, \file_put_contents($this->active, 'active-valid'));
        $lastGood = $this->active . '.last-good';
        self::assertSame(15, \file_put_contents($lastGood, 'last-good-valid'));
        $activeBackup = $this->active . '.wls-backup-' . \str_repeat('a', 16);
        $lastGoodBackup = $lastGood . '.wls-backup-' . \str_repeat('b', 16);
        self::assertSame(10, \file_put_contents($activeBackup, 'old-active'));
        self::assertSame(13, \file_put_contents($lastGoodBackup, 'old-last-good'));

        $validated = [];
        $this->publication->cleanupAtomicWriteRecoveryBackups(
            static function (string $path, string $contents, string $kind) use (&$validated): void {
                $expected = $kind === 'active config' ? 'active-valid' : 'last-good-valid';
                if (!\hash_equals($expected, $contents)) {
                    throw new \RuntimeException('Unexpected recovery target contents.');
                }
                $validated[] = [$path, $kind];
            },
        );

        self::assertCount(2, $validated);
        self::assertFileDoesNotExist($activeBackup);
        self::assertFileDoesNotExist($lastGoodBackup);
        self::assertSame('active-valid', \file_get_contents($this->active));
        self::assertSame('last-good-valid', \file_get_contents($lastGood));
    }

    public function testRetainedActiveAtomicTemporaryIsCollectedOnlyAfterTargetValidation(): void
    {
        self::assertSame(12, \file_put_contents($this->active, 'active-valid'));
        $temporary = $this->active . '.tmp-' . \str_repeat('a', 24);
        self::assertSame(7, \file_put_contents($temporary, 'partial'));
        $validated = 0;

        $this->publication->cleanupAtomicWriteRecoveryBackups(
            static function (
                string $path,
                string $contents,
                string $kind,
            ) use (&$validated): void {
                self::assertSame('active config', $kind);
                self::assertSame('active-valid', $contents);
                self::assertStringEndsWith('nginx.conf', $path);
                ++$validated;
            },
        );

        self::assertSame(1, $validated);
        self::assertFileDoesNotExist($temporary);
        self::assertSame('active-valid', \file_get_contents($this->active));
    }

    public function testMissingAtomicTemporaryTargetPreservesTheTemporaryEvidence(): void
    {
        $temporary = $this->active . '.tmp-' . \str_repeat('b', 24);
        self::assertSame(7, \file_put_contents($temporary, 'partial'));

        try {
            $this->publication->cleanupAtomicWriteRecoveryBackups(
                static function (): void {
                    self::fail('A missing paired target must not reach semantic validation.');
                },
            );
            self::fail('A temporary without its committed target must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('missing or unsafe', $exception->getMessage());
        }

        self::assertFileExists($temporary);
    }

    public function testInvalidLaterTemporaryTargetPreservesAllEarlierTemporaries(): void
    {
        self::assertSame(12, \file_put_contents($this->active, 'active-valid'));
        $lastGood = $this->active . '.last-good';
        self::assertSame(16, \file_put_contents($lastGood, 'last-good-broken'));
        $activeTemporary = $this->active . '.tmp-' . \str_repeat('c', 24);
        $lastGoodTemporary = $lastGood . '.tmp-' . \str_repeat('d', 24);
        self::assertSame(7, \file_put_contents($activeTemporary, 'partial'));
        self::assertSame(7, \file_put_contents($lastGoodTemporary, 'partial'));

        try {
            $this->publication->cleanupAtomicWriteRecoveryBackups(
                static function (
                    string $_path,
                    string $contents,
                    string $kind,
                ): void {
                    $expected = $kind === 'active config'
                        ? 'active-valid'
                        : 'last-good-valid';
                    if (!\hash_equals($expected, $contents)) {
                        throw new \RuntimeException('invalid last-good temporary target');
                    }
                },
            );
            self::fail('Every paired target must validate before any temporary is deleted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'invalid last-good temporary target',
                $exception->getMessage(),
            );
        }

        self::assertFileExists($activeTemporary);
        self::assertFileExists($lastGoodTemporary);
    }

    public function testMalformedAtomicTemporaryPreservesAllExactTemporaries(): void
    {
        self::assertSame(12, \file_put_contents($this->active, 'active-valid'));
        $valid = $this->active . '.tmp-' . \str_repeat('e', 24);
        $malformed = $this->active . '.tmp-bad';
        self::assertSame(7, \file_put_contents($valid, 'partial'));
        self::assertSame(9, \file_put_contents($malformed, 'malformed'));

        try {
            $this->publication->cleanupAtomicWriteRecoveryBackups(
                static function (): void {
                    self::fail('Malformed discovery must fail before target validation.');
                },
            );
            self::fail('A malformed reserved atomic temporary must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'reserved leaf is malformed',
                $exception->getMessage(),
            );
        }

        self::assertFileExists($valid);
        self::assertFileExists($malformed);
    }

    public function testInvalidOrMissingConfigTargetPreservesRetainedBackupEvidence(): void
    {
        self::assertSame(7, \file_put_contents($this->active, 'damaged'));
        $activeBackup = $this->active . '.wls-backup-' . \str_repeat('c', 16);
        self::assertSame(10, \file_put_contents($activeBackup, 'old-active'));
        try {
            $this->publication->cleanupAtomicWriteRecoveryBackups(
                static function (): void {
                    throw new \RuntimeException('invalid nginx config');
                },
            );
            self::fail('An invalid paired target must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('invalid nginx config', $exception->getMessage());
        }
        self::assertFileExists($activeBackup);
        self::assertSame('damaged', \file_get_contents($this->active));

        self::assertTrue(\unlink($this->active));
        try {
            $this->publication->cleanupAtomicWriteRecoveryBackups(
                static function (): void {
                    self::fail('A missing paired target must not reach semantic validation.');
                },
            );
            self::fail('A missing paired target must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('missing or unsafe', $exception->getMessage());
        }
        self::assertFileExists($activeBackup);
    }

    public function testInvalidLastGoodPreservesEarlierActiveBackupEvidence(): void
    {
        self::assertSame(12, \file_put_contents($this->active, 'active-valid'));
        $lastGood = $this->active . '.last-good';
        self::assertSame(16, \file_put_contents($lastGood, 'last-good-broken'));
        $activeBackup = $this->active . '.wls-backup-' . \str_repeat('d', 16);
        $lastGoodBackup = $lastGood . '.wls-backup-' . \str_repeat('e', 16);
        self::assertSame(10, \file_put_contents($activeBackup, 'old-active'));
        self::assertSame(13, \file_put_contents($lastGoodBackup, 'old-last-good'));

        try {
            $this->publication->cleanupAtomicWriteRecoveryBackups(
                static function (
                    string $_path,
                    string $contents,
                    string $kind,
                ): void {
                    $expected = $kind === 'active config'
                        ? 'active-valid'
                        : 'last-good-valid';
                    if (!\hash_equals($expected, $contents)) {
                        throw new \RuntimeException('invalid last-good nginx config');
                    }
                },
            );
            self::fail('A damaged later target must fail the complete config closure.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'invalid last-good nginx config',
                $exception->getMessage(),
            );
        }

        self::assertFileExists($activeBackup);
        self::assertFileExists($lastGoodBackup);
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
