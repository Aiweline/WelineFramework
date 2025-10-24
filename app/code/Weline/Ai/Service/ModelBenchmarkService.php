<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiModelBenchmark;
use Weline\Framework\Manager\ObjectManager;

/**
 * Model Benchmark Service
 * 
 * Manages AI model benchmark testing and results.
 * 
 * @package Weline_Ai
 */
class ModelBenchmarkService
{
    private AiModelBenchmark $benchmark;

    public function __construct(AiModelBenchmark $benchmark)
    {
        $this->benchmark = $benchmark;
    }

    /**
     * Create a benchmark test
     *
     * @param int $modelId
     * @param string $benchmarkName
     * @param string $benchmarkType
     * @param array|null $result
     * @param float|null $score
     * @return AiModelBenchmark
     */
    public function createBenchmark(
        int $modelId,
        string $benchmarkName,
        string $benchmarkType,
        ?array $result = null,
        ?float $score = null
    ): AiModelBenchmark {
        $benchmark = clone $this->benchmark;
        $benchmark->setData([
            AiModelBenchmark::fields_MODEL_ID => $modelId,
            AiModelBenchmark::fields_BENCHMARK_NAME => $benchmarkName,
            AiModelBenchmark::fields_BENCHMARK_TYPE => $benchmarkType,
            AiModelBenchmark::fields_BENCHMARK_RESULT => $result ? json_encode($result) : null,
            AiModelBenchmark::fields_BENCHMARK_SCORE => $score,
            AiModelBenchmark::fields_TESTED_AT => date('Y-m-d H:i:s'),
        ]);
        $benchmark->save();

        return $benchmark;
    }

    /**
     * Get benchmarks by model ID
     *
     * @param int $modelId
     * @param string|null $benchmarkType
     * @return array
     */
    public function getByModelId(int $modelId, ?string $benchmarkType = null): array
    {
        $results = [];
        $collection = clone $this->benchmark;
        $collection->where(AiModelBenchmark::fields_MODEL_ID, $modelId);
        
        if ($benchmarkType) {
            $collection->where(AiModelBenchmark::fields_BENCHMARK_TYPE, $benchmarkType);
        }
        
        $items = $collection->order(AiModelBenchmark::fields_TESTED_AT, 'DESC')
            ->select()
            ->fetch();

        if ($items) {
            foreach ($items as $item) {
                $results[] = $item;
            }
        }

        return $results;
    }

    /**
     * Get best benchmark score for a model
     *
     * @param int $modelId
     * @param string $benchmarkType
     * @return float|null
     */
    public function getBestScore(int $modelId, string $benchmarkType): ?float
    {
        $benchmark = clone $this->benchmark;
        $result = $benchmark->where(AiModelBenchmark::fields_MODEL_ID, $modelId)
            ->where(AiModelBenchmark::fields_BENCHMARK_TYPE, $benchmarkType)
            ->order(AiModelBenchmark::fields_BENCHMARK_SCORE, 'DESC')
            ->find()
            ->fetch();

        return $result && $result->getId() ? (float)$result->getData(AiModelBenchmark::fields_BENCHMARK_SCORE) : null;
    }

    /**
     * Compare benchmarks between two models
     *
     * @param int $modelAId
     * @param int $modelBId
     * @param string $benchmarkType
     * @return array
     */
    public function compareModels(int $modelAId, int $modelBId, string $benchmarkType): array
    {
        $modelABenchmarks = $this->getByModelId($modelAId, $benchmarkType);
        $modelBBenchmarks = $this->getByModelId($modelBId, $benchmarkType);

        return [
            'model_a' => [
                'model_id' => $modelAId,
                'benchmarks' => $modelABenchmarks,
                'best_score' => $this->getBestScore($modelAId, $benchmarkType),
            ],
            'model_b' => [
                'model_id' => $modelBId,
                'benchmarks' => $modelBBenchmarks,
                'best_score' => $this->getBestScore($modelBId, $benchmarkType),
            ],
        ];
    }

    /**
     * Delete benchmark
     *
     * @param int $benchmarkId
     * @return bool
     */
    public function deleteBenchmark(int $benchmarkId): bool
    {
        $benchmark = clone $this->benchmark;
        $benchmark->load($benchmarkId);
        
        if (!$benchmark->getId()) {
            return false;
        }

        return $benchmark->delete();
    }
}
