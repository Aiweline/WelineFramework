<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Migration\Service;

use Weline\Framework\Database\Migration\MigrationManifest;

/**
 * Phase-qualified checkpoint + 单调 journal。
 * 默认挂载文件 Store，支持跨进程 fresh-connection verify；内存态仍可用于纯单测。
 *
 * apply 前必须：assertIsolatedDatabase → checkpoint(manifest) → journal append。
 * 篡改 manifest hash 与已存 checkpoint 不一致则零写拒绝。
 */
final class MigrationCheckpointService
{
    /** @var array<string, array{manifest:MigrationManifest,hash:string,journal:list<array{at:string,event:string,detail:array<string,mixed>}>}> */
    private array $checkpoints = [];

    public function __construct(
        private readonly DatabaseFingerprintGuard $fingerprintGuard,
        private readonly ?MigrationCheckpointJournalStore $store = null,
    ) {
    }

    public static function withDefaultStore(?DatabaseFingerprintGuard $guard = null): self
    {
        return new self(
            $guard ?? new DatabaseFingerprintGuard(),
            new MigrationCheckpointJournalStore(),
        );
    }

    public function store(): ?MigrationCheckpointJournalStore
    {
        return $this->store;
    }

    /**
     * @param array{hostname?:string,hostport?:int|string,database?:string,username?:string,type?:string} $config
     * @return array{ok:bool,fingerprint:?string,error:?string}
     */
    public function preflight(array $config): array
    {
        try {
            $fp = $this->fingerprintGuard->assertIsolatedDatabase($config);

            return ['ok' => true, 'fingerprint' => $fp, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'fingerprint' => null, 'error' => $e->getMessage()];
        }
    }

    public function fingerprint(array $config): string
    {
        return $this->fingerprintGuard->fingerprint($config);
    }

    public function assertIsolatedDatabase(array $config): string
    {
        return $this->fingerprintGuard->assertIsolatedDatabase($config);
    }

    public function checkpoint(MigrationManifest $manifest): string
    {
        $hash = $manifest->hash();
        $id = $manifest->checkpointId;
        $journal = [[
            'at' => \gmdate('c'),
            'event' => 'checkpoint',
            'detail' => ['manifest_hash' => $hash, 'phase' => $manifest->phase],
        ]];
        $this->checkpoints[$id] = [
            'manifest' => $manifest,
            'hash' => $hash,
            'journal' => $journal,
        ];
        $this->persist($id);

        return $hash;
    }

    /**
     * @param array<string, mixed> $detail
     */
    public function appendJournal(string $checkpointId, string $event, array $detail = []): void
    {
        $this->ensureLoaded($checkpointId);
        if (!isset($this->checkpoints[$checkpointId])) {
            throw new \RuntimeException('migration_checkpoint_missing:' . $checkpointId);
        }
        $this->checkpoints[$checkpointId]['journal'][] = [
            'at' => \gmdate('c'),
            'event' => $event,
            'detail' => $detail,
        ];
        $this->persist($checkpointId);
    }

    public function assertManifestUntampered(string $checkpointId, MigrationManifest $candidate): void
    {
        $this->ensureLoaded($checkpointId);
        if (!isset($this->checkpoints[$checkpointId])) {
            throw new \RuntimeException('migration_checkpoint_missing:' . $checkpointId);
        }
        $expected = $this->checkpoints[$checkpointId]['hash'];
        $actual = $candidate->hash();
        if (!\hash_equals($expected, $actual)) {
            throw new \RuntimeException('migration_manifest_tampered: zero-write reject');
        }
    }

    /**
     * @return list<array{at:string,event:string,detail:array<string,mixed>}>
     */
    public function journal(string $checkpointId): array
    {
        $this->ensureLoaded($checkpointId);

        return $this->checkpoints[$checkpointId]['journal'] ?? [];
    }

    public function hasCheckpoint(string $checkpointId): bool
    {
        $this->ensureLoaded($checkpointId);

        return isset($this->checkpoints[$checkpointId]);
    }

    public function manifestHash(string $checkpointId): ?string
    {
        $this->ensureLoaded($checkpointId);

        return $this->checkpoints[$checkpointId]['hash'] ?? null;
    }

