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
use Weline\Eav\Api\EavAttribute;
use Weline\Eav\Api\EavModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
#[Table(comment: '队列类型属性表')]
#[Index(name: 'IDX_TYPE_ID', columns: ['type_id'])]
#[Index(name: 'IDX_ATTR_CODE', columns: ['code'])]
#[Index(name: 'IDX_ATTR_NAME', columns: ['name'])]
class Attributes extends Model
{
    public const schema_table = 'weline_queue_type_attributes';
    public const schema_primary_key = 'type_attribute_id';
    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: '队列类型属性ID')]
    public const schema_fields_ID = 'type_attribute_id';
    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: '队列类型属性ID')]
    public const schema_fields_type_attribute_id = 'type_attribute_id';
    #[Col('int', 11, nullable: false, comment: '属性ID')]
    public const schema_fields_attribute_id = 'attribute_id';
    #[Col('int', 11, nullable: false, comment: '队列类型ID')]
    public const schema_fields_type_id = 'type_id';
    #[Col('varchar', 255, nullable: false, comment: '队列类型属性编码')]
    public const schema_fields_code = 'code';
    #[Col('varchar', 255, nullable: false, comment: '队列类型属性名称')]
    public const schema_fields_name = 'name';
    public array $_index_sort_keys = [self::schema_fields_ID, self::schema_fields_type_id, self::schema_fields_name, self::schema_fields_code];

    public function setTypeId(int $type_id): static
    {
        return $this->setData(self::schema_fields_type_id, $type_id);
    }
    public function setCode(string $code): static
    {
        return $this->setData(self::schema_fields_code, $code);
    }
    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_name, $name);
    }
    public function getTypeId(): int
    {
        return (int)$this->getData(self::schema_fields_type_attribute_id);
    }
    public function getCode(): string
    {
        return (string)$this->getData(self::schema_fields_code);
    }
    public function getName(): string
    {
        return (string)$this->getData(self::schema_fields_name);
    }
    public function getAttributeId(): int
    {
        return (int)$this->getData(self::schema_fields_attribute_id);
    }
    public function setAttributeId(int $attribute_id): static
    {
        return $this->setData(self::schema_fields_attribute_id, $attribute_id);
    }
    public function getAttributesByTypeId(int $type_id, array $options = []): array
    {
        $type_attributes = $this->reset()
            ->fields(self::schema_fields_attribute_id.', '.self::schema_fields_name)
            ->where(self::schema_fields_type_id, $type_id)
            ->select()
            ->fetchArray();
        if (empty($type_attributes)) {
            return [];
        }
        $typeAttributeNames = [];
        foreach ($type_attributes as $typeAttribute) {
            $typeAttributeNames[$typeAttribute[self::schema_fields_attribute_id]] = $typeAttribute[self::schema_fields_name];
        }
        $type_attributes_ids = array_column($type_attributes, EavAttribute::schema_fields_ID);
        /**@var EavModel $entity */
        $entity        = $options['entity'] ?? null;
        $eav_entity_id = $options['eav_entity_id'] ?? null;
        /** @var EavAttribute $attribute */
        $attribute = ObjectManager::getInstance(EavAttribute::class);
        $wheres    = [
            [EavAttribute::schema_fields_ID, 'IN', $type_attributes_ids],
        ];
        if ($entity) {
            $wheres[] = [EavAttribute::schema_fields_eav_entity_id, '=', $entity->getEavEntityId()];
        } elseif ($eav_entity_id) {
            $wheres[] = [EavAttribute::schema_fields_eav_entity_id, '=', $eav_entity_id];
        }
        $attributes = $attribute->reset()->clearData()
            ->where($wheres)
            ->order(EavAttribute::schema_fields_dependence, 'ASC')
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
    public function getAttributesByTypeCode(int $type_id, string $code, array $options = []): EavAttribute|null
    {
        $type_code_attribute = $this->reset()
            ->fields(self::schema_fields_code.','.self::schema_fields_name)
            ->where(self::schema_fields_type_id, $type_id)
            ->where(self::schema_fields_code, $code)
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
            [EavAttribute::schema_fields_code, '=', $code],
        ];
        if ($entity) {
            $wheres[] = [EavAttribute::schema_fields_eav_entity_id, '=', $entity->getEavEntityId()];
        }elseif ($eav_entity_id) {
            $wheres[] = [EavAttribute::schema_fields_eav_entity_id, '=', $eav_entity_id];
        }
        $attribute
            ->where($wheres)
            ->order(EavAttribute::schema_fields_dependence, 'ASC')
            ->find()
            ->fetch();
        $options_data = $options;
        if ($entity) {
            $attribute->current_setEntity($entity);
        }
        $name = __($type_code_attribute[self::schema_fields_name]);
        $attribute->setName($name);
        $options_data['attrs']['placeholder'] = $name;
        if (empty($options['no_html'])) {
            $attribute->setData('html', $attribute->getHtml($options_data));
        }
        return $attribute;
    }
}
