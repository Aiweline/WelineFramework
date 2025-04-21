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
    public function execute(Event &$event)
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
            # 检测类是否可以实例化
            $eavEntityReflectionInstance = ObjectManager::getReflectionInstance($eav);
            if (!$eavEntityReflectionInstance->isInstantiable()) {
                continue;
            }
            /**@var \Weline\Eav\EavInterface $eavEntity */
            $eavEntity = ObjectManager::getInstance($eav);
            if ($eavEntity instanceof EavInterface) {
                $eavEntityModel->reset()
                    ->setData(
                        [
                            EavEntity::fields_ID => $eavEntity->getEavEntityId(),
                            EavEntity::fields_code => $eavEntity->getEntityCode(),
                            EavEntity::fields_class => $eav,
                            EavEntity::fields_name => $eavEntity->getEntityName(),
                            EavEntity::fields_is_system => 1,
                            EavEntity::fields_eav_entity_id_field_type => $eavEntity->getEntityFieldIdType(),
                            EavEntity::fields_eav_entity_id_field_length => $eavEntity->getEntityFieldIdLength(),
                        ]
                    )
                    ->forceCheck(true, EavEntity::fields_code)
                    ->save();
                # 检查属性集和属性组，没有则为实体创建默认属性集和默认属性组
                #--属性集
                $attributeSet = $eavEntity->getAttributeSets();
                if (empty($attributeSet)) {
                    /**@var Set $eavAttributeSet */
                    $eavAttributeSet = ObjectManager::make(Set::class);
                    $eavAttributeSet->reset()->clearData()
                        ->insert([
                            'eav_entity_id' => $eavEntity->getEavEntityId(),
                            'name' => '默认属性集',
                            'code' => 'default',
                        ])->fetch();
                }
                # --属性组
                $attributeGroup = $eavEntity->getAttributeGroups();
                if (empty($attributeGroup)) {
                    # 获取默认属性集
                    $attributeSet = $eavEntity->getAttributeSet('default');
                    /**@var Group $eavAttributeGroup */
                    $eavAttributeGroup = ObjectManager::make(Group::class);
                    $eavAttributeGroup->reset()->clearData()
                        ->insert([
                            'set_id' => $attributeSet->getId(),
                            'eav_entity_id' => $eavEntity->getEavEntityId(),
                            'name' => '默认属性组',
                            'code' => 'default',
                        ])->fetch();
                }
            }
        }
    }
}