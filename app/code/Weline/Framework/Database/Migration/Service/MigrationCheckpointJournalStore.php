<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Migration\Service;

use Weline\Framework\Database\Migration\MigrationManifest;

/**
 * Checkpoint + journal 持久化存储（文件 journal；跨进程 / fresh-connection verify）。
 * DB Model `MigrationCheckpoint` 仍 additive，供后续 MIG apply 同库落表；本 Store 不依赖 setup:upgrade。
 */
final class MigrationCheckpointJournalStore
{
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $resolved = $directory ?? self::defaultDirectory();
        if ($resolved === '') {
            throw new \InvalidArgumentException('migration_journal_store_dir_empty');
        }
        if (!\is_dir($resolved) && !@\mkdir($resolved, 0775, true) && !\is_dir($resolved)) {
            throw new \RuntimeException('migration_journal_store_mkdir_failed:' . $resolved);
        }
        $this->directory = $resolved;
    }

    public static function defaultDirectory(): string
    {
        $bp = \defined('BP') ? BP : \dirname(__DIR__, 7);

        return $bp . '/var/mig/checkpoints';
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * @param list<array{at:string,event:string,detail:array<string,mixed>}> $journal
     */
    public function save(MigrationManifest $manifest, string $hash, array $journal): void
    {
        $payload = [
            'checkpoint_id' => $manifest->checkpointId,
            'manifest_hash' => $hash,
            'connector_fingerprint' => $manifest->connectorFingerprint,
            'phase' => $manifest->phase,
            'manifest' => $manifest->toArray(),
            'journal' => $journal,
            'updated_at' => \gmdate('c'),
        ];
        $path = $this->pathFor($manifest->checkpointId);
        $tmp = $path . '.tmp.' . \bin2hex(\random_bytes(4));
        $json = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        if (@\file_put_contents($tmp, $json . "\n") === false) {
            throw new \RuntimeException('migration_journal_store_write_failed:' . $tmp);
        }
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);
            throw new \RuntimeException('migration_journal_store_rename_failed:' . $path);
        }
    }

    /**
     * @return null|array{
     *   checkpoint_id: string,
     *   manifest_hash: string,
     *   connector_fingerprint: string,
     *   phase: string,
     *   manifest: array<string, mixed>,
     *   journal: list<array{at:string,event:string,detail:array<string,mixed>}>,
     *   updated_at: string
     * }
     */
    public function load(string $checkpointId): ?array
    {
        $path = $this->pathFor($checkpointId);
        if (!\is_file($path)) {
            return null;
        }
        $raw = \file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        try {
            $data = \json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($data) || !isset($data['manifest'], $data['manifest_hash'], $data['journal'])) {
            return null;
        }

        return [
            'checkpoint_id' => (string) ($data['checkpoint_id'] ?? $checkpointId),
            'manifest_hash' => (string) $data['manifest_hash'],
            'connector_fingerprint' => (string) ($data['connector_fingerprint'] ?? ''),
            'phase' => (string) ($data['phase'] ?? ''),
            'manifest' => (array) $data['manifest'],
            'journal' => \is_array($data['journal']) ? $data['journal'] : [],
            'updated_at' => (string) ($data['updated_at'] ?? ''),
        ];
    }

    public function forget(string $checkpointId): void
    {
        $path = $this->pathFor($checkpointId);
        if (\is_file($path)) {
            @\unlink($path);
        }
    }

    /**
     * @return list<string>
     */
    public function listIds(): array
    {
        $ids = [];
        foreach (\glob($this->directory . '/*.json') ?: [] as $file) {
            $base = \basename($file, '.json');
            if ($base !== '') {
                $ids[] = $base;
            }
        }
        \sort($ids);

        return $ids;
    }

    private function pathFor(string $checkpointId): string
    {
        $safe = \preg_replace('/[^a-zA-Z0-9._-]/', '_', $checkpointId) ?: 'invalid';

        return $this->directory . '/' . $safe . '.json';
    }
}
