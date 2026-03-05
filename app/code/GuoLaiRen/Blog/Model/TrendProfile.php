<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 趋势关键词画像模型
 */

namespace GuoLaiRen\Blog\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: '趋势关键词画像表')]
#[Index(name: 'idx_is_active', columns: ['is_active'], comment: '启用状态索引')]
#[Index(name: 'idx_sort', columns: ['sort'], comment: '排序索引')]
class TrendProfile extends Model
{
    public const schema_table = 'guolairen_blog_trend_profile';
    public const schema_primary_key = 'profile_id';


    public const schema_fields_ID        = 'profile_id';
    public const schema_fields_NAME      = 'name';
    public const schema_fields_KEYWORDS  = 'keywords';
    public const schema_fields_SORT      = 'sort';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 1, comment: '是否启用:0否,1是')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    /**
     * 关键词字符串转数组（逗号分隔）
     */
    public function getKeywordsArray(): array
    {
        $kw = $this->getData(self::schema_fields_KEYWORDS);
        if ($kw === null || $kw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', (string)$kw))));
    }

    /**
     * 设置关键词（数组转逗号分隔）
     */
    public function setKeywordsFromArray(array $keywords): self
    {
        $this->setData(self::schema_fields_KEYWORDS, implode(',', array_map('trim', $keywords)));
        return $this;
    }

}

