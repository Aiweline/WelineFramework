<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service\Document;

use Weline\Ai\Api\AiModel;
use Weline\Ai\Api\AiRuntimeInterface;
use Weline\Ai\Api\Configuration\ScenarioConfigurationInterface;
use Weline\Ai\Api\Configuration\ScenarioRecord;
use Weline\DeveloperWorkspace\Model\Document;
use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\DeveloperWorkspace\Model\Document\Catalog\Translation as CatalogTranslation;
use Weline\DeveloperWorkspace\Model\Document\Translation;
use Weline\DeveloperWorkspace\Model\Document\TranslationJob;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;

class DocumentTranslationTaskService
{
    public const ADAPTER_CODE = 'developer_document_translation';
    public const REQUEST_TYPE = 'developer_document_translation';

    public function __construct(
        private DocumentTranslationConfigService $configService,
        private DocumentSourceService $sourceService,
        private MarkdownTranslationSegmenter $segmenter,
        private Document $documentModel,
        private Catalog $catalogModel,
        private Translation $translationModel,
        private CatalogTranslation $catalogTranslationModel,
        private TranslationJob $jobModel,
        private ScenarioConfigurationInterface $scenarioConfiguration,
        private RuntimeProviderResolver $runtimeProviderResolver,
    ) {
    }

