<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/6 23:01:21
 */

namespace Weline\Eav\Model\EavAttribute\Type;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Value extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'value_id';
    public const fields_attribute_id = 'attribute_id';
    public const fields_attribute_code = 'attribute_code';
    public const fields_entity_id = 'entity_id';
    public const fields_value = 'value';
    public const fields_swatch_image = 'has_image';
    public const fields_image = 'image';

    public array $_index_sort_keys = [self::fields_attribute_id, self::fields_entity_id, self::fields_attribute_code];

    private ?EavAttribute $attribute = null;

    /**
     * @DESC          # 设置值属性
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/3/9 22:35
     * 参数区：
     *
     * @param \Weline\Eav\Model\EavAttribute $attribute
     *
     * @return $this
     * @throws null
     */
    public function setAttribute(EavAttribute $attribute): static
    {
        if (empty($attribute->getId())) {
            throw new Exception(__('属性不存在！'));
        }
        $this->attribute = $attribute;
        $this->setData(self::fields_attribute_id, $attribute->getId());
        $this->setData(self::fields_attribute_code, $attribute->getCode());
        $this->getTable();
        return $this;
    }

    /**
     * @DESC          # 设置值属性
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/3/9 22:35
     * 参数区：
     *
     * @param string $code
     *
     * @return $this
     */
    public function setAttributeCode(string $code): static
    {
        $this->setData(self::fields_attribute_code, $code);
        return $this;
    }

    public function getAttribute()
    {
        if ($this->attribute) {
            return $this->attribute;
        }
        $this->attribute = ObjectManager::getInstance(EavAttribute::class);
        return $this->attribute->load($this->getAttributeId());
    }

    public function getAttributeId(): int
    {
        return $this->getData(self::fields_attribute_id) ?: 0;
    }

    public function getTable(string $table = ''): string
    {
        if ($table) {
            return parent::getTable($table);
        }
        if (!$this->attribute) {
            throw new Exception(__('属性不存在！'));
        }
        $table = 'eav_' . $this->attribute->current_getEntity()->getEntityCode() . '_' . $this->attribute->getTypeModel()->getCode();
        $this->origin_table_name = parent::getTable($table);
        return $this->origin_table_name;
    }

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
        /**@var \Weline\Eav\Model\EavEntity $entity */
        $entity = ObjectManager::getInstance(\Weline\Eav\Model\EavEntity::class);

        // 创建对应实体类型值表
        /**@var \Weline\Framework\Setup\Db\ModelSetup $setup */
        $setup = ObjectManager::getInstance(\Weline\Framework\Setup\Db\ModelSetup::class);
        /**@var \Weline\Eav\Model\EavAttribute\Type $type */
        $type = ObjectManager::getInstance(\Weline\Eav\Model\EavAttribute\Type::class);

        $types = $type->select()->fetch()->getItems();
        $entities = $entity->clear()->select()->fetch()->getItems();
        /**@var EavEntity $entity */
        foreach ($entities as $entity) {
            /**@var \Weline\Eav\Model\EavAttribute\Type $type */
            foreach ($types as $type) {
                $eav_entity_type_table = $setup->getTable('eav_' . $entity->getCode() . '_' . $type->getCode());
                $setup->dropTable($eav_entity_type_table);
                if (!$setup->tableExist($eav_entity_type_table)) {
                    $table = $setup->createTable('实体' . $entity->getCode() . '的Eav模型' . $type->getCode() . '类型数据表', $eav_entity_type_table);
                    $table->addColumn(
                        self::fields_attribute_code,
                        TableInterface::column_type_VARCHAR,
                        60,
                        'not null ',
                        '属性代码'
                    )->addColumn(
                        self::fields_attribute_id,
                        TableInterface::column_type_INTEGER,
                        11,
                        'not null',
                        '属性ID'
                    )
                        ->addColumn(
                            self::fields_entity_id,
                            $entity->getEntityIdFieldType(),
                            $entity->getEntityIdFieldLength(),
                            'not null',
                            '实体ID'
                        )
                        ->addColumn(
                            self::fields_value,
                            $type->getFieldType(),
                            $type->getFieldLength(),
                            'not null',
                            '实体值'
                        );
                    if ($type->getIsSwatch()) {
                        $table->addColumn($type::fields_is_swatch, TableInterface::column_type_BOOLEAN, 0, 'default 0', '是否有样本');
                        if ($type->hasSwatchImage()) {
                            $table->addColumn($type::fields_swatch_image, TableInterface::column_type_VARCHAR, 255, '', '样本图片');
                        }
                        if ($type->hasSwatchColor()) {
                            $table->addColumn($type::fields_swatch_color, TableInterface::column_type_VARCHAR, 255, '', '样本颜色');
                        }
                        if ($type->hasSwatchText()) {
                            $table->addColumn($type::fields_swatch_text, TableInterface::column_type_VARCHAR, 255, '', '样本文本');
                        }
                    }
                    $table
                        ->addIndex(TableInterface::index_type_KEY, 'EAV_ATTRIBUTE_ID', 'attribute_id')
                        ->addIndex(TableInterface::index_type_KEY, 'EAV_ATTRIBUTE_CODE', 'attribute_code')
                        ->addIndex(TableInterface::index_type_KEY, 'EAV_ENTITY_ID', 'entity_id')
                        ->addConstraints('primary key(`' . self::fields_attribute_id . '`,`' . self::fields_entity_id . '`,`'
                            . self::fields_value . '`)')
                        ->create();
                }
            }
        }
    }

    public function setValueId(int $value_id): static
    {
        return $this->setData(self::fields_ID, $value_id);
    }

    public function getValueId(): int
    {
        return intval($this->getData(self::fields_ID));
    }

    public function setEntityId(string|int $id): static
    {
        return $this->setData(self::fields_entity_id, $id);
    }

    public function getEntityId(): int|string
    {
        return $this->getData(self::fields_entity_id);
    }

    public function setValue(string|int $value): static
    {
        return $this->setData(self::fields_value, $value);
    }

    public function getValue(): string|int
    {
        return $this->getData(self::fields_value);
    }

    public function getSwatchValues(): array
    {
        return [
            'is_swatch' => $this->getIsSwatch(),
            'swatch_image' => $this->getSwatchImage(),
            'swatch_color' => $this->getSwatchColor(),
            'swatch_text' => $this->getSwatchText(),
        ];
    }

    public function getIsSwatch(): bool
    {
        return $this->getData(EavAttribute\Type::fields_is_swatch) ? true : false;
    }


    public function setIsSwatch(bool $is_swatch): static
    {
        if (!$this->getIsSwatch()) {
            throw new Exception(__('此属性没有样本，无法为属性值设置样本！'));
        }
        return $this->setData(EavAttribute\Type::fields_is_swatch, $is_swatch);
    }

    public function getSwatchImage(): string
    {
        return $this->getData(EavAttribute\Type::fields_swatch_image) ?? '';
    }


    public function setSwatchImage(string $swatch_iamge): static
    {
        $this->checkSwatchType();
        return $this->setData(EavAttribute\Type::fields_swatch_image, $swatch_iamge);
    }

    public function getSwatchColor(): string
    {
        return $this->getData(EavAttribute\Type::fields_swatch_color) ?? '';
    }


    public function setSwatchColor(string $swatch_color): static
    {
        $this->checkSwatchType();
        return $this->setData(EavAttribute\Type::fields_swatch_color, $swatch_color);
    }

    public function getSwatchText(): string
    {
        return $this->getData(EavAttribute\Type::fields_swatch_text) ?? '';
    }


    public function setSwatchText(string $swatch_text): static
    {
        $this->checkSwatchType();
        return $this->setData(EavAttribute\Type::fields_swatch_text, $swatch_text);
    }

    private function checkSwatchType()
    {
        if (!$this->getIsSwatch()) {
            throw new Exception(__('此属性没有样本，无法为属性值设置样本！'));
        }
    }
}
