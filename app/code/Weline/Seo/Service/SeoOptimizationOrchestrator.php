<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Seo\Interface\OptimizationTargetAdapterInterface;
use Weline\Seo\Model\SeoOptimizationExperiment;
use Weline\Seo\Model\SeoOptimizationPolicy;
use Weline\Seo\Model\SeoOptimizationRun;

/** Runs one idempotent discovery/analyze/apply cycle. */
final class SeoOptimizationOrchestrator
{
    public function __construct(
        private readonly OptimizationPolicyService $policyService,
        private readonly OptimizationTargetRegistry $targetRegistry,
        private readonly OptimizationEvidenceService $evidenceService,
        private readonly EventPerformanceAnalysisService $analysisService,
        private readonly SeoOptimizationQueueService $queueService,
        private readonly SeoOptimizationRun $runModel,
        private readonly SeoOptimizationExperiment $experimentModel,
        private readonly SeoOptimizationActivityService $activityService,
        ?OptimizationTiming $timing = null,
        ?OptimizationPublicationState $publicationState = null,
    ) {
        $this->timing = $timing ?? new OptimizationTiming();
        $this->publicationState = $publicationState ?? new OptimizationPublicationState();
    }

    private readonly OptimizationTiming $timing;
    private readonly OptimizationPublicationState $publicationState;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function analyze(array $payload): array
    {
        $websiteId = $this->websiteId($payload['website_id'] ?? null);
        if ($websiteId === null) {
            throw new \InvalidArgumentException('website_id must be non-negative.');
        }
        $policy = $this->policyService->get($websiteId);
        if ((string)$policy['mode'] === SeoOptimizationPolicy::MODE_OFF) {
            return ['status' => 'off', 'website_id' => $websiteId, 'results' => []];
        }
        $adapterCode = \trim((string)($payload['adapter'] ?? 'pagebuilder_ai_site'));
        $adapter = $this->targetRegistry->get($adapterCode);
        if (!$adapter instanceof OptimizationTargetAdapterInterface || !$adapter->supports($websiteId)) {
            throw new \RuntimeException('Optimization target adapter is unavailable.');
        }
        $targets = $this->selectTargets($adapter, $websiteId, $payload['target'] ?? []);
        $requestKey = \trim((string)($payload['request_key'] ?? $this->timing->now()->format('Ymd')));
        $limitedTargets = \array_slice($targets, 0, 200);
        $cycleId = $this->activityService->beginCycle(
            $websiteId,
            $requestKey,
            (string)($payload['trigger_source'] ?? 'scheduler'),
            \count($limitedTargets),
            (int)($payload['queue_id'] ?? 0),
        );
        $results = [];
        foreach ($limitedTargets as $target) {
            try {
                $result = $this->analyzeTarget($adapter, $websiteId, $policy, $target, $requestKey);
            } catch (\Throwable $throwable) {
                $result = [
                    'status' => 'failed',
                    'page_type' => (string)($target['page_type'] ?? ''),
                    'block_key' => (string)($target['block_key'] ?? ''),
                    'reason' => 'target_analysis_failed',
                ];
            }
            $results[] = $result;
            $this->activityService->recordResult(
                $cycleId,
                $websiteId,
                $target,
                $result,
                \count($results),
                \count($limitedTargets),
            );
        }
        $this->activityService->completeCycle($cycleId, $websiteId, $results, \count($limitedTargets));

        return [
            'status' => 'completed',
            'website_id' => $websiteId,
            'mode' => (string)$policy['mode'],
            'target_count' => \count($targets),
            'results' => $results,
        ];
    }

