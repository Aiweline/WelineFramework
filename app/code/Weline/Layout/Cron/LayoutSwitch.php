<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/15
 * 描述：布局定时切换任务
 */

namespace Weline\Layout\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Layout\Model\Layout;
use Weline\Layout\Model\LayoutSchedule;
use Weline\Layout\Service\LayoutService;
use function __;

class LayoutSwitch implements CronTaskInterface
{
    private LayoutSchedule $scheduleModel;
    private ?LayoutService $layoutService = null;

    public function __construct(
        LayoutSchedule $scheduleModel
    ) {
        $this->scheduleModel = $scheduleModel;
    }

    /**
     * 获取 LayoutService 实例（延迟加载）
     */
    private function getLayoutService(): LayoutService
    {
        if ($this->layoutService === null) {
            $this->layoutService = ObjectManager::getInstance(LayoutService::class);
        }
        return $this->layoutService;
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return __('布局定时切换任务');
    }

    /**
     * @inheritDoc
     */
    public function execute_name(): string
    {
        return 'layout_switch';
    }

    /**
     * @inheritDoc
     */
    public function execute(): string
    {
        $messages = [];

        // 1. 处理待执行的计划
        $pendingSchedules = $this->scheduleModel->getPendingSchedules();
        foreach ($pendingSchedules as $schedule) {
            $result = $this->activateSchedule($schedule);
            $messages[] = $result;
        }

        // 2. 处理需要结束的活动计划
        $expiredSchedules = $this->scheduleModel->getExpiredActiveSchedules();
        foreach ($expiredSchedules as $schedule) {
            $result = $this->deactivateSchedule($schedule);
            $messages[] = $result;
        }

        if (empty($messages)) {
            return __('没有需要处理的布局计划');
        }

        return implode("\n", $messages);
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('每分钟检查并执行布局定时切换任务');
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

    /**
     * 激活布局计划
     */
    protected function activateSchedule(array $scheduleData): string
    {
        $scheduleId = $scheduleData['schedule_id'] ?? 0;
        $layoutId = $scheduleData['layout_id'] ?? 0;
        $moduleCode = $scheduleData['module_code'] ?? '';
        $layoutType = $scheduleData['layout_type'] ?? '';

        if (!$scheduleId || !$layoutId || !$moduleCode || !$layoutType) {
            return __('计划 #%1 数据不完整，跳过', $scheduleId);
        }

        // 加载布局
        /** @var Layout $layoutModel */
        $layoutModel = ObjectManager::getInstance(Layout::class);
        $layout = $layoutModel->load($layoutId);

        if (!$layout->getId()) {
            return __('计划 #%1 关联的布局不存在，跳过', $scheduleId);
        }

        $layoutCode = $layout->getCode();

        // 触发计划触发事件
        $this->dispatchScheduleTriggerEvent($scheduleId, $layoutId, $moduleCode, $layoutType, $layoutCode);

        // 更新计划状态为活动
        $this->scheduleModel->load($scheduleId);
        $this->scheduleModel->setStatus(LayoutSchedule::STATUS_ACTIVE)->save();

        return __('计划 #%1 已激活，布局 \'%2\' 应用到 %3::%4', $scheduleId, $layoutCode, $moduleCode, $layoutType);
    }

    /**
     * 停用布局计划
     */
    protected function deactivateSchedule(array $scheduleData): string
    {
        $scheduleId = $scheduleData['schedule_id'] ?? 0;
        $isRecurring = $scheduleData['is_recurring'] ?? 0;

        if (!$scheduleId) {
            return __('无效的计划ID');
        }

        $this->scheduleModel->load($scheduleId);

        if ($isRecurring) {
            // 循环任务：重新计算下次执行时间
            // 这里简化处理，实际需要解析 cron 表达式
            $this->scheduleModel->setStatus(LayoutSchedule::STATUS_PENDING);
            // TODO: 根据 cron 表达式计算下次执行时间
        } else {
            // 非循环任务：标记为已完成
            $this->scheduleModel->setStatus(LayoutSchedule::STATUS_COMPLETED);
        }

        $this->scheduleModel->save();

        $statusText = $isRecurring ? __('重置为待执行') : __('标记为已完成');
        return __('计划 #%1 已%2', $scheduleId, $statusText);
    }

    /**
     * 触发计划触发事件
     */
    protected function dispatchScheduleTriggerEvent(int $scheduleId, int $layoutId, string $moduleCode, string $layoutType, string $layoutCode): void
    {
        $eventManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
        $eventManager->dispatch('Weline_Layout::layout_schedule_trigger', [
            'schedule_id' => $scheduleId,
            'layout_id' => $layoutId,
            'module_code' => $moduleCode,
            'layout_type' => $layoutType,
            'layout_code' => $layoutCode
        ]);
    }
}

