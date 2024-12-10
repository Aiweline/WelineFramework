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
    function setName(string $name = '测试实体操作'): void
    {
        parent::setName($name);
    }

    private EavEntity $eavEntity;

    function setUp(): void
    {
        parent::setUp();
        $this->eavEntity = ObjectManager::getInstance(EavEntity::class);
    }

    function testCreateEntity()
    {
        $result = $this->eavEntity->clear()
                                  ->setData(
                                      [
                                          EavEntity::fields_code                   => 'product',
                                          EavEntity::fields_class                  => 'Aiweline\WebStore\Model\Product',
                                          EavEntity::fields_name                   => '产品实体',
                                          EavEntity::fields_is_system              => '1',
                                          EavEntity::fields_eav_entity_id_field_type   => 'integer',
                                          EavEntity::fields_eav_entity_id_field_length => 11,
                                      ]
                                  )
                                  ->forceCheck(true, EavEntity::fields_code)
                                  ->save();
        self::assertTrue($result,'添加实体');
    }

    function testDeleteEntityAttribute()
    {
        $entity = $this->eavEntity->loadByCode('queue');
        $valueModel = $entity->getAttribute('op_source_site_id')->w_getValueModel();
        dd($valueModel);
    }
}