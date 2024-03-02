<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/6 21:28:26
 */

namespace Weline\Eav\Model\EavAttribute;

use Weline\Eav\Model\EavAttribute;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Type extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'type_id';
    public const fields_type_id = 'type_id';
    public const fields_code = 'code';
    public const fields_name = 'name';
    public const fields_is_swatch = 'is_swatch';
    public const fields_swatch_image = 'swatch_image';
    public const fields_swatch_color = 'swatch_color';
    public const fields_swatch_text = 'swatch_text';
    public const fields_frontend_attrs = 'frontend_attrs';
    public const fields_required = 'required';
    public const fields_field_type = 'field_type';
    public const fields_field_length = 'field_length';
    public array $_unit_primary_keys = ['type_id'];
    public array $_index_sort_keys = ['type_id'];

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
//                $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable('属性类型表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '类型ID'
                )
                ->addColumn(
                    self::fields_code,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'unique not null',
                    '类型代码'
                )
                ->addColumn(
                    self::fields_name,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '类型名'
                )
                ->addColumn(
                    self::fields_is_swatch,
                    TableInterface::column_type_BOOLEAN,
                    0,
                    'not null default 0',
                    '是否可选'
                )
                ->addColumn(
                    self::fields_swatch_image,
                    TableInterface::column_type_BOOLEAN,
                    0,
                    'not null default 0',
                    '可选图'
                )
                ->addColumn(
                    self::fields_swatch_color,
                    TableInterface::column_type_BOOLEAN,
                    0,
                    'not null default 0',
                    '可选颜色'
                )
                ->addColumn(
                    self::fields_swatch_text,
                    TableInterface::column_type_BOOLEAN,
                    0,
                    'not null default 0',
                    '可选文本'
                )
                ->addColumn(
                    self::fields_frontend_attrs,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '前端类型'
                )
                ->addColumn(
                    self::fields_required,
                    TableInterface::column_type_SMALLINT,
                    1,
                    'not null default 0',
                    '是否必须项'
                )
                ->addColumn(
                    self::fields_field_type,
                    TableInterface::column_type_VARCHAR,
                    60,
                    'not null',
                    '数据库字段类型'
                )
                ->addColumn(
                    self::fields_field_length,
                    TableInterface::column_type_SMALLINT,
                    5,
                    'not null',
                    '数据库字段长度'
                )
                ->addIndex(TableInterface::index_type_KEY, 'idx_code', self::fields_code)
                ->create();
            $this->insert(
                [
                    [
                        self::fields_code => 'input_string_60',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" maxlength="60" data-parsley-minlength="3" required',
                        self::fields_field_length => '60',
                        self::fields_is_swatch => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_text => 0,
                        self::fields_name => '字符串输入（60字节）',
                    ],
                    [
                        self::fields_code => 'input_int',
                        self::fields_field_type => TableInterface::column_type_INTEGER,
                        self::fields_frontend_attrs => 'type="number"',
                        self::fields_field_length => 11,
                        self::fields_is_swatch => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_text => 0,
                        self::fields_name => '数字输入',
                    ],
                    [
                        self::fields_code => 'input_bool',
                        self::fields_field_type => TableInterface::column_type_SMALLINT,
                        self::fields_frontend_attrs => 'type="number"',
                        self::fields_field_length => 1,
                        self::fields_is_swatch => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_text => 0,
                        self::fields_name => '布尔值输入',
                    ],
                    [
                        self::fields_code => 'input_string_255',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" maxlength="255" data-parsley-minlength="3" required',
                        self::fields_field_length => 1,
                        self::fields_is_swatch => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_text => 0,
                        self::fields_name => '字符串输入（255字节）',
                    ],
                    [
                        self::fields_code => 'input_string',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                        self::fields_field_length => 1,
                        self::fields_is_swatch => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_text => 0,
                        self::fields_name => '字符串输入',
                    ],
                    [
                        self::fields_code => 'input_string_swatch_color',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                        self::fields_field_length => 1,
                        self::fields_is_swatch => 1,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_color => 1,
                        self::fields_swatch_text => 0,
                        self::fields_name => '字符串输入: 可选颜色',
                    ],
                    [
                        self::fields_code => 'input_string_swatch_image',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                        self::fields_field_length => 1,
                        self::fields_is_swatch => 1,
                        self::fields_swatch_image => 1,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_text => 0,
                        self::fields_name => '字符串输入: 可选图片',
                    ],
                    [
                        self::fields_code => 'input_string_swatch_text',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                        self::fields_field_length => 1,
                        self::fields_is_swatch => 1,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_text => 1,
                        self::fields_name => '字符串输入: 可选文字',
                    ],
                    [
                        self::fields_code => 'input_string_swatch',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                        self::fields_field_length => 255,
                        self::fields_is_swatch => 1,
                        self::fields_swatch_color => 1,
                        self::fields_swatch_image => 1,
                        self::fields_swatch_text => 1,
                        self::fields_name => '字符串输入: 可选样本',
                    ],
                ],
                self::fields_code
            )->fetch();
        }
    }

    public function getName(): string
    {
        return $this->getData(self::fields_name) ?: '';
    }

    public function setName(string $name): static
    {
        return $this->setData(self::fields_name, $name);
    }

    public function getCode(): string
    {
        return $this->getData(self::fields_code) ?: '';
    }

    public function setCode(string $code): static
    {
        return $this->setData(self::fields_code, $code);
    }

    public function getFieldType(): string
    {
        return $this->getData(self::fields_field_type) ?: '';
    }

    public function setFieldType(string $field_type): static
    {
        return $this->setData(self::fields_field_type, $field_type);
    }

    public function getFieldLength(): int
    {
        return intval($this->getData(self::fields_field_length));
    }

    public function setFieldLength(int $field_length): static
    {
        return $this->setData(self::fields_field_length, $field_length);
    }

    public function isSwatch(): bool
    {
        return boolval($this->getData(self::fields_is_swatch));
    }
    public function getIsSwatch(): bool
    {
        return boolval($this->getData(self::fields_is_swatch));
    }

    public function setIsSwatch(bool $is_swatch): static
    {
        return $this->setData(self::fields_is_swatch, $is_swatch ? 1 : 0);
    }

    public function hasSwatchColor(): bool
    {
        return boolval($this->getData(self::fields_swatch_color));
    }

    public function setHasSwatchColor(bool $has_swatch_color): static
    {
        return $this->setData(self::fields_has_swatch_color, $has_swatch_color ? 1 : 0);
    }

    public function hasSwatchImage(): bool
    {
        return boolval($this->getData(self::fields_swatch_image));
    }

    public function setHasSwatchImage(bool $has_swatch_image): static
    {
        return $this->setData(self::fields_swatch_image, $has_swatch_image ? 1 : 0);
    }

    public function hasSwatchText(): bool
    {
        return boolval($this->getData(self::fields_swatch_text));
    }

    public function setHasSwatchText(bool $has_swatch_text): static
    {
        return $this->setData(self::fields_swatch_text, $has_swatch_text ? 1 : 0);
    }

    /**
     * @DESC          # 获取关联属性类型的属性模型
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/27 22:22
     * 参数区：
     */
    public function getAttributeModel(): EavAttribute
    {
        /**@var EavAttribute $attrbiute */
        $attrbiute = ObjectManager::getInstance(EavAttribute::class);
        $attrbiute->where(EavAttribute::fields_type_id, $this->getId());
        return $attrbiute;
    }
}
