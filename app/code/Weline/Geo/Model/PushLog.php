<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Geo\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 推送日志模型
 * @package Weline_Geo
 */
#[Table(comment: 'GEO推送日志表')]
#[Index(name: 'idx_feed_id', columns: ['feed_id'], comment: 'Feed ID索引')]
#[Index(name: 'idx_platform_id', columns: ['platform_id'], comment: '平台ID索引')]
#[Index(name: 'idx_status', columns: ['status'], comment: '状态索引')]
#[Index(name: 'idx_pushed_at', columns: ['pushed_at'], comment: '推送时间索引')]
class PushLog extends Model
{
    public const schema_table = 'geo_push_log';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'feed_id', 'platform_id', 'status', 'pushed_at'];
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', nullable: false, comment: '关联Feed ID')]
    public const schema_fields_FEED_ID = 'feed_id';
    #[Col('int', nullable: false, comment: '关联平台ID')]
    public const schema_fields_PLATFORM_ID = 'platform_id';
    #[Col('int', comment: '关联账户ID')]
    public const schema_fields_PLATFORM_ACCOUNT_ID = 'platform_account_id';
    #[Col('varchar', 20, default: 'manual', comment: '推送类型')]
    public const schema_fields_PUSH_TYPE = 'push_type';
    #[Col('varchar', 20, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', default: 0, comment: '推送条目数')]
    public const schema_fields_ITEMS_COUNT = 'items_count';
    #[Col('text', comment: '响应数据JSON')]
    public const schema_fields_RESPONSE_DATA = 'response_data';
    #[Col('text', comment: '错误消息')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';
    #[Col('int', comment: '推送时间')]
    public const schema_fields_PUSHED_AT = 'pushed_at';
    #[Col('int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    /**
     * Push types
     */
    public const TYPE_MANUAL = 'manual';
    public const TYPE_AUTO = 'auto';
    public const TYPE_SCHEDULED = 'scheduled';
    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    /**
     * Initialize model
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }
    /**
     * 获取主键字段名
     * 
     * @return string
     */
    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
/**
     * 获取响应数据数组
     * 
     * @return array
     */
    public function getResponseDataArray(): array
    {
        $data = $this->getData(self::schema_fields_RESPONSE_DATA);
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($data) ? $data : [];
    }
    /**
     * 设置响应数据数组
     * 
     * @param array $data
     * @return self
     */
    public function setResponseDataArray(array $data): self
    {
        $this->setData(self::schema_fields_RESPONSE_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    /**
     * 检查是否成功
     * 
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_SUCCESS;
    }
    /**
     * 检查是否失败
     * 
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_FAILED;
    }
}
