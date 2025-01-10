<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/30 11:14:40
 */

namespace Weline\Cron\Helper;

enum CronStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case BLOCK = 'block';
    case FAIL = 'fail';
    case MISS = 'miss';

    static function displayProgressBar(string $title, int $current, int $total, bool $scoall = true): void
    {
        if($current>$total){
            $current = $total;
        }
        if ($scoall) echo PHP_EOL;
        $percentage      = (int)round(($current / $total) * 100);
        if(empty($percentage)){
            $percentage = 1;
        }
        $progress        = '[' . str_repeat("\033[42m>\033[0m", $percentage) . str_repeat(' ', 100 - $percentage) . ']';
        $coloredProgress = "\033[42m{$progress}\033[0m"; // 设置背景绿色
        echo "\033[34m {$title}（{$total}\\{$current}）: \033[0m {$coloredProgress} \033[32m {$percentage}% \033[0m \r";
        flush();
    }
}
