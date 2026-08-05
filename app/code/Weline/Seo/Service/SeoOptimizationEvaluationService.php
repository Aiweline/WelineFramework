<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Seo\Interface\OptimizationTargetAdapterInterface;
use Weline\Seo\Model\SeoOptimizationExperiment;
use Weline\Seo\Model\SeoOptimizationPolicy;
use Weline\Seo\Model\SeoOptimizationRun;

/** Evaluates a continuous before/after experiment and keeps or CAS-rolls it back. */
final class SeoOptimizationEvaluationService
{
    public function __construct(
        private readonly OptimizationPolicyService $policyService,
        private readonly OptimizationTargetRegistry $targetRegistry,
        private readonly OptimizationEvidenceService $evidenceService,
        private readonly SeoOptimizationExperiment $experimentModel,
        private readonly SeoOptimizationRun $runModel,
        private readonly SeoOptimizationActivityService $activityService,
        ?OptimizationTiming $timing = null,
        ?OptimizationPublicationState $publicationState = null,
        ?OptimizationEvaluationDecision $decision = null,
    ) {
        $this->timing = $timing ?? new OptimizationTiming();
        $this->publicationState = $publicationState ?? new OptimizationPublicationState();
        $this->decision = $decision ?? new OptimizationEvaluationDecision();
    }