    /**
     * @param array<string,mixed> $policy
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function analyzeTarget(
        OptimizationTargetAdapterInterface $adapter,
        int $websiteId,
        array $policy,
        array $target,
        string $requestKey,
    ): array {
        $snapshot = $adapter->snapshot($websiteId, $target);
        $snapshot = $this->reconcileTerminalCheckpoint($adapter, $websiteId, $snapshot);
        $pageType = (string)($snapshot['page_type'] ?? '');
        $blockKey = (string)($snapshot['block_key'] ?? '');
        $revision = (int)($snapshot['revision'] ?? -1);
        $fingerprint = (string)($snapshot['content_fingerprint'] ?? '');
        if ($pageType === '' || $revision < 0 || \preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \UnexpectedValueException('Optimization target snapshot is invalid.');
        }
        $runKey = 'run_' . \substr(\hash('sha256', \implode('|', [
            $websiteId,
            $adapter->getCode(),
            $pageType,
            $blockKey,
            $revision,
            $fingerprint,
            $requestKey,
        ])), 0, 64);
        $existing = $this->runByKey($runKey);
        if ($existing !== []) {
            return [
                'status' => (string)($existing[SeoOptimizationRun::schema_fields_STATUS] ?? 'idempotent'),
                'run_id' => (int)($existing[SeoOptimizationRun::schema_fields_ID] ?? 0),
                'run_key' => $runKey,
                'idempotent' => true,
            ];
        }

        $days = $blockKey === ''
            ? (int)($policy['seo_baseline_days'] ?? 28)
            : (int)($policy['content_baseline_days'] ?? 14);
        $analysisWindow = $this->timing->analysisWindow($days);
        $startDate = $analysisWindow['start'];
        $endDate = $analysisWindow['end'];
        $run = $this->newRun($runKey, $websiteId, $adapter->getCode(), $snapshot, $startDate, $endDate);

        if (!$this->ownerAvailable($websiteId, $pageType, $blockKey)
            || (\is_array($snapshot['optimization'] ?? null) && $snapshot['optimization'] !== [])
        ) {
            $this->finishRun($run, 'owner_busy', [], [], 'owner_experiment_active', 'Owner already has an experiment or is cooling down.');
            return $this->runResult($run, 'owner_busy', $runKey);
        }

        try {
            $evidence = $this->evidenceService->evidence($websiteId, $snapshot, $policy, $startDate, $endDate);
        } catch (\Throwable $throwable) {
            $this->finishRun($run, 'evidence_unavailable', [], [], 'evidence_unavailable', $throwable->getMessage());
            return $this->runResult($run, 'evidence_unavailable', $runKey, 'evidence_unavailable');
        }
        $evidence['owner'] = $this->ownerEvidence($snapshot);
        if (!$this->evidenceService->sampleEligible($snapshot, $policy, $evidence)) {
            $this->finishRun($run, 'insufficient_sample', $evidence);
            return $this->runResult($run, 'insufficient_sample', $runKey);
        }

        try {
            $recommendation = $this->analysisService->recommend($evidence, $this->aiTarget($snapshot));
        } catch (\Throwable $throwable) {
            $this->finishRun($run, 'failed', $evidence, [], 'analysis_failed', $throwable->getMessage());
            return $this->runResult($run, 'failed', $runKey, 'analysis_failed');
        }
        if ((float)$recommendation['confidence'] < (float)($policy['min_confidence'] ?? 0.8)) {
            $this->finishRun($run, 'confidence_rejected', $evidence, $recommendation);
            return $this->runResult($run, 'confidence_rejected', $runKey);
        }
        if ((string)$policy['mode'] === SeoOptimizationPolicy::MODE_SHADOW) {
            $this->finishRun($run, 'shadow_ready', $evidence, $recommendation);
            return $this->runResult($run, 'shadow_ready', $runKey);
        }
        if ((string)$policy['mode'] === SeoOptimizationPolicy::MODE_AUTO_PUBLISH
            && empty($policy['standing_authorized'])
        ) {
            $this->finishRun(
                $run,
                'failed',
                $evidence,
                $recommendation,
                'standing_authorization_required',
                'auto_publish is not allowed without standing authorization.'
            );
            return $this->runResult($run, 'failed', $runKey, 'standing_authorization_required');
        }

        $metricNames = \array_values(\array_unique(\array_merge(
            [(string)$recommendation['primary_metric']],
            \array_map('strval', $recommendation['guardrails'])
        )));
        try {
            $baselineMetrics = $this->evidenceService->metrics(
                $websiteId,
                $snapshot,
                $metricNames,
                $startDate,
                $endDate,
            );
        } catch (\Throwable $throwable) {
            $reason = \in_array(
                $throwable->getMessage(),
                ['visitor_evidence_unavailable', 'search_evidence_unavailable'],
                true,
            ) ? 'evidence_unavailable' : 'metric_evidence_failed';
            $status = $reason === 'evidence_unavailable' ? 'evidence_unavailable' : 'failed';
            $this->finishRun($run, $status, $evidence, $recommendation, $reason, $throwable->getMessage());
            return $this->runResult($run, $status, $runKey, $reason);
        }

        $observationWindow = $this->timing->observationWindow(
            (int)($policy['evaluation_min_days'] ?? 7),
            (int)($policy['evaluation_max_days'] ?? 28),
        );
        $expiresAt = $observationWindow['expires_at'];
        $idempotencyKey = 'apply_' . \substr(\hash('sha256', $runKey . '|' . $revision), 0, 64);
        try {
            $apply = $adapter->apply([
                'website_id' => $websiteId,
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'expected_revision' => $revision,
                'content_fingerprint' => $fingerprint,
                'analysis_id' => $runKey,
                'allowed_paths' => $recommendation['allowed_paths'],
                'instruction' => (string)$recommendation['instruction'],
                'idempotency_key' => $idempotencyKey,
                'expires_at' => $expiresAt,
            ]);
        } catch (\Throwable $throwable) {
            $this->finishRun($run, 'apply_failed', $evidence, $recommendation, 'apply_exception', $throwable->getMessage());
            return $this->runResult($run, 'apply_failed', $runKey, 'apply_exception');
        }
        if (empty($apply['success']) || empty($apply['applied'])) {
            $status = \in_array((string)($apply['reason'] ?? ''), ['revision_conflict', 'fingerprint_conflict'], true)
                ? 'stale'
                : 'apply_failed';
            $this->finishRun($run, $status, $evidence, $recommendation, (string)($apply['reason'] ?? 'apply_failed'));
            if ($status === 'stale') {
                $this->enqueueReanalysis($websiteId, $adapter->getCode(), $snapshot, $runKey);
            }
            return $this->runResult($run, $status, $runKey, (string)($apply['reason'] ?? 'apply_failed'));
        }

        if ((string)$policy['mode'] === SeoOptimizationPolicy::MODE_AUTO_DRAFT) {
            $evidence['automation'] = [
                'mode' => SeoOptimizationPolicy::MODE_AUTO_DRAFT,
                'status' => 'draft_ready',
                'candidate_revision' => (int)($apply['revision'] ?? 0),
                'candidate_fingerprint' => (string)($apply['candidate_fingerprint'] ?? ''),
                'change_summary' => \is_array($apply['data']['change_summary'] ?? null) ? $apply['data']['change_summary'] : [],
            ];
            $this->finishRun($run, 'draft_ready', $evidence, $recommendation);

            return [
                'status' => 'draft_ready',
                'run_id' => (int)$run->getId(),
                'run_key' => $runKey,
                'revision' => (int)($apply['revision'] ?? 0),
                'candidate_fingerprint' => (string)($apply['candidate_fingerprint'] ?? ''),
            ];
        }

        $publish = [];
        if ((string)$policy['mode'] === SeoOptimizationPolicy::MODE_AUTO_PUBLISH) {
            $publish = $this->safePublish($adapter, [
                'website_id' => $websiteId,
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'analysis_id' => $runKey,
                'idempotency_key' => $idempotencyKey,
                'standing_authorized' => !empty($policy['standing_authorized']),
                'action' => 'candidate',
            ]);
            if (empty($publish['success'])) {
                $rollback = $this->safeRollback($adapter, [
                    'website_id' => $websiteId,
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                    'expected_revision' => (int)($apply['revision'] ?? $revision),
                    'candidate_fingerprint' => (string)($apply['candidate_fingerprint'] ?? ''),
                    'analysis_id' => $runKey,
                    'idempotency_key' => 'publish_failure_' . \substr(\hash('sha256', $runKey), 0, 48),
                ]);
                $this->finishRun(
                    $run,
                    !empty($rollback['success']) ? 'publish_failed_rolled_back' : 'manual_intervention',
                    $evidence,
                    $recommendation,
                    (string)($publish['reason'] ?? 'publish_admission_failed')
                );
                return $this->runResult($run, (string)$run->getData(SeoOptimizationRun::schema_fields_STATUS), $runKey);
            }
        }

        $publishStatus = $this->publicationState->normalize((string)($publish['status'] ?? 'publish_pending'));
        $experimentStatus = $this->publicationState->initialExperimentStatus(
            (string)$policy['mode'],
            $publishStatus,
        );
        try {
            $experiment = $this->createExperiment(
                $run,
                $snapshot,
                $recommendation,
                $baselineMetrics,
                $apply,
                $policy,
                $experimentStatus,
                $observationWindow,
            );
        } catch (\Throwable $throwable) {
            $rollback = $this->safeRollback($adapter, [
                'website_id' => $websiteId,
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'expected_revision' => (int)($apply['revision'] ?? $revision),
                'candidate_fingerprint' => (string)($apply['candidate_fingerprint'] ?? ''),
                'analysis_id' => $runKey,
                'idempotency_key' => 'experiment_failure_' . \substr(\hash('sha256', $runKey), 0, 48),
            ]);
            $this->finishRun(
                $run,
                !empty($rollback['success']) ? 'experiment_failed_rolled_back' : 'manual_intervention',
                $evidence,
                $recommendation,
                'experiment_create_failed',
                $throwable->getMessage()
            );
            return $this->runResult($run, (string)$run->getData(SeoOptimizationRun::schema_fields_STATUS), $runKey);
        }

        $runStatus = $this->publicationState->runStatus($publishStatus);
        $evidence['automation'] = [
            'mode' => SeoOptimizationPolicy::MODE_AUTO_PUBLISH,
            'status' => $publishStatus,
            'queue_id' => (int)($publish['queue_id'] ?? 0),
            'queue_status' => (string)($publish['queue_status'] ?? ''),
            'candidate_revision' => (int)($apply['revision'] ?? 0),
            'candidate_fingerprint' => (string)($apply['candidate_fingerprint'] ?? ''),
            'experiment_status' => $experimentStatus,
            'change_summary' => \is_array($apply['data']['change_summary'] ?? null) ? $apply['data']['change_summary'] : [],
        ];
        $this->finishRun($run, $runStatus, $evidence, $recommendation);

        return [
            'status' => $runStatus,
            'publish_status' => $publishStatus,
            'publish_queue_id' => (int)($publish['queue_id'] ?? 0),
            'run_id' => (int)$run->getId(),
            'run_key' => $runKey,
            'experiment_id' => (int)$experiment->getId(),
            'experiment_key' => (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_EXPERIMENT_KEY),
            'revision' => (int)($apply['revision'] ?? 0),
        ];
    }

    /** @param mixed $requested @return list<array<string,mixed>> */
    private function selectTargets(OptimizationTargetAdapterInterface $adapter, int $websiteId, mixed $requested): array
    {
        $targets = $adapter->targets($websiteId);
        if (!\is_array($requested) || $requested === [] || \array_is_list($requested)) {
            return \array_values(\array_filter($targets, 'is_array'));
        }
        $pageType = \trim((string)($requested['page_type'] ?? ''));
        $blockKey = \trim((string)($requested['block_key'] ?? ''));
        return \array_values(\array_filter($targets, static fn(mixed $target): bool =>
            \is_array($target)
            && (string)($target['page_type'] ?? '') === $pageType
            && (string)($target['block_key'] ?? '') === $blockKey
        ));
    }

