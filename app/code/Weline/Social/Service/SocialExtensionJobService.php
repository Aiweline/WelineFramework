<?php

declare(strict_types=1);

namespace Weline\Social\Service;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Social\Model\SocialExtensionDevice;
use Weline\Social\Model\SocialExtensionJob;
use Weline\Social\Model\SocialPublishBatch;
use Weline\Social\Model\SocialPublishLog;
use Weline\Social\Model\SocialPublishTarget;

class SocialExtensionJobService
{
    public const STATUS_UNVERIFIED = 'unverified';

    public function __construct(
        private readonly ?ObjectManager $objectManager = null
    ) {
    }

    private function newJob(): SocialExtensionJob
    {
        return ($this->objectManager ?? ObjectManager::getInstance())->getInstance(SocialExtensionJob::class, [], false);
    }

    public function enqueue(array $payload): array
    {
        $accountId = (int)($payload['account_id'] ?? 0);
        if ($accountId < 1) {
            return ['success' => false, 'error' => 'missing_account'];
        }

        $platformCode = \trim((string)($payload['platform_code'] ?? ''));
        if ($platformCode === '') {
            return ['success' => false, 'error' => 'missing_platform'];
        }

        $targetId = (int)($payload['target_id'] ?? 0);
        if ($targetId > 0) {
            $existing = $this->findByTarget($targetId);
            if ($existing && $existing->getId()) {
                return ['success' => true, 'job' => $this->toArray($existing), 'duplicated' => true];
            }
        }

        $now = \date('Y-m-d H:i:s');
        $contentKind = \trim((string)($payload['content_kind'] ?? ''));
        if ($contentKind === '') {
            $contentKind = 'text';
        }
        $job = $this->newJob();
        $job->setData(SocialExtensionJob::schema_fields_TARGET_ID, $targetId);
        $job->setData(SocialExtensionJob::schema_fields_ACCOUNT_ID, $accountId);
        $job->setData(SocialExtensionJob::schema_fields_WEBSITE_ID, (int)($payload['website_id'] ?? 0));
        $job->setData(SocialExtensionJob::schema_fields_PLATFORM_CODE, $platformCode);
        $job->setData(SocialExtensionJob::schema_fields_CONTENT_KIND, $contentKind);
        $job->setData(SocialExtensionJob::schema_fields_RECIPE_VERSION, (string)($payload['recipe_version'] ?? ''));
        $job->setData(
            SocialExtensionJob::schema_fields_PAYLOAD_JSON,
            \json_encode(
                $payload['payload'] ?? [],
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
            )
        );
        $job->setData(SocialExtensionJob::schema_fields_STATUS, SocialExtensionJob::STATUS_PENDING);
        $job->setData(SocialExtensionJob::schema_fields_ATTEMPTS, 0);
        $job->setData(SocialExtensionJob::schema_fields_CREATED_AT, $now);
        $job->setData(SocialExtensionJob::schema_fields_UPDATED_AT, $now);
        $job->save();

        if ($targetId <= 0) {
            $batch = null;
            $target = null;
            try {
                if (!$job->getId()) {
                    throw new \RuntimeException('job_id_missing');
                }
                $batch = $this->createExtensionBatch($payload, $contentKind, $now);
                if (!$batch instanceof SocialPublishBatch) {
                    throw new \RuntimeException('batch_create_failed');
                }
                $target = $this->createExtensionTarget($job, $batch, $accountId, $platformCode, $contentKind, $now);
                if (!$target instanceof SocialPublishTarget) {
                    throw new \RuntimeException('target_create_failed');
                }
                $newTargetId = (int)$target->getId();
                $this->newJob()->getQuery(false)
                    ->where(SocialExtensionJob::schema_fields_ID, (int)$job->getId())
                    ->where(SocialExtensionJob::schema_fields_TARGET_ID, 0)
                    ->update([
                        SocialExtensionJob::schema_fields_TARGET_ID => $newTargetId,
                        SocialExtensionJob::schema_fields_UPDATED_AT => $now,
                    ])
                    ->fetch();
                $fresh = $this->loadJob((int)$job->getId());
                if ($fresh === null || (int)($fresh->getData(SocialExtensionJob::schema_fields_TARGET_ID) ?? 0) !== $newTargetId) {
                    throw new \RuntimeException('target_bind_failed');
                }
                $job = $fresh;
            } catch (\Throwable) {
                $this->rollbackExtensionCreation($target, $batch, $job);

                return ['success' => false, 'error' => 'extension_target_create_failed'];
            }
        }

        return ['success' => true, 'job' => $this->toArray($job), 'duplicated' => false];
    }

    private function createExtensionBatch(array $payload, string $contentKind, string $now): ?SocialPublishBatch
    {
        $title = \trim((string)($payload['title'] ?? ''));
        if ($title === '') {
            $title = 'Extension publish';
        }
        $title = \function_exists('mb_substr') ? \mb_substr($title, 0, 190) : \substr($title, 0, 190);
        $contentKind = \function_exists('mb_substr') ? \mb_substr($contentKind, 0, 32) : \substr($contentKind, 0, 32);

        $batch = $this->newBatch();
        $batch->setData([
            SocialPublishBatch::schema_fields_DRAFT_ID => 0,
            SocialPublishBatch::schema_fields_TITLE => $title,
            SocialPublishBatch::schema_fields_STATUS => SocialPublishBatch::STATUS_PENDING,
            SocialPublishBatch::schema_fields_PUBLISH_SCOPE => 'extension',
            SocialPublishBatch::schema_fields_CONTENT_KIND => $contentKind,
            SocialPublishBatch::schema_fields_TARGET_COUNT => 1,
            SocialPublishBatch::schema_fields_SUCCESS_COUNT => 0,
            SocialPublishBatch::schema_fields_FAILED_COUNT => 0,
            SocialPublishBatch::schema_fields_CREATED_AT => $now,
            SocialPublishBatch::schema_fields_UPDATED_AT => $now,
        ])->save();

        return $batch->getId() ? $batch : null;
    }

