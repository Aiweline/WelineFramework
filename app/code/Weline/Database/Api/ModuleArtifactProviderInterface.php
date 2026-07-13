<?php

declare(strict_types=1);

namespace Weline\Database\Api;

interface ModuleArtifactProviderInterface
{
    public function getName(): string;

    public function getPriority(): int;

    /** @return list<array{version: string, source: string, checksum?: string}> */
    public function listVersions(string $moduleName): array;

    /** @return array{success: bool, module_name: string, version: string, path?: string, checksum?: string, source: string, error?: string} */
    public function stage(string $moduleName, string $version, string $operationId): array;

    /** @return array{success: bool, module_name: string, version: string, path?: string, checksum?: string, source: string, error?: string} */
    public function snapshotCurrent(string $moduleName, string $version, string $operationId): array;
}
