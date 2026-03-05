<?php
declare(strict_types=1);
/*
 * GuoLaiRen Blog Module
 * 页面+画像每日发文配额模型（关联 PageBuilder 首页获取语言）
 */
namespace GuoLaiRen\Blog\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '页面趋势发文配额表')]
#[Index(name: 'idx_page_profile', columns: ['page_id', 'profile_id'], comment: '页面+画像索引')]
#[Index(name: 'uk_page_profile', columns: ['page_id', 'profile_id'], type: 'UNIQUE', comment: '页面+画像唯一')]
class TrendSiteQuota extends Model
{
    public const schema_table = 'guolairen_blog_trend_site_quota';
    public const schema_primary_key = 'quota_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '配额ID')]
    public const schema_fields_QUOTA_ID = 'quota_id';
    /** @deprecated 已废弃，使用 page_id 代替 */
    #[Col(type: 'int', nullable: true, comment: '站点ID(废弃)')]
    public const schema_fields_SITE_ID = 'site_id';
    #[Col(type: 'int', nullable: true, comment: '关联 PageBuilder 首页ID')]
    public const schema_fields_PAGE_ID = 'page_id';
    #[Col(type: 'int', nullable: true, comment: '画像ID')]
    public const schema_fields_PROFILE_ID = 'profile_id';
    #[Col(type: 'int', nullable: true, comment: '每日文章数')]
    public const schema_fields_ARTICLES_PER_DAY = 'articles_per_day';
    #[Col(type: 'int', nullable: true, comment: '默认分类ID')]
    public const schema_fields_DEFAULT_CATEGORY_ID = 'default_category_id';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_QUOTA_ID;
    }

    /**
     * 获取关联页面的默认语言（通过 page_builder 查询器，避免跨模块直接调用）
     */
    public function getPageLocale(): string
    {
        $pageId = (int) $this->getData(self::schema_fields_PAGE_ID);
        if ($pageId <= 0) {
            return 'en_US';
        }
        $page = w_query('page_builder', 'getPageById', ['page_id' => $pageId]);
        if ($page === null) {
            return 'en_US';
        }
        return (string) ($page['default_locale'] ?? '') ?: 'en_US';
    }

    /**
     * 获取关联页面的 website_id（用于文章 site_id）
     */
    public function getPageWebsiteId(): int
    {
        $pageId = (int) $this->getData(self::schema_fields_PAGE_ID);
        if ($pageId <= 0) {
            return 0;
        }
        $page = w_query('page_builder', 'getPageById', ['page_id' => $pageId]);
        return $page === null ? 0 : (int) ($page['website_id'] ?? 0);
    }
}
