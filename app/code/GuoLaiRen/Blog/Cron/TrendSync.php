<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 趋势拉取与增长词记录定时任务
 */

namespace GuoLaiRen\Blog\Cron;

use GuoLaiRen\Blog\Model\TrendingKeywordLog;
use GuoLaiRen\Blog\Model\TrendProfile;
use GuoLaiRen\Blog\Model\TrendsConfig;
use GuoLaiRen\Blog\Service\GoogleTrendsService;
use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;

class TrendSync implements CronTaskInterface
{
    public function name(): string
    {
        return __('Blog 趋势同步');
    }

    public function execute_name(): string
    {
        return 'blog_trend_sync';
    }

    public function tip(): string
    {
        return __('每日拉取各画像关键词趋势，计算日/周环比，将满足阈值的增长词写入记录表');
    }

    public function cron_time(): string
    {
        return '0 6 * * *';
    }

    public function execute(): string
    {
        if (!TrendsConfig::useSerpApi() && !TrendsConfig::useOfficialApi()) {
            return __('未配置趋势数据源，已跳过');
        }

        /** @var TrendProfile $profileModel */
        $profileModel = ObjectManager::getInstance(TrendProfile::class);
        $profiles = $profileModel->clear()
            ->where(TrendProfile::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->getItems();

        if (empty($profiles)) {
            return __('没有启用的画像');
        }

        /** @var GoogleTrendsService $service */
        $service = ObjectManager::getInstance(GoogleTrendsService::class);
        /** @var TrendingKeywordLog $logModel */
        $logModel = ObjectManager::getInstance(TrendingKeywordLog::class);

        $threshold = TrendsConfig::getGrowthThreshold();
        $comparison = TrendsConfig::getGrowthComparison();
        $region = TrendsConfig::get(TrendsConfig::KEY_REGION, 'US');
        $today = date('Y-m-d');
        $inserted = 0;

        foreach ($profiles as $profile) {
            $profileId = (int)$profile->getData(TrendProfile::fields_ID);
            $keywords = $profile->getKeywordsArray();
            if (empty($keywords)) {
                continue;
            }

            try {
                $withHistory = $service->fetchTrendsWithHistory($keywords, ['region' => $region]);
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($withHistory as $keyword => $row) {
                $current = (int)($row['current'] ?? 0);
                $prevDay = (int)($row['previous_day'] ?? 0);
                $prevWeek = (int)($row['previous_week'] ?? 0);
                $dateStr = (string)($row['date'] ?? $today);
                $rateDay = $prevDay > 0 ? (($current - $prevDay) / $prevDay) * 100 : ($current > 0 ? 100.0 : 0.0);
                $rateWeek = $prevWeek > 0 ? (($current - $prevWeek) / $prevWeek) * 100 : ($current > 0 ? 100.0 : 0.0);

                $insertedOne = false;
                if ($comparison === TrendsConfig::GROWTH_DAY) {
                    if ($rateDay >= $threshold) {
                        $insertedOne = $this->insertTrendLog($logModel, $profileId, $keyword, $current, $prevDay, $rateDay, TrendingKeywordLog::COMPARISON_DAY, $dateStr);
                    }
                } elseif ($comparison === TrendsConfig::GROWTH_WEEK) {
                    if ($rateWeek >= $threshold) {
                        $insertedOne = $this->insertTrendLog($logModel, $profileId, $keyword, $current, $prevWeek, $rateWeek, TrendingKeywordLog::COMPARISON_WEEK, $dateStr);
                    }
                } else {
                    // GROWTH_BOTH：每个关键词只写一条，优先日环比
                    if ($rateDay >= $threshold) {
                        $insertedOne = $this->insertTrendLog($logModel, $profileId, $keyword, $current, $prevDay, $rateDay, TrendingKeywordLog::COMPARISON_DAY, $dateStr);
                    } elseif ($rateWeek >= $threshold) {
                        $insertedOne = $this->insertTrendLog($logModel, $profileId, $keyword, $current, $prevWeek, $rateWeek, TrendingKeywordLog::COMPARISON_WEEK, $dateStr);
                    }
                }
                if ($insertedOne) {
                    $inserted++;
                }
            }
        }

        return __('趋势同步完成：写入 %{count} 条增长词记录', ['count' => $inserted]);
    }

    private function insertTrendLog(
        TrendingKeywordLog $logModel,
        int $profileId,
        string $keyword,
        int $trendValue,
        int $previousValue,
        float $changeRate,
        string $comparisonType,
        string $trendDate
    ): bool {
        // 去重检查：同一 profile_id + keyword + trend_date 不重复插入
        $existsModel = ObjectManager::getInstance(TrendingKeywordLog::class);
        $exists = $existsModel->clear()
            ->where(TrendingKeywordLog::fields_PROFILE_ID, $profileId)
            ->where(TrendingKeywordLog::fields_KEYWORD, $keyword)
            ->where(TrendingKeywordLog::fields_TREND_DATE, $trendDate)
            ->find()
            ->fetch();

        if ($exists->getId()) {
            // 已存在，跳过插入
            return false;
        }

        $logModel->clear()
            ->setData(TrendingKeywordLog::fields_PROFILE_ID, $profileId)
            ->setData(TrendingKeywordLog::fields_KEYWORD, $keyword)
            ->setData(TrendingKeywordLog::fields_TREND_VALUE, $trendValue)
            ->setData(TrendingKeywordLog::fields_PREVIOUS_VALUE, $previousValue)
            ->setData(TrendingKeywordLog::fields_CHANGE_RATE, round($changeRate, 2))
            ->setData(TrendingKeywordLog::fields_COMPARISON_TYPE, $comparisonType)
            ->setData(TrendingKeywordLog::fields_TREND_DATE, $trendDate)
            ->setData(TrendingKeywordLog::fields_SOURCE, 'google_trends')
            ->save();

        return true;
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 60;
    }
}
