<?php

declare(strict_types=1);

namespace Weline\Ai\Console\Test;

use Weline\Framework\Console\CommandInterface;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiTenant;
use Weline\Ai\Service\AiApiKeyService;

/**
 * Seed test data for Weline_Ai module
 */
class SeedData implements CommandInterface
{

    private AiModel $aiModel;
    private AiTenant $aiTenant;
    private AiApiKeyService $apiKeyService;

    public function __construct(
        AiModel $aiModel,
        AiTenant $aiTenant,
        AiApiKeyService $apiKeyService
    ) {
        $this->aiModel = $aiModel;
        $this->aiTenant = $aiTenant;
        $this->apiKeyService = $apiKeyService;
    }

    public function execute(array $args = [], array $data = [])
    {
        echo "\n=== Weline_Ai 测试数据生成 ===\n\n";

        try {
            $now = time(); // Unix时间戳
            
            // 1. 创建默认租户
            echo "📦 创建默认租户...\n";
            $tenantData = [
                'name' => '默认租户',
                'domain' => 'test-localhost-' . $now,  // 确保唯一性
                'config' => json_encode(['max_users' => 100]),
                'quota_monthly' => 10000,
                'usage_monthly' => 0,
                'billing_plan' => 'free',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $tenant = $this->aiTenant->insert($tenantData)->fetch();
            echo "  ✅ 租户ID: {$tenant->getId()}\n";

            // 2. 创建示例AI模型
            echo "\n🤖 创建示例AI模型...\n";
            $models = [
                [
                    'supplier' => 'OpenAI',
                    'model_code' => 'gpt-3.5-turbo',
                    'name' => 'GPT-3.5 Turbo',
                    'version' => '0613',
                    'is_copy' => 0,
                    'config' => json_encode(['temperature' => 0.7, 'max_tokens' => 2000]),
                    'capabilities' => json_encode(['chat', 'completion']),
                    'max_tokens' => 4096,
                    'cost_per_token' => '0.000002',
                    'token_price_input' => 0.000002,
                    'token_price_output' => 0.000002,
                    'status' => 'active',
                    'is_active' => 1,
                    'is_default' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'supplier' => 'OpenAI',
                    'model_code' => 'gpt-4',
                    'name' => 'GPT-4',
                    'version' => '0613',
                    'is_copy' => 0,
                    'config' => json_encode(['temperature' => 0.7, 'max_tokens' => 4000]),
                    'capabilities' => json_encode(['chat', 'completion', 'analysis']),
                    'max_tokens' => 8192,
                    'cost_per_token' => '0.00003',
                    'token_price_input' => 0.00003,
                    'token_price_output' => 0.00003,
                    'status' => 'active',
                    'is_active' => 1,
                    'is_default' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'supplier' => 'Anthropic',
                    'model_code' => 'claude-3-sonnet',
                    'name' => 'Claude 3 Sonnet',
                    'version' => '20240229',
                    'is_copy' => 0,
                    'config' => json_encode(['temperature' => 1.0, 'max_tokens' => 4000]),
                    'capabilities' => json_encode(['chat', 'completion', 'analysis', 'coding']),
                    'max_tokens' => 200000,
                    'cost_per_token' => '0.000015',
                    'token_price_input' => 0.000015,
                    'token_price_output' => 0.000015,
                    'status' => 'active',
                    'is_active' => 1,
                    'is_default' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ];

            $modelIds = [];
            foreach ($models as $modelData) {
                $model = $this->aiModel->insert($modelData)->fetch();
                $modelIds[] = $model->getId();
                echo "  ✅ {$modelData['name']} (ID: {$model->getId()})\n";
            }

            // 3. 创建测试API密钥
            echo "\n🔑 创建测试API密钥...\n";
            $apiKey = $this->apiKeyService->createApiKey(
                '测试API密钥',
                1, // user_id
                (int)$tenant->getId(),  // 确保是int类型
                [
                    'quota_daily' => 1000,
                    'quota_monthly' => 10000,
                    'status' => 'approved',
                ]
            );
            echo "  ✅ API Key: {$apiKey->getData('token')}\n";
            echo "  ✅ 状态: {$apiKey->getData('status')}\n";

            // 4. 创建拷贝模型示例
            echo "\n📋 创建拷贝模型示例...\n";
            $originalModel = clone $this->aiModel;
            $originalModel->load($modelIds[0]);
            
            $copyModelData = [
                'supplier' => $originalModel->getData('supplier'),
                'model_code' => $originalModel->getData('model_code') . '_custom_' . time(),
                'name' => $originalModel->getData('name') . ' (自定义版本)',
                'version' => $originalModel->getData('version'),
                'is_copy' => 1,
                'origin_model_id' => $originalModel->getId(),
                'config' => json_encode(['temperature' => 0.9, 'max_tokens' => 3000]),
                'capabilities' => $originalModel->getData('capabilities'),
                'max_tokens' => $originalModel->getData('max_tokens'),
                'cost_per_token' => $originalModel->getData('cost_per_token'),
                'token_price_input' => $originalModel->getData('token_price_input'),
                'token_price_output' => $originalModel->getData('token_price_output'),
                'status' => 'active',
                'is_active' => 1,
                'is_default' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $copyModel = $this->aiModel->insert($copyModelData)->fetch();
            echo "  ✅ 拷贝模型: {$copyModel->getData('name')} (ID: {$copyModel->getId()})\n";
            echo "  ✅ 原始模型ID: {$copyModel->getData('origin_model_id')}\n";

            echo "\n✅ 测试数据生成完成！\n";
            echo "\n📝 生成的数据摘要:\n";
            echo "  - 租户: 1个\n";
            echo "  - AI模型: " . count($models) . "个原始模型 + 1个拷贝模型\n";
            echo "  - API密钥: 1个\n";
            echo "\n🧪 现在可以使用以下命令测试API:\n";
            echo "  php bin/w http:request POST /api/v1/chat -d '{\"prompt\":\"你好\",\"model_code\":\"gpt-3.5-turbo\",\"session_id\":\"test\"}'\n";
            echo "  php bin/w http:request GET /api/v1/model/{$modelIds[0]}\n";
            echo "  php bin/w http:request POST /api/v1/api-key -d '{\"name\":\"新密钥\",\"user_id\":1}'\n\n";

        } catch (\Exception $e) {
            echo "\n❌ 错误: {$e->getMessage()}\n";
            echo "堆栈跟踪:\n{$e->getTraceAsString()}\n\n";
        }
    }

    public function tip(): string
    {
        return '生成 Weline_Ai 模块测试数据';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'ai:seed',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            ['php bin/w ai:seed']
        );
    }
}

