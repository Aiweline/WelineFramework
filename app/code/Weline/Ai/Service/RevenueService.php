<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiAssistantRevenue;
use Weline\Ai\Model\AiAssistantRental;
use Weline\Ai\Model\AiAssistant;
use Weline\Frontend\Model\FrontendUser;

/**
 * 收入统计服务
 */
class RevenueService
{
    private AiAssistantRevenue $aiAssistantRevenue;
    private AiAssistantRental $aiAssistantRental;
    private AiAssistant $aiAssistant;
    private FrontendUser $frontendUser;

    public function __construct(
        AiAssistantRevenue $aiAssistantRevenue,
        AiAssistantRental $aiAssistantRental,
        AiAssistant $aiAssistant,
        FrontendUser $frontendUser
    ) {
        $this->aiAssistantRevenue = $aiAssistantRevenue;
        $this->aiAssistantRental = $aiAssistantRental;
        $this->aiAssistant = $aiAssistant;
        $this->frontendUser = $frontendUser;
    }

    /**
     * 获取用户收入统计
     */
    public function getUserRevenue(int $userId, string $period = 'total'): array
    {
        $revenue = $this->aiAssistantRevenue->reset()
            ->where('user_id', $userId)
            ->where('assistant_id', 0) // 用户总体统计
            ->where('period_type', $period)
            ->select()
            ->fetch()
            ->getItem();

        if ($revenue && $revenue->getId()) {
            return [
                'total_revenue' => (float)$revenue->getData('revenue_total'),
                'platform_fee' => (float)$revenue->getData('platform_fee'),
                'actual_revenue' => (float)$revenue->getData('actual_revenue'),
                'rental_count' => (int)$revenue->getData('rental_count'),
                'usage_count' => (int)$revenue->getData('usage_count'),
                'period' => $period
            ];
        }

        return [
            'total_revenue' => 0.0,
            'platform_fee' => 0.0,
            'actual_revenue' => 0.0,
            'rental_count' => 0,
            'usage_count' => 0,
            'period' => $period
        ];
    }

    /**
     * 获取助手收入统计
     */
    public function getAssistantRevenue(int $assistantId, string $period = 'total'): array
    {
        $revenue = $this->aiAssistantRevenue->reset()
            ->where('assistant_id', $assistantId)
            ->where('period_type', $period)
            ->select()
            ->fetch()
            ->getItem();

        if ($revenue && $revenue->getId()) {
            return [
                'total_revenue' => (float)$revenue->getData('revenue_total'),
                'platform_fee' => (float)$revenue->getData('platform_fee'),
                'actual_revenue' => (float)$revenue->getData('actual_revenue'),
                'rental_count' => (int)$revenue->getData('rental_count'),
                'usage_count' => (int)$revenue->getData('usage_count'),
                'rating_average' => (float)$revenue->getData('rating_average'),
                'rating_count' => (int)$revenue->getData('rating_count'),
                'period' => $period
            ];
        }

        return [
            'total_revenue' => 0.0,
            'platform_fee' => 0.0,
            'actual_revenue' => 0.0,
            'rental_count' => 0,
            'usage_count' => 0,
            'rating_average' => 0.0,
            'rating_count' => 0,
            'period' => $period
        ];
    }

