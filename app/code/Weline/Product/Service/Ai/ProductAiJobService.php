<?php

declare(strict_types=1);

namespace Weline\Product\Service\Ai;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Ai\AiJob;
use Weline\Queue\Service\IdempotentQueueAdmission;

/**
 * Creates, dedupes and cancels Product AI jobs.
 *
 * A job is never created without an active scenario binding and without a
 * registered queue consumer: both failures are reported before any row exists,
 * so the review center cannot show work that nothing will ever execute.
 */
final class ProductAiJobService
{
    public const CONSUMER_CLASS = '\\Weline\\Product\\Queue\\ProductAiOptimizeConsumer';
    public const QUEUE_SCOPE = 'product_ai_optimize';

    public function __construct(
        private readonly ProductAiModelResolver $resolver,
        private readonly ProductAiSnapshotService $snapshots,
    ) {
    }

    /**
     * @param list<string> $globalProductUuids
     * @param list<string> $taskTypes
     * @param array<string,string> $modelCodes task type => model code override
     * @return array{job_ids:list<string>,reused:list<string>,review_url:string}
     */
    public function create(
        int $websiteId,
        array $globalProductUuids,
        array $taskTypes,
        array $modelCodes,
        string $locale,
        string $scope,
        int $actorId,
        int $storeId = 0,
    ): array {
        if ($websiteId < 0) {
            throw new ProductAiException('product_ai_website_invalid', (string)__('站点无效'), 422);
        }
        if ($actorId < 1) {
            throw new ProductAiException('product_ai_actor_invalid', (string)__('缺少操作人'), 403);
        }
        $uuids = \array_values(\array_unique(\array_filter(\array_map(
            static fn(mixed $uuid): string => \strtolower(\trim((string)$uuid)),
            $globalProductUuids,
        ))));
        $tasks = \array_values(\array_unique(\array_filter(\array_map(
            static fn(mixed $task): string => \strtolower(\trim((string)$task)),
            $taskTypes,
        ))));
        if ($uuids === [] || $tasks === []) {
            throw new ProductAiException('product_ai_selection_empty', (string)__('请选择要优化的商品与任务'), 422);
        }
        if (\count($uuids) > 1 || \count($tasks) > 1) {
            throw new ProductAiException(
                'product_ai_batch_unsupported',
                (string)__('S1 暂不支持批量创建任务'),
                422,
                ['products' => \count($uuids), 'tasks' => \count($tasks)],
            );
        }
        $consumerClass = \ltrim(self::CONSUMER_CLASS, '\\');
        if (!\class_exists($consumerClass)) {
            throw new ProductAiException(
                'product_ai_queue_unavailable',
                (string)__('AI 优化任务消费者尚未注册，暂时无法创建任务'),
                503,
                ['consumer' => $consumerClass],
            );
        }
        if (!\class_exists(IdempotentQueueAdmission::class)) {
            throw new ProductAiException(
                'product_ai_queue_unavailable',
                (string)__('队列模块未启用，暂时无法创建任务'),
                503,
            );
        }

        $uuid = $uuids[0];
        $taskType = $tasks[0];
        $resolved = $this->resolver->resolve($taskType, $modelCodes[$taskType] ?? null);
        $snapshot = $this->snapshots->snapshot($websiteId, $uuid, $locale, $storeId);

        $existing = $this->findActiveJob($websiteId, $uuid, $taskType, $snapshot['input_hash']);
        if ($existing !== null) {
            return [
                'job_ids' => [(string)$existing->getData(AiJob::schema_fields_ID)],
                'reused' => [(string)$existing->getData(AiJob::schema_fields_ID)],
                'review_url' => $this->reviewUrl((string)$existing->getData(AiJob::schema_fields_ID)),
            ];
        }

        $params = [
            'scope' => $scope,
            'store_id' => $storeId,
            'locale' => $snapshot['locale'],
            'identity_version' => $snapshot['identity_version'],
            'input_hash' => $snapshot['input_hash'],
        ];
        if ($taskType === AiJob::TASK_IMAGE) {
            $mediaLocale = $snapshot['locale'] !== '' ? $snapshot['locale'] : 'zh_Hans_CN';
            $params['media_context'] = $this->freezeMediaContext($mediaLocale, $actorId);
        }

        $jobId = $this->uuidV4();
        $now = \date('Y-m-d H:i:s');
        ObjectManager::make(AiJob::class)->clear()->setData([
            AiJob::schema_fields_ID => $jobId,
            AiJob::schema_fields_BATCH_ID => null,
            AiJob::schema_fields_WEBSITE_ID => $websiteId,
            AiJob::schema_fields_PRODUCT_UUID => $uuid,
            AiJob::schema_fields_PRODUCT_ID => $snapshot['product_id'],
            AiJob::schema_fields_TASK_TYPE => $taskType,
            AiJob::schema_fields_SCENARIO_CODE => $resolved['scenario_code'],
            AiJob::schema_fields_MODEL_CODE => $resolved['model_code'],
            AiJob::schema_fields_LOCALE => $snapshot['locale'],
            AiJob::schema_fields_STATUS => AiJob::STATUS_QUEUED,
            AiJob::schema_fields_PARAMS_JSON => $this->json($params),
            AiJob::schema_fields_INPUT_HASH => $snapshot['input_hash'],
            AiJob::schema_fields_REQUESTED_BY => $actorId,
            AiJob::schema_fields_CREATED_AT => $now,
        ])->save();
        $stored = $this->findJob($jobId);
        if ($stored === null
            || (string)$stored->getData(AiJob::schema_fields_STATUS) !== AiJob::STATUS_QUEUED
        ) {
            throw new ProductAiException('product_ai_job_persist_failed', (string)__('任务写入校验失败'), 500);
        }

        $admission = ObjectManager::getInstance(IdempotentQueueAdmission::class);
        try {
            $admission->admit([
                'class' => $consumerClass,
                'name' => (string)__('商品 AI 优化'),
                'module' => 'Weline_Product',
                'content' => ['job_id' => $jobId],
                'biz_key' => 'product-ai:' . $jobId,
                'idempotency_scope' => self::QUEUE_SCOPE,
                'idempotency_key' => $jobId,
                'auto' => true,
            ]);
        } catch (\Throwable $exception) {
            $this->finish(
                $jobId,
                AiJob::STATUS_FAILED,
                ['job_id' => $jobId, 'queued' => false],
                'product_ai_queue_unavailable',
                $exception->getMessage(),
            );
            throw new ProductAiException(
                'product_ai_queue_unavailable',
                (string)__('任务入队失败，请稍后重试'),
                503,
                ['job_id' => $jobId],
                $exception,
            );
        }

        return [
            'job_ids' => [$jobId],
            'reused' => [],
            'review_url' => $this->reviewUrl($jobId),
        ];
    }

