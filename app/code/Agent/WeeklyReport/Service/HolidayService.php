<?php

declare(strict_types=1);

namespace Agent\WeeklyReport\Service;

/**
 * 中国节假日服务
 * 
 * 职责：判断日期是否为节假日、获取节假日信息
 */
class HolidayService
{
    private array $holidayData = [];
    private bool $loaded = false;

    /**
     * 加载节假日数据
     */
    private function loadHolidayData(int $year = 2026): void
    {
        if ($this->loaded) {
            return;
        }

        $dataFile = __DIR__ . '/../Data/holidays_' . $year . '.json';

        if (file_exists($dataFile)) {
            $content = file_get_contents($dataFile);
            $this->holidayData = json_decode($content, true) ?: [];
        }

        $this->loaded = true;
    }

    /**
     * 检查日期是否为节假日
     */
    public function isHoliday(string $date): bool
    {
        $this->loadHolidayData();

        $dateTime = new \DateTime($date);
        $dateStr = $dateTime->format('Y-m-d');

        foreach ($this->holidayData['holidays'] ?? [] as $holiday) {
            $start = new \DateTime($holiday['start_date']);
            $end = new \DateTime($holiday['end_date']);

            if ($dateTime >= $start && $dateTime <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查日期是否为调休工作日
     */
    public function isWorkday(string $date): bool
    {
        $this->loadHolidayData();

        $dateTime = new \DateTime($date);
        $dateStr = $dateTime->format('Y-m-d');

        foreach ($this->holidayData['workdays'] ?? [] as $workday) {
            if ($workday['date'] === $dateStr) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取日期所在的节假日名称
     */
    public function getHolidayName(string $date): ?string
    {
        $this->loadHolidayData();

        $dateTime = new \DateTime($date);

        foreach ($this->holidayData['holidays'] ?? [] as $holiday) {
            $start = new \DateTime($holiday['start_date']);
            $end = new \DateTime($holiday['end_date']);

            if ($dateTime >= $start && $dateTime <= $end) {
                return $holiday['name'];
            }
        }

        return null;
    }

    /**
     * 检查周是否为节假日周（周内包含重要节假日）
     */
    public function isHolidayWeek(string $weekStartDate, string $weekEndDate): ?array
    {
        $this->loadHolidayData();

        $weekStart = new \DateTime($weekStartDate);
        $weekEnd = new \DateTime($weekEndDate);

        foreach ($this->holidayData['holidays'] ?? [] as $holiday) {
            $holidayStart = new \DateTime($holiday['start_date']);
            $holidayEnd = new \DateTime($holiday['end_date']);
            $holidayDays = $holiday['days'] ?? 1;

            if ($holidayDays >= 5 && $holidayStart <= $weekEnd && $holidayEnd >= $weekStart) {
                return [
                    'is_holiday' => true,
                    'name' => $holiday['name'],
                    'start_date' => $holiday['start_date'],
                    'end_date' => $holiday['end_date'],
                ];
            }
        }

        return null;
    }

    /**
     * 获取所有节假日列表
     */
    public function getAllHolidays(): array
    {
        $this->loadHolidayData();
        return $this->holidayData['holidays'] ?? [];
    }

    /**
     * 获取所有调休工作日
     */
    public function getAllWorkdays(): array
    {
        $this->loadHolidayData();
        return $this->holidayData['workdays'] ?? [];
    }
}