    private readonly OptimizationTiming $timing;
    private readonly OptimizationPublicationState $publicationState;
    private readonly OptimizationEvaluationDecision $decision;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function evaluate(array $payload): array
    {
        $experiment = $this->loadExperiment($payload);
        if (!$experiment instanceof SeoOptimizationExperiment) {
            throw new \InvalidArgumentException('Optimization experiment was not found.');
        }
        $experimentId = (int)$experiment->getId();
        $status = (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_STATUS);
        $pendingAction = $this->publicationState->pendingAction($status);
        if ($pendingAction !== null) {
            return $this->awaitPublication($experiment, $pendingAction);
        }
        if ($status !== SeoOptimizationExperiment::STATUS_EVALUATING) {
            return ['status' => $status, 'experiment_id' => $experimentId, 'idempotent' => true];
        }
        $reconciledPublication = $this->reconcileEvaluatingCandidatePublication($experiment);
        if ($reconciledPublication !== null) {
            return $reconciledPublication;
        }
        $evaluateAfter = (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_EVALUATE_AFTER);
        if ($evaluateAfter !== '' && $this->timing->isFuture($evaluateAfter)) {
            return ['status' => 'waiting', 'experiment_id' => $experimentId, 'evaluate_after' => $evaluateAfter];
        }

        $websiteId = (int)$experiment->getData(SeoOptimizationExperiment::schema_fields_WEBSITE_ID);
        $pageType = (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_PAGE_TYPE);
        $blockKey = (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_BLOCK_KEY);
        $adapter = $this->targetRegistry->get((string)$experiment->getData(SeoOptimizationExperiment::schema_fields_ADAPTER));
        if (!$adapter instanceof OptimizationTargetAdapterInterface) {
            return $this->manual($experiment, 'target_adapter_unavailable');
        }
        try {
            $current = $adapter->snapshot($websiteId, ['page_type' => $pageType, 'block_key' => $blockKey]);
        } catch (\Throwable $throwable) {
            return $this->retry($experiment, 'target_snapshot_failed', $throwable->getMessage());
        }
        $candidateFingerprint = (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_CANDIDATE_FINGERPRINT);
        $candidateRevision = (int)$experiment->getData(SeoOptimizationExperiment::schema_fields_CANDIDATE_REVISION);
        $currentRevision = (int)($current['revision'] ?? -1);
        if ($candidateFingerprint === ''
            || $candidateRevision < 0
            || $currentRevision < $candidateRevision
            || !\hash_equals($candidateFingerprint, (string)($current['content_fingerprint'] ?? ''))
        ) {
            return $this->manual($experiment, 'candidate_revision_or_fingerprint_changed');
        }

        $run = $this->loadRun((int)$experiment->getData(SeoOptimizationExperiment::schema_fields_RUN_ID));
        if (!$run instanceof SeoOptimizationRun) {
            return $this->manual($experiment, 'analysis_run_missing');
        }
        $analysisId = (string)$run->getData(SeoOptimizationRun::schema_fields_RUN_KEY);
        $guardrails = $this->stringList((string)$experiment->getData(SeoOptimizationExperiment::schema_fields_GUARDRAILS_JSON));
        $primaryMetric = (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_PRIMARY_METRIC);
        $metricNames = \array_values(\array_unique(\array_merge([$primaryMetric], $guardrails)));
        $metricTarget = $current;
        $metricTarget['revision'] = $currentRevision;
        $metricTarget['content_fingerprint'] = $candidateFingerprint;
        $metricTarget['primary_metric'] = $primaryMetric;
        $startDate = (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_APPLIED_AT);
        $endDate = $this->timing->format($this->timing->now());
        try {
            $candidateMetrics = $this->evidenceService->metrics(
                $websiteId,
                $metricTarget,
                $metricNames,
                $startDate,
                $endDate,
            );
        } catch (\Throwable $throwable) {
            return $this->retry($experiment, 'candidate_evidence_failed', $throwable->getMessage());
        }
        $baselineMetrics = $this->metricMap((string)$experiment->getData(SeoOptimizationExperiment::schema_fields_BASELINE_METRICS_JSON));
        $policy = $this->policyService->get($websiteId);
        $assessment = $this->assess($primaryMetric, $guardrails, $baselineMetrics, $candidateMetrics, $policy, $blockKey !== '');
        $candidateMetrics['_assessment'] = $assessment;
        $experiment->setData(
            SeoOptimizationExperiment::schema_fields_CANDIDATE_METRICS_JSON,
            $this->json($candidateMetrics)
        )->save();

        $expired = $this->timing->isExpired(
            (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_EXPIRES_AT)
        );
        if (!empty($assessment['keep'])) {
            try {
                $finalized = $adapter->finalize([
                    'website_id' => $websiteId,
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                    'expected_revision' => $currentRevision,
                    'candidate_fingerprint' => $candidateFingerprint,
                    'analysis_id' => $analysisId,
                    'idempotency_key' => 'keep_' . $experimentId,
                ]);
            } catch (\Throwable $throwable) {
                return $this->retry($experiment, 'finalize_failed', $throwable->getMessage());
            }
            if (empty($finalized['success'])) {
                return $this->manual($experiment, (string)($finalized['reason'] ?? 'finalize_failed'));
            }
            return $this->settleResolvedPublication(
                $experiment,
                $run,
                $adapter,
                $analysisId,
                'finalize',
                $policy,
                $assessment,
            );
        }

        $shouldRollback = $this->decision->shouldRollback($assessment, $expired);
        if (!$shouldRollback) {
            return [
                'status' => 'evaluating',
                'experiment_id' => $experimentId,
                'sample_ready' => (bool)($assessment['sample_ready'] ?? false),
                'expires_at' => (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_EXPIRES_AT),
                'assessment' => $assessment,
            ];
        }

        try {
            $rollback = $adapter->rollback([
                'website_id' => $websiteId,
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'expected_revision' => $currentRevision,
                'candidate_fingerprint' => $candidateFingerprint,
                'analysis_id' => $analysisId,
                'idempotency_key' => 'rollback_' . $experimentId,
            ]);
        } catch (\Throwable $throwable) {
            return $this->retry($experiment, 'rollback_failed', $throwable->getMessage());
        }
        if (empty($rollback['success'])) {
            return $this->manual($experiment, (string)($rollback['reason'] ?? 'rollback_failed'));
        }
        return $this->settleResolvedPublication(
            $experiment,
            $run,
            $adapter,
            $analysisId,
            'rollback',
            $policy,
            $assessment,
        );
    }

