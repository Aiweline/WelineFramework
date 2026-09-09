<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'SEO 正文近重复对')]
#[Index(name: 'idx_dup_pair_run', columns: ['run_id'])]
#[Index(name: 'idx_dup_pair_unique', columns: ['run_id', 'doc_a', 'doc_b'], type: 'UNIQUE')]
#[Index(name: 'idx_dup_pair_grade', columns: ['run_id', 'grade'])]
class SeoDupPair extends Model
{
    public const schema_table = 'weline_seo_dup_pair';
    public const schema_primary_key = 'pair_id';

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '配对ID')]
    public const schema_fields_ID = 'pair_id';
    #[Col('int', 0, nullable: false, comment: '跑批ID')]
    public const schema_fields_RUN_ID = 'run_id';
    #[Col('int', 0, nullable: false, comment: '文档A')]
    public const schema_fields_DOC_A = 'doc_a';
    #[Col('int', 0, nullable: false, comment: '文档B')]
    public const schema_fields_DOC_B = 'doc_b';
    #[Col('varchar', 512, nullable: false, default: '', comment: 'URL A')]
    public const schema_fields_URL_A = 'url_a';
    #[Col('varchar', 512, nullable: false, default: '', comment: 'URL B')]
    public const schema_fields_URL_B = 'url_b';
    #[Col('decimal', '12,6', nullable: false, default: '0', comment: 'Jaccard')]
    public const schema_fields_JACCARD = 'jaccard';
    #[Col('varchar', 16, nullable: false, default: 'suspect', comment: '分级')]
    public const schema_fields_GRADE = 'grade';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function save_before(): void
    {
        parent::save_before();
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        }
        $this->setData(self::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
    }
}
