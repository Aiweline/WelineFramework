<?php

declare(strict_types=1);

namespace Weline\Social\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Social\Model\SocialAutopilotPlan;
use Weline\Social\Model\SocialCreativeDraft;
use Weline\Social\Model\SocialMediaAsset;
use Weline\Social\Model\SocialPublishBatch;
use Weline\Social\Model\SocialPublishTarget;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * 社媒日更自动运营：时段执行、通道路由、审片闸门与运行状态。
 *
 * 只编排既有服务，不直接访问外部平台。扩展通道只下发任务，
 * 真正的发布动作发生在用户自己的浏览器里。
 */
final class SocialAutopilotService
{
    public const STATE_FILE = 'var/social_autopilot_run.json';
    public const GLOBAL_SWITCH_KEY = 'social_autopilot_enabled';
    public const CONTENT_KINDS = ['text', 'image', 'video'];
    public const TOPIC_PICK_COUNT = 4;
    public const MAX_REVIEW_REGENERATIONS = 2;

    public function __construct(
        private readonly ?ObjectManager $objectManager = null,
        private readonly ?SocialMediaStudio $mediaStudio = null
    ) {
    }

    /**
     * 执行一个计划时段。
     *
     * @param array{dry_run?: bool, plan?: array<string, mixed>} $options
     * @return array{ok: bool, created: int, skipped: int, error: string}
     */
    public function runSlot(int $websiteId, string $slotKey, array $options = []): array
    {
        $slotKey = \trim($slotKey);
        if ($websiteId < 0 || $slotKey === '') {
            return $this->result(false, 0, 0, 'invalid_slot');
        }

        $plan = $this->planForRun($websiteId, $options);
        if (($plan['exists'] ?? false) !== true) {
            return $this->result(false, 0, 0, 'plan_not_found');
        }
        if ((int)$plan['enabled'] !== 1) {
            return $this->result(true, 0, 1, 'disabled');
        }

        $dryRun = !empty($options['dry_run']);
        $idempotencyKey = $this->idempotencyKey($websiteId, $slotKey);
        if (!$dryRun) {
            $state = $this->readState();
            $existing = $state['runs'][$idempotencyKey] ?? null;
            if (\is_array($existing) && (string)($existing['status'] ?? '') === 'done') {
                return $this->result(true, 0, 1, 'duplicate');
            }
        }

        if (!\class_exists(SocialMediaStudio::class)
            || !\class_exists(SocialTopicService::class)
            || !\class_exists(SocialCreativeService::class)
            || !\class_exists(SocialMediaAssetService::class)
            || !\class_exists(SocialPublishService::class)
            || !\class_exists(SocialPublishRouter::class)
            || !\class_exists(SocialExtensionJobService::class)) {
            return $this->result(false, 0, 0, 'missing_dependency');
        }

        $accounts = $this->filterAccountsByPlatforms(
            $this->resolveAutoAccounts($websiteId),
            (array)$plan['auto_platform_codes']
        );
        if ($accounts === []) {
            return $this->result(true, 0, 1, 'no_account');
        }

        if ($dryRun) {
            return $this->simulateSlot($plan, $accounts, $slotKey);
        }

        $dailyCount = \max(1, (int)($plan['daily_count'] ?? 1));
        $generatedToday = $this->dailyGenerated($websiteId);
        $slotQuota = $this->slotQuota($plan, $slotKey, $dailyCount, $generatedToday);
        if ($slotQuota < 1) {
            $this->rememberRun($idempotencyKey, [
                'status' => 'done',
                'created' => 0,
                'skipped' => 1,
                'error' => 'daily_limit_reached',
                'generated_today' => $generatedToday,
                'media_errors' => [],
            ]);

            return $this->result(true, 0, 1, 'daily_limit_reached');
        }

        $records = 0;
        $targets = 0;
        $skipped = 0;
        $firstError = '';
        $musicErrors = [];
        $mediaErrors = [];
        for ($index = 0; $index < $slotQuota; ++$index) {
            if ($this->dailyGenerated($websiteId) >= $dailyCount) {
                break;
            }

            $record = $this->buildRecord($websiteId, $plan, $slotKey, $index);
            if (($record['ok'] ?? false) !== true) {
                ++$skipped;
                $firstError = $firstError !== '' ? $firstError : (string)($record['error'] ?? 'content_failed');
                break;
            }

            foreach ((array)($record['errors'] ?? []) as $mediaError) {
                $mediaError = \trim((string)$mediaError);
                if ($mediaError !== '') {
                    $mediaErrors[$mediaError] = $mediaError;
                }
            }
            $draftId = (int)($record['draft_id'] ?? 0);
            $contentKind = (string)($record['content_kind'] ?? 'text');
            $media = \is_array($record['media'] ?? null) ? $record['media'] : [];
            $guard = $this->guardPublishableAssets($draftId, $contentKind, $media);
            if (($guard['ok'] ?? false) !== true) {
                ++$skipped;
                $firstError = $firstError !== '' ? $firstError : (string)($guard['error'] ?? 'publish_blocked');
                continue;
            }
            if ((string)($guard['error'] ?? '') !== '') {
                $musicErrors[] = (string)$guard['error'];
                $mediaErrors[(string)$guard['error']] = (string)$guard['error'];
            }

            if ($contentKind === 'video' && (int)$plan['review_video'] === 1) {
                if (!\class_exists(SocialReviewService::class)) {
                    ++$skipped;
                    $firstError = $firstError !== '' ? $firstError : 'missing_dependency';
                    continue;
                }
                $review = $this->submitReview($draftId);
                if (($review['ok'] ?? false) !== true) {
                    ++$skipped;
                    $firstError = $firstError !== '' ? $firstError : (string)($review['error'] ?? 'review_failed');
                    continue;
                }
                ++$records;
                $this->addDailyGenerated($websiteId, 1);
                continue;
            }

            $published = $this->publishDraftRecord(
                $websiteId,
                $plan,
                $accounts,
                $draftId,
                (string)($record['title'] ?? ''),
                $contentKind,
                $media,
                $slotKey
            );
            $targets += (int)($published['created'] ?? 0);
            $skipped += (int)($published['skipped'] ?? 0);
            if ((int)($published['batch_id'] ?? 0) > 0) {
                ++$records;
                $this->addDailyGenerated($websiteId, 1);
            } else {
                ++$skipped;
            }
            if ((string)($published['error'] ?? '') !== '') {
                $firstError = $firstError !== '' ? $firstError : (string)$published['error'];
            }
        }

        $musicError = $musicErrors === [] ? '' : 'music_unlicensed';
        $error = $firstError !== '' ? ($records > 0 ? $musicError : $firstError) : $musicError;
        $this->rememberRun($idempotencyKey, [
            'status' => 'done',
            'created' => $records,
            'targets' => $targets,
            'skipped' => $skipped,
            'error' => $error,
            'generated_today' => $this->dailyGenerated($websiteId),
            'media_errors' => \array_values($mediaErrors),
        ]);

        return $this->result($records > 0 || $error === '' || $error === 'draft_only', $records, $skipped, $error);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function planForRun(int $websiteId, array $options): array
    {
        $plan = $this->getPlan($websiteId);
        if (isset($options['plan']) && \is_array($options['plan'])) {
            $plan = \array_merge(
                $plan,
                $options['plan'],
                $this->normalizePlan($options['plan']),
                ['website_id' => $websiteId, 'exists' => true]
            );
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function buildRecord(int $websiteId, array $plan, string $slotKey, int $index): array
    {
        $topic = $this->pickTopic($websiteId, (array)$plan['topic_ratio'], $slotKey);
        if ($topic === null) {
            return ['ok' => false, 'error' => 'missing_dependency'];
        }

        $title = $this->pickTopicString($topic, ['title', 'topic', 'name']);
        if ($title === '') {
            $title = (string)__('社媒日更') . ' ' . \date('Y-m-d') . ' ' . $slotKey;
        }
        $prompt = $this->pickTopicString($topic, ['prompt', 'summary', 'description', 'excerpt']);
        if ($prompt === '') {
            $prompt = $title;
        }
        if ($index > 0) {
            $prompt .= ' #' . ($index + 1);
        }
        $source = $this->pickTopicString($topic, ['topic_source', 'source']);
        if (!\in_array($source, ['site', 'web'], true)) {
            $source = $this->pickTopicString($topic, ['source_url', 'url']) !== '' ? 'web' : 'site';
        }
        $sourceUrl = $this->pickTopicString($topic, ['topic_source_url', 'source_url', 'url']);

        $build = $this->mediaStudio()->build($plan, $prompt, $websiteId);
        if (($build['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => (string)($build['errors'][0] ?? 'content_failed')];
        }
        $draftId = (int)($build['draft_id'] ?? 0);
        if ($draftId < 1) {
            return ['ok' => false, 'error' => 'draft_failed'];
        }

        try {
            $draft = $this->newDraft();
            $draft->load($draftId);
            if ((int)$draft->getId() < 1) {
                return ['ok' => false, 'error' => 'draft_failed'];
            }
            $draft->setData(SocialCreativeDraft::schema_fields_TITLE, (string)($build['title'] ?? $title))
                ->setData(SocialCreativeDraft::schema_fields_TOPIC_SOURCE, $source)
                ->setData(SocialCreativeDraft::schema_fields_TOPIC_SOURCE_URL, $sourceUrl)
                ->setData(SocialCreativeDraft::schema_fields_REVIEW_STATUS, 'none')
                ->setAssets(\is_array($build['assets'] ?? null) ? $build['assets'] : [])
                ->setData(SocialCreativeDraft::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'))
                ->save();
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'draft_failed'];
        }

        return [
            'ok' => true,
            'draft_id' => $draftId,
            'title' => (string)($build['title'] ?? $title),
            'content_kind' => (string)($build['content_kind'] ?? 'text'),
            'media' => $this->draftMedia($draft),
            'assets' => \is_array($build['assets'] ?? null) ? $build['assets'] : [],
            'errors' => \is_array($build['errors'] ?? null) ? $build['errors'] : [],
        ];
    }

    /**
     * 一条记录一个 batch：所有 api/extension 平台各建一个 target。
     *
     * @param array<string, mixed> $plan
     * @param array<string, array<string, mixed>> $accounts
     * @return array{batch_id:int,created:int,skipped:int,error:string}
     */
    private function publishDraftRecord(
        int $websiteId,
        array $plan,
        array $accounts,
        int $draftId,
        string $title,
        string $contentKind,
        array $media,
        string $slotKey
    ): array {
        $batchId = $this->createRecordBatch($draftId, $websiteId, $title, $contentKind, $slotKey, $plan);
        if ($batchId < 1) {
            return ['batch_id' => 0, 'created' => 0, 'skipped' => 0, 'error' => 'batch_failed'];
        }

        $router = $this->newService(SocialPublishRouter::class);
        $definitions = $this->platformDefinitions();
        $created = 0;
        $skipped = 0;
        $firstError = '';
        foreach ($accounts as $account) {
            $platformCode = \strtolower(\trim((string)($account['platform_code'] ?? '')));
            if ($platformCode === '' || !\in_array($platformCode, (array)$plan['auto_platform_codes'], true)) {
                ++$skipped;
                $firstError = $firstError !== '' ? $firstError : 'no_account:' . $platformCode;
                continue;
            }
            $route = $router->route(
                $account,
                $contentKind,
                $media,
                \is_array($definitions[$platformCode] ?? null) ? $definitions[$platformCode] : [],
                [
                    'mode' => (string)($plan['mode'] ?? 'auto'),
                    'auto_platform_codes' => (array)$plan['auto_platform_codes'],
                ]
            );
            $channel = (string)($route['channel'] ?? 'blocked');
            if (!\in_array($channel, ['api', 'extension'], true)) {
                ++$skipped;
                $firstError = $firstError !== '' ? $firstError : (string)($route['reason'] ?? 'blocked');
                continue;
            }

            $targetId = $this->createRecordTarget(
                $batchId,
                $draftId,
                (int)($account['account_id'] ?? 0),
                $platformCode,
                $channel,
                $contentKind
            );
            if ($targetId < 1) {
                ++$skipped;
                $firstError = $firstError !== '' ? $firstError : 'target_failed';
                continue;
            }
            ++$created;
            if ($channel === 'api') {
                try {
                    $result = $this->newService(SocialPublishService::class)->processTarget($targetId);
                    if (($result['success'] ?? false) !== true) {
                        $firstError = $firstError !== '' ? $firstError : 'publish_failed';
                    }
                } catch (\Throwable) {
                    $this->failRecordTarget($targetId, 'publish_failed');
                    $firstError = $firstError !== '' ? $firstError : 'publish_failed';
                }
                continue;
            }

            try {
                $job = $this->newService(SocialExtensionJobService::class)->enqueue([
                    'target_id' => $targetId,
                    'account_id' => (int)($account['account_id'] ?? 0),
                    'website_id' => $websiteId,
                    'platform_code' => $platformCode,
                    'content_kind' => $contentKind,
                    'recipe_version' => '',
                    'payload' => [
                        'draft_id' => $draftId,
                        'batch_id' => $batchId,
                        'title' => $title,
                        'assets' => $media,
                        'slot_key' => $slotKey,
                    ],
                ]);
                if (($job['success'] ?? false) !== true) {
                    $this->failRecordTarget($targetId, (string)($job['error'] ?? 'extension_enqueue_failed'));
                    $firstError = $firstError !== '' ? $firstError : 'extension_enqueue_failed';
                }
            } catch (\Throwable) {
                $this->failRecordTarget($targetId, 'extension_enqueue_failed');
                $firstError = $firstError !== '' ? $firstError : 'extension_enqueue_failed';
            }
        }

        $this->refreshRecordBatch($batchId);

        return [
            'batch_id' => $batchId,
            'created' => $created,
            'skipped' => $skipped,
            'error' => $firstError,
        ];
    }

    private function createRecordBatch(
        int $draftId,
        int $websiteId,
        string $title,
        string $contentKind,
        string $slotKey,
        array $plan
    ): int {
        try {
            $now = \date('Y-m-d H:i:s');
            $batch = $this->newModel(SocialPublishBatch::class);
            $batch->setData(SocialPublishBatch::schema_fields_DRAFT_ID, $draftId)
                ->setData(SocialPublishBatch::schema_fields_TITLE, $title !== '' ? $title : (string)__('社媒日更'))
                ->setData(SocialPublishBatch::schema_fields_STATUS, SocialPublishBatch::STATUS_PENDING)
                ->setData(SocialPublishBatch::schema_fields_PUBLISH_SCOPE, 'accounts')
                ->setData(SocialPublishBatch::schema_fields_CONTENT_KIND, $contentKind)
                ->setData(SocialPublishBatch::schema_fields_WEBSITE_IDS_JSON, $this->encodeJson([$websiteId]))
                ->setData(SocialPublishBatch::schema_fields_SCOPE_JSON, $this->encodeJson([
                    'website_id' => $websiteId,
                    'slot_key' => $slotKey,
                    'mode' => (string)($plan['mode'] ?? 'auto'),
                ]))
                ->setData(SocialPublishBatch::schema_fields_TARGET_COUNT, 0)
                ->setData(SocialPublishBatch::schema_fields_SUCCESS_COUNT, 0)
                ->setData(SocialPublishBatch::schema_fields_FAILED_COUNT, 0)
                ->setData(SocialPublishBatch::schema_fields_CREATED_AT, $now)
                ->setData(SocialPublishBatch::schema_fields_UPDATED_AT, $now)
                ->save();

            return (int)$batch->getId();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function createRecordTarget(
        int $batchId,
        int $draftId,
        int $accountId,
        string $platformCode,
        string $channel,
        string $contentKind
    ): int {
        try {
            $now = \date('Y-m-d H:i:s');
            $target = $this->newModel(SocialPublishTarget::class);
            $target->setData(SocialPublishTarget::schema_fields_BATCH_ID, $batchId)
                ->setData(SocialPublishTarget::schema_fields_ACCOUNT_ID, $accountId)
                ->setData(SocialPublishTarget::schema_fields_PLATFORM_CODE, $platformCode)
                ->setData(SocialPublishTarget::schema_fields_CHANNEL, $channel)
                ->setData(SocialPublishTarget::schema_fields_CONTENT_KIND, $contentKind)
                ->setData(SocialPublishTarget::schema_fields_STATUS, SocialPublishTarget::STATUS_PENDING)
                ->setData(SocialPublishTarget::schema_fields_IDEMPOTENCY_KEY, \sha1(\implode('|', [
                    'auto',
                    (string)$batchId,
                    (string)$draftId,
                    (string)$accountId,
                    $platformCode,
                ])))
                ->setData(SocialPublishTarget::schema_fields_CREATED_AT, $now)
                ->setData(SocialPublishTarget::schema_fields_UPDATED_AT, $now)
                ->save();

            return (int)$target->getId();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function refreshRecordBatch(int $batchId): void
    {
        try {
            $batch = $this->newModel(SocialPublishBatch::class);
            $batch->load($batchId);
            if ((int)$batch->getId() < 1) {
                return;
            }
            $rows = $this->newService(SocialPublishTarget::class)
                ->reset()
                ->where(SocialPublishTarget::schema_fields_BATCH_ID, $batchId)
                ->select()
                ->fetchArray();
            $total = \is_array($rows) ? \count($rows) : 0;
            $success = 0;
            $failed = 0;
            $running = 0;
            $pending = 0;
            foreach (\is_array($rows) ? $rows : [] as $row) {
                $status = \is_array($row) ? (string)($row[SocialPublishTarget::schema_fields_STATUS] ?? '') : '';
                if ($status === SocialPublishTarget::STATUS_SUCCEEDED) {
                    ++$success;
                } elseif ($status === SocialPublishTarget::STATUS_FAILED) {
                    ++$failed;
                } elseif ($status === SocialPublishTarget::STATUS_RUNNING) {
                    ++$running;
                } else {
                    ++$pending;
                }
            }
            $status = SocialPublishBatch::STATUS_PENDING;
            if ($total > 0 && $success === $total) {
                $status = SocialPublishBatch::STATUS_DONE;
            } elseif ($total > 0 && $failed === $total) {
                $status = SocialPublishBatch::STATUS_FAILED;
            } elseif ($success > 0 && $failed > 0) {
                $status = SocialPublishBatch::STATUS_PARTIAL;
            } elseif ($success > 0 || $running > 0) {
                $status = SocialPublishBatch::STATUS_RUNNING;
            }
            $batch->setData(SocialPublishBatch::schema_fields_STATUS, $status)
                ->setData(SocialPublishBatch::schema_fields_TARGET_COUNT, $total)
                ->setData(SocialPublishBatch::schema_fields_SUCCESS_COUNT, $success)
                ->setData(SocialPublishBatch::schema_fields_FAILED_COUNT, $failed)
                ->setData(SocialPublishBatch::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'))
                ->save();
        } catch (\Throwable) {
            // 批次统计失败不影响已创建的 target/任务。
        }
    }

    private function failRecordTarget(int $targetId, string $message): void
    {
        try {
            $target = $this->newService(SocialPublishTarget::class);
            $target->load($targetId);
            if ((int)$target->getId() < 1) {
                return;
            }
            $target->setData(SocialPublishTarget::schema_fields_STATUS, SocialPublishTarget::STATUS_FAILED)
                ->setData(SocialPublishTarget::schema_fields_ERROR_MESSAGE, $message)
                ->setData(SocialPublishTarget::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'))
                ->save();
        } catch (\Throwable) {
            // 目标失败标记是尽力而为。
        }
    }

    private function dailyGenerated(int $websiteId): int
    {
        $state = $this->readState();
        $date = \date('Y-m-d');

        return \max(0, (int)($state['daily'][$date][$websiteId]['generated'] ?? 0));
    }

    private function addDailyGenerated(int $websiteId, int $count): void
    {
        if ($count < 1) {
            return;
        }
        $state = $this->readState();
        $date = \date('Y-m-d');
        $current = (int)($state['daily'][$date][$websiteId]['generated'] ?? 0);
        $state['daily'][$date][$websiteId]['generated'] = $current + $count;
        $state['daily'][$date][$websiteId]['updated_at'] = \date('Y-m-d H:i:s');
        $this->writeState($state);
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function slotQuota(array $plan, string $slotKey, int $dailyCount, int $generatedToday): int
    {
        $remaining = \max(0, $dailyCount - $generatedToday);
        if ($remaining === 0) {
            return 0;
        }
        $slots = \is_array($plan['slots'] ?? null) ? \array_values($plan['slots']) : [];
        if ($slots === []) {
            $slots = SocialAutopilotPlan::defaults()['slots'];
        }
        $index = \array_search($slotKey, $slots, true);
        if ($index === false) {
            $index = 0;
        }
        $remainingSlots = \max(1, \count($slots) - (int)$index);

        return \min($remaining, \max(1, (int)\ceil($remaining / $remainingSlots)));
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function preferredContentKind(array $plan): string
    {
        $types = \is_array($plan['content_types'] ?? null) ? $plan['content_types'] : ['text'];
        if (\in_array('video', $types, true)) {
            return 'video';
        }
        if (\in_array('image', $types, true)) {
            return 'image';
        }

        return 'text';
    }

    private function mediaStudio(): SocialMediaStudio
    {
        return $this->mediaStudio ?? $this->newService(SocialMediaStudio::class);
    }

    /**
     * dry_run：只按计划与账户计算通道路由结果，不生成草稿、不建批次、不入队、不写状态。
     *
     * @param array<string, mixed> $plan
     * @param array<string, array<string, mixed>> $accounts
     * @return array{ok: bool, created: int, skipped: int, error: string}
     */
    private function simulateSlot(array $plan, array $accounts, string $slotKey): array
    {
        $contentKind = $this->preferredContentKind($plan);
        $media = $this->listAssets((int)$plan['website_id'], $contentKind);
        if (\in_array($contentKind, ['image', 'video'], true) && $media === []) {
            $contentKind = 'text';
            $media = [];
        }
        if ($contentKind === 'video' && (int)$plan['review_video'] === 1) {
            return $this->result(true, 1, 0, 'awaiting_review');
        }

        $router = $this->newService(SocialPublishRouter::class);
        $definitions = $this->platformDefinitions();
        $created = 0;
        $skipped = 0;
        $firstError = '';
        foreach ($accounts as $account) {
            $platformCode = \strtolower(\trim((string)($account['platform_code'] ?? '')));
            if ($platformCode === '') {
                ++$skipped;
                $firstError = $firstError !== '' ? $firstError : 'no_account';
                continue;
            }
            $route = $router->route(
                $account,
                $contentKind,
                $media,
                \is_array($definitions[$platformCode] ?? null) ? $definitions[$platformCode] : [],
                [
                    'mode' => (string)$plan['mode'],
                    'auto_platform_codes' => (array)$plan['auto_platform_codes'],
                ]
            );
            if (\in_array((string)($route['channel'] ?? ''), ['api', 'extension'], true)) {
                ++$created;
                continue;
            }
            ++$skipped;
            $firstError = $firstError !== '' ? $firstError : (string)($route['reason'] ?? 'blocked');
        }

        return $this->result($created > 0 || $skipped === 0, $created, $skipped, $created > 0 ? '' : $firstError);
    }

    /**
     * 审片通过后发布草稿：API 账户建批次/入队，扩展账户下发扩展任务。
     *
     * @return array{ok: bool, created: int, skipped: int, error: string}
     */
    public function publishReviewedDraft(int $draftId): array
    {
        $draft = $this->loadDraft($draftId);
        if ($draft === null) {
            return $this->result(false, 0, 0, 'draft_not_found');
        }
        if (\strtolower(\trim((string)$draft->getData(SocialCreativeDraft::schema_fields_REVIEW_STATUS))) !== 'approved') {
            return $this->result(false, 0, 0, 'not_approved');
        }
        if (!\class_exists(SocialPublishRouter::class)
            || !\class_exists(SocialPublishService::class)
            || !\class_exists(SocialExtensionJobService::class)) {
            return $this->result(false, 0, 0, 'missing_dependency');
        }

        $platforms = $this->draftPlatforms($draft);
        $contentKind = $this->draftContentKind($draft);
        $media = $this->draftMedia($draft);
        $guard = $this->guardPublishableAssets($draftId, $contentKind, $media);
        if (($guard['ok'] ?? false) !== true) {
            return $this->result(false, 0, 0, (string)($guard['error'] ?? 'publish_blocked'));
        }
        $musicError = (string)($guard['error'] ?? '');
        $websiteId = $this->resolveDraftWebsiteId($platforms);
        $accounts = $this->filterAccountsByPlatforms(
            $this->resolveDraftAccounts($websiteId, $platforms),
            $platforms
        );
        if ($accounts === []) {
            return $this->result(false, 0, 0, 'no_account');
        }

        $published = $this->publishDraftRecord(
            $websiteId,
            ['auto_platform_codes' => $platforms, 'mode' => 'auto'],
            $accounts,
            $draftId,
            (string)$draft->getData(SocialCreativeDraft::schema_fields_TITLE),
            $contentKind,
            $media,
            'review'
        );
        $error = (string)($published['error'] ?? '');
        if ($error === '' && $musicError !== '') {
            $error = $musicError;
        }

        return $this->result(
            (int)($published['created'] ?? 0) > 0 || $error === '',
            (int)($published['created'] ?? 0),
            (int)($published['skipped'] ?? 0),
            $error
        );
    }

    /**
     * 驳回后重生成次数 +1；计数复用草稿 variants_json 的 _review 元数据，不新增表。
     *
     * @return array{ok: bool, regenerations: int, error: string}
     */
    public function markRejectedForRegeneration(int $draftId): array
    {
        $draft = $this->loadDraft($draftId);
        if ($draft === null) {
            return ['ok' => false, 'regenerations' => 0, 'error' => 'draft_not_found'];
        }
        if (\strtolower(\trim((string)$draft->getData(SocialCreativeDraft::schema_fields_REVIEW_STATUS))) !== 'rejected') {
            return ['ok' => false, 'regenerations' => 0, 'error' => 'not_rejected'];
        }

        $variants = $draft->getVariants();
        $reviewMeta = \is_array($variants['_review'] ?? null) ? $variants['_review'] : [];
        $regenerations = (int)($reviewMeta['regenerations'] ?? 0);
        if ($regenerations >= self::MAX_REVIEW_REGENERATIONS) {
            return ['ok' => false, 'regenerations' => $regenerations, 'error' => 'regenerate_limit'];
        }
        $reviewMeta['regenerations'] = $regenerations + 1;
        $variants['_review'] = $reviewMeta;
        $draft->setVariants($variants)
            ->setData(SocialCreativeDraft::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'))
            ->save();

        return ['ok' => true, 'regenerations' => $regenerations + 1, 'error' => ''];
    }

    /**
     * 发布前许可闸门：音频缺 license/source_url/attribution/license_confirmed_at 时
     * 回退为无配乐（有图降级图文，无图降级文字），并返回 error=music_unlicensed。
     *
     * @return array{ok: bool, error: string}
     */
    private function guardPublishableAssets(int $draftId, string &$contentKind, array &$media): array
    {
        if (!\class_exists(SocialReviewService::class)) {
            return ['ok' => false, 'error' => 'missing_dependency'];
        }
        try {
            $service = $this->newService(SocialReviewService::class);
            if (!\method_exists($service, 'assertPublishable')) {
                return ['ok' => false, 'error' => 'missing_dependency'];
            }
            $result = $service->assertPublishable($draftId);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'publish_guard_failed'];
        }

        if (($result['ok'] ?? false) === true) {
            return ['ok' => true, 'error' => ''];
        }
        $error = (string)($result['error'] ?? 'publish_blocked');
        if ($error !== 'music_unlicensed') {
            return ['ok' => false, 'error' => $error];
        }

        $hadAudio = false;
        $filtered = [];
        foreach ($media as $asset) {
            if (!\is_array($asset)) {
                continue;
            }
            if (\strtolower(\trim((string)($asset['kind'] ?? ''))) === SocialMediaAsset::KIND_AUDIO) {
                $hadAudio = true;
                continue;
            }
            $filtered[] = $asset;
        }
        $media = $filtered;
        if ($hadAudio && $contentKind === 'video') {
            $hasImage = false;
            foreach ($media as $asset) {
                if (\strtolower(\trim((string)($asset['kind'] ?? ''))) === SocialMediaAsset::KIND_IMAGE) {
                    $hasImage = true;
                    break;
                }
            }
            $contentKind = $hasImage ? 'image' : 'text';
            if ($contentKind === 'text') {
                $media = [];
            }
        }

        try {
            $draft = $this->newDraft();
            $draft->load($draftId);
            if ((int)$draft->getId() > 0) {
                $draft->setAssets($media)
                    ->setData(SocialCreativeDraft::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'))
                    ->save();
            }
        } catch (\Throwable) {
            // 草稿回写失败时仍按过滤后的媒体继续，至少不把未授权音频传给发布入口。
        }

        return ['ok' => true, 'error' => 'music_unlicensed'];
    }

    private function loadDraft(int $draftId): ?SocialCreativeDraft
    {
        if ($draftId < 1) {
            return null;
        }
        try {
            $draft = $this->newDraft();
            $draft->load($draftId);

            return (int)$draft->getId() > 0 ? $draft : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function draftPlatforms(SocialCreativeDraft $draft): array
    {
        $platforms = [];
        foreach (\array_keys($draft->getVariants()) as $key) {
            $key = \strtolower(\trim((string)$key));
            if ($key === '' || \str_starts_with($key, '_')) {
                continue;
            }
            $platforms[$key] = $key;
        }

        return \array_values($platforms);
    }

    private function draftContentKind(SocialCreativeDraft $draft): string
    {
        $kinds = [];
        foreach ($this->draftMedia($draft) as $asset) {
            $kind = \strtolower(\trim((string)($asset['kind'] ?? '')));
            if ($kind !== '') {
                $kinds[$kind] = true;
            }
        }
        if (!empty($kinds['video'])) {
            return 'video';
        }
        if (!empty($kinds['image'])) {
            return 'image';
        }

        return 'text';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function draftMedia(SocialCreativeDraft $draft): array
    {
        $decoded = \json_decode((string)$draft->getData(SocialCreativeDraft::schema_fields_ASSETS_JSON), true);
        if (!\is_array($decoded)) {
            return [];
        }
        $media = [];
        foreach ($decoded as $asset) {
            if (!\is_array($asset)) {
                continue;
            }
            $assetId = (int)($asset['asset_id'] ?? 0);
            if ($assetId < 1) {
                continue;
            }
            $media[] = [
                'asset_id' => $assetId,
                'kind' => (string)($asset['kind'] ?? ''),
                'path' => (string)($asset['path'] ?? ''),
                'mime' => (string)($asset['mime'] ?? ''),
            ];
        }

        return $media;
    }

    /**
     * @param array<int, string> $platforms
     */
    private function resolveDraftWebsiteId(array $platforms): int
    {
        try {
            $rows = $this->newPlan()->reset()
                ->where(SocialAutopilotPlan::schema_fields_ENABLED, 1)
                ->select()
                ->fetchArray();
            $matches = [];
            foreach (\is_array($rows) ? $rows : [] as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $codes = \json_decode((string)($row[SocialAutopilotPlan::schema_fields_AUTO_PLATFORM_CODES_JSON] ?? ''), true);
                $codes = \is_array($codes) ? \array_map(static fn(mixed $code): string => \strtolower((string)$code), $codes) : [];
                if ($platforms !== [] && \array_intersect($codes, $platforms) === []) {
                    continue;
                }
                $websiteId = (int)($row[SocialAutopilotPlan::schema_fields_WEBSITE_ID] ?? 0);
                $matches[$websiteId] = $websiteId;
            }
            if (\count($matches) === 1) {
                return (int)\array_key_first($matches);
            }
        } catch (\Throwable) {
            // 无法从计划推断站点时回落到默认站。
        }

        return 0;
    }

    /**
     * @param array<int, string> $platforms
     * @return array<int, array<string, mixed>>
     */
    private function resolveDraftAccounts(int $websiteId, array $platforms): array
    {
        $accounts = [];
        try {
            $result = $this->newService(SocialWebsiteAccountService::class)->getWebsiteDefaultAccounts($websiteId, true);
            $accounts = \is_array($result['accounts'] ?? null) ? $result['accounts'] : [];
        } catch (\Throwable) {
            $accounts = [];
        }
        if ($accounts === []) {
            try {
                $result = $this->newService(SocialWebsiteAccountService::class)->resolvePublishAccounts(['all_sites' => true]);
                $accounts = \is_array($result['accounts'] ?? null) ? $result['accounts'] : [];
            } catch (\Throwable) {
                $accounts = [];
            }
        }

        $byId = [];
        foreach ($accounts as $account) {
            if (!\is_array($account)) {
                continue;
            }
            $accountId = (int)($account['account_id'] ?? 0);
            $code = \strtolower(\trim((string)($account['platform_code'] ?? '')));
            if ($accountId < 1 || $code === '' || isset($byId[$accountId])) {
                continue;
            }
            if ($platforms !== [] && !\in_array($code, $platforms, true)) {
                continue;
            }
            $byId[$accountId] = $account;
        }

        return \array_values($byId);
    }

    /**
     * 读取站点计划；不存在时返回默认值（enabled=0）。
     *
     * @return array<string, mixed>
     */
    public function getPlan(int $websiteId): array
    {
        $defaults = \array_merge(SocialAutopilotPlan::defaults(), $this->extraPlanDefaults());
        $defaults['website_id'] = $websiteId;
        $defaults['exists'] = false;

        try {
            $plan = $this->newPlan();
            $plan->reset()
                ->where(SocialAutopilotPlan::schema_fields_WEBSITE_ID, $websiteId)
                ->find()
                ->fetch();
            if ((int)$plan->getId() < 1) {
                return $defaults;
            }
            $data = $plan->getData();
            $normalized = $this->normalizePlan(\is_array($data) ? $data : []);

            return \array_merge($defaults, $normalized, ['website_id' => $websiteId, 'exists' => true]);
        } catch (\Throwable) {
            return $defaults;
        }
    }

    /**
     * 保存站点计划。只写计划表，不触发任何外发。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function savePlan(array $params): array
    {
        $websiteId = (int)($params['website_id'] ?? -1);
        if ($websiteId < 0) {
            return ['success' => false, 'error' => 'invalid_website'];
        }

        try {
            $plan = $this->newPlan();
            $plan->reset()
                ->where(SocialAutopilotPlan::schema_fields_WEBSITE_ID, $websiteId)
                ->find()
                ->fetch();
            $exists = (int)$plan->getId() > 0;
            if (!$exists) {
                $plan = $this->newPlan();
            }
            $current = $exists
                ? $this->normalizePlan(\is_array($plan->getData()) ? $plan->getData() : [])
                : \array_merge(SocialAutopilotPlan::defaults(), $this->extraPlanDefaults());
            $next = $this->mergePlanInput($params, $current);
            $now = \date('Y-m-d H:i:s');
            $planColumns = $this->planColumnNames();

            $plan->setData(SocialAutopilotPlan::schema_fields_WEBSITE_ID, $websiteId)
                ->setData(SocialAutopilotPlan::schema_fields_ENABLED, (int)$next['enabled'])
                ->setData(SocialAutopilotPlan::schema_fields_TIMEZONE, (string)$next['timezone'])
                ->setData(SocialAutopilotPlan::schema_fields_SLOTS_JSON, $this->encodeJson($next['slots']))
                ->setData(SocialAutopilotPlan::schema_fields_MIX_JSON, $this->encodeJson($next['mix']))
                ->setData(SocialAutopilotPlan::schema_fields_TOPIC_RATIO_JSON, $this->encodeJson($next['topic_ratio']))
                ->setData(SocialAutopilotPlan::schema_fields_AUTO_PLATFORM_CODES_JSON, $this->encodeJson($next['auto_platform_codes']))
                ->setData(SocialAutopilotPlan::schema_fields_REVIEW_VIDEO, (int)$next['review_video'])
                ->setData(SocialAutopilotPlan::schema_fields_QUOTA_JSON, $this->encodeJson($next['quota']))
                ->setData(SocialAutopilotPlan::schema_fields_MODE, (string)$next['mode'])
                ->setData(SocialAutopilotPlan::schema_fields_MAX_DAILY, (int)$next['max_daily'])
                ->setData(SocialAutopilotPlan::schema_fields_UPDATED_AT, $now);
            if (!$exists) {
                $plan->setData(SocialAutopilotPlan::schema_fields_CREATED_AT, $now);
            }
            $extraPlanData = [
                'daily_count' => (int)$next['daily_count'],
                'content_types' => $this->encodeJson($next['content_types']),
                'content_types_json' => $this->encodeJson($next['content_types']),
                'image_model_code' => (string)$next['image_model_code'],
                'video_model_code' => (string)$next['video_model_code'],
                'video_source' => (string)$next['video_source'],
            ];
            foreach ($extraPlanData as $column => $value) {
                if (\in_array($column, $planColumns, true)) {
                    $plan->setData($column, $value);
                }
            }
            $plan->save();

            return ['success' => true, 'plan' => $this->getPlan($websiteId)];
        } catch (\Throwable $throwable) {
            return ['success' => false, 'error' => 'save_failed', 'message' => $throwable->getMessage()];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listExtensionJobs(int $websiteId = 0, int $limit = 20): array
    {
        if (!\class_exists(SocialExtensionJobService::class)) {
            return [];
        }
        try {
            return $this->newService(SocialExtensionJobService::class)
                ->listPending(\max(0, $websiteId), \max(1, \min(100, $limit)));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listReviewQueue(int $limit = 20): array
    {
        try {
            $rows = $this->newService(SocialCreativeDraft::class)
                ->reset()
                ->where(SocialCreativeDraft::schema_fields_REVIEW_STATUS, 'pending')
                ->order(SocialCreativeDraft::schema_fields_ID, 'DESC')
                ->limit(\max(1, \min(100, $limit)))
                ->select()
                ->fetchArray();
            if (!\is_array($rows)) {
                return [];
            }
            foreach ($rows as &$row) {
                if (!\is_array($row)) {
                    continue;
                }
                $assets = \json_decode((string)($row[SocialCreativeDraft::schema_fields_ASSETS_JSON] ?? ''), true);
                $row['assets'] = \is_array($assets) ? $assets : [];
            }
            unset($row);

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 运行状态：时段结果 + 阻塞项（扩展离线 24h、审片积压），阻塞同日每项只记一次通知。
     *
     * @return array<string, mixed>
     */
    public function getRunStatus(int $websiteId): array
    {
        $state = $this->readState();
        $runs = [];
        $prefix = 'social:auto:' . $websiteId . ':';
        foreach ((array)($state['runs'] ?? []) as $key => $run) {
            if (\str_starts_with((string)$key, $prefix) && \is_array($run)) {
                $runs[(string)$key] = $run;
            }
        }

        $blockers = [];
        $staleJobs = 0;
        foreach ($this->listExtensionJobs($websiteId, 100) as $job) {
            $createdAt = $this->toTimestamp($job['created_at'] ?? '');
            if ($createdAt > 0 && \time() - $createdAt >= 86400) {
                ++$staleJobs;
            }
        }
        if ($staleJobs > 0) {
            $blockers[] = [
                'code' => 'extension_offline_24h',
                'count' => $staleJobs,
                'message' => (string)__('扩展离线超过 24 小时，待发任务已转为积压。'),
            ];
        }

        $reviewCount = \count($this->listReviewQueue(100));
        if ($reviewCount > 0) {
            $blockers[] = [
                'code' => 'review_backlog',
                'count' => $reviewCount,
                'message' => (string)__('审片队列积压：%{1} 条待审。', [$reviewCount]),
            ];
        }

        $notified = $this->markNotifications($websiteId, $blockers);

        return [
            'website_id' => $websiteId,
            'status' => $blockers === [] ? 'ok' : 'needs_user',
            'blockers' => $blockers,
            'runs' => $runs,
            'notified' => $notified,
            'checked_at' => \date('Y-m-d H:i:s'),
        ];
    }

    public function globalEnabled(): bool
    {
        $env = \getenv('SOCIAL_AUTOPILOT_ENABLED');
        if (\is_string($env) && \trim($env) !== '') {
            return \in_array(\strtolower(\trim($env)), ['1', 'true', 'on', 'yes'], true);
        }

        if (!\class_exists(SystemConfig::class)) {
            return false;
        }

        try {
            $value = $this->newService(SystemConfig::class)->getConfig(
                self::GLOBAL_SWITCH_KEY,
                'Weline_Social',
                'backend',
                '0',
                SystemConfig::SCOPE_GLOBAL
            );

            return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'on', 'yes'], true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function prepareContent(int $websiteId, array $plan, string $slotKey): array
    {
        $topic = $this->pickTopic($websiteId, (array)$plan['topic_ratio'], $slotKey);
        if ($topic === null) {
            return ['ok' => false, 'error' => 'missing_dependency'];
        }

        $title = $this->pickTopicString($topic, ['title', 'topic', 'name']);
        if ($title === '') {
            $title = (string)__('社媒日更') . ' ' . \date('Y-m-d') . ' ' . $slotKey;
        }
        $prompt = $this->pickTopicString($topic, ['prompt', 'summary', 'description', 'excerpt']);
        if ($prompt === '') {
            $prompt = $title;
        }

        $contentKind = $this->pickContentKind($plan, $slotKey);
        $media = $this->listAssets($websiteId, $contentKind);
        if (\in_array($contentKind, ['image', 'video'], true) && $media === []) {
            $contentKind = 'text';
            $media = [];
        }
        if ($contentKind === 'video'
            && (int)$plan['review_video'] !== 1
            && !\class_exists('Weline\\Social\\Service\\SocialVideoComposer')) {
            $contentKind = 'text';
            $media = [];
        }

        $source = $this->pickTopicString($topic, ['topic_source', 'source']);
        if (!\in_array($source, ['site', 'web'], true)) {
            $source = $this->pickTopicString($topic, ['source_url', 'url']) !== '' ? 'web' : 'site';
        }
        $sourceUrl = $this->pickTopicString($topic, ['topic_source_url', 'source_url', 'url']);

        $draft = $this->createDraft(
            $plan,
            $title,
            $prompt,
            $source,
            $sourceUrl,
            $contentKind,
            $media
        );
        if (($draft['ok'] ?? false) === true) {
            $draft['title'] = $title;
        }

        return $draft;
    }

    private function createDraft(
        array $plan,
        string $title,
        string $prompt,
        string $source,
        string $sourceUrl,
        string $contentKind,
        array $media
    ): array {
        try {
            if ($contentKind === 'video') {
                $now = \date('Y-m-d H:i:s');
                $draft = $this->newDraft();
                $draft->setData(SocialCreativeDraft::schema_fields_TITLE, $title)
                    ->setData(SocialCreativeDraft::schema_fields_PROMPT, $prompt)
                    ->setData(SocialCreativeDraft::schema_fields_CONTENT, $prompt)
                    ->setData(SocialCreativeDraft::schema_fields_STATUS, SocialCreativeDraft::STATUS_READY)
                    ->setData(SocialCreativeDraft::schema_fields_REVIEW_STATUS, 'none')
                    ->setData(SocialCreativeDraft::schema_fields_TOPIC_SOURCE, $source)
                    ->setData(SocialCreativeDraft::schema_fields_TOPIC_SOURCE_URL, $sourceUrl)
                    ->setData(
                        SocialCreativeDraft::schema_fields_ASSETS_JSON,
                        $this->encodeJson($media)
                    )
                    ->setData(SocialCreativeDraft::schema_fields_CREATED_AT, $now)
                    ->setData(SocialCreativeDraft::schema_fields_UPDATED_AT, $now)
                    ->save();

                return [
                    'ok' => (int)$draft->getId() > 0,
                    'draft_id' => (int)$draft->getId(),
                    'content_kind' => 'video',
                    'media' => $media,
                ];
            }

            $creativeParams = [
                'title' => $title,
                'prompt' => $prompt,
                'platforms' => \array_values((array)$plan['auto_platform_codes']),
                'use_ai' => true,
                'content_kind' => $contentKind,
                'assets' => $media,
            ];
            $draftId = 0;
            try {
                $result = $this->newService(SocialCreativeService::class)->generateCreative($creativeParams);
                $draftId = (int)($result['draft']['draft_id'] ?? 0);
            } catch (\Throwable) {
                $draftId = 0;
            }
            if ($draftId < 1) {
                // AI 不可用（未配置模型/额度）时退回确定性文案，保证日更不因模型缺失停摆。
                $creativeParams['use_ai'] = false;
                try {
                    $result = $this->newService(SocialCreativeService::class)->generateCreative($creativeParams);
                    $draftId = (int)($result['draft']['draft_id'] ?? 0);
                } catch (\Throwable) {
                    $draftId = 0;
                }
            }
            if ($draftId < 1) {
                return ['ok' => false, 'error' => 'draft_failed'];
            }

            try {
                $draft = $this->newDraft();
                $draft->load($draftId);
                if ((int)$draft->getId() > 0) {
                    $draft->setData(SocialCreativeDraft::schema_fields_TOPIC_SOURCE, $source)
                        ->setData(SocialCreativeDraft::schema_fields_TOPIC_SOURCE_URL, $sourceUrl)
                        ->save();
                }
            } catch (\Throwable) {
                // 选题来源是补充信息，写失败不影响已生成的草稿。
            }

            return [
                'ok' => true,
                'draft_id' => $draftId,
                'content_kind' => $contentKind,
                'media' => $media,
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'draft_failed'];
        }
    }

    private function submitReview(int $draftId): array
    {
        if (!\class_exists(SocialReviewService::class)) {
            return ['ok' => false, 'error' => 'missing_dependency'];
        }

        try {
            $service = $this->newService(SocialReviewService::class);
            if (!\method_exists($service, 'submit')) {
                return ['ok' => false, 'error' => 'missing_dependency'];
            }
            $method = new \ReflectionMethod($service, 'submit');
            $result = $method->isStatic()
                ? SocialReviewService::submit($draftId)
                : $method->invoke($service, $draftId);
            if (\is_array($result)) {
                if (\array_key_exists('ok', $result) && !$result['ok']) {
                    return ['ok' => false, 'error' => (string)($result['error'] ?? 'review_failed')];
                }
                if (\array_key_exists('success', $result) && !$result['success']) {
                    return ['ok' => false, 'error' => (string)($result['error'] ?? 'review_failed')];
                }
            }

            return ['ok' => true, 'result' => $result];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'review_failed'];
        }
    }

    /**
     * 兼容 pick() 的参数顺序差异：按参数名/类型注入本站、比例、时段与条数。
     */
    private function pickTopic(int $websiteId, array $ratio, string $slotKey): ?array
    {
        if (!\class_exists(SocialTopicService::class)) {
            return null;
        }

        try {
            $service = new SocialTopicService(
                fn(int $topicWebsiteId, int $count): array => $this->siteTopicItems($topicWebsiteId, $count)
            );
            if (!\method_exists($service, 'pick')) {
                return null;
            }
            $method = new \ReflectionMethod($service, 'pick');
            $args = [];
            foreach ($method->getParameters() as $parameter) {
                $name = \strtolower($parameter->getName());
                if (\in_array($name, ['websiteid', 'website_id', 'siteid', 'site_id'], true)) {
                    $args[] = $websiteId;
                    continue;
                }
                if (\str_contains($name, 'ratio')) {
                    $args[] = $ratio;
                    continue;
                }
                if (\str_contains($name, 'slot')) {
                    $args[] = $slotKey;
                    continue;
                }
                if (\in_array($name, ['count', 'limit', 'num', 'number'], true)) {
                    $args[] = self::TOPIC_PICK_COUNT;
                    continue;
                }
                $type = $parameter->getType();
                $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
                if ($typeName === 'array') {
                    $args[] = $ratio;
                    continue;
                }
                if ($typeName === 'int') {
                    $args[] = $websiteId;
                    continue;
                }
                if ($typeName === 'string') {
                    $args[] = $slotKey;
                    continue;
                }
                if ($typeName === 'bool') {
                    $args[] = false;
                    continue;
                }
                if ($parameter->isDefaultValueAvailable()) {
                    $args[] = $parameter->getDefaultValue();
                    continue;
                }
                if ($parameter->allowsNull()) {
                    $args[] = null;
                    continue;
                }

                return null;
            }
            $result = $method->invokeArgs($method->isStatic() ? null : $service, $args);
            if (\is_array($result)) {
                if ($result !== [] && \array_is_list($result)) {
                    $first = $result[0];
                    if (\is_array($first)) {
                        if (($first['source'] ?? '') === 'site' && !$this->hasWebTopic($result)) {
                            $first['fallback'] = true;
                        }

                        return $first;
                    }
                    if (\is_scalar($first)) {
                        return ['title' => (string)$first, 'source' => 'site', 'fallback' => true];
                    }

                    return [];
                }

                return $result;
            }
            if (\is_object($result) && \method_exists($result, 'toArrayData')) {
                $data = $result->toArrayData();
                return \is_array($data) ? $data : null;
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 站内选题 provider：最近草稿标题优先，没有草稿时用站点名兜底。
     *
     * @return array<int, array<string, mixed>>
     */
    private function siteTopicItems(int $websiteId, int $count): array
    {
        $count = \max(1, \min(100, $count));
        $items = [];
        try {
            foreach ($this->newService(SocialCreativeService::class)->listRecentDrafts(\max(8, $count * 2)) as $draft) {
                $title = \trim((string)($draft['title'] ?? ''));
                if ($title === '' || isset($items[$title])) {
                    continue;
                }
                $content = \trim((string)($draft['content'] ?? ''));
                $items[$title] = [
                    'title' => $title,
                    'url' => '',
                    'excerpt' => $content !== '' ? $content : $title,
                ];
                if (\count($items) >= $count) {
                    break;
                }
            }
        } catch (\Throwable) {
            $items = [];
        }

        if ($items === []) {
            $website = $this->websiteInfo($websiteId);
            $siteName = \trim((string)($website['name'] ?? ''));
            $title = ($siteName !== '' ? $siteName : (string)__('站点日更')) . ' ' . \date('Y-m-d');
            $items[$title] = [
                'title' => $title,
                'url' => (string)($website['url'] ?? ''),
                'excerpt' => $title,
            ];
        }

        return \array_values($items);
    }

    /**
     * @return array<string, mixed>
     */
    private function websiteInfo(int $websiteId): array
    {
        try {
            foreach ($this->newService(SocialWebsiteAccountService::class)->listWebsites() as $website) {
                if (\is_array($website) && (int)($website['website_id'] ?? -1) === $websiteId) {
                    return $website;
                }
            }
        } catch (\Throwable) {
            // 站点名不可用时退回通用标题。
        }

        return [];
    }

    /**
     * @param array<int, mixed> $topics
     */
    private function hasWebTopic(array $topics): bool
    {
        foreach ($topics as $topic) {
            if (\is_array($topic) && (string)($topic['source'] ?? '') === 'web') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $topic
     * @param array<int, string> $keys
     */
    private function pickTopicString(array $topic, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($topic[$key]) && \is_scalar($topic[$key])) {
                $value = \trim((string)$topic[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function pickContentKind(array $plan, string $slotKey): string
    {
        $mix = \is_array($plan['mix'] ?? null) ? $plan['mix'] : SocialAutopilotPlan::defaults()['mix'];
        $sequence = [];
        foreach (self::CONTENT_KINDS as $kind) {
            $count = \max(0, (int)($mix[$kind] ?? 0));
            for ($i = 0; $i < $count; ++$i) {
                $sequence[] = $kind;
            }
        }
        if ($sequence === []) {
            return 'text';
        }

        $seed = \abs((int)\crc32(\date('Y-m-d') . '|' . $slotKey));

        return $sequence[$seed % \count($sequence)];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listAssets(int $websiteId, string $kind): array
    {
        if (!\in_array($kind, ['image', 'video'], true)) {
            return [];
        }

        try {
            $rows = $this->newService(SocialMediaAsset::class)
                ->reset()
                ->where(SocialMediaAsset::schema_fields_WEBSITE_ID, $websiteId)
                ->where(SocialMediaAsset::schema_fields_KIND, $kind)
                ->order(SocialMediaAsset::schema_fields_ID, 'ASC')
                ->limit(6)
                ->select()
                ->fetchArray();
            if (!\is_array($rows)) {
                return [];
            }
            $assets = [];
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $assetId = (int)($row[SocialMediaAsset::schema_fields_ID] ?? 0);
                if ($assetId < 1) {
                    continue;
                }
                $assets[] = [
                    'asset_id' => $assetId,
                    'kind' => (string)($row[SocialMediaAsset::schema_fields_KIND] ?? $kind),
                    'path' => (string)($row[SocialMediaAsset::schema_fields_PATH] ?? ''),
                    'mime' => (string)($row[SocialMediaAsset::schema_fields_MIME] ?? ''),
                ];
            }

            return $assets;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveAutoAccounts(int $websiteId): array
    {
        try {
            $result = $this->newService(SocialWebsiteAccountService::class)
                ->getWebsiteDefaultAccounts($websiteId, true);
            $accounts = \is_array($result['accounts'] ?? null) ? $result['accounts'] : [];
            $byId = [];
            foreach ($accounts as $account) {
                if (!\is_array($account)) {
                    continue;
                }
                $accountId = (int)($account['account_id'] ?? 0);
                if ($accountId < 1 || isset($byId[$accountId])) {
                    continue;
                }
                $byId[$accountId] = $account;
            }

            return \array_values($byId);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $accounts
     * @param array<int, mixed> $platforms
     * @return array<int, array<string, mixed>>
     */
    private function filterAccountsByPlatforms(array $accounts, array $platforms): array
    {
        $codes = [];
        foreach ($platforms as $platform) {
            $platform = \strtolower(\trim((string)$platform));
            if ($platform !== '') {
                $codes[$platform] = $platform;
            }
        }
        if ($codes === []) {
            return [];
        }

        $filtered = [];
        foreach ($accounts as $account) {
            if (!\is_array($account)) {
                continue;
            }
            $code = \strtolower(\trim((string)($account['platform_code'] ?? '')));
            if ($code !== '' && isset($codes[$code])) {
                $filtered[] = $account;
            }
        }

        return $filtered;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function platformDefinitions(): array
    {
        try {
            $definitions = $this->newService(SocialPlatformRegistry::class)->listDefinitions();
            $map = [];
            foreach ($definitions as $definition) {
                if (!\is_array($definition)) {
                    continue;
                }
                $code = \strtolower(\trim((string)($definition['code'] ?? '')));
                if ($code !== '') {
                    $map[$code] = $definition;
                }
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    private function stampBatchTargets(int $batchId, string $channel, string $contentKind): void
    {
        try {
            $rows = $this->newService(SocialPublishTarget::class)
                ->reset()
                ->where(SocialPublishTarget::schema_fields_BATCH_ID, $batchId)
                ->select()
                ->fetchArray();
            foreach (\is_array($rows) ? $rows : [] as $row) {
                $targetId = \is_array($row) ? (int)($row[SocialPublishTarget::schema_fields_ID] ?? 0) : 0;
                if ($targetId < 1) {
                    continue;
                }
                $target = $this->newService(SocialPublishTarget::class);
                $target->load($targetId);
                if ((int)$target->getId() < 1) {
                    continue;
                }
                $target->setData(SocialPublishTarget::schema_fields_CHANNEL, $channel)
                    ->setData(SocialPublishTarget::schema_fields_CONTENT_KIND, $contentKind)
                    ->save();
            }
        } catch (\Throwable) {
            // 通道路由列是记录增强，回填失败不影响发布批次。
        }
    }

    /**
     * @return array{daily_count:int,content_types:array<int,string>,image_model_code:string,video_model_code:string,video_source:string}
     */
    private function extraPlanDefaults(): array
    {
        return [
            'daily_count' => 1,
            'content_types' => ['text'],
            'image_model_code' => '',
            'video_model_code' => '',
            'video_source' => 'image_slideshow',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizePlanContentTypes(mixed $types): array
    {
        if (\is_string($types)) {
            $decoded = \json_decode($types, true);
            if (\is_array($decoded)) {
                $types = $decoded;
            } else {
                $types = \preg_split('/[\s,，]+/u', $types) ?: [];
            }
        }
        $normalized = [];
        foreach (\is_array($types) ? $types : [] as $type) {
            $type = \strtolower(\trim((string)$type));
            if (\in_array($type, self::CONTENT_KINDS, true)) {
                $normalized[$type] = $type;
            }
        }
        if ($normalized === []) {
            $normalized = ['text' => 'text'];
        }

        return \array_values($normalized);
    }

    /**
     * @return array<int, string>
     */
    private function planColumnNames(): array
    {
        try {
            $columns = $this->newPlan()->columns();

            return \array_values(\array_map(
                static fn(mixed $column): string => (string)(\is_array($column) ? ($column['Field'] ?? '') : ''),
                \is_array($columns) ? $columns : []
            ));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizePlan(array $row): array
    {
        $plan = \array_merge(SocialAutopilotPlan::defaults(), $this->extraPlanDefaults());
        $plan['website_id'] = (int)($row[SocialAutopilotPlan::schema_fields_WEBSITE_ID] ?? 0);
        $plan['enabled'] = (int)($row[SocialAutopilotPlan::schema_fields_ENABLED] ?? 0) === 1 ? 1 : 0;
        $timezone = \trim((string)($row[SocialAutopilotPlan::schema_fields_TIMEZONE] ?? ''));
        $plan['timezone'] = $this->validTimezone($timezone);
        $plan['slots'] = $this->normalizeSlots($this->decodeJson($row['slots'] ?? $row[SocialAutopilotPlan::schema_fields_SLOTS_JSON] ?? null, $plan['slots']));
        $plan['mix'] = $this->normalizeMix($this->decodeJson($row['mix'] ?? $row[SocialAutopilotPlan::schema_fields_MIX_JSON] ?? null, $plan['mix']));
        $plan['topic_ratio'] = $this->normalizeTopicRatio($this->decodeJson($row['topic_ratio'] ?? $row[SocialAutopilotPlan::schema_fields_TOPIC_RATIO_JSON] ?? null, $plan['topic_ratio']));
        $plan['auto_platform_codes'] = $this->normalizePlatformCodes($this->decodeJson($row['auto_platform_codes'] ?? $row[SocialAutopilotPlan::schema_fields_AUTO_PLATFORM_CODES_JSON] ?? null, $plan['auto_platform_codes']));
        $plan['review_video'] = (int)($row[SocialAutopilotPlan::schema_fields_REVIEW_VIDEO] ?? 1) === 1 ? 1 : 0;
        $plan['quota'] = $this->normalizeQuota($this->decodeJson($row['quota'] ?? $row[SocialAutopilotPlan::schema_fields_QUOTA_JSON] ?? null, $plan['quota']));
        $mode = \strtolower(\trim((string)($row[SocialAutopilotPlan::schema_fields_MODE] ?? 'auto')));
        $plan['mode'] = \in_array($mode, ['preview', 'auto'], true) ? $mode : 'auto';
        $plan['max_daily'] = \max(1, \min(50, (int)($row[SocialAutopilotPlan::schema_fields_MAX_DAILY] ?? 6)));
        $plan['daily_count'] = \max(1, \min(50, (int)($row['daily_count'] ?? 1)));
        $plan['content_types'] = $this->normalizePlanContentTypes($row['content_types'] ?? $row['content_types_json'] ?? ['text']);
        $plan['image_model_code'] = \trim((string)($row['image_model_code'] ?? ''));
        $plan['video_model_code'] = \trim((string)($row['video_model_code'] ?? ''));
        $videoSource = \strtolower(\trim((string)($row['video_source'] ?? 'image_slideshow')));
        $plan['video_source'] = \in_array($videoSource, ['image_slideshow', 'ai_video'], true) ? $videoSource : 'image_slideshow';
        $plan['created_at'] = (string)($row[SocialAutopilotPlan::schema_fields_CREATED_AT] ?? '');
        $plan['updated_at'] = (string)($row[SocialAutopilotPlan::schema_fields_UPDATED_AT] ?? '');

        return $plan;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function mergePlanInput(array $params, array $current): array
    {
        $next = $current;
        foreach (['enabled', 'review_video'] as $key) {
            if (\array_key_exists($key, $params)) {
                $next[$key] = (int)(bool)$params[$key];
            }
        }
        if (\array_key_exists('timezone', $params)) {
            $next['timezone'] = $this->validTimezone((string)$params['timezone']);
        }
        if (\array_key_exists('slots', $params)) {
            $next['slots'] = $this->normalizeSlots(\is_array($params['slots']) ? $params['slots'] : []);
        }
        if (\array_key_exists('mix', $params)) {
            $next['mix'] = $this->normalizeMix(\is_array($params['mix']) ? $params['mix'] : []);
        }
        if (\array_key_exists('topic_ratio', $params)) {
            $next['topic_ratio'] = $this->normalizeTopicRatio(\is_array($params['topic_ratio']) ? $params['topic_ratio'] : []);
        }
        if (\array_key_exists('auto_platform_codes', $params)) {
            $next['auto_platform_codes'] = $this->normalizePlatformCodes(
                \is_array($params['auto_platform_codes']) ? $params['auto_platform_codes'] : []
            );
        }
        if (\array_key_exists('quota', $params)) {
            $next['quota'] = $this->normalizeQuota(\is_array($params['quota']) ? $params['quota'] : []);
        }
        if (\array_key_exists('mode', $params)) {
            $mode = \strtolower(\trim((string)$params['mode']));
            $next['mode'] = \in_array($mode, ['preview', 'auto'], true) ? $mode : (string)$current['mode'];
        }
        if (\array_key_exists('max_daily', $params)) {
            $next['max_daily'] = \max(1, \min(50, (int)$params['max_daily']));
        }
        if (\array_key_exists('daily_count', $params)) {
            $next['daily_count'] = \max(1, \min(50, (int)$params['daily_count']));
        }
        if (\array_key_exists('content_types', $params)) {
            $next['content_types'] = $this->normalizePlanContentTypes($params['content_types']);
        }
        if (\array_key_exists('image_model_code', $params)) {
            $next['image_model_code'] = \trim((string)$params['image_model_code']);
        }
        if (\array_key_exists('video_model_code', $params)) {
            $next['video_model_code'] = \trim((string)$params['video_model_code']);
        }
        if (\array_key_exists('video_source', $params)) {
            $source = \strtolower(\trim((string)$params['video_source']));
            $next['video_source'] = \in_array($source, ['image_slideshow', 'ai_video'], true) ? $source : 'image_slideshow';
        }

        return $next;
    }

    private function validTimezone(string $timezone): string
    {
        if ($timezone === '') {
            return 'Asia/Shanghai';
        }
        try {
            new \DateTimeZone($timezone);
            return $timezone;
        } catch (\Throwable) {
            return 'Asia/Shanghai';
        }
    }

    /**
     * @param array<int, mixed> $slots
     * @return array<int, string>
     */
    private function normalizeSlots(array $slots): array
    {
        $normalized = [];
        foreach ($slots as $slot) {
            if (!\is_scalar($slot)) {
                continue;
            }
            $value = \trim((string)$slot);
            if (!\preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $matches)) {
                continue;
            }
            $normalized[] = \sprintf('%02d:%02d', (int)$matches[1], (int)$matches[2]);
        }
        $normalized = \array_values(\array_unique($normalized));
        \sort($normalized);

        return $normalized === [] ? SocialAutopilotPlan::defaults()['slots'] : \array_slice($normalized, 0, 24);
    }

    /**
     * @param array<string, mixed> $mix
     * @return array<string, int>
     */
    private function normalizeMix(array $mix): array
    {
        $defaults = SocialAutopilotPlan::defaults()['mix'];
        if ($mix === []) {
            return $defaults;
        }
        $normalized = [];
        foreach (self::CONTENT_KINDS as $kind) {
            $normalized[$kind] = \max(0, (int)($mix[$kind] ?? 0));
        }

        return \array_sum($normalized) > 0 ? $normalized : $defaults;
    }

    /**
     * @param array<string, mixed> $ratio
     * @return array<string, int>
     */
    private function normalizeTopicRatio(array $ratio): array
    {
        $defaults = SocialAutopilotPlan::defaults()['topic_ratio'];
        if ($ratio === []) {
            return $defaults;
        }
        $normalized = [
            'site' => \max(0, (int)($ratio['site'] ?? 0)),
            'web' => \max(0, (int)($ratio['web'] ?? 0)),
        ];

        return \array_sum($normalized) > 0 ? $normalized : $defaults;
    }

    /**
     * @param array<int, mixed> $codes
     * @return array<int, string>
     */
    private function normalizePlatformCodes(array $codes): array
    {
        $normalized = [];
        foreach ($codes as $code) {
            if (!\is_scalar($code)) {
                continue;
            }
            $value = \strtolower(\trim((string)$code));
            if ($value === '' || !\preg_match('/^[a-z0-9_-]{1,64}$/', $value)) {
                continue;
            }
            $normalized[$value] = $value;
        }

        return \array_slice(\array_values($normalized), 0, 100);
    }

    /**
     * @param array<string, mixed> $quota
     * @return array<string, int>
     */
    private function normalizeQuota(array $quota): array
    {
        $defaults = SocialAutopilotPlan::defaults()['quota'];
        if ($quota === []) {
            return $defaults;
        }

        return [
            'per_platform_daily' => \max(1, \min(10, (int)($quota['per_platform_daily'] ?? $defaults['per_platform_daily']))),
        ];
    }

    private function decodeJson(mixed $value, mixed $default): mixed
    {
        if (\is_array($value)) {
            return $value;
        }
        $raw = \trim((string)$value);
        if ($raw === '') {
            return $default;
        }
        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : $default;
    }

    private function encodeJson(mixed $value): string
    {
        return \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private function idempotencyKey(int $websiteId, string $slotKey): string
    {
        return 'social:auto:' . $websiteId . ':' . \date('Y-m-d') . ':' . $slotKey;
    }

    /**
     * @return array{ok: bool, created: int, skipped: int, error: string}
     */
    private function result(bool $ok, int $created, int $skipped, string $error): array
    {
        return ['ok' => $ok, 'created' => $created, 'skipped' => $skipped, 'error' => $error];
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        $path = $this->statePath();
        if (!\is_file($path)) {
            return ['runs' => [], 'notified' => []];
        }
        $decoded = \json_decode((string)@\file_get_contents($path), true);
        if (!\is_array($decoded)) {
            return ['runs' => [], 'notified' => []];
        }
        $decoded['runs'] = \is_array($decoded['runs'] ?? null) ? $decoded['runs'] : [];
        $decoded['notified'] = \is_array($decoded['notified'] ?? null) ? $decoded['notified'] : [];

        return $decoded;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        $path = $this->statePath();
        $directory = \dirname($path);
        if (!\is_dir($directory) && !@\mkdir($directory, 0775, true) && !\is_dir($directory)) {
            return;
        }
        @\file_put_contents(
            $path,
            \json_encode($state, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT),
            \LOCK_EX
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function rememberRun(string $idempotencyKey, array $data): void
    {
        $state = $this->readState();
        $state['runs'][$idempotencyKey] = \array_merge(
            ['updated_at' => \date('Y-m-d H:i:s')],
            $data
        );
        if (\count($state['runs']) > 500) {
            $state['runs'] = \array_slice($state['runs'], -500, null, true);
        }
        $this->writeState($state);
    }

    /**
     * @param array<int, array<string, mixed>> $blockers
     * @return array<int, string>
     */
    private function markNotifications(int $websiteId, array $blockers): array
    {
        if ($blockers === []) {
            return [];
        }
        $state = $this->readState();
        $today = \date('Y-m-d');
        $notified = [];
        foreach ($blockers as $blocker) {
            $code = \trim((string)($blocker['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $key = $websiteId . '|' . $today . '|' . $code;
            if (!empty($state['notified'][$key])) {
                continue;
            }
            $state['notified'][$key] = \date('Y-m-d H:i:s');
            $notified[] = $code;
        }
        if ($notified !== []) {
            $this->writeState($state);
        }

        return $notified;
    }

    private function statePath(): string
    {
        $root = \defined('BP') ? (string)\constant('BP') : \sys_get_temp_dir();

        return \rtrim($root, '/\\') . '/' . self::STATE_FILE;
    }

    private function toTimestamp(mixed $value): int
    {
        $raw = \trim((string)$value);
        if ($raw === '') {
            return 0;
        }
        $timestamp = \strtotime($raw);

        return $timestamp === false ? 0 : $timestamp;
    }

    private function newPlan(): SocialAutopilotPlan
    {
        return $this->newService(SocialAutopilotPlan::class);
    }

    private function newDraft(): SocialCreativeDraft
    {
        return $this->newService(SocialCreativeDraft::class);
    }

    private function newService(string $class): object
    {
        return ($this->objectManager ?? ObjectManager::getInstance())->getInstance($class);
    }

    private function newModel(string $class): object
    {
        return ($this->objectManager ?? ObjectManager::getInstance())->getInstance($class, [], false);
    }
}
