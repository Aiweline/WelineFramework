<?php

declare(strict_types=1);

namespace WeShop\Store\Service;

use Weline\AutoLeadAgent\Model\LeadCandidate;
use Weline\AutoLeadAgent\Service\SourceTypeHandlerInterface;
use Weline\Framework\Manager\ObjectManager;
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
            ->where(Store::fields_STATUS, Store::STATUS_ENABLED)
            ->order(Store::fields_NAME, 'ASC')
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

        return [
            'id'          => (int)$store->getId(),
            'name'        => $store->getData(Store::fields_NAME),
            'description' => (string)($store->getData(Store::fields_DESCRIPTION) ?? ''),
            'meta_title'  => $store->getData(Store::fields_META_TITLE),
            'meta_description' => $store->getData(Store::fields_META_DESCRIPTION),
            'meta_keywords'    => $store->getData(Store::fields_META_KEYWORDS),
            'address'     => $store->getData(Store::fields_ADDRESS),
        ];
    }

    public function processTask(int $taskId, int $sourceId): void
    {
        // 这里预留给实际寻客逻辑，目前只做占位示例，确保接口打通
        // 后续可以接入 AI / 外部站点搜索，写入 LeadCandidate 等。

        /** @var LeadCandidate $candidateModel */
        $candidateModel = ObjectManager::getInstance(LeadCandidate::class);

        $candidateModel->clear()
            ->setData(LeadCandidate::fields_STORE_ID, $sourceId)
            ->setData(LeadCandidate::fields_PROFILE_DATA, json_encode([
                'note' => '示例候选客户，等待后续实现真实寻客逻辑。',
            ], JSON_UNESCAPED_UNICODE))
            ->setData(LeadCandidate::fields_SCORE, 80.00)
            ->setData(LeadCandidate::fields_SOURCE_URL, 'https://example.com')
            ->setData(LeadCandidate::fields_STATUS, LeadCandidate::STATUS_PENDING)
            ->save();
    }
}


