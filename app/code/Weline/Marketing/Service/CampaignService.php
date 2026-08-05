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
        $name = trim((string)($data[Campaign::schema_fields_NAME] ?? ''));
        $ruleId = (int)($data[Campaign::schema_fields_RULE_ID] ?? 0);
        /** @var Rule $rule */
        $rule = ObjectManager::getInstance(Rule::class);
        $rule->load($ruleId);
        if ($name === '' || $ruleId <= 0 || !$rule->getId()) {
            throw new \InvalidArgumentException((string)__('活动名称和有效营销规则不能为空'));
        }

        $status = trim((string)($data[Campaign::schema_fields_STATUS] ?? Campaign::STATUS_DRAFT));
        if (!in_array($status, [
            Campaign::STATUS_DRAFT,
            Campaign::STATUS_ACTIVE,
            Campaign::STATUS_PAUSED,
            Campaign::STATUS_COMPLETED,
            Campaign::STATUS_CANCELLED,
        ], true)) {
            throw new \InvalidArgumentException((string)__('活动状态无效'));
        }

        $startDate = $this->normaliseDate((string)($data[Campaign::schema_fields_START_DATE] ?? ''));
        $endDate = $this->normaliseDate((string)($data[Campaign::schema_fields_END_DATE] ?? ''));
        if ($startDate === '' || $endDate === '' || strtotime($endDate) <= strtotime($startDate)) {
            throw new \InvalidArgumentException((string)__('活动结束时间必须晚于开始时间'));
        }

        $budget = (float)($data[Campaign::schema_fields_BUDGET] ?? 0);
        if ($budget < 0) {
            throw new \InvalidArgumentException((string)__('活动预算不能为负数'));
        }

        $data[Campaign::schema_fields_NAME] = $name;
        $data[Campaign::schema_fields_RULE_ID] = $ruleId;
        $data[Campaign::schema_fields_STATUS] = $status;
        $data[Campaign::schema_fields_START_DATE] = $startDate;
        $data[Campaign::schema_fields_END_DATE] = $endDate;
        $data[Campaign::schema_fields_BUDGET] = $budget;
        $data[Campaign::schema_fields_CREATED_AT] = $data[Campaign::schema_fields_CREATED_AT] ?? date('Y-m-d H:i:s');
        $data[Campaign::schema_fields_UPDATED_AT] = date('Y-m-d H:i:s');

        /** @var Campaign $campaign */
        $campaign = ObjectManager::getInstance(Campaign::class);
        $campaign->setData($data);
        $campaign->save();

        return $campaign;
    }

    private function normaliseDate(string $value): string
    {
        $value = trim(str_replace('T', ' ', $value));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D', $value) === 1) {
            return $value . ':00';
        }

        return $value;
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
