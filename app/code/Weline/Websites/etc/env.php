<?php
return [
    'router' => 'websites',
    // 是否禁止未匹配的域名访问（默认不禁止）
    // true: 如果查不到匹配的站点，返回404错误
    // false: 查不到站点也没关系，继续处理（默认）
    'ban_unmatched_domain' => false,
];