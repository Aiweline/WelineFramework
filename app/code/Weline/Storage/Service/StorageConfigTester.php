<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Storage\Api\Data\StorageConfigSnapshot;
use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;
use Weline\Storage\Model\StorageConfig;

/** Validates an unsaved disk configuration with request-scoped resources. */
final class StorageConfigTester
{
    public function __construct(
        private readonly StorageDriverProviderRegistry $providers,
        private readonly StorageRequestResourceRegistryInterface $resources,
    ) {
    }

    /** @param array<string,mixed> $config */
    public function test(string $driver, string $name, array $config): bool
    {
        $diskCode = StorageConfig::canonicalDiskCode($driver, $name);
        $provider = $this->providers->get(\Weline\Storage\Api\Data\StorageDiskCode::parse($diskCode)->providerCode());
        $snapshot = new StorageConfigSnapshot(
            $diskCode,
            1,
            $config,
            $this->providers->objectNamespaceFingerprint($provider->providerCode(), $config),
        );
        $success = false;
        try {
            $driverInstance = $provider->createDriver($snapshot, $this->resources);
            $provider->createUrlAdapter($snapshot, $this->resources);
            // A non-mutating existence probe initializes the provider client,
            // verifies credentials/bucket access, and remains bounded.
            $driverInstance->exists('.weline-storage-connection-check');
            $success = true;
        } catch (\Throwable) {
            $success = false;
        } finally {
            try {
                $this->resources->closeAll();
            } catch (\Throwable) {
                // Request cleanup will repeat the failure and quarantine the
                // WLS Worker; never turn a cleanup failure into test success.
                $success = false;
            }
        }
        return $success;
    }
}
