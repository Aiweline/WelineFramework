<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Campaign\Campaign;
use Weline\Marketing\Model\Rule\Rule;
use Weline\Marketing\Model\RuleUsage\RuleUsage;

/**
 * 活动服务
 * 
 * @package Weline_Marketing
 */
class CampaignService
{
    /**
     * 创建活动
     *
     * @param array $data 活动数据
     * @return Campaign
     * @throws \Exception
     */
    public function createCampaign(array $data): Campaign
    {
        /** @var Campaign $campaign */
        $campaign = ObjectManager::getInstance(Campaign::class);
        $campaign->setData($data);
        $campaign->save();

        return $campaign;
    }

    /**
     * 获取活动统计
     *
     * @param int $campaignId
     * @return array
     */
    public function getStatistics(int $campaignId): array
    {
        /** @var Campaign $campaign */
        $campaign = ObjectManager::getInstance(Campaign::class);
        $campaign->load($campaignId);

        if (!$campaign->getId()) {
            return [];
        }

        $ruleId = $campaign->getData(Campaign::schema_fields_RULE_ID);
        if (!$ruleId) {
            return [];
        }

        /** @var RuleUsage $ruleUsage */
        $ruleUsage = ObjectManager::getInstance(RuleUsage::class);
        $ruleUsage->where(RuleUsage::schema_fields_RULE_ID, $ruleId);
        $totalUsage = $ruleUsage->count();

        $ruleUsage->reset();
        $ruleUsage->where(RuleUsage::schema_fields_RULE_ID, $ruleId);
        $totalDiscount = $ruleUsage->sum(RuleUsage::schema_fields_DISCOUNT_AMOUNT);

        $budget = $campaign->getData(Campaign::schema_fields_BUDGET);
        $spent = $campaign->getData(Campaign::schema_fields_SPENT);

        return [
            'campaign_id' => $campaignId,
            'name' => $campaign->getData(Campaign::schema_fields_NAME),
            'status' => $campaign->getData(Campaign::schema_fields_STATUS),
            'budget' => $budget,
            'spent' => $spent,
            'remaining_budget' => $budget ? ($budget - $spent) : null,
            'total_usage' => $totalUsage,
            'total_discount' => $totalDiscount ?? 0,
        ];
    }
}

