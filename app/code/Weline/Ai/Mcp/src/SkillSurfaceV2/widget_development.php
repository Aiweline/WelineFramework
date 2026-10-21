<?php

declare(strict_types=1);

/**
 * skill-v2 surface: widget_development（部件开发工程师席）
 *
 * 可执行程序：定 placement(XOR) -> widget.php 只登记路径 -> 模板元数据 -> 静态资源外置 -> 门禁 -> version/upgrade。
 * 政策正文在 hard_constraints（theme_layout_widget_owner / required_default_always_present_without_user_deleted），此处只留判据与指针。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'widget_development',
    'spec' => 'skill-v2',
    'label' => '部件开发规范',
    'description' => '工程团队部件开发工程师席：同模块才可用布局标签内嵌部件，跨模块只走拥有模块的 JSON default_injections，同一部件禁双路径（会渲两个）；无 user_deleted@{versionId} 则必装永远存在。用于新增/改部件、空槽注入、必装缺失与重复渲染排查。见 theme_layout_widget_owner。',
    'authoritative_doc' => 'app/code/Weline/Theme/doc/部件开发指南.md',
    'triggers' => [
        '部件', '部件开发', '部件开发工程师', 'widget', 'widgets', 'default_injections',
        'placement', 'w:widget', '<w:widget', 'w:slot', 'account-social-login',
        '应用注入', '布局内嵌', '同身份 XOR', 'theme_layout_widget_owner',
        '必装永远存在', 'user_deleted', 'required_default_always_present',
    ],
    'authoritative_docs' => [
        'app/code/Weline/Theme/doc/部件开发指南.md',
        'app/code/Weline/Theme/doc/部件静态资源固化规范.md',
        'app/code/Weline/Theme/doc/widget-slot-attributes.md',
        'app/code/Weline/Theme/doc/开发/spec/required-default-always-present.md',
        'app/code/Weline/Theme/doc/前端JS模块加载规范.md',
        'app/code/Weline/Widget/doc/开发指南.md',
    ],
    'when' => [
        '新增/修改部件、widget.php 或模板元数据',
        '跨模块注入、空 <w:slot> 与 default_injections',
        '店面必装部件缺失或同槽重复渲染（红框）',
        '部件 CSS/JS 内联或静态资源位置不对',
        '排除：Theme Token/布局壳（走主题开发工程师）',
    ],
    'inputs' => [
        '归属模块与部件 code；placement 取 layout 还是 injection',
        '宿主布局/槽位路径与 required 语义',
        '部件依赖的 CSS/JS：Vendor_Module::相对路径',
    ],
    'procedure' => [
        '定 placement：本模块布局内嵌用 layout；跨模块只留空 <w:slot> + 拥有模块 injection。',
        'widget.php 只登记模板路径；@widget/@param/placement 写在模板注释元数据里。',
        'CSS/JS 全部外置：layout-source/source 声明位置，实例值走 data 属性，模板零内联。',
        '确认无 user_deleted@{versionId} 时 required 默认注入被固化进布局产物，不缺不重。',
        '跑 frontend:check-theme-layout-widgets；同身份槽另跑 sibling-fetch 门。',
        '改 weline.modules.js 登记后跑 resource:compile welineModules；升 version 并 setup:upgrade。',
        'Team:部件开发工程师: 双轨（施工+合规复审）收口，记录 placement 与注入清单。',
    ],
    'checklist' => [
        '同一部件只有一条路径（layout XOR injection）？',
        '跨模块部件只经拥有模块 default_injections 进入？',
        '模板无 style=、on*=、<style>、可执行 <script>？',
        'source 位置与同 URL 去重符合固化契约？',
        '两条 frontend:check-* 已跑且通过？',
        '无 user_deleted 时 required 注入仍在？',
        '模块 version 已升并跑过 setup:upgrade？',
    ],
    'anti_patterns' => [
        '布局里用 <w:widget> 拉他模块部件',
        '同模块既布局内嵌又写 default_injections（渲两个）',
        '空槽旁再 sibling fetch 同一 required 部件',
        '用运行时 presence/count 去重补丁掩盖双路径',
        'Theme 布局内嵌非 Weline_Theme 部件',
        '模板写 style= / on*= / 行内 <script>',
        '无 user_deleted 却丢掉 required 默认注入',
    ],
    'evidence' => [
        'placement 与 default_injections 清单（部件→路径→槽）',
        '两条 frontend:check-* 命令输出',
        '固化布局产物路径或改后店面截图（含 required 部件）',
    ],
    'norms' => [
        ['id' => 'same_module_tag_inline_only', 'summary' => '布局/partial 用 <w:widget> 或 fetch 内嵌仅限本模块部件；Theme 仅可内嵌 Weline_Theme 自有部件。'],
        ['id' => 'cross_module_json_injection_only', 'summary' => '跨模块外国部件只能：宿主留空 <w:slot> + 拥有模块在模板 @widget.default_injections 声明注入；注入声明必须落在拥有模块，不由 Theme 代持。'],
        ['id' => 'layout_xor_injection_no_double', 'summary' => '同一部件禁止布局内嵌与 default_injections 并存（会渲出两个）；placement=layout 或 injection 二选一，禁止运行时去重补丁。'],
        ['id' => 'required_default_always_present_without_user_deleted', 'summary' => '无 user_deleted@{versionId} 时，required JSON 默认注入经布局固化写入模板（与主题/版本无关）；遗漏归因固化方案；唯一省略=本版本卸载。'],
        ['id' => 'widget_engineer_dual_track', 'summary' => '部件施工必须 Team:部件开发工程师: 上场并跑施工+合规复审；禁止前端/主题开发工程师席代写外国注入。'],
        ['id' => 'widget_js_weline_modules', 'summary' => '业务模块 JS：weline.modules.js + data-weline-load；改登记后 resource:compile welineModules；禁止内联 CSS/可执行 JS（含 style= 与 on*=）。'],
        ['id' => 'widget_static_assets_bake_to_head', 'summary' => '所有部件 CSS/可执行 JS 禁内联（含 style= 与 on*=）；layout-source 提前 head；source 按 source-postion（优先）或 source-position 分发，默认 head；body/end-body 在 body 结束前，footer 在 footer 前（无 footer 落 body 末尾）；多位置按 head→footer→body。'],
        ['id' => 'widget_gates', 'summary' => '改后跑 frontend:check-theme-layout-widgets 与（适用时）frontend:check-required-injection-sibling-fetch；setup:upgrade 同步致命校验。'],
    ],
    'verification_commands' => [
        'php bin/w frontend:check-theme-layout-widgets',
        'php bin/w frontend:check-required-injection-sibling-fetch',
    ],
    // 兼容别名：本体已并入 checklist / anti_patterns / norms，旧模板规则清单不再重复渲染。
    'template_surface_rules' => [
        'required' => [],
        'forbidden' => [],
    ],
];
