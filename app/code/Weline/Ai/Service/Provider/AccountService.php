<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\ModelCollector;
use Weline\Ai\Service\Provider\ProviderInterface;
use Weline\Ai\Service\Provider\VendorConfigManager;
use Weline\Ai\Service\Provider\OpenAiProvider;
use Weline\Ai\Service\Provider\AnthropicProvider;
use Weline\Ai\Service\Provider\GeminiProvider;
use Weline\Ai\Service\Provider\VectorEngineProvider;
use Weline\Framework\App\Env;
use Weline\Framework\Database\Connection\Api\Sql\WriteIntentQueryInterface;

/**
 * Provider Account Service
 * 
 * 管理AI供应商账户的核心服务
 * 
 * @package Weline_Ai
 */
class AccountService
{
    private const BALANCE_PENDING = 0;
    private const BALANCE_APPLIED = 1;
    private const BALANCE_CLAIMED = 2;

    private string $lastProviderInstanceError = '';

    /**
     * @var ObjectManager
     */
    private ObjectManager $objectManager;

    private UsageAuditOutbox $usageAuditOutbox;

    /**
     * Constructor
     */
    public function __construct(?UsageAuditOutbox $usageAuditOutbox = null)
    {
        $this->objectManager = ObjectManager::getInstance();
        $this->usageAuditOutbox = $usageAuditOutbox ?? new UsageAuditOutbox();
    }

    /**
     * 获取支持的供应商列表
     * 
     * @return array
     */
    public function getSupportedProviders(): array
    {
        return VendorConfigManager::getSupportedProviders();
    }

    /**
     * 根据模型代码获取供应商代码
     * 
     * @param string $modelCode
     * @return string|null
     */
    public function getProviderByModelCode(string $modelCode): ?string
    {
        return VendorConfigManager::getProviderByModelCode($modelCode);
    }

    /**
     * Resolve provider code for a persisted model.
     *
     * Prefer the model's saved supplier to avoid ambiguous prefix matching
     * when multiple OpenAI-compatible providers expose similarly named models.
     */
    public function getProviderByModel(AiModel $model): ?string
    {
        $supplier = strtolower(trim((string)$model->getData(AiModel::schema_fields_SUPPLIER)));
        if ($supplier !== '' && VendorConfigManager::isProviderSupported($supplier)) {
            return $supplier;
        }

        return $this->getProviderByModelCode((string)$model->getData(AiModel::schema_fields_MODEL_CODE));
    }

    /**
     * 获取指定供应商的可用账户
     * 
     * @param string $providerCode
     * @param bool $allowZeroBalance When true, active and connected accounts are usable even if balance is recorded as zero.
     * @return Account|null
     */
    public function getAvailableAccount(string $providerCode, bool $allowZeroBalance = false): ?Account
    {
        /** @var Account $accountModel */
        $accountModel = $this->objectManager->make(Account::class);
        
        // 首先尝试获取默认账户
        $defaultAccount = $accountModel->clear()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(Account::schema_fields_IS_DEFAULT, 1)
            ->where(Account::schema_fields_IS_ACTIVE, 1)
            ->where(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_SUCCESS);
        if (!$allowZeroBalance) {
            $defaultAccount->where(Account::schema_fields_BALANCE, 0, '>');
        }
        $defaultAccount = $defaultAccount->find()->fetch();
            
        if ($defaultAccount->getId()) {
            return $defaultAccount;
        }
        
        // 如果没有默认账户，获取任意可用账户
        $availableAccount = $accountModel->clear()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(Account::schema_fields_IS_ACTIVE, 1)
            ->where(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_SUCCESS);
        if (!$allowZeroBalance) {
            $availableAccount->where(Account::schema_fields_BALANCE, 0, '>');
        }
        $availableAccount = $availableAccount
            ->order(Account::schema_fields_BALANCE, 'DESC')
            ->find()
            ->fetch();
            
        return $availableAccount->getId() ? $availableAccount : null;
    }

