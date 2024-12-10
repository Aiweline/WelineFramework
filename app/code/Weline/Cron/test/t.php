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


function parse_crontab($frequency = '* * * * *', $time = false)
{
    $time    = is_string($time) ? strtotime($time) : time();
    $time    = explode(' ', date('i G j n w', $time));
    $time[0] = $time[0] + 0;
    $crontab = explode(' ', $frequency);
    foreach ($crontab as $k => &$v) {
        $v       = explode(',', $v);
        $regexps = array(
            '/^\*$/', # every
            '/^\d+$/', # digit
            '/^(\d+)\-(\d+)$/', # range
            '/^\*\/(\d+)$/' # every digit
        );
        $content = array(
            'true', # every
            "{$time[$k]} === $0", # digit
            "($1 <= {$time[$k]} && {$time[$k]} <= $2)", # range
            "{$time[$k]} % $1 === 0" # every digit
        );
        foreach ($v as &$v1) {
            $v1 = preg_replace($regexps, $content, $v1);
        }
        $v = '(' . implode(' || ', $v) . ')';
    }
    $crontab = implode(' && ', $crontab);
    return eval("return {$crontab};");
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
