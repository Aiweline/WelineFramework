<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Double;

use Weline\Cdn\Api\ScopedAccountBindingRepositoryInterface;

/**
 * Unit-test double only. Production binds the repository contract to ORM.
 */
final class InMemoryScopedAccountBindingRepository implements ScopedAccountBindingRepositoryInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    public function find(string $storageScope, string $storeMode, string $adapter): ?array
    {
        return $this->rows[$this->key($storageScope, $storeMode, $adapter)] ?? null;
    }

    public function save(
        string $storageScope,
        string $storeMode,
        string $adapter,
        int $accountId,
        string $mediaBaseUrl,
        string $globalAlias,
    ): array {
        $row = [
            'account_id' => $accountId,
            'adapter' => $adapter,
            'media_base_url' => $mediaBaseUrl,
            'global_alias' => $globalAlias,
            'storage_scope' => $storageScope,
            'store_mode' => $storeMode,
        ];
        $this->rows[$this->key($storageScope, $storeMode, $adapter)] = $row;

        return $row;
    }

    public function delete(string $storageScope, string $storeMode, string $adapter): bool
    {
        $key = $this->key($storageScope, $storeMode, $adapter);
        if (!isset($this->rows[$key])) {
            return false;
        }
        unset($this->rows[$key]);

        return true;
    }

    public function listForMode(string $storeMode): array
    {
        $rows = \array_values(\array_filter(
            $this->rows,
            static fn(array $row): bool => $row['store_mode'] === $storeMode,
        ));
        \usort($rows, static fn(array $a, array $b): int => [
            $a['storage_scope'],
            $a['adapter'],
        ] <=> [
            $b['storage_scope'],
            $b['adapter'],
        ]);

        return $rows;
    }

    private function key(string $storageScope, string $storeMode, string $adapter): string
    {
        return $storageScope . "\0" . $storeMode . "\0" . $adapter;
    }
}
