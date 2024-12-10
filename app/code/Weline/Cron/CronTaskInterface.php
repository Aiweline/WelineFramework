<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/30 01:20:37
 */

namespace Weline\Cron;

/**
 * 调度任务接口
 */
interface CronTaskInterface
{
    /**
     * @DESC          # 调度任务名【用于系统】
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/10/30 1:35
     * 参数区：
     * @return string
     */
    function name(): string;
    /**
     * @DESC          # 执行名
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/10/30 1:35
     * 参数区：
     * @return string
     */
    function execute_name(): string;

    /**
     * @DESC          # 任务描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/10/30 1:23
     * 参数区：
     * @return string
     */
    function tip(): string;

    /**
     * @DESC          # 调度时间频率 示例：'0 1 * * *' #每天凌晨1点执行一次
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/10/30 1:23
     * 参数区：
     * @return string
     */
    function cron_time(): string;

    /**
     * @DESC          # 执行函数
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/10/30 1:23
     * 参数区：
     * @return string
     */
    function execute(): string;

    /**
     * @DESC          # 调度任务超时解锁时间 单位：分钟 作用：当任务长时间阻塞，超过一定的时间后自动解锁（防止任务永远得不到运行的情况）
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/10/30 14:02
     * 参数区：
     *
     * @param int $minute 默认30分钟超时自动解锁 提示：设置过小，容易导致调度任务解锁重复运行
     *
     * @return int
     */
    public function unlock_timeout(int $minute = 30): int;
}