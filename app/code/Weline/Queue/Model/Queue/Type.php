<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：11/7/2023 09:47:54
 */

namespace Weline\Queue\Model\Queue;

use JetBrains\PhpStorm\NoReturn;
use Weline\Eav\Model\EavAttribute;
use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Queue\Model\Queue\Type\Attributes;

class Type extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'type_id';
    public const fields_name = 'name';
    public const fields_tip = 'tip';
    public const fields_module_name = 'module_name';
    public const fields_class = 'class';
    public const fields_attributes = 'attributes';
    public const fields_enable = 'enable';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    #[NoReturn] public function upgrade(ModelSetup $setup, Context $context): void
    {
        $this->backeup();
        if ($context->getVersion() >= '1.1.*') {
            $setup->alterTable()
                ->addColumn(
                    self::fields_attributes,
                    self::fields_class,
                    TableInterface::column_type_VARCHAR,
                    1000,
                    'not null',
                    '队列类型属性')
                ->alter();
        }
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
//                $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable('队列类型消费者')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_name, TableInterface::column_type_VARCHAR, 255, 'not null', '队列类型名称')
                ->addColumn(self::fields_attributes, TableInterface::column_type_TEXT, 0, '', '队列属性码')
                ->addColumn(self::fields_tip, TableInterface::column_type_TEXT, 2000, 'not null', '提示')
                ->addColumn(self::fields_module_name, TableInterface::column_type_VARCHAR, 255, 'not null', '队列所属模块名称')
                ->addColumn(self::fields_class, TableInterface::column_type_VARCHAR, 128, 'not null unique', '队列类型实现类名')
                ->addColumn(self::fields_enable, TableInterface::column_type_SMALLINT, 1, 'not null default 1', '是否启用')
                ->addAdditional('ENGINE=MyISAM')
                ->create();
        }
    }

    public function getTypeId(): int
    {
        return (int)$this->getData(self::fields_ID);
    }


    public function getModuleName(): string
    {
        return $this->getData(self::fields_module_name);
    }

    public function getName(): string
    {
        return $this->getData(self::fields_name);
    }


    public function getEnable(): bool
    {
        return (bool)$this->getData(self::fields_enable);
    }


    public function setEnable(bool $enable): static
    {
        return $this->setData(self::fields_enable, $enable);
    }


    public function getTip(): string
    {
        return $this->getData(self::fields_tip);
    }

    public function getClass(): string
    {
        return $this->getData(self::fields_class) ?? '';
    }

    public function setTypeId(int $type_id): static
    {
        return $this->setData(self::fields_ID, $type_id);
    }

    public function setName(string $name): static
    {
        return $this->setData(self::fields_name, $name);
    }

    public function setTip(string $tip): static
    {
        return $this->setData(self::fields_tip, $tip);
    }

    public function setModuleName(string $module_name): static
    {
        return $this->setData(self::fields_module_name, $module_name);
    }

    public function setClass(string $class): static
    {
        return $this->setData(self::fields_class, $class);
    }

    public function setAttributes(string $attributes): static
    {
        return $this->setData(self::fields_attributes, $attributes);
    }

    public function getAttributes(array &$options = []): array
    {
        $type_id = $this->getTypeId();
        /** @var Attributes $typeAttributeModel */
        $typeAttributeModel = ObjectManager::getInstance(Attributes::class);
        $attributes         = $typeAttributeModel->getAttributesByTypeId($type_id, $options);
        if (!empty($options['need_array'])) {
            foreach ($attributes as &$attribute) {
                $attribute = $attribute->getData();
            }
        }
        return $attributes;
    }

    public function getAttribute(string $code, array &$options = []): EavAttribute|null
    {
        $type_id = $this->getTypeId();
        /** @var Attributes $typeAttributeModel */
        $typeAttributeModel = ObjectManager::make(Attributes::class);
        return $typeAttributeModel->getAttributesByTypeCode($type_id, $code, $options);
    }
}
