<?php
return [
    'router' => 'websites',

    /**
     * 健康检查 HTTP(S)：默认连本机环回 + CURLOPT_RESOLVE，SNI/Host 仍为域名，避免 hairpin 公网 IP 触发 WLS 自封等。
     * CDN 终结 TLS 时设 local_endpoint_probe => false（否则只验证源站证书）。
     */
    'health_check' => [
        'local_endpoint_probe' => true,
        'local_bind_address' => '127.0.0.1',
    ],

    /**
     * 根域 NS 探测（DomainNsCheck）：与「配置的 DNS 管理账户」比对。
     * - mismatch_alert_enabled：不一致时写 w_log_warning（channel domain_ns_check），默认 true。
     * - mismatch_alert_cooldown_seconds：同一域名告警冷却，默认 3600。
     * - self_heal_enabled：是否允许写入 dns_switch_pending 触发 DnsCdnAutoSwitch，默认 true。
     * - self_heal_domain_whitelist：根域小写列表；留空表示不限制（全部根域可自愈）；非空则仅列出的根域自愈。
     * - self_heal_cooldown_seconds：同一域名自愈冷却，默认 86400。
     */
    'ns_check' => [
        'mismatch_alert_enabled' => true,
        'mismatch_alert_cooldown_seconds' => 3600,
        'self_heal_enabled' => true,
        'self_heal_cooldown_seconds' => 86400,
        'self_heal_domain_whitelist' => [],
    ],

    // 是否禁止未匹配的域名访问（默认不禁止）
    // true: 如果查不到匹配的站点，返回404错误
    // false: 查不到站点也没关系，继续处理（默认）
    'ban_unmatched_domain' => false,

    /**
     * 域名购买默认 WHOIS 联系人（与后台购买弹窗字段一致）。
     * 与每条目的 purchase_contact 合并（条目优先）。用于智能体/API 等无表单场景；留空则仅依赖界面或接口显式传入。
     * 键名示例：first_name, last_name, email, phone, address1, city, state, postal_code, country, organization, privacy
     */
    'domain_purchase_default_contact' => [],
];