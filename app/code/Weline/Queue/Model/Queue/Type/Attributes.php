<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：23/4/2024 17:07:22
 */

namespace Weline\Queue\Model\Queue\Type;

use Weline\Eav\EavModel;
use Weline\Eav\EavModelInterface;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Attributes extends Model
{
    public const fields_ID = 'type_attribute_id';

    public const fields_type_attribute_id = 'type_attribute_id';
    public const fields_attribute_id = 'attribute_id';
    public const fields_type_id = 'type_id';
    public const fields_code = 'code';
    public const fields_name = 'name';

    public array $_index_sort_keys = [self::fields_ID, self::fields_type_id, self::fields_name, self::fields_code];

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
            $setup->createTable('队列类型属性表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    11,
                    'primary key auto_increment',
                    '队列类型属性ID')
                ->addColumn(
                    self::fields_type_id,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '队列类型ID')
                ->addColumn(
                    self::fields_attribute_id,
                    TableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '属性ID'
                )->addColumn(
                    self::fields_code,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '队列类型属性编码')
                ->addColumn(
                    self::fields_name,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '队列类型属性名称')
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'IDX_TYPE_ID',
                    self::fields_type_id,
                    '队列类型ID索引')
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'IDX_ATTR_CODE',
                    self::fields_code,
                    '队列类型属性编码索引')
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'IDX_ATTR_NAME',
                    self::fields_name,
                    '队列类型属性名索引')
                ->create();
        }
    }

    function setTypeId(int $type_id): static
    {
        return $this->setData(self::fields_type_id, $type_id);
    }

    function setCode(string $code): static
    {
        return $this->setData(self::fields_code, $code);
    }

    function setName(string $name): static
    {
        return $this->setData(self::fields_name, $name);
    }

    function getTypeId(): int
    {
        return (int)$this->getData(self::fields_type_attribute_id);
    }

    function getCode(): string
    {
        return (string)$this->getData(self::fields_code);
    }

    function getName(): string
    {
        return (string)$this->getData(self::fields_name);
    }

    function getAttributeId(): int
    {
        return (int)$this->getData(self::fields_attribute_id);
    }

    function setAttributeId(int $attribute_id): static
    {
        return $this->setData(self::fields_attribute_id, $attribute_id);
    }

    function getAttributesByTypeId(int $type_id, array $options = []): array
    {
        $type_attributes = $this->reset()
            ->fields(self::fields_attribute_id.', '.self::fields_name)
            ->where(self::fields_type_id, $type_id)
            ->select()
            ->fetchArray();
        if (empty($type_attributes)) {
            return [];
        }
        $typeAttributeNames = [];
        foreach ($type_attributes as $typeAttribute) {
            $typeAttributeNames[$typeAttribute[self::fields_attribute_id]] = $typeAttribute[self::fields_name];
        }
        $type_attributes_ids = array_column($type_attributes, EavAttribute::fields_ID);
        /**@var EavModel $entity */
        $entity        = $options['entity'] ?? null;
        $eav_entity_id = $options['eav_entity_id'] ?? null;
        /** @var EavAttribute $attribute */
        $attribute = ObjectManager::getInstance(EavAttribute::class);
        $wheres    = [
            [EavAttribute::fields_ID, 'IN', $type_attributes_ids],
        ];
        if ($entity) {
            $wheres[] = [EavAttribute::fields_eav_entity_id, '=', $entity->getEavEntityId()];
        } elseif ($eav_entity_id) {
            $wheres[] = [EavAttribute::fields_eav_entity_id, '=', $eav_entity_id];
        }

        $attributes = $attribute->reset()->clearData()
            ->where($wheres)
            ->order(EavAttribute::fields_dependence, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
//            ->getLastSql();
        $options_data = $options;
        /** @var EavAttribute $attr */
        foreach ($attributes as $attr_key => $attr) {
            if ($entity) {
                $attr->current_setEntity($entity);
            }
            $name = __($typeAttributeNames[$attr->getId()]);
            $attr->setName($name);
            $options_data['attrs']['placeholder'] = $name;
            if (empty($options['no_html'])) {
                $attr->setData('html', $attr->getHtml($options_data));
            }
            $attributes[$attr_key] = $attr;
        }
        return $attributes;
    }

    function getAttributesByTypeCode(int $type_id, string $code, array $options = []): EavAttribute|null
    {
        $type_code_attribute = $this->reset()
            ->fields(self::fields_code.','.self::fields_name)
            ->where(self::fields_type_id, $type_id)
            ->where(self::fields_code, $code)
            ->find()
            ->fetchArray();
        if (empty($type_code_attribute)) {
            return null;
        }
        /**@var EavModel $entity */
        $entity = $options['entity'] ?? null;
        $eav_entity_id = $options['eav_entity_id'] ?? null;
        /** @var EavAttribute $attribute */
        $attribute = ObjectManager::make(EavAttribute::class);
        $wheres    = [
            [EavAttribute::fields_code, '=', $code],
        ];
        if ($entity) {
            $wheres[] = [EavAttribute::fields_eav_entity_id, '=', $entity->getEavEntityId()];
        }elseif ($eav_entity_id) {
            $wheres[] = [EavAttribute::fields_eav_entity_id, '=', $eav_entity_id];
        }
        $attribute
            ->where($wheres)
            ->order(EavAttribute::fields_dependence, 'ASC')
            ->find()
            ->fetch();
        $options_data = $options;
        if ($entity) {
            $attribute->current_setEntity($entity);
        }
        $name = __($type_code_attribute[self::fields_name]);
        $attribute->setName($name);
        $options_data['attrs']['placeholder'] = $name;
        if (empty($options['no_html'])) {
            $attribute->setData('html', $attribute->getHtml($options_data));
        }
        return $attribute;
    }
}