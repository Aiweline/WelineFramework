<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class SearchTask extends AbstractModel
{
    public const table = 'weline_auto_lead_agent_search_task';
    
    public const fields_ID = 'task_id';
    public const fields_STORE_ID = 'store_id';        // 兼容字段（保留向后兼容）
    public const fields_SOURCE_TYPE = 'source_type'; // 来源类型（如 'store'）
    public const fields_SOURCE_ID = 'source_id';     // 来源ID（如店铺ID）
    public const fields_STATUS = 'status';
    public const fields_PROGRESS = 'progress';        // 兼容字段（已废弃）
    public const fields_FOUND_COUNT = 'found_count';  // 找到的潜在客户数量
    public const fields_RESULT_DATA = 'result_data';
    public const fields_SELECTED_SEARCH_ENGINES = 'selected_search_engines';  // 选中的搜索引擎（JSON格式）
    public const fields_SELECTED_TARGET_WEBSITES = 'selected_target_websites';  // 选中的目标网站（JSON格式）
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 状态常量
    public const STATUS_PENDING = 'pending';       // 待执行
    public const STATUS_RUNNING = 'running';       // 运行中（通用）
    public const STATUS_INFERRING = 'inferring';   // 推理中
    public const STATUS_CRAWLING = 'crawling';     // 爬取中
    public const STATUS_COMPLETED = 'completed';   // 已完成
    public const STATUS_FAILED = 'failed';         // 失败
    public const STATUS_CANCELLED = 'cancelled';   // 已取消

    // 状态显示文本
    public const STATUS_LABELS = [
        self::STATUS_PENDING => '待执行',
        self::STATUS_RUNNING => '运行中',
        self::STATUS_INFERRING => '推理中',
        self::STATUS_CRAWLING => '爬取中',
        self::STATUS_COMPLETED => '已完成',
        self::STATUS_FAILED => '失败',
        self::STATUS_CANCELLED => '已取消',
    ];

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['task_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['task_id', 'store_id', 'status', 'created_at'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_primary_key = self::fields_ID;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable(__('搜索任务表'))
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    __('任务ID')
                )
                ->addColumn(
                    self::fields_STORE_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'null',
                    __('店铺ID（兼容字段）')
                )
                ->addColumn(
                    self::fields_SOURCE_TYPE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'null',
                    __('来源类型（如 store、product 等）')
                )
                ->addColumn(
                    self::fields_SOURCE_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'null',
                    __('来源ID（如店铺ID、产品ID等）')
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null default \'pending\'',
                    __('状态（pending/running/completed/failed/cancelled）')
                )
                ->addColumn(
                    self::fields_PROGRESS,
                    TableInterface::column_type_DECIMAL,
                    '5,2',
                    'not null default 0.00',
                    __('进度（已废弃，保留兼容）')
                )
                ->addColumn(
                    self::fields_FOUND_COUNT,
                    TableInterface::column_type_INTEGER,
                    null,
                    'not null default 0',
                    __('找到的潜在客户数量')
                )
                ->addColumn(
                    self::fields_RESULT_DATA,
                    TableInterface::column_type_TEXT,
                    null,
                    '',
                    __('结果数据（JSON格式）')
                )
                ->addColumn(
                    self::fields_SELECTED_SEARCH_ENGINES,
                    TableInterface::column_type_TEXT,
                    null,
                    'null',
                    __('选中的搜索引擎列表（JSON格式）')
                )
                ->addColumn(
                    self::fields_SELECTED_TARGET_WEBSITES,
                    TableInterface::column_type_TEXT,
                    null,
                    'null',
                    __('选中的目标网站列表（JSON格式）')
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    null,
                    'not null default current_timestamp',
                    __('创建时间')
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_TIMESTAMP,
                    null,
                    'not null default current_timestamp on update current_timestamp',
                    __('更新时间')
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_store_id',
                    self::fields_STORE_ID,
                    __('店铺ID索引')
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_status',
                    self::fields_STATUS,
                    __('状态索引')
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_created_at',
                    self::fields_CREATED_AT,
                    __('创建时间索引')
                )
                ->create();
        }
    }

    /**
     * 设置表结构（开发模式）
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 获取状态标签
     */
    public function getStatusLabel(): string
    {
        $status = $this->getData(self::fields_STATUS);
        return self::STATUS_LABELS[$status] ?? $status;
    }

    /**
     * 设置选中的搜索引擎数组
     * 
     * @param array $engines 搜索引擎数组（可以是名称或ID）
     * @return $this
     */
    public function setSelectedSearchEnginesArray(array $engines): self
    {
        $this->setData(self::fields_SELECTED_SEARCH_ENGINES, json_encode($engines, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 获取选中的搜索引擎数组
     * 
     * @return array 搜索引擎数组
     */
    public function getSelectedSearchEnginesArray(): array
    {
        $data = $this->getData(self::fields_SELECTED_SEARCH_ENGINES);
        if (empty($data)) {
            return [];
        }
        
        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return [];
        }
        
        return $decoded;
    }

    /**
     * 设置选中的目标网站数组
     * 
     * @param array $websites 目标网站数组（可以是名称或ID）
     * @return $this
     */
    public function setSelectedTargetWebsitesArray(array $websites): self
    {
        $this->setData(self::fields_SELECTED_TARGET_WEBSITES, json_encode($websites, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 获取选中的目标网站数组
     * 
     * @return array 目标网站数组
     */
    public function getSelectedTargetWebsitesArray(): array
    {
        $data = $this->getData(self::fields_SELECTED_TARGET_WEBSITES);
        if (empty($data)) {
            return [];
        }
        
        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return [];
        }
        
        return $decoded;
    }
}

