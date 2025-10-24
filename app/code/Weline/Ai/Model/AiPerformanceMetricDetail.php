<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/11
 */

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 性能指标详情模型
 * 
 * 功能：
 * - 记录详细性能指标
 * - 支持分维度统计
 * - 提供性能分析数据
 */
class AiPerformanceMetricDetail extends \Weline\Framework\Database\Model
{
    public const table = 'ai_performance_metric_detail';
    public const fields_ID = 'id';
    public const fields_USAGE_LOG_ID = 'usage_log_id';
    public const fields_METRIC_NAME = 'metric_name';
    public const fields_METRIC_VALUE = 'metric_value';
    public const fields_METRIC_UNIT = 'metric_unit';
    public const fields_DIMENSION = 'dimension'; // request, response, processing, network
    public const fields_THRESHOLD = 'threshold';
    public const fields_IS_ABNORMAL = 'is_abnormal';
    public const fields_CREATED_TIME = 'created_time';

    /**
     * 维度常量
     */
    public const DIMENSION_REQUEST = 'request';
    public const DIMENSION_RESPONSE = 'response';
    public const DIMENSION_PROCESSING = 'processing';
    public const DIMENSION_NETWORK = 'network';
    public const DIMENSION_DATABASE = 'database';
    public const DIMENSION_CACHE = 'cache';

    /**
     * 设置模型
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级模型
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: 实现升级逻辑
    }

    /**
     * 安装数据表
     * 
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '主键ID')
                ->addColumn(self::fields_USAGE_LOG_ID, TableInterface::column_type_INTEGER, null, 'not null', '使用日志ID')
                ->addColumn(self::fields_METRIC_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '指标名称')
                ->addColumn(self::fields_METRIC_VALUE, TableInterface::column_type_DECIMAL, '10,4', 'not null', '指标值')
                ->addColumn(self::fields_METRIC_UNIT, TableInterface::column_type_VARCHAR, 50, 'null', '单位')
                ->addColumn(self::fields_DIMENSION, TableInterface::column_type_VARCHAR, 50, 'not null', '维度')
                ->addColumn(self::fields_THRESHOLD, TableInterface::column_type_DECIMAL, '10,4', 'null', '阈值')
                ->addColumn(self::fields_IS_ABNORMAL, TableInterface::column_type_SMALLINT, 1, 'default 0', '是否异常')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, null, 'not null', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_usage_log_id', self::fields_USAGE_LOG_ID, '使用日志索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_metric_name', self::fields_METRIC_NAME, '指标名称索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_dimension', self::fields_DIMENSION, '维度索引')
                ->create();
        }
    }

    /**
     * 检查是否超过阈值
     * 
     * @return bool
     */
    public function isAboveThreshold(): bool
    {
        $threshold = (float)$this->getData(self::fields_THRESHOLD);
        $value = (float)$this->getData(self::fields_METRIC_VALUE);

        return $threshold > 0 && $value > $threshold;
    }

    /**
     * 获取格式化值
     * 
     * @return string
     */
    public function getFormattedValue(): string
    {
        $value = $this->getData(self::fields_METRIC_VALUE);
        $unit = $this->getData(self::fields_METRIC_UNIT);

        return $value . ($unit ? ' ' . $unit : '');
    }

    /**
     * 保存前处理
     * 
     * @return $this
     */
    public function beforeSave(): self
    {
        parent::beforeSave();
        
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_TIME, time());
            
            // 自动检查是否超过阈值
            if ($this->isAboveThreshold()) {
                $this->setData(self::fields_IS_ABNORMAL, 1);
            }
        }
        
        return $this;
    }
}

