<?php

declare(strict_types=1);

namespace Weline\Seo\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Seo\Service\SeoOptimizationControlCenterService;

/** Signed backend bin-query surface for the read-only real-time control center. */
final class SeoOptimizationControlQueryProvider implements QueryProviderInterface
{
    public function __construct(private readonly SeoOptimizationControlCenterService $service)
    {
    }

    public function getProviderName(): string
    {
        return 'seo_optimization_control';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'optimizationControlCenterSnapshot' => $this->service->snapshot(
                $this->nullableWebsiteId($params),
                ['sites_only' => $this->truthy($params['sites_only'] ?? $params['directory_only'] ?? false)]
            ),
            'optimizationTaskList' => $this->service->taskList($params),
            'optimizationTaskDetail' => $this->service->taskDetail($params),
            'optimizationActivityList' => $this->service->activityList($params),
            'optimizationActivityStream' => $this->service->activityStream($params),
            default => throw new \InvalidArgumentException('SEO optimization control query does not support operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        $scope = [
            ['name' => 'website_id', 'type' => 'int|null', 'required' => false],
        ];
        return [
            'provider' => $this->getProviderName(),
            'name' => (string)__('AI 优化实时控制中心'),
            'description' => (string)__('只读查看站点检测、证据、发布与实验实时状态。'),
            'module' => 'Weline_Seo',
            'operations' => [
                $this->operation('optimizationControlCenterSnapshot', 'read', \array_merge($scope, [
                    ['name' => 'sites_only', 'type' => 'bool', 'required' => false],
                    ['name' => 'directory_only', 'type' => 'bool', 'required' => false],
                ])),
                $this->operation('optimizationTaskList', 'read', \array_merge($scope, [
                    ['name' => 'phase', 'type' => 'string', 'required' => false, 'max_length' => 24],
                    ['name' => 'outcome', 'type' => 'string', 'required' => false, 'max_length' => 32],
                    ['name' => 'target', 'type' => 'string', 'required' => false, 'max_length' => 160],
                    ['name' => 'only_exceptions', 'type' => 'bool', 'required' => false],
                    ['name' => 'cursor', 'type' => 'string', 'required' => false, 'max_length' => 360],
                    ['name' => 'page_size', 'type' => 'int', 'required' => false],
                ])),
                $this->operation('optimizationTaskDetail', 'read', [
                    ['name' => 'cycle_id', 'type' => 'string', 'required' => true, 'max_length' => 128],
                    ['name' => 'run_id', 'type' => 'string', 'required' => false, 'max_length' => 128],
                ]),
                $this->operation('optimizationActivityList', 'read', \array_merge($scope, [
                    ['name' => 'cycle_id', 'type' => 'string', 'required' => false, 'max_length' => 128],
                    ['name' => 'run_id', 'type' => 'string', 'required' => false, 'max_length' => 128],
                    ['name' => 'after', 'type' => 'string', 'required' => false, 'max_length' => 20],
                    ['name' => 'before', 'type' => 'string', 'required' => false, 'max_length' => 20],
                    ['name' => 'page_size', 'type' => 'int', 'required' => false],
                ])),
                $this->operation('optimizationActivityStream', 'stream', \array_merge($scope, [
                    ['name' => 'cycle_id', 'type' => 'string', 'required' => false, 'max_length' => 128],
                    ['name' => 'run_id', 'type' => 'string', 'required' => false, 'max_length' => 128],
                    ['name' => 'after', 'type' => 'string', 'required' => false, 'max_length' => 20],
                    ['name' => 'last_event_id', 'type' => 'string', 'required' => false, 'max_length' => 20],
                ])),
            ],
        ];
    }

    /** @param list<array<string,mixed>> $params @return array<string,mixed> */
    private function operation(string $name, string $mode, array $params): array
    {
        return [
            'name' => $name,
            'description' => (string)__($name),
            'frontend' => true,
            'mode' => $mode,
            'graph' => false,
            'auth' => 'backend',
            'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Seo::seo_optimization'],
            'params' => $params,
            'returns' => ['type' => 'array'],
        ];
    }

    /** @param array<string,mixed> $params */
    private function nullableWebsiteId(array $params): ?int
    {
        if (!\array_key_exists('website_id', $params) && !\array_key_exists('websiteId', $params)) {
            return null;
        }
        $raw = $params['website_id'] ?? $params['websiteId'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (\is_int($raw)) {
            if ($raw < 0) {
                throw new \InvalidArgumentException('website_id must be non-negative.');
            }
            return $raw;
        }
        if (!\is_string($raw) || \preg_match('/^(?:0|[1-9][0-9]*)$/D', \trim($raw)) !== 1) {
            throw new \InvalidArgumentException('website_id must be null or a non-negative integer.');
        }
        return (int)\trim($raw);
    }

    private function truthy(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value !== 0;
        }
        $raw = \strtolower(\trim((string)$value));

        return $raw !== '' && !\in_array($raw, ['0', 'false', 'no', 'off', 'null'], true);
    }
}
