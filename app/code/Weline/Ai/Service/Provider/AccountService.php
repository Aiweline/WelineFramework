<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ai\Model\Provider\Account;
use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\Provider\ProviderInterface;
use Weline\Ai\Service\Provider\VendorConfigManager;
use Weline\Ai\Service\Provider\OpenAiProvider;
use Weline\Ai\Service\Provider\AnthropicProvider;
use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Provider Account Service
 * 
 * 绠＄悊AI渚涘簲鍟嗚处鎴风殑鏍稿績鏈嶅姟
 * 
 * @package Weline_Ai
 */
class AccountService
{
    /**
     * @var ObjectManager
     */
    private ObjectManager $objectManager;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->objectManager = ObjectManager::getInstance();
    }

    /**
     * 鑾峰彇鏀寔鐨勪緵搴斿晢鍒楄〃
     * 
     * @return array
     */
    public function getSupportedProviders(): array
    {
        return VendorConfigManager::getSupportedProviders();
    }

    /**
     * 鏍规嵁妯″瀷浠ｇ爜鑾峰彇渚涘簲鍟嗕唬鐮?
     * 
     * @param string $modelCode
     * @return string|null
     */
    public function getProviderByModelCode(string $modelCode): ?string
    {
        /** @var AiModel $model */
        $model = $this->objectManager->make(AiModel::class)
            ->reset()
            ->where(AiModel::schema_fields_MODEL_CODE, $modelCode)
            ->find()
            ->fetch();
        if ($model && $model->getId()) {
            $supplier = (string)$model->getData(AiModel::schema_fields_SUPPLIER);
            if ($supplier !== '') {
                return $supplier;
            }
        }
        return VendorConfigManager::getProviderByModelCode($modelCode);
    }

    /**
     * 鏍￠獙渚涘簲鍟嗘槸鍚︽敮鎸佹寚瀹氭ā鍨嬩唬鐮?
     * 浼樺厛浣跨敤 vendor 閰嶇疆涓殑 models 鍒楄〃锛涜嫢鏃犻厤缃垯浠庤处鎴风紦瀛樼殑 supported_models 鍒ゆ柇銆?
     */
    public function supportsModel(string $providerCode, string $modelCode): bool
    {
        $providerConfig = VendorConfigManager::getProviderConfig($providerCode);
        $providerModels = VendorConfigManager::getProviderModels($providerCode);
        if (is_array($providerModels) && !empty($providerModels)) {
            foreach ($providerModels as $item) {
                $code = is_array($item) ? (string)($item['code'] ?? '') : (string)$item;
                if ($code === $modelCode) {
                    return true;
                }
            }
            if (!empty($providerConfig['allow_custom_models']) && VendorConfigManager::isModelFromProvider($modelCode, $providerCode)) {
                return true;
            }
            return false;
        }

        /** @var Account $accountModel */
        $accountModel = $this->objectManager->make(Account::class);
        $accounts = $accountModel->reset()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->select()
            ->fetch()
            ->getItems();
        foreach ($accounts as $account) {
            $cfg = $account->getConfig();
            $supported = $cfg['supported_models'] ?? [];
            if (is_array($supported) && in_array($modelCode, $supported, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * OpenAI 鍏煎妯″紡锛氫粠渚涘簲鍟嗚处鎴风洿鎺ユ媺鍙栨敮鎸佹ā鍨嬶紝骞剁紦瀛樺埌璐︽埛 config.supported_models
     * 杩斿洖鎷夊彇鍒扮殑妯″瀷 code 鍒楄〃銆?
     */
    public function refreshSupportedModels(Account $account): array
    {
        $apiKey = $account->getDecryptedApiKey();
        if ($apiKey === '') {
            return [];
        }
        $baseUrl = (string)$account->getData(Account::schema_fields_BASE_URL);
        if ($baseUrl === '') {
            return [];
        }

        $url = rtrim($baseUrl, '/') . '/models';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $resp = $this->executeCurl($ch);
        if ($resp === false) {
            curl_close($ch);
            return [];
        }
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http < 200 || $http >= 300) {
            return [];
        }

        $json = json_decode($resp, true);
        $rows = $json['data'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $codes = [];
        foreach ($rows as $row) {
            if (is_array($row) && !empty($row['id'])) {
                $codes[] = (string)$row['id'];
            }
        }
        $codes = array_values(array_unique(array_filter($codes)));

        $cfg = $account->getConfig();
        $cfg['supported_models'] = $codes;
        $cfg['models_synced_at'] = time();
        $account->setData(Account::schema_fields_CONFIG, json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $account->setData(Account::schema_fields_UPDATED_AT, time());
        $account->save();

        return $codes;
    }

    private function executeCurl(\CurlHandle $ch): string|bool
    {
        if (!SchedulerSystem::isSchedulerActive() || !\Fiber::getCurrent()) {
            return curl_exec($ch);
        }

        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $ch);

        $running = 0;
        $multiResult = \CURLM_OK;
        $curlResult = \CURLE_OK;

        do {
            do {
                $multiResult = curl_multi_exec($multi, $running);
            } while ($multiResult === \CURLM_CALL_MULTI_PERFORM);

            while ($info = curl_multi_info_read($multi)) {
                if (($info['handle'] ?? null) === $ch) {
                    $curlResult = (int)($info['result'] ?? \CURLE_OK);
                }
            }

            if ($multiResult !== \CURLM_OK || $curlResult !== \CURLE_OK) {
                break;
            }

            if ($running > 0) {
                SchedulerSystem::yieldDelay(10);
            }
        } while ($running > 0);

        $content = curl_multi_getcontent($ch);
        curl_multi_remove_handle($multi, $ch);
        curl_multi_close($multi);

        if ($multiResult !== \CURLM_OK || $curlResult !== \CURLE_OK) {
            return false;
        }

        return $content === null ? false : $content;
    }

    /**
     * 鑾峰彇鎸囧畾渚涘簲鍟嗙殑鍙敤璐︽埛
     * 
     * @param string $providerCode
     * @return Account|null
     */
    public function getAvailableAccount(string $providerCode): ?Account
    {
        /** @var Account $accountModel */
        $accountModel = $this->objectManager->make(Account::class);
        
        // 棣栧厛灏濊瘯鑾峰彇榛樿璐︽埛
        $defaultAccount = $accountModel->clear()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(Account::schema_fields_IS_DEFAULT, 1)
            ->where(Account::schema_fields_IS_ACTIVE, 1)
            ->where(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_SUCCESS)
            ->where(Account::schema_fields_BALANCE, 0, '>')
            ->find()
            ->fetch();
            
        if ($defaultAccount->getId()) {
            return $defaultAccount;
        }
        
        // 濡傛灉娌℃湁榛樿璐︽埛锛岃幏鍙栦换鎰忓彲鐢ㄨ处鎴?
        $availableAccount = $accountModel->clear()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(Account::schema_fields_IS_ACTIVE, 1)
            ->where(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_SUCCESS)
            ->where(Account::schema_fields_BALANCE, 0, '>')
            ->order(Account::schema_fields_BALANCE, 'DESC')
            ->find()
            ->fetch();
            
        return $availableAccount->getId() ? $availableAccount : null;
    }

    /**
     * 娴嬭瘯璐︽埛杩為€氭€?
     * 
     * @param Account $account
     * @return array ['success' => bool, 'message' => string, 'model_code' => string]
     */
    public function testConnection(Account $account): array
    {
        $trace = [];
        $trace[] = '[1/8] 开始连接测试';
        try {
            $providerCode = $account->getData(Account::schema_fields_PROVIDER_CODE);
            $trace[] = '[2/8] 读取供应商配置: ' . (string)$providerCode;
            $providerInfo = VendorConfigManager::getProviderConfig($providerCode);
            if (!$providerInfo) {
                throw new Exception(__('涓嶆敮鎸佺殑渚涘簲鍟? %{provider}', ['provider' => $providerCode]));
            }
            
            $testModelCode = $providerInfo['test_model'];
            $apiKeyPlain = $account->getDecryptedApiKey();
            $apiKeyTail = $apiKeyPlain ? substr($apiKeyPlain, -4) : '';
            $baseUrl = $account->getData(Account::schema_fields_BASE_URL) ?: ($providerInfo['base_url'] ?? '');
            $trace[] = '[3/8] 解析测试参数: model=' . (string)$testModelCode . ', base_url=' . (string)$baseUrl;
            $trace[] = '[4/8] 检查API Key: ' . ($apiKeyPlain !== '' ? ('已配置(尾号:' . $apiKeyTail . ')') : '未配置');
            Env::log('ai_provider_test.log', sprintf('[testConnection] account_id=%s provider=%s model=%s base_url=%s api_key_tail=%s',
                (string)$account->getId(), $providerCode, $testModelCode, $baseUrl, $apiKeyTail
            ));
            
            // 鍒涘缓涓存椂妯″瀷鐢ㄤ簬娴嬭瘯
            /** @var AiModel $testModel */
            $testModel = $this->objectManager->make(AiModel::class);
            $testModel->setData([
                AiModel::schema_fields_SUPPLIER => $providerCode,
                AiModel::schema_fields_MODEL_CODE => $testModelCode,
                AiModel::schema_fields_CONFIG => json_encode([
                    'api_key' => $apiKeyPlain,
                    'base_url' => $baseUrl,
                    'model' => $testModelCode  // 浣跨敤model瀛楁鑰屼笉鏄痬odel_id
                ])
            ]);
            
            // 璁剧疆浠ｇ悊閰嶇疆
            $proxyConfig = $account->getProxyConfig();
            if (!empty($proxyConfig)) {
                $testModel->setData(AiModel::schema_fields_PROXY_INFO, json_encode($proxyConfig));
                $trace[] = '[5/8] 检测到代理配置: host=' . (string)($proxyConfig['host'] ?? '-') . ', port=' . (string)($proxyConfig['port'] ?? '-');
            } else {
                $trace[] = '[5/8] 未启用代理';
            }
            
            // 鑾峰彇瀵瑰簲鐨凱rovider
            $trace[] = '[6/8] 创建Provider实例';
            $provider = $this->getProviderInstance($account->getData(Account::schema_fields_PROVIDER_CODE));
            if (!$provider) {
                throw new Exception('Unable to create provider instance.');
            }
            
            // 鎵ц娴嬭瘯璇锋眰
            $trace[] = '[7/8] 发起API请求（内置重试最多3次）';
            $result = $provider->generate($testModel, 'Please reply with "OK" to indicate the connection is working.', [
                'max_tokens' => 64,
                'temperature' => 0,
                'test_mode' => true
            ]);
            
            if (!empty($result['content']) || !empty($result['reasoning_content'])) {
                // 鏇存柊杩炴帴鐘舵€?
                $account->setData(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_SUCCESS);
                $account->setData(Account::schema_fields_CONNECTION_TEST_TIME, time());
                $account->setData(Account::schema_fields_CONNECTION_TEST_MESSAGE, 'Connection successful');
                $account->setData(Account::schema_fields_UPDATED_AT, time());
                
                // 淇濆瓨骞惰褰曟棩蹇?
                $saveResult = $account->save();
                Env::log('ai_provider_test.log', sprintf('[testConnection][success] account_id=%s provider=%s status=%s save_result=%s', 
                    (string)$account->getId(), 
                    $providerCode, 
                    Account::STATUS_SUCCESS,
                    $saveResult ? 'true' : 'false'
                ));
                
                // 楠岃瘉淇濆瓨鏄惁鎴愬姛
                $account->reset()->load($account->getId());
                $actualStatus = $account->getData(Account::schema_fields_CONNECTION_STATUS);
                if ($actualStatus !== Account::STATUS_SUCCESS) {
                    Env::log('ai_provider_test.log', sprintf('[testConnection][warning] account_id=%s status_not_saved expected=%s actual=%s', 
                        (string)$account->getId(), 
                        Account::STATUS_SUCCESS,
                        $actualStatus
                    ));
                }
                
                $trace[] = '[8/8] 测试成功，连接状态已更新为 success';
                return [
                    'success' => true,
                    'message' => 'Connection test successful',
                    'model_code' => $testModelCode,
                    'account_id' => $account->getId(),
                    'provider' => $providerCode,
                    'base_url' => $baseUrl,
                    'api_key_tail' => $apiKeyTail,
                    'connection_status' => Account::STATUS_SUCCESS,
                    'connection_test_time' => time(),
                    'connection_test_message' => 'Connection successful',
                    'trace' => $trace
                ];
            } else {
                throw new Exception('API returned an empty response.');
            }
            
        } catch (\Exception $e) {
            $trace[] = '[8/8] 测试失败: ' . $e->getMessage();
            Env::log('ai_provider_test.log', sprintf('[testConnection][error] account_id=%s provider=%s error=%s',
                (string)$account->getId(), (string)$account->getData(Account::schema_fields_PROVIDER_CODE), $e->getMessage()
            ));
            // 鏇存柊杩炴帴鐘舵€?
            $account->setData(Account::schema_fields_CONNECTION_STATUS, Account::STATUS_FAILED);
            $account->setData(Account::schema_fields_CONNECTION_TEST_TIME, time());
            $account->setData(Account::schema_fields_CONNECTION_TEST_MESSAGE, $e->getMessage());
            $account->save();
            
            return [
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
                'model_code' => $testModelCode ?? 'unknown',
                'account_id' => $account->getId(),
                'provider' => $account->getData(Account::schema_fields_PROVIDER_CODE),
                'base_url' => $account->getData(Account::schema_fields_BASE_URL) ?: ($providerInfo['base_url'] ?? ''),
                'api_key_tail' => $apiKeyTail ?? '',
                'trace' => $trace
            ];
        }
    }

    /**
     * 璁板綍浣跨敤鎯呭喌
     * 
     * @param Account $account
     * @param AiModel $model
     * @param array $usage
     * @param array $context
     * @return UsageRecord
     */
    public function recordUsage(Account $account, AiModel $model, array $usage, array $context = []): UsageRecord
    {
        /** @var UsageRecord $record */
        $record = $this->objectManager->make(UsageRecord::class);
        
        // 璁＄畻璐圭敤
        $inputPrice = (float)$model->getData(AiModel::schema_fields_TOKEN_PRICE_INPUT);
        $outputPrice = (float)$model->getData(AiModel::schema_fields_TOKEN_PRICE_OUTPUT);
        
        $record->setData([
            UsageRecord::schema_fields_ACCOUNT_ID => $account->getId(),
            UsageRecord::schema_fields_PROVIDER_CODE => $account->getData(Account::schema_fields_PROVIDER_CODE),
            UsageRecord::schema_fields_MODEL_CODE => $model->getData(AiModel::schema_fields_MODEL_CODE),
            UsageRecord::schema_fields_MODEL_NAME => $model->getData(AiModel::schema_fields_NAME),
            UsageRecord::schema_fields_REQUEST_ID => $context['request_id'] ?? uniqid('req_'),
            UsageRecord::schema_fields_USER_ID => $context['user_id'] ?? null,
            UsageRecord::schema_fields_USER_NAME => $context['user_name'] ?? null,
            UsageRecord::schema_fields_REQUEST_TYPE => $context['request_type'] ?? 'chat',
            UsageRecord::schema_fields_PROMPT_TOKENS => $usage['prompt_tokens'] ?? 0,
            UsageRecord::schema_fields_COMPLETION_TOKENS => $usage['completion_tokens'] ?? 0,
            UsageRecord::schema_fields_TOTAL_TOKENS => $usage['total_tokens'] ?? 0,
            UsageRecord::schema_fields_CURRENCY => $account->getData(Account::schema_fields_CURRENCY),
            UsageRecord::schema_fields_REQUEST_TIME => $context['request_time'] ?? null,
            UsageRecord::schema_fields_STATUS => $context['status'] ?? 'success',
            UsageRecord::schema_fields_ERROR_MESSAGE => $context['error_message'] ?? null,
            UsageRecord::schema_fields_CREATED_AT => time()
        ]);
        
        // 璁＄畻璐圭敤
        $record->calculateCost($inputPrice, $outputPrice);
        $record->save();
        
        // 鏇存柊璐︽埛浣欓
        if ($record->getData(UsageRecord::schema_fields_STATUS) === 'success') {
            $account->updateBalance((float)$record->getData(UsageRecord::schema_fields_TOTAL_COST));
            $account->save();
        }
        
        return $record;
    }

    /**
     * 鑾峰彇Provider瀹炰緥
     * 
     * @param string $providerCode
     * @return ProviderInterface|null
     */
    public function getProviderInstance(string $providerCode): ?ProviderInterface
    {
        try {
            // 鏍规嵁渚涘簲鍟嗕唬鐮佽繑鍥炲搴旂殑Provider瀹炰緥
            $providerClass = match ($providerCode) {
                'anthropic' => AnthropicProvider::class,
                'openai', 'deepseek', 'google', 'kimi' => OpenAiProvider::class,
                default => OpenAiProvider::class, // 榛樿浣跨敤OpenAI鍏煎鐨凱rovider
            };
            
            $provider = $this->objectManager->make($providerClass);
            if (!$provider instanceof ProviderInterface) {
                Env::log('ai_provider_test.log', sprintf('[getProviderInstance][error] provider=%s error=杩斿洖鐨勫璞′笉鏄疨roviderInterface瀹炰緥', $providerCode));
                return null;
            }
            return $provider;
        } catch (\Exception $e) {
            Env::log('ai_provider_test.log', sprintf('[getProviderInstance][error] provider=%s error=%s trace=%s', 
                $providerCode, 
                $e->getMessage(), 
                $e->getTraceAsString()
            ));
            return null;
        } catch (\Throwable $e) {
            Env::log('ai_provider_test.log', sprintf('[getProviderInstance][fatal] provider=%s error=%s trace=%s', 
                $providerCode, 
                $e->getMessage(), 
                $e->getTraceAsString()
            ));
            return null;
        }
    }

    /**
     * 璁剧疆榛樿璐︽埛
     * 
     * @param Account $account
     * @return void
     */
    public function setDefaultAccount(Account $account): void
    {
        $providerCode = $account->getData(Account::schema_fields_PROVIDER_CODE);
        
        // 鍏堝彇娑堣渚涘簲鍟嗙殑鎵€鏈夐粯璁よ处鎴凤紙浣跨敤鎵归噺鏇存柊閬垮厤杩唬瀵硅薄绫诲瀷涓嶄竴鑷达級
        /** @var Account $accountModel */
        $accountModel = $this->objectManager->make(Account::class);
        $accountModel->reset()
            ->where(Account::schema_fields_PROVIDER_CODE, $providerCode)
            ->where(Account::schema_fields_IS_DEFAULT, 1)
            ->update([Account::schema_fields_IS_DEFAULT => 0])
            ->fetch();
        
        // 璁剧疆鏂扮殑榛樿璐︽埛
        $account->setData(Account::schema_fields_IS_DEFAULT, 1)->save();
    }

    /**
     * 鑾峰彇渚涘簲鍟嗙殑鎵€鏈夎处鎴?
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
     * 鑾峰彇鍚屾妯″瀷鍒楄〃鐢ㄧ殑鍙敤璐︽埛锛堜粎瑕佹眰婵€娲伙級
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
     * 鑾峰彇鎸囧畾渚涘簲鍟嗘敮鎸佺殑妯″瀷鍒楄〃
     * 
     * @param string $providerCode
     * @return array
     */
    public function getProviderModels(string $providerCode): array
    {
        return VendorConfigManager::getProviderModels($providerCode);
    }

    /**
     * 鑾峰彇鎵€鏈変緵搴斿晢鍙婂叾鏀寔鐨勬ā鍨嬪垪琛?
     * 
     * @return array
     */
    public function getAllProvidersWithModels(): array
    {
        return VendorConfigManager::getAllProvidersWithModels();
    }
}
