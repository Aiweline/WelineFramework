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

    /**
     * DNS 切换（DnsCdnAutoSwitch → DnsSwitchService）。权威 NS 委派仅由注册商 updateNameservers 写入；以下为传播观测。
     * - 不能加快注册局/全球递归缓存过期；可减少「本机解析器仍旧 NS」误判，并尽早发现已传播。
     * - wait_public_ns_max_seconds 不宜过大，避免 WLS cron 单任务长时间阻塞（默认 3 分钟）。
     * - ns_probe_use_cloudflare_doh：用 1.1.1.1 DoH 与系统 dns_get_record 交叉比对（任一与目标 NS 集合一致即视为公网已跟上）。
     * - cutover_requires_public_authoritative_ns：对 wait_public_ns_provider_codes 内目标（如 cloudflare），仅当公网权威 NS
     *   与 DnsProviderDetector 判定一致时才置 dns_cutover_complete=1；避免「注册商 API 已是 CF NS 但全球仍指向 share-dns」时误放行证书。
     */
    'dns_switch' => [
        'wait_public_ns_enabled' => true,
        'wait_public_ns_provider_codes' => ['cloudflare'],
        'wait_public_ns_max_seconds' => 180,
        'wait_public_ns_interval_seconds' => 15,
        'ns_probe_use_cloudflare_doh' => true,
        'cutover_requires_public_authoritative_ns' => true,
    ],

    /**
     * ACME DNS-01：
     * - **写 TXT 前门闸**：{@see \Weline\Websites\Service\DomainResolveService::validateAcmeDns01HostingViaAdapters}（仅注册商 + DNS 托管适配器 API）。
     * - **写 TXT 后等待**：由 {@see \Weline\Server\Service\SslCertificateService::performDns01Challenge} 按下列键轮询，直到 {@see dns_get_record}(TXT) 命中挑战值（默认可选再查公共 DoH）。
     */
    'acme_dns' => [
        'wait_public_ns_max_seconds' => 0,
        'wait_public_ns_interval_seconds' => 15,
        'ns_probe_use_cloudflare_doh' => null,
        /** 写入验证 TXT 后，最长轮询秒数（本机解析链上是否已能查到 TXT） */
        'txt_poll_max_seconds' => 900,
        'txt_poll_interval_seconds' => 10,
        /** gname / cloudflare 可单独加长（未设置则回退 txt_poll_max_seconds） */
        'txt_poll_max_seconds_gname' => 1200,
        'txt_poll_max_seconds_cloudflare' => 1200,
        /** true：dns_get_record 未命中时再用 Google/Cloudflare DoH 辅助；false：仅 dns_get_record 循环等传播 */
        'txt_visible_use_public_doh' => false,
    ],

    /**
     * 购买落库默认 CDN（非 Cloudflare DNS 时）：未显式选 CDN 账户则绑定此处 registrar 账户 ID。
     * Cloudflare DNS 不写入（由 CF 适配器与 DnsSwitchService verify_cdn 处理）。
     * 未配置或账户不存在时仅打日志，不静默绑定。
     */
    'default_cdn_account_id' => 0,
    /** 可选；留空则用账户的 registrar_code 作为 cdn_provider */
    'default_cdn_provider' => '',

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