    /**
     * @param list<string> $guardrails
     * @param array<string,array<string,mixed>> $baseline
     * @param array<string,array<string,mixed>> $candidate
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    private function assess(
        string $primaryMetric,
        array $guardrails,
        array $baseline,
        array $candidate,
        array $policy,
        bool $contentTarget,
    ): array {
        $basePrimary = \is_array($baseline[$primaryMetric] ?? null) ? $baseline[$primaryMetric] : [];
        $candidatePrimary = \is_array($candidate[$primaryMetric] ?? null) ? $candidate[$primaryMetric] : [];
        $before = (float)($basePrimary['value'] ?? 0.0);
        $after = (float)($candidatePrimary['value'] ?? 0.0);
        $uplift = $this->relativeChange($before, $after);
        $zScore = $this->proportionZ($basePrimary, $candidatePrimary);
        $confidence = $this->normalCdf($zScore);
        $sampleThresholdReady = $contentTarget
            ? (int)($candidatePrimary['sample_size'] ?? 0) >= (int)($policy['min_page_views'] ?? 500)
                && (int)($candidatePrimary['numerator'] ?? 0) >= (int)($policy['min_conversions'] ?? 30)
            : (int)($candidatePrimary['denominator'] ?? 0) >= (int)($policy['min_search_impressions'] ?? 1000);
        $sampleReady = !empty($basePrimary['complete']) && !empty($candidatePrimary['complete']) && $sampleThresholdReady;
        $guardrailLimit = -((int)($policy['max_guardrail_regression_bps'] ?? 300) / 10000);
        $guardrailResults = [];
        $guardrailBreached = false;
        $guardrailsSampleReady = true;
        foreach ($guardrails as $guardrail) {
            $base = \is_array($baseline[$guardrail] ?? null) ? $baseline[$guardrail] : [];
            $next = \is_array($candidate[$guardrail] ?? null) ? $candidate[$guardrail] : [];
            $change = $this->relativeChange((float)($base['value'] ?? 0.0), (float)($next['value'] ?? 0.0));
            $hasSamples = !empty($base['complete'])
                && !empty($next['complete'])
                && (int)($base['denominator'] ?? 0) > 0 && (int)($next['denominator'] ?? 0) > 0;
            $guardrailsSampleReady = $guardrailsSampleReady && $hasSamples;
            $breached = $hasSamples && $change < $guardrailLimit;
            $guardrailBreached = $guardrailBreached || $breached;
            $guardrailResults[$guardrail] = [
                'relative_change' => $change,
                'limit' => $guardrailLimit,
                'sampled' => $hasSamples,
                'breached' => $breached,
            ];
        }
        $requiredUplift = (int)($policy['min_uplift_bps'] ?? 500) / 10000;
        $significant = $zScore >= 1.96;

        return [
            'sample_ready' => $sampleReady,
            'primary_metric' => $primaryMetric,
            'primary_relative_uplift' => $uplift,
            'required_relative_uplift' => $requiredUplift,
            'z_score' => $zScore,
            'statistical_confidence' => $confidence,
            'statistically_significant_95' => $significant,
            'primary_worsened' => $after < $before,
            'guardrails' => $guardrailResults,
            'guardrails_sample_ready' => $guardrailsSampleReady,
            'guardrail_breached' => $guardrailBreached,
            'keep' => $sampleReady
                && $guardrailsSampleReady
                && $uplift >= $requiredUplift
                && $significant
                && !$guardrailBreached,
        ];
    }

    /**
     * Polls the idempotent PageBuilder publish admission. Candidate observation starts
     * only when the worker reports the candidate as published.
     *
     * @return array<string,mixed>
     */
    private function awaitPublication(SeoOptimizationExperiment $experiment, string $action): array
    {
        $websiteId = (int)$experiment->getData(SeoOptimizationExperiment::schema_fields_WEBSITE_ID);
        $adapter = $this->targetRegistry->get((string)$experiment->getData(SeoOptimizationExperiment::schema_fields_ADAPTER));
        if (!$adapter instanceof OptimizationTargetAdapterInterface) {
            return $this->manual($experiment, 'target_adapter_unavailable');
        }
        $run = $this->loadRun((int)$experiment->getData(SeoOptimizationExperiment::schema_fields_RUN_ID));
        if (!$run instanceof SeoOptimizationRun) {
            return $this->manual($experiment, 'analysis_run_missing');
        }
        $analysisId = (string)$run->getData(SeoOptimizationRun::schema_fields_RUN_KEY);
        if ($action === 'candidate') {
            return $this->awaitCandidatePublication($experiment, $run, $adapter, $analysisId);
        }

        return $this->settleResolvedPublication(
            $experiment,
            $run,
            $adapter,
            $analysisId,
            $action,
            $this->policyService->get($websiteId),
            $this->storedAssessment($experiment),
        );
    }

