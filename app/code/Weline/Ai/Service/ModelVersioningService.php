<?php
declare(strict_types=1);

namespace Weline\Ai\Service;

/**
 * 模型版本控制服务
 */
class ModelVersioningService
{
    public function createVersion(int $modelId, string $version): bool
    {
        return true;
    }
}