    private function createExtensionTarget(
        SocialExtensionJob $job,
        SocialPublishBatch $batch,
        int $accountId,
        string $platformCode,
        string $contentKind,
        string $now
    ): ?SocialPublishTarget {
        $contentKind = \function_exists('mb_substr') ? \mb_substr($contentKind, 0, 16) : \substr($contentKind, 0, 16);
        $target = $this->newTarget();
        $target->setData([
            SocialPublishTarget::schema_fields_BATCH_ID => (int)$batch->getId(),
            SocialPublishTarget::schema_fields_ACCOUNT_ID => $accountId,
            SocialPublishTarget::schema_fields_PLATFORM_CODE => $platformCode,
            SocialPublishTarget::schema_fields_STATUS => SocialPublishTarget::STATUS_PENDING,
            SocialPublishTarget::schema_fields_CHANNEL => 'extension',
            SocialPublishTarget::schema_fields_CONTENT_KIND => $contentKind,
            SocialPublishTarget::schema_fields_IDEMPOTENCY_KEY => 'social-ext:' . (int)$job->getId(),
            SocialPublishTarget::schema_fields_CREATED_AT => $now,
            SocialPublishTarget::schema_fields_UPDATED_AT => $now,
        ])->save();

        return $target->getId() ? $target : null;
    }

    private function rollbackExtensionCreation(
        ?SocialPublishTarget $target,
        ?SocialPublishBatch $batch,
        SocialExtensionJob $job
    ): void {
        try {
            $target?->delete();
        } catch (\Throwable) {
        }
        try {
            $batch?->delete();
        } catch (\Throwable) {
        }
        try {
            $job->delete();
        } catch (\Throwable) {
        }
    }

    private function newBatch(): SocialPublishBatch
    {
        return ($this->objectManager ?? ObjectManager::getInstance())->getInstance(SocialPublishBatch::class);
    }

    private function newTarget(): SocialPublishTarget
    {
        return ($this->objectManager ?? ObjectManager::getInstance())->getInstance(SocialPublishTarget::class);
    }

    private function newLog(): SocialPublishLog
    {
        return ($this->objectManager ?? ObjectManager::getInstance())->getInstance(SocialPublishLog::class);
    }

