<?php

declare(strict_types=1);

namespace Weline\Database\Domain\Release;

use InvalidArgumentException;
use Weline\Framework\Database\Release\Api\ReleaseManifestInterface;

/** 不可变发布清单值对象。 */
final class ReleaseManifest implements ReleaseManifestInterface
{
    private const ROLLBACK_POLICIES = ['L0', 'L1', 'L2', 'L3'];

    /**
     * @param array<string, array<string, mixed>> $modules
     */
    public function __construct(
        private readonly string $releaseId,
        private readonly int $schemaEpoch,
        private readonly string $gitCommit,
        private readonly ?string $gitTag,
        private readonly string $deployVersion,
        private readonly array $modules,
        private readonly string $schemaManifestChecksum,
        private readonly ?string $snapshotId,
        private readonly ?string $backupSetId,
        private readonly string $rollbackPolicy,
    ) {
        if ($this->releaseId === '') {
            throw new InvalidArgumentException('release_id 不能为空');
        }
        if ($this->schemaEpoch < 1) {
            throw new InvalidArgumentException('schema_epoch 必须 >= 1');
        }
        if (!\in_array($this->rollbackPolicy, self::ROLLBACK_POLICIES, true)) {
            throw new InvalidArgumentException(
                'rollback_policy 必须是 L0/L1/L2/L3，实际：' . $this->rollbackPolicy
            );
        }
    }

    public function releaseId(): string
    {
        return $this->releaseId;
    }

    public function schemaEpoch(): int
    {
        return $this->schemaEpoch;
    }

    public function gitCommit(): string
    {
        return $this->gitCommit;
    }

    public function gitTag(): ?string
    {
        return $this->gitTag;
    }

    public function deployVersion(): string
    {
        return $this->deployVersion;
    }

    public function modules(): array
    {
        return $this->modules;
    }

    public function schemaManifestChecksum(): string
    {
        return $this->schemaManifestChecksum;
    }

    public function snapshotId(): ?string
    {
        return $this->snapshotId;
    }

    public function backupSetId(): ?string
    {
        return $this->backupSetId;
    }

    public function rollbackPolicy(): string
    {
        return $this->rollbackPolicy;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'release_id' => $this->releaseId,
            'schema_epoch' => $this->schemaEpoch,
            'git_commit' => $this->gitCommit,
            'git_tag' => $this->gitTag,
            'deploy_version' => $this->deployVersion,
            'modules' => $this->modules,
            'schema_manifest_checksum' => $this->schemaManifestChecksum,
            'snapshot_id' => $this->snapshotId,
            'backup_set_id' => $this->backupSetId,
            'rollback_policy' => $this->rollbackPolicy,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            releaseId: (string)($data['release_id'] ?? ''),
            schemaEpoch: (int)($data['schema_epoch'] ?? 0),
            gitCommit: (string)($data['git_commit'] ?? ''),
            gitTag: isset($data['git_tag']) ? (string)$data['git_tag'] : null,
            deployVersion: (string)($data['deploy_version'] ?? ''),
            modules: \is_array($data['modules'] ?? null) ? $data['modules'] : [],
            schemaManifestChecksum: (string)($data['schema_manifest_checksum'] ?? ''),
            snapshotId: isset($data['snapshot_id']) ? (string)$data['snapshot_id'] : null,
            backupSetId: isset($data['backup_set_id']) ? (string)$data['backup_set_id'] : null,
            rollbackPolicy: (string)($data['rollback_policy'] ?? ''),
        );
    }
}
