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

/**
 * EAV属性选项模型 (SRP - 单一职责原则)
 * 
 * 表结构定义已迁移到 Schema/EavAttributeOptionSchema.php
 * 本类只负责数据操作和业务逻辑
 */
class Option extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'option_id';
    public const fields_option_id = 'option_id';
    public const fields_eav_entity_id = 'eav_entity_id';
    public const fields_attribute_id = 'attribute_id';
    public const fields_code = 'code';
    public const fields_value = 'value';
    public const fields_swatch_image = 'swatch_image';
    public const fields_swatch_color = 'swatch_color';
    public const fields_swatch_text = 'swatch_text';

    public array $_unit_primary_keys = ['option_id', 'attribute_id', 'code'];
    public array $_index_sort_keys = ['option_id', 'attribute_id', 'code'];

    // 表结构已迁移到 Schema/EavAttributeOptionSchema.php，由 Setup/Install.php 统一管理表创建；此处不再定义 setup/upgrade/install，使用父类空实现。

    function getOptionId(): int
    {
        return (int)$this->getData(self::schema_fields_option_id);
    }

    function setOptionId(int $option_id): static
    {
        return $this->setData(self::schema_fields_option_id, $option_id);
    }

    function getEavEntityId(): int
    {
        return (int)$this->getData(self::schema_fields_eav_entity_id);
    }

    function setEntityId(int $eav_entity_id): static
    {
        return $this->setData(self::schema_fields_eav_entity_id, $eav_entity_id);
    }

    function getAttributeId(): int
    {
        return (int)$this->getData(self::schema_fields_attribute_id);
    }

    function setAttributeId(int $attribute_id): static
    {
        return $this->setData(self::schema_fields_attribute_id, $attribute_id);
    }

    function getCode(): string
    {
        return $this->getData(self::schema_fields_code);
    }

    function setCode(string $code): static
    {
        return $this->setData(self::schema_fields_code, $code);
    }

    function getValue(): string
    {
        return $this->getData(self::schema_fields_value);
    }

    function setValue(string $value): static
    {
        return $this->setData(self::schema_fields_value, $value);
    }

    function getSwatchImage(): string
    {
        return $this->getData(self::schema_fields_swatch_image);
    }

    function setSwatchImage(string $swatch_image): static
    {
        return $this->setData(self::schema_fields_swatch_image, $swatch_image);
    }

    function getSwatchColor(): string
    {
        return $this->getData(self::schema_fields_swatch_color);
    }

    function setSwatchColor(string $swatch_color): static
    {
        return $this->setData(self::schema_fields_swatch_color, $swatch_color);
    }

    function getSwatchText(): string
    {
        return $this->getData(self::schema_fields_swatch_text);
    }

    function setSwatchText(string $swatch_text): static
    {
        return $this->setData(self::schema_fields_swatch_text, $swatch_text);
    }
}