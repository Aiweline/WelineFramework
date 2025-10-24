<?php

declare(strict_types=1);

namespace Weline\Ai\Setup;

use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiApiKey;
use Weline\Ai\Model\AiTenant;
use Weline\Framework\Manager\ObjectManager;

/**
 * Seed test data for Weline_Ai module
 */
class Seed
{
    /**
     * Seed test data
     *
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function seed(ModelSetup $setup, Context $context): void
    {
        echo "开始创建测试数据...\n";
        
        try {
            // Create test models
            $this->createModels();
            
            // Create test tenants
            $this->createTenants();
            
            // Create test API keys
            $this->createApiKeys();
            
            echo "✅ 测试数据创建成功！\n";
            
        } catch (\Exception $e) {
            echo "❌ 创建测试数据失败: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
    
    /**
     * Create test models
     */
    private function createModels(): void
    {
        echo "创建测试模型...\n";
        
        $models = [
            [
                'supplier' => 'openai',
                'model_code' => 'gpt-3.5-turbo',
                'name' => 'GPT-3.5 Turbo',
                'version' => '0301',
                'is_copy' => false,
                'origin_model_id' => null,
                'config' => json_encode([
                    'temperature' => 0.7,
                    'top_p' => 1.0,
                    'frequency_penalty' => 0.0,
                    'presence_penalty' => 0.0
                ]),
                'capabilities' => json_encode(['chat', 'completion']),
                'max_tokens' => 4096,
                'cost_per_token' => 0.000002,
                'status' => AiModel::STATUS_ACTIVE,
            ],
            [
                'supplier' => 'openai',
                'model_code' => 'gpt-4',
                'name' => 'GPT-4',
                'version' => '0613',
                'is_copy' => false,
                'origin_model_id' => null,
                'config' => json_encode([
                    'temperature' => 0.7,
                    'top_p' => 1.0,
                    'frequency_penalty' => 0.0,
                    'presence_penalty' => 0.0
                ]),
                'capabilities' => json_encode(['chat', 'completion', 'vision']),
                'max_tokens' => 8192,
                'cost_per_token' => 0.00003,
                'status' => AiModel::STATUS_ACTIVE,
            ],
            [
                'supplier' => 'anthropic',
                'model_code' => 'claude-3-sonnet',
                'name' => 'Claude 3 Sonnet',
                'version' => '20240229',
                'is_copy' => false,
                'origin_model_id' => null,
                'config' => json_encode([
                    'temperature' => 1.0,
                    'top_p' => 1.0,
                    'top_k' => 0
                ]),
                'capabilities' => json_encode(['chat', 'completion', 'vision']),
                'max_tokens' => 200000,
                'cost_per_token' => 0.000015,
                'status' => AiModel::STATUS_ACTIVE,
            ],
            [
                'supplier' => 'deepseek',
                'model_code' => 'deepseek-v3.1',
                'name' => 'DeepSeek-V3.1',
                'version' => '20250821',
                'is_copy' => false,
                'origin_model_id' => null,
                'config' => json_encode([
                    'temperature' => 0.7,
                    'top_p' => 1.0,
                    'frequency_penalty' => 0.0,
                    'presence_penalty' => 0.0,
                    'max_tokens' => 64000
                ]),
                'capabilities' => json_encode(['chat', 'completion', 'reasoning', 'coding']),
                'max_tokens' => 64000,
                'cost_per_token' => 0.000001,
                'status' => AiModel::STATUS_ACTIVE,
            ],
            [
                'supplier' => 'deepseek',
                'model_code' => 'deepseek-r1-0528',
                'name' => 'DeepSeek-R1 Reasoning',
                'version' => '20250529',
                'is_copy' => false,
                'origin_model_id' => null,
                'config' => json_encode([
                    'temperature' => 0.7,
                    'top_p' => 1.0,
                    'frequency_penalty' => 0.0,
                    'presence_penalty' => 0.0,
                    'max_tokens' => 32000
                ]),
                'capabilities' => json_encode(['chat', 'reasoning', 'chain-of-thought', 'coding']),
                'max_tokens' => 32000,
                'cost_per_token' => 0.0000008,
                'status' => AiModel::STATUS_ACTIVE,
            ],
        ];
        
        foreach ($models as $modelData) {
            $model = ObjectManager::getInstance(AiModel::class);
            $model->setData($modelData);
            $model->save();
            echo "  ✓ 创建模型: {$modelData['name']}\n";
        }
    }
    
    /**
     * Create test tenants
     */
    private function createTenants(): void
    {
        echo "创建测试租户...\n";
        
        $tenants = [
            [
                'name' => 'Default Tenant',
                'domain' => 'default.local',
                'config' => json_encode([
                    'allowed_models' => ['gpt-3.5-turbo', 'gpt-4', 'claude-3-sonnet'],
                    'quota_daily' => 10000,
                    'quota_monthly' => 300000
                ]),
                'status' => 'active',
            ],
            [
                'name' => 'Test Tenant',
                'domain' => 'test.local',
                'config' => json_encode([
                    'allowed_models' => ['gpt-3.5-turbo'],
                    'quota_daily' => 1000,
                    'quota_monthly' => 30000
                ]),
                'status' => 'active',
            ],
        ];
        
        foreach ($tenants as $tenantData) {
            $tenant = ObjectManager::getInstance(AiTenant::class);
            $tenant->setData($tenantData);
            $tenant->save();
            echo "  ✓ 创建租户: {$tenantData['name']}\n";
        }
    }
    
    /**
     * Create test API keys
     */
    private function createApiKeys(): void
    {
        echo "创建测试API密钥...\n";
        
        $apiKeys = [
            [
                'name' => 'Test API Key',
                'token' => 'test_' . bin2hex(random_bytes(32)),
                'user_id' => 1,
                'tenant_id' => 1,
                'status' => AiApiKey::STATUS_APPROVED,
                'quota_daily' => 1000,
                'quota_monthly' => 30000,
                'usage_daily' => 0,
                'usage_monthly' => 0,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+1 year')),
            ],
        ];
        
        foreach ($apiKeys as $keyData) {
            $apiKey = ObjectManager::getInstance(AiApiKey::class);
            $apiKey->setData($keyData);
            $apiKey->save();
            echo "  ✓ 创建API密钥: {$keyData['name']}\n";
            echo "    Token: {$keyData['token']}\n";
        }
    }
}

