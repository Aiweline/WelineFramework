<?php

declare(strict_types=1);

namespace WeShop\Store\Service;

use Weline\AutoLeadAgent\Model\LeadCandidate;
use Weline\AutoLeadAgent\Service\SourceTypeHandlerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;
use WeShop\Store\Model\Store;

/**
 * 自动寻客 店铺来源类型处理器
 *
 * 说明：
 * - 目前主要用于向自动寻客系统提供店铺选项和详情信息；
 * - 实际的寻客逻辑（processTask）可以在后续阶段逐步完善。
 */
class LeadSearchHandler implements SourceTypeHandlerInterface
{
    public function getType(): string
    {
        return 'store';
    }

    public function getName(): string
    {
        return (string)__('店铺');
    }

    public function getOptions(): array
    {
        /** @var Store $storeModel */
        $storeModel = ObjectManager::getInstance(Store::class);
        $storeModel->clear()
            ->where(Store::schema_fields_STATUS, Store::STATUS_ENABLED)
            ->order(Store::schema_fields_NAME, 'ASC')
            ->fetch();

        $options = [];
        foreach ($storeModel->getItems() as $store) {
            if (!$store instanceof Store || !$store->getId()) {
                continue;
            }
            $options[] = [
                'id'          => (int)$store->getId(),
                'name'        => $store->getData(Store::schema_fields_NAME),
                'description' => (string)($store->getData(Store::schema_fields_DESCRIPTION) ?? ''),
            ];
        }

        return $options;
    }

    public function getDetail(int $id): array
    {
        /** @var Store $storeModel */
        $storeModel = ObjectManager::getInstance(Store::class);
        $store = $storeModel->load($id);
        if (!$store->getId()) {
            return [];
        }

        // 获取语言和货币信息（从关联的Website获取）
        $language = null;
        $currency = null;
        $languages = [];
        
        $websiteId = $store->getWebsiteId();
        if ($websiteId) {
            try {
                /** @var Website $websiteModel */
                $websiteModel = ObjectManager::getInstance(Website::class);
                $website = $websiteModel->load($websiteId);
                
                if ($website->getId()) {
                    // 获取默认语言和货币
                    $language = $website->getDefaultLanguage();
                    $currency = $website->getDefaultCurrency();
                    
                    // 获取支持的语言列表
                    $languages = $website->getLanguageCodes();
                    if (empty($languages) && $language) {
                        // 如果没有关联语言列表，至少包含默认语言
                        $languages = [$language];
                    }
                }
            } catch (\Throwable $e) {
                // 静默失败，使用默认值
                w_log_error('LeadSearchHandler: Failed to get website language/currency: ' . $e->getMessage());
            }
        }
        
        // 如果从Website获取失败，尝试从框架State获取
        if (empty($language)) {
            try {
                $language = \Weline\Framework\App\State::getLang();
            } catch (\Throwable $e) {
                $language = 'zh';
            }
        }
        
        if (empty($currency)) {
            try {
                $currency = \Weline\Framework\App\State::getCurrency();
            } catch (\Throwable $e) {
                $currency = 'CNY';
            }
        }
        
        // 如果languages为空，至少包含默认语言
        if (empty($languages)) {
            $languages = [$language ?: 'zh'];
        }

        return [
            'id'          => (int)$store->getId(),
            'name'        => $store->getData(Store::schema_fields_NAME),
            'description' => (string)($store->getData(Store::schema_fields_DESCRIPTION) ?? ''),
            'meta_title'  => $store->getData(Store::schema_fields_META_TITLE),
            'meta_description' => $store->getData(Store::schema_fields_META_DESCRIPTION),
            'meta_keywords'    => $store->getData(Store::schema_fields_META_KEYWORDS),
            'address'     => $store->getData(Store::schema_fields_ADDRESS),
            'language'    => $language,  // 添加语言字段
            'currency'   => $currency,   // 添加货币字段
            'languages'  => $languages,  // 添加支持的语言列表
        ];
    }

    public function processTask(int $taskId, int $sourceId): void
    {
        // 这里预留给实际寻客逻辑，目前只做占位示例，确保接口打通
        // 后续可以接入 AI / 外部站点搜索，写入 LeadCandidate 等。

        /** @var LeadCandidate $candidateModel */
        $candidateModel = ObjectManager::getInstance(LeadCandidate::class);

        $candidateModel->clear()
            ->setData(LeadCandidate::schema_fields_STORE_ID, $sourceId)
            ->setData(LeadCandidate::schema_fields_PROFILE_DATA, json_encode([
                'note' => '示例候选客户，等待后续实现真实寻客逻辑。',
            ], JSON_UNESCAPED_UNICODE))
            ->setData(LeadCandidate::schema_fields_SCORE, 80.00)
            ->setData(LeadCandidate::schema_fields_SOURCE_URL, 'https://example.com')
            ->setData(LeadCandidate::schema_fields_STATUS, LeadCandidate::STATUS_PENDING)
            ->save();
    }
}


