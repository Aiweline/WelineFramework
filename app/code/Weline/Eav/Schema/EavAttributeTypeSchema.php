<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Eav\Schema;

use Weline\Eav\Ui\EavModel\Select\YesNo;
use Weline\Framework\Database\Api\Db\TableInterface;

/**
 * EAV属性类型表结构定义
 * 
 * 对应表: m_eav_attribute_type
 * 关联Model: Weline\Eav\Model\EavAttribute\Type
 */
class EavAttributeTypeSchema extends AbstractSchema
{
    public const TABLE_NAME = 'eav_attribute_type';
    
    // 字段常量
    public const FIELD_ID = 'type_id';
    public const FIELD_CODE = 'code';
    public const FIELD_NAME = 'name';
    public const FIELD_ELEMENT = 'element';
    public const FIELD_MODEL_CLASS = 'model_class';
    public const FIELD_MODEL_CLASS_DATA = 'model_class_data';
    public const FIELD_DEFAULT_VALUE = 'default_value';
    public const FIELD_IS_SWATCH = 'is_swatch';
    public const FIELD_SWATCH_IMAGE = 'swatch_image';
    public const FIELD_SWATCH_COLOR = 'swatch_color';
    public const FIELD_SWATCH_TEXT = 'swatch_text';
    public const FIELD_FRONTEND_ATTRS = 'frontend_attrs';
    public const FIELD_REQUIRED = 'required';
    public const FIELD_FIELD_TYPE = 'field_type';
    public const FIELD_FIELD_LENGTH = 'field_length';

    public function getTableName(): string
    {
        return self::TABLE_NAME;
    }

    public function getTableComment(): string
    {
        return '属性类型表';
    }

    public function getColumns(): array
    {
        return [
            self::FIELD_ID => $this->primaryKey('类型ID'),
            self::FIELD_CODE => $this->varchar('类型代码', 255, 'unique not null'),
            self::FIELD_NAME => $this->varchar('类型名', 255, 'not null'),
            self::FIELD_ELEMENT => $this->varchar('类型元素', 60, "default 'input'"),
            self::FIELD_MODEL_CLASS => $this->varchar('渲染模型名', 255, "default ''"),
            self::FIELD_MODEL_CLASS_DATA => $this->mediumText('渲染模型内容'),
            self::FIELD_DEFAULT_VALUE => $this->mediumText('默认值'),
            self::FIELD_IS_SWATCH => $this->boolean('是否可选', false),
            self::FIELD_SWATCH_IMAGE => $this->boolean('可选图', false),
            self::FIELD_SWATCH_COLOR => $this->boolean('可选颜色', false),
            self::FIELD_SWATCH_TEXT => $this->boolean('可选文本', false),
            self::FIELD_FRONTEND_ATTRS => $this->varchar('前端类型', 255, 'not null'),
            self::FIELD_REQUIRED => $this->boolean('是否必须项', false),
            self::FIELD_FIELD_TYPE => $this->varchar('数据库字段类型', 60, 'not null'),
            self::FIELD_FIELD_LENGTH => $this->integer('数据库字段长度', 'not null'),
        ];
    }

    public function getIndexes(): array
    {
        return [
            'idx_code' => $this->index(self::FIELD_CODE, '类型代码索引'),
        ];
    }

    public function getUniqueKey(): string|array
    {
        return self::FIELD_CODE;
    }

