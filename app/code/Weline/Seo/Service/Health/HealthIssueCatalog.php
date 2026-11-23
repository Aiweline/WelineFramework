<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Health;

use Weline\Seo\Model\HealthPolicy;

/**
 * URL 健康问题分类字典（对齐 Google Search Console「网页索引编制」）。
 *
 * - issue_code 是稳定机器码，展示名走 i18n。
 * - source=probe 的项本地可测；source=gsc 的项只能由 GSC 导入。
 */
final class HealthIssueCatalog
{
    public const OK = 'ok';
    public const NOT_FOUND = 'not_found';
    public const GONE = 'gone';
    public const FORBIDDEN = 'forbidden';
    public const UNAUTHORIZED = 'unauthorized';
    public const RATE_LIMITED = 'rate_limited';
    public const OTHER_4XX = 'other_4xx';
    public const SOFT_404 = 'soft_404';
    public const SERVER_503 = 'server_503';
    public const SERVER_5XX = 'server_5xx';
    public const REDIRECT_OK = 'redirect_ok';
    public const REDIRECT_EXTERNAL = 'redirect_external';
    public const REDIRECT_BROKEN = 'redirect_broken';
    public const ROBOTS_BLOCKED = 'robots_blocked';
    public const NOINDEX_IN_SITEMAP = 'noindex_in_sitemap';
    public const CANONICAL_MISMATCH = 'canonical_mismatch';
    public const TIMEOUT = 'timeout';
    public const DNS_TLS_ERROR = 'dns_tls_error';
    public const CONNECTION_ERROR = 'connection_error';
    public const EDGE_BLOCKED = 'edge_blocked';
    public const CRAWLED_NOT_INDEXED = 'crawled_not_indexed';
    public const DISCOVERED_NOT_INDEXED = 'discovered_not_indexed';
    public const UNKNOWN_TO_GOOGLE = 'unknown_to_google';
    public const UNKNOWN = 'unknown';

