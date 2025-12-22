<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/12/20
 * 描述：产品布局计划定时切换任务
 */

namespace WeShop\Product\Cron;

use WeShop\Product\Model\ProductLayoutSchedule;
use WeShop\Product\Service\ProductLayoutService;
use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use function __;

class ProductLayoutSwitch implements CronTaskInterface
{
    private ProductLayoutSchedule $scheduleModel;
    private ?ProductLayoutService $layoutService = null;

    public function __construct(ProductLayoutSchedule $scheduleModel)
    {
        $this->scheduleModel = $scheduleModel;
    }

    /**
     * 获取 ProductLayoutService 实例（延迟加载）
     */
    private function getLayoutService(): ProductLayoutService
    {
        if ($this->layoutService === null) {
            $this->layoutService = ObjectManager::getInstance(ProductLayoutService::class);
        }
        return $this->layoutService;
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return __('产品布局定时切换任务');
    }

    /**
     * @inheritDoc
     */
    public function execute_name(): string
    {
        return 'product_layout_switch';
    }

    /**
     * @inheritDoc
     */
    public function execute(): string
    {
        $messages = [];
        $layoutService = $this->getLayoutService();

        // 1. 处理待执行的计划
        $pendingSchedules = $this->scheduleModel->getPendingSchedules();
        foreach ($pendingSchedules as $scheduleData) {
            $schedule = ObjectManager::getInstance(ProductLayoutSchedule::class);
            $schedule->load($scheduleData['schedule_id']);
            
            if ($schedule->getId()) {
                $result = $layoutService->activateSchedule($schedule);
                if ($result) {
                    $messages[] = __(
                        '产品 #%1 的布局计划 #%2 已激活，布局 \'%3\' 应用到 %4',
                        $schedule->getProductId(),
                        $schedule->getId(),
                        $schedule->getLayoutCode(),
                        $schedule->getLayoutType()
                    );
                }
            }
        }

        // 2. 处理需要结束的活动计划
        $expiredSchedules = $this->scheduleModel->getExpiredActiveSchedules();
        foreach ($expiredSchedules as $scheduleData) {
            $schedule = ObjectManager::getInstance(ProductLayoutSchedule::class);
            $schedule->load($scheduleData['schedule_id']);
            
            if ($schedule->getId()) {
                $result = $layoutService->deactivateSchedule($schedule);
                if ($result) {
                    $statusText = $schedule->isRecurring() ? __('重置为待执行') : __('标记为已完成');
                    $messages[] = __(
                        '产品 #%1 的布局计划 #%2 已%3',
                        $schedule->getProductId(),
                        $schedule->getId(),
                        $statusText
                    );
                }
            }
        }

        if (empty($messages)) {
            return __('没有需要处理的产品布局计划');
        }

        return implode("\n", $messages);
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('每分钟检查并执行产品布局定时切换任务');
    }

    /**
     * @inheritDoc
     */
    public function cron_time(): string
    {
        return '* * * * *'; // 每分钟执行
    }

    /**
     * @inheritDoc
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return 10; // 10分钟超时自动解锁
    }
}

