<?php

declare(strict_types=1);

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 站点 URL 当前健康状态（本地版 Search Console 索引覆盖）。
 *
 * 每个站点 + URL 一行；观测历史在 UrlHealthObservation。
 */
#[Table(comment: 'SEO URL 健康状态')]
#[Index(name: 'uk_health_website_url', columns: ['website_id', 'url_hash'], type: 'UNIQUE', comment: '站点内 URL 唯一')]
#[Index(name: 'idx_health_issue_state', columns: ['website_id', 'issue_code', 'state'], comment: '按问题与状态筛选')]
#[Index(name: 'idx_health_last_checked', columns: ['website_id', 'last_checked_at'], comment: '续探测排序')]
#[Index(name: 'idx_health_sitemap_url', columns: ['sitemap_url_id'], comment: '关联 sitemap URL')]
class UrlHealth extends Model
{
    public const schema_table = 'weline_seo_url_health';
    public const schema_primary_key = 'health_id';
    public array $_unit_primary_keys = ['health_id'];
    public array $_index_sort_keys = ['health_id', 'website_id', 'issue_code'];

    public const STATE_OK = 'ok';
    public const STATE_OPEN = 'open';
    public const STATE_IGNORED = 'ignored';
    public const STATE_FIXED = 'fixed';
    public const STATE_AUTO_REMOVED = 'auto_removed';
    public const STATE_AUTO_RESTORED = 'auto_restored';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '主键')]
    public const schema_fields_ID = 'health_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '规范化 URL 的 SHA-256')]
    public const schema_fields_URL_HASH = 'url_hash';
    #[Col(type: 'text', nullable: false, comment: 'URL 地址')]
    public const schema_fields_URL = 'url';
    #[Col(type: 'varchar', length: 100, nullable: false, default: '', comment: 'Provider 模块')]
    public const schema_fields_MODULE = 'module';
    #[Col(type: 'varchar', length: 50, nullable: false, default: '', comment: '业务范围')]
    public const schema_fields_SCOPE = 'scope';
    #[Col(type: 'varchar', length: 191, nullable: true, comment: 'Provider URL 唯一键')]
    public const schema_fields_URL_KEY = 'url_key';
    #[Col(type: 'varchar', length: 32, nullable: false, default: '', comment: '语言')]
    public const schema_fields_LOCALE = 'locale';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Sitemap URL ID（可空关联）')]
    public const schema_fields_SITEMAP_URL_ID = 'sitemap_url_id';
    #[Col(type: 'varchar', length: 40, nullable: false, default: 'unknown', comment: '问题类型')]
    public const schema_fields_ISSUE_CODE = 'issue_code';
    #[Col(type: 'smallint', nullable: false, default: 0, comment: 'HTTP 状态码')]
    public const schema_fields_HTTP_STATUS = 'http_status';
    #[Col(type: 'text', nullable: true, comment: '重定向链 JSON')]
    public const schema_fields_REDIRECT_CHAIN = 'redirect_chain';
    #[Col(type: 'text', nullable: true, comment: '最终 URL')]
    public const schema_fields_FINAL_URL = 'final_url';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'unknown', comment: 'robots 状态')]
    public const schema_fields_ROBOTS_STATE = 'robots_state';
    #[Col(type: 'smallint', nullable: false, default: 0, comment: '是否软 404')]
    public const schema_fields_SOFT_404 = 'soft_404';
    #[Col(type: 'int', nullable: false, default: 0, comment: '响应耗时 ms')]
    public const schema_fields_RESPONSE_MS = 'response_ms';
    #[Col(type: 'datetime', nullable: true, comment: '首次发现')]
    public const schema_fields_FIRST_SEEN_AT = 'first_seen_at';
    #[Col(type: 'datetime', nullable: true, comment: '最近检查')]
    public const schema_fields_LAST_CHECKED_AT = 'last_checked_at';
    #[Col(type: 'datetime', nullable: true, comment: '最近正常')]
    public const schema_fields_LAST_OK_AT = 'last_ok_at';
    #[Col(type: 'int', nullable: false, default: 0, comment: '连续失败次数')]
    public const schema_fields_CONSECUTIVE_FAILURES = 'consecutive_failures';
    #[Col(type: 'int', nullable: false, default: 0, comment: '连续正常次数')]
    public const schema_fields_CONSECUTIVE_OK = 'consecutive_ok';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'open', comment: '处理状态')]
    public const schema_fields_STATE = 'state';
    #[Col(type: 'text', nullable: true, comment: '判定证据 JSON')]
    public const schema_fields_EVIDENCE = 'evidence';
    #[Col(type: 'datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function save_before(): void
    {
        parent::save_before();

        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        }
        $this->setData(self::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
    }

    public function getRedirectChain(): array
    {
        $raw = $this->getData(self::schema_fields_REDIRECT_CHAIN);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function setRedirectChain(array $chain): static
    {
        return $this->setData(self::schema_fields_REDIRECT_CHAIN, json_encode($chain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function getEvidence(): array
    {
        $raw = $this->getData(self::schema_fields_EVIDENCE);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function setEvidence(array $evidence): static
    {
        return $this->setData(self::schema_fields_EVIDENCE, json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