    /** @param array<string,mixed> $snapshot */
    private function newRun(
        string $runKey,
        int $websiteId,
        string $adapter,
        array $snapshot,
        string $windowStart,
        string $windowEnd,
    ): SeoOptimizationRun {
        $run = clone $this->runModel;
        $run->clearData()->clearQuery()->setData([
            SeoOptimizationRun::schema_fields_RUN_KEY => $runKey,
            SeoOptimizationRun::schema_fields_WEBSITE_ID => $websiteId,
            SeoOptimizationRun::schema_fields_ADAPTER => $adapter,
            SeoOptimizationRun::schema_fields_PAGE_TYPE => (string)$snapshot['page_type'],
            SeoOptimizationRun::schema_fields_BLOCK_KEY => (string)($snapshot['block_key'] ?? ''),
            SeoOptimizationRun::schema_fields_SOURCE_REVISION => (int)$snapshot['revision'],
            SeoOptimizationRun::schema_fields_SOURCE_FINGERPRINT => (string)$snapshot['content_fingerprint'],
            SeoOptimizationRun::schema_fields_STATUS => 'analyzing',
            SeoOptimizationRun::schema_fields_WINDOW_START => $windowStart,
            SeoOptimizationRun::schema_fields_WINDOW_END => $windowEnd,
        ])->save(true);
        return $run;
    }

