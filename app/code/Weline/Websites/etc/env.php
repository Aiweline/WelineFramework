<?php
return [
    'router' => 'websites',
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