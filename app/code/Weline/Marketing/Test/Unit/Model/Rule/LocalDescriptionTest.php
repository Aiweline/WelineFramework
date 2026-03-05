<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Test\Unit\Model\Rule;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\LocalModel;
use Weline\I18n\LocalModelInterface;
use Weline\Marketing\Model\Rule\LocalDescription;
use Weline\Marketing\Model\Rule\Rule;

/**
 * LocalDescription 模型单元测试
 * 
 * 测试营销规则多语言翻译模型的功能
 */
class LocalDescriptionTest extends TestCase
{
    private LocalDescription $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = ObjectManager::getInstance(LocalDescription::class);
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        if ($this->model->getId()) {
            $this->model->delete();
        }
        parent::tearDown();
    }

    /**
     * 测试：模型实例化
     * 
     * 验证模型能够正确实例化，并且继承自 LocalModel
     */
    public function testModelInstantiation(): void
    {
        $this->assertInstanceOf(LocalDescription::class, $this->model);
        $this->assertInstanceOf(LocalModel::class, $this->model);
        $this->assertInstanceOf(LocalModelInterface::class, $this->model);
    }

    /**
     * 测试：字段常量定义
     * 
     * 验证所有字段常量正确定义
     */
    public function testFieldConstants(): void
    {
        // 关联主表ID字段
        $this->assertEquals('id', LocalDescription::schema_fields_ID);
        $this->assertEquals(Rule::schema_fields_ID, LocalDescription::schema_fields_ID);
        
        // 多语言字段
        $this->assertEquals('name', LocalDescription::schema_fields_NAME);
        $this->assertEquals('description', LocalDescription::schema_fields_DESCRIPTION);
        
        // LocalModel 接口要求的字段
        $this->assertEquals('local_code', LocalModelInterface::schema_fields_local_code);
    }

    /**
     * 测试：表名和索引器常量
     * 
     * 验证表名和索引器常量正确定义
     */
    public function testTableAndIndexerConstants(): void
    {
        $this->assertEquals('weline_marketing_rule_local_description', LocalDescription::schema_table);
        $this->assertEquals('marketing_rule_local_description', LocalDescription::indexer);
    }

    /**
     * 测试：数据设置和获取
     * 
     * 验证模型能够正确设置和获取数据
     */
    public function testSetAndGetData(): void
    {
        $testData = [
            LocalDescription::schema_fields_ID => 1,
            LocalModelInterface::schema_fields_local_code => 'zh_Hans_CN',
            LocalDescription::schema_fields_NAME => '测试规则名称',
            LocalDescription::schema_fields_DESCRIPTION => '测试规则描述'
        ];

        $this->model->setData($testData);

        $this->assertEquals(1, $this->model->getData(LocalDescription::schema_fields_ID));
        $this->assertEquals('zh_Hans_CN', $this->model->getData(LocalModelInterface::schema_fields_local_code));
        $this->assertEquals('测试规则名称', $this->model->getData(LocalDescription::schema_fields_NAME));
        $this->assertEquals('测试规则描述', $this->model->getData(LocalDescription::schema_fields_DESCRIPTION));
    }

    /**
     * 测试：多语言翻译保存和读取
     * 
     * 验证能够保存和读取不同语言的翻译数据
     */
    public function testSaveAndLoadTranslation(): void
    {
        // 创建测试规则
        /** @var Rule $rule */
        $rule = ObjectManager::getInstance(Rule::class);
        $rule->setData([
            Rule::schema_fields_NAME => 'Test Rule',
            Rule::schema_fields_DESCRIPTION => 'Test Description',
            Rule::schema_fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
            Rule::schema_fields_STATUS => Rule::STATUS_ACTIVE
        ]);
        $rule->save();
        $ruleId = $rule->getId();
        
        $this->assertNotNull($ruleId, '规则ID不应为空');

        try {
            // 保存中文翻译
            /** @var LocalDescription $zhTranslation */
            $zhTranslation = ObjectManager::getInstance(LocalDescription::class);
            $zhTranslation->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                LocalModelInterface::schema_fields_local_code => 'zh_Hans_CN',
                LocalDescription::schema_fields_NAME => '测试规则',
                LocalDescription::schema_fields_DESCRIPTION => '测试规则描述'
            ]);
            $zhTranslation->save();
            
            $this->assertNotNull($zhTranslation->getId(), '中文翻译应保存成功');

            // 保存英文翻译
            /** @var LocalDescription $enTranslation */
            $enTranslation = ObjectManager::getInstance(LocalDescription::class);
            $enTranslation->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                LocalModelInterface::schema_fields_local_code => 'en_US',
                LocalDescription::schema_fields_NAME => 'Test Rule',
                LocalDescription::schema_fields_DESCRIPTION => 'Test Rule Description'
            ]);
            $enTranslation->save();
            
            $this->assertNotNull($enTranslation->getId(), '英文翻译应保存成功');

            // 读取中文翻译
            $zhLoaded = ObjectManager::getInstance(LocalDescription::class);
            $zhLoaded->reset()
                ->where(LocalDescription::schema_fields_ID, $ruleId)
                ->where(LocalModelInterface::schema_fields_local_code, 'zh_Hans_CN')
                ->find()
                ->fetch();
            
            $this->assertEquals('测试规则', $zhLoaded->getData(LocalDescription::schema_fields_NAME));
            $this->assertEquals('测试规则描述', $zhLoaded->getData(LocalDescription::schema_fields_DESCRIPTION));

            // 读取英文翻译
            $enLoaded = ObjectManager::getInstance(LocalDescription::class);
            $enLoaded->reset()
                ->where(LocalDescription::schema_fields_ID, $ruleId)
                ->where(LocalModelInterface::schema_fields_local_code, 'en_US')
                ->find()
                ->fetch();
            
            $this->assertEquals('Test Rule', $enLoaded->getData(LocalDescription::schema_fields_NAME));
            $this->assertEquals('Test Rule Description', $enLoaded->getData(LocalDescription::schema_fields_DESCRIPTION));

        } finally {
            // 清理测试数据
            if ($zhTranslation->getId()) {
                $zhTranslation->delete();
            }
            if ($enTranslation->getId()) {
                $enTranslation->delete();
            }
            if ($ruleId) {
                $rule->reset()->load($ruleId)->delete();
            }
        }
    }

    /**
     * 测试：关联主表ID字段验证
     * 
     * 验证 fields_ID 正确关联到 Rule 模型的主键
     */
    public function testMainTableIdField(): void
    {
        $this->assertEquals(Rule::schema_fields_ID, LocalDescription::schema_fields_ID);
        $this->assertEquals('id', LocalDescription::schema_fields_ID);
    }

    /**
     * 测试：复合主键处理
     * 
     * 验证模型正确处理 id + local_code 复合主键
     */
    public function testCompositePrimaryKey(): void
    {
        // 创建测试规则
        /** @var Rule $rule */
        $rule = ObjectManager::getInstance(Rule::class);
        $rule->setData([
            Rule::schema_fields_NAME => 'Composite Key Test',
            Rule::schema_fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
            Rule::schema_fields_STATUS => Rule::STATUS_ACTIVE
        ]);
        $rule->save();
        $ruleId = $rule->getId();

        try {
            // 保存第一条翻译记录
            /** @var LocalDescription $translation1 */
            $translation1 = ObjectManager::getInstance(LocalDescription::class);
            $translation1->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                LocalModelInterface::schema_fields_local_code => 'zh_Hans_CN',
                LocalDescription::schema_fields_NAME => '复合主键测试'
            ]);
            $translation1->save();
            $translation1Id = $translation1->getId();

            // 保存第二条翻译记录（相同规则ID，不同语言代码）
            /** @var LocalDescription $translation2 */
            $translation2 = ObjectManager::getInstance(LocalDescription::class);
            $translation2->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                LocalModelInterface::schema_fields_local_code => 'en_US',
                LocalDescription::schema_fields_NAME => 'Composite Key Test'
            ]);
            $translation2->save();
            $translation2Id = $translation2->getId();

            // 验证两条记录都能保存（复合主键允许）
            $this->assertNotNull($translation1Id);
            $this->assertNotNull($translation2Id);
            // 注意：由于复合主键，两条记录的ID可能相同（都是 ruleId），但 local_code 不同
            // 这里只验证它们都能保存成功

            // 验证可以通过复合主键查询
            $loaded1 = ObjectManager::getInstance(LocalDescription::class);
            $loaded1->reset()
                ->where(LocalDescription::schema_fields_ID, $ruleId)
                ->where(LocalModelInterface::schema_fields_local_code, 'zh_Hans_CN')
                ->find()
                ->fetch();
            
            $this->assertEquals('复合主键测试', $loaded1->getData(LocalDescription::schema_fields_NAME));

        } finally {
            // 清理测试数据
            if (isset($translation1) && $translation1->getId()) {
                $translation1->delete();
            }
            if (isset($translation2) && $translation2->getId()) {
                $translation2->delete();
            }
            if ($ruleId) {
                $rule->reset()->load($ruleId)->delete();
            }
        }
    }

    /**
     * 测试：更新翻译数据
     * 
     * 验证能够更新已存在的翻译记录
     */
    public function testUpdateTranslation(): void
    {
        // 创建测试规则
        /** @var Rule $rule */
        $rule = ObjectManager::getInstance(Rule::class);
        $rule->setData([
            Rule::schema_fields_NAME => 'Update Test',
            Rule::schema_fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
            Rule::schema_fields_STATUS => Rule::STATUS_ACTIVE
        ]);
        $rule->save();
        $ruleId = $rule->getId();

        try {
            // 创建翻译
            /** @var LocalDescription $translation */
            $translation = ObjectManager::getInstance(LocalDescription::class);
            $translation->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                LocalModelInterface::schema_fields_local_code => 'zh_Hans_CN',
                LocalDescription::schema_fields_NAME => '原始名称',
                LocalDescription::schema_fields_DESCRIPTION => '原始描述'
            ]);
            $translation->save();

            // 更新翻译
            $translation->setData(LocalDescription::schema_fields_NAME, '更新后的名称');
            $translation->setData(LocalDescription::schema_fields_DESCRIPTION, '更新后的描述');
            $translation->save();

            // 验证更新
            $updated = ObjectManager::getInstance(LocalDescription::class);
            $updated->reset()
                ->where(LocalDescription::schema_fields_ID, $ruleId)
                ->where(LocalModelInterface::schema_fields_local_code, 'zh_Hans_CN')
                ->find()
                ->fetch();

            $this->assertEquals('更新后的名称', $updated->getData(LocalDescription::schema_fields_NAME));
            $this->assertEquals('更新后的描述', $updated->getData(LocalDescription::schema_fields_DESCRIPTION));

        } finally {
            // 清理测试数据
            if (isset($translation) && $translation->getId()) {
                $translation->delete();
            }
            if ($ruleId) {
                $rule->reset()->load($ruleId)->delete();
            }
        }
    }

    /**
     * 测试：删除翻译数据
     * 
     * 验证能够删除翻译记录
     */
    public function testDeleteTranslation(): void
    {
        // 创建测试规则
        /** @var Rule $rule */
        $rule = ObjectManager::getInstance(Rule::class);
        $rule->setData([
            Rule::schema_fields_NAME => 'Delete Test',
            Rule::schema_fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
            Rule::schema_fields_STATUS => Rule::STATUS_ACTIVE
        ]);
        $rule->save();
        $ruleId = $rule->getId();

        try {
            // 创建翻译
            /** @var LocalDescription $translation */
            $translation = ObjectManager::getInstance(LocalDescription::class);
            $translation->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                LocalModelInterface::schema_fields_local_code => 'zh_Hans_CN',
                LocalDescription::schema_fields_NAME => '待删除的翻译'
            ]);
            $translation->save();
            $translationId = $translation->getId();

            // 删除翻译
            $translation->delete();

            // 验证删除
            $deleted = ObjectManager::getInstance(LocalDescription::class);
            $deleted->reset()
                ->where(LocalDescription::schema_fields_ID, $ruleId)
                ->where(LocalModelInterface::schema_fields_local_code, 'zh_Hans_CN')
                ->find()
                ->fetch();

            // find()->fetch() 在找不到记录时返回空模型对象，getId() 应为 null
            $this->assertNull($deleted->getId(), '翻译记录应已被删除');
            $this->assertEmpty($deleted->getData(), '翻译记录数据应为空');

        } finally {
            // 清理测试数据
            if ($ruleId) {
                $rule->reset()->load($ruleId)->delete();
            }
        }
    }
}

