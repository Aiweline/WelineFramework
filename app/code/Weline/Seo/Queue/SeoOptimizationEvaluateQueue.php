<?php

declare(strict_types=1);

namespace Weline\Seo\Queue;

use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;
use Weline\Seo\Service\SeoOptimizationEvaluationService;

final class SeoOptimizationEvaluateQueue implements QueueInterface
{
    public function __construct(private readonly SeoOptimizationEvaluationService $evaluationService)
    {
    }

    public function name(): string
    {
        return (string)__('SEO 自动优化评估队列');
    }

    public function tip(): string
    {
        return (string)__('按连续观察窗口保留显著候选，或精确回滚受限字段。');
    }

    public function attributes(): array
    {
        return [];
    }

    public function validate(Queue &$queue): bool
    {
        $payload = $this->payload($queue);
        $valid = (string)($payload['contract'] ?? '') === 'seo.optimization_evaluate_queue.v1'
            && (int)($payload['experiment_id'] ?? 0) > 0
            && \trim((string)($payload['experiment_key'] ?? '')) !== ''
            && \trim((string)($payload['request_key'] ?? '')) !== '';
        if (!$valid) {
            $queue->setResult((string)__('SEO 自动优化评估队列参数无效。'));
        }
        return $valid;
    }

    public function execute(Queue &$queue): string
    {
        $result = $this->evaluationService->evaluate($this->payload($queue));
        return (string)\json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function payload(Queue $queue): array
    {
        $decoded = \json_decode((string)$queue->getContent(), true);
        return \is_array($decoded) && !\array_is_list($decoded) ? $decoded : [];
    }
}
