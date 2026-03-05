<?php

declare(strict_types=1);

namespace WeShop\Store\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Store\Model\Store;

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
            ->where(Store::schema_fields_STATUS, Store::STATUS_ENABLED)
            ->order(Store::schema_fields_NAME, 'ASC')
            ->select()
            ->fetch();

        $options = [];
        foreach ($storeModel->getItems() as $store) {
            if (!$store instanceof Store || !$store->getId()) {
                continue;
            }
            
            // 获取店铺基础描述
            $description = (string)($store->getData(Store::schema_fields_DESCRIPTION) ?? '');
            
            // 获取店铺关联的语言信息
            $languages = [];
            $websiteId = (int)$store->getData(Store::schema_fields_WEBSITE_ID);
            if ($websiteId > 0) {
                try {
                    $languageCodes = w_query('websites', 'getWebsiteLanguageCodes', ['website_id' => $websiteId]);

                    if (!empty($languageCodes)) {
                        foreach ($languageCodes as $code) {
                            $locale = w_query('i18n', 'getLocaleByCode', [
                                'code' => $code,
                                'target_code' => $code,
                            ]);
                            $languages[] = [
                                'code' => $code,
                                'name' => $locale['name'] ?? $code,
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    // 如果获取语言失败，忽略错误
                }
            }
            
            // 获取店铺默认区域信息
            $regions = [];
            $local = (string)($store->getData(Store::schema_fields_LOCAL) ?? '');
            if (!empty($local)) {
                try {
                    $locale = w_query('i18n', 'getLocaleByCode', [
                        'code' => $local,
                        'target_code' => $local,
                    ]);
                    if ($locale !== null) {
                        $regions[] = [
                            'code' => $local,
                            'name' => $locale['name'] ?? $local,
                        ];
                    }
                } catch (\Exception $e) {
                    // 如果获取地区失败，忽略错误
                }
            }
            
            // 构建增强的描述信息
            $descriptionParts = [];
            if (!empty($description)) {
                $descriptionParts[] = $description;
            }
            
            // 添加语言信息
            if (!empty($languages)) {
                $languageNames = array_column($languages, 'name');
                $descriptionParts[] = __('支持语言：%1', implode('、', $languageNames));
            }
            
            // 添加地区信息
            if (!empty($regions)) {
                $regionNames = array_column($regions, 'name');
                $descriptionParts[] = __('支持地区：%1', implode('、', $regionNames));
            }
            
            $enhancedDescription = !empty($descriptionParts) ? implode(' | ', $descriptionParts) : $description;
            
            $options[] = [
                'id'          => (int)$store->getId(),
                'name'        => $store->getData(Store::schema_fields_NAME),
                'description' => $enhancedDescription,
                'meta'        => [
                    'code'       => $store->getData(Store::schema_fields_CODE),
                    'website_id' => $websiteId,
                    'languages'  => $languages,
                    'regions'    => $regions,
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


