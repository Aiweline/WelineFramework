<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/24 21:51:06
 */

namespace Weline\Eav\test;

use Weline\Eav\Model\EavEntity;
use Weline\Framework\Manager\ObjectManager;

class EntityTest extends \Weline\Framework\UnitTest\TestCore
{
    // 注意：PHPUnit 10.x 中 setName() 是 final 方法，不能覆盖
    // 如果需要设置测试名称，请使用 @test 注释或测试方法的名称

    private EavEntity $eavEntity;

    function setUp(): void
    {
        parent::setUp();
        $this->eavEntity = ObjectManager::getInstance(EavEntity::class);
    }

    function testCreateEntity()
    {
        $this->eavEntity->clear()
            ->setData(
                [
                    EavEntity::fields_code => 'test',
                    EavEntity::fields_class => 'Weline\Test\Model\Test',
                    EavEntity::fields_name => '测试实体',
                    EavEntity::fields_is_system => '1',
                    EavEntity::fields_eav_entity_id_field_type => 'integer',
                    EavEntity::fields_eav_entity_id_field_length => 11,
                ]
            )
            ->forceCheck(true, EavEntity::fields_code)
            ->save();
        $entity = $this->eavEntity->loadByCode('test');
        self::assertTrue($entity->getClass() == 'Weline\Test\Model\Test', '添加实体');
    }
}