    public function enqueueMissingAndStale(int $limit = 500): array
    {
        $locales = $this->configService->getEnabledTargetLocales();
        if ($locales === []) {
            return ['created' => 0, 'skipped' => 0, 'message' => __('No AI translation locale is enabled.')];
        }

        $created = 0;
        $skipped = 0;
        $config = $this->configService->getConfig();

        if (!empty($config['scopes']['documents']) || !empty($config['scopes']['api_documents'])) {
            $documents = $this->documentModel->clear()
                ->order(Document::schema_fields_ID, 'ASC')
                ->limit(max(1, $limit), 0)
                ->select()
                ->fetch()
                ->getItems();
            foreach ($documents as $document) {
                if (!$document instanceof Document || !$document->getId()) {
                    continue;
                }
                $isApi = str_starts_with($document->getModuleName(), 'API_');
                if (($isApi && empty($config['scopes']['api_documents'])) || (!$isApi && empty($config['scopes']['documents']))) {
                    $skipped++;
                    continue;
                }
                foreach ($locales as $locale) {
                    $created += $this->enqueueDocument($document, $locale) ? 1 : 0;
                }
            }
        }

        if (!empty($config['scopes']['catalogs'])) {
            $catalogs = $this->catalogModel->clear()
                ->where(Catalog::schema_fields_is_active, 1)
                ->order(Catalog::schema_fields_ID, 'ASC')
                ->limit(max(1, $limit), 0)
                ->select()
                ->fetch()
                ->getItems();
            foreach ($catalogs as $catalog) {
                if (!$catalog instanceof Catalog || !$catalog->getId()) {
                    continue;
                }
                foreach ($locales as $locale) {
                    $created += $this->enqueueCatalog($catalog, $locale) ? 1 : 0;
                }
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    public function enqueueDocument(Document $document, string $locale, bool $force = false): bool
    {
        if (!$this->configService->canAutoTranslateLocale($locale)) {
            return false;
        }
        $isApiDocument = str_starts_with((string)$document->getModuleName(), 'API_');
        if (($isApiDocument && !$this->configService->isScopeEnabled('api_documents'))
            || (!$isApiDocument && !$this->configService->isScopeEnabled('documents'))) {
            return false;
        }

        $sourceHash = $this->sourceService->getDocumentSourceHash($document);
        $translation = $this->translationModel->clear()
            ->where(Translation::schema_fields_SOURCE_DOCUMENT_ID, $document->getId())
            ->where(Translation::schema_fields_LOCALE, $locale)
            ->find()
            ->fetch();

        if ($translation && $translation->getId()) {
            if ((int)$translation->getData(Translation::schema_fields_IS_MANUAL_OVERRIDE) && !$force) {
                return false;
            }
            if ((string)$translation->getData(Translation::schema_fields_SOURCE_HASH) === $sourceHash
                && (string)$translation->getData(Translation::schema_fields_STATUS) === Translation::STATUS_TRANSLATED
                && !$force) {
                return false;
            }
            if ((string)$translation->getData(Translation::schema_fields_SOURCE_HASH) !== $sourceHash) {
                $translation->setData(Translation::schema_fields_STATUS, Translation::STATUS_STALE)->save();
            }
        }

        return $this->enqueueJob(TranslationJob::TARGET_DOCUMENT, (int)$document->getId(), $locale, $sourceHash, $force);
    }

    public function enqueueCatalog(Catalog $catalog, string $locale, bool $force = false): bool
    {
        if (!$this->configService->canAutoTranslateLocale($locale)) {
            return false;
        }
        if (!$this->configService->isScopeEnabled('catalogs')) {
            return false;
        }

        $sourceHash = $this->sourceService->getCatalogSourceHash($catalog);
        $translation = $this->catalogTranslationModel->clear()
            ->where(CatalogTranslation::schema_fields_CATALOG_ID, $catalog->getId())
            ->where(CatalogTranslation::schema_fields_LOCALE, $locale)
            ->find()
            ->fetch();

        if ($translation && $translation->getId()) {
            if ((int)$translation->getData(CatalogTranslation::schema_fields_IS_MANUAL_OVERRIDE) && !$force) {
                return false;
            }
            if ((string)$translation->getData(CatalogTranslation::schema_fields_SOURCE_HASH) === $sourceHash
                && (string)$translation->getData(CatalogTranslation::schema_fields_STATUS) === Translation::STATUS_TRANSLATED
                && !$force) {
                return false;
            }
            if ((string)$translation->getData(CatalogTranslation::schema_fields_SOURCE_HASH) !== $sourceHash) {
                $translation->setData(CatalogTranslation::schema_fields_STATUS, Translation::STATUS_STALE)->save();
            }
        }

        return $this->enqueueJob(TranslationJob::TARGET_CATALOG, (int)$catalog->getId(), $locale, $sourceHash, $force);
    }

    public function processBatch(?int $batchSize = null): array
    {
        $validation = $this->validateConfiguration();
        if (!$validation['ok']) {
            $status = $this->configService->getEnabledTargetLocales() === []
                ? TranslationJob::STATUS_DISABLED
                : TranslationJob::STATUS_BLOCKED_CONFIG;
            $this->markPendingJobs($status, (string)$validation['message']);
            return ['processed' => 0, 'translated' => 0, 'failed' => 0, 'blocked' => 1, 'message' => $validation['message']];
        }

        $config = $this->configService->getConfig();
        $batchSize = $batchSize ?? (int)$config['batch_size'];
        $jobs = $this->claimJobs(max(1, $batchSize));
        $result = ['processed' => 0, 'translated' => 0, 'failed' => 0, 'blocked' => 0];

        foreach ($jobs as $job) {
            $result['processed']++;
            try {
                $this->processJob($job, (string)$validation['model_code']);
                $result['translated']++;
            } catch (\Throwable $throwable) {
                $this->failJob($job, $throwable);
                $result['failed']++;
            }
        }

        return $result;
    }

    public function retryFailed(): int
    {
        $jobs = [];
        foreach ([TranslationJob::STATUS_FAILED, TranslationJob::STATUS_BLOCKED_CONFIG] as $status) {
            $rows = $this->jobModel->clear()
                ->where(TranslationJob::schema_fields_STATUS, $status)
                ->select()
                ->fetch()
                ->getItems();
            foreach ($rows as $row) {
                $jobs[] = $row;
            }
        }

        $count = 0;
        foreach ($jobs as $job) {
            if (!$job instanceof TranslationJob) {
                continue;
            }
            $retryCount = (int)$job->getData(TranslationJob::schema_fields_RETRY_COUNT);
            if (!$this->shouldResetFailedJobForRetry($job)) {
                continue;
            }
            $job->setData(TranslationJob::schema_fields_STATUS, TranslationJob::STATUS_PENDING)
                ->setData(TranslationJob::schema_fields_LOCKED_AT, 0)
                ->setData(TranslationJob::schema_fields_LOCKED_BY, '')
                ->setData(TranslationJob::schema_fields_RETRY_COUNT, $retryCount >= (int)$job->getData(TranslationJob::schema_fields_MAX_RETRIES) ? 0 : $retryCount)
                ->setData(TranslationJob::schema_fields_ERROR_MESSAGE, '')
                ->save();
            $count++;
        }

        return $count;
    }

    private function shouldResetFailedJobForRetry(TranslationJob $job): bool
    {
        if ((int)$job->getData(TranslationJob::schema_fields_RETRYABLE)) {
            return true;
        }

        $message = strtolower((string)$job->getData(TranslationJob::schema_fields_ERROR_MESSAGE));
        return str_contains($message, 'document is too large for configured ai translation limit')
            || $this->isRetryableError($message);
    }

    public function getOverview(): array
    {
        $jobs = $this->jobModel->clear()->select()->fetchArray();
        $counts = [];
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0, 'actual_cost' => 0.0, 'estimated_cost' => 0.0];
        $recentFailures = [];
        foreach (is_array($jobs) ? $jobs : [] as $row) {
            $status = (string)($row[TranslationJob::schema_fields_STATUS] ?? 'unknown');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $usage['prompt_tokens'] += (int)($row[TranslationJob::schema_fields_PROMPT_TOKENS] ?? 0);
            $usage['completion_tokens'] += (int)($row[TranslationJob::schema_fields_COMPLETION_TOKENS] ?? 0);
            $usage['total_tokens'] += (int)($row[TranslationJob::schema_fields_TOTAL_TOKENS] ?? 0);
            $usage['actual_cost'] += (float)($row[TranslationJob::schema_fields_ACTUAL_COST] ?? 0);
            $usage['estimated_cost'] += (float)($row[TranslationJob::schema_fields_ESTIMATED_COST] ?? 0);
            if ($status === TranslationJob::STATUS_FAILED || $status === TranslationJob::STATUS_BLOCKED_CONFIG) {
                $recentFailures[] = $row;
            }
        }

        $adapter = $this->getAdapterRecord(false);
        $modelCode = $adapter ? ($adapter->getModelBinding(AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT) ?? '') : '';
        $validation = $this->validateConfiguration(false);

        return [
            'counts' => $counts,
            'usage' => $usage,
            'adapter' => [
                'exists' => (bool)($adapter && $adapter->id > 0),
                'active' => (bool)($adapter && $adapter->active),
                'code' => self::ADAPTER_CODE,
                'name' => $adapter?->name ?? '',
                'version' => $adapter?->version ?? '',
                'model_code' => $modelCode,
            ],
            'validation' => $validation,
            'recent_failures' => array_slice(array_reverse($recentFailures), 0, 10),
        ];
    }

    public function getTextModels(): array
    {
        return array_map(
            static fn(AiModel $model): array => $model->toArray(),
            $this->scenarioConfiguration->activeModels(AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT),
        );
    }

    public function saveModelBinding(string $modelCode): void
    {
        $adapter = $this->getAdapterRecord(true);
        if (!$adapter || $adapter->id <= 0) {
            throw new Exception(__('Document translation adapter is not registered.'));
        }

        if ($modelCode !== '') {
            $model = $this->scenarioConfiguration->model(
                $modelCode,
                true,
                AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT,
            );
            if (!$model || !$model->supportsPrimaryModality(AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT)) {
                throw new Exception(__('Selected model is not an active text-to-text model.'));
            }
        }

        if (!$this->scenarioConfiguration->bindModel(
            self::ADAPTER_CODE,
            AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT,
            $modelCode,
        )) {
            throw new Exception(__('Document translation adapter is not registered.'));
        }
    }

    public function scanAdapter(): array
    {
        $scanned = $this->scenarioConfiguration->scanAdapters();
        return ['scanned' => $scanned, 'exists' => (bool)$this->getAdapterRecord(false)?->id];
    }

    public function testAdapter(): array
    {
        $adapter = $this->scenarioConfiguration->adapter(self::ADAPTER_CODE);
        if (!$adapter) {
            $this->scanAdapter();
            $adapter = $this->scenarioConfiguration->adapter(self::ADAPTER_CODE);
        }
        if (!$adapter) {
            throw new Exception(__('Document translation adapter is not available.'));
        }

        $prepared = $this->segmenter->prepare([
            'title' => '测试标题',
            'summary' => '请保留 `inlineCode()` 和 https://example.com',
            'content' => "```php\n// 原始注释\n\$name = 'Weline';\n/* 块注释 */\n```\n\n## 小节\n正文 `Config::get()`。",
        ]);
        $fake = [];
        foreach ($prepared['segments'] as $segment) {
            $fake[$segment['id']] = '[EN] ' . $segment['text'];
        }
        $restored = $this->segmenter->restore($prepared['templates'], $fake, $prepared['protected_tokens']);
        $batchBudget = [
            'context_window' => 2048,
            'max_input_tokens' => 700,
            'max_segment_tokens' => 240,
            'max_output_tokens' => 1200,
        ];
        $adapterRecord = $this->getAdapterRecord(false);
        $modelCode = $adapterRecord ? (string)($adapterRecord->getModelBinding(AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT) ?? '') : '';
        if ($modelCode !== '') {
            try {
                $batchBudget = $this->resolveTranslationTokenBudget(
                    $this->loadActiveTextModel($modelCode),
                    $this->configService->getConfig()
                );
            } catch (\Throwable) {
            }
        }
        $longPrepared = $this->segmenter->prepare([
            'content' => str_repeat('这是一个用于验证大文档分批的长段落，包含 `Config::get()` 和 https://example.com 。', 40),
        ]);
        $batchPlan = $this->buildTranslationBatches($longPrepared['segments'], $batchBudget);

        return [
            'adapter' => [
                'code' => $adapter->getCode(),
                'name' => $adapter->getName(),
                'version' => $adapter->getVersion(),
            ],
            'segments' => $prepared['segments'],
            'restored' => $restored,
            'batching' => [
                'model_code' => $modelCode,
                'budget' => $batchBudget,
                'batch_count' => count($batchPlan['batches']),
                'split_part_count' => count($batchPlan['part_map']),
            ],
        ];
    }

    public function validateConfiguration(bool $scanAdapter = true): array
    {
        $config = $this->configService->getConfig();
        if (empty($config['enabled'])) {
            return ['ok' => false, 'message' => __('Document AI translation is disabled.')];
        }
        if ($this->configService->getEnabledTargetLocales() === []) {
            return ['ok' => false, 'message' => __('No target locale has AI translation enabled.')];
        }
        if (!$this->configService->hasEnabledScope()) {
            return ['ok' => false, 'message' => __('No document translation scope is enabled.')];
        }

        $dailyLimit = (int)($config['daily_token_limit'] ?? 0);
        if ($dailyLimit > 0 && $this->getUsedTokensSince(strtotime('today') ?: 0) >= $dailyLimit) {
            return ['ok' => false, 'message' => __('Daily document translation token limit has been reached.')];
        }

        $monthlyLimit = (int)($config['monthly_token_limit'] ?? 0);
        if ($monthlyLimit > 0 && $this->getUsedTokensSince(strtotime(date('Y-m-01 00:00:00')) ?: 0) >= $monthlyLimit) {
            return ['ok' => false, 'message' => __('Monthly document translation token limit has been reached.')];
        }

        $adapter = $this->getAdapterRecord($scanAdapter);
        if (!$adapter || $adapter->id <= 0) {
            return ['ok' => false, 'message' => __('Document translation adapter is missing. Scan adapters first.')];
        }
        if (!$adapter->active) {
            return ['ok' => false, 'message' => __('Document translation adapter is inactive.')];
        }

        $modelCode = $adapter->getModelBinding(AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT) ?? '';
        if ($modelCode === '') {
            return ['ok' => false, 'message' => __('No text-to-text model is bound to the document translation adapter.')];
        }

        $model = $this->scenarioConfiguration->model(
            $modelCode,
            true,
            AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT,
        );
        if (!$model || !$model->supportsPrimaryModality(AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT)) {
            return ['ok' => false, 'message' => __('Bound model is not an active text-to-text model.'), 'model_code' => $modelCode];
        }

        $availability = $this->scenarioConfiguration->providerAvailability($modelCode);
        if (!$availability->available) {
            return ['ok' => false, 'message' => __('No available provider account for the bound model.'), 'model_code' => $modelCode];
        }

        return ['ok' => true, 'message' => __('Ready'), 'model_code' => $modelCode, 'provider_code' => $availability->providerCode];
    }

    private function enqueueJob(string $targetType, int $targetId, string $locale, string $sourceHash, bool $force): bool
    {
        $config = $this->configService->getConfig();
        $job = $this->jobModel->clear()
            ->where(TranslationJob::schema_fields_TARGET_TYPE, $targetType)
            ->where(TranslationJob::schema_fields_TARGET_ID, $targetId)
            ->where(TranslationJob::schema_fields_LOCALE, $locale)
            ->where(TranslationJob::schema_fields_SOURCE_HASH, $sourceHash)
            ->find()
            ->fetch();

        if ($job && $job->getId()) {
            if (!$force && in_array((string)$job->getData(TranslationJob::schema_fields_STATUS), [
                TranslationJob::STATUS_PENDING,
                TranslationJob::STATUS_TRANSLATING,
                TranslationJob::STATUS_TRANSLATED,
            ], true)) {
                return false;
            }
        } else {
            $job = ObjectManager::make(TranslationJob::class);
        }

        $job->setData([
            TranslationJob::schema_fields_TARGET_TYPE => $targetType,
            TranslationJob::schema_fields_TARGET_ID => $targetId,
            TranslationJob::schema_fields_LOCALE => $locale,
            TranslationJob::schema_fields_SOURCE_LOCALE => (string)$config['source_locale'],
            TranslationJob::schema_fields_SOURCE_HASH => $sourceHash,
            TranslationJob::schema_fields_STATUS => TranslationJob::STATUS_PENDING,
            TranslationJob::schema_fields_RETRYABLE => 1,
            TranslationJob::schema_fields_RETRY_COUNT => $force ? 0 : (int)($job->getData(TranslationJob::schema_fields_RETRY_COUNT) ?? 0),
            TranslationJob::schema_fields_MAX_RETRIES => (int)$config['max_retries'],
            TranslationJob::schema_fields_LOCKED_AT => 0,
            TranslationJob::schema_fields_LOCKED_BY => '',
            TranslationJob::schema_fields_ERROR_MESSAGE => '',
        ])->save();

        return true;
    }

    /**
     * @return TranslationJob[]
     */
    private function claimJobs(int $batchSize): array
    {
        $timeout = time() - 3600;
        $items = [];
        foreach ([TranslationJob::STATUS_PENDING, TranslationJob::STATUS_FAILED] as $status) {
            $remaining = $batchSize - count($items);
            if ($remaining <= 0) {
                break;
            }
            $rows = $this->jobModel->clear()
                ->where(TranslationJob::schema_fields_STATUS, $status)
                ->order(TranslationJob::schema_fields_PRIORITY, 'DESC')
                ->order(TranslationJob::schema_fields_ID, 'ASC')
                ->limit($remaining, 0)
                ->select()
                ->fetch()
                ->getItems();
            $items = array_merge($items, is_array($rows) ? $rows : []);
        }

        $claimed = [];
        $worker = gethostname() . ':' . getmypid();
        foreach ($items as $job) {
            if (!$job instanceof TranslationJob || !$job->getId()) {
                continue;
            }
            if ((string)$job->getData(TranslationJob::schema_fields_STATUS) === TranslationJob::STATUS_FAILED) {
                if (!(int)$job->getData(TranslationJob::schema_fields_RETRYABLE)) {
                    continue;
                }
                if ((int)$job->getData(TranslationJob::schema_fields_RETRY_COUNT) >= (int)$job->getData(TranslationJob::schema_fields_MAX_RETRIES)) {
                    continue;
                }
            }
            $lockedAt = (int)$job->getData(TranslationJob::schema_fields_LOCKED_AT);
            if ($lockedAt > 0 && $lockedAt > $timeout) {
                continue;
            }
            $job->setData(TranslationJob::schema_fields_STATUS, TranslationJob::STATUS_TRANSLATING)
                ->setData(TranslationJob::schema_fields_LOCKED_AT, time())
                ->setData(TranslationJob::schema_fields_LOCKED_BY, $worker)
                ->save();
            $claimed[] = $job;
        }

        return $claimed;
    }

    private function processJob(TranslationJob $job, string $modelCode): void
    {
        $targetType = (string)$job->getData(TranslationJob::schema_fields_TARGET_TYPE);
        if ($targetType === TranslationJob::TARGET_DOCUMENT) {
            $this->processDocumentJob($job, $modelCode);
            return;
        }
        if ($targetType === TranslationJob::TARGET_CATALOG) {
            $this->processCatalogJob($job, $modelCode);
            return;
        }

        throw new Exception(__('Unknown document translation target type: %{1}', [$targetType]));
    }

    private function processDocumentJob(TranslationJob $job, string $modelCode): void
    {
        $document = ObjectManager::make(Document::class)->load((int)$job->getData(TranslationJob::schema_fields_TARGET_ID));
        if (!$document || !$document->getId()) {
            throw new Exception(__('Source document no longer exists.'));
        }

        $fields = [
            'title' => (string)$document->getTitle(),
            'summary' => (string)$document->getData(Document::schema_fields_summary),
            'content' => $this->sourceService->getDocumentContent($document),
        ];
        $translated = $this->translateFields($job, $modelCode, $fields);
        $translation = $this->translationModel->clear()
            ->where(Translation::schema_fields_SOURCE_DOCUMENT_ID, $document->getId())
            ->where(Translation::schema_fields_LOCALE, $job->getData(TranslationJob::schema_fields_LOCALE))
            ->find()
            ->fetch();
        if (!$translation || !$translation->getId()) {
            $translation = ObjectManager::make(Translation::class);
        }
        $translation->setData([
            Translation::schema_fields_SOURCE_DOCUMENT_ID => $document->getId(),
            Translation::schema_fields_LOCALE => $job->getData(TranslationJob::schema_fields_LOCALE),
            Translation::schema_fields_SOURCE_LOCALE => $job->getData(TranslationJob::schema_fields_SOURCE_LOCALE),
            Translation::schema_fields_TITLE => $translated['title'] ?? $fields['title'],
            Translation::schema_fields_SUMMARY => $translated['summary'] ?? $fields['summary'],
            Translation::schema_fields_CONTENT => $translated['content'] ?? $fields['content'],
            Translation::schema_fields_SOURCE_HASH => $job->getData(TranslationJob::schema_fields_SOURCE_HASH),
            Translation::schema_fields_STATUS => Translation::STATUS_TRANSLATED,
            Translation::schema_fields_ERROR_MESSAGE => '',
            Translation::schema_fields_TRANSLATED_AT => time(),
        ])->save();

        $this->completeJob($job, $modelCode);
    }

    private function processCatalogJob(TranslationJob $job, string $modelCode): void
    {
        $catalog = ObjectManager::make(Catalog::class)->load((int)$job->getData(TranslationJob::schema_fields_TARGET_ID));
        if (!$catalog || !$catalog->getId()) {
            throw new Exception(__('Source catalog no longer exists.'));
        }

        $fields = [
            'name' => (string)$catalog->getName(),
            'description' => (string)$catalog->getDescription(),
        ];
        $translated = $this->translateFields($job, $modelCode, $fields);
        $translation = $this->catalogTranslationModel->clear()
            ->where(CatalogTranslation::schema_fields_CATALOG_ID, $catalog->getId())
            ->where(CatalogTranslation::schema_fields_LOCALE, $job->getData(TranslationJob::schema_fields_LOCALE))
            ->find()
            ->fetch();
        if (!$translation || !$translation->getId()) {
            $translation = ObjectManager::make(CatalogTranslation::class);
        }
        $translation->setData([
            CatalogTranslation::schema_fields_CATALOG_ID => $catalog->getId(),
            CatalogTranslation::schema_fields_LOCALE => $job->getData(TranslationJob::schema_fields_LOCALE),
            CatalogTranslation::schema_fields_SOURCE_LOCALE => $job->getData(TranslationJob::schema_fields_SOURCE_LOCALE),
            CatalogTranslation::schema_fields_NAME => $translated['name'] ?? $fields['name'],
            CatalogTranslation::schema_fields_DESCRIPTION => $translated['description'] ?? $fields['description'],
            CatalogTranslation::schema_fields_SOURCE_HASH => $job->getData(TranslationJob::schema_fields_SOURCE_HASH),
            CatalogTranslation::schema_fields_STATUS => Translation::STATUS_TRANSLATED,
            CatalogTranslation::schema_fields_ERROR_MESSAGE => '',
            CatalogTranslation::schema_fields_TRANSLATED_AT => time(),
        ])->save();

        $this->completeJob($job, $modelCode);
    }

    private function translateFields(TranslationJob $job, string $modelCode, array $fields): array
    {
        $config = $this->configService->getConfig();
        $fullText = implode("\n\n", array_map('strval', $fields));
        $estimatedTokens = $this->sourceService->estimateTokens($fullText);

        $prepared = $this->segmenter->prepare($fields);
        if (empty($prepared['segments'])) {
            return $fields;
        }

        $model = $this->loadActiveTextModel($modelCode);
        $budget = $this->resolveTranslationTokenBudget($model, $config);
        $plan = $this->buildTranslationBatches($prepared['segments'], $budget);

        $requestId = self::REQUEST_TYPE . '_' . (int)$job->getId() . '_' . substr(hash('sha1', uniqid('', true)), 0, 10);
        $job->setData(TranslationJob::schema_fields_AI_REQUEST_ID, $requestId)
            ->setData(TranslationJob::schema_fields_MODEL_CODE, $modelCode)
            ->setData(TranslationJob::schema_fields_ESTIMATED_COST, $this->estimateCost($modelCode, $estimatedTokens * 2))
            ->setData(TranslationJob::schema_fields_USAGE_ESTIMATED, 1)
            ->setData(TranslationJob::schema_fields_ERROR_MESSAGE, '')
            ->save();

        $translated = [];
        $translatedParts = [];
        $batches = $plan['batches'];
        $batchTotal = count($batches);

        foreach ($batches as $batchIndex => $batch) {
            $batchSegments = $batch['segments'] ?? [];
            if ($batchSegments === []) {
                continue;
            }
            $batchRequestId = $batchTotal > 1
                ? $requestId . '_c' . str_pad((string)($batchIndex + 1), 3, '0', STR_PAD_LEFT)
                : $requestId;

            $response = $this->aiRuntime()->generate(
                'Translate DeveloperWorkspace document segments.',
                $modelCode,
                self::ADAPTER_CODE,
                (string)$job->getData(TranslationJob::schema_fields_LOCALE),
                [
                    'source_locale' => $job->getData(TranslationJob::schema_fields_SOURCE_LOCALE),
                    'target_locale' => $job->getData(TranslationJob::schema_fields_LOCALE),
                    'format' => 'markdown',
                    'segments' => $batchSegments,
                    'protected_tokens' => $this->filterProtectedTokensForSegments($prepared['protected_tokens'], $batchSegments),
                    'request_id' => $batchRequestId,
                    'request_type' => self::REQUEST_TYPE,
                    'scenario_code' => self::ADAPTER_CODE,
                    'batch_index' => $batchIndex + 1,
                    'batch_total' => $batchTotal,
                    'temperature' => 0.1,
                    'max_tokens' => (int)($batch['max_output_tokens'] ?? $budget['max_output_tokens']),
                ],
                null,
                true
            );

            foreach ($this->decodeTranslatedSegments($response) as $id => $text) {
                if (isset($plan['part_map'][$id])) {
                    $sourceId = $plan['part_map'][$id]['source_id'];
                    $partIndex = $plan['part_map'][$id]['part_index'];
                    $translatedParts[$sourceId][$partIndex] = $text;
                    continue;
                }
                $translated[$id] = $text;
            }
        }

        foreach ($translatedParts as $sourceId => $parts) {
            ksort($parts);
            $expectedParts = $plan['split_part_counts'][$sourceId] ?? count($parts);
            if (count($parts) !== $expectedParts) {
                throw new Exception(__('AI translation adapter missed one or more split segment parts: %{1}', [$sourceId]));
            }
            $translated[$sourceId] = implode('', array_map('strval', $parts));
        }

        $this->assertAllSegmentsTranslated($prepared['segments'], $translated);

        return $this->segmenter->restore($prepared['templates'], $translated, $prepared['protected_tokens']);
    }

    private function aiRuntime(): AiRuntimeInterface
    {
        $runtime = $this->runtimeProviderResolver->resolve(AiRuntimeInterface::class);
        if (!$runtime instanceof AiRuntimeInterface) {
            throw new Exception(__('AI运行时提供器不可用。'));
        }

        return $runtime;
    }

    private function loadActiveTextModel(string $modelCode): AiModel
    {
        $model = $this->scenarioConfiguration->model(
            $modelCode,
            true,
            AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT,
        );
        if (!$model || !$model->supportsPrimaryModality(AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT)) {
            throw new Exception(__('Bound model is not an active text-to-text model.'));
        }

        return $model;
    }

    private function resolveTranslationTokenBudget(AiModel $model, array $config): array
    {
        $modelMaxTokens = max(0, (int)$model->getMaxTokens());
        $modelConfig = $model->getConfig();
        $providerConfig = $model->getProviderConfig();
        $capabilities = $model->getCapabilities();

        $contextWindow = $this->firstPositiveInt([
            $providerConfig['context_window'] ?? null,
            $providerConfig['context_length'] ?? null,
            $providerConfig['max_context_tokens'] ?? null,
            $modelConfig['context_window'] ?? null,
            $modelConfig['context_length'] ?? null,
            $modelConfig['max_context_tokens'] ?? null,
            $capabilities['context_window'] ?? null,
            $capabilities['context_length'] ?? null,
            $capabilities['max_context_tokens'] ?? null,
            $modelMaxTokens,
        ]);
        if ($contextWindow <= 0) {
            $contextWindow = 4096;
        }

        $maxOutputTokens = $this->firstPositiveInt([
            $providerConfig['max_output_tokens'] ?? null,
            $providerConfig['output_token_limit'] ?? null,
            $modelConfig['max_output_tokens'] ?? null,
            $modelConfig['output_token_limit'] ?? null,
            $modelMaxTokens,
        ]);
        if ($maxOutputTokens <= 0) {
            $maxOutputTokens = min(4096, max(1024, (int)floor($contextWindow * 0.45)));
        }

        $promptReserve = min(2000, max(800, (int)floor($contextWindow * 0.12)));
        $rawInputBudget = (int)floor(($contextWindow - $promptReserve) * 0.45);
        if ($maxOutputTokens > 0) {
            $rawInputBudget = min($rawInputBudget, max(256, (int)floor($maxOutputTokens * 0.7)));
        }

        $configuredPerRequestLimit = max(0, (int)($config['max_document_tokens'] ?? 0));
        if ($configuredPerRequestLimit > 0) {
            $rawInputBudget = min($rawInputBudget, $configuredPerRequestLimit);
        }

        $maxInputTokens = max(256, $rawInputBudget);
        $maxSegmentTokens = max(128, (int)floor($maxInputTokens * 0.9));

        return [
            'context_window' => $contextWindow,
            'max_input_tokens' => $maxInputTokens,
            'max_segment_tokens' => $maxSegmentTokens,
            'max_output_tokens' => max(256, $maxOutputTokens),
        ];
    }

    private function firstPositiveInt(array $values): int
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                $nested = $this->firstPositiveInt($value);
                if ($nested > 0) {
                    return $nested;
                }
                continue;
            }
            $number = (int)$value;
            if ($number > 0) {
                return $number;
            }
        }

