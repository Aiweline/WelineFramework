<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\TlsTicketRingStore;

final class TlsTicketRingStoreRecoveryTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('The TLS ticket-ring file store is POSIX-only.');
        }
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-ticket-ring-recovery-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testEnsureCollectsBoundedInterruptedTemporaryFiles(): void
    {
        $orphan = $this->root . DIRECTORY_SEPARATOR . '.ring.'
            . \str_repeat('a', 24) . '.tmp';
        self::assertSame(7, \file_put_contents($orphan, 'partial'));
        self::assertTrue(\chmod($orphan, 0600));

        $result = (new TlsTicketRingStore($this->root))->ensure(
            'ai-test-ticket-ring',
            TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
            1_800_000_000,
        );

        self::assertSame(1, $result['epoch']);
        self::assertFileDoesNotExist($orphan);
        self::assertFileExists($this->ringPath('ai-test-ticket-ring'));
    }

    public function testMalformedReservedTemporaryLeafFailsBeforePublication(): void
    {
        $malformed = $this->root . DIRECTORY_SEPARATOR . '.ring.not-bounded.tmp';
        self::assertSame(7, \file_put_contents($malformed, 'partial'));
        self::assertTrue(\chmod($malformed, 0600));

        try {
            (new TlsTicketRingStore($this->root))->ensure(
                'ai-test-ticket-ring-malformed',
                TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
                1_800_000_000,
            );
            self::fail('A malformed reserved ticket-ring leaf must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('reserved', \strtolower($exception->getMessage()));
        }

        self::assertFileExists($malformed);
        self::assertFileDoesNotExist($this->ringPath('ai-test-ticket-ring-malformed'));
    }

    public function testFirstPublicationDoesNotReplaceSymlinkedTarget(): void
    {
        $target = $this->ringPath('ai-test-ticket-ring-link');
        self::assertTrue(\symlink('missing-ticket-ring-target', $target));

        try {
            (new TlsTicketRingStore($this->root))->ensure(
                'ai-test-ticket-ring-link',
                TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
                1_800_000_000,
            );
            self::fail('A linked ticket-ring target must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('regular', \strtolower($exception->getMessage()));
        }

        self::assertTrue(\is_link($target));
    }

    public function testTemporaryQuotaFailsBeforeDeletingAnyRecoveryEvidence(): void
    {
        $orphans = [];
        for ($index = 0; $index < 65; ++$index) {
            $orphan = $this->root . DIRECTORY_SEPARATOR . '.ring.'
                . \str_pad(\dechex($index + 1), 24, '0', STR_PAD_LEFT)
                . '.tmp';
            self::assertSame(0, \file_put_contents($orphan, ''));
            self::assertTrue(\chmod($orphan, 0600));
            $orphans[] = $orphan;
        }

        try {
            (new TlsTicketRingStore($this->root))->ensure(
                'ai-test-ticket-ring-quota',
                TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
                1_800_000_000,
            );
            self::fail('An exhausted ticket-ring temporary quota must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('quota', \strtolower($exception->getMessage()));
        }

        foreach ($orphans as $orphan) {
            self::assertFileExists($orphan);
        }
        self::assertFileDoesNotExist($this->ringPath('ai-test-ticket-ring-quota'));
    }

    public function testRecoveryValidationCompletesBeforeDeletingAnyTemporary(): void
    {
        $valid = $this->temporaryPath('b');
        $unsafe = $this->temporaryPath('c');
        self::assertSame(7, \file_put_contents($valid, 'partial'));
        self::assertTrue(\chmod($valid, 0600));
        self::assertSame(7, \file_put_contents($unsafe, 'partial'));
        self::assertTrue(\chmod($unsafe, 0644));

        try {
            (new TlsTicketRingStore($this->root))->ensure(
                'ai-test-ticket-ring-two-phase',
                TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
                1_800_000_000,
            );
            self::fail('Unsafe recovery state must fail before deleting valid evidence.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('0600', $exception->getMessage());
        }

        self::assertFileExists($valid);
        self::assertFileExists($unsafe);
        self::assertFileDoesNotExist($this->ringPath('ai-test-ticket-ring-two-phase'));
    }

    public function testSymlinkedTemporaryFailsBeforeRecoveryCleanup(): void
    {
        $victim = $this->root . DIRECTORY_SEPARATOR . 'victim';
        $temporary = $this->temporaryPath('d');
        self::assertSame(6, \file_put_contents($victim, 'victim'));
        self::assertTrue(\chmod($victim, 0600));
        self::assertTrue(\symlink($victim, $temporary));

        try {
            (new TlsTicketRingStore($this->root))->ensure(
                'ai-test-ticket-ring-temp-link',
                TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
                1_800_000_000,
            );
            self::fail('A linked recovery temporary must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('regular', \strtolower($exception->getMessage()));
        }

        self::assertTrue(\is_link($temporary));
        self::assertSame('victim', \file_get_contents($victim));
        self::assertFileDoesNotExist($this->ringPath('ai-test-ticket-ring-temp-link'));
    }

    public function testOversizedTemporaryFailsBeforeRecoveryCleanup(): void
    {
        $valid = $this->temporaryPath('e');
        $oversized = $this->temporaryPath('f');
        self::assertSame(7, \file_put_contents($valid, 'partial'));
        self::assertTrue(\chmod($valid, 0600));
        self::assertSame(129, \file_put_contents($oversized, \str_repeat('x', 129)));
        self::assertTrue(\chmod($oversized, 0600));

        try {
            (new TlsTicketRingStore($this->root))->ensure(
                'ai-test-ticket-ring-temp-size',
                TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
                1_800_000_000,
            );
            self::fail('An oversized recovery temporary must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('size', \strtolower($exception->getMessage()));
        }

        self::assertFileExists($valid);
        self::assertFileExists($oversized);
        self::assertFileDoesNotExist($this->ringPath('ai-test-ticket-ring-temp-size'));
    }

    public function testRawEntryQuotaFailsBeforeDeletingRecoveryEvidence(): void
    {
        $temporary = $this->temporaryPath('1');
        self::assertSame(7, \file_put_contents($temporary, 'partial'));
        self::assertTrue(\chmod($temporary, 0600));
        for ($index = 0; $index < 1025; ++$index) {
            $foreign = $this->root . DIRECTORY_SEPARATOR . 'foreign-'
                . \str_pad((string)$index, 4, '0', STR_PAD_LEFT);
            self::assertSame(0, \file_put_contents($foreign, ''));
        }

        try {
            (new TlsTicketRingStore($this->root))->ensure(
                'ai-test-ticket-ring-raw-quota',
                TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
                1_800_000_000,
            );
            self::fail('The raw directory entry quota must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('raw entry quota', \strtolower($exception->getMessage()));
        }

        self::assertFileExists($temporary);
        self::assertFileDoesNotExist($this->ringPath('ai-test-ticket-ring-raw-quota'));
    }

    public function testFirstPublicationCannotOverflowTheRawEntryQuota(): void
    {
        for ($index = 0; $index < 1023; ++$index) {
            $foreign = $this->root . DIRECTORY_SEPARATOR . 'admission-'
                . \str_pad((string)$index, 4, '0', STR_PAD_LEFT);
            self::assertSame(0, \file_put_contents($foreign, ''));
        }

        try {
            (new TlsTicketRingStore($this->root))->ensure(
                'ai-test-ticket-ring-raw-admission',
                TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
                1_800_000_000,
            );
            self::fail('First publication must not overflow the raw entry quota.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('raw entry quota', \strtolower($exception->getMessage()));
        }

        self::assertFileDoesNotExist($this->ringPath('ai-test-ticket-ring-raw-admission'));
    }

    public function testRecoveryCanFreeCapacityBeforeFirstPublication(): void
    {
        $temporary = $this->temporaryPath('3');
        self::assertSame(7, \file_put_contents($temporary, 'partial'));
        self::assertTrue(\chmod($temporary, 0600));
        for ($index = 0; $index < 1022; ++$index) {
            $foreign = $this->root . DIRECTORY_SEPARATOR . 'recovery-admission-'
                . \str_pad((string)$index, 4, '0', STR_PAD_LEFT);
            self::assertSame(0, \file_put_contents($foreign, ''));
        }

        $result = (new TlsTicketRingStore($this->root))->ensure(
            'ai-test-ticket-ring-recovery-admission',
            TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
            1_800_000_000,
        );

        self::assertSame(1, $result['epoch']);
        self::assertTrue($result['rotated']);
        self::assertFileDoesNotExist($temporary);
        self::assertFileExists($this->ringPath('ai-test-ticket-ring-recovery-admission'));
    }

    public function testRotationCannotTemporarilyOverflowTheRawEntryQuota(): void
    {
        $store = new TlsTicketRingStore($this->root);
        $first = $store->ensure(
            'ai-test-ticket-ring-rotation-admission',
            TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
            1_800_000_000,
        );
        $ring = $this->ringPath('ai-test-ticket-ring-rotation-admission');
        $contents = \file_get_contents($ring);
        self::assertIsString($contents);
        for ($index = 0; $index < 1022; ++$index) {
            $foreign = $this->root . DIRECTORY_SEPARATOR . 'rotation-admission-'
                . \str_pad((string)$index, 4, '0', STR_PAD_LEFT);
            self::assertSame(0, \file_put_contents($foreign, ''));
        }

        try {
            $store->ensure(
                'ai-test-ticket-ring-rotation-admission',
                TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
                1_800_000_000 + TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
            );
            self::fail('Rotation must not temporarily overflow the raw entry quota.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('raw entry quota', \strtolower($exception->getMessage()));
        }

        self::assertSame($contents, \file_get_contents($ring));
        $snapshot = $store->loadSecretSnapshot('ai-test-ticket-ring-rotation-admission');
        try {
            self::assertSame($first['epoch'], $snapshot['epoch']);
        } finally {
            TlsTicketRingStore::wipeSnapshot($snapshot);
        }
    }

    public function testRingQuotaFailsBeforePublication(): void
    {
        for ($index = 0; $index < 257; ++$index) {
            $ring = $this->root . DIRECTORY_SEPARATOR
                . \str_pad(\dechex($index + 1), 64, '0', STR_PAD_LEFT)
                . '.ring';
            self::assertSame(0, \file_put_contents($ring, ''));
            self::assertTrue(\chmod($ring, 0600));
        }

        try {
            (new TlsTicketRingStore($this->root))->ensure(
                'ai-test-ticket-ring-ring-quota',
                TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
                1_800_000_000,
            );
            self::fail('The committed ring quota must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('ring quota', \strtolower($exception->getMessage()));
        }

        self::assertFileDoesNotExist($this->ringPath('ai-test-ticket-ring-ring-quota'));
    }

    public function testMalformedReservedRingLeafFailsBeforePublication(): void
    {
        $malformed = $this->root . DIRECTORY_SEPARATOR . 'not-a-digest.ring';
        self::assertSame(0, \file_put_contents($malformed, ''));
        self::assertTrue(\chmod($malformed, 0600));

        try {
            (new TlsTicketRingStore($this->root))->ensure(
                'ai-test-ticket-ring-malformed-ring',
                TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
                1_800_000_000,
            );
            self::fail('A malformed reserved committed-ring leaf must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('reserved', \strtolower($exception->getMessage()));
        }

        self::assertFileExists($malformed);
        self::assertFileDoesNotExist($this->ringPath('ai-test-ticket-ring-malformed-ring'));
    }

    public function testHardLinkedStoreLockFailsBeforePublication(): void
    {
        $lock = $this->root . DIRECTORY_SEPARATOR . '.store.lock';
        $alias = $this->root . DIRECTORY_SEPARATOR . 'lock-alias';
        self::assertSame(0, \file_put_contents($lock, ''));
        self::assertTrue(\chmod($lock, 0600));
        self::assertTrue(\link($lock, $alias));

        try {
            (new TlsTicketRingStore($this->root))->ensure(
                'ai-test-ticket-ring-lock-link',
                TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
                1_800_000_000,
            );
            self::fail('A hard-linked store lock must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('single-link', \strtolower($exception->getMessage()));
        }

        self::assertFileDoesNotExist($this->ringPath('ai-test-ticket-ring-lock-link'));
    }

    public function testContendedStoreLockFailsWithinItsMonotonicBudget(): void
    {
        if (!\function_exists('pcntl_fork')
            || !\function_exists('pcntl_waitpid')
            || !\function_exists('posix_kill')
        ) {
            self::markTestSkipped('POSIX process controls are required for the lock-timeout fixture.');
        }
        $lockPath = $this->root . DIRECTORY_SEPARATOR . '.store.lock';
        $lock = \fopen($lockPath, 'x+b');
        self::assertIsResource($lock);
        self::assertTrue(\chmod($lockPath, 0600));
        self::assertTrue(\flock($lock, LOCK_EX));
        $resultPath = $this->root . DIRECTORY_SEPARATOR . 'lock-timeout-result';

        $pid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            \fclose($lock);
            try {
                (new TlsTicketRingStore($this->root, 0.05))->ensure(
                    'ai-test-ticket-ring-lock-timeout',
                    TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
                    1_800_000_000,
                );
                exit(2);
            } catch (\RuntimeException $exception) {
                \file_put_contents($resultPath, $exception->getMessage());
                exit(0);
            }
        }

        $status = 0;
        $finished = false;
        $deadline = (\hrtime(true) / 1_000_000_000) + 0.75;
        do {
            $waited = \pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited === $pid) {
                $finished = true;
                break;
            }
            \usleep(10_000);
        } while ((\hrtime(true) / 1_000_000_000) < $deadline);

        if (!$finished) {
            \posix_kill($pid, SIGKILL);
            \pcntl_waitpid($pid, $status);
        }
        \flock($lock, LOCK_UN);
        \fclose($lock);

        self::assertTrue($finished, 'Ticket-ring lock acquisition exceeded its fixed budget.');
        self::assertTrue(\pcntl_wifexited($status));
        self::assertSame(0, \pcntl_wexitstatus($status));
        $message = \file_get_contents($resultPath);
        self::assertIsString($message);
        self::assertStringContainsString('timed out', \strtolower($message));
        self::assertFileDoesNotExist($this->ringPath('ai-test-ticket-ring-lock-timeout'));
    }

    public function testHardLinkedCommittedRingFailsClosed(): void
    {
        $store = new TlsTicketRingStore($this->root);
        $store->ensure(
            'ai-test-ticket-ring-committed-link',
            TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
            1_800_000_000,
        );
        $ring = $this->ringPath('ai-test-ticket-ring-committed-link');
        $alias = $this->root . DIRECTORY_SEPARATOR . 'ring-alias';
        self::assertTrue(\link($ring, $alias));

        try {
            $store->ensure(
                'ai-test-ticket-ring-committed-link',
                TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
                1_800_000_001,
            );
            self::fail('A hard-linked committed ring must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('single-link', \strtolower($exception->getMessage()));
        }

        self::assertFileExists($ring);
        self::assertFileExists($alias);
    }

    public function testRecoveryCleanupNeverDeletesCommittedRing(): void
    {
        $store = new TlsTicketRingStore($this->root);
        $first = $store->ensure(
            'ai-test-ticket-ring-retain',
            TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
            1_800_000_000,
        );
        $ring = $this->ringPath('ai-test-ticket-ring-retain');
        $before = \lstat($ring);
        self::assertIsArray($before);
        $contents = \file_get_contents($ring);
        self::assertIsString($contents);
        $temporary = $this->temporaryPath('2');
        self::assertSame(7, \file_put_contents($temporary, 'partial'));
        self::assertTrue(\chmod($temporary, 0600));

        $again = $store->ensure(
            'ai-test-ticket-ring-retain',
            TlsTicketRingStore::DEFAULT_ROTATION_SECONDS,
            1_800_000_001,
        );
        $after = \lstat($ring);
        self::assertIsArray($after);

        self::assertSame($first['epoch'], $again['epoch']);
        self::assertFalse($again['rotated']);
        self::assertSame((int)$before['dev'], (int)$after['dev']);
        self::assertSame((int)$before['ino'], (int)$after['ino']);
        self::assertSame($contents, \file_get_contents($ring));
        self::assertFileDoesNotExist($temporary);
    }

    public function testSecretLoadDoesNotCreateAWriterStore(): void
    {
        $missing = $this->root . DIRECTORY_SEPARATOR . 'missing-store';

        try {
            (new TlsTicketRingStore($missing))->loadSecretSnapshot(
                'ai-test-ticket-ring-read-only',
            );
            self::fail('A read-only ticket-ring load must not bootstrap store state.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('directory', \strtolower($exception->getMessage()));
        }

        self::assertDirectoryDoesNotExist($missing);
    }

    private function ringPath(string $instanceName): string
    {
        return $this->root . DIRECTORY_SEPARATOR
            . \hash('sha256', $instanceName) . '.ring';
    }

    private function temporaryPath(string $hexDigit): string
    {
        return $this->root . DIRECTORY_SEPARATOR . '.ring.'
            . \str_repeat($hexDigit, 24) . '.tmp';
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
