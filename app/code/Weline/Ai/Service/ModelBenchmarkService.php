<?php
declare(strict_types=1);

namespace Weline\Ai\Service;

/**
 * 模型基准测试服务
 */
class ModelBenchmarkService
{
    public function runBenchmark(int $modelId): array
    {
        return ['score' => 0.95];
    }
}
