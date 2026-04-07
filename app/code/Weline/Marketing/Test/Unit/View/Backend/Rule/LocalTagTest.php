<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Test\Unit\View\Backend\Rule;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Rule\LocalDescription;
use Weline\Marketing\Model\Rule\Rule;

/**
 * 视图模板 Local 标签测试
 * 
 * 测试规则列表视图中 <local> 标签的使用
 */
class LocalTagTest extends TestCase
{
    private Rule $ruleModel;
    private LocalDescription $localModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ruleModel = ObjectManager::getInstance(Rule::class);
        $this->localModel = ObjectManager::getInstance(LocalDescription::class);
        $this->ensureLocalDescriptionColumnsAvailable();
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        if ($this->ruleModel->getId()) {
            $this->localModel->reset()
                ->where(LocalDescription::schema_fields_ID, $this->ruleModel->getId())
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
     * 测试：模板变量数据准备
     * 
     * 验证视图模板所需的数据格式正确
     */
    public function testTemplateVariableDataPreparation(): void
    {
        // 创建测试规则
        $this->ruleModel->setData([
            Rule::schema_fields_NAME => 'Template Test Rule',
            Rule::schema_fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
            Rule::schema_fields_STATUS => Rule::STATUS_ACTIVE
        ]);
        $this->ruleModel->save();
        $ruleId = $this->ruleModel->getId();

        try {
            // 创建翻译
            $translation = ObjectManager::getInstance(LocalDescription::class);
            $translation->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                \Weline\I18n\LocalModelInterface::schema_fields_local_code => 'zh_Hans_CN',
                LocalDescription::schema_fields_NAME => '模板测试规则'
            ]);
            $translation->save();

            // 模拟控制器中的数据准备（加载翻译）
            // 注意：在 loadLocalDescription 后，不能使用 load()，需要使用 where() 和表别名
            $rule = ObjectManager::getInstance(Rule::class);
            $rule->reset()
                ->loadLocalDescription('zh_Hans_CN', LocalDescription::class)
                ->where('main_table.' . Rule::schema_fields_ID, $ruleId)
                ->find()
                ->fetch();

            // 转换为数组格式（视图模板中使用的格式）
            $ruleData = $rule->getData();

            // 验证数据格式符合模板要求
            $this->assertIsArray($ruleData, '数据应为数组格式');
            $this->assertArrayHasKey('id', $ruleData, '应包含 id 字段');
            $this->assertArrayHasKey('name', $ruleData, '应包含 name 字段');
            $this->assertArrayHasKey('local_name', $ruleData, '应包含 local_name 字段（翻译）');

            // 验证数据值
            $this->assertEquals($ruleId, $ruleData['id']);
            $this->assertEquals('Template Test Rule', $ruleData['name']);
            $this->assertEquals('模板测试规则', $ruleData['local_name']);

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

    /**
     * 测试：模板变量语法验证
     * 
     * 验证 {{rule.id}} 和 {{rule.local_name|rule.name}} 语法能够正确解析
     */
    public function testTemplateVariableSyntax(): void
    {
        // 创建测试规则
        $this->ruleModel->setData([
            Rule::schema_fields_NAME => 'Syntax Test Rule',
            Rule::schema_fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
            Rule::schema_fields_STATUS => Rule::STATUS_ACTIVE
        ]);
        $this->ruleModel->save();
        $ruleId = $this->ruleModel->getId();

        try {
            // 创建翻译
            $translation = ObjectManager::getInstance(LocalDescription::class);
            $translation->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                \Weline\I18n\LocalModelInterface::schema_fields_local_code => 'zh_Hans_CN',
                LocalDescription::schema_fields_NAME => '语法测试规则'
            ]);
            $translation->save();

            // 准备模板数据（模拟视图中的 $rule 变量）
            // 注意：在 loadLocalDescription 后，不能使用 load()，需要使用 where() 和表别名
            $rule = ObjectManager::getInstance(Rule::class);
            $rule->reset()
                ->loadLocalDescription('zh_Hans_CN', LocalDescription::class)
                ->where('main_table.' . Rule::schema_fields_ID, $ruleId)
                ->find()
                ->fetch();

            $ruleData = $rule->getData();

            // 模拟模板变量解析：{{rule.id}}
            $idValue = $ruleData['id'] ?? null;
            $this->assertEquals($ruleId, $idValue, '{{rule.id}} 应解析为规则ID');

            // 模拟模板变量解析：{{rule.local_name|rule.name}}
            // 优先使用 local_name，如果不存在则使用 name
            $nameValue = $ruleData['local_name'] ?? $ruleData['name'] ?? null;
            $this->assertEquals('语法测试规则', $nameValue, '应优先使用翻译后的名称');

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

    /**
     * 测试：翻译数据正确显示
     * 
     * 验证翻译数据能够正确显示在视图中
     */
    public function testTranslationDataDisplay(): void
    {
        // 创建测试规则
        $this->ruleModel->setData([
            Rule::schema_fields_NAME => 'Display Test Rule',
            Rule::schema_fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
            Rule::schema_fields_STATUS => Rule::STATUS_ACTIVE
        ]);
        $this->ruleModel->save();
        $ruleId = $this->ruleModel->getId();

        try {
            // 创建翻译
            $translation = ObjectManager::getInstance(LocalDescription::class);
            $translation->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                \Weline\I18n\LocalModelInterface::schema_fields_local_code => 'zh_Hans_CN',
                LocalDescription::schema_fields_NAME => '显示测试规则'
            ]);
            $translation->save();

            // 模拟视图中的数据
            // 注意：在 loadLocalDescription 后，不能使用 load()，需要使用 where() 和表别名
            $rule = ObjectManager::getInstance(Rule::class);
            $rule->reset()
                ->loadLocalDescription('zh_Hans_CN', LocalDescription::class)
                ->where('main_table.' . Rule::schema_fields_ID, $ruleId)
                ->find()
                ->fetch();

            $ruleData = $rule->getData();

            // 验证显示数据
            $displayName = $ruleData['local_name'] ?? $ruleData['name'] ?? '';
            $this->assertEquals('显示测试规则', $displayName, '应显示翻译后的名称');

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

    /**
     * 测试：翻译不存在时的回退显示
     * 
     * 验证当翻译不存在时，能够回退显示原始名称
     */
    public function testFallbackDisplayWhenTranslationNotExists(): void
    {
        // 创建测试规则（不创建翻译）
        $this->ruleModel->setData([
            Rule::schema_fields_NAME => 'Fallback Display Test',
            Rule::schema_fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
            Rule::schema_fields_STATUS => Rule::STATUS_ACTIVE
        ]);
        $this->ruleModel->save();
        $ruleId = $this->ruleModel->getId();

        try {
            // 模拟视图中的数据（尝试加载翻译，但翻译不存在）
            // 注意：在 loadLocalDescription 后，不能使用 load()，需要使用 where() 和表别名
            $rule = ObjectManager::getInstance(Rule::class);
            $rule->reset()
                ->loadLocalDescription('zh_Hans_CN', LocalDescription::class)
                ->where('main_table.' . Rule::schema_fields_ID, $ruleId)
                ->find()
                ->fetch();

            $ruleData = $rule->getData();

            // 验证回退机制：{{rule.local_name|rule.name}}
            $displayName = $ruleData['local_name'] ?? $ruleData['name'] ?? '';
            $this->assertEquals('Fallback Display Test', $displayName, '翻译不存在时应回退到原始名称');

        } finally {
            // 清理测试数据
            if ($ruleId) {
                $this->ruleModel->reset()->load($ruleId)->delete();
            }
        }
    }

    /**
     * 测试：多语言切换功能
     * 
     * 验证能够根据语言代码切换显示不同的翻译
     */
    public function testMultiLanguageSwitch(): void
    {
        // 创建测试规则
        $this->ruleModel->setData([
            Rule::schema_fields_NAME => 'Language Switch Test',
            Rule::schema_fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
            Rule::schema_fields_STATUS => Rule::STATUS_ACTIVE
        ]);
        $this->ruleModel->save();
        $ruleId = $this->ruleModel->getId();

        try {
            // 创建中文翻译
            $zhTranslation = ObjectManager::getInstance(LocalDescription::class);
            $zhTranslation->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                \Weline\I18n\LocalModelInterface::schema_fields_local_code => 'zh_Hans_CN',
                LocalDescription::schema_fields_NAME => '语言切换测试'
            ]);
            $zhTranslation->save();

            // 创建英文翻译
            $enTranslation = ObjectManager::getInstance(LocalDescription::class);
            $enTranslation->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                \Weline\I18n\LocalModelInterface::schema_fields_local_code => 'en_US',
                LocalDescription::schema_fields_NAME => 'Language Switch Test'
            ]);
            $enTranslation->save();

            // 测试中文显示
            // 注意：在 loadLocalDescription 后，不能使用 load()，需要使用 where() 和表别名
            $ruleZh = ObjectManager::getInstance(Rule::class);
            $ruleZh->reset()
                ->loadLocalDescription('zh_Hans_CN', LocalDescription::class)
                ->where('main_table.' . Rule::schema_fields_ID, $ruleId)
                ->find()
                ->fetch();
            $ruleZhData = $ruleZh->getData();
            $this->assertEquals('语言切换测试', $ruleZhData['local_name'] ?? '', '应显示中文翻译');

            // 测试英文显示
            $ruleEn = ObjectManager::getInstance(Rule::class);
            $ruleEn->reset()
                ->loadLocalDescription('en_US', LocalDescription::class)
                ->where('main_table.' . Rule::schema_fields_ID, $ruleId)
                ->find()
                ->fetch();
            $ruleEnData = $ruleEn->getData();
            $this->assertEquals('Language Switch Test', $ruleEnData['local_name'] ?? '', '应显示英文翻译');

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
     * 测试：列表视图数据格式
     * 
     * 验证列表视图中多个规则的数据格式正确
     */
    public function testListViewModelDataFormat(): void
    {
        $ruleIds = [];

        try {
            // 创建多个测试规则
            for ($i = 1; $i <= 3; $i++) {
                $rule = ObjectManager::make(Rule::class);
                $rule->setData([
                    Rule::schema_fields_NAME => "List Test Rule {$i}",
                    Rule::schema_fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
                    Rule::schema_fields_STATUS => Rule::STATUS_ACTIVE
                ]);
                $rule->save();
                $ruleId = $rule->getId();
                $ruleIds[] = $ruleId;

                // 创建翻译
                $translation = ObjectManager::getInstance(LocalDescription::class);
                $translation->setData([
                    LocalDescription::schema_fields_ID => $ruleId,
                    \Weline\I18n\LocalModelInterface::schema_fields_local_code => 'zh_Hans_CN',
                    LocalDescription::schema_fields_NAME => "列表测试规则 {$i}"
                ]);
                $translation->save();
            }

            // 模拟列表查询
            // 注意：在 loadLocalDescription 后，需要使用表别名避免列名歧义
            $rules = ObjectManager::getInstance(Rule::class);
            $rules->reset()
                ->loadLocalDescription('zh_Hans_CN', LocalDescription::class)
                ->where('main_table.' . Rule::schema_fields_ID, $ruleIds, 'IN')
                ->select()
                ->fetch();

            $items = $rules->getItems();

            // 验证列表数据格式
            $this->assertIsArray($items, '应返回数组');
            $this->assertCount(3, $items, '应返回3条记录');

            // 验证每条记录的数据格式
            foreach ($items as $item) {
                $data = $item->getData();
                $this->assertArrayHasKey('id', $data, '每条记录应包含 id');
                $this->assertArrayHasKey('name', $data, '每条记录应包含 name');
                $this->assertArrayHasKey('local_name', $data, '每条记录应包含 local_name');
                $this->assertStringContainsString('列表测试规则', $data['local_name']);
            }

        } finally {
            // 清理测试数据
            foreach ($ruleIds as $ruleId) {
                $this->localModel->reset()
                    ->where(LocalDescription::schema_fields_ID, $ruleId)
                    ->select()
                    ->fetch()
                    ->walk(function ($item) {
                        $item->delete();
                    });
                $this->ruleModel->reset()->load($ruleId)->delete();
            }
        }
    }

    private function ensureLocalDescriptionColumnsAvailable(): void
    {
        try {
            $connector = $this->localModel->getConnection()->getConnector();
            $table = $this->localModel->getTable();
            if (!$connector->hasField($table, LocalDescription::schema_fields_NAME)
                || !$connector->hasField($table, LocalDescription::schema_fields_DESCRIPTION)) {
                $this->markTestSkipped('Marketing local description schema is missing name/description columns in the current database.');
            }
        } catch (\Throwable) {
            $this->markTestSkipped('Unable to verify marketing local description schema in the current database.');
        }
    }
}

