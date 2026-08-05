<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Seo\Model\SeoOptimizationActivity;
use Weline\Seo\Model\SeoOptimizationCycle;
use Weline\Seo\Model\SeoOptimizationScheduleState;

/**
 * Best-effort control-plane audit writer.
 *
 * Every public method fails open: Activity and Cycle are telemetry only and
 * must never decide whether PageBuilder applies, publishes or rolls back.
 */
final class SeoOptimizationActivityService
{
    private const DEFAULT_INTERVAL_MINUTES = 1440;

    public function __construct(
        private readonly SeoOptimizationCycle $cycleModel,
        private readonly SeoOptimizationActivity $activityModel,
        private readonly SeoOptimizationScheduleState $scheduleModel,
        ?OptimizationTiming $timing = null,
    ) {
        $this->timing = $timing ?? new OptimizationTiming();
    }

    private readonly OptimizationTiming $timing;

    public function beginCycle(
        int $websiteId,
        string $requestKey,
        string $triggerSource,
        int $targetTotal,
        int $analyzeQueueId = 0,
    ): int {
        try {
            $canonicalKey = $this->canonicalRequestKey($websiteId, $requestKey);
            $cycle = clone $this->cycleModel;
            $cycle->clearData()->clearQuery()
                ->where(SeoOptimizationCycle::schema_fields_REQUEST_KEY, $canonicalKey)
                ->find()->fetch();
            if ((int)$cycle->getId() > 0) {
                return (int)$cycle->getId();
            }

            $now = $this->timing->format($this->timing->now());
            $cycle->clearData()->clearQuery()->setData([
                SeoOptimizationCycle::schema_fields_WEBSITE_ID => $websiteId,
                SeoOptimizationCycle::schema_fields_REQUEST_KEY => $canonicalKey,
                SeoOptimizationCycle::schema_fields_TRIGGER_SOURCE => $this->token($triggerSource, 'scheduler', 32),
                SeoOptimizationCycle::schema_fields_LIFECYCLE_STATE => SeoOptimizationCycle::LIFECYCLE_RUNNING,
                SeoOptimizationCycle::schema_fields_PHASE => 'evidence',
                SeoOptimizationCycle::schema_fields_TARGET_TOTAL => $targetTotal >= 0 ? $targetTotal : null,
                SeoOptimizationCycle::schema_fields_COMPLETED_COUNT => 0,
                SeoOptimizationCycle::schema_fields_ISSUE_COUNT => 0,
                SeoOptimizationCycle::schema_fields_FAILURE_COUNT => 0,
                SeoOptimizationCycle::schema_fields_ANALYZE_QUEUE_ID => $analyzeQueueId > 0 ? $analyzeQueueId : null,
                SeoOptimizationCycle::schema_fields_SCHEDULED_AT => $now,
                SeoOptimizationCycle::schema_fields_STARTED_AT => $now,
                SeoOptimizationCycle::schema_fields_LAST_ACTIVITY_AT => $now,
            ])->save(true);
            $cycleId = (int)$cycle->getId();
            $this->append(
                $websiteId,
                $cycleId,
                0,
                0,
                $analyzeQueueId,
                '',
                '',
                'evidence',
                'worker.started',
                'info',
                'optimization_cycle_started',
                '系统开始收集本次站点检测证据',
                ['completed' => 0, 'total' => $targetTotal >= 0 ? $targetTotal : null],
                'cycle:' . $cycleId . ':worker.started',
            );
            return $cycleId;
        } catch (\Throwable $throwable) {
            $this->logFailure('begin cycle', $throwable);
            return 0;
        }
    }