    /**
     * 测试账户连通性
     * 
     * @param Account $account
     * @return array ['success' => bool, 'message' => string, 'model_code' => string]
     */
    public function testConnection(Account $account, ?string $overrideModelCode = null, array $options = []): array
    {
        try {
            $providerCode = strtolower(trim((string)$account->getData(Account::schema_fields_PROVIDER_CODE)));
            $providerInfo = VendorConfigManager::getProviderConfig($providerCode);
            if (!$providerInfo) {
                throw new Exception(__('不支持的供应商: %{provider}', ['provider' => $providerCode]));
            }
            
            $testModelCode = trim((string)($overrideModelCode ?: ($providerInfo['test_model'] ?? '')));
            if ($testModelCode === '') {
                throw new Exception(__('供应商未配置测试模型'));
            }
            $this->ensureProviderTestModel((string)$providerCode, (string)$testModelCode, $providerInfo);
            $apiKeyPlain = $account->getDecryptedApiKey();
            $apiKeyTail = $apiKeyPlain ? substr($apiKeyPlain, -4) : '';
            $baseUrl = $this->normalizeProviderBaseUrl(
                $providerCode,
                (string)($account->getData(Account::schema_fields_BASE_URL) ?: ($providerInfo['base_url'] ?? ''))
            );
            Env::log('ai_provider_test.log', sprintf('[testConnection] account_id=%s provider=%s model=%s base_url=%s api_key_tail=%s',
                (string)$account->getId(), $providerCode, $testModelCode, $baseUrl, $apiKeyTail
            ));
            
            // 创建临时模型用于测试
            /** @var AiModel $testModel */
            $testModel = $this->objectManager->make(AiModel::class);
            $modelMeta = $this->findProviderModelMeta($providerInfo, $testModelCode);
            $primaryModality = $this->resolveTestPrimaryModality($testModelCode, $modelMeta, $options);
            $runtimeProviderConfig = is_array($options['provider_config'] ?? null) ? $options['provider_config'] : [];
            if ($providerCode === 'vectorengine') {
                foreach (['api_url', 'chat_api_url', 'embeddings_api_url', 'image_api_url'] as $urlKey) {
                    $runtimeProviderConfig[$urlKey] = $baseUrl;
                }
            }
            $testModel->setData([
                AiModel::schema_fields_SUPPLIER => $providerCode,
                AiModel::schema_fields_MODEL_CODE => $testModelCode,
                AiModel::schema_fields_PRIMARY_MODALITY => $primaryModality,
                AiModel::schema_fields_CAPABILITIES => json_encode($options['capabilities'] ?? $modelMeta['capabilities'] ?? []),
                AiModel::schema_fields_CONFIG => json_encode([
                    'api_key' => $apiKeyPlain,
                    'base_url' => $baseUrl,
                    'model' => $testModelCode  // 使用model字段而不是model_id
                ]),
                AiModel::schema_fields_PROVIDER_CONFIG => json_encode(array_replace(
                    $runtimeProviderConfig,
                    [
                    'api_key' => $apiKeyPlain,
                    'base_url' => $baseUrl,
                    'model' => $testModelCode,
                    'provider_model_code' => $testModelCode,
                    'account_id' => (int)$account->getId(),
                    ]
                ))
            ]);
            if ($account->getId() && (string)$account->getData(Account::schema_fields_BASE_URL) !== $baseUrl) {
                $account->setData(Account::schema_fields_BASE_URL, $baseUrl);
            }
            
            // 设置代理配置
            $proxyConfig = $account->getProxyConfig();
            if (!empty($proxyConfig)) {
                $testModel->setData(AiModel::schema_fields_PROXY_INFO, json_encode($proxyConfig));
            }
            
            // 获取对应的Provider
            $provider = $this->getProviderInstance($account->getData(Account::schema_fields_PROVIDER_CODE));
            if (!$provider) {
                $detail = $this->lastProviderInstanceError !== '' ? ('：' . $this->lastProviderInstanceError) : '';
                throw new Exception(__('无法创建供应商实例') . $detail);
            }
            
            if ($provider instanceof ProviderConnectionTestInterface) {
                $result = $provider->testConnection($testModel, [
                    'max_tokens' => 64,
                    'temperature' => 0,
                    'test_mode' => true,
                    'timeout' => $primaryModality === AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE ? 30 : 12
                ]);
            } else {
            // 执行测试请求
            $result = $provider->generate($testModel, '请回复"OK"表示连接成功', [
                'max_tokens' => 64,
                'temperature' => 0,
                'test_mode' => true,
                'timeout' => 12
            ]);
            }
            
            $testResponseContent = trim((string)($result['content'] ?? $result['response'] ?? ''));
            $testImages = is_array($result['images'] ?? null) ? $result['images'] : [];
            if ($testResponseContent !== '' || $testImages !== []) {
                $account->setData(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_SUCCESS);
                $account->setData(Account::schema_fields_CONNECTION_TEST_TIME, time());
                $account->setData(Account::schema_fields_CONNECTION_TEST_MESSAGE, __('连接成功'));
                $account->setData(Account::schema_fields_UPDATED_AT, time());

                $saveResult = $account->save();
                Env::log('ai_provider_test.log', sprintf('[testConnection][success] account_id=%s provider=%s status=%s save_result=%s images=%d',
                    (string)$account->getId(),
                    $providerCode,
                    Account::STATUS_SUCCESS,
                    $saveResult ? 'true' : 'false',
                    count($testImages)
                ));

                $account->reset()->load($account->getId());
                $actualStatus = $account->getData(Account::schema_fields_CONNECTION_STATUS);
                if ($actualStatus !== Account::STATUS_SUCCESS) {
                    Env::log('ai_provider_test.log', sprintf('[testConnection][warning] account_id=%s status_not_saved expected=%s actual=%s',
                        (string)$account->getId(),
                        Account::STATUS_SUCCESS,
                        $actualStatus
                    ));
                }

                return [
                    'success' => true,
                    'message' => __('连接测试成功'),
                    'model_code' => $testModelCode,
                    'account_id' => $account->getId(),
                    'provider' => $providerCode,
                    'base_url' => $baseUrl,
                    'request_url' => (string)($result['request_url'] ?? ''),
                    'api_key_tail' => $apiKeyTail,
                    'connection_status' => Account::STATUS_SUCCESS,
                    'connection_test_time' => time(),
                    'connection_test_message' => __('连接成功'),
                    'response' => $testResponseContent,
                    'duration' => isset($result['duration']) ? (float)$result['duration'] : 0.0,
                    'primary_modality' => $primaryModality,
                    'images' => $testImages,
                ];
            } else {
                throw new Exception(__('API响应为空'));
            }
            
        } catch (\Exception $e) {
            Env::log('ai_provider_test.log', sprintf('[testConnection][error] account_id=%s provider=%s error=%s',
                (string)$account->getId(), (string)$account->getData(Account::schema_fields_PROVIDER_CODE), $e->getMessage()
            ));
            // 更新连接状态
            $account->setData(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_FAILED);
            $account->setData(Account::schema_fields_CONNECTION_TEST_TIME, time());
            $account->setData(Account::schema_fields_CONNECTION_TEST_MESSAGE, $e->getMessage());
            $account->save();
            
            return [
                'success' => false,
                'message' => __('连接测试失败: %{msg}', ['msg' => $e->getMessage()]),
                'model_code' => $testModelCode ?? 'unknown',
                'account_id' => $account->getId(),
                'provider' => $account->getData(Account::schema_fields_PROVIDER_CODE),
                'base_url' => $this->normalizeProviderBaseUrl(
                    (string)$account->getData(Account::schema_fields_PROVIDER_CODE),
                    (string)($account->getData(Account::schema_fields_BASE_URL) ?: ($providerInfo['base_url'] ?? ''))
                ),
                'request_url' => $this->extractRequestUrlFromMessage($e->getMessage()),
                'api_key_tail' => $apiKeyTail ?? ''
            ];
        }
    }

    private function extractRequestUrlFromMessage(string $message): string
    {
        if (preg_match('/URL:\s*([^\s\)]+)/', $message, $matches)) {
            return trim((string)$matches[1]);
        }
        if (preg_match('/\bhttps?:\/\/\S+/', $message, $matches)) {
            return rtrim((string)$matches[0], ')，,。');
        }
        return '';
    }

