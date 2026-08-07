<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\Runtime\NginxRuntimeArtifact;

final class NginxRuntimeArtifactRecoveryTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-nginx-artifact-recovery-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testInstallReclaimsExactOrphanCandidateWithoutTouchingOtherSlots(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-A';
        $orphan = $slot . '.candidate.' . \str_repeat('a', 16);
        $otherSlotCandidate = $this->root . DIRECTORY_SEPARATOR
            . 'slot-B.candidate.' . \str_repeat('b', 16);
        self::assertTrue(\mkdir($orphan . DIRECTORY_SEPARATOR . 'partial', 0700, true));
        self::assertSame(7, \file_put_contents(
            $orphan . DIRECTORY_SEPARATOR . 'partial' . DIRECTORY_SEPARATOR . 'payload',
            'partial',
        ));
        self::assertTrue(\mkdir($otherSlotCandidate, 0700));

        $manifest = (new NginxRuntimeArtifact())->install(
            $slot,
            'gateway-nginx',
            ['bin/nginx' => ['contents' => 'binary', 'mode' => 0700]],
        );

        self::assertDirectoryDoesNotExist($orphan);
        self::assertDirectoryExists($otherSlotCandidate);
        self::assertDirectoryExists($slot);
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            (string)$manifest['runtime_generation'],
        );
    }

    public function testPosixAlternateSeparatorCannotDetachTheExactSlotLock(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Backslash is a native separator on Windows.');
        }
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot\\detached';

        try {
            $this->installSmallArtifact($slot);
            self::fail('A POSIX slot leaf containing an alternate separator must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('slot path', $exception->getMessage());
        }

        self::assertDirectoryDoesNotExist($slot);
        self::assertSame([], \glob($this->root . DIRECTORY_SEPARATOR . 'detached.candidate.*') ?: []);
        self::assertFileDoesNotExist(
            $this->root . DIRECTORY_SEPARATOR . 'detached.install.lock',
        );
    }

    public function testMalformedReservedLeafPreservesEveryRecoveryCandidate(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-malformed';
        $valid = $slot . '.candidate.' . \str_repeat('c', 16);
        $malformed = $slot . '.candidate.not-a-generation';
        self::assertTrue(\mkdir($valid, 0700));
        self::assertTrue(\mkdir($malformed, 0700));

        try {
            $this->installSmallArtifact($slot);
            self::fail('A malformed reserved candidate leaf must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('malformed reserved leaf', $exception->getMessage());
        }

        self::assertDirectoryExists($valid);
        self::assertDirectoryExists($malformed);
        self::assertDirectoryDoesNotExist($slot);
    }

    public function testReservedCandidateRegularFileFailsClosed(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-regular';
        $valid = $slot . '.candidate.' . \str_repeat('d', 16);
        $regular = $slot . '.candidate.' . \str_repeat('e', 16);
        self::assertTrue(\mkdir($valid, 0700));
        self::assertSame(8, \file_put_contents($regular, 'evidence'));

        try {
            $this->installSmallArtifact($slot);
            self::fail('A reserved candidate regular file must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('linked or special', $exception->getMessage());
        }

        self::assertDirectoryExists($valid);
        self::assertFileExists($regular);
        self::assertDirectoryDoesNotExist($slot);
    }

    public function testReservedCandidateSymlinkFailsClosed(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-linked';
        $valid = $slot . '.candidate.' . \str_repeat('f', 16);
        $linked = $slot . '.candidate.' . \str_repeat('1', 16);
        $linkTarget = $this->root . DIRECTORY_SEPARATOR . 'link-target';
        self::assertTrue(\mkdir($valid, 0700));
        self::assertTrue(\mkdir($linkTarget, 0700));
        if (!@\symlink($linkTarget, $linked)) {
            self::markTestSkipped('This filesystem cannot create a candidate symlink fixture.');
        }

        try {
            $this->installSmallArtifact($slot);
            self::fail('A reserved candidate symlink must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('linked or special', $exception->getMessage());
        }

        self::assertDirectoryExists($valid);
        self::assertTrue(\is_link($linked));
        self::assertDirectoryExists($linkTarget);
        self::assertDirectoryDoesNotExist($slot);
    }

    public function testCandidateCountQuotaFailsBeforeAnyRecoveryMutation(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-count-limit';
        $candidates = [];
        for ($index = 0; $index <= NginxRuntimeArtifact::MAX_RECOVERY_CANDIDATES; ++$index) {
            $candidate = $slot . '.candidate.' . \sprintf('%016x', $index + 1);
            self::assertTrue(\mkdir($candidate, 0700));
            $candidates[] = $candidate;
        }

        try {
            $this->installSmallArtifact($slot);
            self::fail('An exhausted candidate recovery quota must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('candidate quota', $exception->getMessage());
        }

        foreach ($candidates as $candidate) {
            self::assertDirectoryExists($candidate);
        }
        self::assertDirectoryDoesNotExist($slot);
    }

    public function testCandidateCountAtQuotaRemainsRecoverable(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-count-boundary';
        $candidates = [];
        for ($index = 0; $index < NginxRuntimeArtifact::MAX_RECOVERY_CANDIDATES; ++$index) {
            $candidate = $slot . '.candidate.' . \sprintf('%016x', $index + 32);
            self::assertTrue(\mkdir($candidate, 0700));
            $candidates[] = $candidate;
        }

        $this->installSmallArtifact($slot);

        foreach ($candidates as $candidate) {
            self::assertDirectoryDoesNotExist($candidate);
        }
        self::assertDirectoryExists($slot);
    }

    public function testRecoveryRejectsCandidateTreeBeyondDepthLimitBeforeDeletion(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-depth-limit';
        $candidate = $slot . '.candidate.' . \str_repeat('2', 16);
        self::assertTrue(\mkdir($candidate, 0700));
        $deepest = $candidate;
        for ($depth = 0; $depth <= NginxRuntimeArtifact::MAX_PATH_DEPTH; ++$depth) {
            $deepest .= DIRECTORY_SEPARATOR . 'd';
            self::assertTrue(\mkdir($deepest, 0700));
        }

        try {
            $this->installSmallArtifact($slot);
            self::fail('An over-depth recovery tree must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('depth safety limit', $exception->getMessage());
        }

        self::assertDirectoryExists($candidate);
        self::assertDirectoryExists($deepest);
        self::assertDirectoryDoesNotExist($slot);
    }

    public function testRecoveryRejectsCandidateTreeBeyondEntryLimitBeforeDeletion(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-entry-limit';
        $candidate = $slot . '.candidate.' . \str_repeat('3', 16);
        self::assertTrue(\mkdir($candidate, 0700));
        for ($index = 0; $index <= NginxRuntimeArtifact::MAX_TREE_ENTRIES; ++$index) {
            self::assertSame(0, \file_put_contents(
                $candidate . DIRECTORY_SEPARATOR . 'entry-' . $index,
                '',
            ));
        }

        try {
            $this->installSmallArtifact($slot);
            self::fail('An oversized recovery tree must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('entry safety limit', $exception->getMessage());
        }

        self::assertDirectoryExists($candidate);
        self::assertFileExists($candidate . DIRECTORY_SEPARATOR . 'entry-0');
        self::assertDirectoryDoesNotExist($slot);
    }

    public function testRecoveryRejectsAggregateCandidateTreesBeyondEntryBudget(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-aggregate-entry-limit';
        $candidates = [
            $slot . '.candidate.' . \str_repeat('a', 15) . '1',
            $slot . '.candidate.' . \str_repeat('a', 15) . '2',
        ];
        $entriesPerCandidate = \intdiv(
            NginxRuntimeArtifact::MAX_RECOVERY_TREE_RECORDS,
            2,
        ) + 1;
        foreach ($candidates as $candidate) {
            self::assertTrue(\mkdir($candidate, 0700));
            for ($index = 0; $index < $entriesPerCandidate; ++$index) {
                self::assertSame(0, \file_put_contents(
                    $candidate . DIRECTORY_SEPARATOR . 'entry-' . $index,
                    '',
                ));
            }
        }

        try {
            $this->installSmallArtifact($slot);
            self::fail('Aggregate recovery trees must have one fixed memory budget.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'aggregate tree entry quota',
                $exception->getMessage(),
            );
        }

        foreach ($candidates as $candidate) {
            self::assertDirectoryExists($candidate);
            self::assertFileExists($candidate . DIRECTORY_SEPARATOR . 'entry-0');
        }
        self::assertDirectoryDoesNotExist($slot);
    }

    public function testRecoveryRejectsCandidateBeyondArtifactByteLimitBeforeDeletion(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-byte-limit';
        $candidate = $slot . '.candidate.' . \str_repeat('8', 16);
        self::assertTrue(\mkdir($candidate, 0700));
        $oversized = $candidate . DIRECTORY_SEPARATOR . 'oversized-sparse-component';
        $handle = \fopen($oversized, 'wb');
        self::assertIsResource($handle);
        try {
            self::assertTrue(\ftruncate(
                $handle,
                NginxRuntimeArtifact::MAX_TOTAL_BYTES + 1,
            ));
        } finally {
            \fclose($handle);
        }

        try {
            $this->installSmallArtifact($slot);
            self::fail('An oversized recovery candidate must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('byte limit', $exception->getMessage());
        }

        self::assertDirectoryExists($candidate);
        self::assertFileExists($oversized);
        self::assertDirectoryDoesNotExist($slot);
    }

    public function testRecoveryParentRawEntryQuotaFailsBeforeCandidateCreation(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-raw-entry-limit';
        $rawEntries = NginxRuntimeArtifact::MAX_RECOVERY_DIRECTORY_ENTRIES + 1;
        for ($index = 0; $index < $rawEntries; ++$index) {
            self::assertSame(0, \file_put_contents(
                $this->root . DIRECTORY_SEPARATOR . 'unrelated-' . $index,
                '',
            ));
        }

        try {
            $this->installSmallArtifact($slot);
            self::fail('An oversized recovery parent namespace must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('raw entry quota', $exception->getMessage());
        }

        self::assertFileExists($this->root . DIRECTORY_SEPARATOR . 'unrelated-0');
        self::assertFileExists(
            $this->root . DIRECTORY_SEPARATOR . 'unrelated-' . ($rawEntries - 1),
        );
        self::assertDirectoryDoesNotExist($slot);
    }

    public function testCandidateEntryCapacityIsReservedBeforeMkdir(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-capacity-limit';
        // readdir() includes dot, dot-dot and the exact-slot lock created
        // before the namespace scan. Fill every remaining raw entry so mkdir
        // would become the first over-limit object.
        $unrelatedEntries = NginxRuntimeArtifact::MAX_RECOVERY_DIRECTORY_ENTRIES - 3;
        for ($index = 0; $index < $unrelatedEntries; ++$index) {
            self::assertSame(0, \file_put_contents(
                $this->root . DIRECTORY_SEPARATOR . 'capacity-' . $index,
                '',
            ));
        }

        try {
            $this->installSmallArtifact($slot);
            self::fail('Candidate capacity must be reserved before mkdir.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('reserved entry capacity', $exception->getMessage());
        }

        self::assertFileExists($this->root . DIRECTORY_SEPARATOR . 'capacity-0');
        self::assertFileExists(
            $this->root . DIRECTORY_SEPARATOR . 'capacity-' . ($unrelatedEntries - 1),
        );
        self::assertDirectoryDoesNotExist($slot);
        self::assertSame([], \glob($slot . '.candidate.*') ?: []);
    }

    public function testExistingTargetPreservesOrphanCandidateEvidence(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-existing';
        $candidate = $slot . '.candidate.' . \str_repeat('4', 16);
        self::assertTrue(\mkdir($candidate, 0700));
        self::assertTrue(\mkdir($slot, 0700));

        try {
            $this->installSmallArtifact($slot);
            self::fail('An existing immutable target must block recovery cleanup.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }

        self::assertDirectoryExists($candidate);
        self::assertDirectoryExists($slot);
    }

    public function testUnsafeInstallLockPreservesOrphanCandidateEvidence(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-unsafe-lock';
        $candidate = $slot . '.candidate.' . \str_repeat('5', 16);
        self::assertTrue(\mkdir($candidate, 0700));
        self::assertTrue(\mkdir($slot . '.install.lock', 0700));

        try {
            $this->installSmallArtifact($slot);
            self::fail('An unsafe exact-slot installation lock must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('state lock', $exception->getMessage());
        }

        self::assertDirectoryExists($candidate);
        self::assertDirectoryExists($slot . '.install.lock');
        self::assertDirectoryDoesNotExist($slot);
    }

    public function testNonEmptyInstallLockFailsClosedBeforeOrphanRecovery(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-non-empty-lock';
        $candidate = $slot . '.candidate.' . \str_repeat('9', 16);
        self::assertTrue(\mkdir($candidate, 0700));
        self::assertSame(6, \file_put_contents($slot . '.install.lock', 'poison'));
        @\chmod($slot . '.install.lock', 0600);

        try {
            $this->installSmallArtifact($slot);
            self::fail('A non-empty exact-slot installation lock must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('invalid size', $exception->getMessage());
        }

        self::assertDirectoryExists($candidate);
        self::assertSame('poison', \file_get_contents($slot . '.install.lock'));
        self::assertDirectoryDoesNotExist($slot);
    }

    public function testInstallWaitsForExactSlotLockBeforeTouchingCandidateNamespace(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-concurrent';
        $orphan = $slot . '.candidate.' . \str_repeat('6', 16);
        self::assertTrue(\mkdir($orphan, 0700));
        $lockPath = $slot . '.install.lock';
        $lock = \fopen($lockPath, 'x+b');
        self::assertIsResource($lock);
        self::assertTrue(\flock($lock, LOCK_EX));
        @\chmod($lockPath, 0600);

        $autoload = \dirname(__DIR__, 10) . DIRECTORY_SEPARATOR
            . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        self::assertFileExists($autoload);
        $code = <<<'PHP'
require $argv[1];
fwrite(STDOUT, "started\n");
fflush(STDOUT);
try {
    (new \Weline\Server\Service\Edge\Nginx\Runtime\NginxRuntimeArtifact())->install(
        $argv[2],
        'gateway-nginx',
        ['bin/nginx' => ['contents' => 'binary', 'mode' => 0700]],
    );
    fwrite(STDOUT, "installed\n");
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable::class . ': ' . $throwable->getMessage() . "\n");
    throw $throwable;
}
PHP;
        $pipes = [];
        $process = \proc_open(
            [PHP_BINARY, '-r', $code, $autoload, $slot],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($process);
        self::assertCount(3, $pipes);
        @\fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        $output = '';
        $deadline = \hrtime(true) + 2_000_000_000;
        try {
            do {
                $chunk = \stream_get_contents($pipes[1]);
                if (\is_string($chunk)) {
                    $output .= $chunk;
                }
                if (\str_contains($output, "started\n")) {
                    break;
                }
                \usleep(10_000);
            } while (\hrtime(true) < $deadline);
            self::assertStringContainsString("started\n", $output);
            \usleep(100_000);
            $status = \proc_get_status($process);
            self::assertTrue($status['running']);
            self::assertDirectoryExists($orphan);
            self::assertDirectoryDoesNotExist($slot);
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }

        \stream_set_blocking($pipes[1], true);
        $remainingOutput = \stream_get_contents($pipes[1]);
        $errorOutput = \stream_get_contents($pipes[2]);
        @\fclose($pipes[1]);
        @\fclose($pipes[2]);
        $exitCode = \proc_close($process);
        self::assertSame(0, $exitCode, (string)$errorOutput);
        self::assertStringContainsString('installed', $output . (string)$remainingOutput);
        self::assertDirectoryDoesNotExist($orphan);
        self::assertDirectoryExists($slot);
    }

    public function testFailedPublicationCollectsOnlyItsOwnCandidate(): void
    {
        $slot = $this->root . DIRECTORY_SEPARATOR . 'slot-failed';
        $otherSlotCandidate = $this->root . DIRECTORY_SEPARATOR
            . 'slot-other.candidate.' . \str_repeat('7', 16);
        self::assertTrue(\mkdir($otherSlotCandidate, 0700));

        try {
            (new NginxRuntimeArtifact())->install(
                $slot,
                'gateway-nginx',
                [
                    'bin/nginx' => [
                        'contents' => 'binary',
                        'mode' => 0700,
                        'sha256' => \str_repeat('0', 64),
                        'size' => 6,
                    ],
                ],
            );
            self::fail('A digest mismatch must fail publication.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('verified bytes', $exception->getMessage());
        }

        self::assertSame([], \glob($slot . '.candidate.*') ?: []);
        self::assertDirectoryDoesNotExist($slot);
        self::assertDirectoryExists($otherSlotCandidate);
    }

    private function installSmallArtifact(string $slot): void
    {
        (new NginxRuntimeArtifact())->install(
            $slot,
            'gateway-nginx',
            ['bin/nginx' => ['contents' => 'binary', 'mode' => 0700]],
        );
    }

    private function removeTree(string $root): void
    {
        if (\is_link($root) || (\file_exists($root) && !\is_dir($root))) {
            @\unlink($root);
            return;
        }
        if (!\is_dir($root)) {
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
