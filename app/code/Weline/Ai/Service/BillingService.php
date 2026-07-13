<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Api\Billing\BillingAccountProviderInterface;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiApiKey;
use Weline\Ai\Model\AiApiCallLog;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Runtime\RuntimeProviderResolver;

/**
 * 计费服务
 */
class BillingService
{
    private ConnectionFactory $connectionFactory;

    private RuntimeProviderResolver $runtimeProviderResolver;

    private ?BillingAccountProviderInterface $billingAccountProvider = null;

    public function __construct(
        ConnectionFactory $connectionFactory,
        RuntimeProviderResolver $runtimeProviderResolver,
    ) {
        $this->connectionFactory = $connectionFactory;
        $this->runtimeProviderResolver = $runtimeProviderResolver;
    }
    
    /**
     * 计算API调用费用
     *
     * @param string $modelCode 模型代码
     * @param int $promptTokens 输入Token数
     * @param int $completionTokens 输出Token数
     * @return array
     * @throws \Exception
     */
    public function calculateCost(
        string $modelCode,
        int $promptTokens,
        int $completionTokens
    ): array {
        /** @var AiModel $model */
        $model = ObjectManager::getInstance(AiModel::class);
        $model = $model->where('code', $modelCode)
            ->orWhere('name', 'like', "%{$modelCode}%")
            ->find()->fetch();
        
        if (!$model || !$model->getId()) {
            throw new \Exception(__('模型不存在：%{1}', $modelCode));
        }
        
        // 获取模型价格（每1000个token的价格）
        $costPerToken = (float)$model->getData('cost_per_token');
        
        // 计算费用
        $promptCost = ($promptTokens / 1000) * $costPerToken;
        $completionCost = ($completionTokens / 1000) * $costPerToken;
        $totalCost = $promptCost + $completionCost;
        
        return [
            'model_id' => $model->getId(),
            'prompt_cost' => round($promptCost, 6),
            'completion_cost' => round($completionCost, 6),
            'total_cost' => round($totalCost, 6),
        ];
    }
    