    private function normalizeProviderBaseUrl(string $providerCode, string $baseUrl): string
    {
        $providerCode = strtolower(trim($providerCode));
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return '';
        }
        if ($providerCode === 'vectorengine') {
            $baseUrl = str_replace('://api.vectorengine.ai', '://api.vectorengine.cn', $baseUrl);
            foreach (['/chat/completions', '/completions', '/embeddings', '/images/generations', '/models'] as $suffix) {
                if (str_ends_with($baseUrl, $suffix)) {
                    $baseUrl = substr($baseUrl, 0, -strlen($suffix));
                    break;
                }
            }
            if (!preg_match('#/v\d+(?:beta)?$#', $baseUrl)) {
                $baseUrl .= '/v1';
            }
        }
        return $baseUrl;
    }

    /**
     * 记录使用情况
     * 
     * @param Account $account
     * @param AiModel $model
     * @param array $usage
     * @param array $context
     * @return UsageRecord
     */
    /**
     * @param array<string,mixed> $proxyConfig
     */
    private function probeProviderAccountError(string $providerCode, string $baseUrl, string $apiKey, array $proxyConfig = []): string
    {
        if ($apiKey === '' || $baseUrl === '') {
            return '';
        }
        $providerCode = strtolower(trim($providerCode));
        if (!in_array($providerCode, ['openai', 'deepseek'], true)) {
            return '';
        }

        $url = rtrim($baseUrl, '/') . '/models';
        $streamError = $this->probeProviderAccountErrorWithStream($url, $apiKey);
        if ($streamError !== '__WELINE_AI_PROVIDER_PROBE_OK__') {
            return $streamError;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return '';
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Weline-Ai-Provider-Test/1.0',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ],
        ];
        if (!empty($proxyConfig['enabled']) && !empty($proxyConfig['host'])) {
            $proxy = (string)$proxyConfig['host'];
            if (!empty($proxyConfig['port'])) {
                $proxy .= ':' . (string)$proxyConfig['port'];
            }
            $options[CURLOPT_PROXY] = $proxy;
            if (!empty($proxyConfig['username'])) {
                $options[CURLOPT_PROXYUSERPWD] = (string)$proxyConfig['username'] . ':' . (string)($proxyConfig['password'] ?? '');
            }
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $curlError !== '') {
            return 'Provider probe failed: ' . ($curlError !== '' ? $curlError : 'empty response');
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return '';
        }

        $rawBody = trim((string)$body);
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $code = trim((string)($decoded['code'] ?? $decoded['error']['code'] ?? ''));
            $message = trim((string)($decoded['message'] ?? $decoded['error']['message'] ?? ''));
            if ($code !== '' || $message !== '') {
                return trim(($code !== '' ? $code . ': ' : '') . ($message !== '' ? $message : ('HTTP ' . $httpCode)));
            }
        }

        return 'Provider probe failed: HTTP ' . $httpCode . ($rawBody !== '' ? ' ' . substr($rawBody, 0, 300) : '');
    }

    private function probeProviderAccountErrorWithStream(string $url, string $apiKey): string
    {
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
            'User-Agent: Weline-Ai-Provider-Test/1.0',
        ];
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 12,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers) . "\r\n",
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $statusCode = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string)$header, $matches)) {
                $statusCode = (int)$matches[1];
                break;
            }
        }
        if ($body === false) {
            return 'Provider probe failed: ' . (string)(error_get_last()['message'] ?? 'empty response');
        }
        if ($statusCode >= 200 && $statusCode < 300) {
            return '__WELINE_AI_PROVIDER_PROBE_OK__';
        }

        $rawBody = trim((string)$body);
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $code = trim((string)($decoded['code'] ?? $decoded['error']['code'] ?? ''));
            $message = trim((string)($decoded['message'] ?? $decoded['error']['message'] ?? ''));
            if ($code !== '' || $message !== '') {
                return trim(($code !== '' ? $code . ': ' : '') . ($message !== '' ? $message : ('HTTP ' . $statusCode)));
            }
        }

        return 'Provider probe failed: HTTP ' . $statusCode . ($rawBody !== '' ? ' ' . substr($rawBody, 0, 300) : '');
    }

    public function recordUsage(Account $account, AiModel $model, array $usage, array $context = []): UsageRecord
    {
        return $this->persistUsagePayload($this->buildUsagePayload($account, $model, $usage, $context));
    }

    /**
     * Persist usage without allowing an audit-store outage to invalidate a
     * provider response that has already completed.
     *
     * @return bool true when the database is current, false when a durable
     *              outbox event was created for Scheduler recovery.
     */
    public function recordUsageReliably(
        Account $account,
        AiModel $model,
        array $usage,
        array $context = [],
    ): bool {
        try {
            $payload = $this->buildUsagePayload($account, $model, $usage, $context);
        } catch (\Throwable $payloadFailure) {
            $requestId = trim((string)($context['request_id'] ?? ''));
            $signal = $this->auditFailureSignal($payloadFailure);
            Env::log(
                'ai_usage_audit_emergency.log',
                sprintf(
                    '[payload_failed] request_sha256=%s class=%s category=%s message_sha256=%s',
                    hash('sha256', $requestId),
                    $signal['class'],
                    $signal['category'],
                    $signal['message_sha256'],
                ),
                'CRITICAL',
                true,
                true,
                0,
            );

            return false;
        }
        try {
            $this->persistUsagePayload($payload);
            $this->usageAuditOutbox->acknowledge($payload);

            return true;
        } catch (\Throwable $databaseFailure) {
            $databaseSignal = $this->auditFailureSignal($databaseFailure);
            try {
                $path = $this->usageAuditOutbox->defer($payload, $databaseFailure);
                Env::log(
                    'ai_usage_audit_recovery.log',
                    sprintf(
                        '[deferred] request_sha256=%s outbox=%s class=%s category=%s message_sha256=%s',
                        hash('sha256', (string)$payload[UsageRecord::schema_fields_REQUEST_ID]),
                        basename($path),
                        $databaseSignal['class'],
                        $databaseSignal['category'],
                        $databaseSignal['message_sha256'],
                    ),
                    'ERROR',
                    true,
                    true,
                    0,
                );
            } catch (\Throwable $outboxFailure) {
                // Preserve the successful provider result. This independent
                // append-only log is the last-resort audit signal when both
                // SQLite and the structured outbox path are unavailable.
                $outboxSignal = $this->auditFailureSignal($outboxFailure);
                Env::log(
                    'ai_usage_audit_emergency.log',
                    sprintf(
                        '[outbox_failed] request_sha256=%s db_class=%s db_category=%s db_message_sha256=%s'
                        . ' outbox_class=%s outbox_category=%s outbox_message_sha256=%s',
                        hash('sha256', (string)$payload[UsageRecord::schema_fields_REQUEST_ID]),
                        $databaseSignal['class'],
                        $databaseSignal['category'],
                        $databaseSignal['message_sha256'],
                        $outboxSignal['class'],
                        $outboxSignal['category'],
                        $outboxSignal['message_sha256'],
                    ),
                    'CRITICAL',
                    true,
                    true,
                    0,
                );
            }

            return false;
        }
    }

    /**
     * @return array{recovered:int,failed:int,dead:int,skipped:int}
     */
    public function recoverDeferredUsage(int $limit = 50): array
    {
        $result = $this->usageAuditOutbox->recover(
            function (array $payload): void {
                $this->persistUsagePayload($payload);
            },
            $limit,
        );
        if ($result['recovered'] > 0 || $result['failed'] > 0 || $result['dead'] > 0) {
            Env::log(
                'ai_usage_audit_recovery.log',
                sprintf(
                    '[recovery] recovered=%d failed=%d dead=%d skipped=%d',
                    $result['recovered'],
                    $result['failed'],
                    $result['dead'],
                    $result['skipped'],
                ),
                $result['failed'] > 0 ? 'ERROR' : 'INFO',
                true,
                true,
                0,
            );
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function buildUsagePayload(
        Account $account,
        AiModel $model,
        array $usage,
        array $context,
    ): array {
        $promptTokens = max(0, (int)($usage['prompt_tokens'] ?? 0));
        $completionTokens = max(0, (int)($usage['completion_tokens'] ?? 0));
        $totalTokens = max(
            $promptTokens + $completionTokens,
            (int)($usage['total_tokens'] ?? 0),
        );
        $inputPrice = (float)$model->getData(AiModel::schema_fields_TOKEN_PRICE_INPUT);
        $outputPrice = (float)$model->getData(AiModel::schema_fields_TOKEN_PRICE_OUTPUT);
        $inputCost = ($promptTokens / 1000) * $inputPrice;
        $outputCost = ($completionTokens / 1000) * $outputPrice;
        $status = (string)($context['status'] ?? 'success');
        if (!in_array($status, ['success', 'failed'], true)) {
            throw new \InvalidArgumentException('AI provider usage status is invalid.');
        }

        return [
            UsageRecord::schema_fields_ACCOUNT_ID => $account->getId(),
            UsageRecord::schema_fields_PROVIDER_CODE => $account->getData(Account::schema_fields_PROVIDER_CODE),
            UsageRecord::schema_fields_MODEL_CODE => $model->getData(AiModel::schema_fields_MODEL_CODE),
            UsageRecord::schema_fields_MODEL_NAME => $model->getData(AiModel::schema_fields_NAME),
            UsageRecord::schema_fields_REQUEST_ID => $this->normalizeUsageRequestId($context['request_id'] ?? null),
            UsageRecord::schema_fields_USER_ID => $context['user_id'] ?? null,
            UsageRecord::schema_fields_USER_NAME => $context['user_name'] ?? null,
            UsageRecord::schema_fields_REQUEST_TYPE => $context['request_type'] ?? 'chat',
            UsageRecord::schema_fields_PROMPT_TOKENS => $promptTokens,
            UsageRecord::schema_fields_COMPLETION_TOKENS => $completionTokens,
            UsageRecord::schema_fields_TOTAL_TOKENS => $totalTokens,
            UsageRecord::schema_fields_INPUT_COST => $inputCost,
            UsageRecord::schema_fields_OUTPUT_COST => $outputCost,
            UsageRecord::schema_fields_TOTAL_COST => $inputCost + $outputCost,
            UsageRecord::schema_fields_CURRENCY => $account->getData(Account::schema_fields_CURRENCY),
            UsageRecord::schema_fields_REQUEST_TIME => $context['request_time'] ?? null,
            UsageRecord::schema_fields_STATUS => $status,
            UsageRecord::schema_fields_ERROR_MESSAGE => $status === 'failed' ? 'provider_failed' : null,
            UsageRecord::schema_fields_CREATED_AT => (int)($context['created_at'] ?? time()),
        ];
    }

    protected function persistUsagePayload(array $payload): UsageRecord
    {
        $requestId = $this->normalizeUsageRequestId(
            $payload[UsageRecord::schema_fields_REQUEST_ID] ?? null,
        );
        $payload[UsageRecord::schema_fields_REQUEST_ID] = $requestId;
        $payload[UsageRecord::schema_fields_REQUEST_KEY] = hash('sha256', $requestId);
        $payload[UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS] =
            UsageRecord::REQUEST_IDENTITY_CANONICAL;
        $existing = $this->findUsageRecordByRequestId($requestId);
        if ($existing !== null) {
            $this->assertExistingUsageMatches($existing, $payload);

            return $this->reconcileExistingUsageRecord($existing, $payload);
        }

        try {
            return $this->insertUsageRecordAndDebit($payload);
        } catch (\Throwable $failure) {
            // A concurrent writer may have won the UNIQUE request_key race.
            // Re-read only for reconciliation; preserve the original busy or
            // storage failure when no canonical row exists.
            try {
                $existing = $this->findUsageRecordByRequestId($requestId);
            } catch (\Throwable) {
                $existing = null;
            }
            if ($existing === null) {
                throw $failure;
            }
            $this->assertExistingUsageMatches($existing, $payload);

            return $this->reconcileExistingUsageRecord($existing, $payload);
        }
    }

    protected function findUsageRecordByRequestId(string $requestId): ?UsageRecord
    {
        /** @var UsageRecord $record */
        $record = $this->objectManager->make(UsageRecord::class);
        $record = $record->clear()
            ->where(UsageRecord::schema_fields_REQUEST_KEY, hash('sha256', $requestId))
            ->find()
            ->fetch();

        if (!$record->getId()) {
            return null;
        }
        $this->ensureUsageIdentityIsReplayable($record);

        return $record;
    }

    protected function ensureUsageIdentityIsReplayable(UsageRecord $record): void
    {
        if (
            (string)$record->getData(UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS)
            === UsageRecord::REQUEST_IDENTITY_LEGACY_CONFLICT
        ) {
            throw new \RuntimeException(
                'AI provider usage request_id has a quarantined historical conflict.',
            );
        }
    }

    protected function insertUsageRecordAndDebit(array $payload): UsageRecord
    {
        /** @var UsageRecord $transactionModel */
        $transactionModel = $this->objectManager->make(UsageRecord::class);
        $transactionQuery = $transactionModel->getQuery(false);
        if ($transactionQuery instanceof WriteIntentQueryInterface) {
            $transactionQuery->beginWriteTransaction();
        } else {
            $transactionQuery->beginTransaction();
        }

        try {
            $requestId = (string)$payload[UsageRecord::schema_fields_REQUEST_ID];
            $existing = $this->findUsageRecordByRequestId($requestId);
            if ($existing !== null) {
                $this->assertExistingUsageMatches($existing, $payload);
                $settled = $this->applyBalanceWithinTransaction($existing, $payload);
                $transactionQuery->commit();

                return $settled
                    ? $existing
                    : $this->requireSettledUsageRecord($requestId);
            }

            /** @var UsageRecord $record */
            $record = $this->objectManager->make(UsageRecord::class);
            $record->setData($payload);
            $record->setData(
                UsageRecord::schema_fields_BALANCE_APPLIED,
                ($payload[UsageRecord::schema_fields_STATUS] ?? '') === 'success'
                    ? self::BALANCE_PENDING
                    : self::BALANCE_APPLIED,
            );
            $record->save();
            $settled = $this->applyBalanceWithinTransaction($record, $payload);
            $transactionQuery->commit();

            return $settled
                ? $record
                : $this->requireSettledUsageRecord($requestId);
        } catch (\Throwable $failure) {
            try {
                $transactionQuery->rollBack();
            } catch (\Throwable) {
                // Preserve the original persistence failure.
            }
            throw $failure;
        }
    }

    protected function reconcileExistingUsageRecord(UsageRecord $record, array $payload): UsageRecord
    {
        if (
            ($payload[UsageRecord::schema_fields_STATUS] ?? '') !== 'success'
            || (int)$record->getData(UsageRecord::schema_fields_BALANCE_APPLIED) === self::BALANCE_APPLIED
        ) {
            return $record;
        }

        /** @var UsageRecord $transactionModel */
        $transactionModel = $this->objectManager->make(UsageRecord::class);
        $transactionQuery = $transactionModel->getQuery(false);
        if ($transactionQuery instanceof WriteIntentQueryInterface) {
            $transactionQuery->beginWriteTransaction();
        } else {
            $transactionQuery->beginTransaction();
        }
        $fresh = null;
        $settled = false;
        try {
            $fresh = $this->findUsageRecordByRequestId(
                (string)$payload[UsageRecord::schema_fields_REQUEST_ID],
            );
            if ($fresh === null) {
                throw new \RuntimeException('AI provider usage record disappeared during reconciliation.');
            }
            $this->assertExistingUsageMatches($fresh, $payload);
            $settled = $this->applyBalanceWithinTransaction($fresh, $payload);
            $transactionQuery->commit();
        } catch (\Throwable $failure) {
            try {
                $transactionQuery->rollBack();
            } catch (\Throwable) {
                // Preserve the original reconciliation failure.
            }
            throw $failure;
        }
        if (!$settled) {
            return $this->requireSettledUsageRecord(
                (string)$payload[UsageRecord::schema_fields_REQUEST_ID],
            );
        }

        return $fresh;
    }

    protected function applyBalanceWithinTransaction(UsageRecord $record, array $payload): bool
    {
        if (($payload[UsageRecord::schema_fields_STATUS] ?? '') !== 'success') {
            return true;
        }
        $balanceState = (int)$record->getData(UsageRecord::schema_fields_BALANCE_APPLIED);
        if ($balanceState === self::BALANCE_APPLIED) {
            return true;
        }
        if ($balanceState !== self::BALANCE_PENDING) {
            throw new \RuntimeException('AI provider usage balance claim is incomplete.');
        }
        if (!$this->claimBalanceWithinTransaction($record)) {
            return false;
        }

        if (!$this->atomicAccountBalanceDebitWithinTransaction(
            (int)$payload[UsageRecord::schema_fields_ACCOUNT_ID],
            (float)$payload[UsageRecord::schema_fields_TOTAL_COST],
        )) {
            throw new \RuntimeException('AI provider usage account is unavailable during balance debit.');
        }
        if (!$this->markBalanceAppliedWithinTransaction($record)) {
            throw new \RuntimeException('AI provider usage balance claim finalization failed.');
        }

        return true;
    }

    protected function atomicAccountBalanceDebitWithinTransaction(int $accountId, float $amount): bool
    {
        if ($accountId < 1 || !is_finite($amount)) {
            throw new \RuntimeException('AI provider usage account debit is invalid.');
        }

        /** @var Account $account */
        $account = $this->objectManager->make(Account::class);
        $accountQuery = $account->getQuery(false);
        $accountQuery->where(Account::schema_fields_ID, $accountId);
        if ($amount >= 0) {
            $accountQuery
                ->dec(Account::schema_fields_BALANCE, $amount)
                ->inc(Account::schema_fields_TOTAL_SPENT, $amount);
        } else {
            // Match Account::updateBalance() for negative adjustments without
            // generating invalid "--N" SQL in Query::dec().
            $absoluteAmount = abs($amount);
            $accountQuery
                ->inc(Account::schema_fields_BALANCE, $absoluteAmount)
                ->dec(Account::schema_fields_TOTAL_SPENT, $absoluteAmount);
        }
        $accountQuery->update();
        $statement = $accountQuery->PDOStatement ?? null;
        $bindings = $accountQuery->bound_values ?? null;
        if (!$statement instanceof \PDOStatement || !is_array($bindings)) {
            $accountQuery->clearQuery();
            throw new \RuntimeException('AI provider usage account debit statement is unavailable.');
        }

        try {
            if (!$statement->execute($bindings)) {
                throw new \RuntimeException('AI provider usage account debit execution failed.');
            }
            $affectedRows = $statement->rowCount();
            if ($affectedRows === 1) {
                return true;
            }
            if ($affectedRows !== 0) {
                throw new \RuntimeException('AI provider usage account debit affected an invalid row count.');
            }
        } finally {
            $accountQuery->clearQuery();
        }

        // MySQL can report zero changed rows when DECIMAL storage rounds a
        // sub-cent debit back to the same values. Distinguish that valid match
        // from a missing account without reverting to a read/modify/write.
        /** @var Account $existing */
        $existing = $this->objectManager->make(Account::class);
        $existing = $existing->clear()
            ->where(Account::schema_fields_ID, $accountId)
            ->find()
            ->fetch();

        return (int)$existing->getId() === $accountId;
    }

    protected function claimBalanceWithinTransaction(UsageRecord $record): bool
    {
        $id = (int)$record->getId();
        $requestKey = (string)$record->getData(UsageRecord::schema_fields_REQUEST_KEY);
        if ($id < 1 || preg_match('/^[a-f0-9]{64}$/', $requestKey) !== 1) {
            throw new \RuntimeException('AI provider usage balance claim identity is invalid.');
        }

        $claimed = $this->atomicBalanceStateUpdate(
            $record,
            self::BALANCE_PENDING,
            self::BALANCE_CLAIMED,
        );
        if ($claimed) {
            $record->setData(UsageRecord::schema_fields_BALANCE_APPLIED, self::BALANCE_CLAIMED);
        }

        return $claimed;
    }

    protected function markBalanceAppliedWithinTransaction(UsageRecord $record): bool
    {
        $applied = $this->atomicBalanceStateUpdate(
            $record,
            self::BALANCE_CLAIMED,
            self::BALANCE_APPLIED,
        );
        if ($applied) {
            $record->setData(UsageRecord::schema_fields_BALANCE_APPLIED, self::BALANCE_APPLIED);
        }

        return $applied;
    }

    protected function atomicBalanceStateUpdate(
        UsageRecord $record,
        int $expectedState,
        int $nextState,
    ): bool {
        /** @var UsageRecord $claim */
        $claim = $this->objectManager->make(UsageRecord::class);
        $claimQuery = $claim->getQuery(false);
        $claimQuery
            ->where(UsageRecord::schema_fields_ID, (int)$record->getId())
            ->where(
                UsageRecord::schema_fields_REQUEST_KEY,
                (string)$record->getData(UsageRecord::schema_fields_REQUEST_KEY),
            )
            ->where(UsageRecord::schema_fields_BALANCE_APPLIED, $expectedState)
            ->update([
                UsageRecord::schema_fields_BALANCE_APPLIED => $nextState,
            ]);
        $statement = $claimQuery->PDOStatement ?? null;
        $bindings = $claimQuery->bound_values ?? null;
        if (!$statement instanceof \PDOStatement || !is_array($bindings)) {
            $claimQuery->clearQuery();
            throw new \RuntimeException('AI provider usage balance claim statement is unavailable.');
        }

        try {
            if (!$statement->execute($bindings)) {
                throw new \RuntimeException('AI provider usage balance claim execution failed.');
            }

            // Model::fetch() returns a model object and SQLite Query::fetch()
            // collapses UPDATE's empty result set to false. The prepared PDO
            // statement is the only portable exact ownership result here.
            return $statement->rowCount() === 1;
        } finally {
            $claimQuery->clearQuery();
        }
    }

    private function requireSettledUsageRecord(string $requestId): UsageRecord
    {
        $record = $this->findUsageRecordByRequestId($requestId);
        if (
            $record === null
            || (int)$record->getData(UsageRecord::schema_fields_BALANCE_APPLIED) !== self::BALANCE_APPLIED
        ) {
            throw new \RuntimeException('AI provider usage balance claim is still active.');
        }

        return $record;
    }

    private function assertExistingUsageMatches(UsageRecord $record, array $payload): void
    {
        foreach ([
            UsageRecord::schema_fields_ACCOUNT_ID,
            UsageRecord::schema_fields_PROVIDER_CODE,
            UsageRecord::schema_fields_MODEL_CODE,
            UsageRecord::schema_fields_REQUEST_TYPE,
            UsageRecord::schema_fields_PROMPT_TOKENS,
            UsageRecord::schema_fields_COMPLETION_TOKENS,
            UsageRecord::schema_fields_TOTAL_TOKENS,
            UsageRecord::schema_fields_CURRENCY,
            UsageRecord::schema_fields_STATUS,
        ] as $field) {
            if ((string)$record->getData($field) !== (string)($payload[$field] ?? '')) {
                throw new \RuntimeException('AI provider usage request_id payload conflict.');
            }
        }
        foreach ([
            UsageRecord::schema_fields_INPUT_COST,
            UsageRecord::schema_fields_OUTPUT_COST,
            UsageRecord::schema_fields_TOTAL_COST,
        ] as $field) {
            if (abs((float)$record->getData($field) - (float)($payload[$field] ?? 0)) > 0.0000005) {
                throw new \RuntimeException('AI provider usage request_id cost conflict.');
            }
        }
    }

    /** @return array{class:string,category:string,message_sha256:string} */
    private function auditFailureSignal(\Throwable $failure): array
    {
        $message = strtolower($failure->getMessage());
        $category = match (true) {
            str_contains($message, 'database is locked'),
            str_contains($message, 'database table is locked'),
            str_contains($message, 'lock wait timeout'),
            str_contains($message, 'deadlock') => 'transient_lock',
            str_contains($message, 'unique constraint'),
            str_contains($message, 'duplicate entry'),
            str_contains($message, 'payload conflict') => 'identity_conflict',
            str_contains($message, 'permission denied'),
            str_contains($message, 'read-only'),
            str_contains($message, 'readonly') => 'storage_permission',
            default => 'unexpected',
        };

        return [
            'class' => $failure::class,
            'category' => $category,
            'message_sha256' => hash('sha256', $failure->getMessage()),
        ];
    }

    private function normalizeUsageRequestId(mixed $requestId): string
    {
        $requestId = trim((string)$requestId);
        if ($requestId === '') {
            return 'req_' . bin2hex(random_bytes(16));
        }
        if (strlen($requestId) > 100 || preg_match('/[\x00-\x1F\x7F]/', $requestId) === 1) {
            return 'req_' . hash('sha256', $requestId);
        }

        return $requestId;
    }

    /**
     * 获取Provider实例
     * 
     * @param string $providerCode
     * @return ProviderInterface|null
     */
    public function getProviderInstance(string $providerCode): ?ProviderInterface
    {
        $providerCode = strtolower(trim($providerCode));
        $this->lastProviderInstanceError = '';
        try {
            // 根据供应商代码返回对应的Provider实例
            $providerClass = match ($providerCode) {
                'anthropic' => AnthropicProvider::class,
                'google' => GeminiProvider::class,
                'vectorengine' => VectorEngineProvider::class,
                'openai', 'deepseek' => OpenAiProvider::class,
                default => OpenAiProvider::class, // 默认使用OpenAI兼容的Provider
            };
            
            $provider = $this->objectManager->make($providerClass);
            if (!$provider instanceof ProviderInterface) {
                $this->lastProviderInstanceError = '返回的对象不是ProviderInterface实例';
                Env::log('ai_provider_test.log', sprintf('[getProviderInstance][error] provider=%s error=返回的对象不是ProviderInterface实例', $providerCode));
                return null;
            }
            return $provider;
        } catch (\Exception $e) {
            $this->lastProviderInstanceError = $e->getMessage();
            Env::log('ai_provider_test.log', sprintf('[getProviderInstance][error] provider=%s error=%s trace=%s', 
                $providerCode, 
                $e->getMessage(), 
                $e->getTraceAsString()
            ));
            return null;
        } catch (\Throwable $e) {
            $this->lastProviderInstanceError = $e->getMessage();
            Env::log('ai_provider_test.log', sprintf('[getProviderInstance][fatal] provider=%s error=%s trace=%s', 
                $providerCode, 
                $e->getMessage(), 
                $e->getTraceAsString()
            ));
            return null;
        }
    }

    /**
     * Ensure provider account tests do not depend on a manual model sync first.
     */
    private function ensureProviderTestModel(string $providerCode, string $testModelCode, array $providerInfo): void
    {
        if ($providerCode === '' || $testModelCode === '') {
            return;
        }

        /** @var AiModel $existing */
        $existing = $this->objectManager->make(AiModel::class)
            ->reset()
            ->where(AiModel::schema_fields_MODEL_CODE, $testModelCode)
            ->find()
            ->fetch();
        if ($existing && $existing->getId()) {
            return;
        }

        $modelMeta = $this->findProviderModelMeta($providerInfo, $testModelCode);
        $defaults = is_array($providerInfo['model_config_defaults'] ?? null) ? $providerInfo['model_config_defaults'] : [];
        $modelField = (string)($providerInfo['model_field'] ?? 'model');
        $baseUrl = (string)($providerInfo['base_url'] ?? '');
        $config = array_merge([
            'api_key' => '',
            'base_url' => $baseUrl,
            'max_tokens' => $modelMeta['max_tokens'] ?? ($defaults['max_tokens'] ?? 4096),
            'temperature' => $defaults['temperature'] ?? 0.7,
            'top_p' => $defaults['top_p'] ?? 1.0,
            'stream' => $defaults['stream'] ?? true,
            'timeout' => $defaults['timeout'] ?? 180,
            'max_retries' => $defaults['max_retries'] ?? 3,
        ], is_array($defaults['extra'] ?? null) ? $defaults['extra'] : []);
        $config[$modelField] = $testModelCode;
        $config['model'] = $config['model'] ?? $testModelCode;
        $config['model_id'] = $config['model_id'] ?? $testModelCode;

        /** @var ModelCollector $collector */
        $collector = $this->objectManager->make(ModelCollector::class);
        $result = $collector->registerModelFromArray([
            'vendor' => $providerCode,
            'model_code' => $testModelCode,
            'model_name' => (string)($modelMeta['name'] ?? $testModelCode),
            'model_version' => (string)($modelMeta['version'] ?? '1.0'),
            'token_price_input' => (float)($modelMeta['input_price'] ?? 0),
            'token_price_output' => (float)($modelMeta['output_price'] ?? 0),
            'max_tokens' => (int)($modelMeta['max_tokens'] ?? ($defaults['max_tokens'] ?? 4096)),
            'primary_modality' => (string)($modelMeta['primary_modality'] ?? AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT),
            'is_active' => 1,
            'is_default' => (int)($modelMeta['is_default'] ?? 0) === 1 ? 1 : 0,
            'capabilities' => is_array($modelMeta['capabilities'] ?? null) ? $modelMeta['capabilities'] : ($defaults['capabilities'] ?? ['chat']),
            'config' => $config,
        ]);

        if (empty($result['model'])) {
            throw new Exception(__('无法自动创建供应商测试模型：%{1}', [$testModelCode]));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function findProviderModelMeta(array $providerInfo, string $modelCode): array
    {
        foreach (($providerInfo['models'] ?? []) as $modelMeta) {
            if (!is_array($modelMeta)) {
                continue;
            }
            if ((string)($modelMeta['code'] ?? $modelMeta['id'] ?? '') === $modelCode) {
                return $modelMeta;
            }
        }

        return [
            'code' => $modelCode,
            'name' => $modelCode,
            'primary_modality' => $this->inferPrimaryModalityFromModelCode($modelCode),
            'capabilities' => ['chat'],
            'max_tokens' => 4096,
        ];
    }

    /**
     * @param array<string,mixed> $modelMeta
     * @param array<string,mixed> $options
     */
    private function resolveTestPrimaryModality(string $modelCode, array $modelMeta, array $options): string
    {
        $requested = trim((string)($options['primary_modality'] ?? ''));
        if ($requested !== '') {
            return AiModel::normalizePrimaryModality($requested);
        }

        $metaModality = trim((string)($modelMeta['primary_modality'] ?? ''));
        if ($metaModality !== '') {
            return AiModel::normalizePrimaryModality($metaModality);
        }

        return $this->inferPrimaryModalityFromModelCode($modelCode);
    }

    private function inferPrimaryModalityFromModelCode(string $modelCode): string
    {
        $code = strtolower(trim($modelCode));
        foreach (['vision', 'multimodal', 'multi-modal', 'image-to-text', 'image2text', '-vl', '_vl', '/vl', 'qwen-vl', 'qwen2-vl', 'qwen2.5-vl', 'glm-4v', 'gpt-4o', 'gpt-4.1', 'claude-3', 'omni'] as $needle) {
            if (str_contains($code, $needle)) {
                return AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT;
            }
        }

        foreach (['embedding', 'embed', 'bge-', 'gte-', 'e5-', 'rerank', 'vector'] as $needle) {
            if (str_contains($code, $needle)) {
                return AiModel::PRIMARY_MODALITY_EMBEDDING;
            }
        }

        foreach (['image', 'img', 'dall-e', 'gpt-image', 'imagen', 'flux', 'stable-diffusion', 'sdxl', 'seedream', 'jimeng', 'nano-banana'] as $needle) {
            if (str_contains($code, $needle)) {
                return AiModel::PRIMARY_MODALITY_TEXT_TO_IMAGE;
            }
        }

        foreach (['video', 'wan-', 'veo', 'sora', 'kling'] as $needle) {
            if (str_contains($code, $needle)) {
                return AiModel::PRIMARY_MODALITY_TEXT_TO_VIDEO;
            }
        }

        return AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT;
    }

    /**
     * 设置默认账户
     * 
     * @param Account $account
     * @return void
     */
    public function setDefaultAccount(Account $account): void
    {
        $providerCode = $account->getData(Account::schema_fields_PROVIDER_CODE);
        
        // 先取消该供应商的所有默认账户（使用批量更新避免迭代对象类型不一致）
        /** @var Account $accountModel */
        $accountModel = $this->objectManager->make(Account::class);
        $accountModel->reset()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(Account::schema_fields_IS_DEFAULT, 1)
            ->update([Account::schema_fields_IS_DEFAULT => 0])
            ->fetch();
        
        // 设置新的默认账户
        $account->setData(Account::schema_fields_IS_DEFAULT, 1)->save();
    }

    /**
     * 获取供应商的所有账户
     * 
     * @param string $providerCode
     * @return array
     */
    public function getProviderAccounts(string $providerCode): array
    {
        /** @var Account $accountModel */
        $accountModel = $this->objectManager->make(Account::class);
        
        $rows = $accountModel->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->order(Account::schema_fields_IS_DEFAULT, 'DESC')
            ->order(Account::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetchArray();
        return is_array($rows) ? $rows : [];
    }

    /**
     * 获取同步模型列表用的可用账户（仅要求激活）
     *
     * @param string $providerCode
     * @return Account|null
     */
    public function getActiveAccountForSync(string $providerCode): ?Account
    {
        /** @var Account $accountModel */
        $accountModel = $this->objectManager->make(Account::class);
        $account = $accountModel->reset()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(Account::schema_fields_IS_ACTIVE, 1)
            ->order(Account::schema_fields_IS_DEFAULT, 'DESC')
            ->order(Account::schema_fields_CREATED_AT, 'DESC')
            ->find()
            ->fetch();

        return $account && $account->getId() ? $account : null;
    }

    /**
     * 获取指定供应商支持的模型列表
     * 
     * @param string $providerCode
     * @return array
     */
    public function supportsModel(string $providerCode, string $modelCode): bool
    {
        $providerCode = strtolower(trim($providerCode));
        $modelCode = trim($modelCode);

        if ($providerCode === '' || $modelCode === '') {
            return false;
        }

        if (!VendorConfigManager::isProviderSupported($providerCode)) {
            return false;
        }

        // 本地/自定义 OpenAI 兼容供应商：允许手工登记任意模型代码
        if (VendorConfigManager::isCustomProvider($providerCode)) {
            return true;
        }

        $models = $this->getProviderModels($providerCode);
        foreach ($models as $model) {
            if (is_string($model) && trim($model) === $modelCode) {
                return true;
            }

            if (!is_array($model)) {
                continue;
            }

            $candidates = [
                $model['code'] ?? null,
                $model['id'] ?? null,
                $model['model'] ?? null,
                $model['name'] ?? null,
            ];

            foreach ($candidates as $candidate) {
                if (is_string($candidate) && trim($candidate) === $modelCode) {
                    return true;
                }
            }
        }

        $testModel = VendorConfigManager::getTestModel($providerCode);
        if (is_string($testModel) && trim($testModel) === $modelCode) {
            return true;
        }

        if (VendorConfigManager::isModelFromProvider($modelCode, $providerCode)) {
            return true;
        }
        return $this->supportsRemoteModel($providerCode, $modelCode);
    }

    public function getProviderModels(string $providerCode): array
    {
        return VendorConfigManager::getProviderModels($providerCode);
    }

    private function supportsRemoteModel(string $providerCode, string $modelCode): bool
    {
        $provider = $this->getProviderInstance($providerCode);
        if (!$provider instanceof ModelListingProviderInterface || !$provider->supportsModelsApi()) {
            return false;
        }

        $config = VendorConfigManager::getProviderConfig($providerCode);
        if (!$config) {
            return false;
        }

        $modelsApi = $config['models_api'] ?? [];
        if (!is_array($modelsApi) || empty($modelsApi['path'])) {
            return false;
        }

        $account = $this->getAvailableAccount($providerCode);
        if (!$account || !$account->getId()) {
            $account = $this->getActiveAccountForSync($providerCode);
        }
        if (!$account || !$account->getId()) {
            return false;
        }

        $apiKey = trim((string)$account->getDecryptedApiKey());
        if ($apiKey === '') {
            return false;
        }

        $baseUrl = (string)($account->getData(Account::schema_fields_BASE_URL) ?: ($config['base_url'] ?? ''));
        $modelConfig = array_replace($config, [
            'provider_code' => $providerCode,
            'api_key' => $apiKey,
            'base_url' => $baseUrl,
            'models_api' => $modelsApi,
        ]);

        try {
            $models = $provider->listRemoteModels($modelConfig, [
                'provider_code' => $providerCode,
                'models_api' => $modelsApi,
            ]);
        } catch (\Throwable) {
            return false;
        }

        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }
            foreach (['code', 'value', 'id', 'model', 'name'] as $field) {
                $candidate = $model[$field] ?? null;
                if (is_string($candidate) && trim($candidate) === $modelCode) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 获取所有供应商及其支持的模型列表
     * 
     * @return array
     */
    public function getAllProvidersWithModels(): array
    {
        return VendorConfigManager::getAllProvidersWithModels();
    }
}
