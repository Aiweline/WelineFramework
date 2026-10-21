<?php

declare(strict_types=1);

/**
 * skill-v2 surface: production_http_probe（生产公网 HTTP 探活）
 *
 * 禁止 Agent 本机 curl 生产公网 FQDN；SSH 站柜主 Host 后探 127.0.0.1 + Host 头。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'production_http_probe',
    'spec' => 'skill-v2',
    'label' => '生产公网 HTTP 探活（SSH 本机，禁 Agent 本机 curl）',
    'description' => '生产/公网店面连通性与 HTTP 状态探活：禁止从 Cursor/Agent 本机 curl 公网 FQDN（易触发 WLS shared_ban 连带浏览器 Forbidden）。必须先 Read 站柜 README 用主用 SSH Host 登录，SSH 后对 127.0.0.1 带 Host 头探活；shared_ban 用 server:security:unblock 解封。本机 *.test.weline.com 验收 curl 仍允许。',
    'triggers' => [
        '生产探活', '线上探活', '公网探活', 'shared_ban', 'Forbidden',
        '生产 curl', '线上 curl', '本机 curl 生产', 'curl 公网',
        'production probe', 'production http probe', 'production_http_probe',
        'weline-production-http-probe',
    ],
    'authoritative_skill' => 'weline-production-http-probe',
    'authoritative_doc' => 'dev/ai-command/ai/生产HTTP探活.md',
    'authoritative_docs' => [
        'dev/ai-command/ai/生产HTTP探活.md',
        'app/code/Weline/Ai/doc/ai/skills/production-http-probe/SKILL.md',
        'app/code/Weline/Ai/doc/规则/00-MCP调用与会话.md',
        'app/code/Weline/Ai/doc/AI硬规则索引.md',
    ],
    'when' => [
        '生产/公网店面的连通性、HTTP 状态码或抽 HTML',
        '公网站点浏览器 Forbidden / 命中 shared_ban',
        '用户要求 curl/访问生产公网 FQDN',
        '本机 *.test.weline.com / localhost 验收（豁免路径）',
        '排除：未明示线上时按 runtime_status_query_local_first 先查本机',
    ],
    'inputs' => [
        '目标站点与 URL/路径',
        'websites/<site>/README.md 的主用 SSH Host（或等价 SSH MCP profile）',
        '客户端 IP 与是否命中 shared_ban（解封时需要）',
    ],
    'procedure' => [
        'Read websites/<site>/README.md 取主用 SSH Host（或等价 SSH MCP profile）。',
        '禁止本机 curl/wget/httpie 生产或 CDN 前公网 FQDN；改为 SSH 登录目标机。',
        '远端对本机 loopback 探活：curl -sI --max-time 5 -H \'Host: www.example.com\' http://127.0.0.1/path。',
        'HTTPS 按机房约定加 -k 与正确 SNI/--resolve。',
        '命中 shared_ban/纯文字 Forbidden → 目标机 php bin/w server:security:unblock --ip=<客户端IP>。',
        '解封可用 --clear-all（按运维判断）；禁止再以本机公网 curl 试到通。',
    ],
    'checklist' => [
        '探活命令在目标机执行，非 Agent 本机公网？',
        '已 Read 站柜 README 并用主用 SSH Host？',
        '请求指向 127.0.0.1 且带正确 Host 头？',
        'shared_ban 用 server:security:unblock 处理，未反复本机 curl？',
        '仅 *.test.weline.com/localhost 使用本机 curl？',
    ],
    'anti_patterns' => [
        '本机 curl/wget 生产或 CDN 前公网 FQDN',
        'shared_ban 后用更多本机公网 curl「试到通」',
        '未读站柜 README 就猜 SSH Host',
        '远端探活不带 Host 头导致探到错误站点',
        '把浏览器 Forbidden 当站点故障结论（未解封验证）',
        '把本机验收豁免扩展到生产 FQDN',
    ],
    'evidence' => [
        'SSH Host 与远端探活命令/输出',
        'HTTP 状态码/响应头',
        'shared_ban 解封命令与执行结果（如命中）',
    ],
    'norms' => [
        ['id' => 'prod_probe_forbid_agent_local_curl', 'summary' => '禁止 Agent 本机对生产公网 FQDN curl/wget/httpie。'],
        ['id' => 'prod_probe_ssh_localhost', 'summary' => '须 SSH 站柜主 Host 后对本机 127.0.0.1+Host 头探活。'],
        ['id' => 'prod_probe_unblock_cli', 'summary' => 'shared_ban 解封走 server:security:unblock，禁止更多本机公网 curl。'],
        ['id' => 'prod_probe_local_accept_exempt', 'summary' => '本机 *.test.weline.com / localhost 验收 curl 豁免。'],
    ],
    'hard_constraint_ids' => [
        'production_http_probe_forbids_agent_local_curl',
        'runtime_status_query_local_first',
    ],
    'template_surface_rules' => ['required' => [], 'forbidden' => []],
    'verification_commands' => [
        'rg -n "production_http_probe_forbids_agent_local_curl" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
        'php app/code/Weline/Ai/Mcp/tests/hard-constraints-symbol-dsl.php',
    ],
];
