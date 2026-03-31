<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Service;

use WeShop\Product\Model\Product;
use WeShop\RecentlyViewed\Model\RecentlyViewed;
use Weline\Framework\Manager\ObjectManager;

class RecentlyViewedAdminPageDataService
{
    public function __construct(
        private readonly RecentlyViewedService $recentlyViewedService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getListData(int $page, int $pageSize, array $filters = []): array
    {
        /** @var RecentlyViewed $model */
        $model = ObjectManager::getInstance(RecentlyViewed::class);

        $query = $model->clear()
            ->order(RecentlyViewed::schema_fields_VIEWED_AT, 'DESC');

        // 应用筛选条件
        if (!empty($filters['customer_id'])) {
            $query->where(RecentlyViewed::schema_fields_CUSTOMER_ID, (int) $filters['customer_id']);
        }

        if (!empty($filters['product_id'])) {
            $query->where(RecentlyViewed::schema_fields_PRODUCT_ID, (int) $filters['product_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->where(RecentlyViewed::schema_fields_VIEWED_AT, $filters['date_from'], '>=');
        }

        if (!empty($filters['date_to'])) {
            $query->where(RecentlyViewed::schema_fields_VIEWED_AT, $filters['date_to'], '<=');
        }

        $items = $query->pagination($page, $pageSize)->select()->fetchArray();
        $total = $query->count();

        // 填充产品信息
        foreach ($items as &$item) {
            if (!empty($item['product_id'])) {
                /** @var Product $product */
                $product = ObjectManager::getInstance(Product::class);
                $product->load((int) $item['product_id']);
                if ($product->getId()) {
                    $item['product_name'] = $product->getData('name') ?? '';
                    $item['product_sku'] = $product->getData('sku') ?? '';
                    $item['product_image'] = $product->getData('image') ?? '';
                    $item['product_price'] = $product->getData('price') ?? 0;
                }
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'filters' => $filters,
        ];
    }

    /**
     * 获取统计数据
     *
     * @return array<string, int>
     */
    public function getStatistics(): array
    {
        /** @var RecentlyViewed $model */
        $model = ObjectManager::getInstance(RecentlyViewed::class);

        $totalRecords = $model->clear()->count();

        // 获取今日浏览数
        $today = date('Y-m-d');
        $todayRecords = $model->clear()
            ->where(RecentlyViewed::schema_fields_VIEWED_AT, $today . ' 00:00:00', '>=')
            ->count();

        // 获取本周浏览数
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $weekRecords = $model->clear()
            ->where(RecentlyViewed::schema_fields_VIEWED_AT, $weekStart . ' 00:00:00', '>=')
            ->count();

        // 获取独立客户数
        $distinctCustomers = $model->clear()
            ->fields('DISTINCT ' . RecentlyViewed::schema_fields_CUSTOMER_ID)
            ->select()
            ->fetchArray();
        $uniqueCustomers = count($distinctCustomers);

        return [
            'total_records' => $totalRecords,
            'today_records' => $todayRecords,
            'week_records' => $weekRecords,
            'unique_customers' => $uniqueCustomers,
        ];
    }

    /**
     * 清除所有浏览历史记录
     */
    public function clearAll(): int
    {
        /** @var RecentlyViewed $model */
        $model = ObjectManager::getInstance(RecentlyViewed::class);

        return $model->clear()->delete()->fetch();
    }

    /**
     * 按客户ID清除浏览历史
     */
    public function clearByCustomerId(int $customerId): int
    {
        /** @var RecentlyViewed $model */
        $model = ObjectManager::getInstance(RecentlyViewed::class);

        return $model->clear()
            ->where(RecentlyViewed::schema_fields_CUSTOMER_ID, $customerId)
            ->delete()
            ->fetch();
    }

    /**
     * 清除指定天数之前的浏览历史
     */
    public function clearOlderThanDays(int $days): int
    {
        /** @var RecentlyViewed $model */
        $model = ObjectManager::getInstance(RecentlyViewed::class);
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $model->clear()
            ->where(RecentlyViewed::schema_fields_VIEWED_AT, $cutoffDate, '<')
            ->delete()
            ->fetch();
    }
}
