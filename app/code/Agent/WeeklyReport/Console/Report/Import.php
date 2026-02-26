<?php

declare(strict_types=1);

namespace Agent\WeeklyReport\Console\Report;

use Agent\WeeklyReport\Model\WeeklyReport;
use Agent\WeeklyReport\Model\WeeklyTask;
use Agent\WeeklyReport\Service\WeeklyReportService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;

/**
 * 导入历史周报数据命令
 * 
 * 命令：report:import
 */
class Import extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $this->printer->note('开始导入历史周报数据...');

        $reportService = ObjectManager::getInstance(WeeklyReportService::class);

        $historicalData = $this->getHistoricalData();

        $imported = 0;
        foreach ($historicalData as $weekData) {
            $weekNumber = $weekData['week_number'];
            $report = $reportService->getOrCreateWeekReport($weekNumber, 2026);

            if ($weekData['is_holiday'] ?? false) {
                $report->setAsHolidayWeek($weekData['holiday_name']);
                $report->save();
            }

            foreach ($weekData['tasks'] as $taskData) {
                $reportService->addTask((int) $report->getId(), $taskData);
                $imported++;
            }

            $this->printer->success("已导入第 {$weekNumber} 周: " . count($weekData['tasks']) . " 个任务");
        }

        $this->printer->success("导入完成！共导入 {$imported} 个任务");
    }

    /**
     * 获取历史数据
     */
    private function getHistoricalData(): array
    {
        return [
            // 第一周 2026/1/12 - 2026/1/18
            [
                'week_number' => 1,
                'tasks' => [
                    [
                        WeeklyTask::fields_CATEGORY => 'Demo 自动AI建站系统',
                        WeeklyTask::fields_TASK_NAME => '完成Demo并上线',
                        WeeklyTask::fields_SUB_TASK => '完成Demo并上线',
                        WeeklyTask::fields_START_DATE => '2026-01-12',
                        WeeklyTask::fields_END_DATE => '2026-01-19',
                        WeeklyTask::fields_STATUS => '已完成',
                        WeeklyTask::fields_PROGRESS => '完成',
                        WeeklyTask::fields_NEXT_WEEK_PLAN => '完成新需求描述',
                    ],
                ],
            ],

            // 第二周 2026/1/19 - 2026/1/25
            [
                'week_number' => 2,
                'tasks' => [
                    [
                        WeeklyTask::fields_CATEGORY => 'Demo 自动AI建站系统',
                        WeeklyTask::fields_TASK_NAME => '新Demo上线',
                        WeeklyTask::fields_SUB_TASK => '新Demo上线',
                        WeeklyTask::fields_START_DATE => '2026-01-20',
                        WeeklyTask::fields_END_DATE => '2026-01-28',
                        WeeklyTask::fields_STATUS => '进行中',
                        WeeklyTask::fields_PROGRESS => '进行中',
                        WeeklyTask::fields_RISKS => '周四周五新的任务占用时间延期',
                        WeeklyTask::fields_NEXT_WEEK_PLAN => '周三演示',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => '建站任务',
                        WeeklyTask::fields_TASK_NAME => '协助每天建站域名购买，解析，部署等任务',
                        WeeklyTask::fields_SUB_TASK => '协助每天建站域名购买，解析，部署等任务',
                        WeeklyTask::fields_START_DATE => '2026-01-22',
                        WeeklyTask::fields_STATUS => '进行中',
                        WeeklyTask::fields_PROGRESS => '进行中',
                        WeeklyTask::fields_RISKS => '占用系统开发时间',
                        WeeklyTask::fields_NEXT_WEEK_PLAN => '持续根据需求购买域名部署',
                    ],
                ],
            ],

            // 第三周 2026/1/26 - 2026/2/1
            [
                'week_number' => 3,
                'tasks' => [
                    [
                        WeeklyTask::fields_CATEGORY => 'Demo 自动AI建站系统',
                        WeeklyTask::fields_TASK_NAME => '新Demo上线',
                        WeeklyTask::fields_SUB_TASK => '新Demo上线',
                        WeeklyTask::fields_START_DATE => '2026-01-20',
                        WeeklyTask::fields_END_DATE => '2026-01-28',
                        WeeklyTask::fields_STATUS => '已完成',
                        WeeklyTask::fields_PROGRESS => '完成',
                        WeeklyTask::fields_RISKS => '周四周五新的任务占用时间延期',
                        WeeklyTask::fields_NEXT_WEEK_PLAN => '周三演示',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => 'Demo 自动AI建站系统',
                        WeeklyTask::fields_TASK_NAME => '可视化模板拆分部件',
                        WeeklyTask::fields_SUB_TASK => '可视化模板拆分部件',
                        WeeklyTask::fields_START_DATE => '2026-01-20',
                        WeeklyTask::fields_END_DATE => '2026-01-28',
                        WeeklyTask::fields_STATUS => '完成',
                        WeeklyTask::fields_PROGRESS => '完成',
                        WeeklyTask::fields_NEXT_WEEK_PLAN => '周三演示',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => 'Demo 自动AI建站系统',
                        WeeklyTask::fields_TASK_NAME => 'AI生成页面词',
                        WeeklyTask::fields_SUB_TASK => 'AI生成页面词',
                        WeeklyTask::fields_START_DATE => '2026-01-20',
                        WeeklyTask::fields_END_DATE => '2026-01-28',
                        WeeklyTask::fields_STATUS => '完成',
                        WeeklyTask::fields_PROGRESS => '完成',
                        WeeklyTask::fields_NEXT_WEEK_PLAN => '周三演示',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => '建站任务',
                        WeeklyTask::fields_TASK_NAME => '协助每天建站域名购买，解析，部署等任务',
                        WeeklyTask::fields_SUB_TASK => '协助每天建站域名购买，解析，部署等任务',
                        WeeklyTask::fields_START_DATE => '2026-01-22',
                        WeeklyTask::fields_END_DATE => '2026-01-28',
                        WeeklyTask::fields_STATUS => '完成',
                        WeeklyTask::fields_PROGRESS => '完成',
                        WeeklyTask::fields_RISKS => '占用系统开发时间',
                        WeeklyTask::fields_NEXT_WEEK_PLAN => '持续根据需求购买域名部署',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => 'Demo 自动AI建站系统',
                        WeeklyTask::fields_TASK_NAME => '自动化SEO、Sitemap提交',
                        WeeklyTask::fields_SUB_TASK => '自动化SEO、Sitemap提交',
                        WeeklyTask::fields_START_DATE => '2026-01-20',
                        WeeklyTask::fields_END_DATE => '2026-02-02',
                        WeeklyTask::fields_STATUS => '完成',
                        WeeklyTask::fields_PROGRESS => '完成',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => 'Demo 自动AI建站系统',
                        WeeklyTask::fields_TASK_NAME => '自动化AI创建可视化编辑组件',
                        WeeklyTask::fields_SUB_TASK => '自动化AI创建可视化编辑组件',
                        WeeklyTask::fields_START_DATE => '2026-01-20',
                        WeeklyTask::fields_END_DATE => '2026-02-02',
                        WeeklyTask::fields_STATUS => '完成',
                        WeeklyTask::fields_PROGRESS => '完成',
                        WeeklyTask::fields_RISKS => '需要写代码强的模型，目前deepseek难以提升成功率',
                        WeeklyTask::fields_NEXT_WEEK_PLAN => '反馈修正',
                    ],
                ],
            ],

            // 第四周 2026/2/2 - 2026/2/8
            [
                'week_number' => 4,
                'tasks' => [
                    [
                        WeeklyTask::fields_CATEGORY => 'Demo 自动AI建站系统',
                        WeeklyTask::fields_TASK_NAME => '自动化分析词生成博客',
                        WeeklyTask::fields_SUB_TASK => '自动化分析词生成博客',
                        WeeklyTask::fields_START_DATE => '2026-02-02',
                        WeeklyTask::fields_END_DATE => '2026-02-06',
                        WeeklyTask::fields_STATUS => '进行中',
                        WeeklyTask::fields_PROGRESS => '测试中',
                        WeeklyTask::fields_RISKS => '本周工作量会变大，同步进行并发开发，占用大量AI资源',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => 'Demo 自动AI建站系统',
                        WeeklyTask::fields_TASK_NAME => '自动化SEO、Sitemap提交',
                        WeeklyTask::fields_SUB_TASK => '自动化SEO、Sitemap提交',
                        WeeklyTask::fields_START_DATE => '2026-02-02',
                        WeeklyTask::fields_END_DATE => '2026-02-04',
                        WeeklyTask::fields_STATUS => '完成',
                        WeeklyTask::fields_PROGRESS => '完成',
                        WeeklyTask::fields_RISKS => '本周工作量会变大，同步进行并发开发，占用大量AI资源',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => '建站任务',
                        WeeklyTask::fields_TASK_NAME => '协助每天建站域名购买，解析，部署等任务',
                        WeeklyTask::fields_SUB_TASK => '协助每天建站域名购买，解析，部署等任务',
                        WeeklyTask::fields_START_DATE => '2026-02-02',
                        WeeklyTask::fields_END_DATE => '2026-02-07',
                        WeeklyTask::fields_STATUS => '完成',
                        WeeklyTask::fields_PROGRESS => '完成',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => 'Demo 自动AI建站系统',
                        WeeklyTask::fields_TASK_NAME => '自动化AI创建可视化编辑组件',
                        WeeklyTask::fields_SUB_TASK => '升级智能体结构',
                        WeeklyTask::fields_START_DATE => '2026-01-02',
                        WeeklyTask::fields_END_DATE => '2026-02-10',
                        WeeklyTask::fields_STATUS => '进行中',
                        WeeklyTask::fields_PROGRESS => '进行中',
                        WeeklyTask::fields_RISKS => '加入Claudecode，但是在优化Deepseek 代码生成质量差，切换开发智能体模式，流式响应',
                        WeeklyTask::fields_NEXT_WEEK_PLAN => '完成并上线',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => '建站任务',
                        WeeklyTask::fields_TASK_NAME => '接到建站150个任务（不含代码）环境准备任务',
                        WeeklyTask::fields_SUB_TASK => '接到建站150个任务（不含代码）环境准备任务',
                        WeeklyTask::fields_START_DATE => '2026-02-06',
                        WeeklyTask::fields_END_DATE => '2026-02-07',
                        WeeklyTask::fields_STATUS => '进行中',
                        WeeklyTask::fields_PROGRESS => '1.完成三个服务器购买准备；2.完成服务器1的三十个域名购买，迁移CF，解析，服务器内建站目录和SSL处理。',
                        WeeklyTask::fields_RISKS => '1.占用系统开发时间；2.目前剩余：120个网站环境准备。',
                        WeeklyTask::fields_NEXT_WEEK_PLAN => '继续完成剩余120个域名的环境准备',
                    ],
                ],
            ],

            // 第五周 2026/2/9 - 2026/2/15
            [
                'week_number' => 5,
                'tasks' => [
                    [
                        WeeklyTask::fields_CATEGORY => 'Demo 自动AI建站系统',
                        WeeklyTask::fields_TASK_NAME => '自动化分析词生成博客',
                        WeeklyTask::fields_SUB_TASK => '自动化分析词生成博客',
                        WeeklyTask::fields_START_DATE => '2026-02-02',
                        WeeklyTask::fields_END_DATE => '2026-02-06',
                        WeeklyTask::fields_STATUS => '测试中',
                        WeeklyTask::fields_PROGRESS => '进行中',
                        WeeklyTask::fields_RISKS => '暂停部分开发功能，只根据词生成文章即可',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => '建站任务',
                        WeeklyTask::fields_TASK_NAME => '协助每天建站域名购买，解析，部署等任务',
                        WeeklyTask::fields_SUB_TASK => '协助每天建站域名购买，解析，部署等任务',
                        WeeklyTask::fields_START_DATE => '2026-02-02',
                        WeeklyTask::fields_END_DATE => '2026-02-07',
                        WeeklyTask::fields_STATUS => '待开始',
                        WeeklyTask::fields_PROGRESS => '待开始',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => '建站任务',
                        WeeklyTask::fields_TASK_NAME => '剩余120个域名建站环境准备',
                        WeeklyTask::fields_SUB_TASK => '剩余120个域名建站环境准备',
                        WeeklyTask::fields_START_DATE => '2026-02-09',
                        WeeklyTask::fields_END_DATE => '2026-02-11',
                        WeeklyTask::fields_STATUS => '完成',
                        WeeklyTask::fields_PROGRESS => '完成',
                        WeeklyTask::fields_RISKS => '1.占用系统开发时间；2.目前剩余：120个网站环境准备。',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => 'Demo 自动AI建站系统',
                        WeeklyTask::fields_TASK_NAME => '自动化AI创建可视化编辑组件',
                        WeeklyTask::fields_SUB_TASK => '升级智能体结构',
                        WeeklyTask::fields_START_DATE => '2026-01-20',
                        WeeklyTask::fields_END_DATE => '2026-02-12',
                        WeeklyTask::fields_STATUS => '进行中',
                        WeeklyTask::fields_PROGRESS => '进行中',
                        WeeklyTask::fields_RISKS => '加入Claudecode，但是在优化Deepseek 代码生成质量差，切换开发智能体模式，流式响应',
                        WeeklyTask::fields_NEXT_WEEK_PLAN => '完成并上线',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => 'Demo 自动AI建站系统',
                        WeeklyTask::fields_TASK_NAME => '优化系统支持Saas环境准备',
                        WeeklyTask::fields_SUB_TASK => '直接购买域名，ssl等自动',
                        WeeklyTask::fields_START_DATE => '2026-02-12',
                        WeeklyTask::fields_END_DATE => '2026-02-28',
                        WeeklyTask::fields_STATUS => '进行中',
                        WeeklyTask::fields_PROGRESS => '进行中',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => '面试（简历筛选）',
                        WeeklyTask::fields_TASK_NAME => '面试（简历筛选）',
                        WeeklyTask::fields_SUB_TASK => '',
                        WeeklyTask::fields_START_DATE => '2026-02-10',
                        WeeklyTask::fields_END_DATE => '2026-02-11',
                        WeeklyTask::fields_STATUS => '进行中',
                        WeeklyTask::fields_PROGRESS => '进行中',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => '带前端熟悉项目',
                        WeeklyTask::fields_TASK_NAME => '带前端熟悉项目',
                        WeeklyTask::fields_SUB_TASK => '',
                        WeeklyTask::fields_START_DATE => '2026-02-12',
                        WeeklyTask::fields_END_DATE => '2026-02-12',
                        WeeklyTask::fields_STATUS => '进行中',
                        WeeklyTask::fields_PROGRESS => '进行中',
                    ],
                ],
            ],

            // 第六周【春节】2026/2/16 - 2026/2/22
            [
                'week_number' => 6,
                'is_holiday' => true,
                'holiday_name' => '春节',
                'tasks' => [],
            ],

            // 第七周 2026/2/23 - 2026/3/1
            [
                'week_number' => 7,
                'tasks' => [
                    [
                        WeeklyTask::fields_CATEGORY => 'Demo 自动AI建站系统',
                        WeeklyTask::fields_TASK_NAME => 'saas系统',
                        WeeklyTask::fields_SUB_TASK => 'saas系统',
                        WeeklyTask::fields_START_DATE => '2026-02-24',
                        WeeklyTask::fields_END_DATE => '2026-02-28',
                        WeeklyTask::fields_STATUS => '进行中',
                        WeeklyTask::fields_PROGRESS => '进行中',
                        WeeklyTask::fields_RISKS => 'saas系统，全面检查上线，直接填写域名购买，自动建站',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => '建站任务',
                        WeeklyTask::fields_TASK_NAME => '协助每天建站域名购买，解析，部署等任务',
                        WeeklyTask::fields_SUB_TASK => '协助每天建站域名购买，解析，部署等任务',
                        WeeklyTask::fields_START_DATE => '2026-02-02',
                        WeeklyTask::fields_END_DATE => '2026-02-07',
                        WeeklyTask::fields_STATUS => '进行中',
                        WeeklyTask::fields_PROGRESS => '进行中',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => 'Demo 自动AI建站系统',
                        WeeklyTask::fields_TASK_NAME => '自动化分析词生成博客',
                        WeeklyTask::fields_SUB_TASK => '自动化分析词生成博客',
                        WeeklyTask::fields_START_DATE => '2026-02-02',
                        WeeklyTask::fields_END_DATE => '2026-02-06',
                        WeeklyTask::fields_STATUS => '测试中',
                        WeeklyTask::fields_PROGRESS => '进行中',
                        WeeklyTask::fields_RISKS => '暂停部分开发功能，只根据词生成文章即可',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => 'Saas',
                        WeeklyTask::fields_TASK_NAME => '建站(快速填写域名建站）',
                        WeeklyTask::fields_SUB_TASK => '建站(快速填写域名建站）',
                        WeeklyTask::fields_START_DATE => '2026-02-02',
                        WeeklyTask::fields_END_DATE => '2026-02-24',
                        WeeklyTask::fields_STATUS => '完成',
                        WeeklyTask::fields_PROGRESS => '完成',
                    ],
                    [
                        WeeklyTask::fields_CATEGORY => 'Saas',
                        WeeklyTask::fields_TASK_NAME => '域名购买接入系统',
                        WeeklyTask::fields_SUB_TASK => '购买自动建站：等待解析即可',
                        WeeklyTask::fields_START_DATE => '2026-02-24',
                        WeeklyTask::fields_END_DATE => '2026-02-28',
                        WeeklyTask::fields_STATUS => '进行中',
                        WeeklyTask::fields_PROGRESS => '进行中',
                    ],
                ],
            ],
        ];
    }

    public function tip(): string
    {
        return '导入历史周报数据';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'report:import',
            '导入历史周报数据到数据库',
            [],
            [],
            [
                '导入历史数据' => 'php bin/w report:import',
            ]
        );
    }
}
