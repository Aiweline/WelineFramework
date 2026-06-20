<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/30 00:43:12
 */


function parse_crontab($frequency = '* * * * *', $time = false): bool
{
    require_once __DIR__ . '/cron.php';

    $timestamp = is_string($time) ? strtotime($time) : (is_int($time) ? $time : time());
    if ($timestamp === false) {
        return false;
    }

    return XwCrontab::check($timestamp, (string)$frequency) === true;
}

for ($i = 0; $i < 24; $i++) {
    for ($j = 0; $j < 60; $j++) {
        $date = sprintf('%d:%02d', $i, $j);
        if (parse_crontab('*/5 * * * *', $date)) {
            print "$date yes\n";
        } else {
            print "$date no\n";
        }
    }
}
