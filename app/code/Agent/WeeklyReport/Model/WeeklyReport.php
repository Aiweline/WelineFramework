<?php

declare(strict_types=1);

namespace Agent\WeeklyReport\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 周报主表模型
 */
#[Table(comment: '周报主表')]
#[Index(name: 'uk_year_week', columns: ['year', 'week_number'], type: 'UNIQUE', comment: '年份周次唯一索引')]
class WeeklyReport extends Model
{
    public const schema_table = 'agent_weekly_report';
    public const schema_primary_key = 'report_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '周报ID')]
    public const schema_fields_ID = 'report_id';

    #[Col(type: 'int', nullable: false, comment: '年份')]
    public const schema_fields_YEAR = 'year';
    #[Col(type: 'int', nullable: false, comment: '周次（1-52）')]
    public const schema_fields_WEEK_NUMBER = 'week_number';
    #[Col(type: 'date', nullable: false, comment: '周起始日期')]
    public const schema_fields_WEEK_START_DATE = 'week_start_date';
    #[Col(type: 'date', nullable: false, comment: '周结束日期')]
    public const schema_fields_WEEK_END_DATE = 'week_end_date';
    #[Col(type: 'tinyint', length: 1, nullable: false, default: 0, comment: '是否节假日周（0否1是）')]
    public const schema_fields_IS_HOLIDAY_WEEK = 'is_holiday_week';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: '节假日名称')]
    public const schema_fields_HOLIDAY_NAME = 'holiday_name';
    #[Col(type: 'text', nullable: true, comment: '备注')]
    public const schema_fields_NOTES = 'notes';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    /**
     * 获取当前周报
     */
    public function getCurrentWeekReport(int $year, int $weekNumber): ?self
    {
        $query = clone $this;
        $query->where(self::schema_fields_YEAR, $year)
            ->where(self::schema_fields_WEEK_NUMBER, $weekNumber)
            ->find()
            ->fetch();

        return $query->getId() ? $query : null;
    }

    /**
     * 获取或创建当前周报
     */
    public function getOrCreateWeekReport(int $year, int $weekNumber, string $startDate, string $endDate): self
    {
        $report = $this->getCurrentWeekReport($year, $weekNumber);

        if ($report) {
            return $report;
        }

        $newReport = clone $this;
        $newReport->clearData();
        $newReport->setData([
            self::schema_fields_YEAR => $year,
            self::schema_fields_WEEK_NUMBER => $weekNumber,
            self::schema_fields_WEEK_START_DATE => $startDate,
            self::schema_fields_WEEK_END_DATE => $endDate,
        ]);
        $newReport->save();

        return $newReport;
    }

    /**
     * 设置为节假日周
     */
    public function setAsHolidayWeek(string $holidayName): self
    {
        $this->setData(self::schema_fields_IS_HOLIDAY_WEEK, 1);
        $this->setData(self::schema_fields_HOLIDAY_NAME, $holidayName);
        return $this;
    }

    /**
     * 是否为节假日周
     */
    public function isHolidayWeek(): bool
    {
        return (bool) $this->getData(self::schema_fields_IS_HOLIDAY_WEEK);
    }

    /**
     * 获取所有周报列表
     */
    public function getAllReports(?int $year = null): array
    {
        $query = clone $this;

        if ($year) {
            $query->where(self::schema_fields_YEAR, $year);
        }

        return $query->order(self::schema_fields_YEAR, 'DESC')
            ->order(self::schema_fields_WEEK_NUMBER, 'DESC')
            ->select()
            ->fetch()
            ->getItems();
    }
}

