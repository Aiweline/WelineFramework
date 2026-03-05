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
#[Table(comment: '潜在客户表')]
#[Index(name: 'idx_store_id', columns: ['store_id'], comment: '店铺ID索引')]
#[Index(name: 'idx_score', columns: ['score'], comment: '分数索引')]
#[Index(name: 'idx_status', columns: ['status'], comment: '状态索引')]
class LeadCandidate extends Model
{
    public const schema_table = 'weline_auto_lead_agent_lead_candidate';
    public const schema_primary_key = 'candidate_id';
    public const schema_primary_keys = ['candidate_id'];

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '候选客户ID')]
    public const schema_fields_ID = 'candidate_id';
    #[Col('int', 0, nullable: false, comment: '店铺ID')]
    public const schema_fields_STORE_ID = 'store_id';
    #[Col('text', nullable: false, comment: '客户画像数据（JSON格式）')]
    public const schema_fields_PROFILE_DATA = 'profile_data';
    #[Col('decimal', '10,2', nullable: false, default: 0, comment: '匹配分数')]
    public const schema_fields_SCORE = 'score';
    #[Col('varchar', 512, nullable: false, comment: '来源URL')]
    public const schema_fields_SOURCE_URL = 'source_url';
    #[Col('text', comment: '所有搜索过的网址（JSON格式）')]
    public const schema_fields_SOURCE_URLS = 'source_urls';
    #[Col('varchar', 50, nullable: false, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 255, comment: '邮箱地址')]
    public const schema_fields_EMAIL = 'email';
    #[Col('varchar', 50, comment: '手机号码')]
    public const schema_fields_PHONE = 'phone';
    #[Col('text', comment: '社媒账户（JSON格式）')]
    public const schema_fields_SOCIAL_MEDIA_ACCOUNTS = 'social_media_accounts';
    #[Col('text', comment: '匹配的文本段（JSON格式）')]
    public const schema_fields_MATCHED_TEXT_SEGMENTS = 'matched_text_segments';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    public array $_index_sort_keys = ['candidate_id', 'store_id', 'score', 'status'];

    public function _init(): void
    {
    }
}


