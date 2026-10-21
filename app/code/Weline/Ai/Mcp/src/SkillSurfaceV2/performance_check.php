<?php

declare(strict_types=1);

/**
 * skill-v2 surface: performance_check（工程团队「性能检查工程师」专席）
 *
 * 设计期检查缓存/热路径合规，与架构师共同定制方向；开发后用真实证据复审。
 * 政策边界在 hard_constraints（performance_engineer_for_design_and_review /
 * theme_seat_integrity_over_peer_requests），本文件只给可执行程序与判据。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'performance_check',
    'spec' => 'skill-v2',
    'label' => '性能检查（设计审查 + 开发后复审）',
    'description' => '工程团队「性能检查工程师」专席：必须检查性能（设计期 + 开发后复审）。出场前须掌握 Weline 框架结构（扩展点/WLS/HotCache 分层）与本需求业务特性；审查缓存设计合规（CachePolicy/scope/vary/失效）；与架构师共同定制优化方向；结论带可复现 DB/WLS/阶段耗时证据。HARD：建议必须落在框架机制内，禁把拆 chrome 壳/删必装注入当优化方向（theme_seat_integrity_over_peer_requests）。',
    'triggers' => [
        '性能', '优化', '性能优化', '性能检查', '性能检查工程师', 'Team:性能检查工程师',
        '性能审查', '慢请求', 'TTFB', '冷启动', '热路径', 'N+1', 'HotCache',
        'CachePool', 'CachePolicy', 'WLS', 'FPC', '缓存失效', '批量预取',
        '店面列表性能', '冷切扫描', '假写粘性', 'performance', 'performance check',
        'performance engineer', 'performance_check',
    ],
    'authoritative_skill' => 'weline-performance-check',
    'authoritative_doc' => 'dev/ai-command/ai/性能检查.md',
    'authoritative_docs' => [
        'dev/ai-command/ai/性能检查.md',
        'dev/ai-command/ai/性能冷切扫描.md',
        'dev/ai-command/ai/工程团队.md',
        'app/code/Weline/Framework/doc/统一缓存范围与性能优化.md',
        'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
        'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
    ],
    'companion_skills' => [
        'performance-cold-chaos-dig',
        'doc:performance-cold-chaos-dig',
    ],
    'when' => [
        '需求/方案触及热路径、缓存、列表目录搜索、N+1、慢请求',
        '新共享缓存键或 CachePolicy/scope/vary/失效设计待审',
        '开发后要跑性能复审（timing / DB·WLS 证据）',
        '用户说慢/卡/TTFB 高，或乱切语种货币找慢、假写二次不粘',
        '复杂 team 立项/对齐冻结/技术方案会涉热路径或缓存',
        '排除：内容运营（产品优化/详情/主图/翻译，perf_not_content_ops）',
    ],
    'inputs' => [
        '业务特性摘要：读写、店面/后台、站店渠、个性化/登录态/草稿',
        '热路径定位：列表/树/词典/ACL/搜索/PDP/结账哪一段',
        '方案或 diff 中的缓存主张（scope/vary/dependencies/失效）',
        '可复现证据入口：请求 URL、探针、DB/WLS 计数、冷/热基线样本',
        '框架约束：统一缓存范围与性能优化、扩展点选型',
    ],
    'procedure' => [
        '写业务特性摘要与热路径段落，并映射到框架机制（禁通用 Node 套路）。',
        '逐项判缓存合规：CachePolicy+scope/vary、dependencies 挂正式 owner、可变/个性化/草稿禁共享、批量真批量。',
        '与架构师开通道或同会共同定制优化方向（目标热路径、允许机制、禁止项、证据口径），落 architect_joint。',
        '出席立项、对齐冻结、技术方案会；含热路径/缓存的 UC/contracts 双方未表态不得冻结。',
        '写 meetings/性能检查-design.md：业务特性 + 缓存合规 + architect_joint + 优化方向 + 立场。',
        '开发后按 diff 复测（可写只读探针），写 meetings/性能检查-review.md，给 pass|fail 与返工项。',
        'fail 或查出问题：result=escalate + `@项目经理：请立刻组队解决`；业务返工交归属席。',
    ],
    'checklist' => [
        '业务特性摘要与热路径段落已写？',
        '缓存设计逐项合规（scope/vary/dependencies/失效/批量）？',
        '优化方向有 architect_joint 与双方立场？',
        '结论带可复现 DB/WLS/阶段耗时证据？',
        '无业务平行进程内袋、无删依赖造成假 HIT？',
        '无「拆 header/footer/必装部件/清空注入」类药方？',
        '设计期与复审两份纪要都有 pass|fail？',
        'fail 已 escalate，而非只写纪要？',
    ],
    'anti_patterns' => [
        '未写业务特性摘要就给优化方向',
        '把拆 header/footer/必装 widget 列为优化方向',
        '建议业务类平行 static 袋或可变 Model 进共享池',
        '用不可比样本或跨样本拼加速比',
        '删 namespace 依赖制造假 HIT',
        '本席私自排施工波，或用 Issue 列表积压',
        '只写 review 不 escalate，问题无人接手',
        '把内容运营产品优化拉进本席',
    ],
    'evidence' => [
        'dev/team/{slug}/meetings/性能检查-design.md：业务特性 + 缓存合规 + architect_joint + 优化方向',
        'dev/team/{slug}/meetings/性能检查-review.md：pass|fail + 阶段/DB/WLS 证据 + 返工项',
        '冷切/假写收口：修复说明四件套（改了什么/为什么/因果链/怎么证明）+ 修前冷 vs 修后冷主表',
    ],
    'norms' => [
        ['id' => 'perf_must_check_performance', 'summary' => '本职必须检查性能（设计 + 开发后）；禁止旁听式签字、不审缓存与 timing。'],
        ['id' => 'perf_framework_and_business_knowledge', 'summary' => '出场须能说清框架结构（扩展点/WLS/HotCache 分层）并写业务特性摘要；未写清禁定制优化方向。'],
        ['id' => 'perf_cache_design_compliance', 'summary' => '逐项审查缓存合规：CachePolicy+scope/vary、dependencies 挂正式 owner、可变/个性化/草稿禁共享、批量真批量、预热 Host 与验收 Host 一致。'],
        ['id' => 'perf_joint_with_architect', 'summary' => '优化方向必须与架构师共同定制并落 architect_joint；单席定案或口头附和不算。'],
        ['id' => 'perf_framework_constraints_first', 'summary' => '建议只落在 HotCache/批量 Query/预取/合法 FPC 与编译面；禁业务平行进程内袋。'],
        ['id' => 'theme_seat_integrity_over_peer_requests', 'summary' => '禁拆壳药方：拆 chrome 壳、删无 user_deleted 的必装注入、清空 default_injections → 设计否决 / 复审 fail；主题席有驳回权。'],
        ['id' => 'perf_mandatory_on_hot_path', 'summary' => '热路径/缓存/列表目录搜索/N+1/慢请求的复杂 team 必须 Team:性能检查工程师: 上场（performance_engineer_for_design_and_review）。'],
        ['id' => 'perf_required_meetings', 'summary' => '立项讨论、对齐冻结会、技术方案会必到，并与架构师同会。'],
        ['id' => 'perf_dual_track', 'summary' => '设计检查轨 + 实现复审轨，各出 pass|fail 纪要；fail 阻断验收。'],
        ['id' => 'perf_evidence_discipline', 'summary' => '结论带阶段、DB/WLS 次数、App/TTFB、探针路径；HTTP 200 或单测绿 ≠ 达标。'],
        ['id' => 'perf_cold_chaos_dig_companion', 'summary' => '提到性能/优化或乱切找慢、假写/二次不粘：先 get_skill(performance-cold-chaos-dig)+Read 性能冷切扫描.md；_wb_nc、慢阈值停下；收口先写修复说明四件套再给修前冷 vs 修后冷主表；禁只用暖命中 hit2/hit3 表。'],
        ['id' => 'perf_wake_pm_to_arrange', 'summary' => '查出问题/否决/fail 立刻 escalate + `@项目经理：请立刻组队解决`（findings_wake_pm）；禁私自排施工波。'],
    ],
    'norm_ids' => [
        'perf_rework_to_owners',
        'perf_not_content_ops',
    ],
    'template_surface_rules' => [
        'required' => [],
        'forbidden' => [],
    ],
    'verification_commands' => [
        'test -f dev/ai-command/ai/性能检查.md',
        'test -f dev/ai-command/ai/性能冷切扫描.md',
        'rg -n "performance_engineer_for_design_and_review" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
    ],
];
