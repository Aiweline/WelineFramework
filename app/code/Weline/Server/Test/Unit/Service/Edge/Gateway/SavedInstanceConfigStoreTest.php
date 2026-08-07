<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\SavedInstanceConfigStore;

final class SavedInstanceConfigStoreTest extends TestCase
{
    public function testLoadCollectsAValidatedSavedConfigBackupUnderTheInstanceLock(): void
    {
        $temporaryRoot = \realpath(\sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $directory = $temporaryRoot . DIRECTORY_SEPARATOR . 'wls-config-recovery-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($directory, 0700, true));
        try {
            $store = new SavedInstanceConfigStore($directory);
            $store->update('default', static fn (array $config): array => [[
                'edge_mode' => 'auto',
            ], null]);
            $file = $store->file('default');
            $backup = $file . '.wls-backup-' . \str_repeat('a', 16);
            self::assertTrue(\copy($file, $backup));

            self::assertSame(['edge_mode' => 'auto'], $store->load('default'));
            self::assertFileDoesNotExist($backup);
        } finally {
            foreach ((array)\glob($directory . DIRECTORY_SEPARATOR . '*') as $file) {
                @\unlink($file);
            }
            @\rmdir($directory);
        }
    }

    public function testLoadDoesNotTreatABackupWithoutItsPairedConfigAsNeverSaved(): void
    {
        $temporaryRoot = \realpath(\sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $directory = $temporaryRoot . DIRECTORY_SEPARATOR
            . 'wls-config-recovery-missing-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($directory, 0700, true));
        try {
            $store = new SavedInstanceConfigStore($directory);
            $store->update('default', static fn (array $config): array => [[
                'edge_mode' => 'auto',
            ], null]);
            $file = $store->file('default');
            $backup = $file . '.wls-backup-' . \str_repeat('b', 16);
            self::assertTrue(\rename($file, $backup));
            self::assertTrue(@\unlink($file . '.lock'));

            try {
                $store->load('default');
                self::fail('Saved configuration recovery evidence must not look unsaved.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'paired target',
                    \strtolower($exception->getMessage()),
                );
            }
            self::assertFileExists($backup);
            self::assertFileDoesNotExist($file);
        } finally {
            foreach ((array)\glob($directory . DIRECTORY_SEPARATOR . '*') as $file) {
                @\unlink($file);
            }
            @\rmdir($directory);
        }
    }

    public function testOwnedFieldRollbackUsesAfterImageCas(): void
    {
        $temporaryRoot = \realpath(\sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $directory = $temporaryRoot . DIRECTORY_SEPARATOR . 'wls-config-cas-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($directory, 0700, true));
        try {
            $store = new SavedInstanceConfigStore($directory);
            $store->update('default', static fn (array $config): array => [[
                'edge_mode' => 'legacy',
                'saved_at' => 'before',
                'unrelated' => 'keep',
            ], null]);
            $before = [
                'edge_mode' => ['exists' => true, 'value' => 'legacy'],
                'saved_at' => ['exists' => true, 'value' => 'before'],
            ];
            $after = [
                'edge_mode' => ['exists' => true, 'value' => 'auto'],
                'saved_at' => ['exists' => true, 'value' => 'promotion'],
            ];
            $store->update('default', static function (array $config): array {
                $config['edge_mode'] = 'auto';
                $config['saved_at'] = 'concurrent';
                return [$config, null];
            });
            $result = $store->restoreOwnedFields('default', $before, $after);

            self::assertSame(['edge_mode'], $result['restored']);
            self::assertSame(['saved_at'], $result['conflicts']);
            $saved = \json_decode((string)\file_get_contents(
                $store->file('default'),
            ), true, 64, JSON_THROW_ON_ERROR);
            self::assertSame('legacy', $saved['edge_mode']);
            self::assertSame('concurrent', $saved['saved_at']);
            self::assertSame('keep', $saved['unrelated']);
        } finally {
            foreach ((array)\glob($directory . DIRECTORY_SEPARATOR . '*') as $file) {
                @\unlink($file);
            }
            @\unlink($directory . DIRECTORY_SEPARATOR . 'default.json.lock');
            @\rmdir($directory);
        }
    }
}
