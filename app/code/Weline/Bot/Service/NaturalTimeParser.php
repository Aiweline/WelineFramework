<?php
declare(strict_types=1);

namespace Weline\Bot\Service;

/**
 * 自然语言时间解析器
 *
 * 将自然语言时间转换为 Cron 表达式
 */
class NaturalTimeParser
{
    // 中文数字映射
    private const CHINESE_NUMBERS = [
        '零' => 0, '一' => 1, '二' => 2, '三' => 3, '四' => 4,
        '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9, '十' => 10,
    ];

    // 星期映射
    private const WEEKDAY_MAP = [
        '周日' => 0, '星期日' => 0, '周天' => 0,
        '周一' => 1, '星期一' => 1,
        '周二' => 2, '星期二' => 2,
        '周三' => 3, '星期三' => 3,
        '周四' => 4, '星期四' => 4,
        '周五' => 5, '星期五' => 5,
        '周六' => 6, '星期六' => 6,
    ];

    // 时间段映射
    private const TIME_PERIODS = [
        '凌晨' => [0, 6],
        '早上' => [6, 9],
        '上午' => [9, 12],
        '中午' => [11, 14],
        '下午' => [14, 18],
        '傍晚' => [17, 19],
        '晚上' => [18, 22],
        '夜间' => [22, 24],
    ];

    /**
     * 解析自然语言时间
     *
     * @param string $natural 自然语言描述
     * @return string|null Cron 表达式，解析失败返回 null
     */
    public function parse(string $natural): ?string
    {
        $natural = trim($natural);

        // 尝试各种模式
        return $this->tryParsePatterns($natural);
    }

    /**
     * 尝试匹配各种模式
     */
    private function tryParsePatterns(string $natural): ?string
    {
        // 每分钟
        if (preg_match('/每分钟|每\s*1?\s*分钟/', $natural)) {
            return '* * * * *';
        }

        // 每小时
        if (preg_match('/每小时|每\s*1?\s*小时/', $natural)) {
            return '0 * * * *';
        }

        // 每天
        if (preg_match('/每天|每日/', $natural)) {
            return $this->parseDaily($natural);
        }

        // 每周
        if (preg_match('/每周|每星期/', $natural)) {
            return $this->parseWeekly($natural);
        }

        // 每月
        if (preg_match('/每月/', $natural)) {
            return $this->parseMonthly($natural);
        }

        // 每隔 N 分钟
        if (preg_match('/每隔?\s*(\d+)\s*分钟/', $natural, $matches)) {
            $n = (int) $matches[1];
            return "*/{$n} * * * *";
        }

        // 每隔 N 小时
        if (preg_match('/每隔?\s*(\d+)\s*小时/', $natural, $matches)) {
            $n = (int) $matches[1];
            return "0 */{$n} * * *";
        }

        return null;
    }

    /**
     * 解析每天的时间
     */
    private function parseDaily(string $natural): string
    {
        $minute = 0;
        $hour = 8; // 默认早上 8 点

        // 解析时间段
        foreach (self::TIME_PERIODS as $period => $range) {
            if (str_contains($natural, $period)) {
                $hour = $range[0];
                break;
            }
        }

        // 解析具体时间
        if (preg_match('/(\d{1,2})[点时:：](\d{1,2})?分?/', $natural, $matches)) {
            $hour = (int) $matches[1];
            $minute = isset($matches[2]) ? (int) $matches[2] : 0;
        } elseif (preg_match('/(\d{1,2})[点时]/', $natural, $matches)) {
            $hour = (int) $matches[1];
        }

        // 中文数字
        if (preg_match('/([一二三四五六七八九十]+)[点时]/', $natural, $matches)) {
            $hour = $this->parseChineseNumber($matches[1]);
        }

        return "{$minute} {$hour} * * *";
    }

