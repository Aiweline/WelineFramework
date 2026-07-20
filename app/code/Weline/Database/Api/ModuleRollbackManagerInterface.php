<?php

declare(strict_types=1);

namespace Weline\Database\Api;

interface ModuleRollbackManagerInterface
{
    /** @return array<string, mixed> */
    public function getModuleState(string $moduleName): array;

    /** @return list<array<string, mixed>> */
    public function listTargets(string $moduleName): array;

    /** @return array<string, mixed> */
    public function createPlan(string $moduleName, string $targetVersion): array;

    /** @return array<string, mixed> */
    public function start(string $planId, string $planHash, string $operator): array;

    /** @return array<string, mixed> */
    public function getOperation(string $operationId): array;
}
