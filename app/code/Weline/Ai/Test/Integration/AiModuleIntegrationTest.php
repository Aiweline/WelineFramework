<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiTenant;
use Weline\Ai\Model\AiApiKey;
use Weline\Ai\Model\AiAssistant;
use Weline\Ai\Service\AiModelService;
use Weline\Ai\Service\AiApiKeyService;
use Weline\Ai\Service\AiAssistantService;

/**
 * AI模块集成测试
 * 
 * 测试范围：
 * - 数据库表
 * - Model CRUD
 * - Service业务逻辑
 * - 集成功能
 */
class AiModuleIntegrationTest extends TestCase
{
    private AiModel $aiModel;
    private AiTenant $aiTenant;
    private AiApiKey $aiApiKey;
    private AiModelService $modelService;
    private AiApiKeyService $apiKeyService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 初始化对象
        $this->aiModel = ObjectManager::getInstance(AiModel::class);
        $this->aiTenant = ObjectManager::getInstance(AiTenant::class);
        $this->aiApiKey = ObjectManager::getInstance(AiApiKey::class);
        $this->modelService = ObjectManager::getInstance(AiModelService::class);
        $this->apiKeyService = ObjectManager::getInstance(AiApiKeyService::class);
    }

    /**
     * 测试1: 数据库表存在性
     */
    public function testDatabaseTablesExist()
    {
        // 测试核心表
        $tables = [
            'ai_model' => $this->aiModel,
            'ai_tenant' => $this->aiTenant,
            'ai_api_key' => $this->aiApiKey,
        ];

        foreach ($tables as $tableName => $model) {
            try {
                $count = $model->total();
                $this->assertIsInt($count, "表 {$tableName} 应该返回整数记录数");
                echo "✓ {$tableName}: {$count} 条记录\n";
            } catch (\Exception $e) {
                $this->fail("表 {$tableName} 不存在或无法访问: " . $e->getMessage());
            }
        }
    }

    /**
     * 测试2: 模型CRUD操作
     */
    public function testModelCrudOperations()
    {
        // 创建测试模型
        $testModel = clone $this->aiModel;
        $testData = [
            'supplier' => 'TestVendor',
            'model_code' => 'test-model-' . time(),
            'name' => '测试模型',
            'version' => '1.0',
            'status' => 'active',
            'is_active' => true,
            'is_copy' => false,
            'token_price_input' => 0.01,
            'token_price_output' => 0.02,
            'config' => json_encode(['temperature' => 0.7]),
        ];

        // 创建
        $testModel->setData($testData);
        $result = $testModel->save();
        $this->assertTrue($result, "模型创建应该成功");
        $this->assertNotEmpty($testModel->getId(), "创建的模型应该有ID");
        echo "✓ 模型创建成功，ID: " . $testModel->getId() . "\n";

        // 读取
        $loadedModel = clone $this->aiModel;
        $loadedModel->load($testModel->getId());
        $this->assertEquals($testData['name'], $loadedModel->getData('name'), "读取的数据应该匹配");
        echo "✓ 模型读取成功\n";

        // 更新
        $loadedModel->setData('name', '测试模型-已更新');
        $loadedModel->save();
        $updatedModel = clone $this->aiModel;
        $updatedModel->load($testModel->getId());
        $this->assertEquals('测试模型-已更新', $updatedModel->getData('name'), "更新应该生效");
        echo "✓ 模型更新成功\n";

        // 删除
        if ($updatedModel->canDelete()) {
            $updatedModel->delete();
            $deletedModel = clone $this->aiModel;
            $deletedModel->load($testModel->getId());
            $this->assertEmpty($deletedModel->getId(), "删除后模型应该不存在");
            echo "✓ 模型删除成功\n";
        } else {
            echo "⚠ 模型受保护，跳过删除测试\n";
        }
    }

    /**
     * 测试3: 模型复制功能
     */
    public function testModelCopyFunction()
    {
        // 创建原始模型
        $originalModel = clone $this->aiModel;
        $originalModel->setData([
            'supplier' => 'TestVendor',
            'model_code' => 'original-' . time(),
            'name' => '原始模型',
            'version' => '1.0',
            'is_copy' => false,
            'status' => 'active',
            'is_active' => true,
            'token_price_input' => 0.01,
            'token_price_output' => 0.02,
            'config' => json_encode(['temperature' => 0.7]),
        ]);
        $originalModel->save();
        $originalId = $originalModel->getId();

        // 测试复制
        $copiedModel = $this->modelService->copyModel(
            $originalId,
            '复制模型',
            ['temperature' => 0.9]
        );

        // 验证
        $this->assertNotEmpty($copiedModel->getId(), "复制的模型应该有ID");
        $this->assertEquals(true, $copiedModel->getData('is_copy'), "is_copy标志应该为true");
        $this->assertEquals($originalId, $copiedModel->getData('origin_model_id'), "origin_model_id应该正确");
        echo "✓ 模型复制成功\n";

        // 验证删除保护
        $this->assertFalse($originalModel->canDelete(), "原始模型不应该可删除");
        $this->assertTrue($copiedModel->canDelete(), "复制模型应该可删除");
        echo "✓ 删除保护逻辑正确\n";

        // 清理
        if ($copiedModel->canDelete()) {
            $copiedModel->delete();
        }
        if ($originalModel->canDelete()) {
            $originalModel->delete();
        }
    }

    /**
     * 测试4: API密钥生成和验证
     */
    public function testApiKeyGeneration()
    {
        // 生成密钥
        $apiKey = $this->apiKeyService->createApiKey(
            '测试密钥',
            1,  // user_id
            1,  // tenant_id
            [
                'quota_daily' => 1000,
                'quota_monthly' => 30000,
            ]
        );

        // 验证生成（一次性返回原始令牌 raw_token）
        $this->assertNotEmpty($apiKey->getData('raw_token'), "Token应该生成");
        $token = $apiKey->getData('raw_token');
        echo "✓ API密钥生成成功: " . substr($token, 0, 20) . "...\n";

        // 验证有效性
        $isValid = $this->apiKeyService->validateToken($token);
        $this->assertNotNull($isValid, "密钥验证应该返回有效结果");
        echo "✓ API密钥验证成功\n";

        // 验证配额
        $this->assertEquals(1000, $apiKey->getData('quota_daily'), "日配额应该正确");
        $this->assertEquals(30000, $apiKey->getData('quota_monthly'), "月配额应该正确");
        $this->assertEquals(0, $apiKey->getData('usage_daily'), "初始使用量应该为0");
        echo "✓ 配额设置正确\n";

        // 清理
        if ($apiKey->getId()) {
            $apiKey->delete();
        }
    }

    /**
     * 测试5: 租户功能
     */
    public function testTenantFunctionality()
    {
        // 检查默认租户
        $defaultTenant = clone $this->aiTenant;
        $tenants = $defaultTenant->reset()
            ->where('status', 'active')
            ->select()
            ->fetch();

        $this->assertNotEmpty($tenants, "应该至少有一个活跃租户");
        echo "✓ 找到 " . count($tenants) . " 个活跃租户\n";

        // 创建测试租户
        $testTenant = clone $this->aiTenant;
        $testTenant->setData([
            'name' => '测试租户',
            'domain' => 'test-' . time() . '.localhost',
            'billing_plan' => 'free',
            'status' => 'active',
            'quota_monthly' => 10000,
            'usage_monthly' => 0,
        ]);
        $testTenant->save();

        $this->assertNotEmpty($testTenant->getId(), "租户应该创建成功");
        echo "✓ 测试租户创建成功\n";

        // 清理
        if ($testTenant->getId()) {
            $testTenant->delete();
        }
    }

    /**
     * 测试6: 助手功能
     */
    public function testAssistantFunctionality()
    {
        // 先确保有模型
        $testModel = clone $this->aiModel;
        $testModel->setData([
            'supplier' => 'TestVendor',
            'model_code' => 'test-assistant-model-' . time(),
            'name' => '助手测试模型',
            'version' => '1.0',
            'status' => 'active',
            'is_active' => true,
            'token_price_input' => 0.01,
            'token_price_output' => 0.02,
        ]);
        $testModel->save();
        $modelId = $testModel->getId();

        // 创建助手
        $assistant = ObjectManager::getInstance(AiAssistant::class);
        $assistant->setData([
            'name' => '测试助手',
            'description' => '这是一个测试助手',
            'prompt_template' => '你是一个AI助手，请帮助用户...',
            'model_id' => $modelId,
            'user_id' => 1,
            'tenant_id' => 1,
            'is_public' => true,
            'status' => 'active',
        ]);
        $assistant->save();

        $this->assertNotEmpty($assistant->getId(), "助手应该创建成功");
        echo "✓ 助手创建成功\n";

        // 清理
        if ($assistant->getId()) {
            $assistant->delete();
        }
        if ($testModel->getId() && $testModel->canDelete()) {
            $testModel->delete();
        }
    }

    /**
     * 测试总结
     */
    public static function tearDownAfterClass(): void
    {
        echo "\n";
        echo "========================================\n";
        echo "     AI模块集成测试完成\n";
        echo "========================================\n";
    }
}

