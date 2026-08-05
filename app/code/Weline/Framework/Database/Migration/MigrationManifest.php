<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Migration;

/**
 * 不可变迁移 manifest（DEC-026）。
 *
 * 构造后禁止改写；任何字段变化必须产生新实例与新 hash。
 */
final class MigrationManifest
{
    /**
     * @param array<string, scalar|null> $schemaFingerprints table => hash
     * @param array<string, int> $rowCounts table => count
     * @param array<string, string> $rowHashes table => hash
     * @param array<string, scalar|null> $watermarks
     */
    public function __construct(
        public readonly string $checkpointId,
        public readonly string $phase,
        public readonly string $repo,
        public readonly string $branch,
        public readonly string $commit,
        public readonly string $connectorFingerprint,
        public readonly array $schemaFingerprints,
        public readonly array $rowCounts,
        public readonly array $rowHashes,
        public readonly array $watermarks,
        public readonly string $backupRef,
        public readonly string $createdAt,
    ) {
        if ($checkpointId === '' || $phase === '' || $connectorFingerprint === '') {
            throw new \InvalidArgumentException('migration_manifest_incomplete');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            checkpointId: (string)($data['checkpoint_id'] ?? ''),
            phase: (string)($data['phase'] ?? ''),
            repo: (string)($data['repo'] ?? ''),
            branch: (string)($data['branch'] ?? ''),
            commit: (string)($data['commit'] ?? ''),
            connectorFingerprint: (string)($data['connector_fingerprint'] ?? ''),
            schemaFingerprints: self::stringMap($data['schema_fingerprints'] ?? []),
            rowCounts: self::intMap($data['row_counts'] ?? []),
            rowHashes: self::stringMap($data['row_hashes'] ?? []),
            watermarks: self::scalarMap($data['watermarks'] ?? []),
            backupRef: (string)($data['backup_ref'] ?? ''),
            createdAt: (string)($data['created_at'] ?? \gmdate('c')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'checkpoint_id' => $this->checkpointId,
            'phase' => $this->phase,
            'repo' => $this->repo,
            'branch' => $this->branch,
            'commit' => $this->commit,
            'connector_fingerprint' => $this->connectorFingerprint,
            'schema_fingerprints' => $this->schemaFingerprints,
            'row_counts' => $this->rowCounts,
            'row_hashes' => $this->rowHashes,
            'watermarks' => $this->watermarks,
            'backup_ref' => $this->backupRef,
            'created_at' => $this->createdAt,
        ];
    }

    public function hash(): string
    {
        $canonical = $this->toArray();
        \ksort($canonical);
        foreach (['schema_fingerprints', 'row_counts', 'row_hashes', 'watermarks'] as $key) {
            if (\is_array($canonical[$key])) {
                \ksort($canonical[$key]);
            }
        }

        return \hash('sha256', \json_encode($canonical, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }

    public function withTamper(string $field, mixed $value): self
    {
        $data = $this->toArray();
        $data[$field] = $value;

        return self::fromArray($data);
    }

    /**
     * @param mixed $raw
     * @return array<string, string>
     */
    private static function stringMap(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string)$k] = $v === null ? '' : (string)$v;
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return array<string, int>
     */
    private static function intMap(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string)$k] = (int)$v;
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return array<string, scalar|null>
     */
    private static function scalarMap(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            if ($v === null || \is_scalar($v)) {
                $out[(string)$k] = $v;
            }
        }

        return $out;
    }
}
