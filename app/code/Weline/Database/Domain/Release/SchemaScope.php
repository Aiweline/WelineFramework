<?php

declare(strict_types=1);

namespace Weline\Database\Domain\Release;

use Weline\Framework\Database\Release\Api\SchemaScopeInterface;

/** 不可变作用域值对象：connection/database/schema/shard/tenant。 */
final class SchemaScope implements SchemaScopeInterface
{
    public function __construct(
        private readonly string $connectionName = '',
        private readonly string $databaseName = '',
        private readonly string $schemaName = '',
        private readonly string $shardKey = '',
        private readonly string $tenantKey = '',
    ) {
    }

    public function connectionName(): string
    {
        return $this->connectionName;
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    public function schemaName(): string
    {
        return $this->schemaName;
    }

    public function shardKey(): string
    {
        return $this->shardKey;
    }

    public function tenantKey(): string
    {
        return $this->tenantKey;
    }

    /** 稳定作用域键：字段按固定顺序序列化后取 sha256。 */
    public function key(): string
    {
        return \hash('sha256', \json_encode([
            'connection' => $this->connectionName,
            'database' => $this->databaseName,
            'schema' => $this->schemaName,
            'shard' => $this->shardKey,
            'tenant' => $this->tenantKey,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            connectionName: (string)($data['connection_name'] ?? ''),
            databaseName: (string)($data['database'] ?? ''),
            schemaName: (string)($data['schema'] ?? ''),
            shardKey: (string)($data['shard_key'] ?? ''),
            tenantKey: (string)($data['tenant_key'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'connection_name' => $this->connectionName,
            'database' => $this->databaseName,
            'schema' => $this->schemaName,
            'shard_key' => $this->shardKey,
            'tenant_key' => $this->tenantKey,
            'key' => $this->key(),
        ];
    }
}