    /**
     * An evaluating experiment may only continue when its current candidate
     * still resolves to the same published Queue token. A stale terminal slot
     * is returned to publish_pending so the Scheduler publishes the candidate.
     *
     * @return null|array<string,mixed>
     */
    private function reconcileEvaluatingCandidatePublication(SeoOptimizationExperiment $experiment): ?array
    {
        if ((string)$experiment->getData(SeoOptimizationExperiment::schema_fields_AUTOMATION_MODE)
            !== SeoOptimizationPolicy::MODE_AUTO_PUBLISH
        ) {
            return null;
        }
        $adapter = $this->targetRegistry->get(
            (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_ADAPTER)
        );
        if (!$adapter instanceof OptimizationTargetAdapterInterface) {
            return $this->manual($experiment, 'target_adapter_unavailable');
        }
        $run = $this->loadRun((int)$experiment->getData(SeoOptimizationExperiment::schema_fields_RUN_ID));
        if (!$run instanceof SeoOptimizationRun) {
            return $this->manual($experiment, 'analysis_run_missing');
        }
        $analysisId = (string)$run->getData(SeoOptimizationRun::schema_fields_RUN_KEY);
        try {
            $receipt = $this->admitPublication($adapter, $experiment, $analysisId, 'candidate');
        } catch (\Throwable $throwable) {
            return $this->retry($experiment, 'candidate_publish_reconciliation_failed', $throwable->getMessage());
        }
        $publishStatus = $this->publicationState->normalize((string)($receipt['status'] ?? 'publish_pending'));
        if (empty($receipt['success']) || $this->publicationState->isFailure($publishStatus)) {
            return $this->manual(
                $experiment,
                'candidate_publish_reconciliation_failed',
                (string)($receipt['reason'] ?? ''),
            );
        }
        if ($this->publicationState->isPublished($publishStatus)) {
            return null;
        }

        return $this->markPublicationPending(
                $experiment,
                $run,
                'candidate',
                $publishStatus,
                $receipt,
            );
    }

