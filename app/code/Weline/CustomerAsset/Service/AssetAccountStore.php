<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Service;

use Weline\CustomerAsset\Model\AssetAccount;

final class AssetAccountStore
{
    /** @var array<string, AssetAccount> */
    private array $rows = [];

    public static function forTesting(): self
    {
        return new self();
    }

    public function key(string $customerId, int $websiteId, string $assetCode, string $namespace): string
    {
        return implode(':', [$namespace, $websiteId, $customerId, strtolower($assetCode)]);
    }

    public function put(AssetAccount $account): void
    {
        $this->rows[$account->accountId] = $account;
    }

    public function get(string $accountId): ?AssetAccount
    {
        return $this->rows[$accountId] ?? null;
    }

    public function find(string $customerId, int $websiteId, string $assetCode, string $namespace): ?AssetAccount
    {
        foreach ($this->rows as $row) {
            if ($row->customerId === $customerId
                && $row->websiteId === $websiteId
                && $row->assetCode === strtolower($assetCode)
                && $row->namespace === $namespace
            ) {
                return $row;
            }
        }

        return null;
    }

    /**
     * CAS：期望 version 匹配才写入。
     */
    public function compareAndSet(AssetAccount $expected, AssetAccount $next): bool
    {
        $current = $this->rows[$expected->accountId] ?? null;
        if ($current === null || $current->version !== $expected->version) {
            return false;
        }
        $this->rows[$expected->accountId] = $next;

        return true;
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
