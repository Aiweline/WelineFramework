<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 正式客户标签表：AI 获客侧维护，避免改动 Customer 模块结构。
 * 每客户每标签一行；tags JSON 同时冗余在行内便于直接取用。
 */
#[Table(comment: 'AI获客客户标签')]
#[Index(name: 'idx_acq_customer_tag_customer', columns: ['customer_id'])]
#[Index(name: 'idx_acq_customer_tag_tag', columns: ['tag'])]
class CustomerTag extends Model
{
 public const schema_table = 'ai_acquisition_customer_tag';
 public const schema_primary_key = 'id';

 #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
 public const schema_fields_ID = 'id';
 #[Col('int', nullable: false, default: 0, comment: '客户ID')]
 public const schema_fields_CUSTOMER_ID = 'customer_id';
 #[Col('varchar', 64, nullable: false, default: '', comment: '标签(group:code)')]
 public const schema_fields_TAG = 'tag';
 #[Col('varchar', 64, nullable: false, default: '', comment: '标签组')]
 public const schema_fields_GROUP = 'tag_group';
 #[Col('varchar', 64, nullable: false, default: '', comment: '标签代码')]
 public const schema_fields_CODE = 'tag_code';
 #[Col('int', nullable: false, default: 0, comment: '来源线索ID')]
 public const schema_fields_LEAD_ID = 'lead_id';
 #[Col('int', nullable: false, default: 0, comment: '创建时间')]
 public const schema_fields_CREATED_AT = 'created_at';

 public function _init(): void
 {
 $this->useMainDbMaster();
 }
}
