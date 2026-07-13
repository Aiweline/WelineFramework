<?php

declare(strict_types=1);

namespace Weline\Social\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Api\QueueStatus;
use Weline\Social\Model\SocialCreativeDraft;
use Weline\Social\Model\SocialPlatformAccount;
use Weline\Social\Model\SocialPublishBatch;
use Weline\Social\Model\SocialPublishLog;
use Weline\Social\Model\SocialPublishTarget;
use Weline\Social\Queue\SocialPublishQueue;

class SocialPublishService
{
    public function __construct(
        private readonly SocialPlatformRegistry $registry,
        private readonly SocialAccountService $accountService,
        private readonly SocialCreativeService $creativeService,
        private readonly SocialWebsiteAccountService $websiteAccountService,
        private readonly ?ObjectManager $objectManager = null
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function createPublishBatch(array $params): array
    {
        $draftId = (int)($params['draft_id'] ?? 0);
        $draft = $this->creativeService->getDraft($draftId);
        if (!$draft instanceof SocialCreativeDraft) {
            throw new \InvalidArgumentException((string)__('创意草稿不存在。'));
        }

        $scope = $this->resolvePublishScope($params);
        $accountIds = \array_values(\array_unique(\array_map('intval', (array)($params['account_ids'] ?? []))));
        if ($accountIds === [] && ($scope['all_sites'] || $scope['website_ids'] !== [] || !empty($scope['scope']))) {
            $resolved = $this->websiteAccountService->resolvePublishAccounts([
                'website_ids' => $scope['website_ids'],
                'all_sites' => $scope['all_sites'],
                'scope_type' => $scope['scope_type'],
                'scope_id' => $scope['scope_id'],
                'child_scope_type' => $scope['child_scope_type'],
                'child_scope_id' => $scope['child_scope_id'],
            ]);
            $accountIds = \array_values(\array_unique(\array_map('intval', (array)($resolved['account_ids'] ?? []))));
            $scope['website_ids'] = \array_values(\array_unique(\array_map('intval', (array)($resolved['website_ids'] ?? $scope['website_ids']))));
            $scope['resolved_scopes'] = \is_array($resolved['scopes'] ?? null) ? $resolved['scopes'] : [];
        }
        if ($accountIds === []) {
            throw new \InvalidArgumentException((string)__('请选择发布账户，或先为当前范围配置默认社媒账户。'));
        }

        $accounts = [];
        foreach ($accountIds as $accountId) {
            $account = $this->accountService->getAccount($accountId);
            if (!$account instanceof SocialPlatformAccount) {
                continue;
            }
            $accounts[] = $account;
        }
        if ($accounts === []) {
            throw new \InvalidArgumentException((string)__('没有可用的发布账户。'));
        }

        $now = \date('Y-m-d H:i:s');
        $batch = $this->newBatch();
        $batch->setData(SocialPublishBatch::schema_fields_DRAFT_ID, (int)$draft->getId())
            ->setData(SocialPublishBatch::schema_fields_TITLE, (string)($params['title'] ?? $draft->getData(SocialCreativeDraft::schema_fields_TITLE)))
            ->setData(SocialPublishBatch::schema_fields_STATUS, SocialPublishBatch::STATUS_PENDING)
            ->setData(SocialPublishBatch::schema_fields_PUBLISH_SCOPE, $scope['publish_scope'])
            ->setData(SocialPublishBatch::schema_fields_CONTENT_KIND, $scope['content_kind'])
            ->setData(SocialPublishBatch::schema_fields_WEBSITE_IDS_JSON, \json_encode($scope['website_ids'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->setData(SocialPublishBatch::schema_fields_SCOPE_JSON, \json_encode($scope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->setData(SocialPublishBatch::schema_fields_TARGET_COUNT, \count($accounts))
            ->setData(SocialPublishBatch::schema_fields_SUCCESS_COUNT, 0)
            ->setData(SocialPublishBatch::schema_fields_FAILED_COUNT, 0)
            ->setData(SocialPublishBatch::schema_fields_CREATED_AT, $now)
            ->setData(SocialPublishBatch::schema_fields_UPDATED_AT, $now)
            ->save();

        $targets = [];
        foreach ($accounts as $account) {
            $target = $this->createTarget($batch, $account, $draft, (string)($params['scheduled_at'] ?? ''));
            $targets[] = $target;
            if (!$this->isAccountPublishable($account)) {
                $this->markTargetFailed($target, (string)__('账户未启用发布或凭据未通过检测。'), [
                    'account_id' => (int)$account->getId(),
                    'platform_code' => (string)$account->getData(SocialPlatformAccount::schema_fields_PLATFORM_CODE),
                ]);
                continue;
            }
            $isFakeTarget = (bool)($params['fake_mode'] ?? false)
                || (string)$account->getData(SocialPlatformAccount::schema_fields_PLATFORM_CODE) === 'fake_browser';
            if ($isFakeTarget) {
                $this->processTarget((int)$target->getId());
                continue;
            }
            $this->enqueueTarget($target);
        }

        $this->refreshBatchStatus((int)$batch->getId());

        return [
            'success' => true,
            'batch' => $this->getBatchStatus((int)$batch->getId()),
        ];
    }

    public function processTarget(int $targetId): array
    {
        $target = $this->newTarget();
        $target->load($targetId);
        if (!$target->getId()) {
            throw new \InvalidArgumentException((string)__('发布目标不存在。'));
        }

        $account = $this->accountService->getAccount((int)$target->getData(SocialPublishTarget::schema_fields_ACCOUNT_ID));
        if (!$account instanceof SocialPlatformAccount) {
            return $this->markTargetFailed($target, (string)__('发布账户不存在。'), []);
        }
        if (!$this->isAccountPublishable($account)) {
            return $this->markTargetFailed($target, (string)__('账户未启用发布或凭据未通过检测。'), [
                'account_id' => (int)$account->getId(),
                'platform_code' => (string)$account->getData(SocialPlatformAccount::schema_fields_PLATFORM_CODE),
            ]);
        }

        $batch = $this->newBatch();
        $batch->load((int)$target->getData(SocialPublishTarget::schema_fields_BATCH_ID));
        $draft = $this->creativeService->getDraft((int)$batch->getData(SocialPublishBatch::schema_fields_DRAFT_ID));
        if (!$draft instanceof SocialCreativeDraft) {
            return $this->markTargetFailed($target, (string)__('创意草稿不存在。'), []);
        }

        $platformCode = (string)$target->getData(SocialPublishTarget::schema_fields_PLATFORM_CODE);
        $provider = $this->registry->getProvider($platformCode);
        if ($provider === null) {
            return $this->markTargetFailed($target, (string)__('平台 Provider 不存在：%{1}', [$platformCode]), []);
        }

        $target->setData(SocialPublishTarget::schema_fields_STATUS, SocialPublishTarget::STATUS_RUNNING)
            ->setData(SocialPublishTarget::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'))
            ->save();

        $draftData = $draft->toArrayData();
        $variants = \is_array($draftData['variants'] ?? null) ? $draftData['variants'] : [];
        $draftData['variant'] = $variants[$platformCode] ?? [];
        $credentials = $this->accountService->getCredentials($account);
        $accountData = $account->toSafeArray();
        $accountData['credentials'] = $credentials;
        $context = [
            'target_id' => $targetId,
            'batch_id' => (int)$batch->getId(),
            'idempotency_key' => (string)$target->getData(SocialPublishTarget::schema_fields_IDEMPOTENCY_KEY),
        ];

        try {
            $result = $provider->publish($draftData, $accountData, $context);
        } catch (\Throwable $throwable) {
            return $this->markTargetFailed($target, $throwable->getMessage(), [
                'request' => ['draft_id' => (int)$draft->getId(), 'account_id' => (int)$account->getId()],
            ]);
        }

        $success = !empty($result['success']);
        $status = $success ? SocialPublishTarget::STATUS_SUCCEEDED : SocialPublishTarget::STATUS_FAILED;
        $now = \date('Y-m-d H:i:s');
        $target->setData(SocialPublishTarget::schema_fields_STATUS, $status)
            ->setData(SocialPublishTarget::schema_fields_REMOTE_ID, (string)($result['remote_id'] ?? ''))
            ->setData(SocialPublishTarget::schema_fields_REMOTE_URL, (string)($result['remote_url'] ?? ''))
            ->setData(SocialPublishTarget::schema_fields_ERROR_MESSAGE, $success ? '' : (string)($result['message'] ?? __('发布失败')))
            ->setData(SocialPublishTarget::schema_fields_PUBLISHED_AT, $success ? $now : null)
            ->setData(SocialPublishTarget::schema_fields_UPDATED_AT, $now)
            ->save();

        $this->writeLog($target, $success ? 'success' : 'failed', [
            'draft_id' => (int)$draft->getId(),
            'account_id' => (int)$account->getId(),
        ], $result, $success ? '' : (string)($result['message'] ?? ''));
        $this->refreshBatchStatus((int)$batch->getId());

        return [
            'success' => $success,
            'target_id' => (int)$target->getId(),
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getBatchStatus(int $batchId): array
    {
        $batch = $this->newBatch();
        $batch->load($batchId);
        if (!$batch->getId()) {
            return [];
        }

        $targets = $this->newTarget()->reset()
            ->where(SocialPublishTarget::schema_fields_BATCH_ID, $batchId)
            ->order(SocialPublishTarget::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        return [
            'batch_id' => (int)$batch->getId(),
            'draft_id' => (int)$batch->getData(SocialPublishBatch::schema_fields_DRAFT_ID),
            'title' => (string)$batch->getData(SocialPublishBatch::schema_fields_TITLE),
            'status' => (string)$batch->getData(SocialPublishBatch::schema_fields_STATUS),
            'publish_scope' => (string)$batch->getData(SocialPublishBatch::schema_fields_PUBLISH_SCOPE),
            'content_kind' => (string)$batch->getData(SocialPublishBatch::schema_fields_CONTENT_KIND),
            'website_ids' => $this->decodeWebsiteIds((string)$batch->getData(SocialPublishBatch::schema_fields_WEBSITE_IDS_JSON)),
            'scope' => $this->decodeScope((string)$batch->getData(SocialPublishBatch::schema_fields_SCOPE_JSON)),
            'target_count' => (int)$batch->getData(SocialPublishBatch::schema_fields_TARGET_COUNT),
            'success_count' => (int)$batch->getData(SocialPublishBatch::schema_fields_SUCCESS_COUNT),
            'failed_count' => (int)$batch->getData(SocialPublishBatch::schema_fields_FAILED_COUNT),
            'targets' => \is_array($targets) ? $targets : [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRecentBatches(int $limit = 10): array
    {
        $rows = $this->newBatch()->reset()
            ->order(SocialPublishBatch::schema_fields_ID, 'DESC')
            ->limit(\max(1, \min(50, $limit)))
            ->select()
            ->fetchArray();

        return \is_array($rows) ? $rows : [];
    }

    private function createTarget(SocialPublishBatch $batch, SocialPlatformAccount $account, SocialCreativeDraft $draft, string $scheduledAt): SocialPublishTarget
    {
        $platformCode = (string)$account->getData(SocialPlatformAccount::schema_fields_PLATFORM_CODE);
        $scheduledAt = \trim($scheduledAt);
        $idempotencyKey = \sha1(\implode('|', [
            (string)$batch->getId(),
            (string)$draft->getId(),
            (string)$account->getId(),
            $platformCode,
        ]));
        $now = \date('Y-m-d H:i:s');
        $target = $this->newTarget();
        $target->setData(SocialPublishTarget::schema_fields_BATCH_ID, (int)$batch->getId())
            ->setData(SocialPublishTarget::schema_fields_ACCOUNT_ID, (int)$account->getId())
            ->setData(SocialPublishTarget::schema_fields_PLATFORM_CODE, $platformCode)
            ->setData(SocialPublishTarget::schema_fields_STATUS, SocialPublishTarget::STATUS_PENDING)
            ->setData(SocialPublishTarget::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey)
            ->setData(SocialPublishTarget::schema_fields_SCHEDULED_AT, $scheduledAt !== '' ? $scheduledAt : null)
            ->setData(SocialPublishTarget::schema_fields_CREATED_AT, $now)
            ->setData(SocialPublishTarget::schema_fields_UPDATED_AT, $now)
            ->save();

        return $target;
    }

    private function enqueueTarget(SocialPublishTarget $target): void
    {
        if (!\function_exists('w_query')) {
            return;
        }
        try {
            w_query('queue', 'create', [
                'class' => SocialPublishQueue::class,
                'name' => (string)__('社媒发布目标 #%{1}', [(string)$target->getId()]),
                'module' => 'Weline_Social',
                'biz_key' => 'weline_social_target_' . (int)$target->getId(),
                'content' => ['target_id' => (int)$target->getId()],
                'status' => QueueStatus::PENDING,
                'auto' => true,
            ]);
        } catch (\Throwable) {
        }
    }

    private function markTargetFailed(SocialPublishTarget $target, string $message, array $request): array
    {
        $target->setData(SocialPublishTarget::schema_fields_STATUS, SocialPublishTarget::STATUS_FAILED)
            ->setData(SocialPublishTarget::schema_fields_ERROR_MESSAGE, $message)
            ->setData(SocialPublishTarget::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'))
            ->save();
        $this->writeLog($target, 'failed', $request, [], $message);
        $this->refreshBatchStatus((int)$target->getData(SocialPublishTarget::schema_fields_BATCH_ID));

        return [
            'success' => false,
            'target_id' => (int)$target->getId(),
            'message' => $message,
        ];
    }

    private function writeLog(SocialPublishTarget $target, string $status, array $request, array $response, string $errorMessage): void
    {
        $log = $this->newLog();
        $log->setData(SocialPublishLog::schema_fields_TARGET_ID, (int)$target->getId())
            ->setData(SocialPublishLog::schema_fields_PLATFORM_CODE, (string)$target->getData(SocialPublishTarget::schema_fields_PLATFORM_CODE))
            ->setData(SocialPublishLog::schema_fields_STATUS, $status)
            ->setData(SocialPublishLog::schema_fields_REQUEST_JSON, \json_encode($this->redact($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->setData(SocialPublishLog::schema_fields_RESPONSE_JSON, \json_encode($this->redact($response), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->setData(SocialPublishLog::schema_fields_ERROR_MESSAGE, $errorMessage)
            ->setData(SocialPublishLog::schema_fields_CREATED_AT, \date('Y-m-d H:i:s'))
            ->save();
    }

    private function refreshBatchStatus(int $batchId): void
    {
        $batch = $this->newBatch();
        $batch->load($batchId);
        if (!$batch->getId()) {
            return;
        }
        $targets = $this->newTarget()->reset()
            ->where(SocialPublishTarget::schema_fields_BATCH_ID, $batchId)
            ->select()
            ->fetchArray();
        $total = \is_array($targets) ? \count($targets) : 0;
        $success = 0;
        $failed = 0;
        foreach ((array)$targets as $target) {
            $status = (string)($target[SocialPublishTarget::schema_fields_STATUS] ?? '');
            if ($status === SocialPublishTarget::STATUS_SUCCEEDED) {
                $success++;
            } elseif ($status === SocialPublishTarget::STATUS_FAILED) {
                $failed++;
            }
        }
        $batchStatus = SocialPublishBatch::STATUS_PENDING;
        if ($total > 0 && $success === $total) {
            $batchStatus = SocialPublishBatch::STATUS_DONE;
        } elseif ($failed > 0 && $success > 0) {
            $batchStatus = SocialPublishBatch::STATUS_PARTIAL;
        } elseif ($failed > 0 && $failed === $total) {
            $batchStatus = SocialPublishBatch::STATUS_FAILED;
        } elseif ($success > 0 || $failed > 0) {
            $batchStatus = SocialPublishBatch::STATUS_RUNNING;
        }

        $batch->setData(SocialPublishBatch::schema_fields_STATUS, $batchStatus)
            ->setData(SocialPublishBatch::schema_fields_TARGET_COUNT, $total)
            ->setData(SocialPublishBatch::schema_fields_SUCCESS_COUNT, $success)
            ->setData(SocialPublishBatch::schema_fields_FAILED_COUNT, $failed)
            ->setData(SocialPublishBatch::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'))
            ->save();
    }

    private function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            $lower = \strtolower((string)$key);
            if (\in_array($lower, ['token', 'access_token', 'refresh_token', 'secret', 'client_secret', 'password', 'authorization', 'api_key', 'credentials', 'cookie', 'set-cookie'], true)) {
                $payload[$key] = '***';
                continue;
            }
            if (\is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }

    private function isAccountPublishable(SocialPlatformAccount $account): bool
    {
        if ((string)$account->getData(SocialPlatformAccount::schema_fields_STATUS) !== SocialPlatformAccount::STATUS_ACTIVE) {
            return false;
        }
        if ((int)$account->getData(SocialPlatformAccount::schema_fields_PUBLISH_ENABLED) !== 1) {
            return false;
        }

        return (string)$account->getData(SocialPlatformAccount::schema_fields_TEST_STATUS) === SocialPlatformAccount::TEST_STATUS_PASSED;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function resolvePublishScope(array $params): array
    {
        $allSites = $this->toBool($params['all_sites'] ?? false);
        $contentKind = \trim((string)($params['content_kind'] ?? 'news')) ?: 'news';
        $websiteIds = [];
        if (isset($params['website_ids'])) {
            $websiteIds = \array_merge($websiteIds, (array)$params['website_ids']);
        }
        if (isset($params['website_id'])) {
            $websiteIds[] = $params['website_id'];
        }
        $websiteIds = \array_values(\array_filter(\array_unique(\array_map('intval', $websiteIds))));
        if ($allSites) {
            $websites = $this->websiteAccountService->listWebsites();
            $websiteIds = \array_values(\array_filter(\array_map(
                static fn(array $website): int => (int)($website['website_id'] ?? 0),
                $websites
            )));
        }

        $scope = null;
        if (!$allSites && $this->hasScopeParams($params)) {
            $scope = $this->websiteAccountService->normalizeScopeParams($params, true);
            $websiteIds = (int)($scope['website_id'] ?? 0) > 0 ? [(int)$scope['website_id']] : $websiteIds;
        } elseif (!$allSites && \count($websiteIds) === 1) {
            $scope = $this->websiteAccountService->normalizeScopeParams([
                'website_id' => (int)$websiteIds[0],
            ], true);
        }

        return [
            'publish_scope' => $allSites ? 'all_sites' : ($scope !== null ? 'scope' : ($websiteIds !== [] ? 'website' : 'accounts')),
            'content_kind' => $contentKind,
            'website_ids' => $websiteIds,
            'all_sites' => $allSites,
            'scope' => $scope,
            'resolved_scopes' => $scope !== null ? [$scope] : [],
            'scope_type' => (string)($scope['scope_type'] ?? ''),
            'scope_id' => (int)($scope['scope_id'] ?? 0),
            'child_scope_type' => (string)($scope['child_scope_type'] ?? ''),
            'child_scope_id' => (int)($scope['child_scope_id'] ?? 0),
            'scope_key' => (string)($scope['scope_key'] ?? ''),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function decodeWebsiteIds(string $json): array
    {
        $decoded = \json_decode($json, true);
        return \is_array($decoded)
            ? \array_values(\array_filter(\array_unique(\array_map('intval', $decoded))))
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeScope(string $json): array
    {
        $decoded = \json_decode($json, true);
        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function hasScopeParams(array $params): bool
    {
        return isset($params['scope'])
            || isset($params['scope_type'])
            || isset($params['scope_id'])
            || isset($params['child_scope_type'])
            || isset($params['child_scope_id'])
            || isset($params['website_id']);
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        $normalized = \strtolower(\trim((string)$value));
        return \in_array($normalized, ['1', 'true', 'yes', 'on'], true);
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
}