    public function recordLifecycle(
        int $websiteId,
        int $runId,
        int $experimentId,
        int $queueId,
        string $pageType,
        string $blockKey,
        string $status,
        string $reason = '',
    ): void {
        try {
            $status = $this->token($status, 'failed', 64);
            $state = $this->statusState($status);
            $reason = $this->token($reason, '', 80);
            $this->append(
                $websiteId,
                0,
                \max(0, $runId),
                \max(0, $experimentId),
                \max(0, $queueId),
                $this->token($pageType, '', 64),
                $this->token($blockKey, '', 128),
                $state['phase'],
                $state['event_type'],
                $state['severity'],
                'optimization_' . $status,
                $state['message'],
                [
                    'status' => $status,
                    'outcome' => $state['outcome'],
                    'run_id' => $runId > 0 ? $runId : null,
                    'experiment_id' => $experimentId > 0 ? $experimentId : null,
                    'queue_id' => $queueId > 0 ? $queueId : null,
                    'reason_code' => $reason !== '' ? $reason : null,
                ],
                'lifecycle:' . $websiteId . ':' . $runId . ':' . $experimentId . ':' . $status . ':' . $queueId . ':' . ($reason !== '' ? $reason : 'none'),
            );
        } catch (\Throwable $throwable) {
            $this->logFailure('record lifecycle', $throwable);
        }
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $result */
    public function recordResult(
        int $cycleId,
        int $websiteId,
        array $target,
        array $result,
        int $completed,
        int $total,
    ): void {
        if ($cycleId <= 0) {
            return;
        }
        try {
            $status = $this->token((string)($result['status'] ?? 'failed'), 'failed', 64);
            $state = $this->statusState($status);
            $runId = \max(0, (int)($result['run_id'] ?? 0));
            $experimentId = \max(0, (int)($result['experiment_id'] ?? 0));
            $queueId = \max(0, (int)($result['publish_queue_id'] ?? 0));
            $pageType = $this->token((string)($result['page_type'] ?? $target['page_type'] ?? ''), '', 64);
            $blockKey = $this->token((string)($result['block_key'] ?? $target['block_key'] ?? ''), '', 128);
            $reason = $this->token((string)($result['reason'] ?? ''), '', 80);
            $facts = [
                'status' => $status,
                'outcome' => $state['outcome'],
                'completed' => \max(0, $completed),
                'total' => $total >= 0 ? $total : null,
                'run_id' => $runId ?: null,
                'experiment_id' => $experimentId ?: null,
                'queue_id' => $queueId ?: null,
                'reason_code' => $reason !== '' ? $reason : null,
                'idempotent' => !empty($result['idempotent']),
            ];
            $keyIdentity = $runId > 0
                ? 'run:' . $runId
                : 'target:' . \substr(\hash('sha256', $pageType . '|' . $blockKey), 0, 32);
            $inserted = $this->append(
                $websiteId,
                $cycleId,
                $runId,
                $experimentId,
                $queueId,
                $pageType,
                $blockKey,
                $state['phase'],
                $state['event_type'],
                $state['severity'],
                'optimization_' . $status,
                $state['message'],
                $facts,
                'cycle:' . $cycleId . ':' . $keyIdentity . ':' . $status,
            );
            if (!$inserted) {
                return;
            }

            $cycle = $this->cycleById($cycleId);
            if ((int)$cycle->getId() <= 0) {
                return;
            }
            $issueCount = (int)$cycle->getData(SeoOptimizationCycle::schema_fields_ISSUE_COUNT);
            $failureCount = (int)$cycle->getData(SeoOptimizationCycle::schema_fields_FAILURE_COUNT);
            if ($state['issue']) {
                $issueCount++;
            }
            if ($state['failure']) {
                $failureCount++;
            }
            $cycle->setData([
                SeoOptimizationCycle::schema_fields_COMPLETED_COUNT => \max(
                    (int)$cycle->getData(SeoOptimizationCycle::schema_fields_COMPLETED_COUNT),
                    $state['terminal'] ? $completed : \max(0, $completed - 1),
                ),
                SeoOptimizationCycle::schema_fields_ISSUE_COUNT => $issueCount,
                SeoOptimizationCycle::schema_fields_FAILURE_COUNT => $failureCount,
                SeoOptimizationCycle::schema_fields_LIFECYCLE_STATE => $state['terminal']
                    ? (string)$cycle->getData(SeoOptimizationCycle::schema_fields_LIFECYCLE_STATE)
                    : SeoOptimizationCycle::LIFECYCLE_RUNNING,
                SeoOptimizationCycle::schema_fields_PHASE => $state['phase'],
                SeoOptimizationCycle::schema_fields_OUTCOME => $state['outcome'] !== '' ? $state['outcome'] : null,
                SeoOptimizationCycle::schema_fields_LAST_ACTIVITY_AT => $this->timing->format($this->timing->now()),
            ])->save(true);
        } catch (\Throwable $throwable) {
            $this->logFailure('record result', $throwable);
        }
    }

    /** @param list<array<string,mixed>> $results */
    public function completeCycle(int $cycleId, int $websiteId, array $results, int $targetTotal): void
    {
        if ($cycleId <= 0) {
            return;
        }
        try {
            $running = false;
            $terminalCount = 0;
            $failureCount = 0;
            $issueCount = 0;
            $phase = 'complete';
            $outcome = 'no_issue';
            foreach ($results as $result) {
                if (!\is_array($result)) {
                    continue;
                }
                $state = $this->statusState((string)($result['status'] ?? 'failed'));
                $running = $running || !$state['terminal'];
                $terminalCount += $state['terminal'] ? 1 : 0;
                $failureCount += $state['failure'] ? 1 : 0;
                $issueCount += $state['issue'] ? 1 : 0;
                if (!$state['terminal']) {
                    $phase = $state['phase'];
                }
                if ($state['outcome'] !== '') {
                    $outcome = $state['outcome'];
                }
            }
            if ($failureCount > 0) {
                $outcome = 'failed';
            }
            $now = $this->timing->format($this->timing->now());
            $cycle = $this->cycleById($cycleId);
            if ((int)$cycle->getId() > 0) {
                $cycle->setData([
                    SeoOptimizationCycle::schema_fields_TARGET_TOTAL => $targetTotal >= 0 ? $targetTotal : null,
                    SeoOptimizationCycle::schema_fields_COMPLETED_COUNT => $terminalCount,
                    SeoOptimizationCycle::schema_fields_ISSUE_COUNT => $issueCount,
                    SeoOptimizationCycle::schema_fields_FAILURE_COUNT => $failureCount,
                    SeoOptimizationCycle::schema_fields_LIFECYCLE_STATE => $running
                        ? SeoOptimizationCycle::LIFECYCLE_RUNNING
                        : SeoOptimizationCycle::LIFECYCLE_TERMINAL,
                    SeoOptimizationCycle::schema_fields_PHASE => $phase,
                    SeoOptimizationCycle::schema_fields_OUTCOME => $outcome,
                    SeoOptimizationCycle::schema_fields_LAST_ACTIVITY_AT => $now,
                    SeoOptimizationCycle::schema_fields_FINISHED_AT => $running ? null : $now,
                ])->save(true);
            }
            $this->append(
                $websiteId,
                $cycleId,
                0,
                0,
                0,
                '',
                '',
                $running ? $phase : 'complete',
                $running ? 'cycle.analysis_completed' : 'cycle.completed',
                $failureCount > 0 ? 'error' : 'info',
                $running ? 'optimization_cycle_observing' : 'optimization_cycle_completed',
                $running ? '本次分析已完成，任务进入后续发布或观察阶段' : '本次站点检测已完成',
                [
                    'completed' => $terminalCount,
                    'total' => $targetTotal >= 0 ? $targetTotal : null,
                    'issues' => $issueCount,
                    'failures' => $failureCount,
                    'outcome' => $outcome,
                ],
                'cycle:' . $cycleId . ':analysis.completed',
            );
            $this->advanceSchedule($websiteId, $now);
        } catch (\Throwable $throwable) {
            $this->logFailure('complete cycle', $throwable);
        }
    }

    /** @param array<string,mixed> $facts */
    private function append(
        int $websiteId,
        int $cycleId,
        int $runId,
        int $experimentId,
        int $queueId,
        string $pageType,
        string $blockKey,
        string $phase,
        string $eventType,
        string $severity,
        string $messageCode,
        string $messageText,
        array $facts,
        string $idempotencyKey,
        string $durability = SeoOptimizationActivity::DURABILITY_CORE,
    ): bool {
        $key = $this->token($idempotencyKey, '', 160);
        if ($key === '') {
            return false;
        }
        $existing = clone $this->activityModel;
        $existing->clearData()->clearQuery()
            ->where(SeoOptimizationActivity::schema_fields_IDEMPOTENCY_KEY, $key)
            ->find()->fetch();
        if ((int)$existing->getId() > 0) {
            return false;
        }
        $retentionDays = $durability === SeoOptimizationActivity::DURABILITY_PROGRESS ? 14 : 180;
        $occurredAt = $this->timing->now();
        $activity = clone $this->activityModel;
        try {
            $activity->clearData()->clearQuery()->setData([
                SeoOptimizationActivity::schema_fields_WEBSITE_ID => $websiteId,
                SeoOptimizationActivity::schema_fields_CYCLE_ID => $cycleId > 0 ? $cycleId : null,
                SeoOptimizationActivity::schema_fields_RUN_ID => $runId > 0 ? $runId : null,
                SeoOptimizationActivity::schema_fields_EXPERIMENT_ID => $experimentId > 0 ? $experimentId : null,
                SeoOptimizationActivity::schema_fields_QUEUE_ID => $queueId > 0 ? $queueId : null,
                SeoOptimizationActivity::schema_fields_PAGE_TYPE => $pageType,
                SeoOptimizationActivity::schema_fields_BLOCK_KEY => $blockKey,
                SeoOptimizationActivity::schema_fields_PHASE => $this->token($phase, 'complete', 24),
                SeoOptimizationActivity::schema_fields_EVENT_TYPE => $this->token($eventType, 'status.changed', 64),
                SeoOptimizationActivity::schema_fields_SEVERITY => $this->token($severity, 'info', 16),
                SeoOptimizationActivity::schema_fields_MESSAGE_CODE => $this->token($messageCode, 'optimization_status', 96),
                SeoOptimizationActivity::schema_fields_MESSAGE_TEXT => \mb_substr($messageText, 0, 500, 'UTF-8'),
                SeoOptimizationActivity::schema_fields_FACTS_JSON => $this->json($this->safeFacts($facts)),
                SeoOptimizationActivity::schema_fields_IDEMPOTENCY_KEY => $key,
                SeoOptimizationActivity::schema_fields_DURABILITY => $durability,
                SeoOptimizationActivity::schema_fields_OCCURRED_AT => $this->timing->format($occurredAt),
                SeoOptimizationActivity::schema_fields_EXPIRES_AT => $this->timing->format(
                    $occurredAt->modify('+' . $retentionDays . ' days'),
                ),
            ])->save(true);
            return true;
        } catch (\Throwable $throwable) {
            $race = clone $this->activityModel;
            $race->clearData()->clearQuery()
                ->where(SeoOptimizationActivity::schema_fields_IDEMPOTENCY_KEY, $key)
                ->find()->fetch();
            if ((int)$race->getId() > 0) {
                return false;
            }
            throw $throwable;
        }
    }

    private function advanceSchedule(int $websiteId, string $lastAnalysisAt): void
    {
        $schedule = clone $this->scheduleModel;
        $schedule->clearData()->clearQuery()
            ->where(SeoOptimizationScheduleState::schema_fields_WEBSITE_ID, $websiteId)
            ->find()->fetch();
        $interval = \max(60, \min(10080, (int)($schedule->getData(
            SeoOptimizationScheduleState::schema_fields_ANALYSIS_INTERVAL_MINUTES
        ) ?: self::DEFAULT_INTERVAL_MINUTES)));
        $schedule->setData([
            SeoOptimizationScheduleState::schema_fields_WEBSITE_ID => $websiteId,
            SeoOptimizationScheduleState::schema_fields_ANALYSIS_INTERVAL_MINUTES => $interval,
            SeoOptimizationScheduleState::schema_fields_LAST_ANALYSIS_AT => $lastAnalysisAt,
            SeoOptimizationScheduleState::schema_fields_NEXT_ANALYSIS_AT => $this->timing->format(
                (new \DateTimeImmutable($lastAnalysisAt, new \DateTimeZone('UTC')))
                    ->modify('+' . $interval . ' minutes'),
            ),
        ])->save(true);
    }

    private function cycleById(int $cycleId): SeoOptimizationCycle
    {
        $cycle = clone $this->cycleModel;
        $cycle->clearData()->clearQuery()
            ->where(SeoOptimizationCycle::schema_fields_ID, $cycleId)
            ->find()->fetch();
        return $cycle;
    }

    /** @return array{phase:string,outcome:string,event_type:string,severity:string,message:string,terminal:bool,issue:bool,failure:bool} */
    private function statusState(string $status): array
    {
        $status = \strtolower(\trim($status));
        return match ($status) {
            'insufficient_sample' => $this->state('sample_gate', 'sample_insufficient', 'sample.rejected', 'warning', '数据样本尚未达到自动优化门槛', true, true, false),
            'evidence_unavailable' => $this->state('sample_gate', 'evidence_unavailable', 'evidence.unavailable', 'warning', '访问或搜索证据暂不可用，未创建实验', true, true, false),
            'confidence_rejected' => $this->state('decision', 'no_issue', 'suggestion.rejected', 'info', 'AI 建议未达到置信度门槛，未执行修改', true, false, false),
            'shadow_ready' => $this->state('decision', 'shadow_ready', 'suggestion.ready', 'info', 'AI 已生成影子建议，未修改站点', true, true, false),
            'draft_ready' => $this->state('apply', 'draft_ready', 'candidate.applied', 'success', '候选内容已写入 plan_json 草稿', true, true, false),
            'publish_pending', 'finalize_pending', 'rollback_pending' => $this->state('publish', '', 'publish.queued', 'info', '候选已进入发布队列', false, true, false),
            'publishing' => $this->state('publish', '', 'publish.progress', 'info', '已发布页面正在重新物化', false, true, false),
            'published' => $this->state('publish', '', 'publish.completed', 'success', '候选内容已完成发布', false, true, false),
            'evaluating' => $this->state('observe', '', 'experiment.observing', 'info', '候选已发布并进入观察窗口', false, true, false),
            'kept' => $this->state('complete', 'kept', 'experiment.kept', 'success', '候选达到指标要求并已保留', true, true, false),
            'rolled_back', 'publish_failed_rolled_back', 'experiment_failed_rolled_back' => $this->state('rollback', 'rolled_back', 'rollback.completed', 'warning', '候选未达要求，已按 checkpoint 精确回滚', true, true, false),
            'stale' => $this->state('apply', 'stale', 'revision.conflict', 'warning', '检测到人工新版本，自动任务已过期', true, true, false),
            'manual_intervention' => $this->state('rollback', 'manual_intervention', 'manual.required', 'error', '版本已变化，自动回滚停止并等待人工处理', true, true, true),
            'owner_busy' => $this->state('sample_gate', 'sample_insufficient', 'owner.busy', 'info', '该页面或区块已有实验，当前检测已跳过', true, false, false),
            'failed', 'apply_failed' => $this->state(
                $status === 'apply_failed' ? 'apply' : 'complete',
                'failed',
                'task.failed',
                'error',
                $status === 'apply_failed' ? '自动修改失败，站点内容保持不变' : '本次目标检测失败',
                true,
                true,
                true,
            ),
            default => $this->state('complete', 'no_issue', 'task.completed', 'info', '目标检测完成，未执行站点修改', true, false, false),
        };
    }

    /** @return array{phase:string,outcome:string,event_type:string,severity:string,message:string,terminal:bool,issue:bool,failure:bool} */
    private function state(
        string $phase,
        string $outcome,
        string $eventType,
        string $severity,
        string $message,
        bool $terminal,
        bool $issue,
        bool $failure,
    ): array {
        return \compact('phase', 'outcome', 'eventType', 'severity', 'message', 'terminal', 'issue', 'failure')
            + ['event_type' => $eventType];
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function safeFacts(array $facts): array
    {
        $safe = [];
        foreach ($facts as $key => $value) {
            $key = $this->token((string)$key, '', 64);
            if ($key === '' || \preg_match('/(?:email|phone|mobile|user|ip|url|query|html|prompt|owner|plan_json|cookie|token)/i', $key)) {
                continue;
            }
            if (\is_bool($value) || \is_int($value) || \is_float($value) || $value === null) {
                $safe[$key] = $value;
                continue;
            }
            if (\is_string($value)) {
                $safe[$key] = \mb_substr($value, 0, 500, 'UTF-8');
            }
        }
        return $safe;
    }

    private function canonicalRequestKey(int $websiteId, string $requestKey): string
    {
        $requestKey = $this->token($requestKey, $this->timing->now()->format('YmdH'), 120);
        $prefix = 'scan:' . $websiteId . ':';
        if (!\str_starts_with($requestKey, $prefix)) {
            $requestKey = $prefix . $requestKey;
        }
        return \strlen($requestKey) <= 160
            ? $requestKey
            : $prefix . \substr(\hash('sha256', $requestKey), 0, 64);
    }

    private function token(string $value, string $fallback, int $length): string
    {
        $value = \trim($value);
        if ($value === '' || \preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) !== 1) {
            return $fallback;
        }
        return \substr($value, 0, $length);
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return (string)\json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    private function logFailure(string $operation, \Throwable $throwable): void
    {
        if (\function_exists('w_log_error')) {
            \w_log_error('[Weline_Seo] optimization telemetry ' . $operation . ' failed: ' . $throwable->getMessage());
        }
    }
}
