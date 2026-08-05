<?php

declare(strict_types=1);

namespace Weline\Seo\Queue;

use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;
use Weline\Seo\Service\SeoOptimizationOrchestrator;

final class SeoOptimizationAnalyzeQueue implements QueueInterface
{
    public function __construct(private readonly SeoOptimizationOrchestrator $orchestrator)
    {
    }

    public function name(): string
    {
        return (string)__('SEO 自动优化分析队列');
    }

    public function tip(): string
    {
        return (string)__('发现达标目标并生成结构化建议；是否修改由站点策略决定。');
    }

    public function attributes(): array
    {
        return [];
    }

    public function validate(Queue &$queue): bool
    {
        $payload = $this->payload($queue);
        $valid = (string)($payload['contract'] ?? '') === 'seo.optimization_analyze_queue.v1'
            && $this->websiteId($payload['website_id'] ?? null) !== null
            && \trim((string)($payload['adapter'] ?? '')) !== ''
            && \trim((string)($payload['request_key'] ?? '')) !== '';
        if (!$valid) {
            $queue->setResult((string)__('SEO 自动优化分析队列参数无效。'));
        }
        return $valid;
    }

    public function execute(Queue &$queue): string
    {
        $result = $this->orchestrator->analyze($this->payload($queue));
        return (string)\json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }


    private function websiteId(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!\is_string($value)) {
            return null;
        }
        $value = \trim($value);
        if ($value === '' || \preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $normalized = \filter_var($value, \FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        return $normalized === false ? null : (int)$normalized;
    }

    /** @return array<string,mixed> */
    private function payload(Queue $queue): array
    {
        $decoded = \json_decode((string)$queue->getContent(), true);
        return \is_array($decoded) && !\array_is_list($decoded) ? $decoded : [];
    }
}