    /**
     * @return array{job_id:string,status:string}
     */
    public function cancel(string $jobId, int $actorId): array
    {
        if ($actorId < 1) {
            throw new ProductAiException('product_ai_actor_invalid', (string)__('缺少操作人'), 403);
        }
        $jobId = \strtolower(\trim($jobId));
        $job = $this->findJob($jobId);
        if ($job === null) {
            throw new ProductAiException('product_ai_job_not_ready', (string)__('任务不存在'), 409);
        }
        $status = (string)$job->getData(AiJob::schema_fields_STATUS);
        if (AiJob::isFinalStatus($status)) {
            return ['job_id' => $jobId, 'status' => $status];
        }

        $next = $status === AiJob::STATUS_QUEUED
            ? AiJob::STATUS_CANCELLED
            : AiJob::STATUS_CANCEL_REQUESTED;
        $this->updateStatus($jobId, $status, [
            AiJob::schema_fields_STATUS => $next,
            AiJob::schema_fields_FINISHED_AT => $next === AiJob::STATUS_CANCELLED ? \date('Y-m-d H:i:s') : null,
        ]);
        $reloaded = $this->findJob($jobId);

        return [
            'job_id' => $jobId,
            'status' => (string)($reloaded?->getData(AiJob::schema_fields_STATUS) ?? $status),
        ];
    }

