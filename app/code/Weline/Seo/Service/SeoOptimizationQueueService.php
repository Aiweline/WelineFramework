<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Queue\Api\QueueStatus;
use Weline\Seo\Queue\SeoOptimizationAnalyzeQueue;
use Weline\Seo\Queue\SeoOptimizationEvaluateQueue;

/** Scheduler-owned admission for SEO queues. It never executes work inline. */
final class SeoOptimizationQueueService
{
    private const MODULE = 'Weline_Seo';

    private readonly OptimizationTiming $timing;

    public function __construct(?OptimizationTiming $timing = null)
    {
        $this->timing = $timing ?? new OptimizationTiming();
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    public function enqueueAnalyze(
        int $websiteId,
        string $adapter = 'pagebuilder_ai_site',
        array $target = [],
        string $requestKey = '',
    ): array {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('website_id must be non-negative.');
        }
        $adapter = \trim($adapter);
        if ($adapter === '' || \strlen($adapter) > 64) {
            throw new \InvalidArgumentException('Optimization adapter is invalid.');
        }
        $requestKey = $requestKey !== ''
            ? $this->key($requestKey)
            : 'daily-' . $this->timing->now()->format('Ymd');
        $targetIdentity = \trim((string)($target['page_type'] ?? '')) . '|'
            . \trim((string)($target['block_key'] ?? ''));
        $bizKey = 'seo-opt:analyze:' . $websiteId . ':'
            . \substr(\hash('sha256', $adapter . '|' . $targetIdentity . '|' . $requestKey), 0, 64);
        $payload = [
            'contract' => 'seo.optimization_analyze_queue.v1',
            'website_id' => $websiteId,
            'adapter' => $adapter,
            'target' => $this->target($target),
            'request_key' => $requestKey,
        ];

        return $this->create(SeoOptimizationAnalyzeQueue::class, (string)__('SEO 自动优化分析'), $payload, $bizKey);
    }

    /** @return array<string,mixed> */
    public function enqueueEvaluate(int $experimentId, string $experimentKey, string $requestKey = ''): array
    {
        if ($experimentId <= 0 || \trim($experimentKey) === '') {
            throw new \InvalidArgumentException('A valid optimization experiment is required.');
        }
        $requestKey = $requestKey !== '' ? $this->key($requestKey) : 'hourly-' . $this->timing->now()->format('YmdH');
        $bizKey = 'seo-opt:evaluate:'
            . \substr(\hash('sha256', $experimentKey . '|' . $requestKey), 0, 64);
        $payload = [
            'contract' => 'seo.optimization_evaluate_queue.v1',
            'experiment_id' => $experimentId,
            'experiment_key' => \trim($experimentKey),
            'request_key' => $requestKey,
        ];

        return $this->create(SeoOptimizationEvaluateQueue::class, (string)__('SEO 自动优化评估'), $payload, $bizKey);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function create(string $class, string $name, array $payload, string $bizKey): array
    {
        $result = \w_query('queue', 'createIfAbsent', [
            'class' => $class,
            'name' => $name,
            'module' => self::MODULE,
            'content' => $payload,
            'status' => QueueStatus::PENDING,
            'auto' => true,
            // SEO experiments are consumed by the registered Cron scheduler so
            // their worker inherits the scheduler-owned runtime context.
            'dispatch' => false,
            'biz_key' => $bizKey,
            'idempotency_scope' => self::MODULE,
            'idempotency_key' => $bizKey,
        ], 'backend');
        if (!\is_array($result) || (int)($result['queue_id'] ?? 0) <= 0) {
            throw new \RuntimeException('SEO optimization queue admission failed.');
        }

        return $result;
    }

    /** @param array<string,mixed> $target @return array<string,string> */
    private function target(array $target): array
    {
        $pageType = \trim((string)($target['page_type'] ?? ''));
        $blockKey = \trim((string)($target['block_key'] ?? ''));
        if ($pageType === '') {
            return [];
        }
        if (\preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $pageType) !== 1
            || ($blockKey !== '' && \preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $blockKey) !== 1)
        ) {
            throw new \InvalidArgumentException('Optimization queue target is invalid.');
        }

        return ['page_type' => $pageType, 'block_key' => $blockKey];
    }

    private function key(string $key): string
    {
        $key = \trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException('Optimization request key is empty.');
        }

        return \strlen($key) <= 96 ? $key : \substr(\hash('sha256', $key), 0, 64);
    }
}
