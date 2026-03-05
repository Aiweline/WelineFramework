<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/15
 * 描述：产品删除后观察者 - 清理库存记录
 */

namespace WeShop\Inventory\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use WeShop\Inventory\Model\SourceItem;

class ProductDeleteAfter implements ObserverInterface
{
    private SourceItem $sourceItem;

    public function __construct(SourceItem $sourceItem)
    {
        $this->sourceItem = $sourceItem;
    }

    /**
     * 产品删除后执行 - 清理所有库存源中该产品的库存记录
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $productId = $data['product_id'] ?? 0;

        if (!$productId) {
            return;
        }

        // 删除该产品在所有库存源的库存记录
        $this->sourceItem->reset()
            ->where(SourceItem::schema_fields_PRODUCT_ID, $productId)
            ->delete()
            ->fetch();
    }
}

