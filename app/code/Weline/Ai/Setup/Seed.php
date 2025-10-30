<?php

declare(strict_types=1);

namespace Weline\Ai\Setup;

use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiApiKey;
use Weline\Ai\Model\AiTenant;
use Weline\Ai\Model\AiScenarioAdapter;
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
            // Create default adapter
            $this->createDefaultAdapter();
            
            // Create code generation adapter
            $this->createCodeGenerationAdapter();
            
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
     * Create default adapter
     */
    private function createDefaultAdapter(): void
    {
        echo "创建默认适配器...\n";
        
        // 检查是否已存在默认适配器
        $adapter = ObjectManager::getInstance(AiScenarioAdapter::class);
        $existing = $adapter->reset()->where('code', 'default')->fetchOne();
        
        if ($existing && $existing->getId()) {
            echo "  ⚠ 默认适配器已存在，跳过创建\n";
            return;
        }
        
        $adapterData = [
            'code' => 'default',
            'name' => '默认通用适配器',
            'description' => '默认的通用适配器，不做任何处理，直接传递用户输入和AI响应。适用于所有模型和场景，是最基础、最灵活的适配器。',
            'version' => '1.0.0',
            'class_name' => 'Weline\\Ai\\Adapter\\DefaultAdapter',
            'supported_models' => json_encode(['*']), // 支持所有模型
            'param_template' => json_encode([
                'description' => '默认适配器无需配置，开箱即用',
                'fields' => []
            ]),
            'examples' => json_encode([
                [
                    'title' => '通用对话示例',
                    'description' => '最简单的使用方式，直接发送用户消息',
                    'input' => '你好，请介绍一下你自己。',
                    'expected_output' => 'AI会直接回复，无需任何特殊格式或处理',
                ],
                [
                    'title' => '自由提问',
                    'description' => '用户可以自由提问任何问题',
                    'input' => '如何学习编程？',
                    'expected_output' => 'AI会根据问题给出相应的回答',
                ],
                [
                    'title' => '多轮对话',
                    'description' => '支持连续的多轮对话',
                    'input' => '继续上一个话题...',
                    'expected_output' => 'AI会基于上下文继续对话',
                ],
            ]),
            'is_active' => 1,
            'created_time' => time(),
            'updated_time' => time(),
        ];
        
        $adapter = ObjectManager::getInstance(AiScenarioAdapter::class);
        $adapter->setData($adapterData);
        $adapter->save();
        
        echo "  ✓ 创建默认适配器: {$adapterData['name']}\n";
        echo "    代码: {$adapterData['code']}\n";
        echo "    类名: {$adapterData['class_name']}\n";
    }
    
    /**
     * Create code generation adapter
     */
    private function createCodeGenerationAdapter(): void
    {
        echo "创建代码生成适配器...\n";
        
        // 检查是否已存在
        $adapter = ObjectManager::getInstance(AiScenarioAdapter::class);
        $existing = $adapter->reset()->where('code', 'code_generation')->fetchOne();
        
        if ($existing && $existing->getId()) {
            echo "  ⚠ 代码生成适配器已存在，跳过创建\n";
            return;
        }
        
        $adapterData = [
            'code' => 'code_generation',
            'name' => '代码生成适配器',
            'description' => '专门用于代码生成的场景适配器。自动为提示词添加编程相关的指令，并从响应中提取代码块。支持多种编程语言和代码风格配置。',
            'version' => '1.0.0',
            'class_name' => 'Weline\\Ai\\Adapter\\CodeGenerationAdapter',
            'supported_models' => json_encode(['*']),
            'param_template' => json_encode([
                'description' => '配置代码生成的语言、风格和注释要求',
                'fields' => [
                    [
                        'name' => 'language',
                        'label' => '编程语言',
                        'type' => 'select',
                        'required' => true,
                        'default' => 'Python',
                        'options' => [
                            ['value' => 'Python', 'label' => 'Python'],
                            ['value' => 'JavaScript', 'label' => 'JavaScript'],
                            ['value' => 'TypeScript', 'label' => 'TypeScript'],
                            ['value' => 'Java', 'label' => 'Java'],
                            ['value' => 'PHP', 'label' => 'PHP'],
                            ['value' => 'C++', 'label' => 'C++'],
                            ['value' => 'C#', 'label' => 'C#'],
                            ['value' => 'Go', 'label' => 'Go'],
                            ['value' => 'Rust', 'label' => 'Rust'],
                            ['value' => 'Ruby', 'label' => 'Ruby'],
                        ],
                        'description' => '选择要生成的编程语言'
                    ],
                    [
                        'name' => 'code_style',
                        'label' => '代码风格',
                        'type' => 'select',
                        'required' => false,
                        'default' => 'clean',
                        'options' => [
                            ['value' => 'clean', 'label' => '简洁代码 (Clean Code)'],
                            ['value' => 'verbose', 'label' => '详细注释 (Verbose)'],
                            ['value' => 'minimal', 'label' => '极简风格 (Minimal)'],
                        ],
                        'description' => '选择代码的注释和文档风格'
                    ],
                    [
                        'name' => 'include_comments',
                        'label' => '包含注释',
                        'type' => 'checkbox',
                        'required' => false,
                        'default' => true,
                        'description' => '是否在生成的代码中包含注释'
                    ],
                    [
                        'name' => 'include_tests',
                        'label' => '包含测试',
                        'type' => 'checkbox',
                        'required' => false,
                        'default' => false,
                        'description' => '是否生成单元测试代码'
                    ],
                ]
            ]),
            'examples' => json_encode([
                [
                    'title' => 'Python快速排序',
                    'description' => '生成Python的快速排序算法实现',
                    'input' => '实现快速排序算法',
                    'expected_output' => '带注释的Python快速排序代码',
                ],
                [
                    'title' => 'JavaScript API客户端',
                    'description' => '生成REST API客户端代码',
                    'input' => '创建一个REST API客户端类',
                    'expected_output' => '完整的JavaScript API客户端实现',
                ],
                [
                    'title' => 'Java数据结构',
                    'description' => '实现自定义数据结构',
                    'input' => '实现一个二叉搜索树',
                    'expected_output' => 'Java二叉搜索树完整实现',
                ],
            ]),
            'is_active' => 1,
            'created_time' => time(),
            'updated_time' => time(),
        ];
        
        $adapter = ObjectManager::getInstance(AiScenarioAdapter::class);
        $adapter->setData($adapterData);
        $adapter->save();
        
        echo "  ✓ 创建代码生成适配器: {$adapterData['name']}\n";
        echo "    代码: {$adapterData['code']}\n";
        echo "    类名: {$adapterData['class_name']}\n";
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
                // 兼容字段：确保外部查询能正常工作
                'vendor' => 'openai',
                'product' => 'gpt',
                'model' => 'gpt-3.5-turbo',
                'class' => '',
                'default_api_key' => '',
                'default_api_url' => '',
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
                // 兼容字段：确保外部查询能正常工作
                'vendor' => 'openai',
                'product' => 'gpt',
                'model' => 'gpt-4',
                'class' => '',
                'default_api_key' => '',
                'default_api_url' => '',
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
                // 兼容字段：确保外部查询能正常工作
                'vendor' => 'anthropic',
                'product' => 'claude',
                'model' => 'claude-3-sonnet',
                'class' => '',
                'default_api_key' => '',
                'default_api_url' => '',
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
                // 兼容字段：确保外部查询能正常工作
                'vendor' => 'deepseek',
                'product' => 'deepseek',
                'model' => 'deepseek-v3.1',
                'class' => '',
                'default_api_key' => '',
                'default_api_url' => '',
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
                // 兼容字段：确保外部查询能正常工作
                'vendor' => 'deepseek',
                'product' => 'deepseek',
                'model' => 'deepseek-r1-0528',
                'class' => '',
                'default_api_key' => '',
                'default_api_url' => '',
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

