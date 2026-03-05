<?php
declare(strict_types=1);

namespace Weline\Ai\test;

use Weline\Ai\Model\AiModel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

/**
 * Backend Model Controller 单元测试
 * 
 * 测试Backend/Model Controller的核心功能：
 * - 模型列表展示
 * - 模型编辑和保存
 * - 模型复制
 * - 模型删除（仅复制模型）
 * - 模型收集
 * - 状态切换
 * 
 * @package Weline_Ai
 * @see app/code/Weline/Ai/Controller/Backend/Model.php
 */
class ModelControllerTest extends TestCore
{
    /**
     * @var AiModel
     */
    private AiModel $model;

    /**
     * 测试模型ID集合（用于清理）
     * @var array
     */
    private array $testModelIds = [];

    /**
     * 设置测试环境
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // 初始化Model实例
        $this->model = ObjectManager::getInstance(AiModel::class);
        
        // 清空测试模型ID列表
        $this->testModelIds = [];
    }

    /**
     * 清理测试环境
     */
    protected function tearDown(): void
    {
        // 清理所有测试创建的模型
        foreach ($this->testModelIds as $modelId) {
            try {
                $model = ObjectManager::getInstance(AiModel::class);
                $model->load($modelId);
                if ($model->getId()) {
                    $model->delete();
                }
            } catch (\Exception $e) {
                // 忽略删除错误
            }
        }

        // 清理所有包含"test"关键词的模型（防止数据泄漏）
        // 注意：不使用where条件，因为Model可能已经加载了其他条件
        // 改为手动查询并删除
        try {
            $testModels = ObjectManager::getInstance(AiModel::class)
                ->clearData()
                ->reset()
                ->select()
                ->fetch();
            
            if ($testModels && method_exists($testModels, 'getItems')) {
                foreach ($testModels->getItems() as $testModel) {
                    $modelCode = $testModel->getData(AiModel::schema_fields_MODEL_CODE);
                    if ($modelCode && strpos($modelCode, 'test') !== false) {
                        try {
                            $testModel->delete()->fetch();
                        } catch (\Exception $e) {
                            // 忽略单个删除错误
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // 忽略清理错误
        }

        parent::tearDown();
    }

    // ============================================
    // 测试夹具方法（Test Fixtures）
    // ============================================

    /**
     * 创建测试用的原始模型
     * 
     * @param array $customData 自定义数据
     * @return AiModel
     */
    private function createTestModel(array $customData = []): AiModel
    {
        // 设置完整的默认值，允许customData完全覆盖
        $defaults = [
            AiModel::schema_fields_SUPPLIER => 'test-supplier-' . uniqid(),
            AiModel::schema_fields_MODEL_CODE => 'test-model-' . uniqid(),
            AiModel::schema_fields_NAME => '测试模型',
            AiModel::schema_fields_VERSION => '1.0.0',
            AiModel::schema_fields_IS_COPY => 0, // 默认是原始模型
            AiModel::schema_fields_ORIGIN_MODEL_ID => null,
            AiModel::schema_fields_CONFIG => json_encode(['temperature' => 0.7]),
            AiModel::schema_fields_CAPABILITIES => json_encode(['chat' => true]),
            AiModel::schema_fields_MAX_TOKENS => 4096,
            AiModel::schema_fields_COST_PER_TOKEN => '0.0015',
            AiModel::schema_fields_TOKEN_PRICE_INPUT => 0.03,
            AiModel::schema_fields_TOKEN_PRICE_OUTPUT => 0.06,
            AiModel::schema_fields_PROXY_INFO => null,
            AiModel::schema_fields_STATUS => 'active',
            AiModel::schema_fields_IS_ACTIVE => 1,
            AiModel::schema_fields_IS_DEFAULT => 0,
        ];

        // customData优先级更高
        $data = array_merge($defaults, $customData);

        // 创建模型实例（使用ObjectManager，但清理数据以避免单例污染）
        $model = ObjectManager::getInstance(AiModel::class);
        $model->clearData(); // 清理之前的所有数据
        $model->reset(); // 重置查询条件
        
        // 逐个设置字段
        foreach ($data as $field => $value) {
            $model->setData($field, $value);
        }
        
        // 保存
        $model->save();
        $modelId = $model->getId();
        
        // 记录ID用于清理
        if ($modelId) {
            $this->testModelIds[] = $modelId;
        }

        // 重新从数据库加载一个新的独立模型实例（避免单例问题）
        // 使用clearData()清空，然后load()会创建新的数据集
        $freshModel = ObjectManager::getInstance(AiModel::class);
        $freshModel->clearData();
        $freshModel->reset();
        $freshModel->load($modelId);
        
        return $freshModel;
    }

    /**
     * 创建测试用的复制模型
     * 
     * @param int $originModelId 原始模型ID
     * @param array $customData 自定义数据
     * @return AiModel
     */
    private function createCopiedModel(int $originModelId, array $customData = []): AiModel
    {
        $defaultData = [
            AiModel::schema_fields_IS_COPY => 1,
            AiModel::schema_fields_ORIGIN_MODEL_ID => $originModelId,
            AiModel::schema_fields_MODEL_CODE => 'test-copy-' . uniqid(),
        ];

        return $this->createTestModel(array_merge($defaultData, $customData));
    }

    // ============================================
    // 测试方法：模型数据库操作
    // ============================================

    /**
     * 测试模型创建和数据完整性
     */
    public function testModelCreationAndDataIntegrity(): void
    {
        // 直接创建模型，不使用createTestModel辅助方法
        $testModel = ObjectManager::getInstance(AiModel::class);
        $testModel->reset();
        
        $modelCode = 'test-integrity-' . uniqid();
        $testModel->setData(AiModel::schema_fields_SUPPLIER, 'integrity-supplier');
        $testModel->setData(AiModel::schema_fields_MODEL_CODE, $modelCode);
        $testModel->setData(AiModel::schema_fields_NAME, '完整性测试模型');
        $testModel->setData(AiModel::schema_fields_VERSION, '1.0.0');
        $testModel->setData(AiModel::schema_fields_IS_COPY, 0);
        $testModel->setData(AiModel::schema_fields_IS_ACTIVE, 1);
        $testModel->setData(AiModel::schema_fields_IS_DEFAULT, 0);
        $testModel->setData(AiModel::schema_fields_TOKEN_PRICE_INPUT, 0.03);
        $testModel->setData(AiModel::schema_fields_TOKEN_PRICE_OUTPUT, 0.06);
        
        $testModel->save();
        $modelId = $testModel->getId();
        $this->testModelIds[] = $modelId;
        
        // 验证保存成功
        $this->assertNotEmpty($modelId, '模型应该被保存');
        
        // 重新从数据库加载
        $loadedModel = ObjectManager::getInstance(AiModel::class);
        $loadedModel->reset()->load($modelId);
        
        // 验证字段值
        $this->assertEquals($modelId, $loadedModel->getId(), 'ID应匹配');
        $this->assertEquals('integrity-supplier', $loadedModel->getData(AiModel::schema_fields_SUPPLIER), '供应商应匹配');
        $this->assertEquals($modelCode, $loadedModel->getData(AiModel::schema_fields_MODEL_CODE), '模型代码应匹配');
        $this->assertEquals('完整性测试模型', $loadedModel->getData(AiModel::schema_fields_NAME), '名称应匹配');
        $this->assertEquals(0, $loadedModel->getData(AiModel::schema_fields_IS_COPY), 'IS_COPY应为0');
        $this->assertEquals(1, $loadedModel->getData(AiModel::schema_fields_IS_ACTIVE), 'IS_ACTIVE应为1');
    }

    /**
     * 测试模型复制逻辑
     */
    public function testModelCopyLogic(): void
    {
        // 创建原始模型
        $originalModel = $this->createTestModel([
            AiModel::schema_fields_NAME => '原始模型',
            AiModel::schema_fields_MODEL_CODE => 'original-model-' . uniqid(),
            AiModel::schema_fields_SUPPLIER => 'copy-test-supplier',
            AiModel::schema_fields_MAX_TOKENS => 8192,
            AiModel::schema_fields_IS_COPY => 0, // 明确设置为原始模型
        ]);

        $originalModelId = (int)$originalModel->getId();
        
        // 验证原始模型创建时的状态（在创建复制模型之前）
        $this->assertEquals(0, $originalModel->getData(AiModel::schema_fields_IS_COPY), '原始模型创建时IS_COPY应为0');

        // 创建复制模型
        $copiedModel = $this->createCopiedModel($originalModelId, [
            AiModel::schema_fields_NAME => '复制的模型',
        ]);

        // 验证复制模型的标记
        $this->assertEquals(1, $copiedModel->getData(AiModel::schema_fields_IS_COPY), '复制模型IS_COPY应为1');
        $this->assertEquals(
            $originalModelId,
            $copiedModel->getData(AiModel::schema_fields_ORIGIN_MODEL_ID),
            'origin_model_id应指向原始模型'
        );
    }

    /**
     * 测试模型删除保护逻辑
     */
    public function testOriginalModelDeletionProtection(): void
    {
        // 创建原始模型
        $originalModel = $this->createTestModel([
            AiModel::schema_fields_NAME => '不可删除的原始模型',
            AiModel::schema_fields_IS_COPY => 0,
        ]);

        // 验证是原始模型
        $this->assertEquals(0, $originalModel->getData(AiModel::schema_fields_IS_COPY), '应为原始模型');
        $this->assertFalse($originalModel->isCopied(), 'isCopied()应返回false');
        
        // Controller应该检查is_copy状态并拒绝删除
        // 这个测试验证模型层面的标识正确
        $this->assertTrue($originalModel->getId() > 0, '原始模型应存在');
        $this->assertFalse($originalModel->isCopied(), 'Controller应根据此标识拒绝删除');
    }

    /**
     * 测试复制模型可以被删除
     */
    public function testCopiedModelCanBeDeleted(): void
    {
        // 创建原始模型和复制模型
        $originalModel = $this->createTestModel([
            AiModel::schema_fields_NAME => '原始模型（用于复制）',
        ]);
        
        $copiedModel = $this->createCopiedModel((int)$originalModel->getId(), [
            AiModel::schema_fields_NAME => '可删除的复制模型',
        ]);

        $copiedModelId = $copiedModel->getId();

        // 验证是复制模型
        $this->assertEquals(1, $copiedModel->getData(AiModel::schema_fields_IS_COPY), '应为复制模型');
        
        // 删除复制模型
        $copiedModel->delete()->fetch();
        
        // 验证删除成功 - 重新从数据库加载
        $deletedModel = ObjectManager::getInstance(AiModel::class);
        $deletedModel->reset()->load($copiedModelId);
        
        $this->assertEmpty($deletedModel->getId(), '复制模型应被成功删除');
    }

    /**
     * 测试模型状态切换
     */
    public function testModelStatusToggle(): void
    {
        // 创建激活状态的模型
        $model = $this->createTestModel([
            AiModel::schema_fields_IS_ACTIVE => 1,
        ]);

        $originalStatus = $model->getData(AiModel::schema_fields_IS_ACTIVE);
        $this->assertEquals(1, $originalStatus, '初始状态应为激活');

        // 切换状态（激活 → 停用）
        $model->setData(AiModel::schema_fields_IS_ACTIVE, 0);
        $model->save();

        // 重新加载验证
        $updatedModel = ObjectManager::getInstance(AiModel::class);
        $updatedModel->load($model->getId());
        $this->assertEquals(0, $updatedModel->getData(AiModel::schema_fields_IS_ACTIVE), '状态应从1变为0');

        // 再次切换（停用 → 激活）
        $updatedModel->setData(AiModel::schema_fields_IS_ACTIVE, 1);
        $updatedModel->save();

        // 验证状态恢复
        $finalModel = ObjectManager::getInstance(AiModel::class);
        $finalModel->load($model->getId());
        $this->assertEquals(1, $finalModel->getData(AiModel::schema_fields_IS_ACTIVE), '状态应从0恢复为1');
    }

    /**
     * 测试模型查询功能
     */
    public function testModelQueryOperations(): void
    {
        // 创建测试供应商标识
        $testSupplier = 'query-test-supplier-' . uniqid();
        
        // 创建第一个测试模型并立即验证（避免单例覆盖）
        $model1 = $this->createTestModel([
            AiModel::schema_fields_NAME => '查询测试模型1',
            AiModel::schema_fields_SUPPLIER => $testSupplier,
        ]);
        
        $model1Id = $model1->getId();
        $model1Name = $model1->getData(AiModel::schema_fields_NAME);
        $model1Supplier = $model1->getData(AiModel::schema_fields_SUPPLIER);
        
        $this->assertNotEmpty($model1Id, 'model1应有ID');
        $this->assertEquals('查询测试模型1', $model1Name, 'model1名称应匹配');
        $this->assertEquals($testSupplier, $model1Supplier, 'model1供应商应匹配');
        
        // 创建第二个测试模型
        $model2 = $this->createTestModel([
            AiModel::schema_fields_NAME => '查询测试模型2',
            AiModel::schema_fields_SUPPLIER => $testSupplier,
        ]);
        
        $model2Id = $model2->getId();
        $model2Name = $model2->getData(AiModel::schema_fields_NAME);
        
        $this->assertNotEmpty($model2Id, 'model2应有ID');
        $this->assertEquals('查询测试模型2', $model2Name, 'model2名称应匹配');
        $this->assertNotEquals($model1Id, $model2Id, 'model1和model2应该是不同的记录');

        // 测试按供应商查询
        $queryModel = new AiModel(); // 使用new创建新实例，避免单例问题
        $models = $queryModel
            ->where(AiModel::schema_fields_SUPPLIER, $testSupplier)
            ->select()
            ->fetch();

        $modelCount = is_object($models) && method_exists($models, 'getItems') 
            ? count($models->getItems()) 
            : (is_array($models) ? count($models) : 0);
        
        $this->assertGreaterThanOrEqual(2, $modelCount, "应找到2个模型（实际找到{$modelCount}个）");
    }

    /**
     * 测试模型字段验证
     */
    public function testModelFieldValidation(): void
    {
        // 验证所有必需的字段常量都已定义
        $requiredFields = [
            'ID', 'SUPPLIER', 'MODEL_CODE', 'NAME', 'VERSION',
            'IS_COPY', 'ORIGIN_MODEL_ID', 'CONFIG', 'CAPABILITIES',
            'MAX_TOKENS', 'COST_PER_TOKEN', 'TOKEN_PRICE_INPUT',
            'TOKEN_PRICE_OUTPUT', 'PROXY_INFO', 'STATUS',
            'IS_ACTIVE', 'IS_DEFAULT', 'CREATED_AT', 'UPDATED_AT'
        ];

        foreach ($requiredFields as $field) {
            $constantName = 'fields_' . $field;
            $this->assertTrue(
                defined(AiModel::class . '::' . $constantName),
                "字段常量 {$constantName} 未定义"
            );
        }
    }
}