    /** @param array<string,mixed> $evidence @param array<string,mixed> $recommendation */
    private function finishRun(
        SeoOptimizationRun $run,
        string $status,
        array $evidence = [],
        array $recommendation = [],
        string $errorCode = '',
        string $errorMessage = '',
    ): void {
        $run->setData(SeoOptimizationRun::schema_fields_STATUS, $status)
            ->setData(SeoOptimizationRun::schema_fields_EVIDENCE_JSON, $this->json($evidence))
            ->setData(SeoOptimizationRun::schema_fields_RECOMMENDATION_JSON, $this->json($recommendation))
            ->setData(SeoOptimizationRun::schema_fields_ERROR_CODE, \substr($errorCode, 0, 80))
            ->setData(SeoOptimizationRun::schema_fields_ERROR_MESSAGE, \substr($errorMessage, 0, 2000))
            ->save();
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $recommendation
     * @param array<string,array<string,mixed>> $baselineMetrics
     * @param array<string,mixed> $apply
     * @param array<string,mixed> $policy
     */
    private function createExperiment(
        SeoOptimizationRun $run,
        array $snapshot,
        array $recommendation,
        array $baselineMetrics,
        array $apply,
        array $policy,
        string $status,
        array $observationWindow,
    ): SeoOptimizationExperiment {
        $experimentKey = 'exp_' . \substr(\hash('sha256', (string)$run->getData(SeoOptimizationRun::schema_fields_RUN_KEY)), 0, 64);
        $appliedAt = (string)$observationWindow['applied_at'];
        $experiment = clone $this->experimentModel;
        $experiment->clearData()->clearQuery()->setData([
            SeoOptimizationExperiment::schema_fields_EXPERIMENT_KEY => $experimentKey,
            SeoOptimizationExperiment::schema_fields_RUN_ID => (int)$run->getId(),
            SeoOptimizationExperiment::schema_fields_WEBSITE_ID => (int)$run->getData(SeoOptimizationRun::schema_fields_WEBSITE_ID),
            SeoOptimizationExperiment::schema_fields_ADAPTER => (string)$run->getData(SeoOptimizationRun::schema_fields_ADAPTER),
            SeoOptimizationExperiment::schema_fields_PAGE_TYPE => (string)$snapshot['page_type'],
            SeoOptimizationExperiment::schema_fields_BLOCK_KEY => (string)($snapshot['block_key'] ?? ''),
            SeoOptimizationExperiment::schema_fields_BASE_REVISION => (int)$snapshot['revision'],
            SeoOptimizationExperiment::schema_fields_CANDIDATE_REVISION => (int)($apply['revision'] ?? 0),
            SeoOptimizationExperiment::schema_fields_BASE_FINGERPRINT => (string)$snapshot['content_fingerprint'],
            SeoOptimizationExperiment::schema_fields_CANDIDATE_FINGERPRINT => (string)($apply['candidate_fingerprint'] ?? ''),
            SeoOptimizationExperiment::schema_fields_PRIMARY_METRIC => (string)$recommendation['primary_metric'],
            SeoOptimizationExperiment::schema_fields_GUARDRAILS_JSON => $this->json($recommendation['guardrails']),
            SeoOptimizationExperiment::schema_fields_BASELINE_METRICS_JSON => $this->json($baselineMetrics),
            SeoOptimizationExperiment::schema_fields_STATUS => $status,
            SeoOptimizationExperiment::schema_fields_AUTOMATION_MODE => (string)$policy['mode'],
            SeoOptimizationExperiment::schema_fields_APPLIED_AT => $appliedAt,
            SeoOptimizationExperiment::schema_fields_EVALUATE_AFTER => (string)$observationWindow['evaluate_after'],
            SeoOptimizationExperiment::schema_fields_EXPIRES_AT => (string)$observationWindow['expires_at'],
        ])->save(true);
        return $experiment;
    }

    private function ownerAvailable(int $websiteId, string $pageType, string $blockKey): bool
    {
        foreach ([
            SeoOptimizationExperiment::STATUS_PUBLISH_PENDING,
            SeoOptimizationExperiment::STATUS_FINALIZE_PENDING,
            SeoOptimizationExperiment::STATUS_ROLLBACK_PENDING,
            SeoOptimizationExperiment::STATUS_EVALUATING,
        ] as $status) {
            $active = clone $this->experimentModel;
            $active->clearData()->clearQuery()
                ->where(SeoOptimizationExperiment::schema_fields_WEBSITE_ID, $websiteId)
                ->where(SeoOptimizationExperiment::schema_fields_PAGE_TYPE, $pageType)
                ->where(SeoOptimizationExperiment::schema_fields_BLOCK_KEY, $blockKey)
                ->where(SeoOptimizationExperiment::schema_fields_STATUS, $status)
                ->find()->fetch();
            if ((int)$active->getId() > 0) {
                return false;
            }
        }
        $latest = clone $this->experimentModel;
        $rows = $latest->clearData()->clearQuery()
            ->where(SeoOptimizationExperiment::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SeoOptimizationExperiment::schema_fields_PAGE_TYPE, $pageType)
            ->where(SeoOptimizationExperiment::schema_fields_BLOCK_KEY, $blockKey)
            ->order(SeoOptimizationExperiment::schema_fields_ID, 'DESC')
            ->limit(1)->select()->fetchArray();
        $cooldown = \is_array($rows[0] ?? null)
            ? (string)($rows[0][SeoOptimizationExperiment::schema_fields_COOLDOWN_UNTIL] ?? '')
            : '';
        return $cooldown === '' || !$this->timing->isFuture($cooldown);
    }

    /**
     * A terminal Experiment must never leave its PageBuilder owner permanently
     * busy. The adapter may clear only the matching optimization checkpoint;
     * it must not restore or overwrite content on this reconciliation path.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function reconcileTerminalCheckpoint(
        OptimizationTargetAdapterInterface $adapter,
        int $websiteId,
        array $snapshot,
    ): array {
        $checkpoint = \is_array($snapshot['optimization'] ?? null)
            ? $snapshot['optimization']
            : [];
        $analysisId = \trim((string)($checkpoint['run_id'] ?? ''));
        $candidateFingerprint = \trim((string)($checkpoint['candidate_fingerprint'] ?? ''));
        if ($analysisId === ''
            || \strlen($analysisId) > 96
            || \preg_match('/^[a-f0-9]{64}$/D', $candidateFingerprint) !== 1
        ) {
            return $snapshot;
        }

        $run = clone $this->runModel;
        $run->clearData()->clearQuery()
            ->where(SeoOptimizationRun::schema_fields_RUN_KEY, $analysisId)
            ->find()->fetch();
        if ((int)$run->getId() < 1) {
            return $snapshot;
        }

        $experiment = clone $this->experimentModel;
        $experiment->clearData()->clearQuery()
            ->where(SeoOptimizationExperiment::schema_fields_RUN_ID, (int)$run->getId())
            ->find()->fetch();
        if ((int)$experiment->getId() < 1
            || !\in_array(
                (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_STATUS),
                [
                    SeoOptimizationExperiment::STATUS_KEPT,
                    SeoOptimizationExperiment::STATUS_ROLLED_BACK,
                    SeoOptimizationExperiment::STATUS_MANUAL_INTERVENTION,
                ],
                true,
            )
        ) {
            return $snapshot;
        }

        try {
            $release = $adapter->finalize([
                'website_id' => $websiteId,
                'page_type' => (string)($snapshot['page_type'] ?? ''),
                'block_key' => (string)($snapshot['block_key'] ?? ''),
                'expected_revision' => (int)($snapshot['revision'] ?? -1),
                'candidate_fingerprint' => $candidateFingerprint,
                'analysis_id' => $analysisId,
            ]);
            if (!empty($release['success']) || !empty($release['checkpoint_released'])) {
                return $adapter->snapshot($websiteId, [
                    'page_type' => (string)($snapshot['page_type'] ?? ''),
                    'block_key' => (string)($snapshot['block_key'] ?? ''),
                ]);
            }
        } catch (\Throwable) {
            // Fail closed: the unchanged checkpoint keeps this owner busy.
        }

        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function runByKey(string $runKey): array
    {
        $run = clone $this->runModel;
        $run->clearData()->clearQuery()->where(SeoOptimizationRun::schema_fields_RUN_KEY, $runKey)->find()->fetch();
        return (int)$run->getId() > 0 ? (array)$run->getData() : [];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function ownerEvidence(array $snapshot): array
    {
        return [
            'revision' => (int)($snapshot['revision'] ?? 0),
            'content_fingerprint' => (string)($snapshot['content_fingerprint'] ?? ''),
            'editable_paths' => \array_values(\array_map('strval', (array)($snapshot['allowed_paths'] ?? []))),
            'current_values' => \is_array($snapshot['current_values'] ?? null) ? $snapshot['current_values'] : [],
        ];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function aiTarget(array $snapshot): array
    {
        return [
            'page_type' => (string)($snapshot['page_type'] ?? ''),
            'block_key' => (string)($snapshot['block_key'] ?? ''),
            'target_type' => (string)($snapshot['target_type'] ?? ''),
            'revision' => (int)($snapshot['revision'] ?? 0),
            'content_fingerprint' => (string)($snapshot['content_fingerprint'] ?? ''),
            'allowed_paths' => \array_values(\array_map('strval', (array)($snapshot['allowed_paths'] ?? []))),
            'current_values' => \is_array($snapshot['current_values'] ?? null) ? $snapshot['current_values'] : [],
            'target_event' => (string)($snapshot['target_event'] ?? ''),
            'primary_metric' => (string)($snapshot['primary_metric'] ?? ''),
            'content_locale' => (string)($snapshot['content_locale'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function safePublish(OptimizationTargetAdapterInterface $adapter, array $request): array
    {
        try {
            return $adapter->admitPublish($request);
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'reason' => 'publish_admission_exception',
                'error' => \substr($throwable->getMessage(), 0, 500),
            ];
        }
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function safeRollback(OptimizationTargetAdapterInterface $adapter, array $request): array
    {
        try {
            return $adapter->rollback($request);
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'reason' => 'rollback_exception',
                'error' => \substr($throwable->getMessage(), 0, 500),
            ];
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function enqueueReanalysis(int $websiteId, string $adapter, array $snapshot, string $runKey): void
    {
        try {
            $this->queueService->enqueueAnalyze($websiteId, $adapter, [
                'page_type' => (string)($snapshot['page_type'] ?? ''),
                'block_key' => (string)($snapshot['block_key'] ?? ''),
            ], 'stale-' . $runKey);
        } catch (\Throwable $throwable) {
            \w_log_error('[Weline_Seo] stale optimization reanalysis admission failed: ' . $throwable->getMessage());
        }
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

    private function json(array $value): string
    {
        return (string)(\json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function runResult(SeoOptimizationRun $run, string $status, string $runKey, string $reason = ''): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'run_id' => (int)$run->getId(),
            'run_key' => $runKey,
            'page_type' => (string)$run->getData(SeoOptimizationRun::schema_fields_PAGE_TYPE),
            'block_key' => (string)$run->getData(SeoOptimizationRun::schema_fields_BLOCK_KEY),
        ];
    }
}
