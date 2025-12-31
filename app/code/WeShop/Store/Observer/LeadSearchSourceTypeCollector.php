<?php

declare(strict_types=1);

namespace WeShop\Store\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use WeShop\Store\Model\Store;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\WebsiteLanguage;
use Weline\I18n\Model\Locals;

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
            
            // 获取店铺基础描述
            $description = (string)($store->getData(Store::fields_DESCRIPTION) ?? '');
            
            // 获取店铺关联的语言信息
            $languages = [];
            $websiteId = (int)$store->getData(Store::fields_WEBSITE_ID);
            if ($websiteId > 0) {
                try {
                    /** @var WebsiteLanguage $websiteLanguageModel */
                    $websiteLanguageModel = ObjectManager::getInstance(WebsiteLanguage::class);
                    $languageCodes = $websiteLanguageModel->getWebsiteLanguageCodes($websiteId);
                    
                    if (!empty($languageCodes)) {
                        // 获取语言名称
                        /** @var Locals $localsModel */
                        $localsModel = ObjectManager::getInstance(Locals::class);
                        foreach ($languageCodes as $code) {
                            $locale = $localsModel->clear()
                                ->where(Locals::fields_CODE, $code)
                                ->where(Locals::fields_IS_ACTIVE, 1)
                                ->find()
                                ->fetch();
                            
                            if ($locale->getId()) {
                                $languages[] = [
                                    'code' => $code,
                                    'name' => $locale->getData(Locals::fields_NAME) ?: $code,
                                ];
                            } else {
                                $languages[] = [
                                    'code' => $code,
                                    'name' => $code,
                                ];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // 如果获取语言失败，忽略错误
                }
            }
            
            // 获取店铺默认区域信息
            $regions = [];
            $local = (string)($store->getData(Store::fields_LOCAL) ?? '');
            if (!empty($local)) {
                try {
                    /** @var Locals $localsModel */
                    $localsModel = ObjectManager::getInstance(Locals::class);
                    $locale = $localsModel->clear()
                        ->where(Locals::fields_CODE, $local)
                        ->where(Locals::fields_IS_ACTIVE, 1)
                        ->find()
                        ->fetch();
                    
                    if ($locale->getId()) {
                        $regions[] = [
                            'code' => $local,
                            'name' => $locale->getData(Locals::fields_NAME) ?: $local,
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
                'name'        => $store->getData(Store::fields_NAME),
                'description' => $enhancedDescription,
                'meta'        => [
                    'code'       => $store->getData(Store::fields_CODE),
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