    public function findByTarget(int $targetId): ?SocialExtensionJob
    {
        if ($targetId < 1) {
            return null;
        }

        $rows = $this->newJob()->reset()
            ->where(SocialExtensionJob::schema_fields_TARGET_ID, $targetId)
            ->order(SocialExtensionJob::schema_fields_ID, 'DESC')
            ->limit(1)
            ->select()
            ->fetch();

        $items = \is_object($rows) && \method_exists($rows, 'getItems') ? $rows->getItems() : $rows;
        if (!\is_array($items)) {
            return null;
        }

        $item = $items[0] ?? null;
        if ($item instanceof SocialExtensionJob) {
            return $item;
        }

        if (\is_array($item)) {
            $job = $this->newJob()->reset()->load((int)($item[SocialExtensionJob::schema_fields_ID] ?? 0));
            return $job->getId() ? $job : null;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPending(int $websiteId = 0, int $limit = 20): array
    {
        $limit = \max(1, \min(100, $limit));
        $query = $this->newJob()->reset()
            ->where(SocialExtensionJob::schema_fields_STATUS, SocialExtensionJob::STATUS_PENDING);
        if ($websiteId > 0) {
            $query->where(SocialExtensionJob::schema_fields_WEBSITE_ID, $websiteId);
        }

        $rows = $query->order(SocialExtensionJob::schema_fields_ID, 'ASC')
            ->limit($limit)
            ->select()
            ->fetch();

        $items = \is_object($rows) && \method_exists($rows, 'getItems') ? $rows->getItems() : $rows;
        if (!\is_array($items)) {
            return [];
        }

        $jobs = [];
        foreach ($items as $item) {
            $data = $this->toArray($item);
            if ($data !== null) {
                $jobs[] = $data;
            }
        }

        return $jobs;
    }

    public function claimNext(string $deviceId, int $websiteId = 0): ?array
    {
        $deviceId = \trim($deviceId);
        if ($deviceId === '') {
            return null;
        }
        $deviceId = \substr($deviceId, 0, 64);

        try {
            $device = $this->loadDevice($deviceId);
            if ($device === null || (string)($device->getData(SocialExtensionDevice::schema_fields_STATUS) ?? '') !== SocialExtensionDevice::STATUS_ACTIVE) {
                return null;
            }

            $deviceWebsiteId = \max(0, (int)($device->getData(SocialExtensionDevice::schema_fields_WEBSITE_ID) ?? 0));
            $deviceUserId = \max(0, (int)($device->getData(SocialExtensionDevice::schema_fields_USER_ID) ?? 0));
            $forceZeroWebsite = $deviceWebsiteId === 0 && $deviceUserId === 0;
            if ($deviceWebsiteId > 0) {
                $scopeWebsiteId = $deviceWebsiteId;
            } elseif ($forceZeroWebsite) {
                // 0/0 设备是默认站设备，只能领取默认站任务，禁止跨站扫描。
                $scopeWebsiteId = 0;
            } else {
                $userWebsiteId = $this->userWebsiteId($deviceUserId);
                if ($userWebsiteId < 0) {
                    return null;
                }
                $scopeWebsiteId = $userWebsiteId;
            }
            $requestedWebsiteId = \max(0, $websiteId);
            if ($forceZeroWebsite) {
                if ($requestedWebsiteId > 0) {
                    return null;
                }
                $filterWebsiteId = 0;
            } elseif ($scopeWebsiteId > 0) {
                if ($requestedWebsiteId > 0 && $requestedWebsiteId !== $scopeWebsiteId) {
                    return null;
                }
                $filterWebsiteId = $scopeWebsiteId;
            } else {
                $filterWebsiteId = $requestedWebsiteId;
            }

            $this->expireStale(24);
            $this->recycleExpiredClaims();

            for ($round = 0; $round < 5; $round++) {
                $query = $this->newJob()->reset()
                    ->where(SocialExtensionJob::schema_fields_STATUS, SocialExtensionJob::STATUS_PENDING)
                    ->where(SocialExtensionJob::schema_fields_ATTEMPTS, 3, '<');
                $activeAccountIds = $this->activeAccountIds();
                if ($activeAccountIds !== []) {
                    $query->where(SocialExtensionJob::schema_fields_ACCOUNT_ID, $activeAccountIds, 'NOT IN');
                }
                if ($forceZeroWebsite) {
                    $query->where(SocialExtensionJob::schema_fields_WEBSITE_ID, 0);
                } elseif ($filterWebsiteId > 0) {
                    $query->where(SocialExtensionJob::schema_fields_WEBSITE_ID, $filterWebsiteId);
                }

                $rows = $query->order(SocialExtensionJob::schema_fields_ID, 'ASC')
                    ->limit(1)
                    ->select()
                    ->fetch();
                $items = \is_object($rows) && \method_exists($rows, 'getItems') ? $rows->getItems() : $rows;
                $item = \is_array($items) ? ($items[0] ?? null) : null;
                $data = $this->toArray($item);
                if ($data === null) {
                    return null;
                }

                $jobId = (int)($data[SocialExtensionJob::schema_fields_ID] ?? 0);
                $attempts = (int)($data[SocialExtensionJob::schema_fields_ATTEMPTS] ?? 0);
                if ($jobId < 1 || $attempts >= 3) {
                    return null;
                }

                $claimToken = \bin2hex(\random_bytes(16));
                $now = \date('Y-m-d H:i:s');
                $expiresAt = \date('Y-m-d H:i:s', \time() + 600);
                $updated = $this->newJob()->getQuery(false)
                    ->where(SocialExtensionJob::schema_fields_ID, $jobId)
                    ->where(SocialExtensionJob::schema_fields_STATUS, SocialExtensionJob::STATUS_PENDING)
                    ->where(SocialExtensionJob::schema_fields_ATTEMPTS, $attempts)
                    ->update([
                        SocialExtensionJob::schema_fields_STATUS => SocialExtensionJob::STATUS_CLAIMED,
                        SocialExtensionJob::schema_fields_CLAIM_TOKEN => $claimToken,
                        SocialExtensionJob::schema_fields_CLAIM_EXPIRES_AT => $expiresAt,
                        SocialExtensionJob::schema_fields_CLAIMED_BY => $deviceId,
                        SocialExtensionJob::schema_fields_ATTEMPTS => $attempts + 1,
                        SocialExtensionJob::schema_fields_UPDATED_AT => $now,
                    ])
                    ->fetch();

                $job = $this->loadJob($jobId);
                if ($job === null) {
                    continue;
                }
                $storedToken = (string)($job->getData(SocialExtensionJob::schema_fields_CLAIM_TOKEN) ?? '');
                if ($storedToken !== '' && \hash_equals($storedToken, $claimToken)) {
                    return $this->toArray($job);
                }
                if ($updated === true) {
                    continue;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    public function heartbeat(string $jobId, string $claimToken, string $version): array
    {
        try {
            $job = $this->loadJob((int)$jobId);
            if ($job === null) {
                return ['ok' => false, 'status' => 'not_found', 'error' => 'job_not_found'];
            }

            $status = (string)($job->getData(SocialExtensionJob::schema_fields_STATUS) ?? '');
            if (!$this->claimTokenMatches($job, $claimToken)) {
                return ['ok' => false, 'status' => $status, 'error' => 'invalid_claim_token'];
            }
            if ($this->isTerminalStatus($status)) {
                return ['ok' => false, 'status' => $status, 'error' => 'job_finished'];
            }
            if (!\in_array($status, [SocialExtensionJob::STATUS_CLAIMED, SocialExtensionJob::STATUS_RUNNING], true)) {
                return ['ok' => false, 'status' => $status, 'error' => 'job_not_claimed'];
            }

            $now = \date('Y-m-d H:i:s');
            $expiresAt = \date('Y-m-d H:i:s', \time() + 600);
            $updated = $this->newJob()->getQuery(false)
                ->where(SocialExtensionJob::schema_fields_ID, (int)$job->getId())
                ->where(SocialExtensionJob::schema_fields_CLAIM_TOKEN, $claimToken)
                ->where(SocialExtensionJob::schema_fields_STATUS, $status)
                ->update([
                    SocialExtensionJob::schema_fields_CLAIM_EXPIRES_AT => $expiresAt,
                    SocialExtensionJob::schema_fields_UPDATED_AT => $now,
                ])
                ->fetch();

            if ($updated !== true) {
                $fresh = $this->loadJob((int)$job->getId());
                if ($fresh === null || (string)($fresh->getData(SocialExtensionJob::schema_fields_CLAIM_EXPIRES_AT) ?? '') !== $expiresAt) {
                    return ['ok' => false, 'status' => $status, 'error' => 'write_failed'];
                }
            }

            $this->touchDevice($job, $version, $now);

            return ['ok' => true, 'status' => $status, 'expires_at' => $expiresAt];
        } catch (\Throwable) {
            return ['ok' => false, 'status' => 'error', 'error' => 'internal_error'];
        }
    }

    public function report(string $jobId, string $claimToken, array $result): array
    {
        try {
            $job = $this->loadJob((int)$jobId);
            if ($job === null) {
                return ['ok' => false, 'duplicated' => false, 'status' => 'not_found', 'error' => 'job_not_found'];
            }

            $currentStatus = (string)($job->getData(SocialExtensionJob::schema_fields_STATUS) ?? '');
            if (!$this->claimTokenMatches($job, $claimToken)) {
                return ['ok' => false, 'duplicated' => false, 'status' => $currentStatus, 'error' => 'invalid_claim_token'];
            }

            $statusValue = $result['status'] ?? '';
            $status = \is_scalar($statusValue) ? \strtolower(\trim((string)$statusValue)) : '';
            if (!\in_array($status, [
                SocialExtensionJob::STATUS_SUCCEEDED,
                SocialExtensionJob::STATUS_FAILED,
                SocialExtensionJob::STATUS_SKIPPED,
                self::STATUS_UNVERIFIED,
            ], true)) {
                return ['ok' => false, 'duplicated' => false, 'status' => $currentStatus, 'error' => 'invalid_status'];
            }
            if ($currentStatus === SocialExtensionJob::STATUS_STALE) {
                return ['ok' => false, 'duplicated' => false, 'status' => $currentStatus, 'error' => 'job_stale'];
            }
            if ($this->isTerminalStatus($currentStatus)) {
                $storedResult = \json_decode((string)($job->getData(SocialExtensionJob::schema_fields_RESULT_JSON) ?? ''), true);
                $this->syncPublishRecord(
                    $job,
                    $currentStatus,
                    \is_array($storedResult) ? $storedResult : [],
                    (string)($job->getData(SocialExtensionJob::schema_fields_ERROR_CODE) ?? '')
                );

                return ['ok' => true, 'duplicated' => true, 'status' => $currentStatus];
            }
            if (!\in_array($currentStatus, [SocialExtensionJob::STATUS_CLAIMED, SocialExtensionJob::STATUS_RUNNING], true)) {
                return ['ok' => false, 'duplicated' => false, 'status' => $currentStatus, 'error' => 'job_not_claimed'];
            }

            $invalidRemoteUrl = false;
            $remoteUrl = $result['remote_url'] ?? null;
            if ($remoteUrl !== null && $remoteUrl !== '' && (!\is_string($remoteUrl) || !$this->isHttpsUrl($remoteUrl))) {
                $invalidRemoteUrl = true;
                $status = SocialExtensionJob::STATUS_FAILED;
                $result['status'] = SocialExtensionJob::STATUS_FAILED;
                $result['error_code'] = 'invalid_remote_url';
            }

            foreach (['claim_token', 'token'] as $sensitiveKey) {
                unset($result[$sensitiveKey]);
            }
            $errorCode = $this->normalizeErrorCode($result['error_code'] ?? $result['error'] ?? '');
            $now = \date('Y-m-d H:i:s');
            $updated = $this->newJob()->getQuery(false)
                ->where(SocialExtensionJob::schema_fields_ID, (int)$job->getId())
                ->where(SocialExtensionJob::schema_fields_CLAIM_TOKEN, $claimToken)
                ->where(SocialExtensionJob::schema_fields_STATUS, $currentStatus)
                ->update([
                    SocialExtensionJob::schema_fields_STATUS => $status,
                    SocialExtensionJob::schema_fields_RESULT_JSON => $this->encodeJson($result),
                    SocialExtensionJob::schema_fields_ERROR_CODE => $errorCode !== '' ? $errorCode : null,
                    SocialExtensionJob::schema_fields_CLAIM_EXPIRES_AT => null,
                    SocialExtensionJob::schema_fields_UPDATED_AT => $now,
                ])
                ->fetch();

            if ($updated !== true) {
                $fresh = $this->loadJob((int)$job->getId());
                if ($fresh === null || !$this->claimTokenMatches($fresh, $claimToken)) {
                    return ['ok' => false, 'duplicated' => false, 'status' => $currentStatus, 'error' => 'write_failed'];
                }
                $freshStatus = (string)($fresh->getData(SocialExtensionJob::schema_fields_STATUS) ?? '');
                if ($freshStatus === $status) {
                    $this->syncPublishRecord($fresh, $status, $result, $errorCode);

                    return $invalidRemoteUrl
                        ? ['ok' => false, 'duplicated' => false, 'status' => $status, 'error' => 'invalid_remote_url', 'error_code' => 'invalid_remote_url']
                        : ['ok' => true, 'duplicated' => false, 'status' => $status];
                }
                if ($this->isTerminalStatus($freshStatus)) {
                    $storedResult = \json_decode((string)($fresh->getData(SocialExtensionJob::schema_fields_RESULT_JSON) ?? ''), true);
                    $this->syncPublishRecord(
                        $fresh,
                        $freshStatus,
                        \is_array($storedResult) ? $storedResult : [],
                        (string)($fresh->getData(SocialExtensionJob::schema_fields_ERROR_CODE) ?? '')
                    );

                    return ['ok' => true, 'duplicated' => true, 'status' => $freshStatus];
                }

                return ['ok' => false, 'duplicated' => false, 'status' => $freshStatus, 'error' => 'write_failed'];
            }

            $this->syncPublishRecord($job, $status, $result, $errorCode);

            if ($invalidRemoteUrl) {
                return ['ok' => false, 'duplicated' => false, 'status' => $status, 'error' => 'invalid_remote_url', 'error_code' => 'invalid_remote_url'];
            }

            return ['ok' => true, 'duplicated' => false, 'status' => $status];
        } catch (\Throwable) {
            return ['ok' => false, 'duplicated' => false, 'status' => 'error', 'error' => 'internal_error'];
        }
    }

    public function release(string $jobId, string $claimToken, string $reason): array
    {
        try {
            $job = $this->loadJob((int)$jobId);
            if ($job === null) {
                return ['ok' => false, 'status' => 'not_found', 'error' => 'job_not_found'];
            }

            $currentStatus = (string)($job->getData(SocialExtensionJob::schema_fields_STATUS) ?? '');
            if (!$this->claimTokenMatches($job, $claimToken)) {
                return ['ok' => false, 'status' => $currentStatus, 'error' => 'invalid_claim_token'];
            }
            if ($currentStatus === SocialExtensionJob::STATUS_STALE) {
                return ['ok' => false, 'status' => $currentStatus, 'error' => 'job_stale'];
            }
            if ($this->isTerminalStatus($currentStatus)) {
                return ['ok' => false, 'status' => $currentStatus, 'error' => 'job_finished'];
            }
            if (!\in_array($currentStatus, [SocialExtensionJob::STATUS_CLAIMED, SocialExtensionJob::STATUS_RUNNING], true)) {
                return ['ok' => false, 'status' => $currentStatus, 'error' => 'job_not_claimed'];
            }

            $reason = \trim($reason);
            $reason = \function_exists('mb_substr') ? \mb_substr($reason, 0, 500) : \substr($reason, 0, 500);
            $now = \date('Y-m-d H:i:s');
            $updated = $this->newJob()->getQuery(false)
                ->where(SocialExtensionJob::schema_fields_ID, (int)$job->getId())
                ->where(SocialExtensionJob::schema_fields_CLAIM_TOKEN, $claimToken)
                ->where(SocialExtensionJob::schema_fields_STATUS, $currentStatus)
                ->update([
                    SocialExtensionJob::schema_fields_STATUS => SocialExtensionJob::STATUS_PENDING,
                    SocialExtensionJob::schema_fields_CLAIM_TOKEN => null,
                    SocialExtensionJob::schema_fields_CLAIM_EXPIRES_AT => null,
                    SocialExtensionJob::schema_fields_CLAIMED_BY => null,
                    SocialExtensionJob::schema_fields_RESULT_JSON => $this->encodeJson(['release_reason' => $reason]),
                    SocialExtensionJob::schema_fields_ERROR_CODE => null,
                    SocialExtensionJob::schema_fields_UPDATED_AT => $now,
                ])
                ->fetch();

            if ($updated !== true) {
                $fresh = $this->loadJob((int)$job->getId());
                if ($fresh === null || (string)($fresh->getData(SocialExtensionJob::schema_fields_STATUS) ?? '') !== SocialExtensionJob::STATUS_PENDING) {
                    return ['ok' => false, 'status' => $currentStatus, 'error' => 'write_failed'];
                }
            }

            return ['ok' => true, 'status' => SocialExtensionJob::STATUS_PENDING];
        } catch (\Throwable) {
            return ['ok' => false, 'status' => 'error', 'error' => 'internal_error'];
        }
    }

    public function expireStale(int $hours = 24): int
    {
        $hours = \max(1, \min(24 * 365, $hours));

        try {
            $cutoff = \date('Y-m-d H:i:s', \time() - $hours * 3600);
            $rows = $this->newJob()->reset()
                ->where(SocialExtensionJob::schema_fields_STATUS, SocialExtensionJob::STATUS_PENDING)
                ->where(SocialExtensionJob::schema_fields_CREATED_AT, $cutoff, '<')
                ->select()
                ->fetch();
            $items = \is_object($rows) && \method_exists($rows, 'getItems') ? $rows->getItems() : $rows;
            if (!\is_array($items)) {
                return 0;
            }

            $ids = [];
            foreach ($items as $item) {
                $data = $this->toArray($item);
                $id = (int)($data[SocialExtensionJob::schema_fields_ID] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $ids = \array_values(\array_unique($ids));
            if ($ids === []) {
                return 0;
            }

            $updated = $this->newJob()->getQuery(false)
                ->where(SocialExtensionJob::schema_fields_ID, $ids, 'IN')
                ->where(SocialExtensionJob::schema_fields_STATUS, SocialExtensionJob::STATUS_PENDING)
                ->update([
                    SocialExtensionJob::schema_fields_STATUS => SocialExtensionJob::STATUS_STALE,
                    SocialExtensionJob::schema_fields_UPDATED_AT => \date('Y-m-d H:i:s'),
                ])
                ->fetch();

            return $updated === true ? \count($ids) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return list<int>
     */
    private function activeAccountIds(): array
    {
        $rows = $this->newJob()->reset()
            ->where(SocialExtensionJob::schema_fields_STATUS, [
                SocialExtensionJob::STATUS_CLAIMED,
                SocialExtensionJob::STATUS_RUNNING,
            ], 'IN')
            ->select()
            ->fetch();
        $items = \is_object($rows) && \method_exists($rows, 'getItems') ? $rows->getItems() : $rows;
        if (!\is_array($items)) {
            return [];
        }

        $accountIds = [];
        foreach ($items as $item) {
            $data = $this->toArray($item);
            $accountId = (int)($data[SocialExtensionJob::schema_fields_ACCOUNT_ID] ?? 0);
            if ($accountId > 0) {
                $accountIds[] = $accountId;
            }
        }

        return \array_values(\array_unique($accountIds));
    }

    private function recycleExpiredClaims(): void
    {
        $now = \date('Y-m-d H:i:s');

        try {
            $this->newJob()->getQuery(false)
                ->where(SocialExtensionJob::schema_fields_STATUS, [
                    SocialExtensionJob::STATUS_CLAIMED,
                    SocialExtensionJob::STATUS_RUNNING,
                ], 'IN')
                ->where(SocialExtensionJob::schema_fields_CLAIM_EXPIRES_AT, $now, '<')
                ->where(SocialExtensionJob::schema_fields_ATTEMPTS, 3, '<')
                ->update([
                    SocialExtensionJob::schema_fields_STATUS => SocialExtensionJob::STATUS_PENDING,
                    SocialExtensionJob::schema_fields_CLAIM_TOKEN => null,
                    SocialExtensionJob::schema_fields_CLAIM_EXPIRES_AT => null,
                    SocialExtensionJob::schema_fields_CLAIMED_BY => null,
                    SocialExtensionJob::schema_fields_UPDATED_AT => $now,
                ])
                ->fetch();

            $this->newJob()->getQuery(false)
                ->where(SocialExtensionJob::schema_fields_STATUS, [
                    SocialExtensionJob::STATUS_CLAIMED,
                    SocialExtensionJob::STATUS_RUNNING,
                ], 'IN')
                ->where(SocialExtensionJob::schema_fields_CLAIM_EXPIRES_AT, $now, '<')
                ->where(SocialExtensionJob::schema_fields_ATTEMPTS, 3, '>=')
                ->update([
                    SocialExtensionJob::schema_fields_STATUS => SocialExtensionJob::STATUS_FAILED,
                    SocialExtensionJob::schema_fields_CLAIM_TOKEN => null,
                    SocialExtensionJob::schema_fields_CLAIM_EXPIRES_AT => null,
                    SocialExtensionJob::schema_fields_CLAIMED_BY => null,
                    SocialExtensionJob::schema_fields_ERROR_CODE => 'attempts_exhausted',
                    SocialExtensionJob::schema_fields_UPDATED_AT => $now,
                ])
                ->fetch();

            $this->newJob()->getQuery(false)
                ->where(SocialExtensionJob::schema_fields_STATUS, SocialExtensionJob::STATUS_PENDING)
                ->where(SocialExtensionJob::schema_fields_ATTEMPTS, 3, '>=')
                ->update([
                    SocialExtensionJob::schema_fields_STATUS => SocialExtensionJob::STATUS_FAILED,
                    SocialExtensionJob::schema_fields_ERROR_CODE => 'attempts_exhausted',
                    SocialExtensionJob::schema_fields_UPDATED_AT => $now,
                ])
                ->fetch();
        } catch (\Throwable) {
            // 租约回收失败不能阻断后续 claim 主流程。
        }
    }

    private function loadDevice(string $deviceId): ?SocialExtensionDevice
    {
        $deviceId = \trim($deviceId);
        if ($deviceId === '' || !\ctype_digit($deviceId)) {
            return null;
        }

        $device = $this->newDevice()->reset()->load((int)$deviceId, null, true);

        return $device->getId() ? $device : null;
    }

    private function userWebsiteId(int $userId): int
    {
        if ($userId < 1 || $userId === 1) {
            return 0;
        }

        try {
            $user = ($this->objectManager ?? ObjectManager::getInstance())->getInstance(BackendUser::class);
            $user->clearData()->clearQuery()->load($userId, null, true);
            if (!$user->getId()) {
                return -1;
            }

            return \max(0, (int)($user->getData(BackendUser::schema_fields_website_id) ?? 0));
        } catch (\Throwable) {
            return -1;
        }
    }

    private function syncPublishRecord(
        SocialExtensionJob $job,
        string $jobStatus,
        array $result,
        string $errorCode
    ): void {
        try {
            $targetId = (int)($job->getData(SocialExtensionJob::schema_fields_TARGET_ID) ?? 0);
            if ($targetId < 1) {
                return;
            }

            $target = $this->newTarget()->reset()->load($targetId, null, true);
            if (!$target->getId()) {
                return;
            }

            $targetStatus = $this->targetStatusForJob($jobStatus);
            $logStatus = $this->logStatusForJob($jobStatus);
            $now = \date('Y-m-d H:i:s');

            $remoteUrl = $result['remote_url'] ?? '';
            $remoteUrl = \is_scalar($remoteUrl) ? \trim((string)$remoteUrl) : '';
            $remoteId = $result['remote_id'] ?? '';
            $remoteId = \is_scalar($remoteId) ? \trim((string)$remoteId) : '';
            $error = $result['error'] ?? $result['message'] ?? '';
            $errorMessage = \is_scalar($error) ? \trim((string)$error) : '';
            if ($errorMessage === '') {
                $errorMessage = \trim($errorCode);
            }
            if ($targetStatus === SocialPublishTarget::STATUS_SUCCEEDED) {
                $errorMessage = '';
            }

            $logExists = $this->publishLogExists($targetId, $logStatus);
            $existingTargetStatus = (string)($target->getData(SocialPublishTarget::schema_fields_STATUS) ?? '');
            if ($existingTargetStatus === $targetStatus && $logExists) {
                $this->refreshBatchCounts((int)($target->getData(SocialPublishTarget::schema_fields_BATCH_ID) ?? 0));

                return;
            }

            if ($remoteUrl === '') {
                $remoteUrl = \trim((string)($target->getData(SocialPublishTarget::schema_fields_REMOTE_URL) ?? ''));
            }
            if ($remoteUrl !== '' && !$this->isHttpsUrl($remoteUrl)) {
                $remoteUrl = '';
            }
            if ($remoteId === '') {
                $remoteId = \trim((string)($target->getData(SocialPublishTarget::schema_fields_REMOTE_ID) ?? ''));
            }
            if ($errorMessage === '' && $targetStatus !== SocialPublishTarget::STATUS_SUCCEEDED) {
                $errorMessage = \trim((string)($target->getData(SocialPublishTarget::schema_fields_ERROR_MESSAGE) ?? ''));
            }

            $targetData = [
                SocialPublishTarget::schema_fields_STATUS => $targetStatus,
                SocialPublishTarget::schema_fields_ERROR_MESSAGE => $errorMessage !== '' ? $errorMessage : null,
                SocialPublishTarget::schema_fields_UPDATED_AT => $now,
            ];
            if ($remoteUrl !== '') {
                $targetData[SocialPublishTarget::schema_fields_REMOTE_URL] = $remoteUrl;
            }
            if ($remoteId !== '') {
                $targetData[SocialPublishTarget::schema_fields_REMOTE_ID] = $remoteId;
            }
            if ($targetStatus === SocialPublishTarget::STATUS_SUCCEEDED) {
                $existingPublishedAt = \trim((string)($target->getData(SocialPublishTarget::schema_fields_PUBLISHED_AT) ?? ''));
                $targetData[SocialPublishTarget::schema_fields_PUBLISHED_AT] = $existingPublishedAt !== '' ? $existingPublishedAt : $now;
            } else {
                $targetData[SocialPublishTarget::schema_fields_PUBLISHED_AT] = null;
            }

            try {
                $target->setData($targetData)->save();
            } catch (\Throwable) {
            }

            try {
                if (!$logExists) {
                    $platformCode = \trim((string)($target->getData(SocialPublishTarget::schema_fields_PLATFORM_CODE) ?? ''));
                    if ($platformCode === '') {
                        $platformCode = \trim((string)($job->getData(SocialExtensionJob::schema_fields_PLATFORM_CODE) ?? ''));
                    }
                    $contentKind = \trim((string)($job->getData(SocialExtensionJob::schema_fields_CONTENT_KIND) ?? ''));
                    if ($contentKind === '') {
                        $contentKind = \trim((string)($target->getData(SocialPublishTarget::schema_fields_CONTENT_KIND) ?? ''));
                    }
                    $response = [
                        'remote_url' => $remoteUrl,
                        'remote_id' => $remoteId,
                        'channel' => 'extension',
                        'content_kind' => $contentKind,
                        'status' => $jobStatus,
                    ];
                    if ($errorMessage !== '') {
                        $response['error'] = $errorMessage;
                    }

                    $log = $this->newLog();
                    $log->setData([
                        SocialPublishLog::schema_fields_TARGET_ID => $targetId,
                        SocialPublishLog::schema_fields_PLATFORM_CODE => $platformCode,
                        SocialPublishLog::schema_fields_STATUS => $logStatus,
                        SocialPublishLog::schema_fields_REQUEST_JSON => $this->encodeJson([
                            'job_id' => (int)$job->getId(),
                            'account_id' => (int)($job->getData(SocialExtensionJob::schema_fields_ACCOUNT_ID) ?? 0),
                            'platform_code' => $platformCode,
                        ]),
                        SocialPublishLog::schema_fields_RESPONSE_JSON => $this->encodeJson($response),
                        SocialPublishLog::schema_fields_ERROR_MESSAGE => $errorMessage !== '' ? $errorMessage : null,
                        SocialPublishLog::schema_fields_CREATED_AT => $now,
                    ])->save();
                }
            } catch (\Throwable) {
            }

            try {
                $this->refreshBatchCounts((int)($target->getData(SocialPublishTarget::schema_fields_BATCH_ID) ?? 0));
            } catch (\Throwable) {
            }
        } catch (\Throwable) {
        }
    }

    private function targetStatusForJob(string $jobStatus): string
    {
        return match ($jobStatus) {
            SocialExtensionJob::STATUS_SUCCEEDED => SocialPublishTarget::STATUS_SUCCEEDED,
            SocialExtensionJob::STATUS_FAILED => SocialPublishTarget::STATUS_FAILED,
            default => SocialPublishTarget::STATUS_SKIPPED,
        };
    }

    private function logStatusForJob(string $jobStatus): string
    {
        return match ($jobStatus) {
            SocialExtensionJob::STATUS_SUCCEEDED => 'success',
            SocialExtensionJob::STATUS_FAILED => 'failed',
            default => 'skipped',
        };
    }

    private function publishLogExists(int $targetId, string $status): bool
    {
        try {
            $rows = $this->newLog()->reset()
                ->where(SocialPublishLog::schema_fields_TARGET_ID, $targetId)
                ->where(SocialPublishLog::schema_fields_STATUS, $status)
                ->limit(1)
                ->select()
                ->fetchArray();

            return \is_array($rows) && $rows !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function refreshBatchCounts(int $batchId): void
    {
        try {
            if ($batchId < 1) {
                return;
            }
            $batch = $this->newBatch()->reset()->load($batchId, null, true);
            if (!$batch->getId()) {
                return;
            }

            $rows = $this->newTarget()->reset()
                ->where(SocialPublishTarget::schema_fields_BATCH_ID, $batchId)
                ->select()
                ->fetchArray();
            if (!\is_array($rows) || $rows === []) {
                return;
            }

            $successCount = 0;
            $failedCount = 0;
            $allTerminal = true;
            foreach ($rows as $row) {
                $status = \is_array($row) ? (string)($row[SocialPublishTarget::schema_fields_STATUS] ?? '') : '';
                if ($status === SocialPublishTarget::STATUS_SUCCEEDED) {
                    $successCount++;
                } elseif ($status === SocialPublishTarget::STATUS_FAILED || $status === SocialPublishTarget::STATUS_SKIPPED) {
                    $failedCount++;
                } else {
                    $allTerminal = false;
                }
            }

            $batchStatus = $allTerminal
                ? ($failedCount > 0 ? SocialPublishBatch::STATUS_FAILED : 'succeeded')
                : SocialPublishBatch::STATUS_PENDING;

            $this->newBatch()->reset()
                ->where(SocialPublishBatch::schema_fields_ID, $batchId)
                ->update([
                    SocialPublishBatch::schema_fields_SUCCESS_COUNT => $successCount,
                    SocialPublishBatch::schema_fields_FAILED_COUNT => $failedCount,
                    SocialPublishBatch::schema_fields_STATUS => $batchStatus,
                    SocialPublishBatch::schema_fields_UPDATED_AT => \date('Y-m-d H:i:s'),
                ])
                ->fetch();
        } catch (\Throwable) {
        }
    }

    private function loadJob(int $jobId): ?SocialExtensionJob
    {
        if ($jobId < 1) {
            return null;
        }

        $job = $this->newJob()->reset()->load($jobId, null, true);

        return $job->getId() ? $job : null;
    }

    private function claimTokenMatches(SocialExtensionJob $job, string $claimToken): bool
    {
        $claimToken = \trim($claimToken);
        $stored = (string)($job->getData(SocialExtensionJob::schema_fields_CLAIM_TOKEN) ?? '');

        return $claimToken !== '' && $stored !== '' && \hash_equals($stored, $claimToken);
    }

    private function isTerminalStatus(string $status): bool
    {
        return \in_array($status, [
            SocialExtensionJob::STATUS_SUCCEEDED,
            SocialExtensionJob::STATUS_FAILED,
            SocialExtensionJob::STATUS_SKIPPED,
            self::STATUS_UNVERIFIED,
            SocialExtensionJob::STATUS_STALE,
        ], true);
    }

    private function isHttpsUrl(string $url): bool
    {
        $url = \trim($url);
        if ($url === '' || \preg_match('/\s/', $url) === 1) {
            return false;
        }

        $parts = \parse_url($url);

        return \is_array($parts)
            && \strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && \trim((string)($parts['host'] ?? '')) !== '';
    }

    private function touchDevice(SocialExtensionJob $job, string $version, string $now): void
    {
        $deviceId = (int)($job->getData(SocialExtensionJob::schema_fields_CLAIMED_BY) ?? 0);
        if ($deviceId < 1) {
            return;
        }

        try {
            $device = $this->newDevice()->reset()->load($deviceId);
            if (!$device->getId()) {
                return;
            }
            $version = \substr(\trim($version), 0, 32);
            if ($version !== '') {
                $device->setData(SocialExtensionDevice::schema_fields_VERSION, $version);
            }
            $device->setData(SocialExtensionDevice::schema_fields_LAST_SEEN_AT, $now);
            $device->save();
        } catch (\Throwable) {
            // 设备元数据写入失败不能影响 job 续租结果。
        }
    }

    private function newDevice(): SocialExtensionDevice
    {
        return ($this->objectManager ?? ObjectManager::getInstance())->getInstance(SocialExtensionDevice::class);
    }

    private function encodeJson(array $data): string
    {
        $encoded = \json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return \is_string($encoded) ? $encoded : '{}';
    }

    private function normalizeErrorCode(mixed $value): string
    {
        $value = \is_scalar($value) ? \trim((string)$value) : '';
        $value = \preg_replace('/[^A-Za-z0-9_.:-]+/', '_', $value) ?? '';
        $value = \trim($value, '_.:-');

        return \substr($value, 0, 64);
    }

    private function toArray(mixed $item): ?array
    {
        if ($item instanceof SocialExtensionJob) {
            if (\method_exists($item, 'toArrayData')) {
                return $item->toArrayData();
            }

            return [
                SocialExtensionJob::schema_fields_ID => (int)($item->getData(SocialExtensionJob::schema_fields_ID) ?? 0),
                SocialExtensionJob::schema_fields_TARGET_ID => $item->getData(SocialExtensionJob::schema_fields_TARGET_ID),
                SocialExtensionJob::schema_fields_ACCOUNT_ID => $item->getData(SocialExtensionJob::schema_fields_ACCOUNT_ID),
                SocialExtensionJob::schema_fields_WEBSITE_ID => $item->getData(SocialExtensionJob::schema_fields_WEBSITE_ID),
                SocialExtensionJob::schema_fields_PLATFORM_CODE => $item->getData(SocialExtensionJob::schema_fields_PLATFORM_CODE),
                SocialExtensionJob::schema_fields_CONTENT_KIND => $item->getData(SocialExtensionJob::schema_fields_CONTENT_KIND),
                SocialExtensionJob::schema_fields_RECIPE_VERSION => $item->getData(SocialExtensionJob::schema_fields_RECIPE_VERSION),
                SocialExtensionJob::schema_fields_PAYLOAD_JSON => $item->getData(SocialExtensionJob::schema_fields_PAYLOAD_JSON),
                SocialExtensionJob::schema_fields_STATUS => $item->getData(SocialExtensionJob::schema_fields_STATUS),
                SocialExtensionJob::schema_fields_ATTEMPTS => (int)($item->getData(SocialExtensionJob::schema_fields_ATTEMPTS) ?? 0),
                SocialExtensionJob::schema_fields_CLAIM_TOKEN => $item->getData(SocialExtensionJob::schema_fields_CLAIM_TOKEN),
                SocialExtensionJob::schema_fields_CLAIM_EXPIRES_AT => $item->getData(SocialExtensionJob::schema_fields_CLAIM_EXPIRES_AT),
                SocialExtensionJob::schema_fields_CLAIMED_BY => $item->getData(SocialExtensionJob::schema_fields_CLAIMED_BY),
                SocialExtensionJob::schema_fields_RESULT_JSON => $item->getData(SocialExtensionJob::schema_fields_RESULT_JSON),
                SocialExtensionJob::schema_fields_ERROR_CODE => $item->getData(SocialExtensionJob::schema_fields_ERROR_CODE),
                SocialExtensionJob::schema_fields_CREATED_AT => $item->getData(SocialExtensionJob::schema_fields_CREATED_AT),
                SocialExtensionJob::schema_fields_UPDATED_AT => $item->getData(SocialExtensionJob::schema_fields_UPDATED_AT),
            ];
        }

        if (!\is_array($item)) {
            return null;
        }

        $data = $item;
        $data[SocialExtensionJob::schema_fields_ID] = (int)($data[SocialExtensionJob::schema_fields_ID] ?? 0);
        $data[SocialExtensionJob::schema_fields_ATTEMPTS] = (int)($data[SocialExtensionJob::schema_fields_ATTEMPTS] ?? 0);

        return $data;
    }
}
