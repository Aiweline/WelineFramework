<?php

declare(strict_types=1);

namespace Weline\Product\Service\Ai;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\Data\ProductAdminCommand;
use Weline\Product\Api\ProductAdminCommandInterface;
use Weline\Product\Model\Ai\AiDraft;
use Weline\Product\Model\Ai\AiJob;
use Weline\Product\Model\ProductAuditLog;

/**
 * Review center for generated drafts: list, detail, accept/reject and apply.
 *
 * Apply is the only write path and it is a single `ACTION_SAVE` command so the
 * published content is never partially rewritten. Every copy draft carries the
 * hash of the value it was generated from; a hash mismatch aborts the whole
 * apply instead of overwriting an edit made while the draft was waiting.
 */
final class ProductAiReviewService
{
    private const REVIEWABLE = [AiDraft::STATUS_PENDING, AiDraft::STATUS_ACCEPTED];
    private const IMAGE_FIELD_PATTERN = '/^media:main:(\d+)$/';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly ProductAiJobService $jobs,
        private readonly ProductAiSnapshotService $snapshots,
        private readonly ProductAdminCommandInterface $commands,
    ) {
    }

    /**
     * @param array{status?:string,task_type?:string,job_id?:string,limit?:int,offset?:int} $filters
     * @return array{website_id:int,jobs:list<array<string,mixed>>,has_more:bool}
     */
    public function list(int $websiteId, array $filters = []): array
    {
        if ($websiteId < 0) {
            throw new ProductAiException('product_ai_website_invalid', (string)__('站点无效'), 422);
        }
        $status = \strtolower(\trim((string)($filters['status'] ?? AiDraft::STATUS_PENDING)));
        $taskType = \strtolower(\trim((string)($filters['task_type'] ?? 'all')));
        $jobId = \strtolower(\trim((string)($filters['job_id'] ?? '')));
        $limit = \max(1, \min(100, (int)($filters['limit'] ?? 20)));
        $offset = \max(0, (int)($filters['offset'] ?? 0));
        if ($taskType !== '' && $taskType !== 'all'
            && !\in_array($taskType, [AiJob::TASK_COPY, AiJob::TASK_IMAGE], true)
        ) {
            throw new ProductAiException('product_ai_task_unsupported', (string)__('未知的任务类型'), 422);
        }

        $query = ObjectManager::make(AiJob::class)
            ->reset()
            ->where(AiJob::schema_fields_WEBSITE_ID, $websiteId);
        if ($jobId !== '') {
            $query->where(AiJob::schema_fields_ID, $jobId);
        }
        if ($taskType !== 'all') {
            $query->where(AiJob::schema_fields_TASK_TYPE, $taskType);
        }
        $jobRows = $query
            ->order(AiJob::schema_fields_CREATED_AT, 'DESC')
            ->order(AiJob::schema_fields_ID, 'DESC')
            ->limit($limit + 1, $offset)
            ->select()
            ->fetchArray();
        $hasMore = \count($jobRows) > $limit;
        if ($hasMore) {
            $jobRows = \array_slice($jobRows, 0, $limit);
        }
        if ($jobRows === []) {
            return ['website_id' => $websiteId, 'jobs' => [], 'has_more' => false];
        }

        $jobIds = \array_map(
            static fn(array $row): string => (string)($row[AiJob::schema_fields_ID] ?? ''),
            $jobRows,
        );
        $draftRows = ObjectManager::make(AiDraft::class)
            ->reset()
            ->where(AiDraft::schema_fields_JOB_ID, $jobIds, 'IN')
            ->order(AiDraft::schema_fields_CREATED_AT, 'ASC')
            ->order(AiDraft::schema_fields_FIELD_KEY, 'ASC')
            ->select()
            ->fetchArray();

        $draftsByJob = [];
        foreach ($draftRows as $row) {
            $draftsByJob[(string)($row[AiDraft::schema_fields_JOB_ID] ?? '')][] = $row;
        }

        $snapshots = [];
        $jobs = [];
        foreach ($jobRows as $jobRow) {
            $currentJobId = (string)($jobRow[AiJob::schema_fields_ID] ?? '');
            $uuid = (string)($jobRow[AiJob::schema_fields_PRODUCT_UUID] ?? '');
            $locale = (string)($jobRow[AiJob::schema_fields_LOCALE] ?? '');
            $cacheKey = $uuid . "\0" . $locale;
            if (!\array_key_exists($cacheKey, $snapshots)) {
                $snapshots[$cacheKey] = $this->safeSnapshot($websiteId, $uuid, $locale);
            }
            $state = $snapshots[$cacheKey];
            $allDrafts = $draftsByJob[$currentJobId] ?? [];
            $visible = [];
            $counts = [
                AiDraft::STATUS_PENDING => 0,
                AiDraft::STATUS_ACCEPTED => 0,
                AiDraft::STATUS_REJECTED => 0,
                AiDraft::STATUS_APPLIED => 0,
            ];
            foreach ($allDrafts as $draftRow) {
                $draftStatus = (string)($draftRow[AiDraft::schema_fields_STATUS] ?? '');
                if (\array_key_exists($draftStatus, $counts)) {
                    ++$counts[$draftStatus];
                }
                if (!$this->matchesStatusFilter($draftStatus, $status)) {
                    continue;
                }
                $visible[] = $this->draftView($draftRow, $state);
            }
            $jobs[] = [
                'job_id' => $currentJobId,
                'batch_id' => (string)($jobRow[AiJob::schema_fields_BATCH_ID] ?? ''),
                'task_type' => (string)($jobRow[AiJob::schema_fields_TASK_TYPE] ?? ''),
                'scenario_code' => (string)($jobRow[AiJob::schema_fields_SCENARIO_CODE] ?? ''),
                'model_code' => (string)($jobRow[AiJob::schema_fields_MODEL_CODE] ?? ''),
                'locale' => $locale,
                'status' => (string)($jobRow[AiJob::schema_fields_STATUS] ?? ''),
                'product' => [
                    'global_product_uuid' => $uuid,
                    'product_id' => (int)($jobRow[AiJob::schema_fields_PRODUCT_ID] ?? 0),
                    'name' => (string)($state['fields']['name'] ?? ''),
                ],
                'created_at' => (string)($jobRow[AiJob::schema_fields_CREATED_AT] ?? ''),
                'finished_at' => (string)($jobRow[AiJob::schema_fields_FINISHED_AT] ?? ''),
                'error_code' => (string)($jobRow[AiJob::schema_fields_ERROR_CODE] ?? ''),
                'draft_counts' => $counts,
                'drafts' => $visible,
            ];
        }

        return ['website_id' => $websiteId, 'jobs' => $jobs, 'has_more' => $hasMore];
    }

    /**
     * @return array<string,mixed>
     */
    public function detail(int $websiteId, string $jobId): array
    {
        $job = $this->loadJob($websiteId, $jobId);
        $uuid = (string)$job->getData(AiJob::schema_fields_PRODUCT_UUID);
        $locale = (string)$job->getData(AiJob::schema_fields_LOCALE);
        $params = $this->decodeJson((string)$job->getData(AiJob::schema_fields_PARAMS_JSON));
        unset($params['media_context']);
        $result = $this->decodeJson((string)$job->getData(AiJob::schema_fields_RESULT_JSON));
        $draftRows = $this->draftsForJob($jobId);
        $state = null;
        $stateUnavailable = '';
        try {
            $state = $this->snapshots->snapshot($websiteId, $uuid, $locale, 0);
        } catch (ProductAiException $exception) {
            $stateUnavailable = $exception->errorCode();
        } catch (\Throwable) {
            $stateUnavailable = 'product_ai_state_unavailable';
        }

        $fields = [];
        $mediaCandidates = [];
        foreach ($draftRows as $row) {
            $view = $this->draftView($row, $state);
            $fields[] = $view;
            if (!$this->isImageFieldKey($view['field_key'])) {
                continue;
            }
            $after = \is_array($view['after']) ? $view['after'] : [];
            $mediaCandidates[] = [
                'draft_id' => $view['draft_id'],
                'field_key' => $view['field_key'],
                'status' => $view['status'],
                'asset_id' => (string)($after['asset_id'] ?? ''),
                'url' => (string)($after['url'] ?? ''),
                'width' => (int)($after['width'] ?? 0),
                'height' => (int)($after['height'] ?? 0),
                'mime' => (string)($after['mime'] ?? ''),
                'candidate' => (int)($after['candidate'] ?? 0),
            ];
        }

        $currentMain = $state !== null ? $this->snapshots->currentMainAssetId($state['media']) : '';
        $currentMainUrl = '';
        if ($state !== null) {
            foreach ($state['media'] as $row) {
                if (($row['role'] ?? '') === 'main' && ($row['asset_id'] ?? '') === $currentMain) {
                    $currentMainUrl = (string)($row['preview_url'] ?? '');
                    break;
                }
            }
        }

        $applicable = $state !== null
            && (string)$job->getData(AiJob::schema_fields_STATUS) === AiJob::STATUS_SUCCEEDED;

        return [
            'job' => [
                'job_id' => $jobId,
                'task_type' => (string)$job->getData(AiJob::schema_fields_TASK_TYPE),
                'scenario_code' => (string)$job->getData(AiJob::schema_fields_SCENARIO_CODE),
                'model_code' => (string)$job->getData(AiJob::schema_fields_MODEL_CODE),
                'locale' => $locale,
                'status' => (string)$job->getData(AiJob::schema_fields_STATUS),
                'created_at' => (string)$job->getData(AiJob::schema_fields_CREATED_AT),
                'started_at' => (string)$job->getData(AiJob::schema_fields_STARTED_AT),
                'finished_at' => (string)$job->getData(AiJob::schema_fields_FINISHED_AT),
                'error_code' => (string)$job->getData(AiJob::schema_fields_ERROR_CODE),
                'error_message' => (string)$job->getData(AiJob::schema_fields_ERROR_MESSAGE),
            ],
            'params' => $params,
            'result' => $result,
            'product' => [
                'global_product_uuid' => $uuid,
                'product_id' => (int)$job->getData(AiJob::schema_fields_PRODUCT_ID),
                'name' => (string)($state['fields']['name'] ?? ''),
                'status' => (string)($state['status'] ?? ''),
            ],
            'local_version' => $state !== null ? (int)$state['local_version'] : null,
            'identity_version' => $state !== null ? (int)$state['identity_version'] : null,
            'applicable' => $applicable,
            'state_unavailable_reason' => $stateUnavailable,
            'fields' => $fields,
            'media' => [
                'current_main' => [
                    'asset_id' => $currentMain,
                    'url' => $currentMainUrl,
                ],
                'candidates' => $mediaCandidates,
            ],
        ];
    }

    /**
     * @param list<string> $fieldKeys
     * @return array{job_id:string,status:string,drafts:list<array<string,mixed>>}
     */
    public function accept(
        int $websiteId,
        string $jobId,
        array $fieldKeys,
        int $actorId,
        ?string $note = null,
    ): array {
        return $this->transition($websiteId, $jobId, $fieldKeys, $actorId, AiDraft::STATUS_ACCEPTED, $note);
    }

    /**
     * @param list<string> $fieldKeys
     * @return array{job_id:string,status:string,drafts:list<array<string,mixed>>}
     */
    public function reject(
        int $websiteId,
        string $jobId,
        array $fieldKeys,
        int $actorId,
        ?string $note = null,
    ): array {
        return $this->transition($websiteId, $jobId, $fieldKeys, $actorId, AiDraft::STATUS_REJECTED, $note);
    }

    /**
     * Applies the selected drafts in one Product save command.
     *
     * @param list<string> $fieldKeys
     * @return array{job_id:string,product_uuid:string,local_version:int,applied:list<array<string,mixed>>,audit_event_id:int}
     */
    public function apply(
        int $websiteId,
        string $jobId,
        array $fieldKeys,
        ?string $imageDraftId,
        int $localVersion,
        int $actorId,
    ): array {
        if ($actorId < 1) {
            throw new ProductAiException('product_ai_actor_invalid', (string)__('缺少操作人'), 403);
        }
        if ($localVersion < 0) {
            throw new ProductAiException('product_ai_local_version_required', (string)__('缺少商品版本号'), 422);
        }
        $job = $this->loadJob($websiteId, $jobId);
        $jobStatus = (string)$job->getData(AiJob::schema_fields_STATUS);
        if ($jobStatus !== AiJob::STATUS_SUCCEEDED) {
            throw new ProductAiException(
                'product_ai_job_not_ready',
                (string)__('任务尚未成功完成，不能应用'),
                409,
                ['status' => $jobStatus],
            );
        }
        $uuid = (string)$job->getData(AiJob::schema_fields_PRODUCT_UUID);
        $locale = (string)$job->getData(AiJob::schema_fields_LOCALE);
        $draftRows = $this->draftsForJob($jobId);
        if ($draftRows === []) {
            throw new ProductAiException('product_ai_selection_empty', (string)__('没有可应用的草稿'), 422);
        }
        $byFieldKey = [];
        foreach ($draftRows as $row) {
            $byFieldKey[(string)($row[AiDraft::schema_fields_FIELD_KEY] ?? '')] = $row;
        }

        $requested = $this->normalizeFieldKeys($fieldKeys);
        if ($requested === []) {
            foreach ($draftRows as $row) {
                if ((string)($row[AiDraft::schema_fields_STATUS] ?? '') === AiDraft::STATUS_ACCEPTED) {
                    $requested[] = (string)($row[AiDraft::schema_fields_FIELD_KEY] ?? '');
                }
            }
        }
        if ($requested === []) {
            throw new ProductAiException('product_ai_selection_empty', (string)__('请选择要应用的字段'), 422);
        }

        $copySelection = [];
        $imageSelection = [];
        $now = \date('Y-m-d H:i:s');
        foreach ($requested as $fieldKey) {
            $row = $byFieldKey[$fieldKey] ?? null;
            if ($row === null) {
                throw new ProductAiException(
                    'product_ai_draft_not_found',
                    (string)__('草稿不存在或不属于该任务'),
                    404,
                    ['field_key' => $fieldKey],
                );
            }
            if (!$this->isApplicableDraft($row, $now)) {
                throw new ProductAiException(
                    'product_ai_draft_status_invalid',
                    (string)__('草稿状态不允许应用'),
                    409,
                    [
                        'field_key' => $fieldKey,
                        'status' => (string)($row[AiDraft::schema_fields_STATUS] ?? ''),
                    ],
                );
            }
            if ($this->isImageFieldKey($fieldKey)) {
                $imageSelection[] = $row;
                continue;
            }
            if (!\in_array($fieldKey, AiDraft::COPY_FIELDS, true)) {
                throw new ProductAiException(
                    'product_ai_draft_status_invalid',
                    (string)__('未知的草稿字段'),
                    409,
                    ['field_key' => $fieldKey],
                );
            }
            $copySelection[$fieldKey] = $row;
        }

        $imageDraft = null;
        if ($imageSelection !== []) {
            $wanted = \strtolower(\trim((string)$imageDraftId));
            if ($wanted !== '') {
                foreach ($imageSelection as $row) {
                    $draftId = \strtolower((string)($row[AiDraft::schema_fields_ID] ?? ''));
                    $fieldKey = \strtolower((string)($row[AiDraft::schema_fields_FIELD_KEY] ?? ''));
                    if ($wanted === $draftId || $wanted === $fieldKey) {
                        $imageDraft = $row;
                        break;
                    }
                }
                if ($imageDraft === null) {
                    throw new ProductAiException(
                        'product_ai_draft_not_found',
                        (string)__('所选图片草稿不在本次应用范围内'),
                        404,
                        ['image_draft_id' => $imageDraftId],
                    );
                }
            } elseif (\count($imageSelection) === 1) {
                $imageDraft = $imageSelection[0];
            } else {
                throw new ProductAiException(
                    'product_ai_image_choice_required',
                    (string)__('请先选择一张候选主图'),
                    422,
                    ['candidates' => \count($imageSelection)],
                );
            }
        }

        $current = $this->snapshots->snapshot($websiteId, $uuid, $locale, 0);
        if ($localVersion !== (int)$current['local_version']) {
            throw new ProductAiException(
                'product_ai_local_version_stale',
                (string)__('商品已被修改，请刷新后重试'),
                409,
                ['expected' => $localVersion, 'actual' => (int)$current['local_version']],
            );
        }

        $conflicts = [];
        foreach ($copySelection as $fieldKey => $row) {
            $beforeHash = (string)($row[AiDraft::schema_fields_BEFORE_HASH] ?? '');
            $currentHash = (string)($current['field_hashes'][$fieldKey] ?? '');
            if ($beforeHash !== '' && \hash_equals($beforeHash, $currentHash)) {
                continue;
            }
            $conflicts[] = [
                'field_key' => $fieldKey,
                'before_hash' => $beforeHash,
                'current_hash' => $currentHash,
                'before' => $this->decodeJson((string)($row[AiDraft::schema_fields_BEFORE_JSON] ?? '')),
                'current' => ['value' => (string)($current['fields'][$fieldKey] ?? '')],
            ];
        }
        $currentMain = $this->snapshots->currentMainAssetId($current['media']);
        if ($imageDraft !== null) {
            $beforeHash = (string)($imageDraft[AiDraft::schema_fields_BEFORE_HASH] ?? '');
            $currentHash = \hash('sha256', AiDraft::FIELD_MAIN_MEDIA . "\0" . $currentMain);
            if ($beforeHash === '' || !\hash_equals($beforeHash, $currentHash)) {
                $conflicts[] = [
                    'field_key' => (string)($imageDraft[AiDraft::schema_fields_FIELD_KEY] ?? ''),
                    'before_hash' => $beforeHash,
                    'current_hash' => $currentHash,
                    'before' => $this->decodeJson((string)($imageDraft[AiDraft::schema_fields_BEFORE_JSON] ?? '')),
                    'current' => ['asset_id' => $currentMain],
                ];
            }
        }
        if ($conflicts !== []) {
            throw new ProductAiException(
                'product_ai_draft_conflict',
                (string)__('草稿生成后商品已被修改，请重新生成或手动处理冲突'),
                409,
                ['conflicts' => $conflicts],
            );
        }

        $payload = [
            'locale' => $locale,
            'store_id' => 0,
            'local_version' => (int)$current['local_version'],
        ];
        $appliedFields = [];
        foreach ($copySelection as $fieldKey => $row) {
            $after = $this->decodeJson((string)($row[AiDraft::schema_fields_AFTER_JSON] ?? ''));
            $before = $this->decodeJson((string)($row[AiDraft::schema_fields_BEFORE_JSON] ?? ''));
            $value = (string)($after['value'] ?? '');
            $payload[$fieldKey] = $value;
            $appliedFields[$fieldKey] = [
                'before' => (string)($before['value'] ?? ''),
                'after' => $value,
            ];
        }

        $newAssetId = '';
        $previousMain = $currentMain;
        if ($imageDraft !== null) {
            $after = $this->decodeJson((string)($imageDraft[AiDraft::schema_fields_AFTER_JSON] ?? ''));
            $newAssetId = \strtolower(\trim((string)($after['asset_id'] ?? '')));
            if (\preg_match(self::UUID_PATTERN, $newAssetId) !== 1) {
                throw new ProductAiException(
                    'product_ai_image_invalid',
                    (string)__('候选主图资产无效'),
                    502,
                    ['asset_id' => $newAssetId],
                );
            }
        }

        $mediaChanged = $newAssetId !== '' && $newAssetId !== $previousMain;
        if ($mediaChanged) {
            $payload['media_assignments'] = $this->mediaAssignments($current['media'], $newAssetId);
        }
        if ($appliedFields === [] && !$mediaChanged) {
            $this->markApplied(
                $imageDraft !== null ? [$imageDraft] : [],
                $actorId,
                '',
                (int)$current['local_version'],
                (int)$current['local_version'],
                ['local_version_before' => (int)$current['local_version'], 'media_before' => $current['media']],
            );
            if ($imageDraft !== null) {
                $this->recordJobApplied($job, [$imageDraft], $actorId, (int)$current['local_version']);
            }

            return [
                'job_id' => $jobId,
                'product_uuid' => $uuid,
                'local_version' => (int)$current['local_version'],
                'applied' => $imageDraft !== null
                    ? [[
                        'draft_id' => (string)($imageDraft[AiDraft::schema_fields_ID] ?? ''),
                        'field_key' => (string)($imageDraft[AiDraft::schema_fields_FIELD_KEY] ?? ''),
                        'status' => AiDraft::STATUS_APPLIED,
                    ]]
                    : [],
                'audit_event_id' => 0,
            ];
        }

        $appliedHash = \hash('sha256', $this->jobs->json([
            'job_id' => $jobId,
            'fields' => \array_keys($appliedFields),
            'image_asset_id' => $newAssetId,
        ]));
        $requestHash = \hash('sha256', 'product-ai:' . $jobId . ':' . $appliedHash);
        $result = $this->commands->execute(ProductAdminCommand::fromArray([
            'action' => ProductAdminCommand::ACTION_SAVE,
            'website_id' => $websiteId,
            'global_product_uuid' => $uuid,
            'actor_id' => $actorId,
            'request_hash' => $requestHash,
            'payload' => $payload,
        ]));
        if (!$result->success) {
            $this->throwApplyFailure((string)($result->errorCode ?? 'product_ai_apply_failed'), $result->data);
        }
        $newVersion = (int)($result->data['local_version'] ?? -1);
        if ($newVersion < 0) {
            throw new ProductAiException(
                'product_ai_snapshot_invalid',
                (string)__('保存结果缺少商品版本号'),
                500,
                ['job_id' => $jobId],
            );
        }

        $appliedDrafts = \array_values($copySelection);
        if ($imageDraft !== null) {
            $appliedDrafts[] = $imageDraft;
        }
        $auditFields = [];
        foreach ($appliedFields as $fieldKey => $values) {
            $auditFields[$fieldKey] = [
                'before_hash' => \hash('sha256', $values['before']),
                'after_hash' => \hash('sha256', $values['after']),
                'after_length' => \strlen($values['after']),
            ];
        }
        $auditEventId = $this->writeAudit(
            $uuid,
            $websiteId,
            (int)$current['local_version'],
            $newVersion,
            $requestHash,
            [
                'job_id' => $jobId,
                'task_type' => (string)$job->getData(AiJob::schema_fields_TASK_TYPE),
                'model_code' => (string)$job->getData(AiJob::schema_fields_MODEL_CODE),
                'locale' => $locale,
                'field_keys' => \array_keys($appliedFields),
                'fields' => $auditFields,
                'image' => $newAssetId !== ''
                    ? ['asset_id' => $newAssetId, 'previous_main' => $previousMain]
                    : null,
                'actor_id' => $actorId,
            ],
        );

        $this->markApplied(
            $appliedDrafts,
            $actorId,
            $requestHash,
            (int)$current['local_version'],
            $newVersion,
            [
                'local_version_before' => (int)$current['local_version'],
                'local_version_after' => $newVersion,
                'media_before' => $current['media'],
            ],
        );

        $this->recordJobApplied($job, $appliedDrafts, $actorId, $newVersion);

        $applied = [];
        foreach ($appliedDrafts as $row) {
            $applied[] = [
                'draft_id' => (string)($row[AiDraft::schema_fields_ID] ?? ''),
                'field_key' => (string)($row[AiDraft::schema_fields_FIELD_KEY] ?? ''),
                'status' => AiDraft::STATUS_APPLIED,
            ];
        }

        return [
            'job_id' => $jobId,
            'product_uuid' => $uuid,
            'local_version' => $newVersion,
            'applied' => $applied,
            'audit_event_id' => $auditEventId,
        ];
    }

    /**
     * @param list<string> $fieldKeys
     * @return array{job_id:string,status:string,drafts:list<array<string,mixed>>}
     */
    private function transition(
        int $websiteId,
        string $jobId,
        array $fieldKeys,
        int $actorId,
        string $targetStatus,
        ?string $note,
    ): array {
        if ($actorId < 1) {
            throw new ProductAiException('product_ai_actor_invalid', (string)__('缺少操作人'), 403);
        }
        $this->loadJob($websiteId, $jobId);
        $draftRows = $this->draftsForJob($jobId);
        if ($draftRows === []) {
            throw new ProductAiException('product_ai_selection_empty', (string)__('该任务没有草稿'), 422);
        }
        $byFieldKey = [];
        foreach ($draftRows as $row) {
            $byFieldKey[(string)($row[AiDraft::schema_fields_FIELD_KEY] ?? '')] = $row;
        }
        $requested = $this->normalizeFieldKeys($fieldKeys);
        if ($requested === []) {
            $allowed = $targetStatus === AiDraft::STATUS_ACCEPTED
                ? [AiDraft::STATUS_PENDING]
                : self::REVIEWABLE;
            foreach ($draftRows as $row) {
                if (\in_array((string)($row[AiDraft::schema_fields_STATUS] ?? ''), $allowed, true)) {
                    $requested[] = (string)($row[AiDraft::schema_fields_FIELD_KEY] ?? '');
                }
            }
        }
        if ($requested === []) {
            throw new ProductAiException('product_ai_selection_empty', (string)__('没有可操作的草稿'), 422);
        }

        $now = \date('Y-m-d H:i:s');
        $noteValue = $note !== null ? \mb_strcut(\trim($note), 0, 250, 'UTF-8') : null;
        $updated = [];
        foreach ($requested as $fieldKey) {
            $row = $byFieldKey[$fieldKey] ?? null;
            if ($row === null) {
                throw new ProductAiException(
                    'product_ai_draft_not_found',
                    (string)__('草稿不存在或不属于该任务'),
                    404,
                    ['field_key' => $fieldKey],
                );
            }
            $draftStatus = (string)($row[AiDraft::schema_fields_STATUS] ?? '');
            $draftId = (string)($row[AiDraft::schema_fields_ID] ?? '');
            if ($draftStatus === $targetStatus) {
                $updated[] = ['draft_id' => $draftId, 'field_key' => $fieldKey, 'status' => $draftStatus];
                continue;
            }
            if (!$this->isApplicableDraft($row, $now)) {
                throw new ProductAiException(
                    'product_ai_draft_status_invalid',
                    (string)__('草稿状态不允许该操作'),
                    409,
                    ['field_key' => $fieldKey, 'status' => $draftStatus],
                );
            }
            $data = [
                AiDraft::schema_fields_STATUS => $targetStatus,
                AiDraft::schema_fields_REVIEWED_BY => $actorId,
                AiDraft::schema_fields_REVIEWED_AT => $now,
            ];
            if ($noteValue !== null && $noteValue !== '') {
                $data[AiDraft::schema_fields_REVIEW_NOTE] = $noteValue;
            }
            ObjectManager::make(AiDraft::class)
                ->getQuery(false)
                ->where(AiDraft::schema_fields_ID, $draftId)
                ->where(AiDraft::schema_fields_STATUS, [AiDraft::STATUS_PENDING, AiDraft::STATUS_ACCEPTED], 'IN')
                ->update($data)
                ->fetch();
            $reloaded = $this->findDraft($draftId);
            $reloadedStatus = $reloaded !== null
                ? (string)$reloaded->getData(AiDraft::schema_fields_STATUS)
                : '';
            if ($reloadedStatus !== $targetStatus) {
                throw new ProductAiException(
                    'product_ai_draft_status_invalid',
                    (string)__('草稿已被其他审核人处理'),
                    409,
                    ['field_key' => $fieldKey, 'status' => $reloadedStatus],
                );
            }
            $updated[] = ['draft_id' => $draftId, 'field_key' => $fieldKey, 'status' => $targetStatus];
        }

        return ['job_id' => $jobId, 'status' => $targetStatus, 'drafts' => $updated];
    }

    /**
     * @param list<array<string,mixed>> $media
     * @return list<array<string,mixed>>
     */
    private function mediaAssignments(array $media, string $newAssetId): array
    {
        $rows = [];
        $position = 1;
        foreach ($media as $row) {
            $role = \strtolower(\trim((string)($row['role'] ?? '')));
            $assetId = \strtolower(\trim((string)($row['asset_id'] ?? '')));
            if ($role === 'variant') {
                // The command service re-preserves existing variant media rows.
                continue;
            }
            if ($assetId === '') {
                if ($role !== 'video') {
                    throw new ProductAiException(
                        'product_ai_media_legacy_unsupported',
                        (string)__('商品存在旧版媒体记录，无法安全重建媒体列表'),
                        409,
                        ['role' => $role],
                    );
                }
                $path = \trim((string)($row['path'] ?? ''));
                $policy = $row['access_policy_json'] ?? null;
                if ($path === '' || !\is_string($policy) || \trim($policy) === '') {
                    throw new ProductAiException(
                        'product_ai_media_legacy_unsupported',
                        (string)__('外链视频缺少可回写的地址'),
                        409,
                        ['role' => $role],
                    );
                }
                $rows[] = [
                    'asset_id' => '',
                    'role' => 'video',
                    'path' => $path,
                    'access_policy_json' => $policy,
                    'hidden' => !empty($row['hidden']),
                    'position' => $position++,
                ];
                continue;
            }
            if ($assetId === $newAssetId) {
                continue;
            }
            if ($role === 'main') {
                $role = 'gallery';
            }
            if (!\in_array($role, ['gallery', 'file', 'download', 'video'], true)) {
                continue;
            }
            $assignment = [
                'asset_id' => $assetId,
                'role' => $role,
                'hidden' => !empty($row['hidden']),
                'position' => $position++,
            ];
            $policy = $row['access_policy_json'] ?? null;
            if ($role === 'download' && \is_string($policy) && \trim($policy) !== '') {
                $assignment['access_policy_json'] = $policy;
            }
            $rows[] = $assignment;
        }
        \array_unshift($rows, [
            'asset_id' => $newAssetId,
            'role' => 'main',
            'hidden' => false,
            'position' => 0,
        ]);

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $drafts
     */
    private function recordJobApplied(AiJob $job, array $drafts, int $actorId, int $localVersion): void
    {
        $jobId = (string)$job->getData(AiJob::schema_fields_ID);
        if ($jobId === '') {
            return;
        }
        $jobResult = $this->decodeJson((string)$job->getData(AiJob::schema_fields_RESULT_JSON));
        $jobResult['applied_at'] = \date('Y-m-d H:i:s');
        $jobResult['applied_by'] = $actorId;
        $jobResult['applied_draft_ids'] = \array_map(
            static fn(array $row): string => (string)($row[AiDraft::schema_fields_ID] ?? ''),
            $drafts,
        );
        $jobResult['local_version_after'] = $localVersion;
        ObjectManager::make(AiJob::class)
            ->getQuery(false)
            ->where(AiJob::schema_fields_ID, $jobId)
            ->where(AiJob::schema_fields_STATUS, AiJob::STATUS_SUCCEEDED)
            ->update([AiJob::schema_fields_RESULT_JSON => $this->jobs->json($jobResult)])
            ->fetch();
    }

    /**
     * @param list<array<string,mixed>> $drafts
     * @param array<string,mixed> $extra
     */
    private function markApplied(
        array $drafts,
        int $actorId,
        string $requestHash,
        int $versionBefore,
        int $versionAfter,
        array $extra,
    ): void {
        $now = \date('Y-m-d H:i:s');
        foreach ($drafts as $row) {
            $draftId = (string)($row[AiDraft::schema_fields_ID] ?? '');
            if ($draftId === '') {
                continue;
            }
            $rollback = $extra;
            $fieldKey = (string)($row[AiDraft::schema_fields_FIELD_KEY] ?? '');
            if (!$this->isImageFieldKey($fieldKey)) {
                unset($rollback['media_before']);
            }
            $rollback['field_key'] = $fieldKey;
            $rollback['before'] = $this->decodeJson((string)($row[AiDraft::schema_fields_BEFORE_JSON] ?? ''));
            $rollback['after'] = $this->decodeJson((string)($row[AiDraft::schema_fields_AFTER_JSON] ?? ''));
            ObjectManager::make(AiDraft::class)
                ->getQuery(false)
                ->where(AiDraft::schema_fields_ID, $draftId)
                ->update([
                    AiDraft::schema_fields_STATUS => AiDraft::STATUS_APPLIED,
                    AiDraft::schema_fields_APPLIED_BY => $actorId,
                    AiDraft::schema_fields_APPLIED_AT => $now,
                    AiDraft::schema_fields_APPLIED_REQUEST_HASH => $requestHash !== '' ? $requestHash : null,
                    AiDraft::schema_fields_APPLIED_VERSION_BEFORE => $versionBefore,
                    AiDraft::schema_fields_APPLIED_VERSION_AFTER => $versionAfter,
                    AiDraft::schema_fields_ROLLBACK_JSON => $this->jobs->json($rollback),
                ])
                ->fetch();
        }
    }

    /**
     * @param array<string,mixed> $details
     */
    private function throwApplyFailure(string $errorCode, array $details): never
    {
        if ($errorCode === 'publish_version_conflict') {
            throw new ProductAiException(
                'product_ai_local_version_stale',
                (string)__('商品已被修改，请刷新后重试'),
                409,
                ['upstream_error_code' => $errorCode] + $details,
            );
        }
        if ($errorCode === 'product_archived_readonly') {
            throw new ProductAiException(
                'product_ai_product_archived',
                (string)__('已归档商品不能应用 AI 优化'),
                409,
                ['upstream_error_code' => $errorCode],
            );
        }
        if (\str_starts_with($errorCode, 'product_media_') || \str_starts_with($errorCode, 'product_download_')) {
            throw new ProductAiException(
                'product_ai_media_apply_rejected',
                (string)__('媒体数据校验失败，无法应用'),
                409,
                ['upstream_error_code' => $errorCode],
            );
        }
        throw new ProductAiException(
            'product_ai_apply_failed',
            (string)__('应用商品改动失败'),
            422,
            ['upstream_error_code' => $errorCode],
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeAudit(
        string $uuid,
        int $websiteId,
        int $beforeVersion,
        int $afterVersion,
        string $requestHash,
        array $payload,
    ): int {
        $log = ObjectManager::make(ProductAuditLog::class)->clear()->setData([
            ProductAuditLog::schema_fields_PRODUCT_UUID => $uuid,
            ProductAuditLog::schema_fields_WEBSITE_ID => $websiteId,
            ProductAuditLog::schema_fields_ACTION => 'product_ai_apply',
            ProductAuditLog::schema_fields_BEFORE_VERSION => $beforeVersion,
            ProductAuditLog::schema_fields_AFTER_VERSION => $afterVersion,
            ProductAuditLog::schema_fields_REQUEST_HASH => $requestHash,
            ProductAuditLog::schema_fields_PAYLOAD_JSON => $this->jobs->json($payload),
            ProductAuditLog::schema_fields_CREATED_AT => \date('Y-m-d H:i:s'),
        ]);
        $log->save();
        $eventId = (int)$log->getData(ProductAuditLog::schema_fields_ID);
        if ($eventId > 0) {
            return $eventId;
        }
        $stored = ObjectManager::make(ProductAuditLog::class)
            ->reset()
            ->where(ProductAuditLog::schema_fields_REQUEST_HASH, $requestHash)
            ->where(ProductAuditLog::schema_fields_ACTION, 'product_ai_apply')
            ->order(ProductAuditLog::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        return $stored->getId() ? (int)$stored->getData(ProductAuditLog::schema_fields_ID) : 0;
    }

    private function loadJob(int $websiteId, string $jobId): AiJob
    {
        $job = $this->jobs->findJob($jobId);
        if ($job === null) {
            throw new ProductAiException(
                'product_ai_job_not_ready',
                (string)__('任务不存在'),
                409,
                ['job_id' => $jobId],
            );
        }
        if ((int)$job->getData(AiJob::schema_fields_WEBSITE_ID) !== $websiteId) {
            throw new ProductAiException(
                'product_ai_job_not_ready',
                (string)__('任务不属于当前站点'),
                409,
                ['job_id' => $jobId],
            );
        }

        return $job;
    }

    /** @return list<array<string,mixed>> */
    private function draftsForJob(string $jobId): array
    {
        return ObjectManager::make(AiDraft::class)
            ->reset()
            ->where(AiDraft::schema_fields_JOB_ID, $jobId)
            ->order(AiDraft::schema_fields_FIELD_KEY, 'ASC')
            ->select()
            ->fetchArray();
    }

    private function findDraft(string $draftId): ?AiDraft
    {
        $draft = ObjectManager::make(AiDraft::class)
            ->reset()
            ->where(AiDraft::schema_fields_ID, $draftId)
            ->find()
            ->fetch();

        return $draft->getId() ? $draft : null;
    }

    /**
     * @param list<string> $fieldKeys
     * @return list<string>
     */
    private function normalizeFieldKeys(array $fieldKeys): array
    {
        $keys = [];
        foreach ($fieldKeys as $key) {
            $key = \strtolower(\trim((string)$key));
            if ($key !== '' && !\in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** @param array<string,mixed> $row */
    private function matchesStatusFilter(string $status, string $filter): bool
    {
        if ($filter === '' || $filter === 'all') {
            return true;
        }
        if ($filter === AiDraft::STATUS_PENDING || $filter === 'review') {
            return \in_array($status, self::REVIEWABLE, true);
        }

        return $status === $filter;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $state
     * @return array<string,mixed>
     */
    private function draftView(array $row, ?array $state): array
    {
        $status = (string)($row[AiDraft::schema_fields_STATUS] ?? '');
        $expiresAt = (string)($row[AiDraft::schema_fields_EXPIRES_AT] ?? '');

        return [
            'draft_id' => (string)($row[AiDraft::schema_fields_ID] ?? ''),
            'field_key' => (string)($row[AiDraft::schema_fields_FIELD_KEY] ?? ''),
            'status' => $status,
            'before' => $this->decodeJson((string)($row[AiDraft::schema_fields_BEFORE_JSON] ?? '')),
            'after' => $this->decodeJson((string)($row[AiDraft::schema_fields_AFTER_JSON] ?? '')),
            'diff' => $this->decodeJson((string)($row[AiDraft::schema_fields_DIFF_JSON] ?? '')),
            'conflict' => $this->isConflict($row, $state),
            'expired' => \in_array($status, self::REVIEWABLE, true)
                && $expiresAt !== ''
                && $expiresAt < \date('Y-m-d H:i:s'),
            'review_note' => (string)($row[AiDraft::schema_fields_REVIEW_NOTE] ?? ''),
            'expires_at' => $expiresAt,
            'created_at' => (string)($row[AiDraft::schema_fields_CREATED_AT] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $state
     */
    private function isConflict(array $row, ?array $state): bool
    {
        $status = (string)($row[AiDraft::schema_fields_STATUS] ?? '');
        if (!\in_array($status, self::REVIEWABLE, true)) {
            return false;
        }
        if ($state === null) {
            return true;
        }
        $beforeHash = (string)($row[AiDraft::schema_fields_BEFORE_HASH] ?? '');
        $fieldKey = (string)($row[AiDraft::schema_fields_FIELD_KEY] ?? '');
        if ($this->isImageFieldKey($fieldKey)) {
            $currentHash = \hash(
                'sha256',
                AiDraft::FIELD_MAIN_MEDIA . "\0" . $this->snapshots->currentMainAssetId($state['media']),
            );

            return $beforeHash === '' || !\hash_equals($beforeHash, $currentHash);
        }
        $currentHash = (string)($state['field_hashes'][$fieldKey] ?? '');

        return $beforeHash === '' || !\hash_equals($beforeHash, $currentHash);
    }

    /** @param array<string,mixed> $row */
    private function isApplicableDraft(array $row, string $now): bool
    {
        $status = (string)($row[AiDraft::schema_fields_STATUS] ?? '');
        if (!\in_array($status, self::REVIEWABLE, true)) {
            return false;
        }
        $expiresAt = (string)($row[AiDraft::schema_fields_EXPIRES_AT] ?? '');

        return $expiresAt === '' || $expiresAt >= $now;
    }

    private function isImageFieldKey(string $fieldKey): bool
    {
        return \preg_match(self::IMAGE_FIELD_PATTERN, $fieldKey) === 1;
    }

    /** @return array<string,mixed>|null */
    private function safeSnapshot(int $websiteId, string $uuid, string $locale): ?array
    {
        try {
            return $this->snapshots->snapshot($websiteId, $uuid, $locale, 0);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $json): array
    {
        $json = \trim($json);
        if ($json === '') {
            return [];
        }
        try {
            $decoded = \json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }
}