    /**
     * 扣除用户余额
     *
     * @param int $userId 用户ID
     * @param float $amount 扣除金额
     * @param array $callData API调用数据
     * @return bool
     * @throws \Exception
     */
    public function deductBalance(int $userId, float $amount, array $callData = []): bool
    {
        $conn = $this->connectionFactory->getConnector();
        $conn->beginTransaction();

        try {
            $result = $this->getBillingAccountProvider()->debit($userId, $amount);

            if (!$result->accountExists) {
                throw new \Exception(__('用户不存在'));
            }

            if (!$result->debited) {
                throw new \Exception(__('账户余额不足，当前余额：%{1}，需要：%{2}', 
                    number_format($result->balanceBefore, 4),
                    number_format($amount, 4)
                ));
            }

            // 如果提供了调用数据，记录调用日志
            if (!empty($callData)) {
                $this->logApiCall(
                    $userId,
                    $result->balanceBefore,
                    $result->balanceAfter,
                    $callData,
                );
            }

            $conn->commit();
            return true;
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }
    
    /**
     * 记录API调用日志
     *
     * @param int $userId 用户ID
     * @param float $balanceBefore 调用前余额
     * @param float $balanceAfter 调用后余额
     * @param array $callData 调用数据
     * @return void
     */
    private function logApiCall(int $userId, float $balanceBefore, float $balanceAfter, array $callData): void
    {
        /** @var AiApiCallLog $log */
        $log = ObjectManager::getInstance(AiApiCallLog::class);
        $log->setData([
            AiApiCallLog::schema_fields_API_KEY_ID => $callData['api_key_id'] ?? 0,
            AiApiCallLog::schema_fields_USER_ID => $userId,
            AiApiCallLog::schema_fields_REQUEST_ID => $callData['request_id'] ?? uniqid('req_'),
            AiApiCallLog::schema_fields_MODEL_ID => $callData['model_id'] ?? 0,
            AiApiCallLog::schema_fields_MODEL_CODE => $callData['model_code'] ?? '',
            AiApiCallLog::schema_fields_ENDPOINT => $callData['endpoint'] ?? '',
            AiApiCallLog::schema_fields_REQUEST_METHOD => $callData['request_method'] ?? 'POST',
            AiApiCallLog::schema_fields_REQUEST_IP => $callData['request_ip'] ?? '',
            AiApiCallLog::schema_fields_PROMPT_TOKENS => $callData['prompt_tokens'] ?? 0,
            AiApiCallLog::schema_fields_COMPLETION_TOKENS => $callData['completion_tokens'] ?? 0,
            AiApiCallLog::schema_fields_TOTAL_TOKENS => $callData['total_tokens'] ?? 0,
            AiApiCallLog::schema_fields_PROMPT_COST => $callData['prompt_cost'] ?? 0.0,
            AiApiCallLog::schema_fields_COMPLETION_COST => $callData['completion_cost'] ?? 0.0,
            AiApiCallLog::schema_fields_TOTAL_COST => $callData['total_cost'] ?? 0.0,
            AiApiCallLog::schema_fields_BALANCE_BEFORE => $balanceBefore,
            AiApiCallLog::schema_fields_BALANCE_AFTER => $balanceAfter,
            AiApiCallLog::schema_fields_RESPONSE_STATUS => $callData['response_status'] ?? 200,
            AiApiCallLog::schema_fields_RESPONSE_TIME => $callData['response_time'] ?? 0,
            AiApiCallLog::schema_fields_STATUS => $callData['status'] ?? AiApiCallLog::STATUS_SUCCESS,
            AiApiCallLog::schema_fields_ERROR_MESSAGE => $callData['error_message'] ?? '',
        ]);
        $log->save();
    }
    
    /**
     * 更新API密钥使用量
     *
     * @param int $apiKeyId API密钥ID
     * @param float $cost 本次消费金额
     * @return void
     */
    public function updateApiKeyUsage(int $apiKeyId, float $cost): void
    {
        /** @var AiApiKey $apiKey */
        $apiKey = ObjectManager::getInstance(AiApiKey::class)->load($apiKeyId);
        if (!$apiKey->getId()) {
            return;
        }
        
        // 更新使用量
        $apiKey->setData(AiApiKey::schema_fields_USAGE_DAILY, 
            ((float)($apiKey->getData(AiApiKey::schema_fields_USAGE_DAILY) ?? 0.0)) + $cost
        );
        $apiKey->setData(AiApiKey::schema_fields_USAGE_MONTHLY, 
            ((float)($apiKey->getData(AiApiKey::schema_fields_USAGE_MONTHLY) ?? 0.0)) + $cost
        );
        $apiKey->setData(AiApiKey::schema_fields_CALL_COUNT, 
            ((int)($apiKey->getData(AiApiKey::schema_fields_CALL_COUNT) ?? 0)) + 1
        );
        $apiKey->setData(AiApiKey::schema_fields_LAST_USED_TIME, date('Y-m-d H:i:s'));
        $apiKey->save();
    }
    
    /**
     * 检查API密钥配额
     *
     * @param AiApiKey $apiKey API密钥
     * @param float $estimatedCost 预估费用
     * @return bool
     */
    public function checkQuota(AiApiKey $apiKey, float $estimatedCost = 0.0): bool
    {
        // 检查每日配额
        $quotaDaily = (float)($apiKey->getData(AiApiKey::schema_fields_QUOTA_DAILY) ?? 0.0);
        if ($quotaDaily > 0) {
            $usageDaily = (float)($apiKey->getData(AiApiKey::schema_fields_USAGE_DAILY) ?? 0.0);
            if ($usageDaily + $estimatedCost > $quotaDaily) {
                return false;
            }
        }
        
        // 检查每月配额
        $quotaMonthly = (float)($apiKey->getData(AiApiKey::schema_fields_QUOTA_MONTHLY) ?? 0.0);
        if ($quotaMonthly > 0) {
            $usageMonthly = (float)($apiKey->getData(AiApiKey::schema_fields_USAGE_MONTHLY) ?? 0.0);
            if ($usageMonthly + $estimatedCost > $quotaMonthly) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 检查用户余额
     *
     * @param int $userId 用户ID
     * @param float $requiredAmount 需要的金额
     * @return bool
     */
    public function checkBalance(int $userId, float $requiredAmount = 0.0): bool
    {
        $provider = $this->resolveBillingAccountProvider();
        if (!$provider instanceof BillingAccountProviderInterface) {
            return false;
        }

        return $provider->hasSufficientBalance($userId, $requiredAmount);
    }

    private function getBillingAccountProvider(): BillingAccountProviderInterface
    {
        $provider = $this->resolveBillingAccountProvider();
        if (!$provider instanceof BillingAccountProviderInterface) {
            throw new \RuntimeException(__('计费账户服务不可用'));
        }

        return $provider;
    }

    private function resolveBillingAccountProvider(): ?BillingAccountProviderInterface
    {
        if ($this->billingAccountProvider instanceof BillingAccountProviderInterface) {
            return $this->billingAccountProvider;
        }

        $provider = $this->runtimeProviderResolver->resolve(BillingAccountProviderInterface::class);
        if (!$provider instanceof BillingAccountProviderInterface) {
            return null;
        }

        return $this->billingAccountProvider = $provider;
    }
}
