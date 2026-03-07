<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Runtime\Runtime;
use Weline\Server\Shared\Service\SharedMemoryService;

/**
 * Cross-worker theme mode strong-consistency storage.
 */
class ThemeModeSharedService
{
    public function __construct(
        private readonly SharedMemoryService $memoryService
    ) {
    }

    public function enabled(): bool
    {
        return Runtime::isPersistent();
    }

    public function getMode(string $area, ?int $userId, ?string $sessionId = null): ?string
    {
        if (!$this->enabled()) {
            return null;
        }
        $key = $this->buildKey($area, $userId, $sessionId);
        $value = $this->memoryService->get('cfg', $key);
        return \is_string($value) ? $value : null;
    }

    public function setMode(string $area, ?int $userId, string $mode, ?string $sessionId = null, int $ttl = 2592000): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $key = $this->buildKey($area, $userId, $sessionId);
        return $this->memoryService->set('cfg', $key, $mode, $ttl);
    }

    private function buildKey(string $area, ?int $userId, ?string $sessionId): string
    {
        $scope = $userId !== null && $userId > 0
            ? 'user:' . $userId
            : 'session:' . ($sessionId ?: 'anonymous');
        return 'theme:mode:' . $area . ':' . $scope;
    }
}
