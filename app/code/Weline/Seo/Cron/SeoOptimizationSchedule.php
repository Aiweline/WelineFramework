<?php

declare(strict_types=1);

namespace Weline\Seo\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoOptimizationExperiment;
use Weline\Seo\Model\SeoOptimizationPolicy;
use Weline\Seo\Service\OptimizationPolicyService;
use Weline\Seo\Service\OptimizationTiming;
use Weline\Seo\Service\SeoOptimizationQueueService;
use Weline\Seo\Service\SeoSearchQueryHeatService;
use Weline\Seo\Service\SeoWebsiteAccountBindingService;

/** Hourly queue admission only; model work remains scheduler-owned. */
final class SeoOptimizationSchedule implements CronTaskInterface
{
    public function __construct(
        private readonly OptimizationPolicyService $policyService,
        private readonly SeoOptimizationQueueService $queueService,
        private readonly SeoOptimizationExperiment $experimentModel,
        ?OptimizationTiming $timing = null,
        ?SeoSearchQueryHeatService $queryHeat = null,
        ?SeoWebsiteAccountBindingService $bindings = null,
    ) {
        $this->timing = $timing ?? new OptimizationTiming();
        $this->queryHeat = $queryHeat ?? ObjectManager::getInstance(SeoSearchQueryHeatService::class);
        $this->bindings = $bindings ?? ObjectManager::getInstance(SeoWebsiteAccountBindingService::class);
    }

    private readonly OptimizationTiming $timing;
    private readonly SeoSearchQueryHeatService $queryHeat;
    private readonly SeoWebsiteAccountBindingService $bindings;

    public function name(): string
    {
        return 'SEO 自动优化调度';
    }

    public function execute_name(): string
    {
        return 'seo_optimization_schedule';
    }

    public function tip(): string
    {
        return '为站点创建幂等分析队列，并为到期实验创建评估队列';
    }

    public function cron_time(): string
    {
        return '17 * * * *';
    }

    public function execute(): string
    {
        $now = $this->timing->now();
        $websiteIds = self::scheduledAnalysisEnabled() ? $this->websiteIds() : [];
        $analysisQueued = 0;
        $evaluationQueued = 0;
        $errors = 0;
        foreach ($websiteIds as $websiteId) {
            try {
                $policy = $this->policyService->get($websiteId);
                if ((string)$policy['mode'] === SeoOptimizationPolicy::MODE_OFF) {
                    continue;
                }
                $this->queueService->enqueueAnalyze(
                    $websiteId,
                    'pagebuilder_ai_site',
                    [],
                    'daily-' . $now->format('Ymd'),
                    ['trigger_source' => 'scheduler'],
                );
                $analysisQueued++;
            } catch (\Throwable $throwable) {
                $errors++;
                \w_log_error('[Weline_Seo] optimization analyze scheduling failed: ' . $throwable->getMessage());
            }
        }

        foreach ([
            SeoOptimizationExperiment::STATUS_PUBLISH_PENDING,
            SeoOptimizationExperiment::STATUS_FINALIZE_PENDING,
            SeoOptimizationExperiment::STATUS_ROLLBACK_PENDING,
            SeoOptimizationExperiment::STATUS_EVALUATING,
        ] as $status) {
            $rows = (clone $this->experimentModel)->clearData()->clearQuery()
                ->where(SeoOptimizationExperiment::schema_fields_STATUS, $status)
                ->order(SeoOptimizationExperiment::schema_fields_EVALUATE_AFTER, 'ASC')
                ->limit(500)->select()->fetchArray();
            foreach (\is_array($rows) ? $rows : [] as $row) {
                if (!\is_array($row)
                    || ($status === SeoOptimizationExperiment::STATUS_EVALUATING
                        && $this->timing->isFuture(
                            (string)($row[SeoOptimizationExperiment::schema_fields_EVALUATE_AFTER] ?? '')
                        ))
                ) {
                    continue;
                }
                try {
                    $this->queueService->enqueueEvaluate(
                        (int)($row[SeoOptimizationExperiment::schema_fields_ID] ?? 0),
                        (string)($row[SeoOptimizationExperiment::schema_fields_EXPERIMENT_KEY] ?? ''),
                        'hourly-' . $now->format('YmdH')
                    );
                    $evaluationQueued++;
                } catch (\Throwable $throwable) {
                    $errors++;
                    \w_log_error('[Weline_Seo] optimization evaluation scheduling failed: ' . $throwable->getMessage());
                }
            }
        }

        return \sprintf(
            'SEO 自动优化调度完成：分析 %d，评估 %d，错误 %d',
            $analysisQueued,
            $evaluationQueued,
            $errors,
        );
    }

    public function unlock_timeout(int $minute = 10): int
    {
        return $minute;
    }

    /** @return list<int> */
    private function websiteIds(): array
    {
        $ids = [];
        foreach (self::websiteIdsFromPolicies($this->policyService->persistedPolicies()) as $id) {
            $ids[$id] = $id;
        }
        try {
            foreach ($this->queryHeat->listWebsiteIds() as $id) {
                if ($id >= 0) {
                    $ids[$id] = $id;
                }
            }
        } catch (\Throwable) {
        }
        try {
            foreach ($this->bindings->getStatsAccounts() as $info) {
                $id = self::websiteId($info['website_id'] ?? null);
                if ($id !== null) {
                    $ids[$id] = $id;
                }
            }
        } catch (\Throwable) {
        }
        \ksort($ids, \SORT_NUMERIC);

        return \array_values($ids);
    }

    /** @param list<array<string,mixed>> $policies @return list<int> */
    private static function websiteIdsFromPolicies(array $policies): array
    {
        $ids = [];
        foreach ($policies as $policy) {
            if (!\is_array($policy)) {
                continue;
            }
            $id = self::websiteId($policy[SeoOptimizationPolicy::schema_fields_WEBSITE_ID] ?? null);
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }
        \ksort($ids, \SORT_NUMERIC);
        return \array_values($ids);
    }

    private static function websiteId(mixed $value): ?int
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

    /**
     * A closed-loop acceptance run admits one exact target through the public
     * Queue service. Keep Scheduler consumption real while preventing the
     * hourly site-wide scan from expanding that bounded paid-AI scope.
     *
     * Production behavior is unchanged unless both acceptance gates are set.
     */
    private static function scheduledAnalysisEnabled(): bool
    {
        return (string)\getenv('WELINE_SEO_ACCEPTANCE_MODE') !== '1'
            || (string)\getenv('WELINE_SEO_ACCEPTANCE_EXACT_TARGET_ONLY') !== '1';
    }
}