    public function findJob(string $jobId): ?AiJob
    {
        $jobId = \strtolower(\trim($jobId));
        if ($jobId === '') {
            return null;
        }
        $job = ObjectManager::make(AiJob::class)
            ->reset()
            ->where(AiJob::schema_fields_ID, $jobId)
            ->find()
            ->fetch();

        return $job->getId() ? $job : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function updateStatus(string $jobId, string $expectedStatus, array $data): void
    {
        ObjectManager::make(AiJob::class)
            ->getQuery(false)
            ->where(AiJob::schema_fields_ID, $jobId)
            ->where(AiJob::schema_fields_STATUS, $expectedStatus)
            ->update($data)
            ->fetch();
    }

    public function finish(
        string $jobId,
        string $status,
        array $result,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): void {
        $data = [
            AiJob::schema_fields_STATUS => $status,
            AiJob::schema_fields_RESULT_JSON => $this->json($result),
            AiJob::schema_fields_ERROR_CODE => $errorCode,
            AiJob::schema_fields_ERROR_MESSAGE => $errorMessage !== null
                ? \mb_strcut($errorMessage, 0, 4000, 'UTF-8')
                : null,
            AiJob::schema_fields_FINISHED_AT => \date('Y-m-d H:i:s'),
        ];
        ObjectManager::make(AiJob::class)
            ->getQuery(false)
            ->where(AiJob::schema_fields_ID, $jobId)
            ->update($data)
            ->fetch();
    }

    public function json(mixed $value): string
    {
        return \json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function reviewUrl(string $jobId): string
    {
        return 'weline_product/backend/ai-review/index?job_id=' . \rawurlencode($jobId);
    }

    public function uuidV4(): string
    {
        $bytes = \random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80);
        $hex = \bin2hex($bytes);

        return \substr($hex, 0, 8) . '-' . \substr($hex, 8, 4) . '-' . \substr($hex, 12, 4)
            . '-' . \substr($hex, 16, 4) . '-' . \substr($hex, 20, 12);
    }

    public static function uuidFromSeed(string $seed): string
    {
        $hex = \substr(\hash('sha256', $seed), 0, 32);
        $hex[12] = '5';
        $hex[16] = \dechex((\hexdec($hex[16]) & 0x3) | 0x8);

        return \substr($hex, 0, 8) . '-' . \substr($hex, 8, 4) . '-' . \substr($hex, 12, 4)
            . '-' . \substr($hex, 16, 4) . '-' . \substr($hex, 20, 12);
    }

    /** @return array<string,mixed> */
    private function freezeMediaContext(string $locale, int $actorId): array
    {
        $factoryClass = \Weline\MediaManager\Service\MediaFileAccessContextFactory::class;
        if (!\class_exists($factoryClass)) {
            throw new ProductAiException(
                'product_ai_media_unavailable',
                (string)__('媒体管理模块未启用，无法创建图片优化任务'),
                503,
            );
        }

        return ObjectManager::getInstance($factoryClass)->freeze(['locale_code' => $locale], $actorId);
    }

    private function findActiveJob(int $websiteId, string $uuid, string $taskType, string $inputHash): ?AiJob
    {
        $job = ObjectManager::make(AiJob::class)
            ->reset()
            ->where(AiJob::schema_fields_WEBSITE_ID, $websiteId)
            ->where(AiJob::schema_fields_PRODUCT_UUID, $uuid)
            ->where(AiJob::schema_fields_TASK_TYPE, $taskType)
            ->where(AiJob::schema_fields_INPUT_HASH, $inputHash)
            ->where(
                AiJob::schema_fields_STATUS,
                [AiJob::STATUS_QUEUED, AiJob::STATUS_RUNNING, AiJob::STATUS_CANCEL_REQUESTED],
                'IN',
            )
            ->order(AiJob::schema_fields_CREATED_AT, 'DESC')
            ->find()
            ->fetch();

        return $job->getId() ? $job : null;
    }
}
