<?php

declare(strict_types=1);

namespace Weline\Server\Api\Runtime;

use Weline\Framework\Runtime\SharedStateAdminProviderInterface;
use Weline\Server\Service\Control\SharedStateAdminService;

final class SharedStateAdminProvider implements SharedStateAdminProviderInterface
{
    public function __construct(
        private readonly SharedStateAdminService $adminService,
    ) {
    }

    public function getMemoryOverview(): array
    {
        return $this->adminService->getMemoryOverview();
    }

    public function listMemoryNamespaces(int $limit = 200): array
    {
        return $this->adminService->listMemoryNamespaces($limit);
    }

    public function clearMemoryNamespace(string $namespace): bool
    {
        return $this->adminService->clearMemoryNamespace($namespace);
    }
}