    /** @return array<string,mixed> */
    private function awaitCandidatePublication(
        SeoOptimizationExperiment $experiment,
        SeoOptimizationRun $run,
        OptimizationTargetAdapterInterface $adapter,
        string $analysisId,
    ): array {
        try {
            $receipt = $this->admitPublication($adapter, $experiment, $analysisId, 'candidate');
        } catch (\Throwable $throwable) {
            return $this->retry($experiment, 'candidate_publish_status_failed', $throwable->getMessage());
        }
        $publishStatus = $this->publicationState->normalize((string)($receipt['status'] ?? 'publish_pending'));
        if (empty($receipt['success']) || $this->publicationState->isFailure($publishStatus)) {
            return $this->rollbackUnpublishedCandidate(
                $experiment,
                $run,
                $adapter,
                $analysisId,
                (string)($receipt['reason'] ?? 'candidate_publish_failed'),
            );
        }
        if (!$this->publicationState->isPublished($publishStatus)) {
            return $this->markPublicationPending($experiment, $run, 'candidate', $publishStatus, $receipt);
        }

        $policy = $this->policyService->get(
            (int)$experiment->getData(SeoOptimizationExperiment::schema_fields_WEBSITE_ID)
        );
        $window = $this->timing->observationWindow(
            (int)($policy['evaluation_min_days'] ?? 7),
            (int)($policy['evaluation_max_days'] ?? 28),
        );
        $queueId = (int)($receipt['queue_id'] ?? 0);
        $this->recordLifecycle($experiment, $run, 'published', $queueId, 'candidate_published');
        $experiment->setData(SeoOptimizationExperiment::schema_fields_STATUS, SeoOptimizationExperiment::STATUS_EVALUATING)
            ->setData(SeoOptimizationExperiment::schema_fields_APPLIED_AT, $window['applied_at'])
            ->setData(SeoOptimizationExperiment::schema_fields_EVALUATE_AFTER, $window['evaluate_after'])
            ->setData(SeoOptimizationExperiment::schema_fields_EXPIRES_AT, $window['expires_at'])
            ->save();
        $run->setData(SeoOptimizationRun::schema_fields_STATUS, SeoOptimizationExperiment::STATUS_EVALUATING)->save();
        $this->recordLifecycle($experiment, $run, SeoOptimizationExperiment::STATUS_EVALUATING, $queueId, 'candidate_published');

        return [
            'status' => 'waiting',
            'experiment_id' => (int)$experiment->getId(),
            'publish_status' => 'published',
            'queue_id' => (int)($receipt['queue_id'] ?? 0),
            'queue_status' => (string)($receipt['queue_status'] ?? ''),
            'evaluate_after' => $window['evaluate_after'],
        ];
    }

    /** @param array<string,mixed> $policy @param array<string,mixed> $assessment @return array<string,mixed> */
    private function settleResolvedPublication(
        SeoOptimizationExperiment $experiment,
        SeoOptimizationRun $run,
        OptimizationTargetAdapterInterface $adapter,
        string $analysisId,
        string $action,
        array $policy,
        array $assessment,
    ): array {
        try {
            $receipt = $this->admitPublication($adapter, $experiment, $analysisId, $action);
        } catch (\Throwable $throwable) {
            return $this->retry($experiment, $action . '_publish_status_failed', $throwable->getMessage());
        }
        $publishStatus = $this->publicationState->normalize((string)($receipt['status'] ?? 'publish_pending'));
        if (empty($receipt['success']) || $this->publicationState->isFailure($publishStatus)) {
            return $this->manual($experiment, $action . '_publish_failed', (string)($receipt['reason'] ?? ''));
        }
        if (!$this->publicationState->isPublished($publishStatus)) {
            return $this->markPublicationPending($experiment, $run, $action, $publishStatus, $receipt);
        }

        $queueId = (int)($receipt['queue_id'] ?? 0);
        $this->recordLifecycle($experiment, $run, 'published', $queueId, $action . '_published');
        return $this->resolve(
            $experiment,
            $run,
            $action === 'rollback'
                ? SeoOptimizationExperiment::STATUS_ROLLED_BACK
                : SeoOptimizationExperiment::STATUS_KEPT,
            $policy,
            $assessment,
            $queueId,
            $action . '_published',
        );
    }

