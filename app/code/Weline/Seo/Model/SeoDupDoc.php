<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'SEO 正文近重复指纹')]
#[Index(name: 'idx_dup_doc_website', columns: ['website_id'])]
#[Index(name: 'idx_dup_doc_url_hash', columns: ['website_id', 'url_hash'], type: 'UNIQUE')]
class SeoDupDoc extends Model
{
    public const schema_table = 'weline_seo_dup_doc';
    public const schema_primary_key = 'doc_id';

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '文档ID')]
    public const schema_fields_ID = 'doc_id';
    #[Col('int', 0, nullable: false, default: 0, comment: '站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 64, nullable: false, comment: 'URL哈希')]
    public const schema_fields_URL_HASH = 'url_hash';
    #[Col('text', null, false, comment: '页面URL')]
    public const schema_fields_URL = 'url';
    #[Col('varchar', 50, nullable: false, default: '', comment: '实体类型')]
    public const schema_fields_ENTITY_TYPE = 'entity_type';
    #[Col('varchar', 100, nullable: false, default: '', comment: '模块')]
    public const schema_fields_MODULE = 'module';
    #[Col('varchar', 32, nullable: false, default: '', comment: '语言')]
    public const schema_fields_LOCALE = 'locale';
    #[Col('varchar', 64, nullable: false, default: '', comment: '正文哈希')]
    public const schema_fields_CONTENT_HASH = 'content_hash';
    #[Col('text', null, true, comment: 'MinHash签名')]
    public const schema_fields_MINHASH = 'minhash';
    #[Col('int', 0, nullable: false, default: 0, comment: 'Shingle数量')]
    public const schema_fields_SHINGLE_COUNT = 'shingle_count';
    #[Col('varchar', 32, nullable: false, default: 'ok', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', comment: '抓取时间')]
    public const schema_fields_FETCHED_AT = 'fetched_at';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_OK = 'ok';
    public const STATUS_FETCH_ERROR = 'fetch_error';
    public const STATUS_TOO_SHORT = 'too_short';

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
