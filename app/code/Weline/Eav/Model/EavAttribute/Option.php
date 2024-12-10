<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/18 13:44:09
 */

namespace Weline\Eav\Model\EavAttribute;

use Weline\Eav\Model\EavAttribute;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Option extends \Weline\Framework\Database\Model
{
    public const fields_ID           = 'option_id';
    public const fields_option_id    = 'option_id';
    public const fields_eav_entity_id    = 'eav_entity_id';
    public const fields_attribute_id = 'attribute_id';
    public const fields_code         = 'code';
    public const fields_value        = 'value';
    public const fields_swatch_image = 'swatch_image';
    public const fields_swatch_color = 'swatch_color';
    public const fields_swatch_text  = 'swatch_text';

    public array $_unit_primary_keys = ['option_id', 'attribute_id', 'code'];
    public array $_index_sort_keys = ['option_id', 'attribute_id', 'code'];

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
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable('属性配置项')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', '配置项ID')
                ->addColumn(self::fields_code, TableInterface::column_type_VARCHAR, 255, 'not null', '配置项代码')
                ->addColumn(self::fields_value, TableInterface::column_type_VARCHAR, 255, 'not null', '配置值')
                ->addColumn(self::fields_attribute_id, TableInterface::column_type_INTEGER, 0, 'not null', '属性ID')
                ->addColumn(self::fields_eav_entity_id, TableInterface::column_type_VARCHAR, 255, 'not null', '相关实体ID')
                ->addColumn(self::fields_swatch_image, TableInterface::column_type_TEXT, 0, '', '图片')
                ->addColumn(self::fields_swatch_color, TableInterface::column_type_VARCHAR, 60, '', '颜色')
                ->addColumn(self::fields_swatch_text, TableInterface::column_type_VARCHAR, 128, '', '文本')
                ->addIndex(TableInterface::index_type_KEY, 'IDX_EAV_ATTRIBUTE_ID', 'attribute_id')
                ->addForeignKey('FK_EAV_ATTRIBUTE_ID', 'attribute_id', ObjectManager::getInstance(EavAttribute::class)->getTable(), 'attribute_id', true)
                ->create();
        }
    }

    function addLocalDescription()
    {
        $lang    = Cookie::getLang();
        $idField = $this::fields_ID;
        $this->joinModel(
            \Weline\Eav\Model\EavAttribute\Option\LocalDescription::class,
            'local',
            "main_table.{$idField}=local.{$idField} and local.local_code='$lang'",
            'left'
        );
        return $this;
    }

    function getOptionId(): int
    {
        return (int)$this->getData(self::fields_option_id);
    }

    function setOptionId(int $option_id): static
    {
        return $this->setData(self::fields_option_id, $option_id);
    }

    function getEavEntityId(): int
    {
        return (int)$this->getData(self::fields_eav_entity_id);
    }

    function setEntityId(int $eav_entity_id): static
    {
        return $this->setData(self::fields_eav_entity_id, $eav_entity_id);
    }

    function getAttributeId(): int
    {
        return (int)$this->getData(self::fields_attribute_id);
    }

    function setAttributeId(int $attribute_id): static
    {
        return $this->setData(self::fields_attribute_id, $attribute_id);
    }

    function getCode(): string
    {
        return $this->getData(self::fields_code);
    }

    function setCode(string $code): static
    {
        return $this->setData(self::fields_code, $code);
    }

    function getValue(): string
    {
        return $this->getData(self::fields_value);
    }

    function setValue(string $value): static
    {
        return $this->setData(self::fields_value, $value);
    }

    function getSwatchImage(): string
    {
        return $this->getData(self::fields_swatch_image);
    }

    function setSwatchImage(string $swatch_image): static
    {
        return $this->setData(self::fields_swatch_image, $swatch_image);
    }

    function getSwatchColor(): string
    {
        return $this->getData(self::fields_swatch_color);
    }

    function setSwatchColor(string $swatch_color): static
    {
        return $this->setData(self::fields_swatch_color, $swatch_color);
    }

    function getSwatchText(): string
    {
        return $this->getData(self::fields_swatch_text);
    }

    function setSwatchText(string $swatch_text): static
    {
        return $this->setData(self::fields_swatch_text, $swatch_text);
    }
}