    /**
     * A failed candidate publication must remove only the still-current candidate.
     *
     * @return array<string,mixed>
     */
    private function rollbackUnpublishedCandidate(
        SeoOptimizationExperiment $experiment,
        SeoOptimizationRun $run,
        OptimizationTargetAdapterInterface $adapter,
        string $analysisId,
        string $reason,
    ): array {
        $websiteId = (int)$experiment->getData(SeoOptimizationExperiment::schema_fields_WEBSITE_ID);
        $pageType = (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_PAGE_TYPE);
        $blockKey = (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_BLOCK_KEY);
        $candidateFingerprint = (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_CANDIDATE_FINGERPRINT);
        $candidateRevision = (int)$experiment->getData(SeoOptimizationExperiment::schema_fields_CANDIDATE_REVISION);
        try {
            $current = $adapter->snapshot($websiteId, ['page_type' => $pageType, 'block_key' => $blockKey]);
        } catch (\Throwable $throwable) {
            return $this->retry($experiment, 'candidate_publish_rollback_snapshot_failed', $throwable->getMessage());
        }
        $currentRevision = (int)($current['revision'] ?? -1);
        if ($candidateFingerprint === ''
            || $candidateRevision < 0
            || $currentRevision < $candidateRevision
            || !\hash_equals($candidateFingerprint, (string)($current['content_fingerprint'] ?? ''))
        ) {
            return $this->manual($experiment, 'candidate_publish_failure_revision_or_fingerprint_changed');
        }
        try {
            $rollback = $adapter->rollback([
                'website_id' => $websiteId,
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'expected_revision' => $currentRevision,
                'candidate_fingerprint' => $candidateFingerprint,
                'analysis_id' => $analysisId,
                'idempotency_key' => 'publish_failure_rollback_' . (int)$experiment->getId(),
            ]);
        } catch (\Throwable $throwable) {
            return $this->retry($experiment, 'candidate_publish_rollback_failed', $throwable->getMessage());
        }
        if (empty($rollback['success'])) {
            return $this->manual(
                $experiment,
                (string)($rollback['reason'] ?? 'candidate_publish_rollback_failed'),
            );
        }

        return $this->settleResolvedPublication(
            $experiment,
            $run,
            $adapter,
            $analysisId,
            'rollback',
            $this->policyService->get($websiteId),
            ['publish_failure_reason' => $reason],
        );
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function markPublicationPending(
        SeoOptimizationExperiment $experiment,
        SeoOptimizationRun $run,
        string $action,
        string $publishStatus,
        array $receipt,
    ): array {
        $pendingStatus = $this->publicationState->pendingStatusForAction($action);
        $experiment->setData(SeoOptimizationExperiment::schema_fields_STATUS, $pendingStatus)->save();
        $run->setData(SeoOptimizationRun::schema_fields_STATUS, $publishStatus)->save();
        $this->recordLifecycle(
            $experiment,
            $run,
            $publishStatus,
            (int)($receipt['queue_id'] ?? 0),
            $action . '_publish_pending',
        );

        return [
            'status' => $pendingStatus,
            'experiment_id' => (int)$experiment->getId(),
            'publish_status' => $publishStatus,
            'queue_id' => (int)($receipt['queue_id'] ?? 0),
            'queue_status' => (string)($receipt['queue_status'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function admitPublication(
        OptimizationTargetAdapterInterface $adapter,
        SeoOptimizationExperiment $experiment,
        string $analysisId,
        string $action,
    ): array {
        if ((string)$experiment->getData(SeoOptimizationExperiment::schema_fields_AUTOMATION_MODE)
            !== SeoOptimizationPolicy::MODE_AUTO_PUBLISH
        ) {
            return ['success' => true, 'status' => 'published'];
        }

        return $adapter->admitPublish([
            'website_id' => (int)$experiment->getData(SeoOptimizationExperiment::schema_fields_WEBSITE_ID),
            'page_type' => (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_PAGE_TYPE),
            'block_key' => (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_BLOCK_KEY),
            'analysis_id' => $analysisId,
            'idempotency_key' => 'publish_' . $action . '_' . (int)$experiment->getId(),
            'standing_authorized' => true,
            'action' => $action,
        ]);
    }

    /** @return array<string,mixed> */
    private function storedAssessment(SeoOptimizationExperiment $experiment): array
    {
        $metrics = $this->metricMap(
            (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_CANDIDATE_METRICS_JSON)
        );
        $assessment = $metrics['_assessment'] ?? [];

        return \is_array($assessment) ? $assessment : [];
    }

    /** @param array<string,mixed> $policy @param array<string,mixed> $assessment @return array<string,mixed> */
    private function resolve(
        SeoOptimizationExperiment $experiment,
        SeoOptimizationRun $run,
        string $status,
        array $policy,
        array $assessment,
        int $queueId = 0,
        string $reason = '',
    ): array {
        $now = $this->timing->format($this->timing->now());
        $cooldownUntil = $this->timing->cooldownUntil((int)($policy['cooldown_days'] ?? 14));
        $experiment->setData(SeoOptimizationExperiment::schema_fields_STATUS, $status)
            ->setData(SeoOptimizationExperiment::schema_fields_RESOLVED_AT, $now)
            ->setData(SeoOptimizationExperiment::schema_fields_COOLDOWN_UNTIL, $cooldownUntil)
            ->save();
        $run->setData(SeoOptimizationRun::schema_fields_STATUS, $status)->save();
        $this->recordLifecycle($experiment, $run, $status, $queueId, $reason);
        return [
            'status' => $status,
            'experiment_id' => (int)$experiment->getId(),
            'cooldown_until' => $cooldownUntil,
            'assessment' => $assessment,
        ];
    }

    /** @return array<string,mixed> */
    private function manual(SeoOptimizationExperiment $experiment, string $reason, string $message = ''): array
    {
        $experiment->setData(SeoOptimizationExperiment::schema_fields_STATUS, SeoOptimizationExperiment::STATUS_MANUAL_INTERVENTION)
            ->setData(SeoOptimizationExperiment::schema_fields_RESOLVED_AT, $this->timing->format($this->timing->now()))
            ->save();
        $run = $this->loadRun((int)$experiment->getData(SeoOptimizationExperiment::schema_fields_RUN_ID));
        if ($run instanceof SeoOptimizationRun) {
            $run->setData(SeoOptimizationRun::schema_fields_STATUS, SeoOptimizationExperiment::STATUS_MANUAL_INTERVENTION)
                ->setData(SeoOptimizationRun::schema_fields_ERROR_CODE, \substr($reason, 0, 80))
                ->setData(SeoOptimizationRun::schema_fields_ERROR_MESSAGE, \substr($message, 0, 2000))
                ->save();
        }
        $this->recordLifecycle($experiment, $run, SeoOptimizationExperiment::STATUS_MANUAL_INTERVENTION, 0, $reason);
        return [
            'status' => SeoOptimizationExperiment::STATUS_MANUAL_INTERVENTION,
            'experiment_id' => (int)$experiment->getId(),
            'reason' => $reason,
        ];
    }

    private function recordLifecycle(
        SeoOptimizationExperiment $experiment,
        ?SeoOptimizationRun $run,
        string $status,
        int $queueId = 0,
        string $reason = '',
    ): void {
        $this->activityService->recordLifecycle(
            (int)$experiment->getData(SeoOptimizationExperiment::schema_fields_WEBSITE_ID),
            $run instanceof SeoOptimizationRun
                ? (int)$run->getId()
                : (int)$experiment->getData(SeoOptimizationExperiment::schema_fields_RUN_ID),
            (int)$experiment->getId(),
            $queueId,
            (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_PAGE_TYPE),
            (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_BLOCK_KEY),
            $status,
            $reason,
        );
    }

    /** @return array<string,mixed> */
    private function retry(SeoOptimizationExperiment $experiment, string $reason, string $message = ''): array
    {
        $status = (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_STATUS);
        $nextStatus = $this->publicationState->isPending($status)
            ? $status
            : SeoOptimizationExperiment::STATUS_EVALUATING;
        $run = $this->loadRun((int)$experiment->getData(SeoOptimizationExperiment::schema_fields_RUN_ID));
        if ($run instanceof SeoOptimizationRun) {
            $run->setData(SeoOptimizationRun::schema_fields_STATUS, $nextStatus)
                ->setData(SeoOptimizationRun::schema_fields_ERROR_CODE, \substr($reason, 0, 80))
                ->setData(SeoOptimizationRun::schema_fields_ERROR_MESSAGE, \substr($message, 0, 2000))
                ->save();
        }
        return [
            'status' => $nextStatus,
            'experiment_id' => (int)$experiment->getId(),
            'reason' => $reason,
            'retryable' => true,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function loadExperiment(array $payload): ?SeoOptimizationExperiment
    {
        $experiment = clone $this->experimentModel;
        $experiment->clearData()->clearQuery();
        $id = (int)($payload['experiment_id'] ?? 0);
        if ($id > 0) {
            $experiment->load($id);
        } else {
            $key = \trim((string)($payload['experiment_key'] ?? ''));
            if ($key === '') {
                return null;
            }
            $experiment->where(SeoOptimizationExperiment::schema_fields_EXPERIMENT_KEY, $key)->find()->fetch();
        }
        if ((int)$experiment->getId() <= 0) {
            return null;
        }
        $expectedKey = \trim((string)($payload['experiment_key'] ?? ''));
        if ($expectedKey !== ''
            && !\hash_equals($expectedKey, (string)$experiment->getData(SeoOptimizationExperiment::schema_fields_EXPERIMENT_KEY))
        ) {
            return null;
        }
        return $experiment;
    }

    private function loadRun(int $runId): ?SeoOptimizationRun
    {
        if ($runId <= 0) {
            return null;
        }
        $run = clone $this->runModel;
        $run->clearData()->load($runId);
        return (int)$run->getId() > 0 ? $run : null;
    }

    /** @return list<string> */
    private function stringList(string $json): array
    {
        $decoded = \json_decode($json, true);
        return \is_array($decoded)
            ? \array_values(\array_unique(\array_filter(\array_map('strval', $decoded))))
            : [];
    }

    /** @return array<string,array<string,mixed>> */
    private function metricMap(string $json): array
    {
        $decoded = \json_decode($json, true);
        return \is_array($decoded) && !\array_is_list($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function proportionZ(array $before, array $after): float
    {
        $x1 = \max(0, (int)($before['numerator'] ?? 0));
        $n1 = \max(0, (int)($before['denominator'] ?? 0));
        $x2 = \max(0, (int)($after['numerator'] ?? 0));
        $n2 = \max(0, (int)($after['denominator'] ?? 0));
        if ($n1 === 0 || $n2 === 0) {
            return 0.0;
        }
        $p1 = $x1 / $n1;
        $p2 = $x2 / $n2;
        $pooled = ($x1 + $x2) / ($n1 + $n2);
        $standardError = \sqrt(\max(0.0, $pooled * (1.0 - $pooled) * ((1.0 / $n1) + (1.0 / $n2))));
        if ($standardError == 0.0) {
            return $p2 > $p1 ? 99.0 : ($p2 < $p1 ? -99.0 : 0.0);
        }
        return \max(-99.0, \min(99.0, ($p2 - $p1) / $standardError));
    }

    private function normalCdf(float $z): float
    {
        if ($z <= -8.0) {
            return 0.0;
        }
        if ($z >= 8.0) {
            return 1.0;
        }
        $x = \abs($z) / \sqrt(2.0);
        $t = 1.0 / (1.0 + 0.3275911 * $x);
        $erf = 1.0 - (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * \exp(-$x * $x);
        if ($z < 0) {
            $erf = -$erf;
        }
        return \max(0.0, \min(1.0, 0.5 * (1.0 + $erf)));
    }

    private function relativeChange(float $before, float $after): float
    {
        if ($before == 0.0) {
            return $after > 0.0 ? 1.0 : 0.0;
        }
        return ($after - $before) / \abs($before);
    }

    private function json(array $value): string
    {
        return (string)\json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }
}
