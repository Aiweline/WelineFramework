<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

final class StorageRuntimeDiagnostics
{
    private const MAX_RESOURCE_KIND_BUCKETS = 64;
    private static int $activeResources = 0;
    private static int $activeClients = 0;
    private static int $cleanupFailures = 0;
    private static int $operationResidues = 0;
    private static int $uncleanedAtLastReset = 0;
    private static ?string $lastCleanupFailure = null;
    private static ?string $lastOperationResidue = null;
    /** @var array<string,int> */
    private static array $activeByKind = [];
    private static int $requestDiskCacheEntries = 0;
    private static int $requestConfigCacheEntries = 0;
    private static int $providerRegistryEntries = 0;

    public static function resourceOpened(string $kind): void
    {
        $kind = self::boundedResourceKind($kind);
        ++self::$activeResources;
        self::$activeByKind[$kind] = (self::$activeByKind[$kind] ?? 0) + 1;
        if (str_contains($kind, 'client')) {
            ++self::$activeClients;
        }
    }

    public static function resourceClosed(string $kind): void
    {
        $kind = self::boundedResourceKind($kind);
        self::$activeResources = max(0, self::$activeResources - 1);
        self::$activeByKind[$kind] = max(0, (self::$activeByKind[$kind] ?? 0) - 1);
        if (str_contains($kind, 'client')) {
            self::$activeClients = max(0, self::$activeClients - 1);
        }
    }

    public static function cleanupFailed(\Throwable $throwable, int $remaining): void
    {
        ++self::$cleanupFailures;
        self::$uncleanedAtLastReset = max(0, $remaining);
        self::$lastCleanupFailure = substr($throwable::class, 0, 180);
    }

    public static function cleanupSucceeded(): void
    {
        self::$uncleanedAtLastReset = 0;
    }

    public static function operationResidue(string $code): void
    {
        ++self::$operationResidues;
        self::$lastOperationResidue = substr(
            preg_replace('/[^a-z0-9_.-]+/i', '_', $code) ?: 'storage_operation_residue',
            0,
            96,
        );
    }

    public static function diskCached(): void
    {
        ++self::$requestDiskCacheEntries;
    }

    public static function diskCacheReleased(int $entries): void
    {
        self::$requestDiskCacheEntries = max(0, self::$requestDiskCacheEntries - max(0, $entries));
    }

    public static function configCacheLoaded(int $entries): void
    {
        self::$requestConfigCacheEntries += max(0, $entries);
    }

    public static function configCacheReleased(int $entries): void
    {
        self::$requestConfigCacheEntries = max(0, self::$requestConfigCacheEntries - max(0, $entries));
    }

    public static function providerRegistryLoaded(int $entries): void
    {
        self::$providerRegistryEntries = max(self::$providerRegistryEntries, max(0, $entries));
    }

    /** @return array<string,int|string|null> */
    public static function snapshot(): array
    {
        return [
            'active_resource_handles' => self::$activeResources,
            'active_clients' => self::$activeClients,
            'active_read_handles' => self::$activeByKind['storage.read_stream'] ?? 0,
            'active_write_handles' => self::$activeByKind['storage.write_stream'] ?? 0,
            'active_local_file_handles' => self::$activeByKind['storage.local_file'] ?? 0,
            'active_proxy_file_handles' => self::$activeByKind['storage.proxy_file'] ?? 0,
            'active_temporary_files' => self::$activeByKind['storage.temporary_file'] ?? 0,
            'active_multipart_uploads' => self::$activeByKind['storage.multipart_upload'] ?? 0,
            'request_disk_cache_entries' => self::$requestDiskCacheEntries,
            'request_config_cache_entries' => self::$requestConfigCacheEntries,
            'provider_registry_entries' => self::$providerRegistryEntries,
            'cleanup_failures' => self::$cleanupFailures,
            'uncleaned_at_last_reset' => self::$uncleanedAtLastReset,
            'last_cleanup_failure' => self::$lastCleanupFailure,
            'operation_residues' => self::$operationResidues,
            'last_operation_residue' => self::$lastOperationResidue,
        ];
    }

    private static function boundedResourceKind(string $kind): string
    {
        $kind = substr(
            preg_replace('/[^a-z0-9_.-]+/i', '_', trim($kind)) ?: 'storage.unknown',
            0,
            64,
        );
        if (isset(self::$activeByKind[$kind])
            || count(self::$activeByKind) < self::MAX_RESOURCE_KIND_BUCKETS
        ) {
            return $kind;
        }
        return 'storage.other';
    }
}
