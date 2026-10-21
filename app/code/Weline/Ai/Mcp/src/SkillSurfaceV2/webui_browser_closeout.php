<?php

declare(strict_types=1);

/**
 * skill-v2 surface: webui_browser_closeout（WebUI 浏览器验收与交付地址）
 *
 * 可执行程序：用例 -> 探活 -> 禁缓存导航 -> 操作员自测/截图 Read -> 交付地址 -> 关 Browser。
 * 政策正文在 hard_constraints（browser_cache_disabled_on_open / browser_operator_fail_closed_release 等）。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'webui_browser_closeout',
    'spec' => 'skill-v2',
    'label' => 'WebUI 浏览器验收与交付地址',
    'description' => '页面/UI 任务收口前必须用宿主可用的真实 Browser 按用例自测；打开前 curl --max-time 5 探活，禁缓存后再导航；卡住 fail-closed unlock+关标签；汇报末尾列「交付地址」，写完立即关闭验收 Browser。默认主链 Host {project_hash}.test.weline.com，禁 *.weline.test。',
    'triggers' => [
        'phtml', '页面', '后台', '前台', '验收', '交付', '完成', 'browser', 'webui',
        '交付地址', '自测', '用例', '截图', 'wls', 'ui', 'test.weline.com', 'weline.test',
        '关闭浏览器', 'close browser', 'webview', '缓存', 'cache', 'ignoreCache',
        '卡住', 'hang', 'fail-closed', 'cleanup-browsers',
    ],
    'authoritative_skill' => 'local-browser-urls',
    'authoritative_doc' => 'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
    'authoritative_docs' => [
        'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
        'app/code/Weline/Ai/doc/AI工程交付流程.md',
        'app/code/Weline/Ai/doc/AI硬规则索引.md',
    ],
    'when' => [
        '改了 .phtml / 布局 / 部件 / Taglib 渲染',
        '改了后台或前台页面输出与交互',
        '用户要求验收/交付/完成汇报且含 Web',
        '排除：纯 Service/CLI 无 UI（WB 记 N/A，交付小节仍写）',
    ],
    'inputs' => [
        '用例：URL + 步骤 + 期望结果',
        '本回合改动的页面与静态资源清单',
        '安装级 w_fpc_pierce secret 与本机 Host',
    ],
    'procedure' => [
        '先写用例（URL/步骤/期望），再动代码。',
        '对字面验收 URL 跑 curl --max-time 5 -I；失败禁开 Browser。',
        '打开前禁 HTTP 缓存，URL 带 w_fpc_pierce=；本回合改静态再加 _wb_nc=。',
        '省略 position 后台导航，按用例点/填/提交；禁 browser_cdp Page.reload。',
        '截图改动面并 Read；断点 768/1024（相关加 375/1440），存 doc/evidence/。',
        '汇报末尾写「交付地址」：http(s) Markdown 主链 + 探活；无 UI 写 N/A 不省节。',
        '写完交付地址立即 unlock+关标签；超时/卡住 fail-closed（e2e:cleanup-browsers）。',
    ],
    'checklist' => [
        '用例在打开 Browser 前已定义',
        '每次打开/导航前已禁缓存并带 w_fpc_pierce=',
        '截图已 Read 且覆盖 768/1024',
        '交付地址小节存在，主链为 http(s) Markdown',
        '主链 Host 为 {project_hash}.test.weline.com',
        '交付后验收标签已全部关闭',
    ],
    'anti_patterns' => [
        '用 curl/单测代替 Browser 用例执行',
        '带默认缓存验收本回合 CSS/JS，得出假 PASS',
        'browser_cdp Page.reload 重载工作台',
        '主链用 *.weline.test 或 command:simpleBrowser 伪协议',
        '测完不关标签，留下空转 webview',
        '未跑用例只能报「代码已改，WebUI 验收未完成」',
        'node -e / chromium.launch 探活，留 headless shell',
    ],
    'evidence' => [
        '用例表 + 截图路径（doc/evidence/ch{N}/）',
        'curl --max-time 5 探活输出',
        '交付地址小节（含 w_fpc_pierce=）+ 关闭标签记录',
    ],
    'norms' => [
        ['id' => 'wb_op_browser_self_test', 'summary' => 'AI 必须用宿主可用的真实 Browser 跑完约定用例；单测/curl 不能替代；不绑定某一 IDE。'],
        ['id' => 'browser_operator_non_preemptive', 'summary' => 'WB-OP 为虚拟操作面、默认非抢占后台：导航省略 position，禁抢 IDE 焦点/鼠标/键盘；后台≠免测；仅用户要求观看才前台。'],
        ['id' => 'browser_cache_disabled_on_open', 'summary' => '每次打开/导航前禁 HTTP 缓存（Network.setCacheDisabled）再 navigate；URL 带 w_fpc_pierce=，本回合改静态再加 _wb_nc= 并抽检正文；禁 browser_cdp Page.reload；被拒则 pierce+cache-bust 并注明降级。'],
        ['id' => 'browser_operator_fail_closed_release', 'summary' => '打开前 curl --max-time 5 探活（禁 10/15/30s）；Browser 超时/无 tab/锁死立即 unlock+关闭全部验收标签（e2e:cleanup-browsers）；导航重试≤2，禁无限等待。'],
        ['id' => 'wb_vis_screenshots', 'summary' => '有视觉面且宿主可截图：多断点截图并 Read，存 doc/evidence/；有原型文档则对照。'],
        ['id' => 'delivery_urls_section', 'summary' => '每次功能完成汇报末尾必须有「交付地址」小节：http(s) Markdown 主链，无 UI 写 N/A 不省节。'],
        ['id' => 'delivery_default_host_test_weline_com', 'summary' => '本机主验收 Host 默认 {project_hash}.test.weline.com；禁主链 *.weline.test；仅无前者才用 127.0.0.1。'],
        ['id' => 'browser_release_after_delivery', 'summary' => '写完「交付地址」立即关闭本回合验收 Browser（unlock + close tabs）；禁留空转 webview；用户明确要求保留除外。'],
        ['id' => 'e2e_playwright_formal_runner_only', 'summary' => 'Playwright 只走 php bin/w e2e:run 或 npx playwright test；禁 node -e / chromium.launch 探活残留 chrome-headless-shell。'],
        ['id' => 'e2e_ai_record_into_module_formal_path', 'summary' => 'AI 录制 E2E 落模块 test/e2e|Test/e2e 的 frontend/backend；优先 data-testid；只走正式 runner。'],
        ['id' => 'full_release_requires_commerce_pathway_e2e', 'summary' => '全量发布前必须 php bin/w e2e:run --suite=commerce-release 全 PASS；未过只能报发布门禁未过。'],
        ['id' => 'automation_test_report_durable_evidence', 'summary' => '自动化验收收口必须回报可回查证据（order_uuid/transaction_no/券/邮箱等），禁只说 N passed。'],
    ],
    'template_surface_rules' => [
        'required' => [
            'Define operator use cases (URL, steps, expected) before claiming Web done',
            'probe with curl --max-time 5 before navigate; on hang unlock+close all tabs',
            'Network.setCacheDisabled then navigate; never browser_cdp Page.reload',
            'End every feature report with http(s) Markdown 交付地址 on {project_hash}.test.weline.com',
        ],
        'forbidden' => [
            'Claiming Web done without host-real Browser operator self-test',
            'Omitting 交付地址 section; *.weline.test as primary acceptance host',
            'Leaving acceptance Browser tabs open after Delivery URLs',
            'Ad-hoc node -e / chromium.launch instead of php bin/w e2e:run',
        ],
    ],
    'verification_commands' => [
        'curl --max-time 5 -I <acceptance_url>',
        'php bin/w e2e:cleanup-browsers',
        'php bin/w e2e:run --suite=commerce-release --project=chromium',
    ],
];
