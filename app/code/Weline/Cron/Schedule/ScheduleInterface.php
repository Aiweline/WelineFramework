<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/27 21:01:35
 */

namespace Weline\Cron\Schedule;

interface ScheduleInterface
{
    function create(string $name): array;

    function run(string $name): array;

    function remove(string $name): array;

    function exist(string $name): bool;

    function getJobs(): array;
}