    /**
     * 获取收入排行榜
     */
    public function getRevenueRanking(string $period = 'monthly', int $limit = 10): array
    {
        $revenues = $this->aiAssistantRevenue->reset()
            ->where('period_type', $period)
            ->where('assistant_id', '>', 0) // 只统计助手，不包含用户总体
            ->order('actual_revenue', 'DESC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();

        $ranking = [];
        $rank = 1;

        foreach ($revenues as $revenue) {
            $userId = $revenue->getData('user_id');
            $assistantId = $revenue->getData('assistant_id');

            // 获取用户和助手信息
            $user = $this->frontendUser->reset()->load($userId);
            $assistant = $this->aiAssistant->reset()->load($assistantId);

            $ranking[] = [
                'rank' => $rank++,
                'user_id' => $userId,
                'user_name' => $user->getData('username') ?? 'User ' . $userId,
                'assistant_id' => $assistantId,
                'assistant_name' => $assistant->getData('name') ?? 'Unknown',
                'total_revenue' => (float)$revenue->getData('revenue_total'),
                'actual_revenue' => (float)$revenue->getData('actual_revenue'),
                'rental_count' => (int)$revenue->getData('rental_count'),
                'usage_count' => (int)$revenue->getData('usage_count'),
                'rating_average' => (float)$revenue->getData('rating_average'),
            ];
        }

        return $ranking;
    }

    /**
     * 更新收入统计（当有新的租用时调用）
     */
    public function updateRevenue(int $assistantId, float $amount, string $paymentStatus = 'completed'): void
    {
        if ($paymentStatus !== 'completed') {
            return; // 只统计已完成支付的
        }

        // 获取助手信息
        $assistant = $this->aiAssistant->reset()->load($assistantId);
        if (!$assistant->getId()) {
            return;
        }

        $userId = $assistant->getData('owner_id');
        $platformFeeRate = 0.1; // 平台抽成10%
        $platformFee = $amount * $platformFeeRate;
        $actualRevenue = $amount - $platformFee;

        // 更新不同周期的统计
        $periods = ['daily', 'monthly', 'yearly', 'total'];
        
        foreach ($periods as $period) {
            $periodKey = $this->getCurrentPeriodKey($period);
            
            // 更新助手收入
            $this->updateOrCreateRevenue([
                'user_id' => $userId,
                'assistant_id' => $assistantId,
                'period_type' => $period,
                'period_key' => $periodKey,
            ], $amount, $platformFee, $actualRevenue);

            // 更新用户总收入
            $this->updateOrCreateRevenue([
                'user_id' => $userId,
                'assistant_id' => 0, // 0表示用户总体
                'period_type' => $period,
                'period_key' => $periodKey,
            ], $amount, $platformFee, $actualRevenue);
        }
    }

    /**
     * 更新或创建收入记录
     */
    private function updateOrCreateRevenue(array $conditions, float $amount, float $platformFee, float $actualRevenue): void
    {
        $revenue = $this->aiAssistantRevenue->reset();
        
        foreach ($conditions as $field => $value) {
            $revenue->where($field, $value);
        }
        
        $existingRevenue = $revenue->select()->fetch()->getItem();

        if ($existingRevenue && $existingRevenue->getId()) {
            // 更新现有记录
            $existingRevenue->setData([
                'revenue_total' => (float)$existingRevenue->getData('revenue_total') + $amount,
                'platform_fee' => (float)$existingRevenue->getData('platform_fee') + $platformFee,
                'actual_revenue' => (float)$existingRevenue->getData('actual_revenue') + $actualRevenue,
                'rental_count' => (int)$existingRevenue->getData('rental_count') + 1,
                'updated_time' => time()
            ]);
            $existingRevenue->save();
        } else {
            // 创建新记录
            $newRevenue = $this->aiAssistantRevenue->reset();
            $newRevenue->setData(array_merge($conditions, [
                'revenue_total' => $amount,
                'platform_fee' => $platformFee,
                'actual_revenue' => $actualRevenue,
                'rental_count' => 1,
                'usage_count' => 0,
                'created_time' => time(),
                'updated_time' => time()
            ]));
            $newRevenue->save();
        }
    }

    /**
     * 获取当前周期键
     */
    private function getCurrentPeriodKey(string $period): string
    {
        switch ($period) {
            case 'daily':
                return date('Y-m-d');
            case 'monthly':
                return date('Y-m');
            case 'yearly':
                return date('Y');
            case 'total':
            default:
                return 'total';
        }
    }
}

