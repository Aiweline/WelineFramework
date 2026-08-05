<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Migration;

/**
 * 一次隔离迁移 clone 的不可变句柄（脱敏，不含密码）。
 */
final class MigrationCloneHandle
{
    /**
     * @param array{hostname:string,hostport:string,database:string,username:string,type:string} $config
     */
    public function __construct(
        public readonly string $cloneId,
        public readonly string $database,
        public readonly string $fingerprint,
        public readonly string $mode,
        public readonly string $sourceDatabase,
        public readonly string $createdAt,
        public readonly string $owner,
        public readonly array $config,
        public readonly string $createCommand,
        public readonly string $destroyCommand,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'clone_id' => $this->cloneId,
            'database' => $this->database,
            'fingerprint' => $this->fingerprint,
            'mode' => $this->mode,
            'source_database' => $this->sourceDatabase,
            'created_at' => $this->createdAt,
            'owner' => $this->owner,
            'config' => $this->config,
            'create_command' => $this->createCommand,
            'destroy_command' => $this->destroyCommand,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $config = $data['config'] ?? [];
        if (!\is_array($config)) {
            $config = [];
        }

        return new self(
            cloneId: (string)($data['clone_id'] ?? ''),
            database: (string)($data['database'] ?? ''),
            fingerprint: (string)($data['fingerprint'] ?? ''),
            mode: (string)($data['mode'] ?? 'schema'),
            sourceDatabase: (string)($data['source_database'] ?? ''),
            createdAt: (string)($data['created_at'] ?? ''),
            owner: (string)($data['owner'] ?? ''),
            config: [
                'type' => (string)($config['type'] ?? 'pgsql'),
                'hostname' => (string)($config['hostname'] ?? '127.0.0.1'),
                'hostport' => (string)($config['hostport'] ?? '5432'),
                'database' => (string)($config['database'] ?? ''),
                'username' => (string)($config['username'] ?? ''),
            ],
            createCommand: (string)($data['create_command'] ?? ''),
            destroyCommand: (string)($data['destroy_command'] ?? ''),
        );
    }
}
