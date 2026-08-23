<?php

declare(strict_types=1);

namespace Weline\Storage\Api\Data;

final readonly class StorageConfigSnapshot
{
    /** @param array<string,mixed> $config */
    public function __construct(
        public string $diskCode,
        public int $configRevision,
        private array $config,
        private string $namespaceFingerprint,
        private ?int $sourceConfigId = null,
    ) {
        StorageDiskCode::parse($diskCode);
        if (
            $configRevision < 1
            || preg_match('/^[a-f0-9]{64}$/D', $namespaceFingerprint) !== 1
            || ($sourceConfigId !== null && $sourceConfigId < 1)
        ) {
            throw new \InvalidArgumentException((string)__('存储配置快照版本或对象命名空间指纹无效。'));
        }
    }

    public function code(): StorageDiskCode
    {
        return StorageDiskCode::parse($this->diskCode);
    }

    public function visibility(): string
    {
        $visibility = strtolower(trim((string)($this->config['visibility'] ?? 'public')));
        if (!in_array($visibility, ['public', 'private'], true)) {
            throw new \RuntimeException((string)__('存储磁盘可见性配置无效。'));
        }
        return $visibility;
    }

    public function objectNamespaceFingerprint(): string
    {
        return $this->namespaceFingerprint;
    }

    /** @internal Used only to lock the exact durable config row at commit time. */
    public function sourceConfigId(): ?int
    {
        return $this->sourceConfigId;
    }

    /** @return array{disk_code:string,config_revision:int} */
    public function __debugInfo(): array
    {
        return [
            'disk_code' => $this->diskCode,
            'config_revision' => $this->configRevision,
        ];
    }

    /** @internal Drivers must never log or persist this result. @return array<string,mixed> */
    public function driverConfig(): array
    {
        return $this->config;
    }
}