    /**
     * 分类定义。default_* 是默认策略，站点可在规则页覆盖（阶段 2）。
     *
     * @return array<string, array{
     *   label:string, group:string, group_label:string, severity:string, source:string,
     *   description:string, default_auto_mode:string, default_action:string,
     *   default_grace_runs:int, restore_enabled:bool, restore_grace_runs:int
     * }>
     */
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        return self::$cache = [
            self::OK => [
                'label' => __('正常'),
                'group' => 'ok',
                'group_label' => __('正常'),
                'severity' => 'ok',
                'source' => 'probe',
                'description' => __('2xx 且非软 404、未被 robots 屏蔽。'),
                'default_auto_mode' => HealthPolicy::AUTO_OFF,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 1,
                'restore_enabled' => false,
                'restore_grace_runs' => 1,
            ],
            self::NOT_FOUND => [
                'label' => __('404 未找到'),
                'group' => 'http_4xx',
                'group_label' => __('客户端错误 4xx'),
                'severity' => 'high',
                'source' => 'probe',
                'description' => __('页面返回 404，搜索引擎会从索引中移除。'),
                'default_auto_mode' => HealthPolicy::AUTO_AUTO,
                'default_action' => HealthPolicy::ACTION_REMOVE,
                'default_grace_runs' => 2,
                'restore_enabled' => true,
                'restore_grace_runs' => 3,
            ],
            self::GONE => [
                'label' => __('410 已删除'),
                'group' => 'http_4xx',
                'group_label' => __('客户端错误 4xx'),
                'severity' => 'high',
                'source' => 'probe',
                'description' => __('页面明确声明已删除，可立即移出 sitemap。'),
                'default_auto_mode' => HealthPolicy::AUTO_AUTO,
                'default_action' => HealthPolicy::ACTION_REMOVE,
                'default_grace_runs' => 1,
                'restore_enabled' => true,
                'restore_grace_runs' => 3,
            ],
            self::FORBIDDEN => [
                'label' => __('403 禁止访问'),
                'group' => 'http_4xx',
                'group_label' => __('客户端错误 4xx'),
                'severity' => 'high',
                'source' => 'probe',
                'description' => __('可能是权限或 WAF/CDN 拦截，先不要自动移除。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 2,
                'restore_enabled' => false,
                'restore_grace_runs' => 3,
            ],
            self::UNAUTHORIZED => [
                'label' => __('401 需认证'),
                'group' => 'http_4xx',
                'group_label' => __('客户端错误 4xx'),
                'severity' => 'medium',
                'source' => 'probe',
                'description' => __('页面需要登录，通常不应出现在 sitemap 中。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 1,
                'restore_enabled' => false,
                'restore_grace_runs' => 3,
            ],
            self::RATE_LIMITED => [
                'label' => __('429 请求过多'),
                'group' => 'http_4xx',
                'group_label' => __('客户端错误 4xx'),
                'severity' => 'medium',
                'source' => 'probe',
                'description' => __('被限流，通常是临时问题，下一轮重试。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 3,
                'restore_enabled' => false,
                'restore_grace_runs' => 3,
            ],
            self::OTHER_4XX => [
                'label' => __('其他 4xx'),
                'group' => 'http_4xx',
                'group_label' => __('客户端错误 4xx'),
                'severity' => 'medium',
                'source' => 'probe',
                'description' => __('400/405/406 等其他客户端错误。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 2,
                'restore_enabled' => false,
                'restore_grace_runs' => 3,
            ],
            self::SOFT_404 => [
                'label' => __('软 404'),
                'group' => 'content',
                'group_label' => __('内容质量'),
                'severity' => 'medium',
                'source' => 'probe',
                'description' => __('返回 200，但内容命中 404 模板指纹或“页面不存在”特征。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 2,
                'restore_enabled' => false,
                'restore_grace_runs' => 3,
            ],
            self::SERVER_503 => [
                'label' => __('503 服务不可用'),
                'group' => 'http_5xx',
                'group_label' => __('服务端错误 5xx'),
                'severity' => 'high',
                'source' => 'probe',
                'description' => __('多为临时故障，连续多次才提示；默认不自动移除。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 3,
                'restore_enabled' => false,
                'restore_grace_runs' => 3,
            ],
            self::SERVER_5XX => [
                'label' => __('其他 5xx'),
                'group' => 'http_5xx',
                'group_label' => __('服务端错误 5xx'),
                'severity' => 'high',
                'source' => 'probe',
                'description' => __('500/502/504 等服务端错误，先排查服务，默认不移除。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 3,
                'restore_enabled' => false,
                'restore_grace_runs' => 3,
            ],
            self::REDIRECT_OK => [
                'label' => __('同站重定向'),
                'group' => 'redirect',
                'group_label' => __('重定向'),
                'severity' => 'medium',
                'source' => 'probe',
                'description' => __('sitemap 里放的是跳转地址；可替换为重定向最终目标。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_REPLACE,
                'default_grace_runs' => 1,
                'restore_enabled' => false,
                'restore_grace_runs' => 3,
            ],
            self::REDIRECT_EXTERNAL => [
                'label' => __('跨站重定向'),
                'group' => 'redirect',
                'group_label' => __('重定向'),
                'severity' => 'medium',
                'source' => 'probe',
                'description' => __('跳到别的域名，通常不应留在本站 sitemap。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 2,
                'restore_enabled' => false,
                'restore_grace_runs' => 3,
            ],
            self::REDIRECT_BROKEN => [
                'label' => __('重定向断链'),
                'group' => 'redirect',
                'group_label' => __('重定向'),
                'severity' => 'high',
                'source' => 'probe',
                'description' => __('重定向循环、超过 5 跳，或最终落到 4xx/5xx。'),
                'default_auto_mode' => HealthPolicy::AUTO_AUTO,
                'default_action' => HealthPolicy::ACTION_REMOVE,
                'default_grace_runs' => 2,
                'restore_enabled' => true,
                'restore_grace_runs' => 3,
            ],
            self::ROBOTS_BLOCKED => [
                'label' => __('被 robots 屏蔽'),
                'group' => 'access',
                'group_label' => __('抓取权限'),
                'severity' => 'medium',
                'source' => 'probe',
                'description' => __('robots.txt 禁止 Googlebot 抓取，sitemap 里不应再列。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 1,
                'restore_enabled' => false,
                'restore_grace_runs' => 3,
            ],
            self::NOINDEX_IN_SITEMAP => [
                'label' => __('sitemap 中的 noindex'),
                'group' => 'access',
                'group_label' => __('抓取权限'),
                'severity' => 'medium',
                'source' => 'probe',
                'description' => __('页面声明 noindex，却仍在 sitemap 里，信号矛盾。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 1,
                'restore_enabled' => false,
                'restore_grace_runs' => 3,
            ],
            self::CANONICAL_MISMATCH => [
                'label' => __('canonical 指向别处'),
                'group' => 'content',
                'group_label' => __('内容质量'),
                'severity' => 'medium',
                'source' => 'probe',
                'description' => __('页面 canonical 不是自己，sitemap 收录该地址意义不大。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 2,
                'restore_enabled' => false,
                'restore_grace_runs' => 3,
            ],
            self::TIMEOUT => [
                'label' => __('连接超时'),
                'group' => 'network',
                'group_label' => __('网络与边缘'),
                'severity' => 'high',
                'source' => 'probe',
                'description' => __('连接或读取超时，可能是服务慢、被墙或 DNS 问题。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 3,
                'restore_enabled' => false,
                'restore_grace_runs' => 3,
            ],
            self::DNS_TLS_ERROR => [
                'label' => __('DNS/TLS 错误'),
                'group' => 'network',
                'group_label' => __('网络与边缘'),
                'severity' => 'high',
                'source' => 'probe',
                'description' => __('域名解析失败或证书错误。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 2,
                'restore_enabled' => false,
                'restore_grace_runs' => 3,
            ],
            self::CONNECTION_ERROR => [
                'label' => __('连接失败'),
                'group' => 'network',
                'group_label' => __('网络与边缘'),
                'severity' => 'high',
                'source' => 'probe',
                'description' => __('域名可解析但连不上端口，常见于服务未启动或防火墙拦截。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 2,
                'restore_enabled' => false,
                'restore_grace_runs' => 3,
            ],
            self::EDGE_BLOCKED => [
                'label' => __('边缘/WAF 拦截'),
                'group' => 'network',
                'group_label' => __('网络与边缘'),
                'severity' => 'medium',
                'source' => 'probe',
                'description' => __('被 Cloudflare/WAF 挑战页拦截，不代表页面失效。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 2,
                'restore_enabled' => false,
                'restore_grace_runs' => 3,
            ],
            self::CRAWLED_NOT_INDEXED => [
                'label' => __('已抓取未编入索引'),
                'group' => 'gsc',
                'group_label' => __('Search Console'),
                'severity' => 'low',
                'source' => 'gsc',
                'description' => __('Google 已抓取但未收录；本地探测无法判断，只能从 GSC 导入。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 1,
                'restore_enabled' => false,
                'restore_grace_runs' => 1,
            ],
            self::DISCOVERED_NOT_INDEXED => [
                'label' => __('已发现未编入索引'),
                'group' => 'gsc',
                'group_label' => __('Search Console'),
                'severity' => 'low',
                'source' => 'gsc',
                'description' => __('Google 已发现但尚未抓取；只能从 GSC 导入。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 1,
                'restore_enabled' => false,
                'restore_grace_runs' => 1,
            ],
            self::UNKNOWN_TO_GOOGLE => [
                'label' => __('Google 未知网址'),
                'group' => 'gsc',
                'group_label' => __('Search Console'),
                'severity' => 'low',
                'source' => 'gsc',
                'description' => __('GSC 尚未发现该网址；只能从 GSC 导入。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 1,
                'restore_enabled' => false,
                'restore_grace_runs' => 1,
            ],
            self::UNKNOWN => [
                'label' => __('未分类'),
                'group' => 'other',
                'group_label' => __('其他'),
                'severity' => 'low',
                'source' => 'probe',
                'description' => __('未能归类，需看探测详情。'),
                'default_auto_mode' => HealthPolicy::AUTO_NOTIFY,
                'default_action' => HealthPolicy::ACTION_NOTIFY,
                'default_grace_runs' => 1,
                'restore_enabled' => false,
                'restore_grace_runs' => 1,
            ],
        ];
    }

    /** @return list<string> */
    public static function tabOrder(): array
    {
        return [
            self::NOT_FOUND,
            self::FORBIDDEN,
            self::UNAUTHORIZED,
            self::RATE_LIMITED,
            self::OTHER_4XX,
            self::SOFT_404,
            self::SERVER_503,
            self::SERVER_5XX,
            self::REDIRECT_OK,
            self::REDIRECT_EXTERNAL,
            self::REDIRECT_BROKEN,
            self::ROBOTS_BLOCKED,
            self::NOINDEX_IN_SITEMAP,
            self::CANONICAL_MISMATCH,
            self::TIMEOUT,
            self::DNS_TLS_ERROR,
            self::CONNECTION_ERROR,
            self::EDGE_BLOCKED,
            self::CRAWLED_NOT_INDEXED,
            self::DISCOVERED_NOT_INDEXED,
            self::UNKNOWN_TO_GOOGLE,
            self::UNKNOWN,
        ];
    }

    /**
     * 索引健康页的分类 Tab：按用户要求的“404 / 403 / 4xx / 5xx / 软404 / 重定向 / robots”归组。
     *
     * @return list<array{code:string, label:string, codes:list<string>, tone:string}>
     */
    public static function tabGroups(): array
    {
        return [
            [
                'code' => 'all',
                'label' => (string)__('全部'),
                'codes' => [],
                'tone' => 'info',
            ],
            [
                'code' => 'not_found',
                'label' => (string)__('404 / 410'),
                'codes' => [self::NOT_FOUND, self::GONE],
                'tone' => 'danger',
            ],
            [
                'code' => 'forbidden',
                'label' => (string)__('403 / 401'),
                'codes' => [self::FORBIDDEN, self::UNAUTHORIZED],
                'tone' => 'warning',
            ],
            [
                'code' => 'other_4xx',
                'label' => (string)__('4xx 其他'),
                'codes' => [self::RATE_LIMITED, self::OTHER_4XX],
                'tone' => 'warning',
            ],
            [
                'code' => 'server_error',
                'label' => (string)__('5xx'),
                'codes' => [self::SERVER_503, self::SERVER_5XX],
                'tone' => 'danger',
            ],
            [
                'code' => 'soft_404',
                'label' => (string)__('软 404'),
                'codes' => [self::SOFT_404],
                'tone' => 'warning',
            ],
            [
                'code' => 'redirect',
                'label' => (string)__('重定向'),
                'codes' => [self::REDIRECT_OK, self::REDIRECT_EXTERNAL, self::REDIRECT_BROKEN],
                'tone' => 'info',
            ],
            [
                'code' => 'robots',
                'label' => (string)__('robots 屏蔽'),
                'codes' => [self::ROBOTS_BLOCKED],
                'tone' => 'warning',
            ],
            [
                'code' => 'other',
                'label' => (string)__('其他'),
                'codes' => [
                    self::NOINDEX_IN_SITEMAP,
                    self::CANONICAL_MISMATCH,
                    self::TIMEOUT,
                    self::DNS_TLS_ERROR,
                    self::CONNECTION_ERROR,
                    self::EDGE_BLOCKED,
                    self::CRAWLED_NOT_INDEXED,
                    self::DISCOVERED_NOT_INDEXED,
                    self::UNKNOWN_TO_GOOGLE,
                    self::UNKNOWN,
                ],
                'tone' => 'muted',
            ],
        ];
    }

    /** @return list<string> */
    public static function groupCodes(string $groupCode): array
    {
        foreach (self::tabGroups() as $group) {
            if ($group['code'] === $groupCode) {
                return $group['codes'];
            }
        }

        return [];
    }

    public static function isGroupCode(string $code): bool
    {
        foreach (self::tabGroups() as $group) {
            if ($group['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    public static function groupForIssue(string $issueCode): string
    {
        foreach (self::tabGroups() as $group) {
            if ($group['code'] === 'all') {
                continue;
            }
            if (in_array($issueCode, $group['codes'], true)) {
                return $group['code'];
            }
        }

        return 'other';
    }

    /** 本地探测可产生的分类（含 ok）。 @return list<string> */
    public static function probeCodes(): array
    {
        $codes = [self::OK];
        foreach (self::all() as $code => $meta) {
            if ($code !== self::OK && ($meta['source'] ?? '') === 'probe') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** @return list<string> */
    public static function issueCodes(): array
    {
        return array_values(array_filter(
            self::probeCodes(),
            static fn (string $code): bool => $code !== self::OK
        ));
    }

    public static function isKnown(string $code): bool
    {
        return isset(self::all()[$code]);
    }

    public static function isHealthy(string $code): bool
    {
        return $code === self::OK;
    }

    /** @return array<string, mixed> */
    public static function meta(string $code): array
    {
        $all = self::all();

        return $all[$code] ?? $all[self::UNKNOWN];
    }

    public static function label(string $code): string
    {
        return (string)(self::meta($code)['label'] ?? $code);
    }

    public static function groupLabel(string $code): string
    {
        return (string)(self::meta($code)['group_label'] ?? __('其他'));
    }

    public static function severityTone(string $code): string
    {
        return match ((string)(self::meta($code)['severity'] ?? 'low')) {
            'high' => 'danger',
            'medium' => 'warning',
            'low' => 'info',
            default => 'muted',
        };
    }

    /**
     * 默认策略（用于规则页与阶段 2 的决策器）。
     *
     * @return array<string, mixed>
     */
    public static function defaultPolicy(string $code): array
    {
        $meta = self::meta($code);

        return [
            'issue_code' => $code,
            'auto_mode' => (string)($meta['default_auto_mode'] ?? HealthPolicy::AUTO_NOTIFY),
            'action' => (string)($meta['default_action'] ?? HealthPolicy::ACTION_NOTIFY),
            'grace_runs' => (int)($meta['default_grace_runs'] ?? 2),
            'restore_enabled' => (bool)($meta['restore_enabled'] ?? false),
            'restore_grace_runs' => (int)($meta['restore_grace_runs'] ?? 3),
            'notify_channels' => ['backend'],
            'is_enabled' => true,
        ];
    }
}
