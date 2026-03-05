<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\AutoLeadAgent\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '搜索任务表')]
#[Index(name: 'idx_store_id', columns: ['store_id'], comment: '店铺ID索引')]
#[Index(name: 'idx_status', columns: ['status'], comment: '状态索引')]
#[Index(name: 'idx_created_at', columns: ['created_at'], comment: '创建时间索引')]
class SearchTask extends Model
{
    public const schema_table = 'weline_auto_lead_agent_search_task';
    public const schema_primary_key = 'task_id';
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '任务ID')]
    public const schema_fields_ID = 'task_id';
    #[Col('int', 0, nullable: true, comment: '店铺ID（兼容字段，保留向后兼容）')]
    public const schema_fields_STORE_ID = 'store_id';
    #[Col('varchar', 64, nullable: true, comment: '来源类型（如 store）')]
    public const schema_fields_SOURCE_TYPE = 'source_type';
    #[Col('varchar', 64, nullable: true, comment: '来源ID（如店铺ID）')]
    public const schema_fields_SOURCE_ID = 'source_id';
    #[Col('varchar', 50, nullable: false, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', 0, nullable: true, comment: '进度（兼容字段，已废弃）')]
    public const schema_fields_PROGRESS = 'progress';
    #[Col('int', 0, nullable: true, comment: '找到的潜在客户数量')]
    public const schema_fields_FOUND_COUNT = 'found_count';
    #[Col('text', comment: '结果数据（JSON格式）')]
    public const schema_fields_RESULT_DATA = 'result_data';
    #[Col('text', nullable: true, comment: '选中的搜索引擎（JSON格式）')]
    public const schema_fields_SELECTED_SEARCH_ENGINES = 'selected_search_engines';
    #[Col('text', nullable: true, comment: '选中的目标网站（JSON格式）')]
    public const schema_fields_SELECTED_TARGET_WEBSITES = 'selected_target_websites';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
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
    public array $_unit_primary_keys = [self::schema_fields_ID];
    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['task_id', 'store_id', 'status', 'created_at'];
    public function _init(): void
    {
        $this->_primary_key = self::schema_fields_ID;
    }
    /**
     * 获取状态标签
     */
    public function getStatusLabel(): string
    {
        $status = $this->getData(self::schema_fields_STATUS);
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
        $this->setData(self::schema_fields_SELECTED_SEARCH_ENGINES, json_encode($engines, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    /**
     * 获取选中的搜索引擎数组
     * 
     * @return array 搜索引擎数组
     */
    public function getSelectedSearchEnginesArray(): array
    {
        $data = $this->getData(self::schema_fields_SELECTED_SEARCH_ENGINES);
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
        $this->setData(self::schema_fields_SELECTED_TARGET_WEBSITES, json_encode($websites, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    /**
     * 获取选中的目标网站数组
     * 
     * @return array 目标网站数组
     */
    public function getSelectedTargetWebsitesArray(): array
    {
        $data = $this->getData(self::schema_fields_SELECTED_TARGET_WEBSITES);
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
