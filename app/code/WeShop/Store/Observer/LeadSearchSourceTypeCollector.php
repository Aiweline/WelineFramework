<?php

declare(strict_types=1);

namespace WeShop\Store\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use WeShop\Store\Model\Store;
use Weline\Framework\Manager\ObjectManager;

/**
 * 自动寻客来源类型收集 - 店铺类型观察者
 *
 * 通过事件向自动寻客模块提供：
 * - 类型标识：store
 * - 类型名称：店铺
 * - 可选项：所有启用状态的店铺列表
 */
class LeadSearchSourceTypeCollector implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $sourceTypes = $data['source_types'] ?? [];
        if (!is_array($sourceTypes)) {
            $sourceTypes = [];
        }

        /** @var Store $storeModel */
        $storeModel = ObjectManager::getInstance(Store::class);
        $storeModel->clear()
            ->where(Store::fields_STATUS, Store::STATUS_ENABLED)
            ->order(Store::fields_NAME, 'ASC')
            ->select()
            ->fetch();

        $options = [];
        foreach ($storeModel->getItems() as $store) {
            if (!$store instanceof Store || !$store->getId()) {
                continue;
            }
            $options[] = [
                'id'          => (int)$store->getId(),
                'name'        => $store->getData(Store::fields_NAME),
                'description' => (string)($store->getData(Store::fields_DESCRIPTION) ?? ''),
                'meta'        => [
                    'code'       => $store->getData(Store::fields_CODE),
                    'website_id' => $store->getData(Store::fields_WEBSITE_ID),
                ],
            ];
        }

        $sourceTypes[] = [
            'type'          => 'store',
            'name'          => (string)__('店铺'),
            'handler_class' => \WeShop\Store\Service\LeadSearchHandler::class,
            'options'       => $options,
        ];

        $event->setData('source_types', $sourceTypes);
    }
}


