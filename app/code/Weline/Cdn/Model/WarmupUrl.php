<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Cdn\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 预热URL模型
 * @package Weline_Cdn
 */
#[Table(comment: '预热URL表')]
#[Index(name: 'idx_module', columns: ['module'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_enabled', columns: ['enabled'])]
#[Index(name: 'idx_domain_id', columns: ['domain_id'])]
#[Index(name: 'idx_url', columns: ['url'])]
class WarmupUrl extends Model
{
    public const schema_table = 'cdn_warmup_url';
    public const schema_primary_key = 'warmup_url_id';
    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['warmup_url_id'];
    /**
     * Field name constants
     */
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '预热URL ID')]
    public const schema_fields_WARMUP_URL_ID = 'warmup_url_id';
    #[Col('varchar', 128, nullable: false, comment: '来源模块')]
    public const schema_fields_MODULE = 'module';
    #[Col('varchar', 128, nullable: false, comment: '提供者')]
    public const schema_fields_PROVIDER = 'provider';
    #[Col('varchar', 512, nullable: false, comment: 'URL地址')]
    public const schema_fields_URL = 'url';
    #[Col('int', comment: '站点ID')]
    public const schema_fields_SITE_ID = 'site_id';
    #[Col('int', comment: '域名ID')]
    public const schema_fields_DOMAIN_ID = 'domain_id';
    #[Col('varchar', 20, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', default: 1, comment: '目标次数')]
    public const schema_fields_TARGET_COUNT = 'target_count';
    #[Col('int', default: 0, comment: '已处理次数')]
    public const schema_fields_PROCESSED_COUNT = 'processed_count';
    #[Col('int', default: 0, comment: '成功次数')]
    public const schema_fields_SUCCESS_COUNT = 'success_count';
    #[Col('int', default: 0, comment: '失败次数')]
    public const schema_fields_FAIL_COUNT = 'fail_count';
    #[Col('int', default: 0, comment: '重试次数')]
    public const schema_fields_RETRIES = 'retries';
    #[Col('int', 1, default: 1, comment: '是否启用')]
    public const schema_fields_ENABLED = 'enabled';
    #[Col('int', comment: '最后预热时间')]
    public const schema_fields_LAST_WARMED_AT = 'last_warmed_at';
    #[Col('int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAIL = 'fail';
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
        return self::schema_fields_WARMUP_URL_ID;
    }
/**
     * 检查是否启用
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (int)$this->getData(self::schema_fields_ENABLED) === 1;
    }
    /**
     * 保存前处理
     * 
     * @return self
     */
    public function beforeSave(): self
    {
        $now = time();
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        $result = parent::beforeSave();

        return $result instanceof self ? $result : $this;
    }
}
