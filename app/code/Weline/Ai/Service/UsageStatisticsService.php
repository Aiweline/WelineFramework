<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiUsageLog;
use Weline\Framework\Manager\ObjectManager;

/**
 * AI 使用量统计服务
 * 按日/周/月/年汇总 m_ai_usage_log，并支持按模型倒序排名（tokens 最多在前）
 */
class UsageStatisticsService
{
    public const PERIOD_DAY = 'day';
    public const PERIOD_WEEK = 'week';
    public const PERIOD_MONTH = 'month';
    public const PERIOD_YEAR = 'year';

    private AiUsageLog $usageLog;

    public function __construct(?AiUsageLog $usageLog = null)
    {
        $this->usageLog = $usageLog ?? ObjectManager::getInstance(AiUsageLog::class);
    }

    /**
     * 获取指定周期的起止时间 [from, to]（Y-m-d H:i:s）
     */
    public function getPeriodRange(string $period): array
    {
        $today = date('Y-m-d');
        switch ($period) {
            case self::PERIOD_DAY:
                return [$today . ' 00:00:00', $today . ' 23:59:59'];
            case self::PERIOD_WEEK:
                $monday = date('Y-m-d', strtotime('monday this week'));
                $sunday = date('Y-m-d', strtotime('sunday this week'));
                return [$monday . ' 00:00:00', $sunday . ' 23:59:59'];
            case self::PERIOD_MONTH:
                $first = date('Y-m-01');
                $last = date('Y-m-t');
                return [$first . ' 00:00:00', $last . ' 23:59:59'];
            case self::PERIOD_YEAR:
                $first = date('Y-01-01');
                $last = date('Y-12-31');
                return [$first . ' 00:00:00', $last . ' 23:59:59'];
            default:
                return [$today . ' 00:00:00', $today . ' 23:59:59'];
        }
    }

    /**
     * 周期汇总：总 tokens、总成本、请求数
     */
    public function getPeriodSummary(string $period): array
    {
        [$from, $to] = $this->getPeriodRange($period);
        $model = ObjectManager::getInstance(AiUsageLog::class);
        $model->reset();
        $model->where(AiUsageLog::schema_fields_CREATED_AT, $from, '>=');
        $model->where(AiUsageLog::schema_fields_CREATED_AT, $to, '<=');
        $row = $model->fields(
            'COUNT(*) AS total_requests,' .
            'COALESCE(SUM(' . AiUsageLog::schema_fields_TOTAL_TOKENS . '), 0) AS total_tokens,' .
            'COALESCE(SUM(' . AiUsageLog::schema_fields_TOTAL_COST . '), 0) AS total_cost'
        )->find()->fetch();
        $row = $this->normalizeSingleRow($row);
        if (!$row || !is_array($row)) {
            return [
                'total_requests' => 0,
                'total_tokens' => 0,
                'total_cost' => 0.0,
                'total_cost_formatted' => '0.00',
            ];
        }
        $totalCost = (float)($row['total_cost'] ?? 0);
        return [
            'total_requests' => (int)($row['total_requests'] ?? 0),
            'total_tokens' => (int)($row['total_tokens'] ?? 0),
            'total_cost' => $totalCost,
            'total_cost_formatted' => number_format($totalCost, 4),
        ];
    }

    /**
     * 按模型排名：倒序（消耗 tokens 最多在前），含约花费
     */
    public function getModelRanking(string $period): array
    {
        [$from, $to] = $this->getPeriodRange($period);
        $model = ObjectManager::getInstance(AiUsageLog::class);
        $model->reset();
        $model->where(AiUsageLog::schema_fields_CREATED_AT, $from, '>=');
        $model->where(AiUsageLog::schema_fields_CREATED_AT, $to, '<=');
        $model->fields(
            AiUsageLog::schema_fields_MODEL_CODE . ' AS model_code,' .
            'COALESCE(SUM(' . AiUsageLog::schema_fields_TOTAL_TOKENS . '), 0) AS total_tokens,' .
            'COALESCE(SUM(' . AiUsageLog::schema_fields_TOTAL_COST . '), 0) AS total_cost'
        );
        $model->group(AiUsageLog::schema_fields_MODEL_CODE);
        $model->order('total_tokens', 'DESC');
        $items = $this->normalizeRows($model->select()->fetch());
        $list = [];
        foreach ($items as $row) {
            $cost = (float)($row['total_cost'] ?? 0);
            $list[] = [
                'model_code' => $row['model_code'] ?? '',
                'total_tokens' => (int)($row['total_tokens'] ?? 0),
                'total_cost' => $cost,
                'total_cost_formatted' => number_format($cost, 4),
            ];
        }
        return $list;
    }

    /**
     * 供 Hook/仪表盘使用：多周期汇总 + 模型排名（默认今日）
     */
    public function getStatsForPanel(string $period = self::PERIOD_DAY): array
    {
        return [
            'period' => $period,
            'summary' => $this->getPeriodSummary($period),
            'model_ranking' => $this->getModelRanking($period),
        ];
    }

    /**
     * 兼容 ORM fetch 可能返回数组/模型对象/结果对象的差异。
     */
    private function normalizeSingleRow(mixed $row): array
    {
        if (is_array($row)) {
            return $row;
        }
        if (is_object($row) && method_exists($row, 'getData')) {
            $data = $row->getData();
            return is_array($data) ? $data : [];
        }
        return [];
    }

    /**
     * 兼容 ORM 列表查询返回结构，统一为二维数组。
     */
    private function normalizeRows(mixed $rows): array
    {
        if (is_array($rows)) {
            return $rows;
        }
        if (is_object($rows) && method_exists($rows, 'getItems')) {
            $items = $rows->getItems();
            return is_array($items) ? $items : [];
        }
        if (is_object($rows) && method_exists($rows, 'getData')) {
            $data = $rows->getData();
            return is_array($data) ? [$data] : [];
        }
        return [];
    }
}
