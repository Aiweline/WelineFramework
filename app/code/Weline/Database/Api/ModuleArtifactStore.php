<?php

declare(strict_types=1);

namespace Weline\Database\Api;

use Weline\Database\Service\Artifact\LocalModuleArtifactProvider;

/**
 * Public module-artifact storage boundary for declared module integrations.
 */
final class ModuleArtifactStore
{
    public function __construct(private readonly LocalModuleArtifactProvider $localArtifacts)
    {
    }

    /** @return array<string, mixed> */
    public function importDirectory(
        string $moduleName,
        string $version,
        string $operationId,
        string $source,
        string $sourceName,
    ): array {
        return $this->localArtifacts->importDirectory(
            $moduleName,
            $version,
            $operationId,
            $source,
            $sourceName,
        );
    }

    /** @return array<string, mixed> */
    public function snapshotCurrent(string $moduleName, string $version, string $operationId): array
    {
        return $this->localArtifacts->snapshotCurrent($moduleName, $version, $operationId);
    }
}