    /**
     * 解析每周的时间
     */
    private function parseWeekly(string $natural): string
    {
        $weekday = 1; // 默认周一
        $hour = 8;
        $minute = 0;

        // 解析星期
        foreach (self::WEEKDAY_MAP as $name => $day) {
            if (str_contains($natural, $name)) {
                $weekday = $day;
                break;
            }
        }

        // 解析时间
        if (preg_match('/(\d{1,2})[点时:：](\d{1,2})?/', $natural, $matches)) {
            $hour = (int) $matches[1];
            $minute = isset($matches[2]) ? (int) $matches[2] : 0;
        }

        return "{$minute} {$hour} * * {$weekday}";
    }

    /**
     * 解析每月的时间
     */
    private function parseMonthly(string $natural): string
    {
        $day = 1; // 默认每月 1 号
        $hour = 8;
        $minute = 0;

        // 解析日期
        if (preg_match('/(\d{1,2})[号日]/', $natural, $matches)) {
            $day = (int) $matches[1];
        }

        // 中文日期
        if (preg_match('/([一二三四五六七八九十]+)[号日]/', $natural, $matches)) {
            $day = $this->parseChineseNumber($matches[1]);
        }

        // 解析时间
        if (preg_match('/(\d{1,2})[点时:：](\d{1,2})?/', $natural, $matches)) {
            $hour = (int) $matches[1];
            $minute = isset($matches[2]) ? (int) $matches[2] : 0;
        }

        return "{$minute} {$hour} {$day} * *";
    }

    /**
     * 解析中文数字
     */
    private function parseChineseNumber(string $chinese): int
    {
        if (isset(self::CHINESE_NUMBERS[$chinese])) {
            return self::CHINESE_NUMBERS[$chinese];
        }

        // 处理十一到十九
        if (str_starts_with($chinese, '十')) {
            $remainder = substr($chinese, 1);
            if (empty($remainder)) {
                return 10;
            }
            return 10 + (self::CHINESE_NUMBERS[$remainder] ?? 0);
        }

        // 处理二十、三十等
        if (str_ends_with($chinese, '十')) {
            $prefix = substr($chinese, 0, -1);
            return (self::CHINESE_NUMBERS[$prefix] ?? 0) * 10;
        }

        return 0;
    }

    /**
     * 验证 Cron 表达式
     */
    public function validate(string $cron): bool
    {
        $parts = explode(' ', $cron);
        if (count($parts) !== 5) {
            return false;
        }

        // 简单验证各部分
        foreach ($parts as $part) {
            if ($part === '*') continue;
            if (preg_match('/^\d+$/', $part)) continue;
            if (preg_match('/^\*\/\d+$/', $part)) continue;
            if (preg_match('/^\d+-\d+$/', $part)) continue;
            if (preg_match('/^\d+(,\d+)*$/', $part)) continue;
            return false;
        }

        return true;
    }

    /**
     * 获取人类可读的描述
     */
    public function toHumanReadable(string $cron): string
    {
        $parts = explode(' ', $cron);
        if (count($parts) !== 5) {
            return $cron;
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;

        $descriptions = [];

        // 频率
        if ($minute === '*' && $hour === '*') {
            return '每分钟';
        }

        if ($minute === '0' && str_starts_with($hour, '*/')) {
            $n = substr($hour, 2);
            return "每隔 {$n} 小时";
        }

        if (str_starts_with($minute, '*/')) {
            $n = substr($minute, 2);
            return "每隔 {$n} 分钟";
        }

        // 每天
        if ($day === '*' && $month === '*' && $weekday === '*') {
            return "每天 {$hour}:{$minute}";
        }

        // 每周
        if ($weekday !== '*') {
            $weekdays = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];
            return "每{$weekdays[(int)$weekday]} {$hour}:{$minute}";
        }

        // 每月
        if ($day !== '*') {
            return "每月 {$day} 号 {$hour}:{$minute}";
        }

        return $cron;
    }
}
