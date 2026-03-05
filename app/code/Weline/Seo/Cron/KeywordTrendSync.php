<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoKeyword;
use Weline\Seo\Model\SeoKeywordTrend;
use Weline\Seo\Service\TrendFetcherService;
use Weline\Seo\Service\SuggestionService;

/**
 * SEO 关键词趋势同步任务
 * 
 * 定时拉取关键词趋势数据并更新建议
 * 
 * @package Weline_Seo
 */
class KeywordTrendSync implements CronTaskInterface
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 任务名称
     */
    public function name(): string
    {
        return 'SEO 关键词趋势同步';
    }

    /**
     * 执行名称
     */
    public function execute_name(): string
    {
        return 'seo_keyword_trend_sync';
    }

    /**
     * 任务描述
     */
    public function tip(): string
    {
        return '定时同步关键词趋势数据，从各大趋势平台拉取关键词热度信息，并更新SEO建议';
    }

    /**
     * Cron时间表达式 - 每4小时执行一次
     */
    public function cron_time(): string
    {
        return '0 */4 * * *';
    }

    /**
     * 执行任务
     */
    public function execute(): string
    {
        try {
            /** @var SeoKeyword $keywordModel */
            $keywordModel = $this->objectManager->getInstance(SeoKeyword::class);
            
            // 获取需要同步的关键词（启用状态，最近7天内有更新）
            $keywords = $keywordModel->reset()
                ->where(SeoKeyword::schema_fields_STATUS, SeoKeyword::STATUS_ENABLED)
                ->where(SeoKeyword::schema_fields_UPDATED_AT, date('Y-m-d H:i:s', strtotime('-7 days')), '>=')
                ->select()
                ->fetchArray();

            if (empty($keywords)) {
                return '没有需要同步的关键词';
            }

            // 按主体分组关键词
            $keywordsBySubject = [];
            foreach ($keywords as $keyword) {
                $subjectId = $keyword['subject_id'];
                if (!isset($keywordsBySubject[$subjectId])) {
                    $keywordsBySubject[$subjectId] = [];
                }
                $keywordsBySubject[$subjectId][] = $keyword['keyword'];
            }

            /** @var TrendFetcherService $trendFetcher */
            $trendFetcher = $this->objectManager->getInstance(TrendFetcherService::class);
            
            /** @var SeoKeywordTrend $trendModel */
            $trendModel = $this->objectManager->getInstance(SeoKeywordTrend::class);

            $totalTrends = 0;
            $totalErrors = 0;

            // 为每个主体获取趋势数据
            foreach ($keywordsBySubject as $subjectId => $keywordList) {
                try {
                    $trends = $trendFetcher->fetchTrends($keywordList, [
                        'region' => 'CN', // 默认中国地区
                    ]);

                    // 保存趋势数据
                    foreach ($trends as $platform => $platformTrends) {
                        if (isset($platformTrends['error'])) {
                            $totalErrors++;
                            continue;
                        }

                        foreach ($platformTrends as $keyword => $trendData) {
                            if (!is_array($trendData)) {
                                continue;
                            }

                            // 查找关键词ID
                            $keywordRecord = $keywordModel->reset()
                                ->where(SeoKeyword::schema_fields_SUBJECT_ID, $subjectId)
                                ->where(SeoKeyword::schema_fields_KEYWORD, $keyword)
                                ->find()
                                ->fetch();

                            if (!$keywordRecord->getId()) {
                                continue;
                            }

                            // 保存趋势记录
                            $trendModel->reset()
                                ->setKeywordId($keywordRecord->getId())
                                ->setPlatform($platform)
                                ->setTrendValue($trendData['value'] ?? 0)
                                ->setTrendDate($trendData['date'] ?? date('Y-m-d'))
                                ->setRegion($trendData['region'] ?? 'CN')
                                ->save();

                            $totalTrends++;
                        }
                    }

                    // 更新主体的最后同步时间
                    // TODO: 更新 SeoSubject 的 last_sync_at 字段

                } catch (\Exception $e) {
                    $totalErrors++;
                    // 记录错误但继续处理其他主体
                }
            }

            // 可选：为有趋势更新的主体重新生成建议
            // TODO: 调用 SuggestionService 更新建议

            return sprintf(
                '趋势同步完成：处理 %d 个主体，保存 %d 条趋势记录，错误 %d 个',
                count($keywordsBySubject),
                $totalTrends,
                $totalErrors
            );

        } catch (\Exception $e) {
            return '趋势同步失败：' . $e->getMessage();
        }
    }

    /**
     * @inheritDoc
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return 60; // 60分钟超时自动解锁
    }
}

