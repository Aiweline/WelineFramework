<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

use WeShop\Product\Model\Product;
use WeShop\Review\Model\Review;
use Weline\Framework\Manager\ObjectManager;

/**
 * 评分筛选提供者
 * 
 * 提供按用户评分筛选产品的功能
 */
class RatingFilterProvider extends AbstractFilterProvider
{
    /**
     * @var int 最低显示评分
     */
    private int $minRating = 3;
    
    /**
     * @var int 最高评分
     */
    private int $maxRating = 5;
    
    public function __construct()
    {
        $this->sortOrder = 50;
        $this->displayType = 'list';
        $this->icon = 'star';
    }
    
    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'rating';
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return __('用户评分');
    }
    
    /**
     * @inheritDoc
     */
    public function getOptions(int $categoryId, array $productIds, array $appliedFilters = []): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        $andAbove = __('及以上');
        $options = [];
        
        // 从最高评分到最低评分
        for ($rating = $this->maxRating; $rating >= $this->minRating; $rating--) {
            $count = $this->countProductsByRating($productIds, $rating);
            
            // 跳过没有产品的评分
            if ($count === 0) {
                continue;
            }
            
            $value = (string)$rating;
            $stars = str_repeat('★', $rating) . str_repeat('☆', $this->maxRating - $rating);
            $label = $stars . ' ' . $andAbove;
            
            $options[] = [
                'value' => $value,
                'label' => $label,
                'count' => $count,
                'selected' => $this->isValueSelected($value, $appliedFilters),
                'rating' => $rating,
                'stars_filled' => $rating,
                'stars_empty' => $this->maxRating - $rating,
            ];
        }
        
        return $options;
    }
    
    /**
     * @inheritDoc
     */
    public function apply(array $productIds, array $filterValues): array
    {
        if (empty($productIds) || empty($filterValues)) {
            return $productIds;
        }
        
        // 获取最低评分要求（取所有值中最低的）
        $minRating = min(array_map('intval', $filterValues));
        
        if ($minRating < 1 || $minRating > $this->maxRating) {
            return $productIds;
        }
        
        // 获取符合评分要求的产品
        return $this->getProductsByMinRating($productIds, $minRating);
    }
    
    /**
     * 统计特定评分及以上的产品数量
     */
    private function countProductsByRating(array $productIds, int $minRating): int
    {
        // 尝试从评论模块获取评分数据
        try {
            /** @var Review $reviewModel */
            $reviewModel = ObjectManager::getInstance(Review::class);
            
            // 查询有评分且评分 >= minRating 的产品
            $reviewModel->reset()
                ->fields('COUNT(DISTINCT product_id) as count')
                ->where('product_id', $productIds, 'in')
                ->where('rating', $minRating, '>=')
                ->where('status', Review::STATUS_APPROVED); // 已审核的评论
            
            $result = $reviewModel->find()->fetchArray();
            return (int)($result['count'] ?? 0);
        } catch (\Throwable $e) {
            // 如果评论模块不可用，返回所有产品
            return count($productIds);
        }
    }
    
    /**
     * 获取符合最低评分的产品ID
     */
    private function getProductsByMinRating(array $productIds, int $minRating): array
    {
        try {
            /** @var Review $reviewModel */
            $reviewModel = ObjectManager::getInstance(Review::class);
            
            // 查询平均评分 >= minRating 的产品
            $reviewModel->reset()
                ->fields('product_id')
                ->where('product_id', $productIds, 'in')
                ->where('status', Review::STATUS_APPROVED)
                ->groupBy('product_id')
                ->having('AVG(rating)', $minRating, '>=');
            
            $results = $reviewModel->select()->fetchArray();
            
            if (empty($results)) {
                return [];
            }
            
            return array_column($results, 'product_id');
        } catch (\Throwable $e) {
            // 如果评论模块不可用，返回原始产品
            return $productIds;
        }
    }
    
    /**
     * 设置最低显示评分
     */
    public function setMinRating(int $rating): self
    {
        $this->minRating = max(1, min($rating, $this->maxRating));
        return $this;
    }
    
    /**
     * 设置最高评分
     */
    public function setMaxRating(int $rating): self
    {
        $this->maxRating = max(1, $rating);
        return $this;
    }
    
    /**
     * @inheritDoc
     */
    public function getValueLabel(string $value): string
    {
        $rating = (int)$value;
        if ($rating < 1 || $rating > $this->maxRating) {
            return $value;
        }
        
        $stars = str_repeat('★', $rating) . str_repeat('☆', $this->maxRating - $rating);
        return $stars . ' ' . __('及以上');
    }
}