    public function getInitialData(): array
    {
        return [
            [
                self::FIELD_CODE => 'input_string_60',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_VARCHAR,
                self::FIELD_FRONTEND_ATTRS => 'type="text" maxlength="60" data-parsley-minlength="3" required',
                self::FIELD_FIELD_LENGTH => 60,
                self::FIELD_IS_SWATCH => 0,
                self::FIELD_SWATCH_IMAGE => 0,
                self::FIELD_SWATCH_COLOR => 0,
                self::FIELD_SWATCH_TEXT => 0,
                self::FIELD_ELEMENT => 'input',
                self::FIELD_MODEL_CLASS => '',
                self::FIELD_MODEL_CLASS_DATA => '',
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '',
                self::FIELD_NAME => '字符串输入（60字节）',
            ],
            [
                self::FIELD_CODE => 'input_int',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_INTEGER,
                self::FIELD_FRONTEND_ATTRS => 'type="number"',
                self::FIELD_FIELD_LENGTH => 11,
                self::FIELD_IS_SWATCH => 0,
                self::FIELD_SWATCH_IMAGE => 0,
                self::FIELD_SWATCH_COLOR => 0,
                self::FIELD_SWATCH_TEXT => 0,
                self::FIELD_ELEMENT => 'input',
                self::FIELD_MODEL_CLASS => '',
                self::FIELD_MODEL_CLASS_DATA => '',
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '',
                self::FIELD_NAME => '数字输入',
            ],
            [
                self::FIELD_CODE => 'input_decimal_2',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_INTEGER,
                self::FIELD_FRONTEND_ATTRS => 'type="number" step="0.01"',
                self::FIELD_FIELD_LENGTH => 11,
                self::FIELD_IS_SWATCH => 0,
                self::FIELD_SWATCH_IMAGE => 0,
                self::FIELD_SWATCH_COLOR => 0,
                self::FIELD_SWATCH_TEXT => 0,
                self::FIELD_ELEMENT => 'input',
                self::FIELD_MODEL_CLASS => '',
                self::FIELD_MODEL_CLASS_DATA => '',
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '',
                self::FIELD_NAME => '小数输入',
            ],
            [
                self::FIELD_CODE => 'input_bool',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_SMALLINT,
                self::FIELD_FRONTEND_ATTRS => 'type="number"',
                self::FIELD_FIELD_LENGTH => 1,
                self::FIELD_IS_SWATCH => 0,
                self::FIELD_SWATCH_IMAGE => 0,
                self::FIELD_SWATCH_COLOR => 0,
                self::FIELD_SWATCH_TEXT => 0,
                self::FIELD_ELEMENT => 'input',
                self::FIELD_MODEL_CLASS => '',
                self::FIELD_MODEL_CLASS_DATA => '',
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '',
                self::FIELD_NAME => '布尔值输入',
            ],
            [
                self::FIELD_CODE => 'input_string_255',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_VARCHAR,
                self::FIELD_FRONTEND_ATTRS => 'type="text" maxlength="255" data-parsley-minlength="3" required',
                self::FIELD_FIELD_LENGTH => 255,
                self::FIELD_IS_SWATCH => 0,
                self::FIELD_SWATCH_IMAGE => 0,
                self::FIELD_SWATCH_COLOR => 0,
                self::FIELD_SWATCH_TEXT => 0,
                self::FIELD_ELEMENT => 'input',
                self::FIELD_MODEL_CLASS => '',
                self::FIELD_MODEL_CLASS_DATA => '',
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '',
                self::FIELD_NAME => '字符串输入（255字节）',
            ],
            [
                self::FIELD_CODE => 'input_string',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_VARCHAR,
                self::FIELD_FRONTEND_ATTRS => 'type="text" data-parsley-minlength="3" required',
                self::FIELD_FIELD_LENGTH => 255,
                self::FIELD_IS_SWATCH => 0,
                self::FIELD_SWATCH_IMAGE => 0,
                self::FIELD_SWATCH_COLOR => 0,
                self::FIELD_SWATCH_TEXT => 0,
                self::FIELD_ELEMENT => 'input',
                self::FIELD_MODEL_CLASS => '',
                self::FIELD_MODEL_CLASS_DATA => '',
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '',
                self::FIELD_NAME => '字符串输入',
            ],
            [
                self::FIELD_CODE => 'textarea_varchar',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_VARCHAR,
                self::FIELD_FRONTEND_ATTRS => 'type="text" data-parsley-minlength="3" required',
                self::FIELD_FIELD_LENGTH => 255,
                self::FIELD_IS_SWATCH => 0,
                self::FIELD_SWATCH_IMAGE => 0,
                self::FIELD_SWATCH_COLOR => 0,
                self::FIELD_SWATCH_TEXT => 0,
                self::FIELD_ELEMENT => 'textarea',
                self::FIELD_MODEL_CLASS => '',
                self::FIELD_MODEL_CLASS_DATA => '',
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '',
                self::FIELD_NAME => '文本区域输入(VARCHAR)',
            ],
            [
                self::FIELD_CODE => 'textarea_text',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_TEXT,
                self::FIELD_FRONTEND_ATTRS => 'type="text" data-parsley-minlength="3" required',
                self::FIELD_FIELD_LENGTH => 0,
                self::FIELD_IS_SWATCH => 0,
                self::FIELD_SWATCH_IMAGE => 0,
                self::FIELD_SWATCH_COLOR => 0,
                self::FIELD_SWATCH_TEXT => 0,
                self::FIELD_ELEMENT => 'textarea',
                self::FIELD_MODEL_CLASS => '',
                self::FIELD_MODEL_CLASS_DATA => '',
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '',
                self::FIELD_NAME => '文本区域输入(TEXT)',
            ],
            [
                self::FIELD_CODE => 'textarea_mediumtext',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_MEDIU_TEXT,
                self::FIELD_FRONTEND_ATTRS => 'type="text" data-parsley-minlength="3" required',
                self::FIELD_FIELD_LENGTH => 0,
                self::FIELD_IS_SWATCH => 0,
                self::FIELD_SWATCH_IMAGE => 0,
                self::FIELD_SWATCH_COLOR => 0,
                self::FIELD_SWATCH_TEXT => 0,
                self::FIELD_ELEMENT => 'textarea',
                self::FIELD_MODEL_CLASS => '',
                self::FIELD_MODEL_CLASS_DATA => '',
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '',
                self::FIELD_NAME => '文本区域输入(MEDIUMTEXT)',
            ],
            [
                self::FIELD_CODE => 'textarea_longtext',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_LONG_TEXT,
                self::FIELD_FRONTEND_ATTRS => 'type="text" data-parsley-minlength="3" required',
                self::FIELD_FIELD_LENGTH => 0,
                self::FIELD_IS_SWATCH => 0,
                self::FIELD_SWATCH_IMAGE => 0,
                self::FIELD_SWATCH_COLOR => 0,
                self::FIELD_SWATCH_TEXT => 0,
                self::FIELD_ELEMENT => 'textarea',
                self::FIELD_MODEL_CLASS => '',
                self::FIELD_MODEL_CLASS_DATA => '',
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '',
                self::FIELD_NAME => '文本区域输入(LONGTEXT)',
            ],
            [
                self::FIELD_CODE => 'input_string_swatch_image',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_VARCHAR,
                self::FIELD_FRONTEND_ATTRS => 'type="text" data-parsley-minlength="3" required',
                self::FIELD_FIELD_LENGTH => 255,
                self::FIELD_IS_SWATCH => 1,
                self::FIELD_SWATCH_IMAGE => 1,
                self::FIELD_SWATCH_COLOR => 0,
                self::FIELD_SWATCH_TEXT => 0,
                self::FIELD_ELEMENT => 'input',
                self::FIELD_MODEL_CLASS => '',
                self::FIELD_MODEL_CLASS_DATA => '',
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '',
                self::FIELD_NAME => '字符串输入: 可选图片',
            ],
            [
                self::FIELD_CODE => 'input_string_swatch_color',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_VARCHAR,
                self::FIELD_FRONTEND_ATTRS => 'type="text" data-parsley-minlength="3" required',
                self::FIELD_FIELD_LENGTH => 255,
                self::FIELD_IS_SWATCH => 1,
                self::FIELD_SWATCH_IMAGE => 0,
                self::FIELD_SWATCH_COLOR => 1,
                self::FIELD_SWATCH_TEXT => 0,
                self::FIELD_ELEMENT => 'input',
                self::FIELD_MODEL_CLASS => '',
                self::FIELD_MODEL_CLASS_DATA => '',
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '',
                self::FIELD_NAME => '字符串输入: 可选颜色',
            ],
            [
                self::FIELD_CODE => 'input_string_swatch_text',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_VARCHAR,
                self::FIELD_FRONTEND_ATTRS => 'type="text" data-parsley-minlength="3" required',
                self::FIELD_FIELD_LENGTH => 255,
                self::FIELD_IS_SWATCH => 1,
                self::FIELD_SWATCH_IMAGE => 0,
                self::FIELD_SWATCH_COLOR => 0,
                self::FIELD_SWATCH_TEXT => 1,
                self::FIELD_ELEMENT => 'input',
                self::FIELD_MODEL_CLASS => '',
                self::FIELD_MODEL_CLASS_DATA => '',
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '',
                self::FIELD_NAME => '字符串输入: 可选文字',
            ],
            [
                self::FIELD_CODE => 'input_string_swatch',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_VARCHAR,
                self::FIELD_FRONTEND_ATTRS => 'type="text" data-parsley-minlength="3" required',
                self::FIELD_FIELD_LENGTH => 255,
                self::FIELD_IS_SWATCH => 1,
                self::FIELD_SWATCH_IMAGE => 1,
                self::FIELD_SWATCH_COLOR => 1,
                self::FIELD_SWATCH_TEXT => 1,
                self::FIELD_ELEMENT => 'input',
                self::FIELD_MODEL_CLASS => '',
                self::FIELD_MODEL_CLASS_DATA => '',
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '',
                self::FIELD_NAME => '字符串输入: 可选样本',
            ],
            [
                self::FIELD_CODE => 'select_yes_no',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_VARCHAR,
                self::FIELD_FRONTEND_ATTRS => 'type="text" data-parsley-minlength="3" required',
                self::FIELD_FIELD_LENGTH => 255,
                self::FIELD_IS_SWATCH => 1,
                self::FIELD_SWATCH_IMAGE => 0,
                self::FIELD_SWATCH_COLOR => 0,
                self::FIELD_SWATCH_TEXT => 1,
                self::FIELD_ELEMENT => 'select',
                self::FIELD_MODEL_CLASS => YesNo::class,
                self::FIELD_MODEL_CLASS_DATA => json_encode(['1' => '是', '0' => '否']),
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '1',
                self::FIELD_NAME => '选择：是/否',
            ],
            [
                self::FIELD_CODE => 'select_option',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_VARCHAR,
                self::FIELD_FRONTEND_ATTRS => 'type="text" data-parsley-minlength="3" required',
                self::FIELD_FIELD_LENGTH => 255,
                self::FIELD_IS_SWATCH => 1,
                self::FIELD_SWATCH_IMAGE => 0,
                self::FIELD_SWATCH_COLOR => 0,
                self::FIELD_SWATCH_TEXT => 1,
                self::FIELD_ELEMENT => 'select',
                self::FIELD_MODEL_CLASS => \Weline\Eav\Ui\EavModel\Select\Option::class,
                self::FIELD_MODEL_CLASS_DATA => json_encode(['1' => '是', '0' => '否']),
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '1',
                self::FIELD_NAME => '选择：选项[单选]',
            ],
            [
                self::FIELD_CODE => 'select_option_multiple',
                self::FIELD_FIELD_TYPE => TableInterface::column_type_VARCHAR,
                self::FIELD_FRONTEND_ATTRS => 'type="text" data-parsley-minlength="3" required multiple',
                self::FIELD_FIELD_LENGTH => 255,
                self::FIELD_IS_SWATCH => 1,
                self::FIELD_SWATCH_IMAGE => 0,
                self::FIELD_SWATCH_COLOR => 0,
                self::FIELD_SWATCH_TEXT => 1,
                self::FIELD_ELEMENT => 'select',
                self::FIELD_MODEL_CLASS => \Weline\Eav\Ui\EavModel\Select\Option::class,
                self::FIELD_MODEL_CLASS_DATA => json_encode(['1' => '是', '0' => '否']),
                self::FIELD_REQUIRED => 1,
                self::FIELD_DEFAULT_VALUE => '1',
                self::FIELD_NAME => '选择：选项[多选]',
            ],
        ];
    }
}
