<?php

declare(strict_types=1);

namespace Weline\Database\Domain\Release;

use InvalidArgumentException;
use Weline\Framework\Database\Release\Api\SchemaManifestInterface;

/** 不可变 schema 清单值对象。 */
final class SchemaManifest implements SchemaManifestInterface
{
    /**
     * @param array<string, array<string, mixed>> $modules
     */
    public function __construct(
        private readonly string $releaseId,
        private readonly array $modules,
        private readonly string $checksum,
    ) {
        if (\preg_match('/^[a-f0-9]{64}$/Di', $this->checksum) !== 1) {
            throw new InvalidArgumentException('checksum 必须是 64 位 hex');
        }
    }

    public function releaseId(): string
    {
        return $this->releaseId;
    }

    public function modules(): array
    {
        return $this->modules;
    }

    public function checksum(): string
    {
        return $this->checksum;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'release_id' => $this->releaseId,
            'modules' => $this->modules,
            'checksum' => $this->checksum,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            releaseId: (string)($data['release_id'] ?? ''),
            modules: \is_array($data['modules'] ?? null) ? $data['modules'] : [],
            checksum: (string)($data['checksum'] ?? ''),
        );
    }
}