        return 0;
    }

    private function buildTranslationBatches(array $segments, array $budget): array
    {
        $maxInputTokens = max(256, (int)$budget['max_input_tokens']);
        $expanded = [];
        $partMap = [];
        $splitPartCounts = [];

        foreach ($segments as $segment) {
            if (!is_array($segment) || !isset($segment['id'], $segment['text'])) {
                continue;
            }
            foreach ($this->expandSegmentForBudget($segment, (int)$budget['max_segment_tokens']) as $part) {
                $expanded[] = $part;
                if (($part['source_id'] ?? '') !== ($part['id'] ?? '')) {
                    $partMap[(string)$part['id']] = [
                        'source_id' => (string)$part['source_id'],
                        'part_index' => (int)$part['part_index'],
                    ];
                    $splitPartCounts[(string)$part['source_id']] = (int)$part['part_count'];
                }
            }
        }

        $batches = [];
        $current = [];
        $currentTokens = 0;
        foreach ($expanded as $segment) {
            $segmentForAi = ['id' => (string)$segment['id'], 'text' => (string)$segment['text']];
            $segmentTokens = $this->estimateSegmentPayloadTokens($segmentForAi);
            if ($current !== [] && $currentTokens + $segmentTokens > $maxInputTokens) {
                $batches[] = $this->makeTranslationBatch($current, $currentTokens, $budget);
                $current = [];
                $currentTokens = 0;
            }
            $current[] = $segmentForAi;
            $currentTokens += $segmentTokens;
        }
        if ($current !== []) {
            $batches[] = $this->makeTranslationBatch($current, $currentTokens, $budget);
        }

        return [
            'batches' => $batches,
            'part_map' => $partMap,
            'split_part_counts' => $splitPartCounts,
        ];
    }

    private function expandSegmentForBudget(array $segment, int $maxSegmentTokens): array
    {
        $id = (string)$segment['id'];
        $text = (string)$segment['text'];
        if ($this->estimateSegmentPayloadTokens(['id' => $id, 'text' => $text]) <= $maxSegmentTokens) {
            return [[
                'id' => $id,
                'source_id' => $id,
                'part_index' => 0,
                'part_count' => 1,
                'text' => $text,
            ]];
        }

        $chunks = $this->splitTextByTokenBudget($text, max(64, $maxSegmentTokens - 32));
        $parts = [];
        $partCount = count($chunks);
        foreach ($chunks as $index => $chunk) {
            $parts[] = [
                'id' => $id . '__part_' . ($index + 1),
                'source_id' => $id,
                'part_index' => $index,
                'part_count' => $partCount,
                'text' => $chunk,
            ];
        }

        return $parts;
    }

    private function splitTextByTokenBudget(string $text, int $maxTokens): array
    {
        if ($text === '') {
            return [''];
        }

        $pieces = preg_split('/(?<=[。！？；.!?;])(\s*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!is_array($pieces) || count($pieces) <= 1) {
            $pieces = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($pieces) || $pieces === []) {
            $pieces = [$text];
        }

        $chunks = [];
        $current = '';
        foreach ($pieces as $piece) {
            $piece = (string)$piece;
            if ($piece === '') {
                continue;
            }
            if ($current !== '' && $this->sourceService->estimateTokens($current . $piece) > $maxTokens) {
                $chunks[] = $current;
                $current = '';
            }
            if ($this->sourceService->estimateTokens($piece) > $maxTokens) {
                foreach ($this->splitLongTextByCharacters($piece, $maxTokens) as $subPiece) {
                    if ($current !== '' && $this->sourceService->estimateTokens($current . $subPiece) > $maxTokens) {
                        $chunks[] = $current;
                        $current = '';
                    }
                    $current .= $subPiece;
                }
                continue;
            }
            $current .= $piece;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks !== [] ? $chunks : [$text];
    }

    private function splitLongTextByCharacters(string $text, int $maxTokens): array
    {
        $length = mb_strlen($text, 'UTF-8');
        $chunkChars = max(80, (int)floor($maxTokens / 2));
        $chunks = [];
        for ($offset = 0; $offset < $length; $offset += $chunkChars) {
            $chunks[] = mb_substr($text, $offset, $chunkChars, 'UTF-8');
        }

        return $chunks;
    }

    private function makeTranslationBatch(array $segments, int $sourceTokens, array $budget): array
    {
        $maxOutputTokens = (int)$budget['max_output_tokens'];
        $outputTokens = min($maxOutputTokens, max(256, (int)ceil($sourceTokens * 1.4) + 256));

        return [
            'segments' => $segments,
            'source_tokens' => $sourceTokens,
            'max_output_tokens' => $outputTokens,
        ];
    }

    private function estimateSegmentPayloadTokens(array $segment): int
    {
        return $this->sourceService->estimateTokens((string)($segment['id'] ?? '') . "\n" . (string)($segment['text'] ?? '')) + 16;
    }

    private function filterProtectedTokensForSegments(array $protectedTokens, array $segments): array
    {
        if ($protectedTokens === []) {
            return [];
        }

        $payload = json_encode($segments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $filtered = [];
        foreach ($protectedTokens as $token => $raw) {
            if (str_contains($payload, (string)$token)) {
                $filtered[$token] = $raw;
            }
        }

        return $filtered;
    }

    private function decodeTranslatedSegments(string $response): array
    {
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['segments']) || !is_array($decoded['segments'])) {
            throw new Exception(__('AI translation adapter returned invalid JSON segments.'));
        }

        $translated = [];
        foreach ($decoded['segments'] as $segment) {
            if (!is_array($segment) || !isset($segment['id'], $segment['text'])) {
                throw new Exception(__('AI translation adapter returned a segment without id or text.'));
            }
            $translated[(string)$segment['id']] = (string)$segment['text'];
        }

        return $translated;
    }

    private function assertAllSegmentsTranslated(array $segments, array $translated): void
    {
        foreach ($segments as $segment) {
            if (!is_array($segment) || !isset($segment['id'])) {
                continue;
            }
            $id = (string)$segment['id'];
            if (!array_key_exists($id, $translated)) {
                throw new Exception(__('AI translation adapter missed segment id: %{1}', [$id]));
            }
        }
    }

    private function completeJob(TranslationJob $job, string $modelCode): void
    {
        $usage = $this->aggregateUsageByRequestId((string)$job->getData(TranslationJob::schema_fields_AI_REQUEST_ID));
        $job->setData(TranslationJob::schema_fields_STATUS, TranslationJob::STATUS_TRANSLATED)
            ->setData(TranslationJob::schema_fields_MODEL_CODE, $modelCode)
            ->setData(TranslationJob::schema_fields_LOCKED_AT, 0)
            ->setData(TranslationJob::schema_fields_LOCKED_BY, '')
            ->setData(TranslationJob::schema_fields_ERROR_MESSAGE, '')
            ->setData(TranslationJob::schema_fields_RETRYABLE, 0);

        if ($usage) {
            $job->setData(TranslationJob::schema_fields_PROMPT_TOKENS, (int)$usage['prompt_tokens'])
                ->setData(TranslationJob::schema_fields_COMPLETION_TOKENS, (int)$usage['completion_tokens'])
                ->setData(TranslationJob::schema_fields_TOTAL_TOKENS, (int)$usage['total_tokens'])
                ->setData(TranslationJob::schema_fields_ACTUAL_COST, (float)$usage['actual_cost'])
                ->setData(TranslationJob::schema_fields_USAGE_ESTIMATED, 0);
        }

        $job->save();
    }

    private function failJob(TranslationJob $job, \Throwable $throwable): void
    {
        $message = $throwable->getMessage();
        $retryable = $this->isRetryableError($message);
        $retryCount = (int)$job->getData(TranslationJob::schema_fields_RETRY_COUNT) + 1;
        $maxRetries = (int)$job->getData(TranslationJob::schema_fields_MAX_RETRIES);
        if ($retryCount >= $maxRetries) {
            $retryable = false;
        }

        $job->setData(TranslationJob::schema_fields_STATUS, TranslationJob::STATUS_FAILED)
            ->setData(TranslationJob::schema_fields_RETRY_COUNT, $retryCount)
            ->setData(TranslationJob::schema_fields_RETRYABLE, $retryable ? 1 : 0)
            ->setData(TranslationJob::schema_fields_LOCKED_AT, 0)
            ->setData(TranslationJob::schema_fields_LOCKED_BY, '')
            ->setData(TranslationJob::schema_fields_ERROR_MESSAGE, $message)
            ->save();
    }

    private function isRetryableError(string $message): bool
    {
        $message = strtolower($message);
        foreach ([
            'timeout',
            'rate limit',
            '429',
            '500',
            '502',
            '503',
            '504',
            'connection',
            'temporarily',
            'must be json',
            'invalid json segments',
            'without id or text',
            'missing translated segment',
            'missed segment id',
            'translated segment id',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function markPendingJobs(string $status, string $message): void
    {
        $this->jobModel->clear()
            ->where(TranslationJob::schema_fields_STATUS, TranslationJob::STATUS_PENDING)
            ->update([
                TranslationJob::schema_fields_STATUS => $status,
                TranslationJob::schema_fields_ERROR_MESSAGE => $message,
                TranslationJob::schema_fields_RETRYABLE => $status === TranslationJob::STATUS_BLOCKED_CONFIG ? 1 : 0,
                TranslationJob::schema_fields_UPDATED_AT => time(),
            ])
            ->fetch();
    }

    private function getAdapterRecord(bool $scan): ?ScenarioRecord
    {
        return $this->scenarioConfiguration->scenario(self::ADAPTER_CODE, $scan);
    }

    private function aggregateUsageByRequestId(string $requestId): ?array
    {
        if ($requestId === '') {
            return null;
        }

        return $this->scenarioConfiguration->usageByRequestPrefix($requestId)?->toArray();
    }

    private function getUsedTokensSince(int $timestamp): int
    {
        if ($timestamp <= 0) {
            return 0;
        }

        $rows = $this->jobModel->clear()->select()->fetchArray();
        $tokens = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            if ((int)($row[TranslationJob::schema_fields_UPDATED_AT] ?? 0) < $timestamp) {
                continue;
            }
            $tokens += (int)($row[TranslationJob::schema_fields_TOTAL_TOKENS] ?? 0);
        }

        return $tokens;
    }

    private function estimateCost(string $modelCode, int $tokens): float
    {
        $model = $this->scenarioConfiguration->model($modelCode);
        if (!$model || $model->getId() <= 0) {
            return 0.0;
        }

        $half = (int)ceil($tokens / 2);
        return ($half / 1000) * (float)$model->getTokenPriceInput()
            + ($half / 1000) * (float)$model->getTokenPriceOutput();
    }
}
