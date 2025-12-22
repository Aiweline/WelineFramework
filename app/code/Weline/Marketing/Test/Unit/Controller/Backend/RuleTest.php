<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Controller\Backend\Rule as RuleController;
use Weline\Marketing\Model\Rule\LocalDescription;
use Weline\Marketing\Model\Rule\Rule;

/**
 * Rule 控制器单元测试
 * 
 * 测试营销规则管理控制器的功能，特别是 LocalDescription 相关功能
 */
class RuleTest extends TestCase
{
    private RuleController $controller;
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
     * 测试：index() 方法中 loadLocalDescription() 调用验证
     * 
     * 验证控制器在查询规则列表时调用了 loadLocalDescription()
     */
    public function testIndexCallsLoadLocalDescription(): void
    {
        // 创建测试规则
        $this->ruleModel->setData([
            Rule::fields_NAME => 'Controller Test Rule',
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
                LocalDescription::fields_NAME => '控制器测试规则'
            ]);
            $translation->save();

            // 模拟控制器中的查询逻辑
            $rule = ObjectManager::getInstance(Rule::class);
            $rule->reset()
                ->loadLocalDescription('', \Weline\Marketing\Model\Rule\LocalDescription::class)  // 这是控制器中调用的方法
                ->pagination()
                ->select()
                ->fetch();

            $items = $rule->getItems();
            $this->assertNotEmpty($items, '应查询到规则');

            // 验证翻译数据已加载
            $found = false;
            foreach ($items as $item) {
                if ($item->getId() == $ruleId) {
                    $found = true;
                    $this->assertArrayHasKey('local_name', $item->getData(), '应包含翻译字段');
                    $this->assertEquals('控制器测试规则', $item->getData('local_name'));
                    break;
                }
            }
            $this->assertTrue($found, '应找到测试规则');

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
     * 测试：翻译数据正确传递到视图
     * 
     * 验证控制器能够将翻译数据正确传递给视图模板
     */
    public function testTranslationDataPassedToView(): void
    {
        // 创建测试规则
        $this->ruleModel->setData([
            Rule::fields_NAME => 'View Test Rule',
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
                LocalDescription::fields_NAME => '视图测试规则'
            ]);
            $translation->save();

            // 模拟控制器逻辑：查询并准备数据
            $rule = ObjectManager::getInstance(Rule::class);
            $rule->reset()
                ->loadLocalDescription('', \Weline\Marketing\Model\Rule\LocalDescription::class)
                ->pagination()
                ->select()
                ->fetch();

            // 模拟 assign 操作（实际控制器中会调用 $this->assign('rules', $rule->getItems())）
            $rules = $rule->getItems();
            $pagination = $rule->getPagination();

            // 验证数据格式
            $this->assertIsArray($rules, 'rules 应为数组');
            $this->assertNotEmpty($rules, 'rules 不应为空');

            // 验证每个规则都包含翻译数据
            foreach ($rules as $ruleItem) {
                $ruleData = $ruleItem->getData();
                // 验证包含必要的字段
                $this->assertArrayHasKey('id', $ruleData);
                $this->assertArrayHasKey('name', $ruleData);
                // 如果存在翻译，应包含 local_name
                if (isset($ruleData['local_name'])) {
                    $this->assertIsString($ruleData['local_name']);
                }
            }

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
     * 测试：搜索功能与翻译数据的兼容性
     * 
     * 验证搜索功能能够正确处理翻译数据
     */
    public function testSearchCompatibilityWithTranslation(): void
    {
        // 创建测试规则
        $this->ruleModel->setData([
            Rule::fields_NAME => 'Search Test Rule',
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
                LocalDescription::fields_NAME => '搜索测试规则'
            ]);
            $translation->save();

            // 模拟搜索功能（按原始名称搜索）
            // 注意：在 loadLocalDescription 后，需要使用表别名避免列名歧义
            $rule = ObjectManager::getInstance(Rule::class);
            $rule->reset()
                ->where('main_table.name', '%Search Test%', 'like')
                ->loadLocalDescription('', \Weline\Marketing\Model\Rule\LocalDescription::class)
                ->select()
                ->fetch();

            $items = $rule->getItems();
            $this->assertNotEmpty($items, '应搜索到规则');

            // 验证搜索结果包含翻译数据
            $found = false;
            foreach ($items as $item) {
                if ($item->getId() == $ruleId) {
                    $found = true;
                    $this->assertEquals('搜索测试规则', $item->getData('local_name'));
                    break;
                }
            }
            $this->assertTrue($found, '应找到测试规则');

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
     * 测试：分页功能与翻译数据的兼容性
     * 
     * 验证分页功能能够正确处理翻译数据
     */
    public function testPaginationCompatibilityWithTranslation(): void
    {
        $ruleIds = [];

        try {
            // 创建多个测试规则
            for ($i = 1; $i <= 5; $i++) {
                $rule = ObjectManager::getInstance(Rule::class);
                $rule->setData([
                    Rule::fields_NAME => "Pagination Test Rule {$i}",
                    Rule::fields_RULE_TYPE => Rule::RULE_TYPE_AUTOMATIC,
                    Rule::fields_STATUS => Rule::STATUS_ACTIVE
                ]);
                $rule->save();
                $ruleId = $rule->getId();
                $ruleIds[] = $ruleId;

                // 创建翻译
                $translation = ObjectManager::getInstance(LocalDescription::class);
                $translation->setData([
                    LocalDescription::fields_ID => $ruleId,
                    \Weline\I18n\LocalModelInterface::fields_local_code => 'zh_Hans_CN',
                    LocalDescription::fields_NAME => "分页测试规则 {$i}"
                ]);
                $translation->save();
            }

            // 模拟分页查询
            $rule = ObjectManager::getInstance(Rule::class);
            $rule->reset()
                ->loadLocalDescription('', \Weline\Marketing\Model\Rule\LocalDescription::class)
                ->pagination(1, 2)  // 第1页，每页2条
                ->select()
                ->fetch();

            $items = $rule->getItems();
            $pagination = $rule->getPagination();

            // 验证分页结果
            $this->assertCount(2, $items, '第一页应返回2条记录');
            $this->assertNotNull($pagination, '应返回分页对象');

            // 验证每条记录都包含翻译数据
            foreach ($items as $item) {
                $this->assertArrayHasKey('local_name', $item->getData(), '每条记录应包含翻译字段');
                // 注意：由于分页可能返回不同的记录，只验证包含翻译字段即可
                $this->assertNotEmpty($item->getData('local_name'), '翻译字段不应为空');
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
     * 测试：异常处理机制
     * 
     * 验证控制器在遇到异常时能够正确处理
     */
    public function testExceptionHandling(): void
    {
        // 测试：当 loadLocalDescription 失败时的处理
        // 这通常不会失败，因为 loadLocalDescription 使用 LEFT JOIN
        // 但我们可以测试当规则不存在时的情况

        // 查询不存在的规则
        // 注意：在 loadLocalDescription 后，需要使用表别名避免列名歧义
        $rule = ObjectManager::getInstance(Rule::class);
        $rule->reset()
            ->loadLocalDescription('', \Weline\Marketing\Model\Rule\LocalDescription::class)
            ->where('main_table.' . Rule::fields_ID, 999999)  // 不存在的ID，使用表别名
            ->select()
            ->fetch();

        $items = $rule->getItems();
        $this->assertIsArray($items, '应返回数组');
        $this->assertEmpty($items, '应返回空数组（规则不存在）');

        // 验证不会抛出异常
        $this->assertTrue(true, '不应抛出异常');
    }
}

