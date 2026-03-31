<?php

namespace Weline\Eav\Observer;

use Weline\Eav\EavInterface;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Model\Module;

class UpgradeDefaultAttribute implements ObserverInterface
{

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 安装实体
        /**@var \Weline\Framework\Module\Config\ModuleFileReader $moduleFileReader */
        $moduleFileReader = ObjectManager::getInstance(ModuleFileReader::class);

        $modules = Env::getInstance()->getActiveModules();
        $eavs = [];
        foreach ($modules as $module) {
            $eavs = array_merge($eavs, $moduleFileReader->readClass(new Module($module), 'Model'));
        }
        $eavEntityModel = ObjectManager::getInstance(EavEntity::class);
        foreach ($eavs as $eav) {
            try {
                # 检测类是否可以实例化
                $eavEntityReflectionInstance = ObjectManager::getReflectionInstance($eav);
                if (!$eavEntityReflectionInstance->isInstantiable()) {
                    continue;
                }
                // 跳过静态类
                if (ObjectManager::isStaticClass($eav)) {
                    continue;
                }
                /**@var \Weline\Eav\EavInterface $eavEntity */
                $eavEntity = ObjectManager::getInstance($eav);
                if ($eavEntity instanceof EavInterface) {
                    // 单例模型在循环场景会残留上一轮 data/id，必须先 clearData() 再做 forceCheck 保存。
                    $eavEntityModel->clearData()
                        ->setData(
                            [
                                EavEntity::schema_fields_code => $eavEntity->getEntityCode(),
                                EavEntity::schema_fields_class => $eav,
                                EavEntity::schema_fields_name => $eavEntity->getEntityName(),
                                EavEntity::schema_fields_is_system => 1,
                                EavEntity::schema_fields_eav_entity_id_field_type => $eavEntity->getEntityFieldIdType(),
                                EavEntity::schema_fields_eav_entity_id_field_length => $eavEntity->getEntityFieldIdLength(),
                            ]
                        )
                        ->forceCheck(true, EavEntity::schema_fields_code)
                        ->save();
                    
                    // 获取刚保存的实体 ID（从 eavEntityModel 获取，而不是从 eavEntity 获取）
                    // 因为 eavEntity->getEavEntityId() 可能使用了缓存中的旧数据
                    $savedEntityId = $eavEntityModel->getId();
                    
                    # 检查属性集和属性组，没有则为实体创建默认属性集和默认属性组
                    #--属性集
                    /**@var Set $setModel */
                    $setModel = ObjectManager::getInstance(Set::class);
                    $existingSet = $setModel->clearData()
                        ->where('eav_entity_id', $savedEntityId)
                        ->where('code', 'default')
                        ->find()->fetch();
                    if (!$existingSet->getId()) {
                        /**@var Set $eavAttributeSet */
                        $eavAttributeSet = ObjectManager::make(Set::class);
                        $eavAttributeSet->clearData()
                            ->insert([
                                'eav_entity_id' => $savedEntityId,
                                'name' => '默认属性集',
                                'code' => 'default',
                            ])->fetch();
                    }
                    
                    # --属性组
                    /**@var Group $groupModel */
                    $groupModel = ObjectManager::getInstance(Group::class);
                    $existingGroup = $groupModel->clearData()
                        ->where('eav_entity_id', $savedEntityId)
                        ->where('code', 'default')
                        ->find()->fetch();
                    if (!$existingGroup->getId()) {
                        # 获取默认属性集
                        $defaultSet = ObjectManager::getInstance(Set::class);
                        $defaultSet->clearData()
                            ->where('eav_entity_id', $savedEntityId)
                            ->where('code', 'default')
                            ->find()->fetch();
                        
                        if ($defaultSet->getId()) {
                            /**@var Group $eavAttributeGroup */
                            $eavAttributeGroup = ObjectManager::make(Group::class);
                            $eavAttributeGroup->clearData()
                                ->insert([
                                    'set_id' => $defaultSet->getId(),
                                    'eav_entity_id' => $savedEntityId,
                                    'name' => '默认属性组',
                                    'code' => 'default',
                                ])->fetch();
                        }
                    }
                }
            } catch (\Throwable $e) {
                // 如果类不存在或无法实例化，跳过（可能是命名空间转换问题，如 Weline_Bt_Center -> Weline\Bt\Center）
                // 或者类不是 EAV 实体，静默跳过
                continue;
            }
        }
    }
}