    public function rollbackGuard(string $checkpointId): void
    {
        $this->ensureLoaded($checkpointId);
        if (!isset($this->checkpoints[$checkpointId])) {
            throw new \RuntimeException('migration_checkpoint_missing:' . $checkpointId);
        }
        $this->appendJournal($checkpointId, 'rollback_guard', [
            'policy' => 'continue-forward-or-safe-reader-only',
            'delete_new_facts' => false,
        ]);
    }

    /**
     * 模拟 apply：必须先隔离库 + 未篡改 manifest；本服务本身不写业务表。
     *
     * @param array{hostname?:string,hostport?:int|string,database?:string,username?:string,type?:string} $config
     */
    public function applyGuard(array $config, string $checkpointId, MigrationManifest $manifest): void
    {
        $this->assertIsolatedDatabase($config);
        $this->assertManifestUntampered($checkpointId, $manifest);
        $this->appendJournal($checkpointId, 'apply_guard_passed', [
            'manifest_hash' => $manifest->hash(),
        ]);
    }

    /**
     * Fresh-connection verify：新 Store 句柄重载，比对 hash/journal 长度与末事件。
     *
     * @return array{ok:bool,checkpoint_id:string,manifest_hash:?string,journal_count:int,last_event:?string,error:?string}
     */
    public function verifyFresh(string $checkpointId): array
    {
        if ($this->store === null) {
            return [
                'ok' => false,
                'checkpoint_id' => $checkpointId,
                'manifest_hash' => null,
                'journal_count' => 0,
                'last_event' => null,
                'error' => 'migration_journal_store_required',
            ];
        }

        $fresh = new self($this->fingerprintGuard, new MigrationCheckpointJournalStore($this->store->directory()));
        if (!$fresh->hasCheckpoint($checkpointId)) {
            return [
                'ok' => false,
                'checkpoint_id' => $checkpointId,
                'manifest_hash' => null,
                'journal_count' => 0,
                'last_event' => null,
                'error' => 'migration_checkpoint_missing:' . $checkpointId,
            ];
        }

        $journal = $fresh->journal($checkpointId);
        $hash = $fresh->manifestHash($checkpointId);
        $last = $journal === [] ? null : (string) ($journal[\count($journal) - 1]['event'] ?? null);

        // Re-hash stored manifest to ensure integrity.
        $row = $this->store->load($checkpointId);
        if ($row === null) {
            return [
                'ok' => false,
                'checkpoint_id' => $checkpointId,
                'manifest_hash' => $hash,
                'journal_count' => \count($journal),
                'last_event' => $last,
                'error' => 'migration_journal_store_load_failed',
            ];
        }
        $recomputed = MigrationManifest::fromArray($row['manifest'])->hash();
        if (!\hash_equals((string) $hash, $recomputed) || !\hash_equals((string) $hash, $row['manifest_hash'])) {
            return [
                'ok' => false,
                'checkpoint_id' => $checkpointId,
                'manifest_hash' => $hash,
                'journal_count' => \count($journal),
                'last_event' => $last,
                'error' => 'migration_manifest_tampered',
            ];
        }

        return [
            'ok' => true,
            'checkpoint_id' => $checkpointId,
            'manifest_hash' => $hash,
            'journal_count' => \count($journal),
            'last_event' => $last,
            'error' => null,
        ];
    }

    private function ensureLoaded(string $checkpointId): void
    {
        if (isset($this->checkpoints[$checkpointId]) || $this->store === null) {
            return;
        }
        $row = $this->store->load($checkpointId);
        if ($row === null) {
            return;
        }
        $manifest = MigrationManifest::fromArray($row['manifest']);
        $this->checkpoints[$checkpointId] = [
            'manifest' => $manifest,
            'hash' => $row['manifest_hash'],
            'journal' => $row['journal'],
        ];
    }

    private function persist(string $checkpointId): void
    {
        if ($this->store === null || !isset($this->checkpoints[$checkpointId])) {
            return;
        }
        $row = $this->checkpoints[$checkpointId];
        $this->store->save($row['manifest'], $row['hash'], $row['journal']);
    }
}
