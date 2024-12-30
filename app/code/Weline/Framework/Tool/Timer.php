<?php

namespace Weline\Framework\Tool;

use DateTime;

class Timer
{
    public static function offset_with_times_range(string &$date, string &$offset_type = 'day', int $offset = -1, int $times = 4, bool $need_time = false, array &$rang_times = []): string|array
    {
        $offset_types = ['day', 'week', 'month', 'quarter'];
        if (!in_array($offset_type, $offset_types)) {
            throw new \Exception('offset_type 不存在, 请选择 ' . implode(',', $offset_types));
        }
        if ($offset > 0) {
            $offset = '+' . $offset;
        } else {
            $offset = '-' . abs($offset);
        }
        if (empty($rang_times) and $times > 1) {
            for ($i = 0; $i < $times; $i++) {
                $rang_times[$i] = self::offset_with_times_range($date, $offset_type, $offset * $i, 1, $need_time, $rang_times);
            }
            return $rang_times;
        }
        $start_time = '';
        $end_time = '';
        if ($need_time) {
            $start_time = '00:00:00';
            $end_time = '23:59:59';
        }
        switch ($offset_type) {
            case 'day':
                $dateTime = new DateTime($date);
                $dateTime->modify($offset * 1 . ' day');
                $dayStart = $dateTime->format('Y-m-d ' . $start_time);
                $dateTime->modify($offset * 1 . ' day');
                $dayEnd = $dateTime->format('Y-m-d ' . $end_time);
                return [
                    'start' => $dayStart,
                    'end' => $dayEnd
                ];
            case 'week':
                # 根据日期获取这周的开始时间和结束时间
                // 创建日期对象
                $dateTime = new DateTime($date);
                $dateTime->modify($offset * 1 . ' week');  // 偏移天数
                // 设置为本周的第一天 (星期一)
                $dateTime->modify('this week monday');
                $weekStart = $dateTime->format('Y-m-d ' . $start_time);
                // 设置为本周的最后一天 (星期日)
                $dateTime->modify('this week sunday');
                $weekEnd = $dateTime->format('Y-m-d ' . $end_time);
                return [
                    'start' => $weekStart,
                    'end' => $weekEnd
                ];
            case 'month':
                $dateTime = new DateTime($date);
                $dateTime->modify($offset * 1 . ' month');
                $dateTime->modify('first day of this month');
                $monthStart = $dateTime->format('Y-m-01 ' . $start_time);
                $dateTime->modify('last day of this month');
                $monthEnd = $dateTime->format('Y-m-t ' . $end_time);
                return [
                    'start' => $monthStart,
                    'end' => $monthEnd
                ];
            case 'quarter':
                // 当前日期
                $dateTime = new DateTime();

                // 偏移季度数
                $dateTime->modify($offset * 3 . ' month');

                // 获取季度的第一天和最后一天
                $quarter = ceil(($dateTime->format('n') / 3));
                $year = $dateTime->format('Y');

                // 计算季度的第一天和最后一天
                $startMonth = ($quarter - 1) * 3 + 1;
                $endMonth = $quarter * 3;

                $firstDayOfQuarter = date('Y-m-d ' . $start_time, strtotime("$year-$startMonth-01"));
                $lastDayOfQuarter = date('Y-m-t ' . $end_time, strtotime("$year-$endMonth-01"));
                return ['start' => $firstDayOfQuarter, 'end' => $lastDayOfQuarter];
            default:
                return $date;
        }
        return date('Y-m-d', strtotime("{$offset} $offset_type", strtotime($date)));
    }
}