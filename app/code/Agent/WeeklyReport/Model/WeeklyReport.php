<?php

declare(strict_types=1);

namespace Agent\WeeklyReport\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 周报主表模型
 */
class WeeklyReport extends Model
{
    public const table = 'agent_weekly_report';
    public string $_primary_key = 'report_id';

    public const fields_ID = 'report_id';
    public const fields_YEAR = 'year';
    public const fields_WEEK_NUMBER = 'week_number';
    public const fields_WEEK_START_DATE = 'week_start_date';
    public const fields_WEEK_END_DATE = 'week_end_date';
    public const fields_IS_HOLIDAY_WEEK = 'is_holiday_week';
    public const fields_HOLIDAY_NAME = 'holiday_name';
    public const fields_NOTES = 'notes';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('周报主表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                null,
                'primary key auto_increment',
                '周报ID'
            )
            ->addColumn(
                self::fields_YEAR,
                TableInterface::column_type_INTEGER,
                null,
                'not null',
                '年份'
            )
            ->addColumn(
                self::fields_WEEK_NUMBER,
                TableInterface::column_type_INTEGER,
                null,
                'not null',
                '周次（1-52）'
            )
            ->addColumn(
                self::fields_WEEK_START_DATE,
                TableInterface::column_type_DATE,
                null,
                'not null',
                '周起始日期'
            )
            ->addColumn(
                self::fields_WEEK_END_DATE,
                TableInterface::column_type_DATE,
                null,
                'not null',
                '周结束日期'
            )
            ->addColumn(
                self::fields_IS_HOLIDAY_WEEK,
                TableInterface::column_type_TINYINT,
                1,
                'not null default 0',
                '是否节假日周（0否1是）'
            )
            ->addColumn(
                self::fields_HOLIDAY_NAME,
                TableInterface::column_type_VARCHAR,
                100,
                'default null',
                '节假日名称'
            )
            ->addColumn(
                self::fields_NOTES,
                TableInterface::column_type_TEXT,
                null,
                '',
                '备注'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                TableInterface::column_type_DATETIME,
                null,
                'not null default CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATED_AT,
                TableInterface::column_type_DATETIME,
                null,
                'not null default CURRENT_TIMESTAMP',
                '更新时间'
            )
            ->addIndex(
                TableInterface::index_type_UNIQUE,
                'uk_year_week',
                [self::fields_YEAR, self::fields_WEEK_NUMBER],
                '年份周次唯一索引'
            )
            ->create();
    }

    /**
     * 初始化模型（必须实现）
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            return;
        }
    }

    /**
     * 获取当前周报
     */
    public function getCurrentWeekReport(int $year, int $weekNumber): ?self
    {
        $query = clone $this;
        $query->where(self::fields_YEAR, $year)
            ->where(self::fields_WEEK_NUMBER, $weekNumber)
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
            self::fields_YEAR => $year,
            self::fields_WEEK_NUMBER => $weekNumber,
            self::fields_WEEK_START_DATE => $startDate,
            self::fields_WEEK_END_DATE => $endDate,
        ]);
        $newReport->save();

        return $newReport;
    }

    /**
     * 设置为节假日周
     */
    public function setAsHolidayWeek(string $holidayName): self
    {
        $this->setData(self::fields_IS_HOLIDAY_WEEK, 1);
        $this->setData(self::fields_HOLIDAY_NAME, $holidayName);
        return $this;
    }

    /**
     * 是否为节假日周
     */
    public function isHolidayWeek(): bool
    {
        return (bool) $this->getData(self::fields_IS_HOLIDAY_WEEK);
    }

    /**
     * 获取所有周报列表
     */
    public function getAllReports(?int $year = null): array
    {
        $query = clone $this;

        if ($year) {
            $query->where(self::fields_YEAR, $year);
        }

        return $query->order(self::fields_YEAR, 'DESC')
            ->order(self::fields_WEEK_NUMBER, 'DESC')
            ->select()
            ->fetch()
            ->getItems();
    }
}
