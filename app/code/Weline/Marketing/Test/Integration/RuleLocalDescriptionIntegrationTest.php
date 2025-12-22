<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Rule\LocalDescription;
use Weline\Marketing\Model\Rule\Rule;

/**
 * Rule LocalDescription 集成测试
 * 
 * 测试规则名称多语言翻译功能的完整流程
 */
class RuleLocalDescriptionIntegrationTest extends TestCase
{
    private Rule $ruleModel;
    private LocalDescription $localModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ruleModel = ObjectManager::getInstance(Rule::class);
        $this->localModel = ObjectManager::getInstance(LocalDescription::class);
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        if ($this->ruleModel->getId()) {
            $this->localModel->reset()
                ->where(LocalDescription::fields_ID, $this->ruleModel->getId())
                ->select()
                ->fetch()
                ->walk(function ($item) {
                    $item->delete();
                });
            $this->ruleModel->delete();
        }
        parent::tearDown();
    }

    /**
     * 测试：完整流程 - 创建规则 → 添加翻译 → 查询显示
     * 
     * 验证从创建规则到显示翻译的完整流程
     */
    public function testCompleteFlowCreateRuleAddTranslationQueryDisplay(): void
    {
        // 步骤1：创建规则
        $this->ruleModel->setData([
            Rule::fields_NAME => 'Complete Flow Test Rule',
            Rule::fields_DESCRIPTION => 'Complete Flow Test Description',
            Rule::fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
            Rule::fields_STATUS => Rule::STATUS_ACTIVE,
            Rule::fields_PRIORITY => 10
        ]);
        $this->ruleModel->save();
        $ruleId = $this->ruleModel->getId();

        $this->assertNotNull($ruleId, '规则应创建成功');

        try {
            // 步骤2：添加中文翻译
            $zhTranslation = ObjectManager::getInstance(LocalDescription::class);
            $zhTranslation->setData([
                LocalDescription::fields_ID => $ruleId,
                \Weline\I18n\LocalModelInterface::fields_local_code => 'zh_Hans_CN',
                LocalDescription::fields_NAME => '完整流程测试规则',
                LocalDescription::fields_DESCRIPTION => '完整流程测试描述'
            ]);
            $zhTranslation->save();
            $zhTranslationId = $zhTranslation->getId();

            $this->assertNotNull($zhTranslationId, '中文翻译应保存成功');

            // 步骤3：添加英文翻译
            $enTranslation = ObjectManager::getInstance(LocalDescription::class);
            $enTranslation->setData([
                LocalDescription::fields_ID => $ruleId,
                \Weline\I18n\LocalModelInterface::fields_local_code => 'en_US',
                LocalDescription::fields_NAME => 'Complete Flow Test Rule',
                LocalDescription::fields_DESCRIPTION => 'Complete Flow Test Description'
            ]);
            $enTranslation->save();
            $enTranslationId = $enTranslation->getId();

            $this->assertNotNull($enTranslationId, '英文翻译应保存成功');

            // 步骤4：查询并显示中文翻译
            // 注意：在 loadLocalDescription 后，不能使用 load()，需要使用 where() 和表别名
            $ruleZh = ObjectManager::getInstance(Rule::class);
            $ruleZh->reset()
                ->loadLocalDescription('zh_Hans_CN', LocalDescription::class)
                ->where('main_table.' . Rule::fields_ID, $ruleId)
                ->find()
                ->fetch();

            $this->assertEquals('完整流程测试规则', $ruleZh->getData('local_name'));
            $this->assertEquals('完整流程测试描述', $ruleZh->getData('local_description'));

            // 步骤5：查询并显示英文翻译
            $ruleEn = ObjectManager::getInstance(Rule::class);
            $ruleEn->reset()
                ->loadLocalDescription('en_US', LocalDescription::class)
                ->where('main_table.' . Rule::fields_ID, $ruleId)
                ->find()
                ->fetch();

            $this->assertEquals('Complete Flow Test Rule', $ruleEn->getData('local_name'));
            $this->assertEquals('Complete Flow Test Description', $ruleEn->getData('local_description'));

        } finally {
            // 清理测试数据
            if (isset($zhTranslation) && $zhTranslation->getId()) {
                $zhTranslation->delete();
            }
            if (isset($enTranslation) && $enTranslation->getId()) {
                $enTranslation->delete();
            }
            if ($ruleId) {
                $this->ruleModel->reset()->load($ruleId)->delete();
            }
        }
    }

    /**
     * 测试：多语言环境下的规则列表显示
     * 
     * 验证在不同语言环境下，规则列表能够正确显示对应的翻译
     */
    public function testRuleListDisplayInMultiLanguageEnvironment(): void
    {
        $ruleIds = [];

        try {
            // 创建多个测试规则
            for ($i = 1; $i <= 3; $i++) {
                $rule = ObjectManager::getInstance(Rule::class);
                $rule->setData([
                    Rule::fields_NAME => "Multi Language Rule {$i}",
                    Rule::fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
                    Rule::fields_STATUS => Rule::STATUS_ACTIVE
                ]);
                $rule->save();
                $ruleId = $rule->getId();
                $ruleIds[] = $ruleId;

                // 创建中文翻译
                $zhTranslation = ObjectManager::getInstance(LocalDescription::class);
                $zhTranslation->setData([
                    LocalDescription::fields_ID => $ruleId,
                    \Weline\I18n\LocalModelInterface::fields_local_code => 'zh_Hans_CN',
                    LocalDescription::fields_NAME => "多语言规则 {$i}"
                ]);
                $zhTranslation->save();

                // 创建英文翻译
                $enTranslation = ObjectManager::getInstance(LocalDescription::class);
                $enTranslation->setData([
                    LocalDescription::fields_ID => $ruleId,
                    \Weline\I18n\LocalModelInterface::fields_local_code => 'en_US',
                    LocalDescription::fields_NAME => "Multi Language Rule {$i}"
                ]);
                $enTranslation->save();
            }

            // 测试中文环境下的列表显示
            // 注意：在 loadLocalDescription 后，需要使用表别名避免列名歧义
            $rulesZh = ObjectManager::getInstance(Rule::class);
            $rulesZh->reset()
                ->loadLocalDescription('zh_Hans_CN', LocalDescription::class)
                ->where('main_table.' . Rule::fields_ID, $ruleIds, 'IN')
                ->select()
                ->fetch();

            $itemsZh = $rulesZh->getItems();
            $this->assertCount(3, $itemsZh, '应查询到3条规则');

            foreach ($itemsZh as $item) {
                $this->assertStringContainsString('多语言规则', $item->getData('local_name'));
            }

            // 测试英文环境下的列表显示
            // 注意：在 loadLocalDescription 后，需要使用表别名避免列名歧义
            $rulesEn = ObjectManager::getInstance(Rule::class);
            $rulesEn->reset()
                ->loadLocalDescription('en_US', LocalDescription::class)
                ->where('main_table.' . Rule::fields_ID, $ruleIds, 'IN')
                ->select()
                ->fetch();

            $itemsEn = $rulesEn->getItems();
            $this->assertCount(3, $itemsEn, '应查询到3条规则');

            foreach ($itemsEn as $item) {
                $this->assertStringContainsString('Multi Language Rule', $item->getData('local_name'));
            }

        } finally {
            // 清理测试数据
            foreach ($ruleIds as $ruleId) {
                $this->localModel->reset()
                    ->where(LocalDescription::fields_ID, $ruleId)
                    ->select()
                    ->fetch()
                    ->walk(function ($item) {
                        $item->delete();
                    });
                $this->ruleModel->reset()->load($ruleId)->delete();
            }
        }
    }

    /**
     * 测试：翻译数据的增删改查完整流程
     * 
     * 验证翻译数据的完整 CRUD 操作
     */
    public function testTranslationDataCrudOperations(): void
    {
        // 创建测试规则
        $this->ruleModel->setData([
            Rule::fields_NAME => 'CRUD Test Rule',
            Rule::fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
            Rule::fields_STATUS => Rule::STATUS_ACTIVE
        ]);
        $this->ruleModel->save();
        $ruleId = $this->ruleModel->getId();

        try {
            // CREATE: 创建翻译
            $translation = ObjectManager::getInstance(LocalDescription::class);
            $translation->setData([
                LocalDescription::fields_ID => $ruleId,
                \Weline\I18n\LocalModelInterface::fields_local_code => 'zh_Hans_CN',
                LocalDescription::fields_NAME => 'CRUD测试规则',
                LocalDescription::fields_DESCRIPTION => 'CRUD测试描述'
            ]);
            $translation->save();
            $translationId = $translation->getId();

            $this->assertNotNull($translationId, '翻译应创建成功');

            // READ: 读取翻译
            $loaded = ObjectManager::getInstance(LocalDescription::class);
            $loaded->reset()
                ->where(LocalDescription::fields_ID, $ruleId)
                ->where(\Weline\I18n\LocalModelInterface::fields_local_code, 'zh_Hans_CN')
                ->find()
                ->fetch();

            $this->assertEquals('CRUD测试规则', $loaded->getData(LocalDescription::fields_NAME));
            $this->assertEquals('CRUD测试描述', $loaded->getData(LocalDescription::fields_DESCRIPTION));

            // UPDATE: 更新翻译
            $loaded->setData(LocalDescription::fields_NAME, 'CRUD更新测试规则');
            $loaded->setData(LocalDescription::fields_DESCRIPTION, 'CRUD更新测试描述');
            $loaded->save();

            $updated = ObjectManager::getInstance(LocalDescription::class);
            $updated->reset()
                ->where(LocalDescription::fields_ID, $ruleId)
                ->where(\Weline\I18n\LocalModelInterface::fields_local_code, 'zh_Hans_CN')
                ->find()
                ->fetch();

            $this->assertEquals('CRUD更新测试规则', $updated->getData(LocalDescription::fields_NAME));
            $this->assertEquals('CRUD更新测试描述', $updated->getData(LocalDescription::fields_DESCRIPTION));

            // DELETE: 删除翻译
            $updated->delete();

            $deleted = ObjectManager::getInstance(LocalDescription::class);
            $deleted->reset()
                ->where(LocalDescription::fields_ID, $ruleId)
                ->where(\Weline\I18n\LocalModelInterface::fields_local_code, 'zh_Hans_CN')
                ->find()
                ->fetch();

            // find()->fetch() 在找不到记录时返回空模型对象，getId() 应为 null
            $this->assertNull($deleted->getId(), '翻译应已删除');
            $this->assertEmpty($deleted->getData(), '翻译数据应为空');

        } finally {
            // 清理测试数据
            if ($ruleId) {
                $this->ruleModel->reset()->load($ruleId)->delete();
            }
        }
    }

    /**
     * 测试：数据一致性验证
     * 
     * 验证规则和翻译数据的一致性
     */
    public function testDataConsistency(): void
    {
        // 创建测试规则
        $this->ruleModel->setData([
            Rule::fields_NAME => 'Consistency Test Rule',
            Rule::fields_DESCRIPTION => 'Consistency Test Description',
            Rule::fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
            Rule::fields_STATUS => Rule::STATUS_ACTIVE
        ]);
        $this->ruleModel->save();
        $ruleId = $this->ruleModel->getId();

        try {
            // 创建翻译
            $translation = ObjectManager::getInstance(LocalDescription::class);
            $translation->setData([
                LocalDescription::fields_ID => $ruleId,
                \Weline\I18n\LocalModelInterface::fields_local_code => 'zh_Hans_CN',
                LocalDescription::fields_NAME => '一致性测试规则',
                LocalDescription::fields_DESCRIPTION => '一致性测试描述'
            ]);
            $translation->save();

            // 验证规则ID一致性
            $this->assertEquals($ruleId, $translation->getData(LocalDescription::fields_ID), '规则ID应一致');

            // 验证通过 loadLocalDescription 加载的数据一致性
            // 注意：在 loadLocalDescription 后，不能使用 load()，需要使用 where() 和表别名
            $rule = ObjectManager::getInstance(Rule::class);
            $rule->reset()
                ->loadLocalDescription('zh_Hans_CN', LocalDescription::class)
                ->where('main_table.' . Rule::fields_ID, $ruleId)
                ->find()
                ->fetch();

            $this->assertEquals($ruleId, $rule->getId(), '规则ID应一致');
            $this->assertEquals('Consistency Test Rule', $rule->getData(Rule::fields_NAME), '原始名称应一致');
            $this->assertEquals('一致性测试规则', $rule->getData('local_name'), '翻译名称应一致');

            // 验证删除规则时，翻译数据也应被处理（外键约束或级联删除）
            // 注意：这取决于数据库配置，这里只验证翻译数据存在
            $translationExists = ObjectManager::getInstance(LocalDescription::class);
            $translationExists->reset()
                ->where(LocalDescription::fields_ID, $ruleId)
                ->where(\Weline\I18n\LocalModelInterface::fields_local_code, 'zh_Hans_CN')
                ->find()
                ->fetch();

            $this->assertNotNull($translationExists->getId(), '翻译数据应存在');

        } finally {
            // 清理测试数据
            if (isset($translation) && $translation->getId()) {
                $translation->delete();
            }
            if ($ruleId) {
                $this->ruleModel->reset()->load($ruleId)->delete();
            }
        }
    }
}

