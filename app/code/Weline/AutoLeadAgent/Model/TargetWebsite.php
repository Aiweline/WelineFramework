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
/**
 * 搜索目标网站模型
 * 存储目标网站配置，包括搜索语法模板
 */
#[Table(comment: '搜索目标网站表')]
#[Index(name: 'idx_is_active', columns: ['is_active'], comment: '是否启用索引')]
#[Index(name: 'idx_sort_order', columns: ['sort_order'], comment: '排序索引')]
#[Index(name: 'idx_domain', columns: ['domain'], type: 'UNIQUE', comment: '域名唯一索引')]
class TargetWebsite extends Model
{
    public const schema_table = 'weline_auto_lead_agent_target_website';
    public const schema_primary_key = 'target_website_id';
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '目标网站ID')]
    public const schema_fields_ID = 'target_website_id';
    #[Col('varchar', 100, nullable: false, comment: '网站名称')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 255, nullable: false, comment: '域名')]
    public const schema_fields_DOMAIN = 'domain';
    #[Col('text', comment: '搜索语法模板')]
    public const schema_fields_SEARCH_SYNTAX_TEMPLATE = 'search_syntax_template';
    #[Col('smallint', 1, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('int', 0, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('text', comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('varchar', 500, comment: '图标URL')]
    public const schema_fields_ICON_URL = 'icon_url';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = ['target_website_id', 'is_active', 'sort_order'];
    public function _init(): void
    {
        $this->_primary_key = self::schema_fields_ID;
    }
    /**
     * 获取启用的目标网站列表
     * 
     * @return array
     */
    public function getActiveWebsites(): array
    {
        return $this->clear()
            ->where(self::schema_fields_IS_ACTIVE, 1)
            ->order(self::schema_fields_SORT_ORDER, 'ASC')
            ->order(self::schema_fields_NAME, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }
    /**
     * 根据域名获取目标网站
     * 
     * @param string $domain
     * @return self|null
     */
    public function getByDomain(string $domain): ?self
    {
        return $this->clear()
            ->where(self::schema_fields_DOMAIN, $domain)
            ->find()
            ->fetch();
    }
}
