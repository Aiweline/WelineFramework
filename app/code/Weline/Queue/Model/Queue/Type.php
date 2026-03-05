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
use Weline\Eav\Model\EavAttribute;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Model\Queue\Type\Attributes;
#[Table(comment: '队列类型消费者')]
#[Index(name: 'idx_class', columns: ['class'], type: 'UNIQUE')]
class Type extends \Weline\Framework\Database\Model
{
    public string $module_name = '';
    public const schema_table = 'weline_queue_type';
    public const schema_primary_key = 'type_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'type_id';
    #[Col('varchar', 255, nullable: false, comment: '队列类型名称')]
    public const schema_fields_name = 'name';
    #[Col('varchar', 128, nullable: false, comment: '所属模块')]
    public const schema_fields_module_name = 'module_name';
    #[Col('text', 2000, nullable: false, comment: '提示')]
    public const schema_fields_tip = 'tip';
    #[Col('varchar', 128, nullable: false, comment: '队列类型实现类名')]
    public const schema_fields_class = 'class';
    #[Col('text', comment: '队列属性码')]
    public const schema_fields_attributes = 'attributes';
    #[Col('smallint', 1, nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_enable = 'enable';
public function getTypeId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }
    public function getModuleName(): string
    {
        return $this->getData(self::schema_fields_module_name);
    }
    public function getName(): string
    {
        return $this->getData(self::schema_fields_name);
    }
    public function getEnable(): bool
    {
        return (bool)$this->getData(self::schema_fields_enable);
    }
    public function setEnable(bool $enable): static
    {
        return $this->setData(self::schema_fields_enable, $enable);
    }
    public function getTip(): string
    {
        return $this->getData(self::schema_fields_tip);
    }
    public function getClass(): string
    {
        return $this->getData(self::schema_fields_class) ?? '';
    }
    public function setTypeId(int $type_id): static
    {
        return $this->setData(self::schema_fields_ID, $type_id);
    }
    public function setName(string $name): static
    {
        return $this->setData(self::schema_fields_name, $name);
    }
    public function setTip(string $tip): static
    {
        return $this->setData(self::schema_fields_tip, $tip);
    }
    public function setModuleName(string $module_name): static
    {
        return $this->setData(self::schema_fields_module_name, $module_name);
    }
    public function setClass(string $class): static
    {
        return $this->setData(self::schema_fields_class, $class);
    }
    public function setAttributes(string $attributes): static
    {
        return $this->setData(self::schema_fields_attributes, $attributes);
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
