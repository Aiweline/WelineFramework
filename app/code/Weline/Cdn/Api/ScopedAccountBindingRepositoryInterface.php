<?php

declare(strict_types=1);

namespace Weline\Cdn\Api;

/**
 * CDN account binding persistence boundary.
 *
 * Production implementations must be shared by all WLS workers and survive
 * process restarts. Binding rows contain account identifiers only, never
 * account credentials or secret references.
 */
interface ScopedAccountBindingRepositoryInterface
{
    /**
     * @return array{
     *   account_id:int,
     *   adapter:string,
     *   media_base_url:string,
     *   global_alias:string,
     *   storage_scope:string,
     *   store_mode:string
     * }|null
     */
    public function find(string $storageScope, string $storeMode, string $adapter): ?array;

    /**
     * @return array{
     *   account_id:int,
     *   adapter:string,
     *   media_base_url:string,
     *   global_alias:string,
     *   storage_scope:string,
     *   store_mode:string
     * }
     */
    public function save(
        string $storageScope,
        string $storeMode,
        string $adapter,
        int $accountId,
        string $mediaBaseUrl,
        string $globalAlias,
    ): array;

    public function delete(string $storageScope, string $storeMode, string $adapter): bool;

    /**
     * @return list<array{
     *   account_id:int,
     *   adapter:string,
     *   media_base_url:string,
     *   global_alias:string,
     *   storage_scope:string,
     *   store_mode:string
     * }>
     */
    public function listForMode(string $storeMode): array;
}
