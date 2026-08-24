<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Terraform\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** Terraform 批次域名项 */
#[Table(comment: 'Terraform批次域名项')]
#[Index(name: 'idx_batch_id', columns: ['batch_id'])]
#[Index(name: 'idx_domain', columns: ['domain_name'])]
#[Index(name: 'idx_status', columns: ['status'])]
class BatchItem extends Model
{
    public const schema_table = 'terraform_batch_item';
    public const schema_primary_key = 'item_id';
    public array $_unit_primary_keys = ['item_id'];
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '项ID')]
    public const schema_fields_ITEM_ID = 'item_id';
    #[Col('int', nullable: false, comment: '批次ID')]
    public const schema_fields_BATCH_ID = 'batch_id';
    #[Col('varchar', 255, nullable: false, comment: '域名')]
    public const schema_fields_DOMAIN_NAME = 'domain_name';
    #[Col('int', default: 0, comment: '网站ID')]
    public const schema_fields_SITE_ID = 'site_id';
    #[Col('varchar', 64, nullable: false, comment: '供应商代码')]
    public const schema_fields_PROVIDER = 'provider';
    #[Col('int', nullable: false, comment: '账户ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';
    #[Col('varchar', 128, default: '', comment: 'Zone ID')]
    public const schema_fields_ZONE_ID = 'zone_id';
    #[Col('varchar', 20, default: 'success', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', comment: '结果消息')]
    public const schema_fields_MESSAGE = 'message';
    #[Col('text', comment: 'DNS记录JSON')]
    public const schema_fields_DNS_RECORD = 'dns_record';
    #[Col('int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public function _init(): void
    {
        $this->useMainDbMaster();
    }
    public function getIdFieldName(): string
    {
        return self::schema_fields_ITEM_ID;
    }
public function beforeSave(): self
    {
        $now = time();
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
        return parent::beforeSave();
    }
}
