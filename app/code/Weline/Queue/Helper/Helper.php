<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：23/4/2024 17:53:42
 */

namespace Weline\Queue\Helper;

use Weline\Eav\Api\EavAttribute;
use Weline\Framework\App\Env;
use Weline\Framework\Async\TaskConsumerInterface as FrameworkTaskConsumerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Model\Module;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Model\Queue\Type;
use Weline\Queue\QueueInterface as LegacyQueueInterface;

class Helper
{
    static function collect(): void
    {
        /** @var ModuleFileReader $reader */
        $reader = ObjectManager::getInstance(ModuleFileReader::class);
        /** @var Type $type */
        $type = ObjectManager::getInstance(Type::class);
        /** @var Type\Attributes $queueTypeAttributeModel */
        $queueTypeAttributeModel = ObjectManager::getInstance(Type\Attributes::class);
        $modules = Env::getInstance()->getActiveModules();
        foreach ($modules as $module) {
            $queue_files = $reader->readClass(new Module($module), 'Queue');
            foreach ($queue_files as $queue_class) {
                try {
                    $queue_ref = ObjectManager::getReflectionInstance($queue_class);
                    $isFrameworkConsumer = $queue_ref->implementsInterface(FrameworkTaskConsumerInterface::class);
                    $isPublicConsumer = $queue_ref->implementsInterface(QueueConsumerInterface::class);
                    $isLegacyConsumer = $queue_ref->implementsInterface(LegacyQueueInterface::class);
                    if (!$queue_ref->isInstantiable() || (!$isFrameworkConsumer && !$isPublicConsumer && !$isLegacyConsumer)) {
                        continue;
                    }
                    /** @var FrameworkTaskConsumerInterface|QueueConsumerInterface|LegacyQueueInterface $queue */
                    $queue = ObjectManager::getInstance($queue_class);
                } catch (\Exception $e) {
                    continue;
                }
                $type->reset()->where(Type::schema_fields_class, $queue::class)
                    ->find()
                    ->fetch();
                $type_id = (int)$type->getId();
                if ($type_id) {
                    $type->reset()->clearData();
                    $type->where($type::schema_fields_ID, $type_id);
                    $type->update([
                        Type::schema_fields_name => $queue->name(),
                        Type::schema_fields_module_name => $module['name'],
                        Type::schema_fields_tip => $queue->tip(),
                        Type::schema_fields_class => $queue::class,
                        Type::schema_fields_enable => method_exists($queue, 'enable') ? $queue->enable() : true
                    ])
                        ->fetch();
                } else {
                    $type->reset()->clearData();
                    $type_id = $type->setModelFieldsData([
                        Type::schema_fields_name => $queue->name(),
                        Type::schema_fields_module_name => $module['name'],
                        Type::schema_fields_tip => $queue->tip(),
                        Type::schema_fields_class => $queue::class,
                        Type::schema_fields_attributes => '',
                        Type::schema_fields_enable => method_exists($queue, 'enable') ? $queue->enable() : true
                    ])->save(true);
                }
                # 属性更新
                /** @var EavAttribute[] $attrs */
                $attrs = $queue->attributes();
                foreach ($attrs as $attr) {
                    if (!($attr instanceof EavAttribute)) {
                        throw new \Exception(__('队列类：%{1} 属性错误。 队列属性必须继承自 %{2}', [
                            $queue_class,
                            EavAttribute::class
                        ]));
                    }
                }
                $attrsCodes = array_map(function (EavAttribute $attr) {
                    return $attr->getCode();
                }, $attrs);
                if ($attrsCodes) {
                    $type->reset()->where($type::schema_fields_ID, $type_id)
                        ->update($type::schema_fields_attributes, implode(',', $attrsCodes))
                        ->fetch();
                }
                # 写入类型属性
                $attrIds = [];
                foreach ($attrs as $attr) {
                    $queueTypeAttributeModel->clearData()->reset()
                        ->where($queueTypeAttributeModel::schema_fields_type_id, $type_id)
                        ->where($queueTypeAttributeModel::schema_fields_code, $attr->getCode())
                        ->find()
                        ->fetch();
                    if ($queueTypeAttributeModel->getId()) {
                        $queueTypeAttributeModel->reset()
                            ->where($queueTypeAttributeModel::schema_fields_code, $attr->getCode())
                            ->where($queueTypeAttributeModel::schema_fields_type_id, $type_id)
                            ->update($queueTypeAttributeModel::schema_fields_name, $attr->getName())
                            ->update($queueTypeAttributeModel::schema_fields_attribute_id, $attr->getId())
                            ->fetch();
                    } else {
                        $queueTypeAttributeModel
                            ->setTypeId($type_id)
                            ->setAttributeId((int)$attr->getId())
                            ->setData($queueTypeAttributeModel::schema_fields_code, $attr->getCode())
                            ->setData($queueTypeAttributeModel::schema_fields_name, $attr->getName())
                            ->save();
                    }
                    $attrIds[] = $attr->getId();
                }
                # 不存在的属性数据进行删除清理
//                p($queueTypeAttributeModel
//                    ->clearData()
//                    ->reset()
//                    ->where($queueTypeAttributeModel::schema_fields_type_id, $type_id)
//                    ->where($queueTypeAttributeModel::schema_fields_attribute_id, $attrIds, 'not in')
//                    ->getQuery()
//                    ->delete()->bound_values);
                if ($attrIds) {
                    /**@var Type\Attributes[] $notBeLongTypeAttrs */
                    $notBeLongTypeAttrs = $queueTypeAttributeModel
                        ->clearData()
                        ->reset()
                        ->where($queueTypeAttributeModel::schema_fields_type_id, $type_id)
                        ->where($queueTypeAttributeModel::schema_fields_attribute_id, $attrIds, 'not in')
                        ->select()
                        ->fetch()
                        ->getItems();
                    # 先查找不属于当前队列的属性
                    /** @var EavAttribute $eavAttribute */
                    $eavAttribute = ObjectManager::getInstance(EavAttribute::class);
                    foreach ($notBeLongTypeAttrs as $notBeLongTypeAttr) {
                        $eavAttribute->load($notBeLongTypeAttr->getAttributeId());
                        $valueTable = $eavAttribute->getEavEntityAttributeValueTable();
                        $query = $eavAttribute->getQuery(false);
                        # 删除属性相关数据
                        $query->reset()
                            ->table($valueTable)
                            ->where('attribute_id', $notBeLongTypeAttr->getAttributeId())
                            ->delete()
                            ->fetch();
                        # 删除属性
                        $eavAttribute->delete()->fetch();
                    }

                    # 删除队列类型属性关系
                    $queueTypeAttributeModel
                        ->clearData()
                        ->reset()
                        ->where($queueTypeAttributeModel::schema_fields_type_id, $type_id)
                        ->where($queueTypeAttributeModel::schema_fields_attribute_id, $attrIds, 'not in')
                        ->delete()
                        ->fetch();
                }
            }
        }
    }
}
