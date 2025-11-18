<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/6 22:38:34
 */

namespace Weline\Eav\test;

use Weline\Eav\Helper\EavHelper;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Type\Value;
use Weline\Eav\Model\Test;
use Weline\Framework\App\Debug;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use function PHPUnit\Framework\assertTrue;

class EavTest extends TestCore
{
    private Test $test;
    private EavAttribute $attribute;
    private Value $value;
    private EavAttribute\Type $type;
    const multi_attr = 'test_multi';
    const multi_attr_has_option = 'test_multi_has_option';
    const single_attr = 'test_single';

    public function setUp(): void
    {
        parent::setUp();
        $this->type = ObjectManager::getInstance(EavAttribute\Type::class);
        $this->test = ObjectManager::getInstance(Test::class)->load(1);
        $this->attribute = ObjectManager::getInstance(EavAttribute::class);
        $this->value = ObjectManager::getInstance(Value::class);
        $setModel = ObjectManager::getInstance(EavAttribute\Set::class);
        $setModel->setCode('test')
            ->setEavEntityId($this->test->getId())
            ->setName('测试属性集')
            ->save(true);
        // 添加测试属性类型
        $typeCode = 'type_test';
        /**@var EavAttribute\Type $type */
        $this->type
            ->setCode($typeCode)
            ->setName('测试属性类型')
            ->setDefaultValue('test')
            ->setElement('input')
            ->setFieldLength(100)
            ->setFieldType('varchar')
            ->setFrontendAttrs('test="1"')
            ->setRequired(true)
            ->save(true);
        // 添加测试属性集
        /**@var EavAttribute\Set $setModel */
        $setModel = ObjectManager::getInstance(EavAttribute\Set::class);
        $setModel->setCode('test')
            ->setEavEntityId($this->test->getId())
            ->setName('测试属性集')
            ->save(true);
        // 添加测试属性组
        /**@var EavAttribute\Group $groupModel */
        $groupModel = ObjectManager::getInstance(EavAttribute\Group::class);
        $groupModel->setCode('test')
            ->setEavEntityId($this->test->getId())
            ->setSetId($setModel->getId())
            ->setName('测试属性组')
            ->save(true);
    }

    function testAddAttribute()
    {
        $this->test->unsetAttribute(self::single_attr);
        $assertion = $this->test->addAttribute(
            self::single_attr,
            '测试',
            'type_test',
            false,
            true,
            true,
            true,
            'test',
            'test');
        self::assertTrue($assertion, 'Eav添加属性测试');
    }

    function testAddMultiAttribute()
    {
        $this->test->unsetAttribute(self::multi_attr);
        $assertion = $this->test->addAttribute(
            self::multi_attr,
            '测试(多值属性)',
            'type_test',
            true,
            true,
            true,
            true,
            'test',
            'test'
        );
        self::assertTrue($assertion, 'Eav添加属性测试');
    }

    function testAddMultiAttributeHasOption()
    {
        $this->test->unsetAttribute(self::multi_attr_has_option);
        $assertion = $this->test->addAttribute(
            self::multi_attr_has_option,
            '测试(多值特定配置项属性)',
            'type_test',
            true,
            true,
            true,
            true,
            'test',
            'test'
        );
        self::assertTrue($assertion, 'Eav添加多值特定配置项属性测试');
    }

    function testGetAttribute()
    {
        $this->testAddAttribute();
        $result = $this->test->getAttribute(self::single_attr);
        self::assertTrue($result->getCode() === self::single_attr, '获取属性');
    }

    function testGetAttributes()
    {
        $this->testAddAttribute();
        $this->testAddMultiAttribute();
        $result = $this->test->getAttributes();
        self::assertIsArray($result, '获取所有属性');
    }

    function testSetAttribute()
    {
        $this->testAddAttribute();
        $attribute = $this->test->getAttribute(self::single_attr);
        $attribute->setName('测试修改属性名1');
        $this->test->setAttribute($attribute);
        $attribute = $this->test->getAttribute(self::single_attr);
        $assertion1 = $attribute->getName() == '测试修改属性名1';
        $this->testAddMultiAttribute();
        $attribute = $this->test->getAttribute(self::multi_attr);
        $attribute->setName('测试修改属性名(多值)');
        $this->test->setAttribute($attribute);
        $attribute = $this->test->getAttribute(self::multi_attr);
        $assertion2 = $attribute->getName() == '测试修改属性名(多值)';
        self::assertTrue($assertion1 && $assertion2, 'Eav设置属性测试');
    }

    function testSetSingleValueAttributeValue()
    {
        $this->testAddAttribute();
        $attribute = $this->test->getAttribute(self::single_attr);
        try {
            $attribute->setValue($this->test->getId(), 2);
            assertTrue(true, '设置单值属性值');
        } catch (Exception $e) {
            assertTrue(false, '设置单值属性值：' . $e->getMessage());
        }

    }

    function testSetMultiValueAttributeValue()
    {
        $this->testAddMultiAttribute();
        $attribute = $this->test->getAttribute(self::multi_attr);
        try {
            $attribute->setValue($this->test->getId(), [1, 3, 5]);
            assertTrue(true, '设置单值属性值');
        } catch (Exception $e) {
            assertTrue(false, '设置单值属性值：' . $e->getMessage());
        }

    }

    function testGetSingleValueAttributeValueByEntity()
    {
        $this->testAddAttribute();
        $this->testSetSingleValueAttributeValue();
        $attribute = $this->test->getAttribute(self::single_attr);
        self::assertTrue($attribute->getValue() == 2, '获取实体属性');
    }

    function testGetMultiValueAttributeValueByEntity()
    {
        $this->testAddMultiAttribute();
        $this->testSetMultiValueAttributeValue();
        $attribute = $this->test->getAttribute(self::multi_attr);
        self::assertTrue($attribute->getValue() == ['1', '3', '5'], '获取实体属性');
    }

    function testUnsetAttribute()
    {
        $this->testAddAttribute();
        $this->testAddMultiAttribute();
        $this->testSetSingleValueAttributeValue();
        $this->testSetMultiValueAttributeValue();
        $s1 = false;
        $s2 = false;
        $a1 = $this->test->getAttribute(self::single_attr);
        $a2 = $this->test->getAttribute(self::multi_attr);
        if (intval($a1->getValue()) == 2) {
            $s1 = true;
        }
        if ($a2->getValue() == [1, 3, 5]) {
            $s2 = true;
        }
        $this->testAddAttribute();
        $this->testAddMultiAttribute();
        $this->testSetSingleValueAttributeValue();
        $this->testSetMultiValueAttributeValue();
        $this->test->unsetAttribute(self::single_attr, true);
        $this->test->unsetAttribute(self::multi_attr, true);

        $a1 = $this->test->getAttribute(self::single_attr);
        $a2 = $this->test->getAttribute(self::multi_attr);
        if ($a1) {
            $s1 = false;
        }
        if ($a2) {
            $s1 = false;
        }

        assertTrue($s1 && $s2, '删除属性成功');
    }
}