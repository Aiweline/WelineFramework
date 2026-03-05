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
use Weline\Marketing\Model\Rule\LocalDescription;
use Weline\Marketing\Model\Rule\Rule;

/**
 * Rule 模型 LocalDescription 集成测试
 * 
 * 测试 Rule 模型与 LocalDescription 的集成功能
 */
class RuleLocalDescriptionTest extends TestCase
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
            // 删除关联的翻译数据
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
     * 测试：loadLocalDescription() 方法功能验证
     * 
     * 验证 Rule 模型能够正确加载 LocalDescription 翻译数据
     */
    public function testLoadLocalDescription(): void
    {
        // 创建测试规则
        $this->ruleModel->setData([
            Rule::schema_fields_NAME => 'Load Test Rule',
            Rule::schema_fields_DESCRIPTION => 'Load Test Description',
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
                LocalDescription::schema_fields_NAME => '加载测试规则',
                LocalDescription::schema_fields_DESCRIPTION => '加载测试描述'
            ]);
            $zhTranslation->save();

            // 使用 loadLocalDescription 加载翻译
            // 注意：在 loadLocalDescription 后，不能使用 load()，需要使用 where() 和表别名
            $loadedRule = ObjectManager::getInstance(Rule::class);
            $loadedRule->reset()
                ->loadLocalDescription('', LocalDescription::class)
                ->where('main_table.' . Rule::schema_fields_ID, $ruleId)
                ->find()
                ->fetch();

            // 验证翻译数据已加载
            $this->assertNotNull($loadedRule->getData('local_name'), '应加载翻译后的名称');
            $this->assertEquals('加载测试规则', $loadedRule->getData('local_name'));

        } finally {
            // 清理测试数据
            if ($zhTranslation->getId()) {
                $zhTranslation->delete();
            }
            if ($ruleId) {
                $this->ruleModel->reset()->load($ruleId)->delete();
            }
        }
    }

    /**
     * 测试：翻译数据自动加载
     * 
     * 验证在查询规则列表时，翻译数据能够自动加载
     */
    public function testAutoLoadTranslationInList(): void
    {
        // 创建测试规则
        $this->ruleModel->setData([
            Rule::schema_fields_NAME => 'Auto Load Test',
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
                LocalDescription::schema_fields_NAME => '自动加载测试'
            ]);
            $translation->save();

            // 使用 loadLocalDescription 查询列表
            $rules = ObjectManager::getInstance(Rule::class);
            $rules->reset()
                ->loadLocalDescription('', LocalDescription::class)
                ->where(Rule::schema_fields_ID, $ruleId)
                ->select()
                ->fetch();

            $items = $rules->getItems();
            $this->assertNotEmpty($items, '应查询到规则');

            $firstRule = $items[0];
            $this->assertArrayHasKey('local_name', $firstRule->getData(), '应包含翻译字段');
            $this->assertEquals('自动加载测试', $firstRule->getData('local_name'));

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
     * 测试：多语言字段合并到主模型
     * 
     * 验证翻译字段能够正确合并到主模型数据中
     */
    public function testMergeTranslationFieldsToMainModel(): void
    {
        // 创建测试规则
        $this->ruleModel->setData([
            Rule::schema_fields_NAME => 'Merge Test',
            Rule::schema_fields_DESCRIPTION => 'Merge Description',
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
                LocalDescription::schema_fields_NAME => '合并测试',
                LocalDescription::schema_fields_DESCRIPTION => '合并描述'
            ]);
            $translation->save();

            // 加载规则并合并翻译
            // 注意：在 loadLocalDescription 后，不能使用 load()，需要使用 where() 和表别名
            $rule = ObjectManager::getInstance(Rule::class);
            $rule->reset()
                ->loadLocalDescription('', LocalDescription::class)
                ->where('main_table.' . Rule::schema_fields_ID, $ruleId)
                ->find()
                ->fetch();

            // 验证原始字段仍然存在
            $this->assertEquals('Merge Test', $rule->getData(Rule::schema_fields_NAME));
            $this->assertEquals('Merge Description', $rule->getData(Rule::schema_fields_DESCRIPTION));

            // 验证翻译字段已合并
            $this->assertEquals('合并测试', $rule->getData('local_name'));
            $this->assertEquals('合并描述', $rule->getData('local_description'));

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
     * 测试：不同语言代码的翻译切换
     * 
     * 验证能够根据不同的语言代码加载对应的翻译
     */
    public function testSwitchTranslationByLanguageCode(): void
    {
        // 创建测试规则
        $this->ruleModel->setData([
            Rule::schema_fields_NAME => 'Switch Test',
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
                LocalDescription::schema_fields_NAME => '切换测试'
            ]);
            $zhTranslation->save();

            // 创建英文翻译
            $enTranslation = ObjectManager::getInstance(LocalDescription::class);
            $enTranslation->setData([
                LocalDescription::schema_fields_ID => $ruleId,
                \Weline\I18n\LocalModelInterface::schema_fields_local_code => 'en_US',
                LocalDescription::schema_fields_NAME => 'Switch Test'
            ]);
            $enTranslation->save();

            // 加载中文翻译
            // 注意：在 loadLocalDescription 后，不能使用 load()，需要使用 where() 和表别名
            $ruleZh = ObjectManager::getInstance(Rule::class);
            $ruleZh->reset()
                ->loadLocalDescription('zh_Hans_CN', LocalDescription::class)
                ->where('main_table.' . Rule::schema_fields_ID, $ruleId)
                ->find()
                ->fetch();

            $this->assertEquals('切换测试', $ruleZh->getData('local_name'));

            // 加载英文翻译
            $ruleEn = ObjectManager::getInstance(Rule::class);
            $ruleEn->reset()
                ->loadLocalDescription('en_US', LocalDescription::class)
                ->where('main_table.' . Rule::schema_fields_ID, $ruleId)
                ->find()
                ->fetch();

            $this->assertEquals('Switch Test', $ruleEn->getData('local_name'));

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
     * 测试：翻译不存在时的回退机制
     * 
     * 验证当翻译不存在时，能够回退到原始字段值
     */
    public function testFallbackWhenTranslationNotExists(): void
    {
        // 创建测试规则
        $this->ruleModel->setData([
            Rule::schema_fields_NAME => 'Fallback Test',
            Rule::schema_fields_DESCRIPTION => 'Fallback Description',
            Rule::schema_fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
            Rule::schema_fields_STATUS => Rule::STATUS_ACTIVE
        ]);
        $this->ruleModel->save();
        $ruleId = $this->ruleModel->getId();

        try {
            // 不创建翻译，直接加载
            // 注意：在 loadLocalDescription 后，不能使用 load()，需要使用 where() 和表别名
            $rule = ObjectManager::getInstance(Rule::class);
            $rule->reset()
                ->loadLocalDescription('zh_Hans_CN', LocalDescription::class)
                ->where('main_table.' . Rule::schema_fields_ID, $ruleId)
                ->find()
                ->fetch();

            // 验证原始字段仍然可用
            $this->assertEquals('Fallback Test', $rule->getData(Rule::schema_fields_NAME));
            $this->assertEquals('Fallback Description', $rule->getData(Rule::schema_fields_DESCRIPTION));

            // 验证翻译字段为空或不存在（取决于框架实现）
            $localName = $rule->getData('local_name');
            // 如果框架实现为 null，则验证为 null；如果为空字符串，则验证为空字符串
            $this->assertTrue(
                $localName === null || $localName === '',
                '翻译不存在时，local_name 应为空'
            );

        } finally {
            // 清理测试数据
            if ($ruleId) {
                $this->ruleModel->reset()->load($ruleId)->delete();
            }
        }
    }

    /**
     * 测试：多个规则同时加载翻译
     * 
     * 验证在查询多个规则时，能够同时加载各自的翻译
     */
    public function testLoadMultipleRulesWithTranslations(): void
    {
        $ruleIds = [];

        try {
            // 创建多个测试规则
            for ($i = 1; $i <= 3; $i++) {
                $rule = ObjectManager::getInstance(Rule::class);
                $rule->setData([
                    Rule::schema_fields_NAME => "Multi Load Test {$i}",
                    Rule::schema_fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
                    Rule::schema_fields_STATUS => Rule::STATUS_ACTIVE
                ]);
                $rule->save();
                $ruleId = $rule->getId();
                $ruleIds[] = $ruleId;

                // 为每个规则创建翻译
                $translation = ObjectManager::getInstance(LocalDescription::class);
                $translation->setData([
                    LocalDescription::schema_fields_ID => $ruleId,
                    \Weline\I18n\LocalModelInterface::schema_fields_local_code => 'zh_Hans_CN',
                    LocalDescription::schema_fields_NAME => "多规则加载测试 {$i}"
                ]);
                $translation->save();
            }

            // 查询所有规则并加载翻译
            $rules = ObjectManager::getInstance(Rule::class);
            $rules->reset()
                ->loadLocalDescription('zh_Hans_CN', LocalDescription::class)
                ->where(Rule::schema_fields_ID, $ruleIds, 'IN')
                ->select()
                ->fetch();

            $items = $rules->getItems();
            $this->assertCount(3, $items, '应查询到3个规则');

            // 验证每个规则都有翻译
            foreach ($items as $item) {
                $this->assertNotNull($item->getData('local_name'), '每个规则都应加载翻译');
                $this->assertStringContainsString('多规则加载测试', $item->getData('local_name'));
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
}

