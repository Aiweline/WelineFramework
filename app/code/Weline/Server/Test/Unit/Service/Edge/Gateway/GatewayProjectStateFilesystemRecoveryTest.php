<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

final class GatewayProjectStateFilesystemRecoveryTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'wls-state-recovery-' . \bin2hex(\random_bytes(8));
        self::assertTrue(@\mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        $entries = @\scandir($this->directory);
        if (\is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @\unlink($this->directory . DIRECTORY_SEPARATOR . $entry);
                }
            }
        }
        @\rmdir($this->directory);
    }

    public function testCleanupRequiresAStableStrictlyValidatedPairedTarget(): void
    {
        $target = $this->directory . DIRECTORY_SEPARATOR . 'state.json';
        $backup = $target . '.wls-backup-' . \str_repeat('a', 16);
        self::assertSame(15, @\file_put_contents($target, "{\"valid\":true}\n"));
        self::assertSame(16, @\file_put_contents($backup, "{\"valid\":false}\n"));

        GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
            $target,
            1024,
            'test state',
            static function (string $contents): void {
                $decoded = \json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
                if (!\is_array($decoded) || ($decoded['valid'] ?? null) !== true) {
                    throw new \RuntimeException('test state is invalid');
                }
            },
        );

        self::assertFileExists($target);
        self::assertFileDoesNotExist($backup);
    }

    public function testMissingOrInvalidPairedTargetRetainsEveryRecoveryBackup(): void
    {
        foreach (['missing', 'invalid'] as $case) {
            $target = $this->directory . DIRECTORY_SEPARATOR . $case . '.json';
            $backup = $target . '.wls-backup-' . \str_repeat(
                $case === 'missing' ? 'b' : 'c',
                16,
            );
            self::assertSame(9, @\file_put_contents($backup, '{"old":1}'));
            if ($case === 'invalid') {
                self::assertSame(9, @\file_put_contents($target, '{"bad":1}'));
            }

            try {
                GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                    $target,
                    1024,
                    'test state',
                    static function (string $contents): void {
                        $decoded = \json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
                        if (!\is_array($decoded) || ($decoded['valid'] ?? null) !== true) {
                            throw new \RuntimeException('test state is invalid');
                        }
                    },
                );
                self::fail('Recovery evidence requires a valid paired target.');
            } catch (\RuntimeException) {
                self::assertFileExists($backup);
            }
        }
    }

    public function testTargetMutationDuringValidationRetainsRecoveryEvidence(): void
    {
        $target = $this->directory . DIRECTORY_SEPARATOR . 'changing.json';
        $backup = $target . '.wls-backup-' . \str_repeat('d', 16);
        self::assertSame(15, @\file_put_contents($target, "{\"valid\":true}\n"));
        self::assertSame(9, @\file_put_contents($backup, '{"old":1}'));

        try {
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $target,
                1024,
                'test state',
                static function (string $contents) use ($target): void {
                    unset($contents);
                    if (@\file_put_contents($target, '{"changed":true}') === false) {
                        throw new \RuntimeException('unable to mutate test target');
                    }
                },
            );
            self::fail('A target identity change must abort recovery cleanup.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('changed', \strtolower($exception->getMessage()));
        }
        self::assertFileExists($backup);
    }

    public function testMalformedReservedLeafAndQuotaExhaustionFailWithoutDeletion(): void
    {
        $target = $this->directory . DIRECTORY_SEPARATOR . 'bounded.json';
        self::assertSame(15, @\file_put_contents($target, "{\"valid\":true}\n"));
        $backups = [];
        for ($index = 0; $index < 9; ++$index) {
            $backup = $target . '.wls-backup-' . \str_pad(
                \dechex($index),
                16,
                '0',
                STR_PAD_LEFT,
            );
            self::assertSame(9, @\file_put_contents($backup, '{"old":1}'));
            $backups[] = $backup;
        }

        try {
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $target,
                1024,
                'test state',
                static fn (string $contents): null => null,
            );
            self::fail('The fixed per-target recovery quota must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('quota', \strtolower($exception->getMessage()));
        }
        foreach ($backups as $backup) {
            self::assertFileExists($backup);
        }

        $malformedTarget = $this->directory . DIRECTORY_SEPARATOR . 'malformed.json';
        $validBackup = $malformedTarget . '.wls-backup-' . \str_repeat('f', 16);
        $malformedBackup = $malformedTarget . '.wls-backup-not-a-native-suffix';
        self::assertSame(15, @\file_put_contents($malformedTarget, "{\"valid\":true}\n"));
        self::assertSame(9, @\file_put_contents($validBackup, '{"old":1}'));
        self::assertSame(9, @\file_put_contents($malformedBackup, '{"old":2}'));

        try {
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $malformedTarget,
                1024,
                'test state',
                static fn (string $contents): null => null,
            );
            self::fail('A malformed reserved leaf must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('malformed', \strtolower($exception->getMessage()));
        }
        self::assertFileExists($validBackup);
        self::assertFileExists($malformedBackup);
    }

    public function testCleanupCollectsExactInterruptedStagingOnlyAfterTargetValidation(): void
    {
        $target = $this->directory . DIRECTORY_SEPARATOR . 'staged.json';
        $staging = $target . '.tmp-' . \str_repeat('1', 24);
        self::assertSame(15, @\file_put_contents($target, "{\"valid\":true}\n"));
        self::assertSame(16, @\file_put_contents($staging, "{\"valid\":false}\n"));

        self::assertTrue(GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
            $target,
            1024,
            'test state',
        ));
        GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
            $target,
            1024,
            'test state',
            static function (string $contents): void {
                $decoded = \json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
                if (!\is_array($decoded) || ($decoded['valid'] ?? null) !== true) {
                    throw new \RuntimeException('test state is invalid');
                }
            },
        );

        self::assertFileExists($target);
        self::assertFileDoesNotExist($staging);
    }

    public function testMissingTargetRetainsInterruptedStagingAfterImage(): void
    {
        $target = $this->directory . DIRECTORY_SEPARATOR . 'missing-staged.json';
        $staging = $target . '.tmp-' . \str_repeat('2', 24);
        self::assertSame(14, @\file_put_contents($staging, "{\"valid\":true}"));

        try {
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $target,
                1024,
                'test state',
                static fn (string $contents): null => null,
            );
            self::fail('A first-publication staging after-image requires a paired target.');
        } catch (\RuntimeException) {
            self::assertFileExists($staging);
        }
    }

    public function testUnsafeStagingAndCaseAliasAbortCompleteRecoverySetBeforeDeletion(): void
    {
        foreach (['unsafe', 'case-alias'] as $case) {
            $target = $this->directory . DIRECTORY_SEPARATOR . $case . '.json';
            $backup = $target . '.wls-backup-' . \str_repeat('3', 16);
            $staging = $target . '.tmp-' . \str_repeat('4', 24);
            self::assertSame(15, @\file_put_contents($target, "{\"valid\":true}\n"));
            self::assertSame(9, @\file_put_contents($backup, '{"old":1}'));
            if ($case === 'unsafe') {
                self::assertTrue(@\symlink($target, $staging));
            } else {
                self::assertSame(
                    9,
                    @\file_put_contents(
                        $target . '.TMP-' . \str_repeat('5', 24),
                        '{"old":2}',
                    ),
                );
            }

            try {
                GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                    $target,
                    1024,
                    'test state',
                    static fn (string $contents): null => null,
                );
                self::fail('An unsafe reserved artifact must abort the complete cleanup pass.');
            } catch (\RuntimeException) {
                self::assertFileExists(
                    $backup,
                    'No valid recovery artifact may be deleted before full preflight succeeds.',
                );
                self::assertTrue(\file_exists($staging) || \is_link($staging) || $case === 'case-alias');
            }
        }
    }

    public function testInterruptedStagingQuotaFailsClosedWithoutDeletingEarlierArtifacts(): void
    {
        $target = $this->directory . DIRECTORY_SEPARATOR . 'staging-quota.json';
        self::assertSame(15, @\file_put_contents($target, "{\"valid\":true}\n"));
        $staging = [];
        for ($index = 0; $index < 9; ++$index) {
            $path = $target . '.tmp-' . \str_pad(\dechex($index), 24, '0', STR_PAD_LEFT);
            self::assertSame(9, @\file_put_contents($path, '{"old":1}'));
            $staging[] = $path;
        }

        try {
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $target,
                1024,
                'test state',
                static fn (string $contents): null => null,
            );
            self::fail('The fixed per-target staging quota must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('quota', \strtolower($exception->getMessage()));
        }
        foreach ($staging as $path) {
            self::assertFileExists($path);
        }
    }

    public function testAtomicWriteRefusesToLayerOverUnresolvedCrashEvidence(): void
    {
        $target = $this->directory . DIRECTORY_SEPARATOR . 'unresolved.json';
        $staging = $target . '.tmp-' . \str_repeat('6', 24);
        self::assertSame(9, @\file_put_contents($target, '{"old":1}'));
        self::assertSame(9, @\file_put_contents($staging, '{"new":1}'));

        try {
            GatewayProjectStateFilesystem::atomicWrite($target, '{"next":1}', 0600);
            self::fail('A new write must not hide unresolved atomic recovery evidence.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'unresolved atomic recovery artifacts',
                $exception->getMessage(),
            );
        }

        self::assertSame('{"old":1}', \file_get_contents($target));
        self::assertSame('{"new":1}', \file_get_contents($staging));
    }
}
