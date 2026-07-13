<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Read/write administration surface for an optional shared-state runtime.
 */
interface SharedStateAdminProviderInterface
{
    /** @return array<string, mixed> */
    public function getMemoryOverview(): array;

    /** @return array<int, array<string, mixed>> */
    public function listMemoryNamespaces(int $limit = 200): array;

    public function clearMemoryNamespace(string $namespace): bool;
}
