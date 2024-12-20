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

use Weline\Eav\EavModelInterface;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Ui\EavModel\Select\YesNo;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Http\Request;
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
    public const fields_model_class = 'model_class';
    public const fields_model_class_data = 'model_class_data';
    public const fields_element = 'element';
    public const fields_default_value = 'default_value';
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
        # 插入数据
        $setup->alterTable()
            ->addConstraints('engine=MyISAM default charset=utf8mb4;')
            ->alterColumn(
                self::fields_field_length,
                self::fields_field_length,
                self::fields_field_type,
                TableInterface::column_type_BIGINT,
                11,
                'not null',
                '数据库字段长度',
            )->alter();
        $this->insert([
            [
                self::fields_code => 'textarea_varchar',
                self::fields_field_type => TableInterface::column_type_VARCHAR,
                self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                self::fields_field_length => 255,
                self::fields_is_swatch => 0,
                self::fields_swatch_image => 0,
                self::fields_swatch_color => 0,
                self::fields_swatch_text => 0,
                self::fields_element => 'textarea',
                self::fields_model_class => '',
                self::fields_model_class_data => '',
                self::fields_required => 1,
                self::fields_default_value => '',
                self::fields_name => '字符串输入',
            ],
            [
                self::fields_code => 'textarea_text',
                self::fields_field_type => TableInterface::column_type_TEXT,
                self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                self::fields_field_length => 0,
                self::fields_is_swatch => 0,
                self::fields_swatch_image => 0,
                self::fields_swatch_color => 0,
                self::fields_swatch_text => 0,
                self::fields_element => 'textarea',
                self::fields_model_class => '',
                self::fields_model_class_data => '',
                self::fields_required => 1,
                self::fields_default_value => '',
                self::fields_name => '字符串输入',
            ],
            [
                self::fields_code => 'textarea_mediumtext',
                self::fields_field_type => TableInterface::column_type_MEDIU_TEXT,
                self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                self::fields_field_length => 0,
                self::fields_is_swatch => 0,
                self::fields_swatch_image => 0,
                self::fields_swatch_color => 0,
                self::fields_swatch_text => 0,
                self::fields_element => 'textarea',
                self::fields_model_class => '',
                self::fields_model_class_data => '',
                self::fields_required => 1,
                self::fields_default_value => '',
                self::fields_name => '字符串输入',
            ],
            [
                self::fields_code => 'textarea_longtext',
                self::fields_field_type => TableInterface::column_type_LONG_TEXT,
                self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                self::fields_field_length => 0,
                self::fields_is_swatch => 0,
                self::fields_swatch_image => 0,
                self::fields_swatch_color => 0,
                self::fields_swatch_text => 0,
                self::fields_element => 'textarea',
                self::fields_model_class => '',
                self::fields_model_class_data => '',
                self::fields_required => 1,
                self::fields_default_value => '',
                self::fields_name => '字符串输入',
            ]], self::fields_code)
            ->fetch();
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
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
                    self::fields_element,
                    TableInterface::column_type_VARCHAR,
                    60,
                    "default 'input'",
                    '类型元素'
                )
                ->addColumn(
                    self::fields_model_class,
                    TableInterface::column_type_VARCHAR,
                    255,
                    "default ''",
                    '渲染模型名'
                )
                ->addColumn(
                    self::fields_model_class_data,
                    TableInterface::column_type_MEDIU_TEXT,
                    0,
                    "",
                    '渲染模型内容'
                )
                ->addColumn(
                    self::fields_default_value,
                    TableInterface::column_type_MEDIU_TEXT,
                    0,
                    "",
                    '默认值'
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
                    TableInterface::column_type_INTEGER,
                    11,
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
                        self::fields_swatch_image => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_text => 0,
                        self::fields_element => 'input',
                        self::fields_model_class => '',
                        self::fields_model_class_data => '',
                        self::fields_required => 1,
                        self::fields_default_value => '',
                        self::fields_name => '字符串输入（60字节）',
                    ],
                    [
                        self::fields_code => 'input_int',
                        self::fields_field_type => TableInterface::column_type_INTEGER,
                        self::fields_frontend_attrs => 'type="number"',
                        self::fields_field_length => 11,
                        self::fields_is_swatch => 0,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_text => 0,
                        self::fields_element => 'input',
                        self::fields_model_class => '',
                        self::fields_model_class_data => '',
                        self::fields_required => 1,
                        self::fields_default_value => '',
                        self::fields_name => '数字输入',
                    ],
                    [
                        self::fields_code => 'input_decimal_2',
                        self::fields_field_type => TableInterface::column_type_INTEGER,
                        self::fields_frontend_attrs => 'type="number" step="0.01"',
                        self::fields_field_length => 11,
                        self::fields_is_swatch => 0,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_text => 0,
                        self::fields_element => 'input',
                        self::fields_model_class => '',
                        self::fields_model_class_data => '',
                        self::fields_required => 1,
                        self::fields_default_value => '',
                        self::fields_name => '数字输入',
                    ],
                    [
                        self::fields_code => 'input_bool',
                        self::fields_field_type => TableInterface::column_type_SMALLINT,
                        self::fields_frontend_attrs => 'type="number"',
                        self::fields_field_length => 1,
                        self::fields_is_swatch => 0,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_text => 0,
                        self::fields_element => 'input',
                        self::fields_model_class => '',
                        self::fields_model_class_data => '',
                        self::fields_required => 1,
                        self::fields_default_value => '',
                        self::fields_name => '布尔值输入',
                    ],
                    [
                        self::fields_code => 'input_string_255',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" maxlength="255" data-parsley-minlength="3" required',
                        self::fields_field_length => 255,
                        self::fields_is_swatch => 0,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_text => 0,
                        self::fields_element => 'input',
                        self::fields_model_class => '',
                        self::fields_model_class_data => '',
                        self::fields_required => 1,
                        self::fields_default_value => '',
                        self::fields_name => '字符串输入（255字节）',
                    ],
                    [
                        self::fields_code => 'input_string',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                        self::fields_field_length => 255,
                        self::fields_is_swatch => 0,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_text => 0,
                        self::fields_element => 'input',
                        self::fields_model_class => '',
                        self::fields_model_class_data => '',
                        self::fields_required => 1,
                        self::fields_default_value => '',
                        self::fields_name => '字符串输入',
                    ],
                    [
                        self::fields_code => 'textarea_varchar',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                        self::fields_field_length => 255,
                        self::fields_is_swatch => 0,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_text => 0,
                        self::fields_element => 'textarea',
                        self::fields_model_class => '',
                        self::fields_model_class_data => '',
                        self::fields_required => 1,
                        self::fields_default_value => '',
                        self::fields_name => '字符串输入',
                    ],
                    [
                        self::fields_code => 'textarea_text',
                        self::fields_field_type => TableInterface::column_type_TEXT,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                        self::fields_field_length => 0,
                        self::fields_is_swatch => 0,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_text => 0,
                        self::fields_element => 'textarea',
                        self::fields_model_class => '',
                        self::fields_model_class_data => '',
                        self::fields_required => 1,
                        self::fields_default_value => '',
                        self::fields_name => '字符串输入',
                    ],
                    [
                        self::fields_code => 'textarea_mediumtext',
                        self::fields_field_type => TableInterface::column_type_MEDIU_TEXT,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                        self::fields_field_length => 0,
                        self::fields_is_swatch => 0,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_text => 0,
                        self::fields_element => 'textarea',
                        self::fields_model_class => '',
                        self::fields_model_class_data => '',
                        self::fields_required => 1,
                        self::fields_default_value => '',
                        self::fields_name => '字符串输入',
                    ],
                    [
                        self::fields_code => 'textarea_longtext',
                        self::fields_field_type => TableInterface::column_type_LONG_TEXT,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                        self::fields_field_length => 0,
                        self::fields_is_swatch => 0,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_text => 0,
                        self::fields_element => 'textarea',
                        self::fields_model_class => '',
                        self::fields_model_class_data => '',
                        self::fields_required => 1,
                        self::fields_default_value => '',
                        self::fields_name => '字符串输入',
                    ],
                    [
                        self::fields_code => 'input_string_swatch_image',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                        self::fields_field_length => 255,
                        self::fields_is_swatch => 1,
                        self::fields_swatch_image => 1,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_text => 0,
                        self::fields_element => 'input',
                        self::fields_model_class => '',
                        self::fields_model_class_data => '',
                        self::fields_required => 1,
                        self::fields_default_value => '',
                        self::fields_name => '字符串输入: 可选图片',
                    ],
                    [
                        self::fields_code => 'input_string_swatch_color',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                        self::fields_field_length => 255,
                        self::fields_is_swatch => 1,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_color => 1,
                        self::fields_swatch_text => 0,
                        self::fields_element => 'input',
                        self::fields_model_class => '',
                        self::fields_model_class_data => '',
                        self::fields_required => 1,
                        self::fields_default_value => '',
                        self::fields_name => '字符串输入: 可选颜色',
                    ],
                    [
                        self::fields_code => 'input_string_swatch_text',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                        self::fields_field_length => 255,
                        self::fields_is_swatch => 1,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_text => 1,
                        self::fields_element => 'input',
                        self::fields_model_class => '',
                        self::fields_model_class_data => '',
                        self::fields_required => 1,
                        self::fields_default_value => '',
                        self::fields_name => '字符串输入: 可选文字',
                    ],
                    [
                        self::fields_code => 'input_string_swatch',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                        self::fields_field_length => 255,
                        self::fields_is_swatch => 1,
                        self::fields_swatch_image => 1,
                        self::fields_swatch_color => 1,
                        self::fields_swatch_text => 1,
                        self::fields_element => 'input',
                        self::fields_model_class => '',
                        self::fields_model_class_data => '',
                        self::fields_required => 1,
                        self::fields_default_value => '',
                        self::fields_name => '字符串输入: 可选样本',
                    ],
                    [
                        self::fields_code => 'select_yes_no',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                        self::fields_field_length => 255,
                        self::fields_is_swatch => 1,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_text => 1,
                        self::fields_element => 'select',
                        self::fields_model_class => YesNo::class,
                        self::fields_model_class_data => json_encode(['1' => '是', '0' => '否']),
                        self::fields_required => 1,
                        self::fields_default_value => 1,
                        self::fields_name => '选择：是/否',
                    ],
                    [
                        self::fields_code => 'select_option',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required',
                        self::fields_field_length => 255,
                        self::fields_is_swatch => 1,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_text => 1,
                        self::fields_element => 'select',
                        self::fields_model_class => \Weline\Eav\Ui\EavModel\Select\Option::class,
                        self::fields_model_class_data => json_encode(['1' => '是', '0' => '否']),
                        self::fields_required => 1,
                        self::fields_default_value => 1,
                        self::fields_name => '选择：选项[单选]',
                    ],
                    [
                        self::fields_code => 'select_option_multiple',
                        self::fields_field_type => TableInterface::column_type_VARCHAR,
                        self::fields_frontend_attrs => 'type="text" data-parsley-minlength="3" required multiple',
                        self::fields_field_length => 255,
                        self::fields_is_swatch => 1,
                        self::fields_swatch_image => 0,
                        self::fields_swatch_color => 0,
                        self::fields_swatch_text => 1,
                        self::fields_element => 'select',
                        self::fields_model_class => \Weline\Eav\Ui\EavModel\Select\Option::class,
                        self::fields_model_class_data => json_encode(['1' => '是', '0' => '否']),
                        self::fields_required => 1,
                        self::fields_default_value => 1,
                        self::fields_name => '选择：选项[多选]',
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

    public function setElement(string $element): static
    {
        return $this->setData(self::fields_element, $element);
    }

    public function getElement(): string
    {
        return $this->getData(self::fields_element) ?: 'input';
    }

    public function getModelClass(): string
    {
        return $this->getData(self::fields_model_class) ?: '';
    }

    public function setModelClass(string $model): static
    {
        return $this->setData(self::fields_model_class, $model);
    }

    public function getModelClassData(): string
    {
        return $this->getData(self::fields_model_class_data) ?: '';
    }

    public function setModelClassData(string $model_data): static
    {
        return $this->setData(self::fields_model_class_data, $model_data);
    }

    public function getDefaultValue(): string|null
    {
        return $this->getData(self::fields_default_value) ?: null;
    }

    public function setDefaultValue(string $default_value): static
    {
        return $this->setData(self::fields_default_value, $default_value);
    }

    public function getRequired(): bool
    {
        return boolval($this->getData(self::fields_required));
    }

    public function setRequired(bool $required): static
    {
        return $this->setData(self::fields_required, $required ? 1 : 0);
    }

    public function getFrontendAttrs(): string
    {
        return $this->getData(self::fields_frontend_attrs) ?: '';
    }

    public function setFrontendAttrs(string $frontend_attrs): static
    {
        return $this->setData(self::fields_frontend_attrs, $frontend_attrs);
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
        return $this->setData(self::fields_swatch_color, $has_swatch_color ? 1 : 0);
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

    public static function processOptions(EavAttribute &$attribute, array &$options = []): array
    {
        $option_items = $options['options'] ?? [];
        $values = $options['values'] ?? [];
        $type = $attribute->getType();
        # 模型默认的选项
        $only_custom_options = $options['only_custom_options'] ?? true;
        if (!$only_custom_options and $model_class_data = $type->getModelClassData()) {
            $model_class_data = json_decode($model_class_data, true);
            # 数组合并，兼容键是数字时的合并
            if ($model_class_data) {
                foreach ($model_class_data as $key => $model_class_data_item) {
                    $option_items[$key] = $model_class_data_item;
                }
            }
            $type->setModelClassData(json_encode($model_class_data));
        }
        # 模型数据
        if ($model_class = $type->getModelClass()) {
            /**@var EavModelInterface $model_object */
            $model_object = ObjectManager::getInstance($model_class);
            if ($model_object instanceof EavModelInterface) {
                $type->setModelClassData(json_encode($model_object->getModelData()));
            } else {
                throw new \Exception(__('模型类: %1 必须是 EavModelInterface 接口类的实例', $model_class));
            }
        }
        $attrs = $options['attrs'] ?? [];
        $label_class = $options['label_class'] ?? '';
        $attrs = array_merge($attribute->getData(), [
            'field_type' => $type->getFieldType(),
            'length' => $type->getFieldLength(),
            'name' => $attribute->getCode(),
            'model_class_data' => htmlspecialchars($type->getModelClassData()),
            'title' => $attribute->getName() ?: $type->getName(),
            'placeholder' => __('请输入 ') . $type->getName(),
            'required' => $type->getRequired() ? 'required' : '',
            $type->getFrontendAttrs() => ''
        ], $attrs);
        unset($attrs['frontend_attrs']);
        unset($attrs['type']);
        return [$label_class, $attrs, $option_items, $values, $only_custom_options];
    }

    /**
     * @DESC          # 获取类型输出html
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 26/4/2024 下午1:59
     * 参数区：
     * @param EavAttribute $attribute
     * @param array $options ['options' => ['1' => '选项1', '2' => '选项2'], 'attrs' => ['class' => 'form-control'], 'label_class' => 'label-class']
     * @return string
     * @throws \Exception
     */
    function getHtml(EavAttribute &$attribute, array &$options = []): string
    {
        list($label_class, $attrs, $option_items, $values, $only_custom_options) = self::processOptions($attribute, $options);
        # 提取配置值
        $value = null;
        if (isset($values[$attribute->getCode()])) {
            $value = $values[$attribute->getCode()];
        }
        if ($value === null) {
            if (isset($options['entity']) and $options['entity']) {
                try {
                    $value = $attribute->getValue();
                } catch (Exception $e) {
                    $value = $this->getDefaultValue();
                }
            } else {
                $default_value = $this->getValue();
                if($default_value){
                    $value = $default_value;
                }else{
                    $value = $this->getDefaultValue();
                }
            }
        }
        # 如果有模型则直接返回模型
        if ($this->getModelClass()) {
            /** @var EavModelInterface $model */
            $model = ObjectManager::getInstance($this->getModelClass());
            return $model->getHtml($attribute, $value, $label_class, $attrs, $option_items, $only_custom_options);
        } else {
            $html = '';
            $element = $this->getElement();
            switch ($element) {
                case 'select':
                    $options = array_merge($options, json_decode($this->getModelClassData(), true));
                    if (empty($options)) {
                        throw new \Exception(__('Eav属性输入：缺少select选项'));
                    }
                    break;
                case 'input':
                case 'checkbox':
                case 'radio':
                    $attrs['value'] = $value;
                    break;
                case 'textarea':
                default:
                    break;
            }
            $attrsString = $this->processElementAttr($attribute, $attrs);
            switch ($element) {
                case 'select':
                    $html .= '<select ' . $attrsString . '>';
                    foreach ($option_items as $k => $v) {
                        $html .= '<option value="' . $k . '" ' . ($value == $k ? 'selected' : '') . '>' . $v . '</option>';
                    }
                    $html .= '</select>';
                    break;
                case 'textarea':
                    $html .= '<textarea ' . $attrsString . '>' . $value . '</textarea>';
                    break;
                case 'radio':
                case 'checkbox':
                default:
                    $html .= '<input ' . $attrsString . '>';
                    break;
            }
            self::processLabel($attribute, $label_class, $html);
            self::processDependence($attribute, $html);
            return $html;
        }
    }

    function processElementAttr(EavAttribute &$attribute, array &$attrs): string
    {
        $type = $attribute->getTypeModel();
        $id = $this->getCode() . '_' . $attribute->getCode() . '_' . $this->getId();
        $attrsString = ' id="' . $id . '" data-name="' . $type->getCode() . '" code="' . $attribute->getCode() . '" ';
        foreach ($attrs as $k => $v) {
            if (is_array($v)) {
                $v = json_encode($v);
            }
            switch ($k) {
                case 'type':
                    switch ($v) {
                        case 'int':
                        case 'integer':
                        case 'float':
                        case 'smallint':
                            $attrsString .= ' type="number"';
                            break;
                        case self::fields_swatch_image:
                            $attrsString .= ' type="image"';
                            break;
                        case self::fields_swatch_color:
                            $attrsString .= ' type="color"';
                            break;
                        default:
                            $attrsString .= ' type="text"';
                            break;
                    }
                    break;
                default:
                    $attrsString .= ' ' . $k . '="' . $v . '"';
                    break;
            }
        }
        $attrsString .= $type->getFrontendAttrs();
        return $attrsString;
    }

    static function processLabel(EavAttribute &$attribute, string &$label_class, string &$html): void
    {
        $type = $attribute->getTypeModel();
        $required = $type->getRequired() ? '<span class="required">*</span>' : '';
        $name = __($attribute->getName());
        $typeCode = $type->getCode();
        $attributeCode = $attribute->getCode();
        $dependence = $attribute->getDependence() ? '<br>' . __('依赖：') . '<span class="text-info">' . $attribute->getDependence() . '</span>' : '';
        $label = <<<LABEL
<label title="$attributeCode-$name" data-type-code="$typeCode" class="' . $label_class . '">$required $name <span class="text-primary">$attributeCode</span>$dependence</label>
LABEL;
        $html = $label . $html;
    }

    static function processDependence(EavAttribute &$attribute, string &$html): void
    {
        $dependence = $attribute->getDependence();
        $attributeCode = $attribute->getCode();
        if ($dependence) {
            /**@var Request $req */
            $req = ObjectManager::getInstance(Request::class);
            $eavModel = Env::getInstance()->getModuleByName('Weline_Eav');
            if (isset($eavModel['router'])) {
                $dependence = explode(',', $dependence);
                $eavDependenceUrl = $req->isBackend() ? $req->getUrlBuilder()->getBackendUrl($eavModel['router'] . '/backend/attribute/dependence') : $req->getUrlBuilder()->getUrl($eavModel['router'] . '/backend/attribute/dependence');
                $js = <<<PRE_JS
<script>
$(function() {
PRE_JS;

                foreach ($dependence as $dependenceItem) {
                    $js .= <<<DEPENDENCE_JS
                let currentAttribute = $('*[code="$attributeCode"]');
                let dependenceAttribute = $('*[code="$dependenceItem"]');
                let update = function() {
                    let currentAttribute = $('*[code="$attributeCode"]');
                    let dependenceAttribute = $('*[code="$dependenceItem"]');
                    // 从ajax获取依赖属性的值，并更新到当前属性
                    $.ajax({
                        url: "$eavDependenceUrl",
                        type: "GET",
                        data: {
                            d: dependenceAttribute.attr("code"),
                            dv: dependenceAttribute.val(),
                            a: "$attributeCode",
                        },
                        success: function(res) {
                            // 渲染到当前属性,如果是input则英文逗号打断数组填入，如果是select则组装option填入
                            let items = res['data'];
                            console.log(items)
                            let values = [];
                            for(key in items) {
                                values.push(items[key]);
                            }
                            let currentAttribute = $('*[code="$attributeCode"]');
                            if(items && currentAttribute.length) {
                                let attributeElement = currentAttribute[0];
                                if(attributeElement.tagName === 'INPUT' || attributeElement.tagName === 'TEXTAREA') {
                                    attributeElement.val(values.join(','));
                                } else if(attributeElement.tagName === 'SELECT') {
                                    let optionItems = [];
                                    for(key in items) {
                                        optionItems.push('<option value="' + key + '">' + items[key] + '</option>');
                                    }
                                    attributeElement.innerHTML = optionItems.join('');
                                }else{
                                    attributeElement.innerHTML = values.join('');
                                }
                                $('*[dependence="$attributeCode"]').parent().css("display", "block");
                            }
                        }
                    })
                }
                
                let value = dependenceAttribute.val();
                let disabled = (value === '' || ((Array.isArray(value) && value.length === 0) || dependenceAttribute.attr("disabled")));
                dependenceAttribute.change(function() {
                    let value = dependenceAttribute.val();
                    let disabled = (value === '' || ((Array.isArray(value) && value.length === 0) || dependenceAttribute.attr("disabled")));
                    if(disabled) {
                        currentAttribute.attr("disabled", disabled);
                        currentAttribute.parent().css("display", "none");
                    }else{
                        currentAttribute.attr("disabled", false);
                        currentAttribute.parent().css("display", "block");
                    }
                   console.log("依赖更新：dependenceAttribute")
                   update();
                });
                if(disabled) {
                    currentAttribute.attr("disabled", true);
                    currentAttribute.parent().css("display", "none");
                    return;
                }
                update();
DEPENDENCE_JS;
                }
                $html .= $js . '});</script>';
            }
        }
    }
}
