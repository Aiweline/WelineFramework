
(function () {
    'use strict';
    var PB_SSE_CONNECT_DELAY_MS = 5000;

    var isGuidedUi = false;
    var publicId = "f1a364a3baaf768cb8e260b4d224aa2e";
    var stateJsonUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/state-json?public_id=f1a364a3baaf768cb8e260b4d224aa2e";
    var pageTypeLabelMap = {"home_page":"首页","about_page":"关于我们","contact_page":"联系我们","privacy_policy":"隐私政策","terms_of_service":"服务条款","refund_policy":"退款政策","shipping_policy":"配送政策","cookie_policy":"Cookie政策","blog_post":"博客文章","blog_category":"博客分类","blog_list":"博客列表","custom_page":"自定义页面"};
    var currentStageCode = "plan";
    var initialWorkspaceState = {"public_id":"f1a364a3baaf768cb8e260b4d224aa2e","stage":"plan","stage_label":"计划阶段","workspace_status":"preparing","publish_status":"draft","can_publish":false,"workspace_track":"virtual_theme","site_ready":1,"website_id":0,"virtual_theme_id":0,"website_profile":{"site_title":"","site_tagline":"","brief_description":"","target_domain":"","default_locale":"en_US","locales":["en_US"],"logo":"data:image\/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxNjAiIGhlaWdodD0iNDgiIHZpZXdCb3g9IjAgMCAxNjAgNDgiPgogIDxkZWZzPgogICAgPGxpbmVhckdyYWRpZW50IGlkPSJicmFuZExvZ29HcmFkaWVudCIgeDE9IjAlIiB5MT0iMCUiIHgyPSIxMDAlIiB5Mj0iMTAwJSI+CiAgICAgIDxzdG9wIG9mZnNldD0iMCUiIHN0b3AtY29sb3I9IiMwZjE3MmEiLz4KICAgICAgPHN0b3Agb2Zmc2V0PSIxMDAlIiBzdG9wLWNvbG9yPSIjMzM0MTU1Ii8+CiAgICA8L2xpbmVhckdyYWRpZW50PgogIDwvZGVmcz4KICA8cmVjdCB4PSIwIiB5PSIwIiB3aWR0aD0iMTYwIiBoZWlnaHQ9IjQ4IiByeD0iMTQiIGZpbGw9IiNmZmZmZmYiLz4KICA8cmVjdCB4PSI2IiB5PSI2IiB3aWR0aD0iMzYiIGhlaWdodD0iMzYiIHJ4PSIxMiIgZmlsbD0idXJsKCNicmFuZExvZ29HcmFkaWVudCkiLz4KICA8Y2lyY2xlIGN4PSIyNCIgY3k9IjI0IiByPSIxMCIgZmlsbD0icmdiYSgyNTUsMjU1LDI1NSwwLjE4KSIvPgogIDx0ZXh0IHg9IjI0IiB5PSIyNiIgZG9taW5hbnQtYmFzZWxpbmU9Im1pZGRsZSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1mYW1pbHk9IkFyaWFsLCBzYW5zLXNlcmlmIiBmb250LXNpemU9IjE0IiBmb250LXdlaWdodD0iNzAwIiBmaWxsPSIjZmZmZmZmIj5BPC90ZXh0PgogIDx0ZXh0IHg9IjUyIiB5PSIyMCIgZm9udC1mYW1pbHk9IkFyaWFsLCBzYW5zLXNlcmlmIiBmb250LXNpemU9IjEzIiBmb250LXdlaWdodD0iNzAwIiBmaWxsPSIjMGYxNzJhIj48L3RleHQ+CiAgPHRleHQgeD0iNTIiIHk9IjM0IiBmb250LWZhbWlseT0iQXJpYWwsIHNhbnMtc2VyaWYiIGZvbnQtc2l6ZT0iOSIgZmlsbD0iIzY0NzQ4YiI+PC90ZXh0PgogIDxyZWN0IHg9IjUyIiB5PSIyNCIgd2lkdGg9IjE4IiBoZWlnaHQ9IjIiIHJ4PSIxIiBmaWxsPSIjMzhiZGY4Ii8+Cjwvc3ZnPg==","favicon":"data:image\/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI2NCIgaGVpZ2h0PSI2NCIgdmlld0JveD0iMCAwIDY0IDY0Ij4KICA8ZGVmcz4KICAgIDxsaW5lYXJHcmFkaWVudCBpZD0iYnJhbmRJY29uR3JhZGllbnQiIHgxPSIwJSIgeTE9IjAlIiB4Mj0iMTAwJSIgeTI9IjEwMCUiPgogICAgICA8c3RvcCBvZmZzZXQ9IjAlIiBzdG9wLWNvbG9yPSIjMGYxNzJhIi8+CiAgICAgIDxzdG9wIG9mZnNldD0iMTAwJSIgc3RvcC1jb2xvcj0iIzMzNDE1NSIvPgogICAgPC9saW5lYXJHcmFkaWVudD4KICA8L2RlZnM+CiAgPHJlY3QgeD0iNCIgeT0iNCIgd2lkdGg9IjU2IiBoZWlnaHQ9IjU2IiByeD0iMTgiIGZpbGw9InVybCgjYnJhbmRJY29uR3JhZGllbnQpIi8+CiAgPGNpcmNsZSBjeD0iMzIiIGN5PSIzMiIgcj0iMTYiIGZpbGw9InJnYmEoMjU1LDI1NSwyNTUsMC4xNCkiLz4KICA8cGF0aCBkPSJNMTggNDYgTDQ2IDE4IiBzdHJva2U9IiMzOGJkZjgiIHN0cm9rZS13aWR0aD0iMyIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBvcGFjaXR5PSIwLjU1Ii8+CiAgPHRleHQgeD0iMzIiIHk9IjM1IiBkb21pbmFudC1iYXNlbGluZT0ibWlkZGxlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmb250LWZhbWlseT0iQXJpYWwsIHNhbnMtc2VyaWYiIGZvbnQtc2l6ZT0iMjAiIGZvbnQtd2VpZ2h0PSI3MDAiIGZpbGw9IiNmZmZmZmYiPkE8L3RleHQ+Cjwvc3ZnPg==","icon":"data:image\/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI2NCIgaGVpZ2h0PSI2NCIgdmlld0JveD0iMCAwIDY0IDY0Ij4KICA8ZGVmcz4KICAgIDxsaW5lYXJHcmFkaWVudCBpZD0iYnJhbmRJY29uR3JhZGllbnQiIHgxPSIwJSIgeTE9IjAlIiB4Mj0iMTAwJSIgeTI9IjEwMCUiPgogICAgICA8c3RvcCBvZmZzZXQ9IjAlIiBzdG9wLWNvbG9yPSIjMGYxNzJhIi8+CiAgICAgIDxzdG9wIG9mZnNldD0iMTAwJSIgc3RvcC1jb2xvcj0iIzMzNDE1NSIvPgogICAgPC9saW5lYXJHcmFkaWVudD4KICA8L2RlZnM+CiAgPHJlY3QgeD0iNCIgeT0iNCIgd2lkdGg9IjU2IiBoZWlnaHQ9IjU2IiByeD0iMTgiIGZpbGw9InVybCgjYnJhbmRJY29uR3JhZGllbnQpIi8+CiAgPGNpcmNsZSBjeD0iMzIiIGN5PSIzMiIgcj0iMTYiIGZpbGw9InJnYmEoMjU1LDI1NSwyNTUsMC4xNCkiLz4KICA8cGF0aCBkPSJNMTggNDYgTDQ2IDE4IiBzdHJva2U9IiMzOGJkZjgiIHN0cm9rZS13aWR0aD0iMyIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBvcGFjaXR5PSIwLjU1Ii8+CiAgPHRleHQgeD0iMzIiIHk9IjM1IiBkb21pbmFudC1iYXNlbGluZT0ibWlkZGxlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmb250LWZhbWlseT0iQXJpYWwsIHNhbnMtc2VyaWYiIGZvbnQtc2l6ZT0iMjAiIGZvbnQtd2VpZ2h0PSI3MDAiIGZpbGw9IiNmZmZmZmYiPkE8L3RleHQ+Cjwvc3ZnPg==","seo":{"meta_title":"","meta_description":"","meta_keywords":""},"_ai_profile":{"version":4,"signature":"c9a6c800bec1b417b50cfe98df806e52b4b90de6","source_brief":"","managed_fields":{"site_title":true,"site_tagline":true,"brief_description":true,"logo":true,"icon":true}}},"draft_website_id":0,"pagebuilder_pages_by_type":[],"page_type_layouts":{"home_page":{"version":"1.0","page_id":0,"use_original_template":false,"header":{"component":"","config":[]},"content":[],"footer":{"component":"","config":[]}},"about_page":{"version":"1.0","page_id":0,"use_original_template":false,"header":{"component":"","config":[]},"content":[],"footer":{"component":"","config":[]}},"contact_page":{"version":"1.0","page_id":0,"use_original_template":false,"header":{"component":"","config":[]},"content":[],"footer":{"component":"","config":[]}},"privacy_policy":{"version":"1.0","page_id":0,"use_original_template":false,"header":{"component":"","config":[]},"content":[],"footer":{"component":"","config":[]}},"terms_of_service":{"version":"1.0","page_id":0,"use_original_template":false,"header":{"component":"","config":[]},"content":[],"footer":{"component":"","config":[]}},"refund_policy":{"version":"1.0","page_id":0,"use_original_template":false,"header":{"component":"","config":[]},"content":[],"footer":{"component":"","config":[]}},"shipping_policy":{"version":"1.0","page_id":0,"use_original_template":false,"header":{"component":"","config":[]},"content":[],"footer":{"component":"","config":[]}},"cookie_policy":{"version":"1.0","page_id":0,"use_original_template":false,"header":{"component":"","config":[]},"content":[],"footer":{"component":"","config":[]}},"blog_post":{"version":"1.0","page_id":0,"use_original_template":false,"header":{"component":"","config":[]},"content":[],"footer":{"component":"","config":[]}},"blog_category":{"version":"1.0","page_id":0,"use_original_template":false,"header":{"component":"","config":[]},"content":[],"footer":{"component":"","config":[]}},"blog_list":{"version":"1.0","page_id":0,"use_original_template":false,"header":{"component":"","config":[]},"content":[],"footer":{"component":"","config":[]}},"custom_page":{"version":"1.0","page_id":0,"use_original_template":false,"header":{"component":"","config":[]},"content":[],"footer":{"component":"","config":[]}}},"virtual_pages_by_type":{"home_page":{"page_type":"home_page","title":"首页","handle":"","locale":"en_US","style_code":"default","style_settings":[],"meta_title":"","meta_description":"","meta_keywords":"","ai_description":"","virtual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page&visual_editor=1","virtual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page","preview_full_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page","last_generated_at":"","materialized_page_id":0,"section_refinements":[],"blocks":[{"block_id":"home-page-site-header","type":"site_header","html":"<header class=\"ai-block ai-block-site-header\" style=\"padding:18px 28px;border-bottom:1px solid #e5e7eb;background:rgba(255,255,255,0.94);backdrop-filter:blur(12px);display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;\"><div style=\"display:grid;gap:4px;min-width:0;\"><strong style=\"font-size:18px;line-height:1.2;color:#0f172a;\"><\/strong><\/div><div style=\"display:flex;flex-wrap:wrap;gap:16px;align-items:center;\"><a href=\"\/\" style=\"color:#0f172a;font-size:14px;font-weight:700;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">自定义页面<\/a><span style=\"display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">首页<\/span><\/div><\/header>","config":{"site_title":"","site_tagline":"","current_page_label":"首页","nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Header Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"site_tagline":{"type":"text","label":"Site Tagline"},"current_page_label":{"type":"text","label":"Current Page"},"nav_items":{"type":"textarea","label":"Header Links","format":"nav-items"}}}}},{"block_id":"home-page-hero","type":"hero","html":"<section class=\"ai-block ai-block-hero\" style=\"padding:56px 28px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);display:grid;gap:16px;\"><span style=\"font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">首页<\/span><h1 style=\"margin:0;font-size:40px;line-height:1.08;color:#0f172a;\">首页 Hero<\/h1><p style=\"margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;\">首页 的首页先讲清核心价值、主要亮点和下一步动作，让访客在第一屏就知道为什么值得继续浏览。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:10px;\"><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">首页<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">静态占位<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">可继续编辑<\/span><\/div><div><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#0f172a;color:#fff;font-size:14px;font-weight:700;\">继续完善<\/span><\/div><\/section>","config":{"eyebrow":"首页","headline":"首页 Hero","description":"首页 的首页先讲清核心价值、主要亮点和下一步动作，让访客在第一屏就知道为什么值得继续浏览。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["首页","静态占位","可继续编辑"],"primary_cta":"继续完善","secondary_note":"AI 可用后可重新生成该区块"},"field_schema":{"content":{"label":"Content Fields","fields":{"eyebrow":{"type":"text","label":"Eyebrow"},"headline":{"type":"text","label":"Headline"},"description":{"type":"textarea","label":"Description"},"chips":{"type":"textarea","label":"Chips","format":"lines"},"primary_cta":{"type":"text","label":"Primary CTA"},"secondary_note":{"type":"text","label":"Secondary Note"}}}}},{"block_id":"home-page-highlights","type":"cards","html":"<section class=\"ai-block ai-block-cards\" style=\"padding:20px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#0f172a;\">核心卖点<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">把首页第一屏以下最值得点击的理由放出来。<\/p><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;\"><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 01<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">核心卖点<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">把首页第一屏以下最值得点击的理由放出来。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 02<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">当前已回退为静态内容<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 03<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">首页<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。<\/p><\/article><\/div><\/section>","config":{"section_title":"核心卖点","section_intro":"把首页第一屏以下最值得点击的理由放出来。","items":[{"eyebrow":"亮点 01","title":"核心卖点","description":"把首页第一屏以下最值得点击的理由放出来。"},{"eyebrow":"亮点 02","title":"当前已回退为静态内容","description":"AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。"},{"eyebrow":"亮点 03","title":"首页","description":"此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。"}]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"items":{"type":"textarea","label":"Cards","format":"card-items"}}}}},{"block_id":"home-page-details","type":"checklist","html":"<section class=\"ai-block ai-block-checklist\" style=\"padding:8px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:26px;line-height:1.25;color:#0f172a;\">转化路径<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">让用户知道进入站点后应该先看什么、再做什么。<\/p><div style=\"display:grid;gap:12px;\"><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">1<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">让用户知道进入站点后应该先看什么、再做什么。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">2<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">AI 服务暂不可用，已自动切换到静态占位内容。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">3<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">你可以先继续编辑区块，稍后再重新触发 AI 生成。<\/p><\/div><\/div><\/section>","config":{"section_title":"转化路径","section_intro":"让用户知道进入站点后应该先看什么、再做什么。","points":["让用户知道进入站点后应该先看什么、再做什么。","AI 服务暂不可用，已自动切换到静态占位内容。","你可以先继续编辑区块，稍后再重新触发 AI 生成。"]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"points":{"type":"textarea","label":"Checklist Items","format":"lines"}}}}},{"block_id":"home-page-cta","type":"cta","html":"<section class=\"ai-block ai-block-cta\" style=\"padding:16px 28px 56px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);\"><div style=\"padding:28px;border-radius:28px;background:#0f172a;color:#fff;display:grid;gap:14px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#fff;\">开始承接流量<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,.82);\">把 APK 推广、站内信任感和转化动作串成一条明确路径。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:12px;align-items:center;\"><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;\">继续编辑<\/span><\/div><\/div><\/section>","config":{"section_title":"开始承接流量","section_text":"把 APK 推广、站内信任感和转化动作串成一条明确路径。","button_label":"继续编辑","assist_text":""},"field_schema":{"content":{"label":"CTA Fields","fields":{"section_title":{"type":"text","label":"Title"},"section_text":{"type":"textarea","label":"Text"},"button_label":{"type":"text","label":"Button Label"},"assist_text":{"type":"text","label":"Assist Text"}}}}},{"block_id":"home-page-site-footer","type":"site_footer","html":"<footer class=\"ai-block ai-block-site-footer\" style=\"padding:28px;background:#020617;color:#e2e8f0;display:grid;gap:20px;\"><div style=\"display:grid;gap:8px;\"><strong style=\"font-size:18px;line-height:1.2;color:#fff;\"><\/strong><p style=\"margin:0;max-width:760px;font-size:14px;line-height:1.7;color:#94a3b8;\">该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><\/div><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px 24px;\"><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Featured Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Policy Info<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">All Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><\/div><div style=\"display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;color:#64748b;font-size:12px;\"><span>漏 2026 <\/span><span>Always improving the visitor experience<\/span><\/div><\/footer>","config":{"site_title":"","brief_description":"该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","domain":"","links.column1_title":"Featured Pages","links.column1_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"links.column2_title":"Policy Info","links.column2_items":[{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false}],"links.column3_title":"All Pages","links.column3_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Footer Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"brief_description":{"type":"textarea","label":"Footer Summary"},"domain":{"type":"text","label":"Domain"},"links.column1_title":{"type":"text","label":"Group 1 Title"},"links.column1_items":{"type":"textarea","label":"Group 1 Links","format":"nav-items"},"links.column2_title":{"type":"text","label":"Group 2 Title"},"links.column2_items":{"type":"textarea","label":"Group 2 Links","format":"nav-items"},"links.column3_title":{"type":"text","label":"Group 3 Title"},"links.column3_items":{"type":"textarea","label":"Group 3 Links","format":"nav-items"}}}}}],"visual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page&visual_editor=1","visual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page"},"about_page":{"page_type":"about_page","title":"关于我们","handle":"about","locale":"en_US","style_code":"default","style_settings":[],"meta_title":"","meta_description":"","meta_keywords":"","ai_description":"","virtual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=about_page&visual_editor=1","virtual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=about_page","preview_full_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=about_page","last_generated_at":"","materialized_page_id":0,"section_refinements":[],"blocks":[{"block_id":"about-page-site-header","type":"site_header","html":"<header class=\"ai-block ai-block-site-header\" style=\"padding:18px 28px;border-bottom:1px solid #e5e7eb;background:rgba(255,255,255,0.94);backdrop-filter:blur(12px);display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;\"><div style=\"display:grid;gap:4px;min-width:0;\"><strong style=\"font-size:18px;line-height:1.2;color:#0f172a;\"><\/strong><\/div><div style=\"display:flex;flex-wrap:wrap;gap:16px;align-items:center;\"><a href=\"\/\" style=\"color:#0f172a;font-size:14px;font-weight:700;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">自定义页面<\/a><span style=\"display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">关于我们<\/span><\/div><\/header>","config":{"site_title":"","site_tagline":"","current_page_label":"关于我们","nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Header Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"site_tagline":{"type":"text","label":"Site Tagline"},"current_page_label":{"type":"text","label":"Current Page"},"nav_items":{"type":"textarea","label":"Header Links","format":"nav-items"}}}}},{"block_id":"about-page-hero","type":"hero","html":"<section class=\"ai-block ai-block-hero\" style=\"padding:56px 28px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);display:grid;gap:16px;\"><span style=\"font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">关于我们<\/span><h1 style=\"margin:0;font-size:40px;line-height:1.08;color:#0f172a;\">关于我们 Hero<\/h1><p style=\"margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;\">关于我们 的关于页重点呈现品牌定位、团队能力、服务经验和长期投入，帮助访客快速建立信任。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:10px;\"><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">关于我们<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">静态占位<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">可继续编辑<\/span><\/div><div><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#0f172a;color:#fff;font-size:14px;font-weight:700;\">继续完善<\/span><\/div><\/section>","config":{"eyebrow":"关于我们","headline":"关于我们 Hero","description":"关于我们 的关于页重点呈现品牌定位、团队能力、服务经验和长期投入，帮助访客快速建立信任。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["关于我们","静态占位","可继续编辑"],"primary_cta":"继续完善","secondary_note":"AI 可用后可重新生成该区块"},"field_schema":{"content":{"label":"Content Fields","fields":{"eyebrow":{"type":"text","label":"Eyebrow"},"headline":{"type":"text","label":"Headline"},"description":{"type":"textarea","label":"Description"},"chips":{"type":"textarea","label":"Chips","format":"lines"},"primary_cta":{"type":"text","label":"Primary CTA"},"secondary_note":{"type":"text","label":"Secondary Note"}}}}},{"block_id":"about-page-story","type":"cards","html":"<section class=\"ai-block ai-block-cards\" style=\"padding:20px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#0f172a;\">品牌与团队<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">把故事、能力和差异化表达清楚。<\/p><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;\"><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 01<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">品牌与团队<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">把故事、能力和差异化表达清楚。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 02<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">当前已回退为静态内容<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 03<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">关于我们<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。<\/p><\/article><\/div><\/section>","config":{"section_title":"品牌与团队","section_intro":"把故事、能力和差异化表达清楚。","items":[{"eyebrow":"亮点 01","title":"品牌与团队","description":"把故事、能力和差异化表达清楚。"},{"eyebrow":"亮点 02","title":"当前已回退为静态内容","description":"AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。"},{"eyebrow":"亮点 03","title":"关于我们","description":"此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。"}]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"items":{"type":"textarea","label":"Cards","format":"card-items"}}}}},{"block_id":"about-page-values","type":"checklist","html":"<section class=\"ai-block ai-block-checklist\" style=\"padding:8px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:26px;line-height:1.25;color:#0f172a;\">为什么值得信任<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">用更稳妥的方式展示经验、规范和长期投入。<\/p><div style=\"display:grid;gap:12px;\"><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">1<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">用更稳妥的方式展示经验、规范和长期投入。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">2<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">AI 服务暂不可用，已自动切换到静态占位内容。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">3<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">你可以先继续编辑区块，稍后再重新触发 AI 生成。<\/p><\/div><\/div><\/section>","config":{"section_title":"为什么值得信任","section_intro":"用更稳妥的方式展示经验、规范和长期投入。","points":["用更稳妥的方式展示经验、规范和长期投入。","AI 服务暂不可用，已自动切换到静态占位内容。","你可以先继续编辑区块，稍后再重新触发 AI 生成。"]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"points":{"type":"textarea","label":"Checklist Items","format":"lines"}}}}},{"block_id":"about-page-cta","type":"cta","html":"<section class=\"ai-block ai-block-cta\" style=\"padding:16px 28px 56px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);\"><div style=\"padding:28px;border-radius:28px;background:#0f172a;color:#fff;display:grid;gap:14px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#fff;\">继续了解合作方式<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,.82);\">关于页需要承接信任感，并把用户带去下一步动作。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:12px;align-items:center;\"><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;\">继续编辑<\/span><\/div><\/div><\/section>","config":{"section_title":"继续了解合作方式","section_text":"关于页需要承接信任感，并把用户带去下一步动作。","button_label":"继续编辑","assist_text":""},"field_schema":{"content":{"label":"CTA Fields","fields":{"section_title":{"type":"text","label":"Title"},"section_text":{"type":"textarea","label":"Text"},"button_label":{"type":"text","label":"Button Label"},"assist_text":{"type":"text","label":"Assist Text"}}}}},{"block_id":"about-page-site-footer","type":"site_footer","html":"<footer class=\"ai-block ai-block-site-footer\" style=\"padding:28px;background:#020617;color:#e2e8f0;display:grid;gap:20px;\"><div style=\"display:grid;gap:8px;\"><strong style=\"font-size:18px;line-height:1.2;color:#fff;\"><\/strong><p style=\"margin:0;max-width:760px;font-size:14px;line-height:1.7;color:#94a3b8;\">该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><\/div><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px 24px;\"><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Featured Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Policy Info<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">All Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><\/div><div style=\"display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;color:#64748b;font-size:12px;\"><span>漏 2026 <\/span><span>Always improving the visitor experience<\/span><\/div><\/footer>","config":{"site_title":"","brief_description":"该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","domain":"","links.column1_title":"Featured Pages","links.column1_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"links.column2_title":"Policy Info","links.column2_items":[{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false}],"links.column3_title":"All Pages","links.column3_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Footer Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"brief_description":{"type":"textarea","label":"Footer Summary"},"domain":{"type":"text","label":"Domain"},"links.column1_title":{"type":"text","label":"Group 1 Title"},"links.column1_items":{"type":"textarea","label":"Group 1 Links","format":"nav-items"},"links.column2_title":{"type":"text","label":"Group 2 Title"},"links.column2_items":{"type":"textarea","label":"Group 2 Links","format":"nav-items"},"links.column3_title":{"type":"text","label":"Group 3 Title"},"links.column3_items":{"type":"textarea","label":"Group 3 Links","format":"nav-items"}}}}}],"visual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=about_page&visual_editor=1","visual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=about_page"},"contact_page":{"page_type":"contact_page","title":"联系我们","handle":"contact","locale":"en_US","style_code":"default","style_settings":[],"meta_title":"","meta_description":"","meta_keywords":"","ai_description":"","virtual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=contact_page&visual_editor=1","virtual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=contact_page","preview_full_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=contact_page","last_generated_at":"","materialized_page_id":0,"section_refinements":[],"blocks":[{"block_id":"contact-page-site-header","type":"site_header","html":"<header class=\"ai-block ai-block-site-header\" style=\"padding:18px 28px;border-bottom:1px solid #e5e7eb;background:rgba(255,255,255,0.94);backdrop-filter:blur(12px);display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;\"><div style=\"display:grid;gap:4px;min-width:0;\"><strong style=\"font-size:18px;line-height:1.2;color:#0f172a;\"><\/strong><\/div><div style=\"display:flex;flex-wrap:wrap;gap:16px;align-items:center;\"><a href=\"\/\" style=\"color:#0f172a;font-size:14px;font-weight:700;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">自定义页面<\/a><span style=\"display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">联系我们<\/span><\/div><\/header>","config":{"site_title":"","site_tagline":"","current_page_label":"联系我们","nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Header Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"site_tagline":{"type":"text","label":"Site Tagline"},"current_page_label":{"type":"text","label":"Current Page"},"nav_items":{"type":"textarea","label":"Header Links","format":"nav-items"}}}}},{"block_id":"contact-page-hero","type":"hero","html":"<section class=\"ai-block ai-block-hero\" style=\"padding:56px 28px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);display:grid;gap:16px;\"><span style=\"font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">联系我们<\/span><h1 style=\"margin:0;font-size:40px;line-height:1.08;color:#0f172a;\">联系我们 Hero<\/h1><p style=\"margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;\">联系我们 的联系页要把咨询入口、响应预期和合作路径讲清楚，减少用户发起联系前的犹豫。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:10px;\"><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">联系我们<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">静态占位<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">可继续编辑<\/span><\/div><div><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#0f172a;color:#fff;font-size:14px;font-weight:700;\">继续完善<\/span><\/div><\/section>","config":{"eyebrow":"联系我们","headline":"联系我们 Hero","description":"联系我们 的联系页要把咨询入口、响应预期和合作路径讲清楚，减少用户发起联系前的犹豫。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["联系我们","静态占位","可继续编辑"],"primary_cta":"继续完善","secondary_note":"AI 可用后可重新生成该区块"},"field_schema":{"content":{"label":"Content Fields","fields":{"eyebrow":{"type":"text","label":"Eyebrow"},"headline":{"type":"text","label":"Headline"},"description":{"type":"textarea","label":"Description"},"chips":{"type":"textarea","label":"Chips","format":"lines"},"primary_cta":{"type":"text","label":"Primary CTA"},"secondary_note":{"type":"text","label":"Secondary Note"}}}}},{"block_id":"contact-page-channels","type":"cards","html":"<section class=\"ai-block ai-block-cards\" style=\"padding:20px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#0f172a;\">联系渠道<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">把商务、客服和合作咨询的路径拆得足够直接。<\/p><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;\"><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 01<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">联系渠道<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">把商务、客服和合作咨询的路径拆得足够直接。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 02<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">当前已回退为静态内容<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 03<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">联系我们<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。<\/p><\/article><\/div><\/section>","config":{"section_title":"联系渠道","section_intro":"把商务、客服和合作咨询的路径拆得足够直接。","items":[{"eyebrow":"亮点 01","title":"联系渠道","description":"把商务、客服和合作咨询的路径拆得足够直接。"},{"eyebrow":"亮点 02","title":"当前已回退为静态内容","description":"AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。"},{"eyebrow":"亮点 03","title":"联系我们","description":"此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。"}]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"items":{"type":"textarea","label":"Cards","format":"card-items"}}}}},{"block_id":"contact-page-process","type":"checklist","html":"<section class=\"ai-block ai-block-checklist\" style=\"padding:8px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:26px;line-height:1.25;color:#0f172a;\">响应预期<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">让访客知道留言后会发生什么。<\/p><div style=\"display:grid;gap:12px;\"><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">1<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">让访客知道留言后会发生什么。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">2<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">AI 服务暂不可用，已自动切换到静态占位内容。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">3<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">你可以先继续编辑区块，稍后再重新触发 AI 生成。<\/p><\/div><\/div><\/section>","config":{"section_title":"响应预期","section_intro":"让访客知道留言后会发生什么。","points":["让访客知道留言后会发生什么。","AI 服务暂不可用，已自动切换到静态占位内容。","你可以先继续编辑区块，稍后再重新触发 AI 生成。"]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"points":{"type":"textarea","label":"Checklist Items","format":"lines"}}}}},{"block_id":"contact-page-cta","type":"cta","html":"<section class=\"ai-block ai-block-cta\" style=\"padding:16px 28px 56px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);\"><div style=\"padding:28px;border-radius:28px;background:#0f172a;color:#fff;display:grid;gap:14px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#fff;\">现在发起联系<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,.82);\">联系页的目标是缩短决策路径，避免用户找不到入口。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:12px;align-items:center;\"><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;\">继续编辑<\/span><\/div><\/div><\/section>","config":{"section_title":"现在发起联系","section_text":"联系页的目标是缩短决策路径，避免用户找不到入口。","button_label":"继续编辑","assist_text":""},"field_schema":{"content":{"label":"CTA Fields","fields":{"section_title":{"type":"text","label":"Title"},"section_text":{"type":"textarea","label":"Text"},"button_label":{"type":"text","label":"Button Label"},"assist_text":{"type":"text","label":"Assist Text"}}}}},{"block_id":"contact-page-site-footer","type":"site_footer","html":"<footer class=\"ai-block ai-block-site-footer\" style=\"padding:28px;background:#020617;color:#e2e8f0;display:grid;gap:20px;\"><div style=\"display:grid;gap:8px;\"><strong style=\"font-size:18px;line-height:1.2;color:#fff;\"><\/strong><p style=\"margin:0;max-width:760px;font-size:14px;line-height:1.7;color:#94a3b8;\">该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><\/div><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px 24px;\"><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Featured Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Policy Info<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">All Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><\/div><div style=\"display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;color:#64748b;font-size:12px;\"><span>漏 2026 <\/span><span>Always improving the visitor experience<\/span><\/div><\/footer>","config":{"site_title":"","brief_description":"该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","domain":"","links.column1_title":"Featured Pages","links.column1_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"links.column2_title":"Policy Info","links.column2_items":[{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false}],"links.column3_title":"All Pages","links.column3_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Footer Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"brief_description":{"type":"textarea","label":"Footer Summary"},"domain":{"type":"text","label":"Domain"},"links.column1_title":{"type":"text","label":"Group 1 Title"},"links.column1_items":{"type":"textarea","label":"Group 1 Links","format":"nav-items"},"links.column2_title":{"type":"text","label":"Group 2 Title"},"links.column2_items":{"type":"textarea","label":"Group 2 Links","format":"nav-items"},"links.column3_title":{"type":"text","label":"Group 3 Title"},"links.column3_items":{"type":"textarea","label":"Group 3 Links","format":"nav-items"}}}}}],"visual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=contact_page&visual_editor=1","visual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=contact_page"},"privacy_policy":{"page_type":"privacy_policy","title":"隐私政策","handle":"privacy","locale":"en_US","style_code":"default","style_settings":[],"meta_title":"","meta_description":"","meta_keywords":"","ai_description":"","virtual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=privacy_policy&visual_editor=1","virtual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=privacy_policy","preview_full_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=privacy_policy","last_generated_at":"","materialized_page_id":0,"section_refinements":[],"blocks":[{"block_id":"privacy-policy-site-header","type":"site_header","html":"<header class=\"ai-block ai-block-site-header\" style=\"padding:18px 28px;border-bottom:1px solid #e5e7eb;background:rgba(255,255,255,0.94);backdrop-filter:blur(12px);display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;\"><div style=\"display:grid;gap:4px;min-width:0;\"><strong style=\"font-size:18px;line-height:1.2;color:#0f172a;\"><\/strong><\/div><div style=\"display:flex;flex-wrap:wrap;gap:16px;align-items:center;\"><a href=\"\/\" style=\"color:#0f172a;font-size:14px;font-weight:700;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">自定义页面<\/a><span style=\"display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">隐私政策<\/span><\/div><\/header>","config":{"site_title":"","site_tagline":"","current_page_label":"隐私政策","nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Header Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"site_tagline":{"type":"text","label":"Site Tagline"},"current_page_label":{"type":"text","label":"Current Page"},"nav_items":{"type":"textarea","label":"Header Links","format":"nav-items"}}}}},{"block_id":"privacy-policy-hero","type":"hero","html":"<section class=\"ai-block ai-block-hero\" style=\"padding:56px 28px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);display:grid;gap:16px;\"><span style=\"font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">隐私政策<\/span><h1 style=\"margin:0;font-size:40px;line-height:1.08;color:#0f172a;\">隐私政策 Hero<\/h1><p style=\"margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;\">隐私政策 的隐私政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:10px;\"><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">隐私政策<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">静态占位<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">可继续编辑<\/span><\/div><div><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#0f172a;color:#fff;font-size:14px;font-weight:700;\">继续完善<\/span><\/div><\/section>","config":{"eyebrow":"隐私政策","headline":"隐私政策 Hero","description":"隐私政策 的隐私政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["隐私政策","静态占位","可继续编辑"],"primary_cta":"继续完善","secondary_note":"AI 可用后可重新生成该区块"},"field_schema":{"content":{"label":"Content Fields","fields":{"eyebrow":{"type":"text","label":"Eyebrow"},"headline":{"type":"text","label":"Headline"},"description":{"type":"textarea","label":"Description"},"chips":{"type":"textarea","label":"Chips","format":"lines"},"primary_cta":{"type":"text","label":"Primary CTA"},"secondary_note":{"type":"text","label":"Secondary Note"}}}}},{"block_id":"privacy-policy-coverage","type":"cards","html":"<section class=\"ai-block ai-block-cards\" style=\"padding:20px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#0f172a;\">适用范围<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">先把这份政策覆盖哪些数据、流程或责任说清楚。<\/p><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;\"><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 01<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">适用范围<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">先把这份政策覆盖哪些数据、流程或责任说清楚。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 02<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">当前已回退为静态内容<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 03<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">隐私政策<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。<\/p><\/article><\/div><\/section>","config":{"section_title":"适用范围","section_intro":"先把这份政策覆盖哪些数据、流程或责任说清楚。","items":[{"eyebrow":"亮点 01","title":"适用范围","description":"先把这份政策覆盖哪些数据、流程或责任说清楚。"},{"eyebrow":"亮点 02","title":"当前已回退为静态内容","description":"AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。"},{"eyebrow":"亮点 03","title":"隐私政策","description":"此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。"}]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"items":{"type":"textarea","label":"Cards","format":"card-items"}}}}},{"block_id":"privacy-policy-rights","type":"checklist","html":"<section class=\"ai-block ai-block-checklist\" style=\"padding:8px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:26px;line-height:1.25;color:#0f172a;\">用户权利与执行<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">列出用户能做什么，以及站点如何响应。<\/p><div style=\"display:grid;gap:12px;\"><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">1<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">列出用户能做什么，以及站点如何响应。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">2<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">AI 服务暂不可用，已自动切换到静态占位内容。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">3<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">你可以先继续编辑区块，稍后再重新触发 AI 生成。<\/p><\/div><\/div><\/section>","config":{"section_title":"用户权利与执行","section_intro":"列出用户能做什么，以及站点如何响应。","points":["列出用户能做什么，以及站点如何响应。","AI 服务暂不可用，已自动切换到静态占位内容。","你可以先继续编辑区块，稍后再重新触发 AI 生成。"]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"points":{"type":"textarea","label":"Checklist Items","format":"lines"}}}}},{"block_id":"privacy-policy-cta","type":"cta","html":"<section class=\"ai-block ai-block-cta\" style=\"padding:16px 28px 56px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);\"><div style=\"padding:28px;border-radius:28px;background:#0f172a;color:#fff;display:grid;gap:14px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#fff;\">需要补充说明？<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,.82);\">政策页也需要一个清晰的联系与更新说明。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:12px;align-items:center;\"><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;\">继续编辑<\/span><\/div><\/div><\/section>","config":{"section_title":"需要补充说明？","section_text":"政策页也需要一个清晰的联系与更新说明。","button_label":"继续编辑","assist_text":""},"field_schema":{"content":{"label":"CTA Fields","fields":{"section_title":{"type":"text","label":"Title"},"section_text":{"type":"textarea","label":"Text"},"button_label":{"type":"text","label":"Button Label"},"assist_text":{"type":"text","label":"Assist Text"}}}}},{"block_id":"privacy-policy-site-footer","type":"site_footer","html":"<footer class=\"ai-block ai-block-site-footer\" style=\"padding:28px;background:#020617;color:#e2e8f0;display:grid;gap:20px;\"><div style=\"display:grid;gap:8px;\"><strong style=\"font-size:18px;line-height:1.2;color:#fff;\"><\/strong><p style=\"margin:0;max-width:760px;font-size:14px;line-height:1.7;color:#94a3b8;\">该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><\/div><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px 24px;\"><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Featured Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Policy Info<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">All Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><\/div><div style=\"display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;color:#64748b;font-size:12px;\"><span>漏 2026 <\/span><span>Always improving the visitor experience<\/span><\/div><\/footer>","config":{"site_title":"","brief_description":"该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","domain":"","links.column1_title":"Featured Pages","links.column1_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"links.column2_title":"Policy Info","links.column2_items":[{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false}],"links.column3_title":"All Pages","links.column3_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Footer Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"brief_description":{"type":"textarea","label":"Footer Summary"},"domain":{"type":"text","label":"Domain"},"links.column1_title":{"type":"text","label":"Group 1 Title"},"links.column1_items":{"type":"textarea","label":"Group 1 Links","format":"nav-items"},"links.column2_title":{"type":"text","label":"Group 2 Title"},"links.column2_items":{"type":"textarea","label":"Group 2 Links","format":"nav-items"},"links.column3_title":{"type":"text","label":"Group 3 Title"},"links.column3_items":{"type":"textarea","label":"Group 3 Links","format":"nav-items"}}}}}],"visual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=privacy_policy&visual_editor=1","visual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=privacy_policy"},"terms_of_service":{"page_type":"terms_of_service","title":"服务条款","handle":"terms","locale":"en_US","style_code":"default","style_settings":[],"meta_title":"","meta_description":"","meta_keywords":"","ai_description":"","virtual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=terms_of_service&visual_editor=1","virtual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=terms_of_service","preview_full_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=terms_of_service","last_generated_at":"","materialized_page_id":0,"section_refinements":[],"blocks":[{"block_id":"terms-of-service-site-header","type":"site_header","html":"<header class=\"ai-block ai-block-site-header\" style=\"padding:18px 28px;border-bottom:1px solid #e5e7eb;background:rgba(255,255,255,0.94);backdrop-filter:blur(12px);display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;\"><div style=\"display:grid;gap:4px;min-width:0;\"><strong style=\"font-size:18px;line-height:1.2;color:#0f172a;\"><\/strong><\/div><div style=\"display:flex;flex-wrap:wrap;gap:16px;align-items:center;\"><a href=\"\/\" style=\"color:#0f172a;font-size:14px;font-weight:700;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">自定义页面<\/a><span style=\"display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">服务条款<\/span><\/div><\/header>","config":{"site_title":"","site_tagline":"","current_page_label":"服务条款","nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Header Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"site_tagline":{"type":"text","label":"Site Tagline"},"current_page_label":{"type":"text","label":"Current Page"},"nav_items":{"type":"textarea","label":"Header Links","format":"nav-items"}}}}},{"block_id":"terms-of-service-hero","type":"hero","html":"<section class=\"ai-block ai-block-hero\" style=\"padding:56px 28px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);display:grid;gap:16px;\"><span style=\"font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">服务条款<\/span><h1 style=\"margin:0;font-size:40px;line-height:1.08;color:#0f172a;\">服务条款 Hero<\/h1><p style=\"margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;\">服务条款 的服务条款需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:10px;\"><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">服务条款<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">静态占位<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">可继续编辑<\/span><\/div><div><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#0f172a;color:#fff;font-size:14px;font-weight:700;\">继续完善<\/span><\/div><\/section>","config":{"eyebrow":"服务条款","headline":"服务条款 Hero","description":"服务条款 的服务条款需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["服务条款","静态占位","可继续编辑"],"primary_cta":"继续完善","secondary_note":"AI 可用后可重新生成该区块"},"field_schema":{"content":{"label":"Content Fields","fields":{"eyebrow":{"type":"text","label":"Eyebrow"},"headline":{"type":"text","label":"Headline"},"description":{"type":"textarea","label":"Description"},"chips":{"type":"textarea","label":"Chips","format":"lines"},"primary_cta":{"type":"text","label":"Primary CTA"},"secondary_note":{"type":"text","label":"Secondary Note"}}}}},{"block_id":"terms-of-service-coverage","type":"cards","html":"<section class=\"ai-block ai-block-cards\" style=\"padding:20px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#0f172a;\">适用范围<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">先把这份政策覆盖哪些数据、流程或责任说清楚。<\/p><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;\"><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 01<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">适用范围<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">先把这份政策覆盖哪些数据、流程或责任说清楚。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 02<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">当前已回退为静态内容<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 03<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">服务条款<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。<\/p><\/article><\/div><\/section>","config":{"section_title":"适用范围","section_intro":"先把这份政策覆盖哪些数据、流程或责任说清楚。","items":[{"eyebrow":"亮点 01","title":"适用范围","description":"先把这份政策覆盖哪些数据、流程或责任说清楚。"},{"eyebrow":"亮点 02","title":"当前已回退为静态内容","description":"AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。"},{"eyebrow":"亮点 03","title":"服务条款","description":"此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。"}]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"items":{"type":"textarea","label":"Cards","format":"card-items"}}}}},{"block_id":"terms-of-service-rights","type":"checklist","html":"<section class=\"ai-block ai-block-checklist\" style=\"padding:8px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:26px;line-height:1.25;color:#0f172a;\">用户权利与执行<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">列出用户能做什么，以及站点如何响应。<\/p><div style=\"display:grid;gap:12px;\"><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">1<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">列出用户能做什么，以及站点如何响应。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">2<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">AI 服务暂不可用，已自动切换到静态占位内容。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">3<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">你可以先继续编辑区块，稍后再重新触发 AI 生成。<\/p><\/div><\/div><\/section>","config":{"section_title":"用户权利与执行","section_intro":"列出用户能做什么，以及站点如何响应。","points":["列出用户能做什么，以及站点如何响应。","AI 服务暂不可用，已自动切换到静态占位内容。","你可以先继续编辑区块，稍后再重新触发 AI 生成。"]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"points":{"type":"textarea","label":"Checklist Items","format":"lines"}}}}},{"block_id":"terms-of-service-cta","type":"cta","html":"<section class=\"ai-block ai-block-cta\" style=\"padding:16px 28px 56px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);\"><div style=\"padding:28px;border-radius:28px;background:#0f172a;color:#fff;display:grid;gap:14px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#fff;\">需要补充说明？<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,.82);\">政策页也需要一个清晰的联系与更新说明。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:12px;align-items:center;\"><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;\">继续编辑<\/span><\/div><\/div><\/section>","config":{"section_title":"需要补充说明？","section_text":"政策页也需要一个清晰的联系与更新说明。","button_label":"继续编辑","assist_text":""},"field_schema":{"content":{"label":"CTA Fields","fields":{"section_title":{"type":"text","label":"Title"},"section_text":{"type":"textarea","label":"Text"},"button_label":{"type":"text","label":"Button Label"},"assist_text":{"type":"text","label":"Assist Text"}}}}},{"block_id":"terms-of-service-site-footer","type":"site_footer","html":"<footer class=\"ai-block ai-block-site-footer\" style=\"padding:28px;background:#020617;color:#e2e8f0;display:grid;gap:20px;\"><div style=\"display:grid;gap:8px;\"><strong style=\"font-size:18px;line-height:1.2;color:#fff;\"><\/strong><p style=\"margin:0;max-width:760px;font-size:14px;line-height:1.7;color:#94a3b8;\">该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><\/div><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px 24px;\"><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Featured Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Policy Info<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">All Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><\/div><div style=\"display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;color:#64748b;font-size:12px;\"><span>漏 2026 <\/span><span>Always improving the visitor experience<\/span><\/div><\/footer>","config":{"site_title":"","brief_description":"该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","domain":"","links.column1_title":"Featured Pages","links.column1_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"links.column2_title":"Policy Info","links.column2_items":[{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false}],"links.column3_title":"All Pages","links.column3_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Footer Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"brief_description":{"type":"textarea","label":"Footer Summary"},"domain":{"type":"text","label":"Domain"},"links.column1_title":{"type":"text","label":"Group 1 Title"},"links.column1_items":{"type":"textarea","label":"Group 1 Links","format":"nav-items"},"links.column2_title":{"type":"text","label":"Group 2 Title"},"links.column2_items":{"type":"textarea","label":"Group 2 Links","format":"nav-items"},"links.column3_title":{"type":"text","label":"Group 3 Title"},"links.column3_items":{"type":"textarea","label":"Group 3 Links","format":"nav-items"}}}}}],"visual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=terms_of_service&visual_editor=1","visual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=terms_of_service"},"refund_policy":{"page_type":"refund_policy","title":"退款政策","handle":"refund","locale":"en_US","style_code":"default","style_settings":[],"meta_title":"","meta_description":"","meta_keywords":"","ai_description":"","virtual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=refund_policy&visual_editor=1","virtual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=refund_policy","preview_full_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=refund_policy","last_generated_at":"","materialized_page_id":0,"section_refinements":[],"blocks":[{"block_id":"refund-policy-site-header","type":"site_header","html":"<header class=\"ai-block ai-block-site-header\" style=\"padding:18px 28px;border-bottom:1px solid #e5e7eb;background:rgba(255,255,255,0.94);backdrop-filter:blur(12px);display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;\"><div style=\"display:grid;gap:4px;min-width:0;\"><strong style=\"font-size:18px;line-height:1.2;color:#0f172a;\"><\/strong><\/div><div style=\"display:flex;flex-wrap:wrap;gap:16px;align-items:center;\"><a href=\"\/\" style=\"color:#0f172a;font-size:14px;font-weight:700;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">自定义页面<\/a><span style=\"display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">退款政策<\/span><\/div><\/header>","config":{"site_title":"","site_tagline":"","current_page_label":"退款政策","nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Header Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"site_tagline":{"type":"text","label":"Site Tagline"},"current_page_label":{"type":"text","label":"Current Page"},"nav_items":{"type":"textarea","label":"Header Links","format":"nav-items"}}}}},{"block_id":"refund-policy-hero","type":"hero","html":"<section class=\"ai-block ai-block-hero\" style=\"padding:56px 28px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);display:grid;gap:16px;\"><span style=\"font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">退款政策<\/span><h1 style=\"margin:0;font-size:40px;line-height:1.08;color:#0f172a;\">退款政策 Hero<\/h1><p style=\"margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;\">退款政策 的退款政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:10px;\"><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">退款政策<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">静态占位<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">可继续编辑<\/span><\/div><div><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#0f172a;color:#fff;font-size:14px;font-weight:700;\">继续完善<\/span><\/div><\/section>","config":{"eyebrow":"退款政策","headline":"退款政策 Hero","description":"退款政策 的退款政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["退款政策","静态占位","可继续编辑"],"primary_cta":"继续完善","secondary_note":"AI 可用后可重新生成该区块"},"field_schema":{"content":{"label":"Content Fields","fields":{"eyebrow":{"type":"text","label":"Eyebrow"},"headline":{"type":"text","label":"Headline"},"description":{"type":"textarea","label":"Description"},"chips":{"type":"textarea","label":"Chips","format":"lines"},"primary_cta":{"type":"text","label":"Primary CTA"},"secondary_note":{"type":"text","label":"Secondary Note"}}}}},{"block_id":"refund-policy-coverage","type":"cards","html":"<section class=\"ai-block ai-block-cards\" style=\"padding:20px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#0f172a;\">适用范围<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">先把这份政策覆盖哪些数据、流程或责任说清楚。<\/p><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;\"><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 01<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">适用范围<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">先把这份政策覆盖哪些数据、流程或责任说清楚。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 02<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">当前已回退为静态内容<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 03<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">退款政策<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。<\/p><\/article><\/div><\/section>","config":{"section_title":"适用范围","section_intro":"先把这份政策覆盖哪些数据、流程或责任说清楚。","items":[{"eyebrow":"亮点 01","title":"适用范围","description":"先把这份政策覆盖哪些数据、流程或责任说清楚。"},{"eyebrow":"亮点 02","title":"当前已回退为静态内容","description":"AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。"},{"eyebrow":"亮点 03","title":"退款政策","description":"此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。"}]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"items":{"type":"textarea","label":"Cards","format":"card-items"}}}}},{"block_id":"refund-policy-rights","type":"checklist","html":"<section class=\"ai-block ai-block-checklist\" style=\"padding:8px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:26px;line-height:1.25;color:#0f172a;\">用户权利与执行<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">列出用户能做什么，以及站点如何响应。<\/p><div style=\"display:grid;gap:12px;\"><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">1<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">列出用户能做什么，以及站点如何响应。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">2<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">AI 服务暂不可用，已自动切换到静态占位内容。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">3<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">你可以先继续编辑区块，稍后再重新触发 AI 生成。<\/p><\/div><\/div><\/section>","config":{"section_title":"用户权利与执行","section_intro":"列出用户能做什么，以及站点如何响应。","points":["列出用户能做什么，以及站点如何响应。","AI 服务暂不可用，已自动切换到静态占位内容。","你可以先继续编辑区块，稍后再重新触发 AI 生成。"]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"points":{"type":"textarea","label":"Checklist Items","format":"lines"}}}}},{"block_id":"refund-policy-cta","type":"cta","html":"<section class=\"ai-block ai-block-cta\" style=\"padding:16px 28px 56px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);\"><div style=\"padding:28px;border-radius:28px;background:#0f172a;color:#fff;display:grid;gap:14px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#fff;\">需要补充说明？<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,.82);\">政策页也需要一个清晰的联系与更新说明。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:12px;align-items:center;\"><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;\">继续编辑<\/span><\/div><\/div><\/section>","config":{"section_title":"需要补充说明？","section_text":"政策页也需要一个清晰的联系与更新说明。","button_label":"继续编辑","assist_text":""},"field_schema":{"content":{"label":"CTA Fields","fields":{"section_title":{"type":"text","label":"Title"},"section_text":{"type":"textarea","label":"Text"},"button_label":{"type":"text","label":"Button Label"},"assist_text":{"type":"text","label":"Assist Text"}}}}},{"block_id":"refund-policy-site-footer","type":"site_footer","html":"<footer class=\"ai-block ai-block-site-footer\" style=\"padding:28px;background:#020617;color:#e2e8f0;display:grid;gap:20px;\"><div style=\"display:grid;gap:8px;\"><strong style=\"font-size:18px;line-height:1.2;color:#fff;\"><\/strong><p style=\"margin:0;max-width:760px;font-size:14px;line-height:1.7;color:#94a3b8;\">该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><\/div><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px 24px;\"><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Featured Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Policy Info<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">All Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><\/div><div style=\"display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;color:#64748b;font-size:12px;\"><span>漏 2026 <\/span><span>Always improving the visitor experience<\/span><\/div><\/footer>","config":{"site_title":"","brief_description":"该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","domain":"","links.column1_title":"Featured Pages","links.column1_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"links.column2_title":"Policy Info","links.column2_items":[{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false}],"links.column3_title":"All Pages","links.column3_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Footer Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"brief_description":{"type":"textarea","label":"Footer Summary"},"domain":{"type":"text","label":"Domain"},"links.column1_title":{"type":"text","label":"Group 1 Title"},"links.column1_items":{"type":"textarea","label":"Group 1 Links","format":"nav-items"},"links.column2_title":{"type":"text","label":"Group 2 Title"},"links.column2_items":{"type":"textarea","label":"Group 2 Links","format":"nav-items"},"links.column3_title":{"type":"text","label":"Group 3 Title"},"links.column3_items":{"type":"textarea","label":"Group 3 Links","format":"nav-items"}}}}}],"visual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=refund_policy&visual_editor=1","visual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=refund_policy"},"shipping_policy":{"page_type":"shipping_policy","title":"配送政策","handle":"shipping","locale":"en_US","style_code":"default","style_settings":[],"meta_title":"","meta_description":"","meta_keywords":"","ai_description":"","virtual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=shipping_policy&visual_editor=1","virtual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=shipping_policy","preview_full_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=shipping_policy","last_generated_at":"","materialized_page_id":0,"section_refinements":[],"blocks":[{"block_id":"shipping-policy-site-header","type":"site_header","html":"<header class=\"ai-block ai-block-site-header\" style=\"padding:18px 28px;border-bottom:1px solid #e5e7eb;background:rgba(255,255,255,0.94);backdrop-filter:blur(12px);display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;\"><div style=\"display:grid;gap:4px;min-width:0;\"><strong style=\"font-size:18px;line-height:1.2;color:#0f172a;\"><\/strong><\/div><div style=\"display:flex;flex-wrap:wrap;gap:16px;align-items:center;\"><a href=\"\/\" style=\"color:#0f172a;font-size:14px;font-weight:700;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">自定义页面<\/a><span style=\"display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">配送政策<\/span><\/div><\/header>","config":{"site_title":"","site_tagline":"","current_page_label":"配送政策","nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Header Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"site_tagline":{"type":"text","label":"Site Tagline"},"current_page_label":{"type":"text","label":"Current Page"},"nav_items":{"type":"textarea","label":"Header Links","format":"nav-items"}}}}},{"block_id":"shipping-policy-hero","type":"hero","html":"<section class=\"ai-block ai-block-hero\" style=\"padding:56px 28px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);display:grid;gap:16px;\"><span style=\"font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">配送政策<\/span><h1 style=\"margin:0;font-size:40px;line-height:1.08;color:#0f172a;\">配送政策 Hero<\/h1><p style=\"margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;\">配送政策 的配送政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:10px;\"><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">配送政策<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">静态占位<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">可继续编辑<\/span><\/div><div><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#0f172a;color:#fff;font-size:14px;font-weight:700;\">继续完善<\/span><\/div><\/section>","config":{"eyebrow":"配送政策","headline":"配送政策 Hero","description":"配送政策 的配送政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["配送政策","静态占位","可继续编辑"],"primary_cta":"继续完善","secondary_note":"AI 可用后可重新生成该区块"},"field_schema":{"content":{"label":"Content Fields","fields":{"eyebrow":{"type":"text","label":"Eyebrow"},"headline":{"type":"text","label":"Headline"},"description":{"type":"textarea","label":"Description"},"chips":{"type":"textarea","label":"Chips","format":"lines"},"primary_cta":{"type":"text","label":"Primary CTA"},"secondary_note":{"type":"text","label":"Secondary Note"}}}}},{"block_id":"shipping-policy-coverage","type":"cards","html":"<section class=\"ai-block ai-block-cards\" style=\"padding:20px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#0f172a;\">适用范围<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">先把这份政策覆盖哪些数据、流程或责任说清楚。<\/p><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;\"><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 01<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">适用范围<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">先把这份政策覆盖哪些数据、流程或责任说清楚。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 02<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">当前已回退为静态内容<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 03<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">配送政策<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。<\/p><\/article><\/div><\/section>","config":{"section_title":"适用范围","section_intro":"先把这份政策覆盖哪些数据、流程或责任说清楚。","items":[{"eyebrow":"亮点 01","title":"适用范围","description":"先把这份政策覆盖哪些数据、流程或责任说清楚。"},{"eyebrow":"亮点 02","title":"当前已回退为静态内容","description":"AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。"},{"eyebrow":"亮点 03","title":"配送政策","description":"此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。"}]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"items":{"type":"textarea","label":"Cards","format":"card-items"}}}}},{"block_id":"shipping-policy-rights","type":"checklist","html":"<section class=\"ai-block ai-block-checklist\" style=\"padding:8px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:26px;line-height:1.25;color:#0f172a;\">用户权利与执行<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">列出用户能做什么，以及站点如何响应。<\/p><div style=\"display:grid;gap:12px;\"><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">1<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">列出用户能做什么，以及站点如何响应。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">2<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">AI 服务暂不可用，已自动切换到静态占位内容。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">3<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">你可以先继续编辑区块，稍后再重新触发 AI 生成。<\/p><\/div><\/div><\/section>","config":{"section_title":"用户权利与执行","section_intro":"列出用户能做什么，以及站点如何响应。","points":["列出用户能做什么，以及站点如何响应。","AI 服务暂不可用，已自动切换到静态占位内容。","你可以先继续编辑区块，稍后再重新触发 AI 生成。"]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"points":{"type":"textarea","label":"Checklist Items","format":"lines"}}}}},{"block_id":"shipping-policy-cta","type":"cta","html":"<section class=\"ai-block ai-block-cta\" style=\"padding:16px 28px 56px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);\"><div style=\"padding:28px;border-radius:28px;background:#0f172a;color:#fff;display:grid;gap:14px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#fff;\">需要补充说明？<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,.82);\">政策页也需要一个清晰的联系与更新说明。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:12px;align-items:center;\"><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;\">继续编辑<\/span><\/div><\/div><\/section>","config":{"section_title":"需要补充说明？","section_text":"政策页也需要一个清晰的联系与更新说明。","button_label":"继续编辑","assist_text":""},"field_schema":{"content":{"label":"CTA Fields","fields":{"section_title":{"type":"text","label":"Title"},"section_text":{"type":"textarea","label":"Text"},"button_label":{"type":"text","label":"Button Label"},"assist_text":{"type":"text","label":"Assist Text"}}}}},{"block_id":"shipping-policy-site-footer","type":"site_footer","html":"<footer class=\"ai-block ai-block-site-footer\" style=\"padding:28px;background:#020617;color:#e2e8f0;display:grid;gap:20px;\"><div style=\"display:grid;gap:8px;\"><strong style=\"font-size:18px;line-height:1.2;color:#fff;\"><\/strong><p style=\"margin:0;max-width:760px;font-size:14px;line-height:1.7;color:#94a3b8;\">该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><\/div><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px 24px;\"><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Featured Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Policy Info<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">All Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><\/div><div style=\"display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;color:#64748b;font-size:12px;\"><span>漏 2026 <\/span><span>Always improving the visitor experience<\/span><\/div><\/footer>","config":{"site_title":"","brief_description":"该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","domain":"","links.column1_title":"Featured Pages","links.column1_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"links.column2_title":"Policy Info","links.column2_items":[{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false}],"links.column3_title":"All Pages","links.column3_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Footer Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"brief_description":{"type":"textarea","label":"Footer Summary"},"domain":{"type":"text","label":"Domain"},"links.column1_title":{"type":"text","label":"Group 1 Title"},"links.column1_items":{"type":"textarea","label":"Group 1 Links","format":"nav-items"},"links.column2_title":{"type":"text","label":"Group 2 Title"},"links.column2_items":{"type":"textarea","label":"Group 2 Links","format":"nav-items"},"links.column3_title":{"type":"text","label":"Group 3 Title"},"links.column3_items":{"type":"textarea","label":"Group 3 Links","format":"nav-items"}}}}}],"visual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=shipping_policy&visual_editor=1","visual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=shipping_policy"},"cookie_policy":{"page_type":"cookie_policy","title":"Cookie政策","handle":"cookies","locale":"en_US","style_code":"default","style_settings":[],"meta_title":"","meta_description":"","meta_keywords":"","ai_description":"","virtual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=cookie_policy&visual_editor=1","virtual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=cookie_policy","preview_full_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=cookie_policy","last_generated_at":"","materialized_page_id":0,"section_refinements":[],"blocks":[{"block_id":"cookie-policy-site-header","type":"site_header","html":"<header class=\"ai-block ai-block-site-header\" style=\"padding:18px 28px;border-bottom:1px solid #e5e7eb;background:rgba(255,255,255,0.94);backdrop-filter:blur(12px);display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;\"><div style=\"display:grid;gap:4px;min-width:0;\"><strong style=\"font-size:18px;line-height:1.2;color:#0f172a;\"><\/strong><\/div><div style=\"display:flex;flex-wrap:wrap;gap:16px;align-items:center;\"><a href=\"\/\" style=\"color:#0f172a;font-size:14px;font-weight:700;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">自定义页面<\/a><span style=\"display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">Cookie政策<\/span><\/div><\/header>","config":{"site_title":"","site_tagline":"","current_page_label":"Cookie政策","nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Header Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"site_tagline":{"type":"text","label":"Site Tagline"},"current_page_label":{"type":"text","label":"Current Page"},"nav_items":{"type":"textarea","label":"Header Links","format":"nav-items"}}}}},{"block_id":"cookie-policy-hero","type":"hero","html":"<section class=\"ai-block ai-block-hero\" style=\"padding:56px 28px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);display:grid;gap:16px;\"><span style=\"font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">Cookie政策<\/span><h1 style=\"margin:0;font-size:40px;line-height:1.08;color:#0f172a;\">Cookie政策 Hero<\/h1><p style=\"margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;\">Cookie政策 的Cookie政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:10px;\"><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">Cookie政策<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">静态占位<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">可继续编辑<\/span><\/div><div><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#0f172a;color:#fff;font-size:14px;font-weight:700;\">继续完善<\/span><\/div><\/section>","config":{"eyebrow":"Cookie政策","headline":"Cookie政策 Hero","description":"Cookie政策 的Cookie政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["Cookie政策","静态占位","可继续编辑"],"primary_cta":"继续完善","secondary_note":"AI 可用后可重新生成该区块"},"field_schema":{"content":{"label":"Content Fields","fields":{"eyebrow":{"type":"text","label":"Eyebrow"},"headline":{"type":"text","label":"Headline"},"description":{"type":"textarea","label":"Description"},"chips":{"type":"textarea","label":"Chips","format":"lines"},"primary_cta":{"type":"text","label":"Primary CTA"},"secondary_note":{"type":"text","label":"Secondary Note"}}}}},{"block_id":"cookie-policy-coverage","type":"cards","html":"<section class=\"ai-block ai-block-cards\" style=\"padding:20px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#0f172a;\">适用范围<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">先把这份政策覆盖哪些数据、流程或责任说清楚。<\/p><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;\"><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 01<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">适用范围<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">先把这份政策覆盖哪些数据、流程或责任说清楚。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 02<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">当前已回退为静态内容<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 03<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">Cookie政策<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。<\/p><\/article><\/div><\/section>","config":{"section_title":"适用范围","section_intro":"先把这份政策覆盖哪些数据、流程或责任说清楚。","items":[{"eyebrow":"亮点 01","title":"适用范围","description":"先把这份政策覆盖哪些数据、流程或责任说清楚。"},{"eyebrow":"亮点 02","title":"当前已回退为静态内容","description":"AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。"},{"eyebrow":"亮点 03","title":"Cookie政策","description":"此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。"}]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"items":{"type":"textarea","label":"Cards","format":"card-items"}}}}},{"block_id":"cookie-policy-rights","type":"checklist","html":"<section class=\"ai-block ai-block-checklist\" style=\"padding:8px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:26px;line-height:1.25;color:#0f172a;\">用户权利与执行<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">列出用户能做什么，以及站点如何响应。<\/p><div style=\"display:grid;gap:12px;\"><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">1<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">列出用户能做什么，以及站点如何响应。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">2<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">AI 服务暂不可用，已自动切换到静态占位内容。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">3<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">你可以先继续编辑区块，稍后再重新触发 AI 生成。<\/p><\/div><\/div><\/section>","config":{"section_title":"用户权利与执行","section_intro":"列出用户能做什么，以及站点如何响应。","points":["列出用户能做什么，以及站点如何响应。","AI 服务暂不可用，已自动切换到静态占位内容。","你可以先继续编辑区块，稍后再重新触发 AI 生成。"]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"points":{"type":"textarea","label":"Checklist Items","format":"lines"}}}}},{"block_id":"cookie-policy-cta","type":"cta","html":"<section class=\"ai-block ai-block-cta\" style=\"padding:16px 28px 56px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);\"><div style=\"padding:28px;border-radius:28px;background:#0f172a;color:#fff;display:grid;gap:14px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#fff;\">需要补充说明？<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,.82);\">政策页也需要一个清晰的联系与更新说明。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:12px;align-items:center;\"><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;\">继续编辑<\/span><\/div><\/div><\/section>","config":{"section_title":"需要补充说明？","section_text":"政策页也需要一个清晰的联系与更新说明。","button_label":"继续编辑","assist_text":""},"field_schema":{"content":{"label":"CTA Fields","fields":{"section_title":{"type":"text","label":"Title"},"section_text":{"type":"textarea","label":"Text"},"button_label":{"type":"text","label":"Button Label"},"assist_text":{"type":"text","label":"Assist Text"}}}}},{"block_id":"cookie-policy-site-footer","type":"site_footer","html":"<footer class=\"ai-block ai-block-site-footer\" style=\"padding:28px;background:#020617;color:#e2e8f0;display:grid;gap:20px;\"><div style=\"display:grid;gap:8px;\"><strong style=\"font-size:18px;line-height:1.2;color:#fff;\"><\/strong><p style=\"margin:0;max-width:760px;font-size:14px;line-height:1.7;color:#94a3b8;\">该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><\/div><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px 24px;\"><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Featured Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Policy Info<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">All Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><\/div><div style=\"display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;color:#64748b;font-size:12px;\"><span>漏 2026 <\/span><span>Always improving the visitor experience<\/span><\/div><\/footer>","config":{"site_title":"","brief_description":"该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","domain":"","links.column1_title":"Featured Pages","links.column1_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"links.column2_title":"Policy Info","links.column2_items":[{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false}],"links.column3_title":"All Pages","links.column3_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Footer Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"brief_description":{"type":"textarea","label":"Footer Summary"},"domain":{"type":"text","label":"Domain"},"links.column1_title":{"type":"text","label":"Group 1 Title"},"links.column1_items":{"type":"textarea","label":"Group 1 Links","format":"nav-items"},"links.column2_title":{"type":"text","label":"Group 2 Title"},"links.column2_items":{"type":"textarea","label":"Group 2 Links","format":"nav-items"},"links.column3_title":{"type":"text","label":"Group 3 Title"},"links.column3_items":{"type":"textarea","label":"Group 3 Links","format":"nav-items"}}}}}],"visual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=cookie_policy&visual_editor=1","visual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=cookie_policy"},"blog_post":{"page_type":"blog_post","title":"博客文章","handle":"post","locale":"en_US","style_code":"default","style_settings":[],"meta_title":"","meta_description":"","meta_keywords":"","ai_description":"","virtual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=blog_post&visual_editor=1","virtual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=blog_post","preview_full_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=blog_post","last_generated_at":"","materialized_page_id":0,"section_refinements":[],"blocks":[{"block_id":"blog-post-site-header","type":"site_header","html":"<header class=\"ai-block ai-block-site-header\" style=\"padding:18px 28px;border-bottom:1px solid #e5e7eb;background:rgba(255,255,255,0.94);backdrop-filter:blur(12px);display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;\"><div style=\"display:grid;gap:4px;min-width:0;\"><strong style=\"font-size:18px;line-height:1.2;color:#0f172a;\"><\/strong><\/div><div style=\"display:flex;flex-wrap:wrap;gap:16px;align-items:center;\"><a href=\"\/\" style=\"color:#0f172a;font-size:14px;font-weight:700;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">自定义页面<\/a><span style=\"display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">博客文章<\/span><\/div><\/header>","config":{"site_title":"","site_tagline":"","current_page_label":"博客文章","nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Header Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"site_tagline":{"type":"text","label":"Site Tagline"},"current_page_label":{"type":"text","label":"Current Page"},"nav_items":{"type":"textarea","label":"Header Links","format":"nav-items"}}}}},{"block_id":"blog-post-hero","type":"hero","html":"<section class=\"ai-block ai-block-hero\" style=\"padding:56px 28px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);display:grid;gap:16px;\"><span style=\"font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">博客文章<\/span><h1 style=\"margin:0;font-size:40px;line-height:1.08;color:#0f172a;\">博客文章 Hero<\/h1><p style=\"margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;\">博客文章 的内容页要帮助访客理解栏目方向、文章重点和继续阅读路径，用内容承接信任与复访。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:10px;\"><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">博客文章<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">静态占位<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">可继续编辑<\/span><\/div><div><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#0f172a;color:#fff;font-size:14px;font-weight:700;\">继续完善<\/span><\/div><\/section>","config":{"eyebrow":"博客文章","headline":"博客文章 Hero","description":"博客文章 的内容页要帮助访客理解栏目方向、文章重点和继续阅读路径，用内容承接信任与复访。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["博客文章","静态占位","可继续编辑"],"primary_cta":"继续完善","secondary_note":"AI 可用后可重新生成该区块"},"field_schema":{"content":{"label":"Content Fields","fields":{"eyebrow":{"type":"text","label":"Eyebrow"},"headline":{"type":"text","label":"Headline"},"description":{"type":"textarea","label":"Description"},"chips":{"type":"textarea","label":"Chips","format":"lines"},"primary_cta":{"type":"text","label":"Primary CTA"},"secondary_note":{"type":"text","label":"Secondary Note"}}}}},{"block_id":"blog-post-topics","type":"cards","html":"<section class=\"ai-block ai-block-cards\" style=\"padding:20px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#0f172a;\">内容主题<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">把博客页的栏目、主题或文章结构安排清楚。<\/p><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;\"><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 01<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">内容主题<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">把博客页的栏目、主题或文章结构安排清楚。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 02<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">当前已回退为静态内容<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 03<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">博客文章<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。<\/p><\/article><\/div><\/section>","config":{"section_title":"内容主题","section_intro":"把博客页的栏目、主题或文章结构安排清楚。","items":[{"eyebrow":"亮点 01","title":"内容主题","description":"把博客页的栏目、主题或文章结构安排清楚。"},{"eyebrow":"亮点 02","title":"当前已回退为静态内容","description":"AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。"},{"eyebrow":"亮点 03","title":"博客文章","description":"此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。"}]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"items":{"type":"textarea","label":"Cards","format":"card-items"}}}}},{"block_id":"blog-post-structure","type":"checklist","html":"<section class=\"ai-block ai-block-checklist\" style=\"padding:8px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:26px;line-height:1.25;color:#0f172a;\">阅读路径<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">让读者知道该从哪里开始，以及下一篇应该看什么。<\/p><div style=\"display:grid;gap:12px;\"><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">1<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">让读者知道该从哪里开始，以及下一篇应该看什么。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">2<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">AI 服务暂不可用，已自动切换到静态占位内容。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">3<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">你可以先继续编辑区块，稍后再重新触发 AI 生成。<\/p><\/div><\/div><\/section>","config":{"section_title":"阅读路径","section_intro":"让读者知道该从哪里开始，以及下一篇应该看什么。","points":["让读者知道该从哪里开始，以及下一篇应该看什么。","AI 服务暂不可用，已自动切换到静态占位内容。","你可以先继续编辑区块，稍后再重新触发 AI 生成。"]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"points":{"type":"textarea","label":"Checklist Items","format":"lines"}}}}},{"block_id":"blog-post-cta","type":"cta","html":"<section class=\"ai-block ai-block-cta\" style=\"padding:16px 28px 56px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);\"><div style=\"padding:28px;border-radius:28px;background:#0f172a;color:#fff;display:grid;gap:14px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#fff;\">继续浏览内容<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,.82);\">博客类页面也需要承接关注或转化。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:12px;align-items:center;\"><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;\">继续编辑<\/span><\/div><\/div><\/section>","config":{"section_title":"继续浏览内容","section_text":"博客类页面也需要承接关注或转化。","button_label":"继续编辑","assist_text":""},"field_schema":{"content":{"label":"CTA Fields","fields":{"section_title":{"type":"text","label":"Title"},"section_text":{"type":"textarea","label":"Text"},"button_label":{"type":"text","label":"Button Label"},"assist_text":{"type":"text","label":"Assist Text"}}}}},{"block_id":"blog-post-site-footer","type":"site_footer","html":"<footer class=\"ai-block ai-block-site-footer\" style=\"padding:28px;background:#020617;color:#e2e8f0;display:grid;gap:20px;\"><div style=\"display:grid;gap:8px;\"><strong style=\"font-size:18px;line-height:1.2;color:#fff;\"><\/strong><p style=\"margin:0;max-width:760px;font-size:14px;line-height:1.7;color:#94a3b8;\">该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><\/div><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px 24px;\"><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Featured Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Policy Info<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">All Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><\/div><div style=\"display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;color:#64748b;font-size:12px;\"><span>漏 2026 <\/span><span>Always improving the visitor experience<\/span><\/div><\/footer>","config":{"site_title":"","brief_description":"该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","domain":"","links.column1_title":"Featured Pages","links.column1_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"links.column2_title":"Policy Info","links.column2_items":[{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false}],"links.column3_title":"All Pages","links.column3_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Footer Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"brief_description":{"type":"textarea","label":"Footer Summary"},"domain":{"type":"text","label":"Domain"},"links.column1_title":{"type":"text","label":"Group 1 Title"},"links.column1_items":{"type":"textarea","label":"Group 1 Links","format":"nav-items"},"links.column2_title":{"type":"text","label":"Group 2 Title"},"links.column2_items":{"type":"textarea","label":"Group 2 Links","format":"nav-items"},"links.column3_title":{"type":"text","label":"Group 3 Title"},"links.column3_items":{"type":"textarea","label":"Group 3 Links","format":"nav-items"}}}}}],"visual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=blog_post&visual_editor=1","visual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=blog_post"},"blog_category":{"page_type":"blog_category","title":"博客分类","handle":"blog-category","locale":"en_US","style_code":"default","style_settings":[],"meta_title":"","meta_description":"","meta_keywords":"","ai_description":"","virtual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=blog_category&visual_editor=1","virtual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=blog_category","preview_full_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=blog_category","last_generated_at":"","materialized_page_id":0,"section_refinements":[],"blocks":[{"block_id":"blog-category-site-header","type":"site_header","html":"<header class=\"ai-block ai-block-site-header\" style=\"padding:18px 28px;border-bottom:1px solid #e5e7eb;background:rgba(255,255,255,0.94);backdrop-filter:blur(12px);display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;\"><div style=\"display:grid;gap:4px;min-width:0;\"><strong style=\"font-size:18px;line-height:1.2;color:#0f172a;\"><\/strong><\/div><div style=\"display:flex;flex-wrap:wrap;gap:16px;align-items:center;\"><a href=\"\/\" style=\"color:#0f172a;font-size:14px;font-weight:700;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">自定义页面<\/a><span style=\"display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">博客分类<\/span><\/div><\/header>","config":{"site_title":"","site_tagline":"","current_page_label":"博客分类","nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Header Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"site_tagline":{"type":"text","label":"Site Tagline"},"current_page_label":{"type":"text","label":"Current Page"},"nav_items":{"type":"textarea","label":"Header Links","format":"nav-items"}}}}},{"block_id":"blog-category-hero","type":"hero","html":"<section class=\"ai-block ai-block-hero\" style=\"padding:56px 28px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);display:grid;gap:16px;\"><span style=\"font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">博客分类<\/span><h1 style=\"margin:0;font-size:40px;line-height:1.08;color:#0f172a;\">博客分类 Hero<\/h1><p style=\"margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;\">博客分类 的内容页要帮助访客理解栏目方向、文章重点和继续阅读路径，用内容承接信任与复访。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:10px;\"><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">博客分类<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">静态占位<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">可继续编辑<\/span><\/div><div><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#0f172a;color:#fff;font-size:14px;font-weight:700;\">继续完善<\/span><\/div><\/section>","config":{"eyebrow":"博客分类","headline":"博客分类 Hero","description":"博客分类 的内容页要帮助访客理解栏目方向、文章重点和继续阅读路径，用内容承接信任与复访。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["博客分类","静态占位","可继续编辑"],"primary_cta":"继续完善","secondary_note":"AI 可用后可重新生成该区块"},"field_schema":{"content":{"label":"Content Fields","fields":{"eyebrow":{"type":"text","label":"Eyebrow"},"headline":{"type":"text","label":"Headline"},"description":{"type":"textarea","label":"Description"},"chips":{"type":"textarea","label":"Chips","format":"lines"},"primary_cta":{"type":"text","label":"Primary CTA"},"secondary_note":{"type":"text","label":"Secondary Note"}}}}},{"block_id":"blog-category-topics","type":"cards","html":"<section class=\"ai-block ai-block-cards\" style=\"padding:20px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#0f172a;\">内容主题<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">把博客页的栏目、主题或文章结构安排清楚。<\/p><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;\"><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 01<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">内容主题<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">把博客页的栏目、主题或文章结构安排清楚。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 02<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">当前已回退为静态内容<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 03<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">博客分类<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。<\/p><\/article><\/div><\/section>","config":{"section_title":"内容主题","section_intro":"把博客页的栏目、主题或文章结构安排清楚。","items":[{"eyebrow":"亮点 01","title":"内容主题","description":"把博客页的栏目、主题或文章结构安排清楚。"},{"eyebrow":"亮点 02","title":"当前已回退为静态内容","description":"AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。"},{"eyebrow":"亮点 03","title":"博客分类","description":"此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。"}]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"items":{"type":"textarea","label":"Cards","format":"card-items"}}}}},{"block_id":"blog-category-structure","type":"checklist","html":"<section class=\"ai-block ai-block-checklist\" style=\"padding:8px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:26px;line-height:1.25;color:#0f172a;\">阅读路径<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">让读者知道该从哪里开始，以及下一篇应该看什么。<\/p><div style=\"display:grid;gap:12px;\"><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">1<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">让读者知道该从哪里开始，以及下一篇应该看什么。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">2<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">AI 服务暂不可用，已自动切换到静态占位内容。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">3<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">你可以先继续编辑区块，稍后再重新触发 AI 生成。<\/p><\/div><\/div><\/section>","config":{"section_title":"阅读路径","section_intro":"让读者知道该从哪里开始，以及下一篇应该看什么。","points":["让读者知道该从哪里开始，以及下一篇应该看什么。","AI 服务暂不可用，已自动切换到静态占位内容。","你可以先继续编辑区块，稍后再重新触发 AI 生成。"]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"points":{"type":"textarea","label":"Checklist Items","format":"lines"}}}}},{"block_id":"blog-category-cta","type":"cta","html":"<section class=\"ai-block ai-block-cta\" style=\"padding:16px 28px 56px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);\"><div style=\"padding:28px;border-radius:28px;background:#0f172a;color:#fff;display:grid;gap:14px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#fff;\">继续浏览内容<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,.82);\">博客类页面也需要承接关注或转化。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:12px;align-items:center;\"><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;\">继续编辑<\/span><\/div><\/div><\/section>","config":{"section_title":"继续浏览内容","section_text":"博客类页面也需要承接关注或转化。","button_label":"继续编辑","assist_text":""},"field_schema":{"content":{"label":"CTA Fields","fields":{"section_title":{"type":"text","label":"Title"},"section_text":{"type":"textarea","label":"Text"},"button_label":{"type":"text","label":"Button Label"},"assist_text":{"type":"text","label":"Assist Text"}}}}},{"block_id":"blog-category-site-footer","type":"site_footer","html":"<footer class=\"ai-block ai-block-site-footer\" style=\"padding:28px;background:#020617;color:#e2e8f0;display:grid;gap:20px;\"><div style=\"display:grid;gap:8px;\"><strong style=\"font-size:18px;line-height:1.2;color:#fff;\"><\/strong><p style=\"margin:0;max-width:760px;font-size:14px;line-height:1.7;color:#94a3b8;\">该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><\/div><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px 24px;\"><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Featured Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Policy Info<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">All Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><\/div><div style=\"display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;color:#64748b;font-size:12px;\"><span>漏 2026 <\/span><span>Always improving the visitor experience<\/span><\/div><\/footer>","config":{"site_title":"","brief_description":"该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","domain":"","links.column1_title":"Featured Pages","links.column1_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"links.column2_title":"Policy Info","links.column2_items":[{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false}],"links.column3_title":"All Pages","links.column3_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Footer Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"brief_description":{"type":"textarea","label":"Footer Summary"},"domain":{"type":"text","label":"Domain"},"links.column1_title":{"type":"text","label":"Group 1 Title"},"links.column1_items":{"type":"textarea","label":"Group 1 Links","format":"nav-items"},"links.column2_title":{"type":"text","label":"Group 2 Title"},"links.column2_items":{"type":"textarea","label":"Group 2 Links","format":"nav-items"},"links.column3_title":{"type":"text","label":"Group 3 Title"},"links.column3_items":{"type":"textarea","label":"Group 3 Links","format":"nav-items"}}}}}],"visual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=blog_category&visual_editor=1","visual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=blog_category"},"blog_list":{"page_type":"blog_list","title":"博客列表","handle":"blog","locale":"en_US","style_code":"default","style_settings":[],"meta_title":"","meta_description":"","meta_keywords":"","ai_description":"","virtual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=blog_list&visual_editor=1","virtual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=blog_list","preview_full_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=blog_list","last_generated_at":"","materialized_page_id":0,"section_refinements":[],"blocks":[{"block_id":"blog-list-site-header","type":"site_header","html":"<header class=\"ai-block ai-block-site-header\" style=\"padding:18px 28px;border-bottom:1px solid #e5e7eb;background:rgba(255,255,255,0.94);backdrop-filter:blur(12px);display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;\"><div style=\"display:grid;gap:4px;min-width:0;\"><strong style=\"font-size:18px;line-height:1.2;color:#0f172a;\"><\/strong><\/div><div style=\"display:flex;flex-wrap:wrap;gap:16px;align-items:center;\"><a href=\"\/\" style=\"color:#0f172a;font-size:14px;font-weight:700;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">自定义页面<\/a><span style=\"display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">博客列表<\/span><\/div><\/header>","config":{"site_title":"","site_tagline":"","current_page_label":"博客列表","nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Header Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"site_tagline":{"type":"text","label":"Site Tagline"},"current_page_label":{"type":"text","label":"Current Page"},"nav_items":{"type":"textarea","label":"Header Links","format":"nav-items"}}}}},{"block_id":"blog-list-hero","type":"hero","html":"<section class=\"ai-block ai-block-hero\" style=\"padding:56px 28px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);display:grid;gap:16px;\"><span style=\"font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">博客列表<\/span><h1 style=\"margin:0;font-size:40px;line-height:1.08;color:#0f172a;\">博客列表 Hero<\/h1><p style=\"margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;\">博客列表 的内容页要帮助访客理解栏目方向、文章重点和继续阅读路径，用内容承接信任与复访。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:10px;\"><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">博客列表<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">静态占位<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">可继续编辑<\/span><\/div><div><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#0f172a;color:#fff;font-size:14px;font-weight:700;\">继续完善<\/span><\/div><\/section>","config":{"eyebrow":"博客列表","headline":"博客列表 Hero","description":"博客列表 的内容页要帮助访客理解栏目方向、文章重点和继续阅读路径，用内容承接信任与复访。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["博客列表","静态占位","可继续编辑"],"primary_cta":"继续完善","secondary_note":"AI 可用后可重新生成该区块"},"field_schema":{"content":{"label":"Content Fields","fields":{"eyebrow":{"type":"text","label":"Eyebrow"},"headline":{"type":"text","label":"Headline"},"description":{"type":"textarea","label":"Description"},"chips":{"type":"textarea","label":"Chips","format":"lines"},"primary_cta":{"type":"text","label":"Primary CTA"},"secondary_note":{"type":"text","label":"Secondary Note"}}}}},{"block_id":"blog-list-topics","type":"cards","html":"<section class=\"ai-block ai-block-cards\" style=\"padding:20px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#0f172a;\">内容主题<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">把博客页的栏目、主题或文章结构安排清楚。<\/p><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;\"><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 01<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">内容主题<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">把博客页的栏目、主题或文章结构安排清楚。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 02<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">当前已回退为静态内容<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 03<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">博客列表<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。<\/p><\/article><\/div><\/section>","config":{"section_title":"内容主题","section_intro":"把博客页的栏目、主题或文章结构安排清楚。","items":[{"eyebrow":"亮点 01","title":"内容主题","description":"把博客页的栏目、主题或文章结构安排清楚。"},{"eyebrow":"亮点 02","title":"当前已回退为静态内容","description":"AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。"},{"eyebrow":"亮点 03","title":"博客列表","description":"此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。"}]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"items":{"type":"textarea","label":"Cards","format":"card-items"}}}}},{"block_id":"blog-list-structure","type":"checklist","html":"<section class=\"ai-block ai-block-checklist\" style=\"padding:8px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:26px;line-height:1.25;color:#0f172a;\">阅读路径<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">让读者知道该从哪里开始，以及下一篇应该看什么。<\/p><div style=\"display:grid;gap:12px;\"><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">1<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">让读者知道该从哪里开始，以及下一篇应该看什么。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">2<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">AI 服务暂不可用，已自动切换到静态占位内容。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">3<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">你可以先继续编辑区块，稍后再重新触发 AI 生成。<\/p><\/div><\/div><\/section>","config":{"section_title":"阅读路径","section_intro":"让读者知道该从哪里开始，以及下一篇应该看什么。","points":["让读者知道该从哪里开始，以及下一篇应该看什么。","AI 服务暂不可用，已自动切换到静态占位内容。","你可以先继续编辑区块，稍后再重新触发 AI 生成。"]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"points":{"type":"textarea","label":"Checklist Items","format":"lines"}}}}},{"block_id":"blog-list-cta","type":"cta","html":"<section class=\"ai-block ai-block-cta\" style=\"padding:16px 28px 56px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);\"><div style=\"padding:28px;border-radius:28px;background:#0f172a;color:#fff;display:grid;gap:14px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#fff;\">继续浏览内容<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,.82);\">博客类页面也需要承接关注或转化。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:12px;align-items:center;\"><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;\">继续编辑<\/span><\/div><\/div><\/section>","config":{"section_title":"继续浏览内容","section_text":"博客类页面也需要承接关注或转化。","button_label":"继续编辑","assist_text":""},"field_schema":{"content":{"label":"CTA Fields","fields":{"section_title":{"type":"text","label":"Title"},"section_text":{"type":"textarea","label":"Text"},"button_label":{"type":"text","label":"Button Label"},"assist_text":{"type":"text","label":"Assist Text"}}}}},{"block_id":"blog-list-site-footer","type":"site_footer","html":"<footer class=\"ai-block ai-block-site-footer\" style=\"padding:28px;background:#020617;color:#e2e8f0;display:grid;gap:20px;\"><div style=\"display:grid;gap:8px;\"><strong style=\"font-size:18px;line-height:1.2;color:#fff;\"><\/strong><p style=\"margin:0;max-width:760px;font-size:14px;line-height:1.7;color:#94a3b8;\">该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><\/div><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px 24px;\"><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Featured Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Policy Info<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">All Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><\/div><div style=\"display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;color:#64748b;font-size:12px;\"><span>漏 2026 <\/span><span>Always improving the visitor experience<\/span><\/div><\/footer>","config":{"site_title":"","brief_description":"该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","domain":"","links.column1_title":"Featured Pages","links.column1_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"links.column2_title":"Policy Info","links.column2_items":[{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false}],"links.column3_title":"All Pages","links.column3_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Footer Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"brief_description":{"type":"textarea","label":"Footer Summary"},"domain":{"type":"text","label":"Domain"},"links.column1_title":{"type":"text","label":"Group 1 Title"},"links.column1_items":{"type":"textarea","label":"Group 1 Links","format":"nav-items"},"links.column2_title":{"type":"text","label":"Group 2 Title"},"links.column2_items":{"type":"textarea","label":"Group 2 Links","format":"nav-items"},"links.column3_title":{"type":"text","label":"Group 3 Title"},"links.column3_items":{"type":"textarea","label":"Group 3 Links","format":"nav-items"}}}}}],"visual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=blog_list&visual_editor=1","visual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=blog_list"},"custom_page":{"page_type":"custom_page","title":"自定义页面","handle":"page","locale":"en_US","style_code":"default","style_settings":[],"meta_title":"","meta_description":"","meta_keywords":"","ai_description":"","virtual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=custom_page&visual_editor=1","virtual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=custom_page","preview_full_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=custom_page","last_generated_at":"","materialized_page_id":0,"section_refinements":[],"blocks":[{"block_id":"custom-page-site-header","type":"site_header","html":"<header class=\"ai-block ai-block-site-header\" style=\"padding:18px 28px;border-bottom:1px solid #e5e7eb;background:rgba(255,255,255,0.94);backdrop-filter:blur(12px);display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;\"><div style=\"display:grid;gap:4px;min-width:0;\"><strong style=\"font-size:18px;line-height:1.2;color:#0f172a;\"><\/strong><\/div><div style=\"display:flex;flex-wrap:wrap;gap:16px;align-items:center;\"><a href=\"\/\" style=\"color:#0f172a;font-size:14px;font-weight:700;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">自定义页面<\/a><span style=\"display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">自定义页面<\/span><\/div><\/header>","config":{"site_title":"","site_tagline":"","current_page_label":"自定义页面","nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Header Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"site_tagline":{"type":"text","label":"Site Tagline"},"current_page_label":{"type":"text","label":"Current Page"},"nav_items":{"type":"textarea","label":"Header Links","format":"nav-items"}}}}},{"block_id":"custom-page-hero","type":"hero","html":"<section class=\"ai-block ai-block-hero\" style=\"padding:56px 28px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);display:grid;gap:16px;\"><span style=\"font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">自定义页面<\/span><h1 style=\"margin:0;font-size:40px;line-height:1.08;color:#0f172a;\">自定义页面 Hero<\/h1><p style=\"margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;\">自定义页面 的自定义页面需要围绕页面目标组织完整内容，保证结构清楚、信息完整、行动入口明确。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:10px;\"><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">自定义页面<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">静态占位<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">可继续编辑<\/span><\/div><div><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#0f172a;color:#fff;font-size:14px;font-weight:700;\">继续完善<\/span><\/div><\/section>","config":{"eyebrow":"自定义页面","headline":"自定义页面 Hero","description":"自定义页面 的自定义页面需要围绕页面目标组织完整内容，保证结构清楚、信息完整、行动入口明确。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["自定义页面","静态占位","可继续编辑"],"primary_cta":"继续完善","secondary_note":"AI 可用后可重新生成该区块"},"field_schema":{"content":{"label":"Content Fields","fields":{"eyebrow":{"type":"text","label":"Eyebrow"},"headline":{"type":"text","label":"Headline"},"description":{"type":"textarea","label":"Description"},"chips":{"type":"textarea","label":"Chips","format":"lines"},"primary_cta":{"type":"text","label":"Primary CTA"},"secondary_note":{"type":"text","label":"Secondary Note"}}}}},{"block_id":"custom-page-modules","type":"cards","html":"<section class=\"ai-block ai-block-cards\" style=\"padding:20px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#0f172a;\">信息模块<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">把这一页需要承接的重点内容拆成可编辑的区块。<\/p><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;\"><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 01<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">信息模块<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">把这一页需要承接的重点内容拆成可编辑的区块。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 02<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">当前已回退为静态内容<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 03<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">自定义页面<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。<\/p><\/article><\/div><\/section>","config":{"section_title":"信息模块","section_intro":"把这一页需要承接的重点内容拆成可编辑的区块。","items":[{"eyebrow":"亮点 01","title":"信息模块","description":"把这一页需要承接的重点内容拆成可编辑的区块。"},{"eyebrow":"亮点 02","title":"当前已回退为静态内容","description":"AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。"},{"eyebrow":"亮点 03","title":"自定义页面","description":"此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。"}]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"items":{"type":"textarea","label":"Cards","format":"card-items"}}}}},{"block_id":"custom-page-steps","type":"checklist","html":"<section class=\"ai-block ai-block-checklist\" style=\"padding:8px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:26px;line-height:1.25;color:#0f172a;\">继续完善建议<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">先生成结构，再逐块微调。<\/p><div style=\"display:grid;gap:12px;\"><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">1<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">先生成结构，再逐块微调。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">2<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">AI 服务暂不可用，已自动切换到静态占位内容。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">3<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">你可以先继续编辑区块，稍后再重新触发 AI 生成。<\/p><\/div><\/div><\/section>","config":{"section_title":"继续完善建议","section_intro":"先生成结构，再逐块微调。","points":["先生成结构，再逐块微调。","AI 服务暂不可用，已自动切换到静态占位内容。","你可以先继续编辑区块，稍后再重新触发 AI 生成。"]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"points":{"type":"textarea","label":"Checklist Items","format":"lines"}}}}},{"block_id":"custom-page-cta","type":"cta","html":"<section class=\"ai-block ai-block-cta\" style=\"padding:16px 28px 56px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);\"><div style=\"padding:28px;border-radius:28px;background:#0f172a;color:#fff;display:grid;gap:14px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#fff;\">继续微调这一页<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,.82);\">自定义页面更适合按区块迭代。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:12px;align-items:center;\"><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;\">继续编辑<\/span><\/div><\/div><\/section>","config":{"section_title":"继续微调这一页","section_text":"自定义页面更适合按区块迭代。","button_label":"继续编辑","assist_text":""},"field_schema":{"content":{"label":"CTA Fields","fields":{"section_title":{"type":"text","label":"Title"},"section_text":{"type":"textarea","label":"Text"},"button_label":{"type":"text","label":"Button Label"},"assist_text":{"type":"text","label":"Assist Text"}}}}},{"block_id":"custom-page-site-footer","type":"site_footer","html":"<footer class=\"ai-block ai-block-site-footer\" style=\"padding:28px;background:#020617;color:#e2e8f0;display:grid;gap:20px;\"><div style=\"display:grid;gap:8px;\"><strong style=\"font-size:18px;line-height:1.2;color:#fff;\"><\/strong><p style=\"margin:0;max-width:760px;font-size:14px;line-height:1.7;color:#94a3b8;\">该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><\/div><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px 24px;\"><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Featured Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Policy Info<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">All Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><\/div><div style=\"display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;color:#64748b;font-size:12px;\"><span>漏 2026 <\/span><span>Always improving the visitor experience<\/span><\/div><\/footer>","config":{"site_title":"","brief_description":"该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","domain":"","links.column1_title":"Featured Pages","links.column1_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"links.column2_title":"Policy Info","links.column2_items":[{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false}],"links.column3_title":"All Pages","links.column3_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Footer Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"brief_description":{"type":"textarea","label":"Footer Summary"},"domain":{"type":"text","label":"Domain"},"links.column1_title":{"type":"text","label":"Group 1 Title"},"links.column1_items":{"type":"textarea","label":"Group 1 Links","format":"nav-items"},"links.column2_title":{"type":"text","label":"Group 2 Title"},"links.column2_items":{"type":"textarea","label":"Group 2 Links","format":"nav-items"},"links.column3_title":{"type":"text","label":"Group 3 Title"},"links.column3_items":{"type":"textarea","label":"Group 3 Links","format":"nav-items"}}}}}],"visual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=custom_page&visual_editor=1","visual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=custom_page"}},"pending_generation_page_types":["home_page","about_page","contact_page","privacy_policy","terms_of_service","refund_policy","shipping_policy","cookie_policy","blog_post","blog_category","blog_list","custom_page"],"build_task_summary":{"total":50,"done":0,"pending":50,"running":0,"failed":0,"cancelled":0,"groups":{"shared":{"page_type":"","total":2,"done":0,"pending":2,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"shared:header","label":"Header","section_code":"","component":"","task_type":"shared_component","page_type":"","group_key":"shared","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"shared:footer","label":"Footer","section_code":"","component":"","task_type":"shared_component","page_type":"","group_key":"shared","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"home_page":{"page_type":"home_page","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:home_page:content\/home-page-hero","label":"首页 Hero","section_code":"content\/home-page-hero","component":"","task_type":"page_section","page_type":"home_page","group_key":"home_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:home_page:content\/home-page-highlights","label":"核心卖点","section_code":"content\/home-page-highlights","component":"","task_type":"page_section","page_type":"home_page","group_key":"home_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:home_page:content\/home-page-details","label":"转化路径","section_code":"content\/home-page-details","component":"","task_type":"page_section","page_type":"home_page","group_key":"home_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:home_page:content\/home-page-cta","label":"开始承接流量","section_code":"content\/home-page-cta","component":"","task_type":"page_section","page_type":"home_page","group_key":"home_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"about_page":{"page_type":"about_page","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:about_page:content\/about-page-hero","label":"关于我们 Hero","section_code":"content\/about-page-hero","component":"","task_type":"page_section","page_type":"about_page","group_key":"about_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:about_page:content\/about-page-story","label":"品牌与团队","section_code":"content\/about-page-story","component":"","task_type":"page_section","page_type":"about_page","group_key":"about_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:about_page:content\/about-page-values","label":"为什么值得信任","section_code":"content\/about-page-values","component":"","task_type":"page_section","page_type":"about_page","group_key":"about_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:about_page:content\/about-page-cta","label":"继续了解合作方式","section_code":"content\/about-page-cta","component":"","task_type":"page_section","page_type":"about_page","group_key":"about_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"contact_page":{"page_type":"contact_page","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:contact_page:content\/contact-page-hero","label":"联系我们 Hero","section_code":"content\/contact-page-hero","component":"","task_type":"page_section","page_type":"contact_page","group_key":"contact_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:contact_page:content\/contact-page-channels","label":"联系渠道","section_code":"content\/contact-page-channels","component":"","task_type":"page_section","page_type":"contact_page","group_key":"contact_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:contact_page:content\/contact-page-process","label":"响应预期","section_code":"content\/contact-page-process","component":"","task_type":"page_section","page_type":"contact_page","group_key":"contact_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:contact_page:content\/contact-page-cta","label":"现在发起联系","section_code":"content\/contact-page-cta","component":"","task_type":"page_section","page_type":"contact_page","group_key":"contact_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"privacy_policy":{"page_type":"privacy_policy","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:privacy_policy:content\/privacy-policy-hero","label":"隐私政策 Hero","section_code":"content\/privacy-policy-hero","component":"","task_type":"page_section","page_type":"privacy_policy","group_key":"privacy_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:privacy_policy:content\/privacy-policy-coverage","label":"适用范围","section_code":"content\/privacy-policy-coverage","component":"","task_type":"page_section","page_type":"privacy_policy","group_key":"privacy_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:privacy_policy:content\/privacy-policy-rights","label":"用户权利与执行","section_code":"content\/privacy-policy-rights","component":"","task_type":"page_section","page_type":"privacy_policy","group_key":"privacy_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:privacy_policy:content\/privacy-policy-cta","label":"需要补充说明？","section_code":"content\/privacy-policy-cta","component":"","task_type":"page_section","page_type":"privacy_policy","group_key":"privacy_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"terms_of_service":{"page_type":"terms_of_service","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:terms_of_service:content\/terms-of-service-hero","label":"服务条款 Hero","section_code":"content\/terms-of-service-hero","component":"","task_type":"page_section","page_type":"terms_of_service","group_key":"terms_of_service","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:terms_of_service:content\/terms-of-service-coverage","label":"适用范围","section_code":"content\/terms-of-service-coverage","component":"","task_type":"page_section","page_type":"terms_of_service","group_key":"terms_of_service","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:terms_of_service:content\/terms-of-service-rights","label":"用户权利与执行","section_code":"content\/terms-of-service-rights","component":"","task_type":"page_section","page_type":"terms_of_service","group_key":"terms_of_service","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:terms_of_service:content\/terms-of-service-cta","label":"需要补充说明？","section_code":"content\/terms-of-service-cta","component":"","task_type":"page_section","page_type":"terms_of_service","group_key":"terms_of_service","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"refund_policy":{"page_type":"refund_policy","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:refund_policy:content\/refund-policy-hero","label":"退款政策 Hero","section_code":"content\/refund-policy-hero","component":"","task_type":"page_section","page_type":"refund_policy","group_key":"refund_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:refund_policy:content\/refund-policy-coverage","label":"适用范围","section_code":"content\/refund-policy-coverage","component":"","task_type":"page_section","page_type":"refund_policy","group_key":"refund_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:refund_policy:content\/refund-policy-rights","label":"用户权利与执行","section_code":"content\/refund-policy-rights","component":"","task_type":"page_section","page_type":"refund_policy","group_key":"refund_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:refund_policy:content\/refund-policy-cta","label":"需要补充说明？","section_code":"content\/refund-policy-cta","component":"","task_type":"page_section","page_type":"refund_policy","group_key":"refund_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"shipping_policy":{"page_type":"shipping_policy","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:shipping_policy:content\/shipping-policy-hero","label":"配送政策 Hero","section_code":"content\/shipping-policy-hero","component":"","task_type":"page_section","page_type":"shipping_policy","group_key":"shipping_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:shipping_policy:content\/shipping-policy-coverage","label":"适用范围","section_code":"content\/shipping-policy-coverage","component":"","task_type":"page_section","page_type":"shipping_policy","group_key":"shipping_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:shipping_policy:content\/shipping-policy-rights","label":"用户权利与执行","section_code":"content\/shipping-policy-rights","component":"","task_type":"page_section","page_type":"shipping_policy","group_key":"shipping_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:shipping_policy:content\/shipping-policy-cta","label":"需要补充说明？","section_code":"content\/shipping-policy-cta","component":"","task_type":"page_section","page_type":"shipping_policy","group_key":"shipping_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"cookie_policy":{"page_type":"cookie_policy","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:cookie_policy:content\/cookie-policy-hero","label":"Cookie政策 Hero","section_code":"content\/cookie-policy-hero","component":"","task_type":"page_section","page_type":"cookie_policy","group_key":"cookie_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:cookie_policy:content\/cookie-policy-coverage","label":"适用范围","section_code":"content\/cookie-policy-coverage","component":"","task_type":"page_section","page_type":"cookie_policy","group_key":"cookie_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:cookie_policy:content\/cookie-policy-rights","label":"用户权利与执行","section_code":"content\/cookie-policy-rights","component":"","task_type":"page_section","page_type":"cookie_policy","group_key":"cookie_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:cookie_policy:content\/cookie-policy-cta","label":"需要补充说明？","section_code":"content\/cookie-policy-cta","component":"","task_type":"page_section","page_type":"cookie_policy","group_key":"cookie_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"blog_post":{"page_type":"blog_post","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:blog_post:content\/blog-post-hero","label":"博客文章 Hero","section_code":"content\/blog-post-hero","component":"","task_type":"page_section","page_type":"blog_post","group_key":"blog_post","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_post:content\/blog-post-topics","label":"内容主题","section_code":"content\/blog-post-topics","component":"","task_type":"page_section","page_type":"blog_post","group_key":"blog_post","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_post:content\/blog-post-structure","label":"阅读路径","section_code":"content\/blog-post-structure","component":"","task_type":"page_section","page_type":"blog_post","group_key":"blog_post","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_post:content\/blog-post-cta","label":"继续浏览内容","section_code":"content\/blog-post-cta","component":"","task_type":"page_section","page_type":"blog_post","group_key":"blog_post","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"blog_category":{"page_type":"blog_category","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:blog_category:content\/blog-category-hero","label":"博客分类 Hero","section_code":"content\/blog-category-hero","component":"","task_type":"page_section","page_type":"blog_category","group_key":"blog_category","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_category:content\/blog-category-topics","label":"内容主题","section_code":"content\/blog-category-topics","component":"","task_type":"page_section","page_type":"blog_category","group_key":"blog_category","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_category:content\/blog-category-structure","label":"阅读路径","section_code":"content\/blog-category-structure","component":"","task_type":"page_section","page_type":"blog_category","group_key":"blog_category","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_category:content\/blog-category-cta","label":"继续浏览内容","section_code":"content\/blog-category-cta","component":"","task_type":"page_section","page_type":"blog_category","group_key":"blog_category","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"blog_list":{"page_type":"blog_list","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:blog_list:content\/blog-list-hero","label":"博客列表 Hero","section_code":"content\/blog-list-hero","component":"","task_type":"page_section","page_type":"blog_list","group_key":"blog_list","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_list:content\/blog-list-topics","label":"内容主题","section_code":"content\/blog-list-topics","component":"","task_type":"page_section","page_type":"blog_list","group_key":"blog_list","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_list:content\/blog-list-structure","label":"阅读路径","section_code":"content\/blog-list-structure","component":"","task_type":"page_section","page_type":"blog_list","group_key":"blog_list","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_list:content\/blog-list-cta","label":"继续浏览内容","section_code":"content\/blog-list-cta","component":"","task_type":"page_section","page_type":"blog_list","group_key":"blog_list","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"custom_page":{"page_type":"custom_page","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:custom_page:content\/custom-page-hero","label":"自定义页面 Hero","section_code":"content\/custom-page-hero","component":"","task_type":"page_section","page_type":"custom_page","group_key":"custom_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:custom_page:content\/custom-page-modules","label":"信息模块","section_code":"content\/custom-page-modules","component":"","task_type":"page_section","page_type":"custom_page","group_key":"custom_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:custom_page:content\/custom-page-steps","label":"继续完善建议","section_code":"content\/custom-page-steps","component":"","task_type":"page_section","page_type":"custom_page","group_key":"custom_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:custom_page:content\/custom-page-cta","label":"继续微调这一页","section_code":"content\/custom-page-cta","component":"","task_type":"page_section","page_type":"custom_page","group_key":"custom_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]}}},"plan":{"json":[],"markdown":"","structured":[],"execution_blueprint":[]},"plan_json":[],"plan_markdown":"","plan_structured":[],"plan_confirmed":0,"has_execution_blueprint":false,"plan_confirmed_at":"","plan_sse_url":"\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/plan-sse","confirmed_plan_markdown":"","confirmed_plan_signature":"","task_plan":{"markdown":"","structured":[],"virtual_theme_plan":[]},"task_plan_markdown":"","task_plan_structured":[],"task_plan_confirmed":0,"task_plan_confirmed_at":"","has_virtual_theme_plan":false,"has_pending_build_tasks":true,"task_plan_sse_url":"\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-task-plan-sse","auto_start_build_after_stream":false,"preview_page_options":[],"preview_page_id":0,"preview_page_type":"home_page","preview_full_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page","visual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page&visual_editor=1","visual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page","pre_publish_visual_urls":{"preview_full_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page","visual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page&visual_editor=1","visual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page"},"active_operation":[],"build_summary":{"page_count":12,"pending_page_count":12,"pending_page_types":["home_page","about_page","contact_page","privacy_policy","terms_of_service","refund_policy","shipping_policy","cookie_policy","blog_post","blog_category","blog_list","custom_page"],"last_generated_at":"","active_operation":"","can_publish":false,"task_summary":{"total":50,"done":0,"pending":50,"running":0,"failed":0,"cancelled":0,"groups":{"shared":{"page_type":"","total":2,"done":0,"pending":2,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"shared:header","label":"Header","section_code":"","component":"","task_type":"shared_component","page_type":"","group_key":"shared","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"shared:footer","label":"Footer","section_code":"","component":"","task_type":"shared_component","page_type":"","group_key":"shared","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"home_page":{"page_type":"home_page","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:home_page:content\/home-page-hero","label":"首页 Hero","section_code":"content\/home-page-hero","component":"","task_type":"page_section","page_type":"home_page","group_key":"home_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:home_page:content\/home-page-highlights","label":"核心卖点","section_code":"content\/home-page-highlights","component":"","task_type":"page_section","page_type":"home_page","group_key":"home_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:home_page:content\/home-page-details","label":"转化路径","section_code":"content\/home-page-details","component":"","task_type":"page_section","page_type":"home_page","group_key":"home_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:home_page:content\/home-page-cta","label":"开始承接流量","section_code":"content\/home-page-cta","component":"","task_type":"page_section","page_type":"home_page","group_key":"home_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"about_page":{"page_type":"about_page","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:about_page:content\/about-page-hero","label":"关于我们 Hero","section_code":"content\/about-page-hero","component":"","task_type":"page_section","page_type":"about_page","group_key":"about_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:about_page:content\/about-page-story","label":"品牌与团队","section_code":"content\/about-page-story","component":"","task_type":"page_section","page_type":"about_page","group_key":"about_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:about_page:content\/about-page-values","label":"为什么值得信任","section_code":"content\/about-page-values","component":"","task_type":"page_section","page_type":"about_page","group_key":"about_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:about_page:content\/about-page-cta","label":"继续了解合作方式","section_code":"content\/about-page-cta","component":"","task_type":"page_section","page_type":"about_page","group_key":"about_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"contact_page":{"page_type":"contact_page","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:contact_page:content\/contact-page-hero","label":"联系我们 Hero","section_code":"content\/contact-page-hero","component":"","task_type":"page_section","page_type":"contact_page","group_key":"contact_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:contact_page:content\/contact-page-channels","label":"联系渠道","section_code":"content\/contact-page-channels","component":"","task_type":"page_section","page_type":"contact_page","group_key":"contact_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:contact_page:content\/contact-page-process","label":"响应预期","section_code":"content\/contact-page-process","component":"","task_type":"page_section","page_type":"contact_page","group_key":"contact_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:contact_page:content\/contact-page-cta","label":"现在发起联系","section_code":"content\/contact-page-cta","component":"","task_type":"page_section","page_type":"contact_page","group_key":"contact_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"privacy_policy":{"page_type":"privacy_policy","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:privacy_policy:content\/privacy-policy-hero","label":"隐私政策 Hero","section_code":"content\/privacy-policy-hero","component":"","task_type":"page_section","page_type":"privacy_policy","group_key":"privacy_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:privacy_policy:content\/privacy-policy-coverage","label":"适用范围","section_code":"content\/privacy-policy-coverage","component":"","task_type":"page_section","page_type":"privacy_policy","group_key":"privacy_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:privacy_policy:content\/privacy-policy-rights","label":"用户权利与执行","section_code":"content\/privacy-policy-rights","component":"","task_type":"page_section","page_type":"privacy_policy","group_key":"privacy_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:privacy_policy:content\/privacy-policy-cta","label":"需要补充说明？","section_code":"content\/privacy-policy-cta","component":"","task_type":"page_section","page_type":"privacy_policy","group_key":"privacy_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"terms_of_service":{"page_type":"terms_of_service","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:terms_of_service:content\/terms-of-service-hero","label":"服务条款 Hero","section_code":"content\/terms-of-service-hero","component":"","task_type":"page_section","page_type":"terms_of_service","group_key":"terms_of_service","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:terms_of_service:content\/terms-of-service-coverage","label":"适用范围","section_code":"content\/terms-of-service-coverage","component":"","task_type":"page_section","page_type":"terms_of_service","group_key":"terms_of_service","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:terms_of_service:content\/terms-of-service-rights","label":"用户权利与执行","section_code":"content\/terms-of-service-rights","component":"","task_type":"page_section","page_type":"terms_of_service","group_key":"terms_of_service","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:terms_of_service:content\/terms-of-service-cta","label":"需要补充说明？","section_code":"content\/terms-of-service-cta","component":"","task_type":"page_section","page_type":"terms_of_service","group_key":"terms_of_service","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"refund_policy":{"page_type":"refund_policy","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:refund_policy:content\/refund-policy-hero","label":"退款政策 Hero","section_code":"content\/refund-policy-hero","component":"","task_type":"page_section","page_type":"refund_policy","group_key":"refund_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:refund_policy:content\/refund-policy-coverage","label":"适用范围","section_code":"content\/refund-policy-coverage","component":"","task_type":"page_section","page_type":"refund_policy","group_key":"refund_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:refund_policy:content\/refund-policy-rights","label":"用户权利与执行","section_code":"content\/refund-policy-rights","component":"","task_type":"page_section","page_type":"refund_policy","group_key":"refund_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:refund_policy:content\/refund-policy-cta","label":"需要补充说明？","section_code":"content\/refund-policy-cta","component":"","task_type":"page_section","page_type":"refund_policy","group_key":"refund_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"shipping_policy":{"page_type":"shipping_policy","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:shipping_policy:content\/shipping-policy-hero","label":"配送政策 Hero","section_code":"content\/shipping-policy-hero","component":"","task_type":"page_section","page_type":"shipping_policy","group_key":"shipping_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:shipping_policy:content\/shipping-policy-coverage","label":"适用范围","section_code":"content\/shipping-policy-coverage","component":"","task_type":"page_section","page_type":"shipping_policy","group_key":"shipping_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:shipping_policy:content\/shipping-policy-rights","label":"用户权利与执行","section_code":"content\/shipping-policy-rights","component":"","task_type":"page_section","page_type":"shipping_policy","group_key":"shipping_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:shipping_policy:content\/shipping-policy-cta","label":"需要补充说明？","section_code":"content\/shipping-policy-cta","component":"","task_type":"page_section","page_type":"shipping_policy","group_key":"shipping_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"cookie_policy":{"page_type":"cookie_policy","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:cookie_policy:content\/cookie-policy-hero","label":"Cookie政策 Hero","section_code":"content\/cookie-policy-hero","component":"","task_type":"page_section","page_type":"cookie_policy","group_key":"cookie_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:cookie_policy:content\/cookie-policy-coverage","label":"适用范围","section_code":"content\/cookie-policy-coverage","component":"","task_type":"page_section","page_type":"cookie_policy","group_key":"cookie_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:cookie_policy:content\/cookie-policy-rights","label":"用户权利与执行","section_code":"content\/cookie-policy-rights","component":"","task_type":"page_section","page_type":"cookie_policy","group_key":"cookie_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:cookie_policy:content\/cookie-policy-cta","label":"需要补充说明？","section_code":"content\/cookie-policy-cta","component":"","task_type":"page_section","page_type":"cookie_policy","group_key":"cookie_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"blog_post":{"page_type":"blog_post","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:blog_post:content\/blog-post-hero","label":"博客文章 Hero","section_code":"content\/blog-post-hero","component":"","task_type":"page_section","page_type":"blog_post","group_key":"blog_post","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_post:content\/blog-post-topics","label":"内容主题","section_code":"content\/blog-post-topics","component":"","task_type":"page_section","page_type":"blog_post","group_key":"blog_post","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_post:content\/blog-post-structure","label":"阅读路径","section_code":"content\/blog-post-structure","component":"","task_type":"page_section","page_type":"blog_post","group_key":"blog_post","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_post:content\/blog-post-cta","label":"继续浏览内容","section_code":"content\/blog-post-cta","component":"","task_type":"page_section","page_type":"blog_post","group_key":"blog_post","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"blog_category":{"page_type":"blog_category","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:blog_category:content\/blog-category-hero","label":"博客分类 Hero","section_code":"content\/blog-category-hero","component":"","task_type":"page_section","page_type":"blog_category","group_key":"blog_category","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_category:content\/blog-category-topics","label":"内容主题","section_code":"content\/blog-category-topics","component":"","task_type":"page_section","page_type":"blog_category","group_key":"blog_category","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_category:content\/blog-category-structure","label":"阅读路径","section_code":"content\/blog-category-structure","component":"","task_type":"page_section","page_type":"blog_category","group_key":"blog_category","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_category:content\/blog-category-cta","label":"继续浏览内容","section_code":"content\/blog-category-cta","component":"","task_type":"page_section","page_type":"blog_category","group_key":"blog_category","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"blog_list":{"page_type":"blog_list","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:blog_list:content\/blog-list-hero","label":"博客列表 Hero","section_code":"content\/blog-list-hero","component":"","task_type":"page_section","page_type":"blog_list","group_key":"blog_list","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_list:content\/blog-list-topics","label":"内容主题","section_code":"content\/blog-list-topics","component":"","task_type":"page_section","page_type":"blog_list","group_key":"blog_list","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_list:content\/blog-list-structure","label":"阅读路径","section_code":"content\/blog-list-structure","component":"","task_type":"page_section","page_type":"blog_list","group_key":"blog_list","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_list:content\/blog-list-cta","label":"继续浏览内容","section_code":"content\/blog-list-cta","component":"","task_type":"page_section","page_type":"blog_list","group_key":"blog_list","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"custom_page":{"page_type":"custom_page","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:custom_page:content\/custom-page-hero","label":"自定义页面 Hero","section_code":"content\/custom-page-hero","component":"","task_type":"page_section","page_type":"custom_page","group_key":"custom_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:custom_page:content\/custom-page-modules","label":"信息模块","section_code":"content\/custom-page-modules","component":"","task_type":"page_section","page_type":"custom_page","group_key":"custom_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:custom_page:content\/custom-page-steps","label":"继续完善建议","section_code":"content\/custom-page-steps","component":"","task_type":"page_section","page_type":"custom_page","group_key":"custom_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:custom_page:content\/custom-page-cta","label":"继续微调这一页","section_code":"content\/custom-page-cta","component":"","task_type":"page_section","page_type":"custom_page","group_key":"custom_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]}}}},"top_logs":[],"scope":{"workspace_status":"preparing","fake_mode":1,"handoff_workspace_public_id":"4a7b9293b052ea9a0aa1e419125a38da","provider_handoff_mode":"pagebuilder_native_workspace","target_domain":"","selected_domain":"","preferred_registrar_account_id":0,"registrar_account_id":0,"recommended_registrar_label":"","recommended_domain_list":[],"site_ready":1,"site_title":"","site_tagline":"","brief_description":"","user_description":"","default_locale":"","plan_locale":"","page_types":["home_page","about_page","contact_page","privacy_policy","terms_of_service","refund_policy","shipping_policy","cookie_policy","blog_post","blog_category","blog_list","custom_page"],"recommended_pages":["home_page","about_page","contact_page","privacy_policy","terms_of_service","refund_policy","shipping_policy","cookie_policy","blog_post","blog_category","blog_list","custom_page"],"page_types_user_customized":1,"site_profile_manual":{"site_title":false,"site_tagline":false,"target_domain":false,"brief_description":false,"default_locale":false,"plan_locale":false},"draft_website_id":0,"preview_page_id":0,"preview_page_type":"home_page","website_profile":{"site_title":"","site_tagline":"","brief_description":"","target_domain":"","default_locale":"en_US","locales":["en_US"],"logo":"data:image\/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxNjAiIGhlaWdodD0iNDgiIHZpZXdCb3g9IjAgMCAxNjAgNDgiPgogIDxkZWZzPgogICAgPGxpbmVhckdyYWRpZW50IGlkPSJicmFuZExvZ29HcmFkaWVudCIgeDE9IjAlIiB5MT0iMCUiIHgyPSIxMDAlIiB5Mj0iMTAwJSI+CiAgICAgIDxzdG9wIG9mZnNldD0iMCUiIHN0b3AtY29sb3I9IiMwZjE3MmEiLz4KICAgICAgPHN0b3Agb2Zmc2V0PSIxMDAlIiBzdG9wLWNvbG9yPSIjMzM0MTU1Ii8+CiAgICA8L2xpbmVhckdyYWRpZW50PgogIDwvZGVmcz4KICA8cmVjdCB4PSIwIiB5PSIwIiB3aWR0aD0iMTYwIiBoZWlnaHQ9IjQ4IiByeD0iMTQiIGZpbGw9IiNmZmZmZmYiLz4KICA8cmVjdCB4PSI2IiB5PSI2IiB3aWR0aD0iMzYiIGhlaWdodD0iMzYiIHJ4PSIxMiIgZmlsbD0idXJsKCNicmFuZExvZ29HcmFkaWVudCkiLz4KICA8Y2lyY2xlIGN4PSIyNCIgY3k9IjI0IiByPSIxMCIgZmlsbD0icmdiYSgyNTUsMjU1LDI1NSwwLjE4KSIvPgogIDx0ZXh0IHg9IjI0IiB5PSIyNiIgZG9taW5hbnQtYmFzZWxpbmU9Im1pZGRsZSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZm9udC1mYW1pbHk9IkFyaWFsLCBzYW5zLXNlcmlmIiBmb250LXNpemU9IjE0IiBmb250LXdlaWdodD0iNzAwIiBmaWxsPSIjZmZmZmZmIj5BPC90ZXh0PgogIDx0ZXh0IHg9IjUyIiB5PSIyMCIgZm9udC1mYW1pbHk9IkFyaWFsLCBzYW5zLXNlcmlmIiBmb250LXNpemU9IjEzIiBmb250LXdlaWdodD0iNzAwIiBmaWxsPSIjMGYxNzJhIj48L3RleHQ+CiAgPHRleHQgeD0iNTIiIHk9IjM0IiBmb250LWZhbWlseT0iQXJpYWwsIHNhbnMtc2VyaWYiIGZvbnQtc2l6ZT0iOSIgZmlsbD0iIzY0NzQ4YiI+PC90ZXh0PgogIDxyZWN0IHg9IjUyIiB5PSIyNCIgd2lkdGg9IjE4IiBoZWlnaHQ9IjIiIHJ4PSIxIiBmaWxsPSIjMzhiZGY4Ii8+Cjwvc3ZnPg==","favicon":"data:image\/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI2NCIgaGVpZ2h0PSI2NCIgdmlld0JveD0iMCAwIDY0IDY0Ij4KICA8ZGVmcz4KICAgIDxsaW5lYXJHcmFkaWVudCBpZD0iYnJhbmRJY29uR3JhZGllbnQiIHgxPSIwJSIgeTE9IjAlIiB4Mj0iMTAwJSIgeTI9IjEwMCUiPgogICAgICA8c3RvcCBvZmZzZXQ9IjAlIiBzdG9wLWNvbG9yPSIjMGYxNzJhIi8+CiAgICAgIDxzdG9wIG9mZnNldD0iMTAwJSIgc3RvcC1jb2xvcj0iIzMzNDE1NSIvPgogICAgPC9saW5lYXJHcmFkaWVudD4KICA8L2RlZnM+CiAgPHJlY3QgeD0iNCIgeT0iNCIgd2lkdGg9IjU2IiBoZWlnaHQ9IjU2IiByeD0iMTgiIGZpbGw9InVybCgjYnJhbmRJY29uR3JhZGllbnQpIi8+CiAgPGNpcmNsZSBjeD0iMzIiIGN5PSIzMiIgcj0iMTYiIGZpbGw9InJnYmEoMjU1LDI1NSwyNTUsMC4xNCkiLz4KICA8cGF0aCBkPSJNMTggNDYgTDQ2IDE4IiBzdHJva2U9IiMzOGJkZjgiIHN0cm9rZS13aWR0aD0iMyIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBvcGFjaXR5PSIwLjU1Ii8+CiAgPHRleHQgeD0iMzIiIHk9IjM1IiBkb21pbmFudC1iYXNlbGluZT0ibWlkZGxlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmb250LWZhbWlseT0iQXJpYWwsIHNhbnMtc2VyaWYiIGZvbnQtc2l6ZT0iMjAiIGZvbnQtd2VpZ2h0PSI3MDAiIGZpbGw9IiNmZmZmZmYiPkE8L3RleHQ+Cjwvc3ZnPg==","icon":"data:image\/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI2NCIgaGVpZ2h0PSI2NCIgdmlld0JveD0iMCAwIDY0IDY0Ij4KICA8ZGVmcz4KICAgIDxsaW5lYXJHcmFkaWVudCBpZD0iYnJhbmRJY29uR3JhZGllbnQiIHgxPSIwJSIgeTE9IjAlIiB4Mj0iMTAwJSIgeTI9IjEwMCUiPgogICAgICA8c3RvcCBvZmZzZXQ9IjAlIiBzdG9wLWNvbG9yPSIjMGYxNzJhIi8+CiAgICAgIDxzdG9wIG9mZnNldD0iMTAwJSIgc3RvcC1jb2xvcj0iIzMzNDE1NSIvPgogICAgPC9saW5lYXJHcmFkaWVudD4KICA8L2RlZnM+CiAgPHJlY3QgeD0iNCIgeT0iNCIgd2lkdGg9IjU2IiBoZWlnaHQ9IjU2IiByeD0iMTgiIGZpbGw9InVybCgjYnJhbmRJY29uR3JhZGllbnQpIi8+CiAgPGNpcmNsZSBjeD0iMzIiIGN5PSIzMiIgcj0iMTYiIGZpbGw9InJnYmEoMjU1LDI1NSwyNTUsMC4xNCkiLz4KICA8cGF0aCBkPSJNMTggNDYgTDQ2IDE4IiBzdHJva2U9IiMzOGJkZjgiIHN0cm9rZS13aWR0aD0iMyIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBvcGFjaXR5PSIwLjU1Ii8+CiAgPHRleHQgeD0iMzIiIHk9IjM1IiBkb21pbmFudC1iYXNlbGluZT0ibWlkZGxlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmb250LWZhbWlseT0iQXJpYWwsIHNhbnMtc2VyaWYiIGZvbnQtc2l6ZT0iMjAiIGZvbnQtd2VpZ2h0PSI3MDAiIGZpbGw9IiNmZmZmZmYiPkE8L3RleHQ+Cjwvc3ZnPg==","seo":{"meta_title":"","meta_description":"","meta_keywords":""},"_ai_profile":{"version":4,"signature":"c9a6c800bec1b417b50cfe98df806e52b4b90de6","source_brief":"","managed_fields":{"site_title":true,"site_tagline":true,"brief_description":true,"logo":true,"icon":true}}},"workspace_track":"virtual_theme","extra_page_types_panel_open":0,"build_blueprint":{"version":1,"workspace_track":"virtual_theme","page_types":["home_page","about_page","contact_page","privacy_policy","terms_of_service","refund_policy","shipping_policy","cookie_policy","blog_post","blog_category","blog_list","custom_page"],"page_blueprints":{"home_page":{"page_type":"home_page","page_label":"首页","page_title":"首页","ai_description":"首页 的首页先讲清核心价值、主要亮点和下一步动作，让访客在第一屏就知道为什么值得继续浏览。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_title":"","meta_description":"首页 的首页先讲清核心价值、主要亮点和下一步动作，让访客在第一屏就知道为什么值得继续浏览。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_keywords":"首页, 该网站, 是一个品牌展示与业务转化站点, 内容重点围绕价值传达与下一步行动来组织, 整体表达强调清晰的信息架构、可信说明与明确行动入口","site_display_name":"","section_refinements":[],"sections":[{"key":"hero","code":"content\/home-page-hero","name":"首页 Hero","template":"hero","sort_order":10,"config":{"eyebrow":"首页","headline":"首页","description":"首页 的首页先讲清核心价值、主要亮点和下一步动作，让访客在第一屏就知道为什么值得继续浏览。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["用一句话讲清 当前站点 的核心价值与差异点","突出 内容与资讯运营站点 的主要亮点与适用场景","让访客迅速理解为什么现在就要继续了解或开始行动"],"primary_cta":"开始了解","secondary_note":""}},{"key":"highlights","code":"content\/home-page-highlights","name":"核心卖点","template":"cards","sort_order":20,"config":{"section_title":"核心卖点","section_intro":"把首页第一屏以下最值得点击的理由放出来。","items":[{"eyebrow":"01","title":"核心卖点","description":"用一句话讲清 当前站点 的核心价值与差异点"},{"eyebrow":"02","title":"投放场景","description":"突出 内容与资讯运营站点 的主要亮点与适用场景"},{"eyebrow":"03","title":"下载转化","description":"让访客迅速理解为什么现在就要继续了解或开始行动"}]}},{"key":"details","code":"content\/home-page-details","name":"转化路径","template":"checklist","sort_order":30,"config":{"section_title":"转化路径","section_intro":"让用户知道进入站点后应该先看什么、再做什么。","points":["突出 内容与资讯运营站点 的主要亮点与适用场景","让访客迅速理解为什么现在就要继续了解或开始行动","补足 清晰的信息架构、可信说明与明确行动入口","首屏与首屏下方都保留明确的转化入口"]}},{"key":"cta","code":"content\/home-page-cta","name":"开始承接流量","template":"cta","sort_order":40,"config":{"section_title":"开始承接流量","section_text":"把 APK 推广、站内信任感和转化动作串成一条明确路径。","button_label":"开始了解","assist_text":"支持在工作区继续微调这个区块。"}}]},"about_page":{"page_type":"about_page","page_label":"关于我们","page_title":"关于我们","ai_description":"关于我们 的关于页重点呈现品牌定位、团队能力、服务经验和长期投入，帮助访客快速建立信任。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_title":"关于我们 | ","meta_description":"关于我们 的关于页重点呈现品牌定位、团队能力、服务经验和长期投入，帮助访客快速建立信任。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_keywords":"关于我们, 该网站, 是一个品牌展示与业务转化站点, 内容重点围绕价值传达与下一步行动来组织, 整体表达强调清晰的信息架构、可信说明与明确行动入口","site_display_name":"","section_refinements":[],"sections":[{"key":"hero","code":"content\/about-page-hero","name":"关于我们 Hero","template":"hero","sort_order":10,"config":{"eyebrow":"关于我们","headline":"关于我们","description":"关于我们 的关于页重点呈现品牌定位、团队能力、服务经验和长期投入，帮助访客快速建立信任。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["说明品牌定位、团队背景和服务经验","解释为什么由 当前站点 来服务 目标用户","用里程碑、流程或方法论建立信任"],"primary_cta":"查看团队与能力","secondary_note":""}},{"key":"highlights","code":"content\/about-page-story","name":"品牌与团队","template":"cards","sort_order":20,"config":{"section_title":"品牌与团队","section_intro":"把故事、能力和差异化表达清楚。","items":[{"eyebrow":"01","title":"品牌起点","description":"说明品牌定位、团队背景和服务经验"},{"eyebrow":"02","title":"团队能力","description":"解释为什么由 当前站点 来服务 目标用户"},{"eyebrow":"03","title":"市场理解","description":"用里程碑、流程或方法论建立信任"}]}},{"key":"details","code":"content\/about-page-values","name":"为什么值得信任","template":"checklist","sort_order":30,"config":{"section_title":"为什么值得信任","section_intro":"用更稳妥的方式展示经验、规范和长期投入。","points":["解释为什么由 当前站点 来服务 目标用户","用里程碑、流程或方法论建立信任","把长期投入、稳定支持和差异化讲清楚","让关于页自然衔接到咨询或下一步动作"]}},{"key":"cta","code":"content\/about-page-cta","name":"继续了解合作方式","template":"cta","sort_order":40,"config":{"section_title":"继续了解合作方式","section_text":"关于页需要承接信任感，并把用户带去下一步动作。","button_label":"查看团队与能力","assist_text":"支持在工作区继续微调这个区块。"}}]},"contact_page":{"page_type":"contact_page","page_label":"联系我们","page_title":"联系我们","ai_description":"联系我们 的联系页要把咨询入口、响应预期和合作路径讲清楚，减少用户发起联系前的犹豫。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_title":"联系我们 | ","meta_description":"联系我们 的联系页要把咨询入口、响应预期和合作路径讲清楚，减少用户发起联系前的犹豫。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_keywords":"联系我们, 该网站, 是一个品牌展示与业务转化站点, 内容重点围绕价值传达与下一步行动来组织, 整体表达强调清晰的信息架构、可信说明与明确行动入口","site_display_name":"","section_refinements":[],"sections":[{"key":"hero","code":"content\/contact-page-hero","name":"联系我们 Hero","template":"hero","sort_order":10,"config":{"eyebrow":"联系我们","headline":"联系我们","description":"联系我们 的联系页要把咨询入口、响应预期和合作路径讲清楚，减少用户发起联系前的犹豫。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["把商务、客服与合作咨询入口拆分清楚","说明响应时效、处理流程和常见咨询方向","帮助用户快速判断该走哪条联系路径"],"primary_cta":"立即联系","secondary_note":""}},{"key":"highlights","code":"content\/contact-page-channels","name":"联系渠道","template":"cards","sort_order":20,"config":{"section_title":"联系渠道","section_intro":"把商务、客服和合作咨询的路径拆得足够直接。","items":[{"eyebrow":"01","title":"商务咨询","description":"把商务、客服与合作咨询入口拆分清楚"},{"eyebrow":"02","title":"客服支持","description":"说明响应时效、处理流程和常见咨询方向"},{"eyebrow":"03","title":"渠道合作","description":"帮助用户快速判断该走哪条联系路径"}]}},{"key":"details","code":"content\/contact-page-process","name":"响应预期","template":"checklist","sort_order":30,"config":{"section_title":"响应预期","section_intro":"让访客知道留言后会发生什么。","points":["说明响应时效、处理流程和常见咨询方向","帮助用户快速判断该走哪条联系路径","减少发起联系前的顾虑","用清晰的行动提示承接 内容沉淀、复访与关注转化"]}},{"key":"cta","code":"content\/contact-page-cta","name":"现在发起联系","template":"cta","sort_order":40,"config":{"section_title":"现在发起联系","section_text":"联系页的目标是缩短决策路径，避免用户找不到入口。","button_label":"立即联系","assist_text":"支持在工作区继续微调这个区块。"}}]},"privacy_policy":{"page_type":"privacy_policy","page_label":"隐私政策","page_title":"隐私政策","ai_description":"隐私政策 的隐私政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_title":"隐私政策 | ","meta_description":"隐私政策 的隐私政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_keywords":"隐私政策, 该网站, 是一个品牌展示与业务转化站点, 内容重点围绕价值传达与下一步行动来组织, 整体表达强调清晰的信息架构、可信说明与明确行动入口","site_display_name":"","section_refinements":[],"sections":[{"key":"hero","code":"content\/privacy-policy-hero","name":"隐私政策 Hero","template":"hero","sort_order":10,"config":{"eyebrow":"隐私政策","headline":"隐私政策","description":"隐私政策 的隐私政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["明确政策覆盖范围与适用对象","拆分用户权利、执行流程与更新时间","对第三方、Cookie、退款或配送边界做清楚说明"],"primary_cta":"联系支持","secondary_note":""}},{"key":"details","code":"content\/privacy-policy-coverage","name":"适用范围","template":"checklist","sort_order":20,"config":{"section_title":"适用范围","section_intro":"先把这份政策覆盖哪些数据、流程或责任说清楚。","points":["拆分用户权利、执行流程与更新时间","对第三方、Cookie、退款或配送边界做清楚说明","保持语言稳定、准确、便于查阅","预留联系与补充说明入口"]}},{"key":"details","code":"content\/privacy-policy-rights","name":"用户权利与执行","template":"checklist","sort_order":30,"config":{"section_title":"用户权利与执行","section_intro":"列出用户能做什么，以及站点如何响应。","points":["保持语言稳定、准确、便于查阅","预留联系与补充说明入口","该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构...","继续补充更具体的利益点、信任点和转化动作。"]}},{"key":"cta","code":"content\/privacy-policy-cta","name":"需要补充说明？","template":"cta","sort_order":40,"config":{"section_title":"需要补充说明？","section_text":"政策页也需要一个清晰的联系与更新说明。","button_label":"联系支持","assist_text":"支持在工作区继续微调这个区块。"}}]},"terms_of_service":{"page_type":"terms_of_service","page_label":"服务条款","page_title":"服务条款","ai_description":"服务条款 的服务条款需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_title":"服务条款 | ","meta_description":"服务条款 的服务条款需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_keywords":"服务条款, 该网站, 是一个品牌展示与业务转化站点, 内容重点围绕价值传达与下一步行动来组织, 整体表达强调清晰的信息架构、可信说明与明确行动入口","site_display_name":"","section_refinements":[],"sections":[{"key":"hero","code":"content\/terms-of-service-hero","name":"服务条款 Hero","template":"hero","sort_order":10,"config":{"eyebrow":"服务条款","headline":"服务条款","description":"服务条款 的服务条款需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["明确政策覆盖范围与适用对象","拆分用户权利、执行流程与更新时间","对第三方、Cookie、退款或配送边界做清楚说明"],"primary_cta":"联系支持","secondary_note":""}},{"key":"details","code":"content\/terms-of-service-coverage","name":"适用范围","template":"checklist","sort_order":20,"config":{"section_title":"适用范围","section_intro":"先把这份政策覆盖哪些数据、流程或责任说清楚。","points":["拆分用户权利、执行流程与更新时间","对第三方、Cookie、退款或配送边界做清楚说明","保持语言稳定、准确、便于查阅","预留联系与补充说明入口"]}},{"key":"details","code":"content\/terms-of-service-rights","name":"用户权利与执行","template":"checklist","sort_order":30,"config":{"section_title":"用户权利与执行","section_intro":"列出用户能做什么，以及站点如何响应。","points":["保持语言稳定、准确、便于查阅","预留联系与补充说明入口","该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构...","继续补充更具体的利益点、信任点和转化动作。"]}},{"key":"cta","code":"content\/terms-of-service-cta","name":"需要补充说明？","template":"cta","sort_order":40,"config":{"section_title":"需要补充说明？","section_text":"政策页也需要一个清晰的联系与更新说明。","button_label":"联系支持","assist_text":"支持在工作区继续微调这个区块。"}}]},"refund_policy":{"page_type":"refund_policy","page_label":"退款政策","page_title":"退款政策","ai_description":"退款政策 的退款政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_title":"退款政策 | ","meta_description":"退款政策 的退款政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_keywords":"退款政策, 该网站, 是一个品牌展示与业务转化站点, 内容重点围绕价值传达与下一步行动来组织, 整体表达强调清晰的信息架构、可信说明与明确行动入口","site_display_name":"","section_refinements":[],"sections":[{"key":"hero","code":"content\/refund-policy-hero","name":"退款政策 Hero","template":"hero","sort_order":10,"config":{"eyebrow":"退款政策","headline":"退款政策","description":"退款政策 的退款政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["明确政策覆盖范围与适用对象","拆分用户权利、执行流程与更新时间","对第三方、Cookie、退款或配送边界做清楚说明"],"primary_cta":"联系支持","secondary_note":""}},{"key":"details","code":"content\/refund-policy-coverage","name":"适用范围","template":"checklist","sort_order":20,"config":{"section_title":"适用范围","section_intro":"先把这份政策覆盖哪些数据、流程或责任说清楚。","points":["拆分用户权利、执行流程与更新时间","对第三方、Cookie、退款或配送边界做清楚说明","保持语言稳定、准确、便于查阅","预留联系与补充说明入口"]}},{"key":"details","code":"content\/refund-policy-rights","name":"用户权利与执行","template":"checklist","sort_order":30,"config":{"section_title":"用户权利与执行","section_intro":"列出用户能做什么，以及站点如何响应。","points":["保持语言稳定、准确、便于查阅","预留联系与补充说明入口","该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构...","继续补充更具体的利益点、信任点和转化动作。"]}},{"key":"cta","code":"content\/refund-policy-cta","name":"需要补充说明？","template":"cta","sort_order":40,"config":{"section_title":"需要补充说明？","section_text":"政策页也需要一个清晰的联系与更新说明。","button_label":"联系支持","assist_text":"支持在工作区继续微调这个区块。"}}]},"shipping_policy":{"page_type":"shipping_policy","page_label":"配送政策","page_title":"配送政策","ai_description":"配送政策 的配送政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_title":"配送政策 | ","meta_description":"配送政策 的配送政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_keywords":"配送政策, 该网站, 是一个品牌展示与业务转化站点, 内容重点围绕价值传达与下一步行动来组织, 整体表达强调清晰的信息架构、可信说明与明确行动入口","site_display_name":"","section_refinements":[],"sections":[{"key":"hero","code":"content\/shipping-policy-hero","name":"配送政策 Hero","template":"hero","sort_order":10,"config":{"eyebrow":"配送政策","headline":"配送政策","description":"配送政策 的配送政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["明确政策覆盖范围与适用对象","拆分用户权利、执行流程与更新时间","对第三方、Cookie、退款或配送边界做清楚说明"],"primary_cta":"联系支持","secondary_note":""}},{"key":"details","code":"content\/shipping-policy-coverage","name":"适用范围","template":"checklist","sort_order":20,"config":{"section_title":"适用范围","section_intro":"先把这份政策覆盖哪些数据、流程或责任说清楚。","points":["拆分用户权利、执行流程与更新时间","对第三方、Cookie、退款或配送边界做清楚说明","保持语言稳定、准确、便于查阅","预留联系与补充说明入口"]}},{"key":"details","code":"content\/shipping-policy-rights","name":"用户权利与执行","template":"checklist","sort_order":30,"config":{"section_title":"用户权利与执行","section_intro":"列出用户能做什么，以及站点如何响应。","points":["保持语言稳定、准确、便于查阅","预留联系与补充说明入口","该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构...","继续补充更具体的利益点、信任点和转化动作。"]}},{"key":"cta","code":"content\/shipping-policy-cta","name":"需要补充说明？","template":"cta","sort_order":40,"config":{"section_title":"需要补充说明？","section_text":"政策页也需要一个清晰的联系与更新说明。","button_label":"联系支持","assist_text":"支持在工作区继续微调这个区块。"}}]},"cookie_policy":{"page_type":"cookie_policy","page_label":"Cookie政策","page_title":"Cookie政策","ai_description":"Cookie政策 的Cookie政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_title":"Cookie政策 | ","meta_description":"Cookie政策 的Cookie政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_keywords":"Cookie政策, 该网站, 是一个品牌展示与业务转化站点, 内容重点围绕价值传达与下一步行动来组织, 整体表达强调清晰的信息架构、可信说明与明确行动入口","site_display_name":"","section_refinements":[],"sections":[{"key":"hero","code":"content\/cookie-policy-hero","name":"Cookie政策 Hero","template":"hero","sort_order":10,"config":{"eyebrow":"Cookie政策","headline":"Cookie政策","description":"Cookie政策 的Cookie政策需要清楚说明适用范围、执行方式和用户权利，语言应当稳定、明确、可追溯。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["明确政策覆盖范围与适用对象","拆分用户权利、执行流程与更新时间","对第三方、Cookie、退款或配送边界做清楚说明"],"primary_cta":"联系支持","secondary_note":""}},{"key":"details","code":"content\/cookie-policy-coverage","name":"适用范围","template":"checklist","sort_order":20,"config":{"section_title":"适用范围","section_intro":"先把这份政策覆盖哪些数据、流程或责任说清楚。","points":["拆分用户权利、执行流程与更新时间","对第三方、Cookie、退款或配送边界做清楚说明","保持语言稳定、准确、便于查阅","预留联系与补充说明入口"]}},{"key":"details","code":"content\/cookie-policy-rights","name":"用户权利与执行","template":"checklist","sort_order":30,"config":{"section_title":"用户权利与执行","section_intro":"列出用户能做什么，以及站点如何响应。","points":["保持语言稳定、准确、便于查阅","预留联系与补充说明入口","该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构...","继续补充更具体的利益点、信任点和转化动作。"]}},{"key":"cta","code":"content\/cookie-policy-cta","name":"需要补充说明？","template":"cta","sort_order":40,"config":{"section_title":"需要补充说明？","section_text":"政策页也需要一个清晰的联系与更新说明。","button_label":"联系支持","assist_text":"支持在工作区继续微调这个区块。"}}]},"blog_post":{"page_type":"blog_post","page_label":"博客文章","page_title":"博客文章","ai_description":"博客文章 的内容页要帮助访客理解栏目方向、文章重点和继续阅读路径，用内容承接信任与复访。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_title":"博客文章 | ","meta_description":"博客文章 的内容页要帮助访客理解栏目方向、文章重点和继续阅读路径，用内容承接信任与复访。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_keywords":"博客文章, 该网站, 是一个品牌展示与业务转化站点, 内容重点围绕价值传达与下一步行动来组织, 整体表达强调清晰的信息架构、可信说明与明确行动入口","site_display_name":"","section_refinements":[],"sections":[{"key":"hero","code":"content\/blog-post-hero","name":"博客文章 Hero","template":"hero","sort_order":10,"config":{"eyebrow":"博客文章","headline":"博客文章","description":"博客文章 的内容页要帮助访客理解栏目方向、文章重点和继续阅读路径，用内容承接信任与复访。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["围绕用户关心的主题组织栏目与文章结构","说明读者能获得什么信息或帮助","给出继续阅读、分类浏览和最新内容入口"],"primary_cta":"浏览更多内容","secondary_note":""}},{"key":"highlights","code":"content\/blog-post-topics","name":"内容主题","template":"cards","sort_order":20,"config":{"section_title":"内容主题","section_intro":"把博客页的栏目、主题或文章结构安排清楚。","items":[{"eyebrow":"01","title":"栏目聚焦","description":"围绕用户关心的主题组织栏目与文章结构"},{"eyebrow":"02","title":"读者收益","description":"说明读者能获得什么信息或帮助"},{"eyebrow":"03","title":"持续更新","description":"给出继续阅读、分类浏览和最新内容入口"}]}},{"key":"details","code":"content\/blog-post-structure","name":"阅读路径","template":"checklist","sort_order":30,"config":{"section_title":"阅读路径","section_intro":"让读者知道该从哪里开始，以及下一篇应该看什么。","points":["说明读者能获得什么信息或帮助","给出继续阅读、分类浏览和最新内容入口","用内容建立专业度与复访理由","兼顾阅读体验与后续转化"]}},{"key":"cta","code":"content\/blog-post-cta","name":"继续浏览内容","template":"cta","sort_order":40,"config":{"section_title":"继续浏览内容","section_text":"博客类页面也需要承接关注或转化。","button_label":"浏览更多内容","assist_text":"支持在工作区继续微调这个区块。"}}]},"blog_category":{"page_type":"blog_category","page_label":"博客分类","page_title":"博客分类","ai_description":"博客分类 的内容页要帮助访客理解栏目方向、文章重点和继续阅读路径，用内容承接信任与复访。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_title":"博客分类 | ","meta_description":"博客分类 的内容页要帮助访客理解栏目方向、文章重点和继续阅读路径，用内容承接信任与复访。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_keywords":"博客分类, 该网站, 是一个品牌展示与业务转化站点, 内容重点围绕价值传达与下一步行动来组织, 整体表达强调清晰的信息架构、可信说明与明确行动入口","site_display_name":"","section_refinements":[],"sections":[{"key":"hero","code":"content\/blog-category-hero","name":"博客分类 Hero","template":"hero","sort_order":10,"config":{"eyebrow":"博客分类","headline":"博客分类","description":"博客分类 的内容页要帮助访客理解栏目方向、文章重点和继续阅读路径，用内容承接信任与复访。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["围绕用户关心的主题组织栏目与文章结构","说明读者能获得什么信息或帮助","给出继续阅读、分类浏览和最新内容入口"],"primary_cta":"浏览更多内容","secondary_note":""}},{"key":"highlights","code":"content\/blog-category-topics","name":"内容主题","template":"cards","sort_order":20,"config":{"section_title":"内容主题","section_intro":"把博客页的栏目、主题或文章结构安排清楚。","items":[{"eyebrow":"01","title":"栏目聚焦","description":"围绕用户关心的主题组织栏目与文章结构"},{"eyebrow":"02","title":"读者收益","description":"说明读者能获得什么信息或帮助"},{"eyebrow":"03","title":"持续更新","description":"给出继续阅读、分类浏览和最新内容入口"}]}},{"key":"details","code":"content\/blog-category-structure","name":"阅读路径","template":"checklist","sort_order":30,"config":{"section_title":"阅读路径","section_intro":"让读者知道该从哪里开始，以及下一篇应该看什么。","points":["说明读者能获得什么信息或帮助","给出继续阅读、分类浏览和最新内容入口","用内容建立专业度与复访理由","兼顾阅读体验与后续转化"]}},{"key":"cta","code":"content\/blog-category-cta","name":"继续浏览内容","template":"cta","sort_order":40,"config":{"section_title":"继续浏览内容","section_text":"博客类页面也需要承接关注或转化。","button_label":"浏览更多内容","assist_text":"支持在工作区继续微调这个区块。"}}]},"blog_list":{"page_type":"blog_list","page_label":"博客列表","page_title":"博客列表","ai_description":"博客列表 的内容页要帮助访客理解栏目方向、文章重点和继续阅读路径，用内容承接信任与复访。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_title":"博客列表 | ","meta_description":"博客列表 的内容页要帮助访客理解栏目方向、文章重点和继续阅读路径，用内容承接信任与复访。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_keywords":"博客列表, 该网站, 是一个品牌展示与业务转化站点, 内容重点围绕价值传达与下一步行动来组织, 整体表达强调清晰的信息架构、可信说明与明确行动入口","site_display_name":"","section_refinements":[],"sections":[{"key":"hero","code":"content\/blog-list-hero","name":"博客列表 Hero","template":"hero","sort_order":10,"config":{"eyebrow":"博客列表","headline":"博客列表","description":"博客列表 的内容页要帮助访客理解栏目方向、文章重点和继续阅读路径，用内容承接信任与复访。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["围绕用户关心的主题组织栏目与文章结构","说明读者能获得什么信息或帮助","给出继续阅读、分类浏览和最新内容入口"],"primary_cta":"浏览更多内容","secondary_note":""}},{"key":"highlights","code":"content\/blog-list-topics","name":"内容主题","template":"cards","sort_order":20,"config":{"section_title":"内容主题","section_intro":"把博客页的栏目、主题或文章结构安排清楚。","items":[{"eyebrow":"01","title":"栏目聚焦","description":"围绕用户关心的主题组织栏目与文章结构"},{"eyebrow":"02","title":"读者收益","description":"说明读者能获得什么信息或帮助"},{"eyebrow":"03","title":"持续更新","description":"给出继续阅读、分类浏览和最新内容入口"}]}},{"key":"details","code":"content\/blog-list-structure","name":"阅读路径","template":"checklist","sort_order":30,"config":{"section_title":"阅读路径","section_intro":"让读者知道该从哪里开始，以及下一篇应该看什么。","points":["说明读者能获得什么信息或帮助","给出继续阅读、分类浏览和最新内容入口","用内容建立专业度与复访理由","兼顾阅读体验与后续转化"]}},{"key":"cta","code":"content\/blog-list-cta","name":"继续浏览内容","template":"cta","sort_order":40,"config":{"section_title":"继续浏览内容","section_text":"博客类页面也需要承接关注或转化。","button_label":"浏览更多内容","assist_text":"支持在工作区继续微调这个区块。"}}]},"custom_page":{"page_type":"custom_page","page_label":"自定义页面","page_title":"自定义页面","ai_description":"自定义页面 的自定义页面需要围绕页面目标组织完整内容，保证结构清楚、信息完整、行动入口明确。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_title":"自定义页面 | ","meta_description":"自定义页面 的自定义页面需要围绕页面目标组织完整内容，保证结构清楚、信息完整、行动入口明确。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","meta_keywords":"自定义页面, 该网站, 是一个品牌展示与业务转化站点, 内容重点围绕价值传达与下一步行动来组织, 整体表达强调清晰的信息架构、可信说明与明确行动入口","site_display_name":"","section_refinements":[],"sections":[{"key":"hero","code":"content\/custom-page-hero","name":"自定义页面 Hero","template":"hero","sort_order":10,"config":{"eyebrow":"自定义页面","headline":"自定义页面","description":"自定义页面 的自定义页面需要围绕页面目标组织完整内容，保证结构清楚、信息完整、行动入口明确。\n该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["围绕页面目标组织完整信息模块","把 内容与资讯运营站点 的重点内容拆成可编辑区块","保留清晰的行动入口与补充说明"],"primary_cta":"继续完善","secondary_note":""}},{"key":"highlights","code":"content\/custom-page-modules","name":"信息模块","template":"cards","sort_order":20,"config":{"section_title":"信息模块","section_intro":"把这一页需要承接的重点内容拆成可编辑的区块。","items":[{"eyebrow":"01","title":"内容重点","description":"围绕页面目标组织完整信息模块"},{"eyebrow":"02","title":"展示方式","description":"把 内容与资讯运营站点 的重点内容拆成可编辑区块"},{"eyebrow":"03","title":"转化动作","description":"保留清晰的行动入口与补充说明"}]}},{"key":"details","code":"content\/custom-page-steps","name":"继续完善建议","template":"checklist","sort_order":30,"config":{"section_title":"继续完善建议","section_intro":"先生成结构，再逐块微调。","points":["把 内容与资讯运营站点 的重点内容拆成可编辑区块","保留清晰的行动入口与补充说明","兼顾品牌表达、信任信息与实际转化","确保内容适合继续逐块微调"]}},{"key":"cta","code":"content\/custom-page-cta","name":"继续微调这一页","template":"cta","sort_order":40,"config":{"section_title":"继续微调这一页","section_text":"自定义页面更适合按区块迭代。","button_label":"继续完善","assist_text":"支持在工作区继续微调这个区块。"}}]}},"tasks":[{"task_key":"shared:header","task_type":"shared_component","scope_key":"shared_components.header","group_key":"shared","page_type":"","region":"header","label":"Header","sort_order":10},{"task_key":"shared:footer","task_type":"shared_component","scope_key":"shared_components.footer","group_key":"shared","page_type":"","region":"footer","label":"Footer","sort_order":20},{"task_key":"page:home_page:content\/home-page-hero","task_type":"page_section","scope_key":"page_sections.home_page.content\/home-page-hero","group_key":"home_page","page_type":"home_page","region":"content","section_code":"content\/home-page-hero","section_key":"hero","label":"首页 Hero","sort_order":1000},{"task_key":"page:home_page:content\/home-page-highlights","task_type":"page_section","scope_key":"page_sections.home_page.content\/home-page-highlights","group_key":"home_page","page_type":"home_page","region":"content","section_code":"content\/home-page-highlights","section_key":"highlights","label":"核心卖点","sort_order":1010},{"task_key":"page:home_page:content\/home-page-details","task_type":"page_section","scope_key":"page_sections.home_page.content\/home-page-details","group_key":"home_page","page_type":"home_page","region":"content","section_code":"content\/home-page-details","section_key":"details","label":"转化路径","sort_order":1020},{"task_key":"page:home_page:content\/home-page-cta","task_type":"page_section","scope_key":"page_sections.home_page.content\/home-page-cta","group_key":"home_page","page_type":"home_page","region":"content","section_code":"content\/home-page-cta","section_key":"cta","label":"开始承接流量","sort_order":1030},{"task_key":"page:about_page:content\/about-page-hero","task_type":"page_section","scope_key":"page_sections.about_page.content\/about-page-hero","group_key":"about_page","page_type":"about_page","region":"content","section_code":"content\/about-page-hero","section_key":"hero","label":"关于我们 Hero","sort_order":1100},{"task_key":"page:about_page:content\/about-page-story","task_type":"page_section","scope_key":"page_sections.about_page.content\/about-page-story","group_key":"about_page","page_type":"about_page","region":"content","section_code":"content\/about-page-story","section_key":"highlights","label":"品牌与团队","sort_order":1110},{"task_key":"page:about_page:content\/about-page-values","task_type":"page_section","scope_key":"page_sections.about_page.content\/about-page-values","group_key":"about_page","page_type":"about_page","region":"content","section_code":"content\/about-page-values","section_key":"details","label":"为什么值得信任","sort_order":1120},{"task_key":"page:about_page:content\/about-page-cta","task_type":"page_section","scope_key":"page_sections.about_page.content\/about-page-cta","group_key":"about_page","page_type":"about_page","region":"content","section_code":"content\/about-page-cta","section_key":"cta","label":"继续了解合作方式","sort_order":1130},{"task_key":"page:contact_page:content\/contact-page-hero","task_type":"page_section","scope_key":"page_sections.contact_page.content\/contact-page-hero","group_key":"contact_page","page_type":"contact_page","region":"content","section_code":"content\/contact-page-hero","section_key":"hero","label":"联系我们 Hero","sort_order":1200},{"task_key":"page:contact_page:content\/contact-page-channels","task_type":"page_section","scope_key":"page_sections.contact_page.content\/contact-page-channels","group_key":"contact_page","page_type":"contact_page","region":"content","section_code":"content\/contact-page-channels","section_key":"highlights","label":"联系渠道","sort_order":1210},{"task_key":"page:contact_page:content\/contact-page-process","task_type":"page_section","scope_key":"page_sections.contact_page.content\/contact-page-process","group_key":"contact_page","page_type":"contact_page","region":"content","section_code":"content\/contact-page-process","section_key":"details","label":"响应预期","sort_order":1220},{"task_key":"page:contact_page:content\/contact-page-cta","task_type":"page_section","scope_key":"page_sections.contact_page.content\/contact-page-cta","group_key":"contact_page","page_type":"contact_page","region":"content","section_code":"content\/contact-page-cta","section_key":"cta","label":"现在发起联系","sort_order":1230},{"task_key":"page:privacy_policy:content\/privacy-policy-hero","task_type":"page_section","scope_key":"page_sections.privacy_policy.content\/privacy-policy-hero","group_key":"privacy_policy","page_type":"privacy_policy","region":"content","section_code":"content\/privacy-policy-hero","section_key":"hero","label":"隐私政策 Hero","sort_order":1300},{"task_key":"page:privacy_policy:content\/privacy-policy-coverage","task_type":"page_section","scope_key":"page_sections.privacy_policy.content\/privacy-policy-coverage","group_key":"privacy_policy","page_type":"privacy_policy","region":"content","section_code":"content\/privacy-policy-coverage","section_key":"details","label":"适用范围","sort_order":1310},{"task_key":"page:privacy_policy:content\/privacy-policy-rights","task_type":"page_section","scope_key":"page_sections.privacy_policy.content\/privacy-policy-rights","group_key":"privacy_policy","page_type":"privacy_policy","region":"content","section_code":"content\/privacy-policy-rights","section_key":"details","label":"用户权利与执行","sort_order":1320},{"task_key":"page:privacy_policy:content\/privacy-policy-cta","task_type":"page_section","scope_key":"page_sections.privacy_policy.content\/privacy-policy-cta","group_key":"privacy_policy","page_type":"privacy_policy","region":"content","section_code":"content\/privacy-policy-cta","section_key":"cta","label":"需要补充说明？","sort_order":1330},{"task_key":"page:terms_of_service:content\/terms-of-service-hero","task_type":"page_section","scope_key":"page_sections.terms_of_service.content\/terms-of-service-hero","group_key":"terms_of_service","page_type":"terms_of_service","region":"content","section_code":"content\/terms-of-service-hero","section_key":"hero","label":"服务条款 Hero","sort_order":1400},{"task_key":"page:terms_of_service:content\/terms-of-service-coverage","task_type":"page_section","scope_key":"page_sections.terms_of_service.content\/terms-of-service-coverage","group_key":"terms_of_service","page_type":"terms_of_service","region":"content","section_code":"content\/terms-of-service-coverage","section_key":"details","label":"适用范围","sort_order":1410},{"task_key":"page:terms_of_service:content\/terms-of-service-rights","task_type":"page_section","scope_key":"page_sections.terms_of_service.content\/terms-of-service-rights","group_key":"terms_of_service","page_type":"terms_of_service","region":"content","section_code":"content\/terms-of-service-rights","section_key":"details","label":"用户权利与执行","sort_order":1420},{"task_key":"page:terms_of_service:content\/terms-of-service-cta","task_type":"page_section","scope_key":"page_sections.terms_of_service.content\/terms-of-service-cta","group_key":"terms_of_service","page_type":"terms_of_service","region":"content","section_code":"content\/terms-of-service-cta","section_key":"cta","label":"需要补充说明？","sort_order":1430},{"task_key":"page:refund_policy:content\/refund-policy-hero","task_type":"page_section","scope_key":"page_sections.refund_policy.content\/refund-policy-hero","group_key":"refund_policy","page_type":"refund_policy","region":"content","section_code":"content\/refund-policy-hero","section_key":"hero","label":"退款政策 Hero","sort_order":1500},{"task_key":"page:refund_policy:content\/refund-policy-coverage","task_type":"page_section","scope_key":"page_sections.refund_policy.content\/refund-policy-coverage","group_key":"refund_policy","page_type":"refund_policy","region":"content","section_code":"content\/refund-policy-coverage","section_key":"details","label":"适用范围","sort_order":1510},{"task_key":"page:refund_policy:content\/refund-policy-rights","task_type":"page_section","scope_key":"page_sections.refund_policy.content\/refund-policy-rights","group_key":"refund_policy","page_type":"refund_policy","region":"content","section_code":"content\/refund-policy-rights","section_key":"details","label":"用户权利与执行","sort_order":1520},{"task_key":"page:refund_policy:content\/refund-policy-cta","task_type":"page_section","scope_key":"page_sections.refund_policy.content\/refund-policy-cta","group_key":"refund_policy","page_type":"refund_policy","region":"content","section_code":"content\/refund-policy-cta","section_key":"cta","label":"需要补充说明？","sort_order":1530},{"task_key":"page:shipping_policy:content\/shipping-policy-hero","task_type":"page_section","scope_key":"page_sections.shipping_policy.content\/shipping-policy-hero","group_key":"shipping_policy","page_type":"shipping_policy","region":"content","section_code":"content\/shipping-policy-hero","section_key":"hero","label":"配送政策 Hero","sort_order":1600},{"task_key":"page:shipping_policy:content\/shipping-policy-coverage","task_type":"page_section","scope_key":"page_sections.shipping_policy.content\/shipping-policy-coverage","group_key":"shipping_policy","page_type":"shipping_policy","region":"content","section_code":"content\/shipping-policy-coverage","section_key":"details","label":"适用范围","sort_order":1610},{"task_key":"page:shipping_policy:content\/shipping-policy-rights","task_type":"page_section","scope_key":"page_sections.shipping_policy.content\/shipping-policy-rights","group_key":"shipping_policy","page_type":"shipping_policy","region":"content","section_code":"content\/shipping-policy-rights","section_key":"details","label":"用户权利与执行","sort_order":1620},{"task_key":"page:shipping_policy:content\/shipping-policy-cta","task_type":"page_section","scope_key":"page_sections.shipping_policy.content\/shipping-policy-cta","group_key":"shipping_policy","page_type":"shipping_policy","region":"content","section_code":"content\/shipping-policy-cta","section_key":"cta","label":"需要补充说明？","sort_order":1630},{"task_key":"page:cookie_policy:content\/cookie-policy-hero","task_type":"page_section","scope_key":"page_sections.cookie_policy.content\/cookie-policy-hero","group_key":"cookie_policy","page_type":"cookie_policy","region":"content","section_code":"content\/cookie-policy-hero","section_key":"hero","label":"Cookie政策 Hero","sort_order":1700},{"task_key":"page:cookie_policy:content\/cookie-policy-coverage","task_type":"page_section","scope_key":"page_sections.cookie_policy.content\/cookie-policy-coverage","group_key":"cookie_policy","page_type":"cookie_policy","region":"content","section_code":"content\/cookie-policy-coverage","section_key":"details","label":"适用范围","sort_order":1710},{"task_key":"page:cookie_policy:content\/cookie-policy-rights","task_type":"page_section","scope_key":"page_sections.cookie_policy.content\/cookie-policy-rights","group_key":"cookie_policy","page_type":"cookie_policy","region":"content","section_code":"content\/cookie-policy-rights","section_key":"details","label":"用户权利与执行","sort_order":1720},{"task_key":"page:cookie_policy:content\/cookie-policy-cta","task_type":"page_section","scope_key":"page_sections.cookie_policy.content\/cookie-policy-cta","group_key":"cookie_policy","page_type":"cookie_policy","region":"content","section_code":"content\/cookie-policy-cta","section_key":"cta","label":"需要补充说明？","sort_order":1730},{"task_key":"page:blog_post:content\/blog-post-hero","task_type":"page_section","scope_key":"page_sections.blog_post.content\/blog-post-hero","group_key":"blog_post","page_type":"blog_post","region":"content","section_code":"content\/blog-post-hero","section_key":"hero","label":"博客文章 Hero","sort_order":1800},{"task_key":"page:blog_post:content\/blog-post-topics","task_type":"page_section","scope_key":"page_sections.blog_post.content\/blog-post-topics","group_key":"blog_post","page_type":"blog_post","region":"content","section_code":"content\/blog-post-topics","section_key":"highlights","label":"内容主题","sort_order":1810},{"task_key":"page:blog_post:content\/blog-post-structure","task_type":"page_section","scope_key":"page_sections.blog_post.content\/blog-post-structure","group_key":"blog_post","page_type":"blog_post","region":"content","section_code":"content\/blog-post-structure","section_key":"details","label":"阅读路径","sort_order":1820},{"task_key":"page:blog_post:content\/blog-post-cta","task_type":"page_section","scope_key":"page_sections.blog_post.content\/blog-post-cta","group_key":"blog_post","page_type":"blog_post","region":"content","section_code":"content\/blog-post-cta","section_key":"cta","label":"继续浏览内容","sort_order":1830},{"task_key":"page:blog_category:content\/blog-category-hero","task_type":"page_section","scope_key":"page_sections.blog_category.content\/blog-category-hero","group_key":"blog_category","page_type":"blog_category","region":"content","section_code":"content\/blog-category-hero","section_key":"hero","label":"博客分类 Hero","sort_order":1900},{"task_key":"page:blog_category:content\/blog-category-topics","task_type":"page_section","scope_key":"page_sections.blog_category.content\/blog-category-topics","group_key":"blog_category","page_type":"blog_category","region":"content","section_code":"content\/blog-category-topics","section_key":"highlights","label":"内容主题","sort_order":1910},{"task_key":"page:blog_category:content\/blog-category-structure","task_type":"page_section","scope_key":"page_sections.blog_category.content\/blog-category-structure","group_key":"blog_category","page_type":"blog_category","region":"content","section_code":"content\/blog-category-structure","section_key":"details","label":"阅读路径","sort_order":1920},{"task_key":"page:blog_category:content\/blog-category-cta","task_type":"page_section","scope_key":"page_sections.blog_category.content\/blog-category-cta","group_key":"blog_category","page_type":"blog_category","region":"content","section_code":"content\/blog-category-cta","section_key":"cta","label":"继续浏览内容","sort_order":1930},{"task_key":"page:blog_list:content\/blog-list-hero","task_type":"page_section","scope_key":"page_sections.blog_list.content\/blog-list-hero","group_key":"blog_list","page_type":"blog_list","region":"content","section_code":"content\/blog-list-hero","section_key":"hero","label":"博客列表 Hero","sort_order":2000},{"task_key":"page:blog_list:content\/blog-list-topics","task_type":"page_section","scope_key":"page_sections.blog_list.content\/blog-list-topics","group_key":"blog_list","page_type":"blog_list","region":"content","section_code":"content\/blog-list-topics","section_key":"highlights","label":"内容主题","sort_order":2010},{"task_key":"page:blog_list:content\/blog-list-structure","task_type":"page_section","scope_key":"page_sections.blog_list.content\/blog-list-structure","group_key":"blog_list","page_type":"blog_list","region":"content","section_code":"content\/blog-list-structure","section_key":"details","label":"阅读路径","sort_order":2020},{"task_key":"page:blog_list:content\/blog-list-cta","task_type":"page_section","scope_key":"page_sections.blog_list.content\/blog-list-cta","group_key":"blog_list","page_type":"blog_list","region":"content","section_code":"content\/blog-list-cta","section_key":"cta","label":"继续浏览内容","sort_order":2030},{"task_key":"page:custom_page:content\/custom-page-hero","task_type":"page_section","scope_key":"page_sections.custom_page.content\/custom-page-hero","group_key":"custom_page","page_type":"custom_page","region":"content","section_code":"content\/custom-page-hero","section_key":"hero","label":"自定义页面 Hero","sort_order":2100},{"task_key":"page:custom_page:content\/custom-page-modules","task_type":"page_section","scope_key":"page_sections.custom_page.content\/custom-page-modules","group_key":"custom_page","page_type":"custom_page","region":"content","section_code":"content\/custom-page-modules","section_key":"highlights","label":"信息模块","sort_order":2110},{"task_key":"page:custom_page:content\/custom-page-steps","task_type":"page_section","scope_key":"page_sections.custom_page.content\/custom-page-steps","group_key":"custom_page","page_type":"custom_page","region":"content","section_code":"content\/custom-page-steps","section_key":"details","label":"继续完善建议","sort_order":2120},{"task_key":"page:custom_page:content\/custom-page-cta","task_type":"page_section","scope_key":"page_sections.custom_page.content\/custom-page-cta","group_key":"custom_page","page_type":"custom_page","region":"content","section_code":"content\/custom-page-cta","section_key":"cta","label":"继续微调这一页","sort_order":2130}],"signature":"d4290b2b5b85032c273128a1502e5dde66b1cf25"},"build_tasks":{"shared:header":{"task_key":"shared:header","task_type":"shared_component","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"shared:footer":{"task_key":"shared:footer","task_type":"shared_component","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:home_page:content\/home-page-hero":{"task_key":"page:home_page:content\/home-page-hero","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:home_page:content\/home-page-highlights":{"task_key":"page:home_page:content\/home-page-highlights","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:home_page:content\/home-page-details":{"task_key":"page:home_page:content\/home-page-details","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:home_page:content\/home-page-cta":{"task_key":"page:home_page:content\/home-page-cta","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:about_page:content\/about-page-hero":{"task_key":"page:about_page:content\/about-page-hero","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:about_page:content\/about-page-story":{"task_key":"page:about_page:content\/about-page-story","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:about_page:content\/about-page-values":{"task_key":"page:about_page:content\/about-page-values","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:about_page:content\/about-page-cta":{"task_key":"page:about_page:content\/about-page-cta","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:contact_page:content\/contact-page-hero":{"task_key":"page:contact_page:content\/contact-page-hero","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:contact_page:content\/contact-page-channels":{"task_key":"page:contact_page:content\/contact-page-channels","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:contact_page:content\/contact-page-process":{"task_key":"page:contact_page:content\/contact-page-process","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:contact_page:content\/contact-page-cta":{"task_key":"page:contact_page:content\/contact-page-cta","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:privacy_policy:content\/privacy-policy-hero":{"task_key":"page:privacy_policy:content\/privacy-policy-hero","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:privacy_policy:content\/privacy-policy-coverage":{"task_key":"page:privacy_policy:content\/privacy-policy-coverage","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:privacy_policy:content\/privacy-policy-rights":{"task_key":"page:privacy_policy:content\/privacy-policy-rights","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:privacy_policy:content\/privacy-policy-cta":{"task_key":"page:privacy_policy:content\/privacy-policy-cta","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:terms_of_service:content\/terms-of-service-hero":{"task_key":"page:terms_of_service:content\/terms-of-service-hero","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:terms_of_service:content\/terms-of-service-coverage":{"task_key":"page:terms_of_service:content\/terms-of-service-coverage","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:terms_of_service:content\/terms-of-service-rights":{"task_key":"page:terms_of_service:content\/terms-of-service-rights","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:terms_of_service:content\/terms-of-service-cta":{"task_key":"page:terms_of_service:content\/terms-of-service-cta","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:refund_policy:content\/refund-policy-hero":{"task_key":"page:refund_policy:content\/refund-policy-hero","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:refund_policy:content\/refund-policy-coverage":{"task_key":"page:refund_policy:content\/refund-policy-coverage","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:refund_policy:content\/refund-policy-rights":{"task_key":"page:refund_policy:content\/refund-policy-rights","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:refund_policy:content\/refund-policy-cta":{"task_key":"page:refund_policy:content\/refund-policy-cta","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:shipping_policy:content\/shipping-policy-hero":{"task_key":"page:shipping_policy:content\/shipping-policy-hero","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:shipping_policy:content\/shipping-policy-coverage":{"task_key":"page:shipping_policy:content\/shipping-policy-coverage","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:shipping_policy:content\/shipping-policy-rights":{"task_key":"page:shipping_policy:content\/shipping-policy-rights","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:shipping_policy:content\/shipping-policy-cta":{"task_key":"page:shipping_policy:content\/shipping-policy-cta","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:cookie_policy:content\/cookie-policy-hero":{"task_key":"page:cookie_policy:content\/cookie-policy-hero","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:cookie_policy:content\/cookie-policy-coverage":{"task_key":"page:cookie_policy:content\/cookie-policy-coverage","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:cookie_policy:content\/cookie-policy-rights":{"task_key":"page:cookie_policy:content\/cookie-policy-rights","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:cookie_policy:content\/cookie-policy-cta":{"task_key":"page:cookie_policy:content\/cookie-policy-cta","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:blog_post:content\/blog-post-hero":{"task_key":"page:blog_post:content\/blog-post-hero","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:blog_post:content\/blog-post-topics":{"task_key":"page:blog_post:content\/blog-post-topics","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:blog_post:content\/blog-post-structure":{"task_key":"page:blog_post:content\/blog-post-structure","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:blog_post:content\/blog-post-cta":{"task_key":"page:blog_post:content\/blog-post-cta","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:blog_category:content\/blog-category-hero":{"task_key":"page:blog_category:content\/blog-category-hero","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:blog_category:content\/blog-category-topics":{"task_key":"page:blog_category:content\/blog-category-topics","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:blog_category:content\/blog-category-structure":{"task_key":"page:blog_category:content\/blog-category-structure","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:blog_category:content\/blog-category-cta":{"task_key":"page:blog_category:content\/blog-category-cta","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:blog_list:content\/blog-list-hero":{"task_key":"page:blog_list:content\/blog-list-hero","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:blog_list:content\/blog-list-topics":{"task_key":"page:blog_list:content\/blog-list-topics","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:blog_list:content\/blog-list-structure":{"task_key":"page:blog_list:content\/blog-list-structure","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:blog_list:content\/blog-list-cta":{"task_key":"page:blog_list:content\/blog-list-cta","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:custom_page:content\/custom-page-hero":{"task_key":"page:custom_page:content\/custom-page-hero","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:custom_page:content\/custom-page-modules":{"task_key":"page:custom_page:content\/custom-page-modules","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:custom_page:content\/custom-page-steps":{"task_key":"page:custom_page:content\/custom-page-steps","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""},"page:custom_page:content\/custom-page-cta":{"task_key":"page:custom_page:content\/custom-page-cta","task_type":"page_section","status":"pending","attempt_no":0,"message":"","result_ref":[],"updated_at":"","started_at":"","finished_at":""}},"preview_full_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page","visual_preview_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page&visual_editor=1","visual_edit_url":"https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page","can_publish":0},"last_event_id":0};
    var hasGeneratedPages = true;
    var isOperationRunning = false;
    var currentActiveOperationState = [];
    /** 褰撳墠鏃犻〉闈㈢被鍨嬪閫夋鏃讹紙濡傚揩閫熸ā寮忋€屽噯澶囦笂绾裤€嶆楠わ級锛屽脊绐楃敤鏈嶅姟绔凡淇濆瓨绫诲瀷浣滀负鍒濆鍕鹃€?*/
    var serverPageTypesFallback = ["home_page","about_page","contact_page","privacy_policy","terms_of_service","refund_policy","shipping_policy","cookie_policy","blog_post","blog_category","blog_list","custom_page"];
    var pageTypesUserCustomized = true;
    var mergeScopeUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-merge-scope";
    var replaceScopeUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-replace-scope";
    var setStageUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-set-stage";
    var startPlanUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-start-plan";
    var confirmPlanUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-confirm-plan";
    var planSseUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/plan-sse";
    var startTaskPlanUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-start-task-plan";
    var confirmTaskPlanUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-confirm-task-plan";
    var taskPlanSseUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-task-plan-sse";
    var resumeBuildUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-resume-build";
    var runVirtualThemeUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-start-build";
    var startRegeneratePageUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-start-regenerate-page";
    var startRefineComponentUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-start-refine-component";
    var startBlockRefineSseUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-block-refine-sse";
    var startBlockRegenerateSseUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-block-regenerate-sse";
    var updateBlockConfigUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-update-block-config";
    var switchPreviewPageUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-switch-preview-page";
    var publishCheckUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/post-publish-checklist";
    var guidedUrl = "https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace?public_id=f1a364a3baaf768cb8e260b4d224aa2e";
    var previewFullUrl = "https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page";
    var visualPreviewUrl = "https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page&visual_editor=1";
    var visualEditBaseUrl = "https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/page\/virtual-edit?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page";
    var currentEmbeddedVisualUrl = "https:\/\/p11005ce4.weline.local\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=f1a364a3baaf768cb8e260b4d224aa2e&page_type=home_page&visual_editor=1";
    var currentPreviewPageType = "home_page";
    var currentPreviewPageId = 0;
    var currentPreviewDevice = 'desktop';
    var currentWorkspaceStage = "plan";
    var planConfirmedState = false;
    var hasVirtualThemePlanState = false;
    var taskPlanConfirmedState = false;
    var hasExecutionBlueprintState = false;
    var hasPendingBuildTasksState = true;
    /** 涓?PHP $pbAiPhaseOnePlanPresent 瀵归綈锛沨ydrate 鍚庢洿鏂帮紝鐢ㄤ簬绗簩闃舵銆岀敓鎴愮粏鑺備换鍔¤鍒掓柟妗堛€嶆寜閽?*/
    var lastPhaseOnePlanPresent = false;
    var globalRunVirtualThemeDisabled = false;
    window.__pbTaskSummary = {"total":50,"done":0,"pending":50,"running":0,"failed":0,"cancelled":0,"groups":{"shared":{"page_type":"","total":2,"done":0,"pending":2,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"shared:header","label":"Header","section_code":"","component":"","task_type":"shared_component","page_type":"","group_key":"shared","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"shared:footer","label":"Footer","section_code":"","component":"","task_type":"shared_component","page_type":"","group_key":"shared","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"home_page":{"page_type":"home_page","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:home_page:content\/home-page-hero","label":"首页 Hero","section_code":"content\/home-page-hero","component":"","task_type":"page_section","page_type":"home_page","group_key":"home_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:home_page:content\/home-page-highlights","label":"核心卖点","section_code":"content\/home-page-highlights","component":"","task_type":"page_section","page_type":"home_page","group_key":"home_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:home_page:content\/home-page-details","label":"转化路径","section_code":"content\/home-page-details","component":"","task_type":"page_section","page_type":"home_page","group_key":"home_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:home_page:content\/home-page-cta","label":"开始承接流量","section_code":"content\/home-page-cta","component":"","task_type":"page_section","page_type":"home_page","group_key":"home_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"about_page":{"page_type":"about_page","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:about_page:content\/about-page-hero","label":"关于我们 Hero","section_code":"content\/about-page-hero","component":"","task_type":"page_section","page_type":"about_page","group_key":"about_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:about_page:content\/about-page-story","label":"品牌与团队","section_code":"content\/about-page-story","component":"","task_type":"page_section","page_type":"about_page","group_key":"about_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:about_page:content\/about-page-values","label":"为什么值得信任","section_code":"content\/about-page-values","component":"","task_type":"page_section","page_type":"about_page","group_key":"about_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:about_page:content\/about-page-cta","label":"继续了解合作方式","section_code":"content\/about-page-cta","component":"","task_type":"page_section","page_type":"about_page","group_key":"about_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"contact_page":{"page_type":"contact_page","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:contact_page:content\/contact-page-hero","label":"联系我们 Hero","section_code":"content\/contact-page-hero","component":"","task_type":"page_section","page_type":"contact_page","group_key":"contact_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:contact_page:content\/contact-page-channels","label":"联系渠道","section_code":"content\/contact-page-channels","component":"","task_type":"page_section","page_type":"contact_page","group_key":"contact_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:contact_page:content\/contact-page-process","label":"响应预期","section_code":"content\/contact-page-process","component":"","task_type":"page_section","page_type":"contact_page","group_key":"contact_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:contact_page:content\/contact-page-cta","label":"现在发起联系","section_code":"content\/contact-page-cta","component":"","task_type":"page_section","page_type":"contact_page","group_key":"contact_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"privacy_policy":{"page_type":"privacy_policy","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:privacy_policy:content\/privacy-policy-hero","label":"隐私政策 Hero","section_code":"content\/privacy-policy-hero","component":"","task_type":"page_section","page_type":"privacy_policy","group_key":"privacy_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:privacy_policy:content\/privacy-policy-coverage","label":"适用范围","section_code":"content\/privacy-policy-coverage","component":"","task_type":"page_section","page_type":"privacy_policy","group_key":"privacy_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:privacy_policy:content\/privacy-policy-rights","label":"用户权利与执行","section_code":"content\/privacy-policy-rights","component":"","task_type":"page_section","page_type":"privacy_policy","group_key":"privacy_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:privacy_policy:content\/privacy-policy-cta","label":"需要补充说明？","section_code":"content\/privacy-policy-cta","component":"","task_type":"page_section","page_type":"privacy_policy","group_key":"privacy_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"terms_of_service":{"page_type":"terms_of_service","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:terms_of_service:content\/terms-of-service-hero","label":"服务条款 Hero","section_code":"content\/terms-of-service-hero","component":"","task_type":"page_section","page_type":"terms_of_service","group_key":"terms_of_service","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:terms_of_service:content\/terms-of-service-coverage","label":"适用范围","section_code":"content\/terms-of-service-coverage","component":"","task_type":"page_section","page_type":"terms_of_service","group_key":"terms_of_service","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:terms_of_service:content\/terms-of-service-rights","label":"用户权利与执行","section_code":"content\/terms-of-service-rights","component":"","task_type":"page_section","page_type":"terms_of_service","group_key":"terms_of_service","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:terms_of_service:content\/terms-of-service-cta","label":"需要补充说明？","section_code":"content\/terms-of-service-cta","component":"","task_type":"page_section","page_type":"terms_of_service","group_key":"terms_of_service","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"refund_policy":{"page_type":"refund_policy","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:refund_policy:content\/refund-policy-hero","label":"退款政策 Hero","section_code":"content\/refund-policy-hero","component":"","task_type":"page_section","page_type":"refund_policy","group_key":"refund_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:refund_policy:content\/refund-policy-coverage","label":"适用范围","section_code":"content\/refund-policy-coverage","component":"","task_type":"page_section","page_type":"refund_policy","group_key":"refund_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:refund_policy:content\/refund-policy-rights","label":"用户权利与执行","section_code":"content\/refund-policy-rights","component":"","task_type":"page_section","page_type":"refund_policy","group_key":"refund_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:refund_policy:content\/refund-policy-cta","label":"需要补充说明？","section_code":"content\/refund-policy-cta","component":"","task_type":"page_section","page_type":"refund_policy","group_key":"refund_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"shipping_policy":{"page_type":"shipping_policy","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:shipping_policy:content\/shipping-policy-hero","label":"配送政策 Hero","section_code":"content\/shipping-policy-hero","component":"","task_type":"page_section","page_type":"shipping_policy","group_key":"shipping_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:shipping_policy:content\/shipping-policy-coverage","label":"适用范围","section_code":"content\/shipping-policy-coverage","component":"","task_type":"page_section","page_type":"shipping_policy","group_key":"shipping_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:shipping_policy:content\/shipping-policy-rights","label":"用户权利与执行","section_code":"content\/shipping-policy-rights","component":"","task_type":"page_section","page_type":"shipping_policy","group_key":"shipping_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:shipping_policy:content\/shipping-policy-cta","label":"需要补充说明？","section_code":"content\/shipping-policy-cta","component":"","task_type":"page_section","page_type":"shipping_policy","group_key":"shipping_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"cookie_policy":{"page_type":"cookie_policy","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:cookie_policy:content\/cookie-policy-hero","label":"Cookie政策 Hero","section_code":"content\/cookie-policy-hero","component":"","task_type":"page_section","page_type":"cookie_policy","group_key":"cookie_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:cookie_policy:content\/cookie-policy-coverage","label":"适用范围","section_code":"content\/cookie-policy-coverage","component":"","task_type":"page_section","page_type":"cookie_policy","group_key":"cookie_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:cookie_policy:content\/cookie-policy-rights","label":"用户权利与执行","section_code":"content\/cookie-policy-rights","component":"","task_type":"page_section","page_type":"cookie_policy","group_key":"cookie_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:cookie_policy:content\/cookie-policy-cta","label":"需要补充说明？","section_code":"content\/cookie-policy-cta","component":"","task_type":"page_section","page_type":"cookie_policy","group_key":"cookie_policy","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"blog_post":{"page_type":"blog_post","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:blog_post:content\/blog-post-hero","label":"博客文章 Hero","section_code":"content\/blog-post-hero","component":"","task_type":"page_section","page_type":"blog_post","group_key":"blog_post","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_post:content\/blog-post-topics","label":"内容主题","section_code":"content\/blog-post-topics","component":"","task_type":"page_section","page_type":"blog_post","group_key":"blog_post","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_post:content\/blog-post-structure","label":"阅读路径","section_code":"content\/blog-post-structure","component":"","task_type":"page_section","page_type":"blog_post","group_key":"blog_post","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_post:content\/blog-post-cta","label":"继续浏览内容","section_code":"content\/blog-post-cta","component":"","task_type":"page_section","page_type":"blog_post","group_key":"blog_post","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"blog_category":{"page_type":"blog_category","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:blog_category:content\/blog-category-hero","label":"博客分类 Hero","section_code":"content\/blog-category-hero","component":"","task_type":"page_section","page_type":"blog_category","group_key":"blog_category","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_category:content\/blog-category-topics","label":"内容主题","section_code":"content\/blog-category-topics","component":"","task_type":"page_section","page_type":"blog_category","group_key":"blog_category","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_category:content\/blog-category-structure","label":"阅读路径","section_code":"content\/blog-category-structure","component":"","task_type":"page_section","page_type":"blog_category","group_key":"blog_category","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_category:content\/blog-category-cta","label":"继续浏览内容","section_code":"content\/blog-category-cta","component":"","task_type":"page_section","page_type":"blog_category","group_key":"blog_category","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"blog_list":{"page_type":"blog_list","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:blog_list:content\/blog-list-hero","label":"博客列表 Hero","section_code":"content\/blog-list-hero","component":"","task_type":"page_section","page_type":"blog_list","group_key":"blog_list","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_list:content\/blog-list-topics","label":"内容主题","section_code":"content\/blog-list-topics","component":"","task_type":"page_section","page_type":"blog_list","group_key":"blog_list","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_list:content\/blog-list-structure","label":"阅读路径","section_code":"content\/blog-list-structure","component":"","task_type":"page_section","page_type":"blog_list","group_key":"blog_list","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:blog_list:content\/blog-list-cta","label":"继续浏览内容","section_code":"content\/blog-list-cta","component":"","task_type":"page_section","page_type":"blog_list","group_key":"blog_list","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]},"custom_page":{"page_type":"custom_page","total":4,"done":0,"pending":4,"running":0,"failed":0,"cancelled":0,"tasks":[{"task_key":"page:custom_page:content\/custom-page-hero","label":"自定义页面 Hero","section_code":"content\/custom-page-hero","component":"","task_type":"page_section","page_type":"custom_page","group_key":"custom_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:custom_page:content\/custom-page-modules","label":"信息模块","section_code":"content\/custom-page-modules","component":"","task_type":"page_section","page_type":"custom_page","group_key":"custom_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:custom_page:content\/custom-page-steps","label":"继续完善建议","section_code":"content\/custom-page-steps","component":"","task_type":"page_section","page_type":"custom_page","group_key":"custom_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""},{"task_key":"page:custom_page:content\/custom-page-cta","label":"继续微调这一页","section_code":"content\/custom-page-cta","component":"","task_type":"page_section","page_type":"custom_page","group_key":"custom_page","status":"pending","attempt_no":0,"message":"","updated_at":"","finished_at":""}]}}};
    var buildNavigationGuardActive = false;
    var buildStartupPending = false;
    if (typeof window.__pbWorkspaceStreamPaused === 'undefined') {
        window.__pbWorkspaceStreamPaused = false;
    }
    var refineComponentState = {
        pageType: '',
        componentCode: '',
        componentLabel: '',
        refineStreaming: "AI refine started",
        regenerateStreaming: "AI rebuild started",
        blockSseUnavailable: "Block SSE terminal is not ready",
    };
    var blockEditorState = {
        pageType: '',
        blockId: '',
        blockType: '',
        blockLabel: '',
        blockConfig: {},
        fieldSchema: {}
    };
    // 鐢ㄤ簬鍖哄潡绾?SSE 鍒锋柊鐘舵€侊紙缁熶竴鎸傚埌 window锛岄伩鍏嶅 script 浣滅敤鍩熶涪澶憋級
    function getSharedBlockRefreshState() {
        if (!window.__pbBlockRefreshState || typeof window.__pbBlockRefreshState !== 'object') {
            window.__pbBlockRefreshState = {
                active: false,
                pageType: '',
                blockId: ''
            };
        }
        return window.__pbBlockRefreshState;
    }
    var blockRefreshState = getSharedBlockRefreshState();
    var pendingBlockSseResult = null;
    var virtualPagesByTypeState = {"home_page":{"blocks":[{"block_id":"home-page-site-header","type":"site_header","html":"<header class=\"ai-block ai-block-site-header\" style=\"padding:18px 28px;border-bottom:1px solid #e5e7eb;background:rgba(255,255,255,0.94);backdrop-filter:blur(12px);display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;\"><div style=\"display:grid;gap:4px;min-width:0;\"><strong style=\"font-size:18px;line-height:1.2;color:#0f172a;\"><\/strong><\/div><div style=\"display:flex;flex-wrap:wrap;gap:16px;align-items:center;\"><a href=\"\/\" style=\"color:#0f172a;font-size:14px;font-weight:700;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#475569;font-size:14px;font-weight:600;text-decoration:none;\">自定义页面<\/a><span style=\"display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">首页<\/span><\/div><\/header>","config":{"site_title":"","site_tagline":"","current_page_label":"首页","nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Header Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"site_tagline":{"type":"text","label":"Site Tagline"},"current_page_label":{"type":"text","label":"Current Page"},"nav_items":{"type":"textarea","label":"Header Links","format":"nav-items"}}}}},{"block_id":"home-page-hero","type":"hero","html":"<section class=\"ai-block ai-block-hero\" style=\"padding:56px 28px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);display:grid;gap:16px;\"><span style=\"font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">首页<\/span><h1 style=\"margin:0;font-size:40px;line-height:1.08;color:#0f172a;\">首页 Hero<\/h1><p style=\"margin:0;max-width:760px;font-size:18px;line-height:1.7;color:#475569;\">首页 的首页先讲清核心价值、主要亮点和下一步动作，让访客在第一屏就知道为什么值得继续浏览。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:10px;\"><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">首页<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">静态占位<\/span><span style=\"display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:#ffffff;border:1px solid #dbe3ee;color:#0f172a;font-size:13px;font-weight:600;\">可继续编辑<\/span><\/div><div><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#0f172a;color:#fff;font-size:14px;font-weight:700;\">继续完善<\/span><\/div><\/section>","config":{"eyebrow":"首页","headline":"首页 Hero","description":"首页 的首页先讲清核心价值、主要亮点和下一步动作，让访客在第一屏就知道为什么值得继续浏览。 该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。 整体表达强调清晰的信息架构、可信说明与明确行动入口。","chips":["首页","静态占位","可继续编辑"],"primary_cta":"继续完善","secondary_note":"AI 可用后可重新生成该区块"},"field_schema":{"content":{"label":"Content Fields","fields":{"eyebrow":{"type":"text","label":"Eyebrow"},"headline":{"type":"text","label":"Headline"},"description":{"type":"textarea","label":"Description"},"chips":{"type":"textarea","label":"Chips","format":"lines"},"primary_cta":{"type":"text","label":"Primary CTA"},"secondary_note":{"type":"text","label":"Secondary Note"}}}}},{"block_id":"home-page-highlights","type":"cards","html":"<section class=\"ai-block ai-block-cards\" style=\"padding:20px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#0f172a;\">核心卖点<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">把首页第一屏以下最值得点击的理由放出来。<\/p><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;\"><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 01<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">核心卖点<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">把首页第一屏以下最值得点击的理由放出来。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 02<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">当前已回退为静态内容<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。<\/p><\/article><article style=\"display:grid;gap:10px;padding:20px;border-radius:20px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2563eb;\">亮点 03<\/span><h3 style=\"margin:0;font-size:20px;line-height:1.2;color:#0f172a;\">首页<\/h3><p style=\"margin:0;font-size:15px;line-height:1.7;color:#475569;\">此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。<\/p><\/article><\/div><\/section>","config":{"section_title":"核心卖点","section_intro":"把首页第一屏以下最值得点击的理由放出来。","items":[{"eyebrow":"亮点 01","title":"核心卖点","description":"把首页第一屏以下最值得点击的理由放出来。"},{"eyebrow":"亮点 02","title":"当前已回退为静态内容","description":"AI 服务暂时不可用，你仍可继续预览、编辑并稍后重新生成。"},{"eyebrow":"亮点 03","title":"首页","description":"此占位区块会保留页面结构，避免工作台因 AI 账户问题中断。"}]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"items":{"type":"textarea","label":"Cards","format":"card-items"}}}}},{"block_id":"home-page-details","type":"checklist","html":"<section class=\"ai-block ai-block-checklist\" style=\"padding:8px 28px 36px;background:#ffffff;display:grid;gap:18px;\"><h2 style=\"margin:0;font-size:26px;line-height:1.25;color:#0f172a;\">转化路径<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:#475569;\">让用户知道进入站点后应该先看什么、再做什么。<\/p><div style=\"display:grid;gap:12px;\"><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">1<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">让用户知道进入站点后应该先看什么、再做什么。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">2<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">AI 服务暂不可用，已自动切换到静态占位内容。<\/p><\/div><div style=\"display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:16px 18px;border-radius:18px;background:#f8fafc;border:1px solid #e5e7eb;\"><span style=\"display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:700;\">3<\/span><p style=\"margin:0;font-size:15px;line-height:1.7;color:#334155;\">你可以先继续编辑区块，稍后再重新触发 AI 生成。<\/p><\/div><\/div><\/section>","config":{"section_title":"转化路径","section_intro":"让用户知道进入站点后应该先看什么、再做什么。","points":["让用户知道进入站点后应该先看什么、再做什么。","AI 服务暂不可用，已自动切换到静态占位内容。","你可以先继续编辑区块，稍后再重新触发 AI 生成。"]},"field_schema":{"content":{"label":"Content Fields","fields":{"section_title":{"type":"text","label":"Section Title"},"section_intro":{"type":"textarea","label":"Section Intro"},"points":{"type":"textarea","label":"Checklist Items","format":"lines"}}}}},{"block_id":"home-page-cta","type":"cta","html":"<section class=\"ai-block ai-block-cta\" style=\"padding:16px 28px 56px;background:linear-gradient(180deg,#ffffff 0%,#eef2ff 100%);\"><div style=\"padding:28px;border-radius:28px;background:#0f172a;color:#fff;display:grid;gap:14px;\"><h2 style=\"margin:0;font-size:28px;line-height:1.2;color:#fff;\">开始承接流量<\/h2><p style=\"margin:0;max-width:760px;font-size:16px;line-height:1.7;color:rgba(255,255,255,.82);\">把 APK 推广、站内信任感和转化动作串成一条明确路径。<\/p><div style=\"display:flex;flex-wrap:wrap;gap:12px;align-items:center;\"><span style=\"display:inline-flex;align-items:center;padding:12px 18px;border-radius:999px;background:#ffffff;color:#0f172a;font-size:14px;font-weight:700;\">继续编辑<\/span><\/div><\/div><\/section>","config":{"section_title":"开始承接流量","section_text":"把 APK 推广、站内信任感和转化动作串成一条明确路径。","button_label":"继续编辑","assist_text":""},"field_schema":{"content":{"label":"CTA Fields","fields":{"section_title":{"type":"text","label":"Title"},"section_text":{"type":"textarea","label":"Text"},"button_label":{"type":"text","label":"Button Label"},"assist_text":{"type":"text","label":"Assist Text"}}}}},{"block_id":"home-page-site-footer","type":"site_footer","html":"<footer class=\"ai-block ai-block-site-footer\" style=\"padding:28px;background:#020617;color:#e2e8f0;display:grid;gap:20px;\"><div style=\"display:grid;gap:8px;\"><strong style=\"font-size:18px;line-height:1.2;color:#fff;\"><\/strong><p style=\"margin:0;max-width:760px;font-size:14px;line-height:1.7;color:#94a3b8;\">该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。<\/p><\/div><div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px 24px;\"><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Featured Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">Policy Info<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><\/div><\/div><div style=\"display:grid;gap:10px;min-width:180px;\"><strong style=\"font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f8fafc;\">All Pages<\/strong><div style=\"display:grid;gap:8px;\"><a href=\"\/\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">首页<\/a><a href=\"\/about\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">关于我们<\/a><a href=\"\/contact\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">联系我们<\/a><a href=\"\/privacy\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">隐私政策<\/a><a href=\"\/terms\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">服务条款<\/a><a href=\"\/refund\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">退款政策<\/a><a href=\"\/shipping\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">配送政策<\/a><a href=\"\/cookies\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">Cookie政策<\/a><a href=\"\/blog\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">博客列表<\/a><a href=\"\/page\" style=\"color:#cbd5e1;font-size:13px;line-height:1.7;text-decoration:none;\">自定义页面<\/a><\/div><\/div><\/div><div style=\"display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;color:#64748b;font-size:12px;\"><span>漏 2026 <\/span><span>Always improving the visitor experience<\/span><\/div><\/footer>","config":{"site_title":"","brief_description":"该网站 是一个品牌展示与业务转化站点，内容重点围绕价值传达与下一步行动来组织。\n整体表达强调清晰的信息架构、可信说明与明确行动入口。","domain":"","links.column1_title":"Featured Pages","links.column1_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"links.column2_title":"Policy Info","links.column2_items":[{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false}],"links.column3_title":"All Pages","links.column3_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}],"nav_items":[{"label":"首页","href":"\/","active":true},{"label":"关于我们","href":"\/about","active":false},{"label":"联系我们","href":"\/contact","active":false},{"label":"隐私政策","href":"\/privacy","active":false},{"label":"服务条款","href":"\/terms","active":false},{"label":"退款政策","href":"\/refund","active":false},{"label":"配送政策","href":"\/shipping","active":false},{"label":"Cookie政策","href":"\/cookies","active":false},{"label":"博客列表","href":"\/blog","active":false},{"label":"自定义页面","href":"\/page","active":false}]},"field_schema":{"identity":{"label":"Footer Fields","fields":{"site_title":{"type":"text","label":"Site Title"},"brief_description":{"type":"textarea","label":"Footer Summary"},"domain":{"type":"text","label":"Domain"},"links.column1_title":{"type":"text","label":"Group 1 Title"},"links.column1_items":{"type":"textarea","label":"Group 1 Links","format":"nav-items"},"links.column2_title":{"type":"text","label":"Group 2 Title"},"links.column2_items":{"type":"textarea","label":"Group 2 Links","format":"nav-items"},"links.column3_title":{"type":"text","label":"Group 3 Title"},"links.column3_items":{"type":"textarea","label":"Group 3 Links","format":"nav-items"}}}}}]}};

    function resolvePreviewBridge() {
        return window.PbAiWorkspacePreview && typeof window.PbAiWorkspacePreview === 'object'
            ? window.PbAiWorkspacePreview
            : {};
    }
    var previewBridge = resolvePreviewBridge();

    function resolveTaskSummaryUpdater() {
        if (window.__pbWorkspaceApi && typeof window.__pbWorkspaceApi.updateTaskSummaryFromState === 'function') {
            return window.__pbWorkspaceApi.updateTaskSummaryFromState;
        }
        if (typeof window.updateTaskSummaryFromState === 'function') {
            return window.updateTaskSummaryFromState;
        }
        return function () {};
    }

    function normalizePlanSseUrl(url) {
        var normalized = String(url || '').trim();
        if (normalized === '') {
            return normalized;
        }
        return normalized.replace('/post-plan-sse', '/plan-sse');
    }
    // 闃叉鍦ㄥ嚱鏁板皻鏈祴鍊煎墠琚叾瀹冭剼鏈洿鎺ヨ皟鐢ㄥ鑷?ReferenceError
    if (typeof window.updateTaskSummaryFromState !== 'function') {
        window.updateTaskSummaryFromState = function () {};
    }
    var updateTaskSummaryFromState = window.updateTaskSummaryFromState;

    function isPlainObjectNonEmpty(obj) {
        return !!(obj && typeof obj === 'object' && !Array.isArray(obj) && Object.keys(obj).length > 0);
    }

    /**
     * 鏄惁涓?workspace.phtml 涓?$pbAiPhaseOnePlanPresent 涓€鑷达細浼氳瘽閲屾槸鍚﹀凡鏈夐樁娈典竴寤虹珯鏂规鍐呭銆?     */
    function phaseOnePlanPresentFromWorkspaceState(workspaceState) {
        if (!workspaceState || typeof workspaceState !== 'object') {
            return false;
        }
        if (workspaceState.plan_confirmed) {
            return true;
        }
        var planBlock = workspaceState.plan && typeof workspaceState.plan === 'object' ? workspaceState.plan : {};
        if (String(planBlock.markdown || '').trim() !== '') {
            return true;
        }
        if (isPlainObjectNonEmpty(planBlock.json)) {
            return true;
        }
        var sc = workspaceState.scope && typeof workspaceState.scope === 'object' ? workspaceState.scope : {};
        if (String(sc.plan_markdown || '').trim() !== '') {
            return true;
        }
        if (isPlainObjectNonEmpty(sc.plan_json)) {
            return true;
        }
        if (isPlainObjectNonEmpty(sc.plan_structured)) {
            return true;
        }
        return false;
    }

    function getRunVirtualThemeButtons() {
        return document.querySelectorAll('.pb-ai-run-virtual-theme');
    }

    function applyRunVirtualThemeButtonsDisabledState() {
        getRunVirtualThemeButtons().forEach(function (b) {
            b.disabled = globalRunVirtualThemeDisabled;
        });
        document.querySelectorAll('[data-pb-gate-phase-one="1"]').forEach(function (b) {
            var locked = globalRunVirtualThemeDisabled || !lastPhaseOnePlanPresent;
            if (b.classList && b.classList.contains('accordion-button')) {
                if (locked) {
                    b.setAttribute('disabled', 'disabled');
                    b.removeAttribute('data-bs-toggle');
                } else {
                    b.removeAttribute('disabled');
                    b.setAttribute('data-bs-toggle', 'collapse');
                }
            } else {
                b.disabled = locked;
            }
        });
        document.querySelectorAll('.pb-ai-phase-one-missing-hint').forEach(function (el) {
            el.classList.toggle('d-none', lastPhaseOnePlanPresent);
        });
    }

    function setRunVirtualThemeButtonsDisabled(disabled) {
        globalRunVirtualThemeDisabled = Boolean(disabled);
        applyRunVirtualThemeButtonsDisabledState();
    }

    window.__pbSyncRunVirtualThemeGateState = applyRunVirtualThemeButtonsDisabledState;

    function hydrateWorkspaceFromState(workspaceState) {
        if (!workspaceState || typeof workspaceState !== 'object') {
            return;
        }
        // 渚涢瑙堝唴閾炬帴閲嶅啓鍣ㄤ笌鍘嗗彶纭鏂规鍙棰勮澶嶇敤
        if (workspaceState.pagebuilder_pages_by_type && typeof workspaceState.pagebuilder_pages_by_type === 'object') {
            window.__pbWorkspacePagesByType = workspaceState.pagebuilder_pages_by_type;
        }
        var scope = workspaceState.scope && typeof workspaceState.scope === 'object' ? workspaceState.scope : {};
        window.__pbWorkspaceConfirmedPlan = {
            markdown: String(workspaceState.confirmed_plan_markdown || scope.plan_markdown || ''),
            plan_json: (scope.plan_json && typeof scope.plan_json === 'object') ? scope.plan_json : {},
            structured: (workspaceState.plan_structured && typeof workspaceState.plan_structured === 'object')
                ? workspaceState.plan_structured
                : ((scope.plan_structured && typeof scope.plan_structured === 'object') ? scope.plan_structured : {}),
            confirmed_at: String(workspaceState.plan_confirmed_at || scope.plan_confirmed_at || ''),
            signature: String(workspaceState.confirmed_plan_signature || scope.execution_blueprint_confirmed_signature || ''),
            plan_locale: String((scope.plan_locale || (scope.website_profile && scope.website_profile.plan_locale) || '')),
        };
        var workspaceTaskPlan = workspaceState.task_plan && typeof workspaceState.task_plan === 'object'
            ? workspaceState.task_plan
            : {};
        var vtPlan = scope.virtual_theme_plan && typeof scope.virtual_theme_plan === 'object'
            ? scope.virtual_theme_plan
            : (workspaceTaskPlan.virtual_theme_plan && typeof workspaceTaskPlan.virtual_theme_plan === 'object' ? workspaceTaskPlan.virtual_theme_plan : {});
        var persistedTaskPlanStructured = (workspaceState.task_plan_structured && typeof workspaceState.task_plan_structured === 'object')
            ? workspaceState.task_plan_structured
            : ((scope.task_plan_structured && typeof scope.task_plan_structured === 'object')
                ? scope.task_plan_structured
                : {});
        window.__pbWorkspaceConfirmedTaskPlan = {
            markdown: String(vtPlan.confirmed_markdown || ''),
            confirmed_markdown: String(vtPlan.confirmed_markdown || ''),
            confirmed_at: String(workspaceState.task_plan_confirmed_at || vtPlan.confirmed_at || ''),
            signature: String(vtPlan.confirmed_signature || vtPlan.plan_signature || ''),
            task_plan_signature: String(vtPlan.confirmed_signature || vtPlan.plan_signature || ''),
            plan_locale: String((scope.plan_locale || (scope.website_profile && scope.website_profile.plan_locale) || '')),
            structured: isNonEmptyObject(persistedTaskPlanStructured)
                ? persistedTaskPlanStructured
                : (vtPlan.confirmed && typeof vtPlan.confirmed === 'object' ? vtPlan.confirmed : {}),
        };
        var taskPlanDraftMarkdown = String(workspaceTaskPlan.markdown || workspaceState.task_plan_markdown || vtPlan.draft_markdown || '').trim();
        var taskPlanConfirmedMarkdown = String(vtPlan.confirmed_markdown || '').trim();
        var taskPlanDraftStructured = isNonEmptyObject(persistedTaskPlanStructured)
            ? persistedTaskPlanStructured
            : ((workspaceTaskPlan.structured && typeof workspaceTaskPlan.structured === 'object')
                ? workspaceTaskPlan.structured
                : (vtPlan.draft && typeof vtPlan.draft === 'object' ? vtPlan.draft : {}));
        var taskPlanConfirmedStructured = vtPlan.confirmed && typeof vtPlan.confirmed === 'object' ? vtPlan.confirmed : {};
        if (
            taskPlanDraftMarkdown !== ''
            || taskPlanConfirmedMarkdown !== ''
            || isNonEmptyObject(taskPlanDraftStructured)
            || isNonEmptyObject(taskPlanConfirmedStructured)
        ) {
            currentTaskPlanPayload = Object.assign({}, currentTaskPlanPayload || {}, {
                markdown: taskPlanDraftMarkdown !== '' ? taskPlanDraftMarkdown : taskPlanConfirmedMarkdown,
                structured: isNonEmptyObject(taskPlanDraftStructured) ? taskPlanDraftStructured : taskPlanConfirmedStructured,
                virtual_theme_plan: Object.assign({}, vtPlan)
            });
        }
        syncGuidedProfileDefaultsFromWorkspaceState(workspaceState);
        if (workspaceState.plan && typeof workspaceState.plan === 'object') {
            currentPlanPayload = workspaceState.plan;
            if (typeof updatePlanModalContent === 'function') {
                var planMarkdown = String((workspaceState.plan.markdown || '')).trim();
                if (!planMarkdown) {
                    planMarkdown = String((scope.plan_markdown || '')).trim();
                }
                updatePlanModalContent(planMarkdown);
            }
        } else {
            var fallbackPlanMarkdown = String(scope.plan_markdown || '').trim();
            var fallbackPlanJson = (scope.plan_json && typeof scope.plan_json === 'object') ? scope.plan_json : {};
            var fallbackStructured = (scope.plan_structured && typeof scope.plan_structured === 'object') ? scope.plan_structured : {};
            if (fallbackPlanMarkdown !== '' || isNonEmptyObject(fallbackPlanJson) || isNonEmptyObject(fallbackStructured)) {
                currentPlanPayload = Object.assign({}, currentPlanPayload || {}, {
                    markdown: fallbackPlanMarkdown,
                    json: fallbackPlanJson,
                    structured: fallbackStructured
                });
                if (typeof updatePlanModalContent === 'function') {
                    updatePlanModalContent(fallbackPlanMarkdown);
                }
            }
        }
        if (Object.prototype.hasOwnProperty.call(workspaceState, 'plan_confirmed')) {
            planConfirmedState = !!workspaceState.plan_confirmed;
        }
        if (Object.prototype.hasOwnProperty.call(workspaceState, 'plan_sse_url')) {
            planSseUrl = normalizePlanSseUrl(workspaceState.plan_sse_url || '');
        }
        if (Object.prototype.hasOwnProperty.call(workspaceState, 'has_virtual_theme_plan')) {
            hasVirtualThemePlanState = !!workspaceState.has_virtual_theme_plan;
        }
        if (Object.prototype.hasOwnProperty.call(workspaceState, 'task_plan_confirmed')) {
            taskPlanConfirmedState = !!workspaceState.task_plan_confirmed;
        }
        if (Object.prototype.hasOwnProperty.call(workspaceState, 'has_execution_blueprint')) {
            hasExecutionBlueprintState = !!workspaceState.has_execution_blueprint;
        }
        if (Object.prototype.hasOwnProperty.call(workspaceState, 'has_pending_build_tasks')) {
            hasPendingBuildTasksState = !!workspaceState.has_pending_build_tasks;
        }
        if (Object.prototype.hasOwnProperty.call(workspaceState, 'active_operation') && workspaceState.active_operation && typeof workspaceState.active_operation === 'object') {
            currentActiveOperationState = workspaceState.active_operation;
            var activeStatus = String(workspaceState.active_operation.status || '').toLowerCase();
            isOperationRunning = (activeStatus === 'queued' || activeStatus === 'running');
        }
        if (Object.prototype.hasOwnProperty.call(workspaceState, 'task_plan_sse_url')) {
            taskPlanSseUrl = String(workspaceState.task_plan_sse_url || '');
        } else if (Object.prototype.hasOwnProperty.call(workspaceState, 'task_plan_stream_sse_url')) {
            taskPlanSseUrl = String(workspaceState.task_plan_stream_sse_url || '');
        }
        syncWorkspacePageTypeState(workspaceState);
        resolveTaskSummaryUpdater()(workspaceState);
        if (window.__pbWorkspaceApi && typeof window.__pbWorkspaceApi.updateStageStatusSummary === 'function') {
            window.__pbWorkspaceApi.updateStageStatusSummary(workspaceState);
        }
        if (typeof previewBridge.syncPreviewMetaFromState === 'function') {
            previewBridge.syncPreviewMetaFromState(workspaceState);
        }
        if (workspaceState.stage && typeof previewBridge.switchWorkspaceStage === 'function') {
            previewBridge.switchWorkspaceStage(String(workspaceState.stage));
        }
        if (typeof previewBridge.bindEmbeddedPreviewFrameBridge === 'function') {
            previewBridge.bindEmbeddedPreviewFrameBridge();
        }
        lastPhaseOnePlanPresent = phaseOnePlanPresentFromWorkspaceState(workspaceState);
        if (typeof applyRunVirtualThemeButtonsDisabledState === 'function') {
            applyRunVirtualThemeButtonsDisabledState();
        }
        syncConfirmPlanButtonEnabled();
    }
    // 缁熶竴瀵瑰 API锛氳法 script 璋冪敤缁熶竴璧?window.__pbWorkspaceApi
    var workspaceApi = window.__pbWorkspaceApi;
    if (!workspaceApi || typeof workspaceApi !== 'object') {
        workspaceApi = {};
    }
    workspaceApi.hydrateWorkspaceFromState = hydrateWorkspaceFromState;
    workspaceApi.getPlanConfirmedState = function () {
        return !!planConfirmedState;
    };
    workspaceApi.getHasVirtualThemePlanState = function () {
        return !!hasVirtualThemePlanState;
    };
    workspaceApi.getTaskPlanConfirmedState = function () {
        return !!taskPlanConfirmedState;
    };
    workspaceApi.getHasExecutionBlueprintState = function () {
        return !!hasExecutionBlueprintState;
    };
    workspaceApi.getHasPendingBuildTasksState = function () {
        return !!hasPendingBuildTasksState;
    };
    // 鏆撮湶鏂规娓叉煋鍑芥暟缁?stream/runtime 鑴氭湰锛岀‘淇?SSE chunk 鍒拌揪鏃?Markdown 涓庨瑙堝悓姝ュ埛鏂?
    workspaceApi.updatePlanModalContent = function (markdown) {
        return updatePlanModalContent(markdown);
    };

    function hasCurrentPhaseOnePlanDraft() {
        var payload = currentPlanPayload && typeof currentPlanPayload === 'object' ? currentPlanPayload : {};
        if (String(payload.markdown || '').trim() !== '') {
            return true;
        }
        if (isNonEmptyObject(payload.json)) {
            return true;
        }
        if (isNonEmptyObject(payload.structured)) {
            return true;
        }
        var scopePlan = (window.__pbWorkspaceConfirmedPlan && typeof window.__pbWorkspaceConfirmedPlan === 'object')
            ? window.__pbWorkspaceConfirmedPlan
            : {};
        if (String(scopePlan.markdown || '').trim() !== '') {
            return true;
        }
        if (isNonEmptyObject(scopePlan.plan_json) || isNonEmptyObject(scopePlan.structured)) {
            return true;
        }
        return false;
    }

    function syncConfirmPlanButtonEnabled() {
        var confirmBtn = document.getElementById('pb-ai-confirm-plan');
        if (!confirmBtn) {
            return;
        }
        confirmBtn.disabled = !hasCurrentPhaseOnePlanDraft();
    }
    /**
     * 宸ヤ綔鍖?stream-sse 鍦ㄩ樁娈典竴 plan_generated 绛変簨浠跺悗鍥炶皟锛氳嚜鍔ㄤ繚瀛?scope 骞惰В閿併€岀‘璁ら樁娈典竴鏂规銆嶃€?     */
    workspaceApi.applyPlanGenerationCompletionFromWorkspaceStream = function (serverState) {
        try {
            planSseRunning = false;
            if (typeof setPlanModeRunButtonLoading === 'function') {
                setPlanModeRunButtonLoading(false);
            }
            if (typeof setPlanModeStatus === 'function') {
                setPlanModeStatus(messages.planModeDone);
            }
            var md = '';
            if (serverState && typeof serverState === 'object') {
                if (serverState.plan && typeof serverState.plan === 'object' && serverState.plan.markdown) {
                    md = String(serverState.plan.markdown || '');
                }
                if (md.trim() === '' && serverState.scope && typeof serverState.scope === 'object') {
                    md = String(serverState.scope.plan_markdown || '');
                }
            }
            if (md.trim() === '') {
                md = String((currentPlanPayload && currentPlanPayload.markdown) || '');
            }
            if (md.trim() !== '' && typeof updatePlanModalContent === 'function') {
                updatePlanModalContent(md);
            }
            var latestMd = String((currentPlanPayload && currentPlanPayload.markdown) || '').trim();
            if (latestMd === '') {
                var mdEl = document.getElementById('pb-ai-plan-md-content');
                latestMd = String(mdEl && mdEl.textContent ? mdEl.textContent : '').trim();
            }
            var confirmEnabled = !planConfirmedState && (latestMd !== '' || hasCurrentPhaseOnePlanDraft());
            if (typeof setPlanModalProgress === 'function') {
                setPlanModalProgress(messages.planModeDone, 100, confirmEnabled);
            }
            if (typeof setPlanRetryButtonVisible === 'function') {
                setPlanRetryButtonVisible(true);
            }
            if (typeof autoSaveScope === 'function') {
                autoSaveScope();
            }
        } catch (e) {
            console.warn('[workspace] applyPlanGenerationCompletionFromWorkspaceStream', e);
        }
    };
    window.__pbWorkspaceApi = workspaceApi;
    // 鍚戝悗鍏煎鏃ц皟鐢ㄥ叆鍙?
    window.hydrateWorkspaceFromState = function (workspaceState) {
        return window.__pbWorkspaceApi.hydrateWorkspaceFromState(workspaceState);
    };
    // 鍏煎鍘嗗彶鐩存帴璋冪敤 window.updatePlanModalContent 鐨勯€昏緫
    window.updatePlanModalContent = function (markdown) {
        return updatePlanModalContent(markdown);
    };
    
    function mergeVirtualPagesByTypeState(pages) {
        if (!pages || typeof pages !== 'object') {
            return;
        }
        var merged = {};
        if (virtualPagesByTypeState && typeof virtualPagesByTypeState === 'object') {
            Object.keys(virtualPagesByTypeState).forEach(function (pageType) {
                merged[pageType] = virtualPagesByTypeState[pageType];
            });
        }
        Object.keys(pages).forEach(function (pageType) {
            merged[pageType] = pages[pageType];
        });
        virtualPagesByTypeState = merged;
    }
    var messages = {
        networkError: "缃戠粶閿欒锛岃绋嶅悗閲嶈瘯",
        saveSuccess: "",
        saveFailed: "鑷姩淇濆瓨澶辫触",
        orchestrated: "",
        switched: "棰勮椤靛凡鍒囨崲",
        visualPreviewUnreachable: "",
        visualPreviewFrameTitle: "",
        noPageType: "",
        briefRequired: "",
        siteTitleRequired: "",
        domainRequiredForBuild: "",
        planStartUnavailable: "",
        planSaving: "",
        planGenerating: "",
        planRegeneratingByPageTypes: "",
        planRegeneratingByLocale: "",
        planRegeneratingByPageTypesAndLocale: "",
        planGenerated: "",
        planConfirmRequired: "",
        phaseOnePlanMissingForTaskPlan: "",
        planConfirmSaved: "",
        planConfirmStartBuildQuestion: "闃舵涓€鏂规宸蹭繚瀛橈紝鏄惁杩涘叆闃舵浜屼换鍔¤鍒掔‘璁わ紵",
        planDeferred: "",
        planDraftSaved: "",
        planDraftSaveFailed: "",
        planModeRefine: "寰皟鏂规",
        planModeRebuild: "閲嶅缓鏂规",
        planModeReady: "",
        planModeUnavailable: "",
        planModeRunning: "",
        planModeDone: "",
        planFriendlyInvalidAiJson: "",
        planStructuredPreviewTitle: "",
        planStructuredPreviewEmpty: "",
        planStructuredPreviewNote: "",
        taskPlanStartUnavailable: "",
        taskPlanGenerating: "",
        taskPlanDetecting: "",
        taskPlanGenerated: "",
        taskPlanConfirmRequired: "",
        taskPlanConfirmSaved: "",
        taskPlanConfirmStartBuildQuestion: "闃舵浜屼换鍔¤鍒掑凡淇濆瓨锛屾槸鍚︾珛鍗冲紑濮嬫瀯寤猴紵",
        continueGenerateNow: "缁х画鐢熸垚",
        continueGenerateLater: "绋嶅悗缁х画",
        immediateGenerate: "绔嬪嵆鐢熸垚",
        laterGenerate: "绋嶅悗鐢熸垚",
        taskPlanDeferred: "",
        taskPlanRequiredByBuild: "",
        taskPlanDetailRequiredBeforeAiGenerate: "",
        taskPlanModeRefine: "寰皟浠诲姟鏂规",
        taskPlanModeRebuild: "閲嶅缓浠诲姟鏂规",
        taskPlanModeReady: "",
        taskPlanModeUnavailable: "",
        taskPlanModeRunning: "",
        taskPlanModeDone: "",
        taskPlanModeBootstrapRequired: "",
        taskPlanModeBootstrapFailed: "",
        taskPlanGenerateConfirmTitle: "鐢熸垚绗簩闃舵浠诲姟鏂规",
        taskPlanGenerateConfirmMessage: "",
        taskPlanGenerateCancelled: "",
        taskPlanDraftSaved: "",
        taskPlanDraftSaveFailed: "",
        taskPlanRunningMutationWarningTitle: "妫€娴嬪埌浠诲姟姝ｅ湪杩愯",
        taskPlanRunningMutationWarningMessage: "",
        resumePromptTitle: "",
        resumeRunningPrompt: "妫€娴嬪埌鏈夋鍦ㄦ墽琛岀殑宸ヤ綔鍖烘搷浣滐紝鏄惁缁х画瑙傚療鎵ц娴侊紵",
        resumePendingPrompt: "",
        resumeDeferred: "",
        resumeStarted: "",
        operationStreamMissing: "",
        missingRunVirtualTheme: "",
        autoGenerateNeeded: "",
        generating: "",
        waiting: "",
        generated: "",
        focusEmbeddedPreview: "",
        refineInstructionRequired: "璇峰厛杈撳叆鍖哄潡寰皟瑕佹眰",
        refineUnavailable: "褰撳墠鍖哄潡鏆傛椂鏃犳硶鍙戣捣 AI 寰皟",
        refineQueued: "宸叉彁浜ゅ尯鍧楀井璋冿紝姝ｅ湪閲嶆柊鐢熸垚褰撳墠椤甸潰",
        refineContextFallback: "",
        editUnavailable: "",
        editSaved: "鍖哄潡瀛楁宸叉洿鏂帮紝宸插埛鏂板綋鍓嶅潡",
        blockSseDoneConfirm: "",
        blockSseNoResult: "",
        blockSseApplied: "",
        buildPreparing: "",
        buildReady: "",
        operationDuplicateStream: "",
        leaveDuringBuild: "",
        domainNeedBrief: "",
        domainNeedRegistrar: "",
        domainRecommendLoading: "",
        domainRecommendEmpty: "",
        domainCheckNeedRegistrar: "",
        domainConfirmCheckFailed: "",
        domainPurchaseFailed: "璐拱鍩熷悕澶辫触",
        domainPurchaseDialogLoadFailed: "",
        domainPurchaseQueued: "",
        domainPurchased: "",
        publishStartUnavailable: "",
        publishChecking: "",
        publishRunning: "",
        publishPreviewUnavailable: "",
    };
    var previewLabels = {
        visualPreviewEmpty: "暂无可预览页面，请先完成页面生成或选择预览页。",
        field: "字段",
        sample: "示例",
        reason: "原因",
        featurePoints: "执行要点",
        mediaAssets: "素材建议",
        palette: "色系",
        styleTone: "风格",
        typography: "字体与排版",
        themeSummary: "主题规划",
        stageToolbarHint: "悬停分块后可直接微调、重建、删除或新增块；也可以对整个阶段重新生成。",
        refineStage: "整体微调",
        rebuildStage: "完全重新生成",
        primaryKeywords: "主关键词",
        secondaryKeywords: "次关键词",
        noBlocks: "暂无块规划",
        pageGoal: "页面目标",
        blockGoal: "块目标",
        scriptScene: "脚本场景",
        scriptGoal: "脚本目标",
        fillRule: "内容填充规则",
        acceptance: "验收标准",
        stage3Directive: "第三阶段执行指令",
        taskScriptBrief: "任务脚本概览",
        sharedTasks: "共享任务",
        tasksUnit: "项任务",
        riskNotes: "风险提示",
        taskPlanHint: "以下为第二阶段块任务预览，可直接对单块或整阶段进行操作。",
        headerPlan: "页头规划",
        footerPlan: "页脚规划",
        publishCheckPassed: "发布检查通过",
        publishCheckFailed: "发布检查未通过，请先处理阻塞项。"    };

    var lastToastByKey = {};
    function normalizeToastMessage(message) {
        var text = String(message || '');
        var lower = text.toLowerCase();
        if (lower.indexOf('insufficient balance') >= 0 || lower.indexOf('http 402') >= 0) {
            return "";
        }
        if (lower.indexOf('ai component generation failed') >= 0) {
            return "";
        }
        return text;
    }

    // 鍏煎鍘嗗彶鎷煎啓锛坣ormalizetoastMessage锛夛紝閬垮厤鏃ч棴鍖?缂撳瓨鑴氭湰瑙﹀彂 ReferenceError
    function normalizetoastMessage(message) {
        return normalizeToastMessage(message);
    }

    function toast(type, message) {
        if (!message) {
            return;
        }
        message = normalizeToastMessage(message);
        var key = String(type || '') + ':' + String(message || '');
        var now = Date.now();
        if (lastToastByKey[key] && (now - lastToastByKey[key]) < 5000) {
            return;
        }
        lastToastByKey[key] = now;
        if (window.BackendToast && typeof window.BackendToast[type] === 'function') {
            window.BackendToast[type](message);
            return;
        }
        console[type === 'error' ? 'error' : 'log'](message);
    }

    function postForm(url, fields) {
        var fd = new FormData();
        Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
        return fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: fd
        }).then(function (response) {
            if (!response.ok) {
                return response.text().then(function (text) {
                    var errMsg = '缃戠粶閿欒: ' + response.status + ' ' + response.statusText;
                    try {
                        var j = JSON.parse(text);
                        if (j && (j.message || j.error)) {
                            errMsg = String(j.message || j.error);
                        }
                    } catch (e) {
                        // 濡傛灉涓嶆槸 JSON锛屼娇鐢ㄥ師濮嬮敊璇俊鎭?
                    }
                    throw new Error(errMsg);
                });
            }
            return response.text().then(function (text) {
                var rawText = String(text || '').trim();
                if (!rawText) {
                    return { success: true };
                }
                try {
                    var parsed = JSON.parse(rawText);
                    return parsed && typeof parsed === 'object' ? parsed : { success: true, data: parsed };
                } catch (error) {
                    var isLikelyHtml = rawText.indexOf('<!DOCTYPE') === 0 || rawText.indexOf('<html') === 0 || rawText.indexOf('<') === 0;
                    if (isLikelyHtml) {
                        throw new Error("接口返回了 HTML，而不是 JSON。");
                    }
                    throw new Error("接口返回了非 JSON 响应。");
                }
            });
        }).catch(function (error) {
            throw error;
        });
    }

    function jsonTruthyFlag(raw) {
        return raw === true || raw === 1 || raw === '1'
            || (typeof raw === 'string' && ['true', 'yes', 'on'].indexOf(raw.trim().toLowerCase()) >= 0);
    }

    function setBuildNavigationGuard(active) {
        buildNavigationGuardActive = !!active;
    }

    function showBuildGuard(message, detail) {
        // 鎸変骇鍝佽姹傜Щ闄ゆ瀯寤洪伄缃╁脊绐楁樉绀猴紱淇濈暀璋冪敤鐐归伩鍏嶅奖鍝嶇幇鏈夋祦绋嬨€?        void message;
        void detail;
        hideBuildGuard();
    }

    function updateBuildGuardProgress(message, progressPercent, detail) {
        var messageEl = document.getElementById('pb-ai-build-guard-message');
        var detailEl = document.getElementById('pb-ai-build-guard-detail');
        var progressBar = document.getElementById('pb-ai-build-guard-progress-bar');
        if (messageEl) {
            messageEl.textContent = String(message || '');
        }
        if (detailEl) {
            detailEl.textContent = String(detail || '');
        }
        if (progressBar) {
            var percent = parseInt(progressPercent || 0, 10) || 0;
            progressBar.style.width = Math.min(100, Math.max(0, percent)) + '%';
        }
    }

    function resetBuildGuardProgress() {
        var progressBar = document.getElementById('pb-ai-build-guard-progress-bar');
        if (progressBar) {
            progressBar.style.width = '0%';
        }
    }

    function hideBuildGuard() {
        var guard = document.getElementById('pb-ai-build-guard');
        if (guard) {
            guard.classList.add('d-none');
            guard.setAttribute('aria-hidden', 'true');
        }
    }

    function updateStageButtons(nextStage) {
        document.querySelectorAll('.pb-ai-set-stage').forEach(function (btn) {
            var stage = String(btn.getAttribute('data-stage') || '');
            btn.classList.toggle('btn-primary', stage === nextStage);
            btn.classList.toggle('btn-outline-primary', stage !== nextStage);
        });
        var stageLabelEl = document.getElementById('pb-ai-current-stage');
        if (stageLabelEl) {
            var activeBtn = document.querySelector('.pb-ai-set-stage[data-stage="' + nextStage + '"]');
            stageLabelEl.textContent = activeBtn ? String(activeBtn.textContent || '').trim() : nextStage;
        }
    }

    function updateGuidedSteps(nextStage) {
        var orderedStages = ['plan', 'visual_edit', 'publish'];
        var activeIndex = orderedStages.indexOf(nextStage);
        if (activeIndex < 0) {
            activeIndex = 0;
        }
        document.querySelectorAll('.pb-guided-step').forEach(function (step, index) {
            step.classList.toggle('active', index === activeIndex);
            step.classList.toggle('done', index < activeIndex);
        });
    }

    function switchWorkspaceStage(nextStage) {
        currentWorkspaceStage = String(nextStage || 'plan');
        document.querySelectorAll('[data-stage-panel]').forEach(function (panel) {
            var stage = String(panel.getAttribute('data-stage-panel') || '');
            panel.classList.toggle('d-none', stage !== currentWorkspaceStage);
        });
        updateStageButtons(currentWorkspaceStage);
        updateGuidedSteps(currentWorkspaceStage);
    }

    function persistWorkspaceStage(nextStage, options) {
        var targetStage = String(nextStage || 'plan');
        var opts = options && typeof options === 'object' ? options : {};
        switchWorkspaceStage(targetStage);
        if (!setStageUrl || !publicId) {
            return Promise.resolve(null);
        }
        return postForm(setStageUrl, {
            public_id: publicId,
            stage: targetStage
        }).then(function (data) {
            if (data && data.success && data.data && typeof data.data === 'object') {
                hydrateWorkspaceFromState(data.data);
            }
            return data;
        }).catch(function (error) {
            if (!opts.silent) {
                console.warn('persistWorkspaceStage failed:', error);
            }
            return null;
        });
    }

    function getVisualPreviewHost() {
        return document.getElementById('pb-ai-visual-preview-host');
    }

    function setVisualPreviewErrorOverlay(visible, messageText) {
        var overlay = document.getElementById('pb-ai-visual-preview-error-overlay');
        if (!overlay) {
            return;
        }
        var text = String(messageText || '').trim();
        if (!text) {
            text = String(messages.visualPreviewUnreachable || '');
        }
        overlay.classList.toggle('d-none', !visible);
        var line = overlay.querySelector('.pb-ai-visual-preview-error-text');
        if (line) {
            line.textContent = visible ? text : '';
        }
    }

    function clearVisualPreviewErrorOverlay() {
        setVisualPreviewErrorOverlay(false, '');
    }

    function normalizePreviewDevice(device) {
        device = String(device || '').toLowerCase();
        return ['desktop', 'tablet', 'mobile'].indexOf(device) >= 0 ? device : 'desktop';
    }

    function applyPreviewDeviceFrameSizing(frame) {
        if (!frame) {
            return;
        }
        if (currentPreviewDevice === 'desktop') {
            frame.style.width = '100%';
            frame.style.maxWidth = '100%';
            frame.style.minHeight = '860px';
        } else if (currentPreviewDevice === 'tablet') {
            frame.style.width = '834px';
            frame.style.maxWidth = '834px';
            frame.style.minHeight = '1112px';
        } else {
            frame.style.width = '390px';
            frame.style.maxWidth = '390px';
            frame.style.minHeight = '844px';
        }
        frame.style.display = 'block';
        frame.style.margin = '0 auto';
    }

    function applyPreviewDeviceMode(device) {
        currentPreviewDevice = normalizePreviewDevice(device);

        document.querySelectorAll('.pb-ai-preview-device-btn').forEach(function (button) {
            var active = String(button.getAttribute('data-preview-device') || '') === currentPreviewDevice;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        document.querySelectorAll('.pb-ai-preview-device-stage').forEach(function (stage) {
            stage.classList.remove('is-desktop', 'is-tablet', 'is-mobile');
            stage.classList.add('is-' + currentPreviewDevice);
            var frame = stage.querySelector('#pb-ai-visual-preview-frame') || stage.querySelector('iframe');
            applyPreviewDeviceFrameSizing(frame);
        });

        var orphanFrame = document.getElementById('pb-ai-visual-preview-frame');
        if (orphanFrame && !orphanFrame.closest('.pb-ai-preview-device-stage')) {
            applyPreviewDeviceFrameSizing(orphanFrame);
        }

        try {
            window.localStorage.setItem('pb-ai-preview-device', currentPreviewDevice);
        } catch (error) {}
    }

    function bindPreviewDeviceButtons() {
        document.querySelectorAll('.pb-ai-preview-device-btn').forEach(function (button) {
            if (button.dataset.previewDeviceBound === '1') {
                return;
            }
            button.dataset.previewDeviceBound = '1';
            button.addEventListener('click', function () {
                applyPreviewDeviceMode(String(button.getAttribute('data-preview-device') || 'desktop'));
            });
        });
    }

    function ensureVisualPreviewFrame() {
        var host = getVisualPreviewHost();
        if (!host) {
            var existingFrame = document.getElementById('pb-ai-visual-preview-frame');
            if (existingFrame) {
                applyPreviewDeviceMode(currentPreviewDevice);
            }
            return existingFrame || null;
        }
        var frame = host.querySelector('#pb-ai-visual-preview-frame');
        if (frame) {
            applyPreviewDeviceMode(currentPreviewDevice);
            return frame;
        }
        host.innerHTML = '';
        frame = document.createElement('iframe');
        frame.id = 'pb-ai-visual-preview-frame';
        frame.title = String(messages.visualPreviewFrameTitle || '');
        frame.loading = 'lazy';
        frame.referrerPolicy = 'no-referrer';
        frame.style.cssText = 'width:100%;min-height:860px;border:1px solid var(--backend-color-border-default, #e5e7eb);border-radius:16px;background:var(--backend-color-bg-primary, #fff);';
        host.appendChild(frame);
        applyPreviewDeviceMode(currentPreviewDevice);
        return frame;
    }

    function renderVisualPreviewHost(url) {
        var host = getVisualPreviewHost();
        var emptyState = document.getElementById('pb-ai-visual-edit-empty-state');
        if (!host) {
            return null;
        }
        clearVisualPreviewErrorOverlay();
        if (url) {
            if (emptyState) {
                emptyState.classList.add('d-none');
            }
            return ensureVisualPreviewFrame();
        }
        host.innerHTML = '<div class="alert alert-info mb-0 pb-ai-preview-host-empty">' + escapeHtml(previewLabels.visualPreviewEmpty) + '</div>';
        if (emptyState) {
            emptyState.classList.remove('d-none');
        }
        return null;
    }

    function ensurePreviewTabsList() {
        var wrapper = document.getElementById('pb-ai-preview-tabs-wrap');
        if (!wrapper) {
            return null;
        }
        var list = wrapper.querySelector('.pb-ai-preview-tabs');
        if (!list) {
            list = document.createElement('ul');
            list.className = 'nav nav-tabs pb-ai-preview-tabs';
            list.setAttribute('role', 'tablist');
            wrapper.appendChild(list);
        }
        wrapper.classList.remove('d-none');
        return list;
    }

    function ensureMaterializedPagesCard() {
        var card = document.getElementById('pb-ai-materialized-pages-card');
        if (card) {
            return card;
        }
        var anchor = document.getElementById('pb-ai-visual-edit-empty-state');
        var container = anchor && anchor.parentNode ? anchor.parentNode : document.querySelector('[data-stage-panel="visual_edit"] .card-body');
        if (!container) {
            return null;
        }
        card = document.createElement('div');
        card.className = 'border rounded p-3 d-none';
        card.id = 'pb-ai-materialized-pages-card';
        card.innerHTML = '<div class="fw-semibold mb-2">宸茬墿鍖栭〉闈?/div><div class="d-flex flex-wrap gap-2" id="pb-ai-materialized-pages-list"></div>';
        container.insertBefore(card, anchor || null);
        return card;
    }

    function renderMaterializedPagesByType(pagesByType) {
        var card = ensureMaterializedPagesCard();
        if (!card) {
            return;
        }
        var list = document.getElementById('pb-ai-materialized-pages-list');
        if (!list) {
            return;
        }
        list.innerHTML = '';
        var hasRows = false;
        Object.keys(pagesByType || {}).forEach(function (pageType) {
            var pageData = pagesByType[pageType] && typeof pagesByType[pageType] === 'object' ? pagesByType[pageType] : {};
            var pageId = parseInt(pageData.page_id || 0, 10) || 0;
            if (!pageId) {
                return;
            }
            hasRows = true;
            var badge = document.createElement('span');
            badge.className = 'badge bg-soft-info text-info';
            badge.textContent = normalizePageTypeLabel(pageType) + '#' + pageId;
            list.appendChild(badge);
        });
        card.classList.toggle('d-none', !hasRows);
    }

    function syncVisualActionBar(workspaceState) {
        var actionBar = document.getElementById('pb-ai-visual-action-bar');
        if (!actionBar) {
            return;
        }
        var visualUrl = workspaceState && workspaceState.visual_preview_url ? String(workspaceState.visual_preview_url) : '';
        var previewUrl = workspaceState && workspaceState.preview_full_url ? String(workspaceState.preview_full_url) : '';
        var focusBtn = document.getElementById('pb-ai-focus-embedded-editor-btn');
        var previewLink = document.getElementById('pb-ai-open-preview-full-link');
        if (!focusBtn) {
            focusBtn = document.createElement('button');
            focusBtn.type = 'button';
            focusBtn.className = 'btn btn-primary pb-ai-focus-embedded-editor';
            focusBtn.id = 'pb-ai-focus-embedded-editor-btn';
            focusBtn.textContent = messages.focusEmbeddedPreview;
            actionBar.insertBefore(focusBtn, actionBar.firstChild || null);
        }
        if (focusBtn && focusBtn.dataset.pbFocusBound !== '1') {
            focusBtn.dataset.pbFocusBound = '1';
            focusBtn.addEventListener('click', function () {
                focusEmbeddedEditor();
            });
        }
        if (!previewLink) {
            previewLink = document.createElement('a');
            previewLink.className = 'btn btn-outline-secondary';
            previewLink.id = 'pb-ai-open-preview-full-link';
            previewLink.target = '_blank';
            previewLink.rel = 'noopener';
            previewLink.textContent = "打开普通预览";
            actionBar.appendChild(previewLink);
        }
        actionBar.classList.toggle('d-none', visualUrl === '' && previewUrl === '');
        if (focusBtn) {
            focusBtn.classList.toggle('d-none', visualUrl === '');
        }
        if (previewLink) {
            previewLink.classList.toggle('d-none', previewUrl === '');
            previewLink.setAttribute('href', previewUrl || '#');
        }
    }

    window.addEventListener('beforeunload', function (event) {
        if (!buildNavigationGuardActive) {
            return;
        }
        var message = messages.leaveDuringBuild || "页面仍在生成中，离开后会中断当前生成。";
        event.preventDefault();
        event.returnValue = message;
        return message;
    });

    function getTargetDomainInputs() {
        return document.querySelectorAll('.pb-ai-target-domain-sync');
    }

    /** 浼樺厛 #pb-ai-target-domain锛堟楠?1 涓昏緭鍏ワ級锛屽惁鍒欐憳瑕佸尯 #pb-ai-target-domain-summary锛屼繚璇佷笌 scope.target_domain 鍚屾簮 */
    function getTargetDomainTrimmed() {
        var primary = document.getElementById('pb-ai-target-domain');
        if (primary && typeof primary.value === 'string') {
            return String(primary.value || '').trim();
        }
        var summary = document.getElementById('pb-ai-target-domain-summary');
        if (summary && typeof summary.value === 'string') {
            return String(summary.value || '').trim();
        }
        var nodes = getTargetDomainInputs();
        var v = nodes.length ? String(nodes[0].value || '').trim() : '';
        if (v !== '') {
            return v;
        }
        try {
            var elGd = document.getElementById('pb-ai-guided-scope-defaults');
            if (elGd) {
                var parsed = JSON.parse(elGd.textContent || '{}') || {};
                var fd = typeof parsed.target_domain === 'string' ? String(parsed.target_domain).trim() : '';
                if (fd !== '') {
                    return fd;
                }
            }
        } catch (eGd) {}
        return '';
    }

    function setTargetDomainFieldInvalid(on) {
        getTargetDomainInputs().forEach(function (el) {
            el.classList.toggle('is-invalid', !!on);
        });
    }

    function bindTargetDomainInvalidClearOnce() {
        getTargetDomainInputs().forEach(function (el) {
            if (el.dataset.pbDomainInvalidClearBound === '1') {
                return;
            }
            el.dataset.pbDomainInvalidClearBound = '1';
            el.addEventListener('input', function () {
                if (String(el.value || '').trim() !== '') {
                    setTargetDomainFieldInvalid(false);
                }
            });
        });
    }

    function setAllTargetDomainInputs(value) {
        var v = String(value || '');
        getTargetDomainInputs().forEach(function (el) {
            el.value = v;
        });
    }

    function collectLocaleInputNodes(syncClass, elementId) {
        var seen = new Set();
        var nodes = [];

        function pushNode(node) {
            if (!node || seen.has(node)) {
                return;
            }
            seen.add(node);
            nodes.push(node);
        }

        document.querySelectorAll(syncClass).forEach(pushNode);

        var idNode = document.getElementById(elementId);
        pushNode(idNode);

        // 鍏煎閮ㄥ垎 i18n 缁勪欢浼氭覆鏌?hidden/input 浣滀负鐪熷疄鍊煎鍣?
        [
            elementId + '_value',
            elementId + '-value',
            elementId + '_input',
            elementId + '-input',
            elementId + '_hidden',
            elementId + '-hidden'
        ].forEach(function (id) {
            pushNode(document.getElementById(id));
        });

        return nodes;
    }

    function getLocaleValueFromNodes(nodes) {
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            if (!node) {
                continue;
            }
            var value = '';
            if (typeof node.value !== 'undefined') {
                value = String(node.value || '').trim();
            } else if (typeof node.getAttribute === 'function') {
                value = String(node.getAttribute('value') || '').trim();
            }
            if (value !== '') {
                return value;
            }
        }

        return '';
    }

    function setLocaleValueToNodes(nodes, value) {
        var v = String(value || '');
        nodes.forEach(function (node) {
            if (!node) {
                return;
            }
            if (typeof node.value !== 'undefined') {
                node.value = v;
            }
            if (typeof node.setAttribute === 'function') {
                node.setAttribute('value', v);
            }
            if (typeof Event === 'function' && typeof node.dispatchEvent === 'function') {
                node.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    function getDefaultLocaleInputs() {
        return collectLocaleInputNodes('.pb-ai-default-locale-sync', 'pb-ai-default-locale');
    }

    function getPrimaryDefaultLocaleValue() {
        return getLocaleValueFromNodes(getDefaultLocaleInputs());
    }

    function setAllDefaultLocaleSelects(value) {
        setLocaleValueToNodes(getDefaultLocaleInputs(), value);
    }

    function getPlanLocaleInputs() {
        return collectLocaleInputNodes('.pb-ai-plan-locale-sync', 'pb-ai-plan-locale');
    }

    function getPrimaryPlanLocaleValue() {
        return getLocaleValueFromNodes(getPlanLocaleInputs());
    }

    function setAllPlanLocaleSelects(value) {
        setLocaleValueToNodes(getPlanLocaleInputs(), value);
    }

    var recommendDomainUrl = document.getElementById('site-builder-api-recommend-domain') ? document.getElementById('site-builder-api-recommend-domain').value : '';
    var checkDomainUrl = document.getElementById('site-builder-api-check-domain') ? document.getElementById('site-builder-api-check-domain').value : '';
    var startDomainPurchaseUrl = document.getElementById('site-builder-api-start-domain-purchase') ? document.getElementById('site-builder-api-start-domain-purchase').value : '';
    var domainPurchaseSseBaseUrl = "\/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8\/websites\/backend\/site-builder-agent\/domain-purchase-sse";
    var linkedWorkbenchPublicId = "4a7b9293b052ea9a0aa1e419125a38da";
    var briefInputEl = document.getElementById('pb-ai-brief-description');
    var titleInputEl = document.getElementById('pb-ai-site-title');
    var recommendationBoxEl = document.getElementById('pb-ai-domain-recommendation');
    var recommendationListEl = document.getElementById('pb-ai-recommended-domain-list');
    var domainPurchaseState = {"status":"idle","status_label":"待启动","stage":"purchase","stage_label":"购买域名","message":"准备启动域名购买","domain":"","registrar_account_id":0,"order_id":0,"purchase_order_id":0,"execution_token":"","updated_at":"","started_at":"","finished_at":"","can_start":false,"is_running":false,"is_completed":false,"is_failed":false,"needs_resume":false};
    var domainPurchaseSource = null;
    var domainPurchaseSourceStartTimer = null;

    function getRegistrarSelection() {
        var hidden = document.getElementById('pb-ai-registrar-account_value');
        if (!hidden) {
            return { accountId: 0, label: '' };
        }
        var accountId = parseInt(String(hidden.value || '0'), 10) || 0;
        var label = '';
        var api = window.WelineRegistrarSelect && window.WelineRegistrarSelect['pb-ai-registrar-account'];
        if (api && typeof api.getSelected === 'function') {
            var items = api.getSelected();
            if (items && items[0]) {
                label = String(items[0].label || '').trim();
            }
        }
        return { accountId: accountId, label: label };
    }

    function setDomainRecommendationState(type, message) {
        if (!recommendationBoxEl) {
            return;
        }
        if (!message) {
            recommendationBoxEl.className = 'alert alert-light border d-none mb-2';
            recommendationBoxEl.textContent = '';
            return;
        }
        var stateClass = 'alert-info';
        if (type === 'success') {
            stateClass = 'alert-success';
        } else if (type === 'warning') {
            stateClass = 'alert-warning';
        } else if (type === 'error') {
            stateClass = 'alert-danger';
        }
        recommendationBoxEl.className = 'alert border mb-2 ' + stateClass;
        recommendationBoxEl.textContent = message;
    }

    function renderRecommendedDomains(domains) {
        if (!recommendationListEl) {
            return;
        }
        recommendationListEl.innerHTML = '';
        (Array.isArray(domains) ? domains : []).forEach(function (domain) {
            var value = String(domain || '').trim();
            if (!value) {
                return;
            }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-soft-secondary pb-ai-domain-suggestion';
            btn.setAttribute('data-domain', value);
            btn.textContent = value;
            btn.addEventListener('click', function () {
                setAllTargetDomainInputs(value);
                siteProfileManual.target_domain = true;
                autoSaveScope();
            });
            recommendationListEl.appendChild(btn);
        });
    }

    function syncDomainPurchaseCard(state) {
        if (!state || typeof state !== 'object') {
            return;
        }
        domainPurchaseState = Object.assign({}, domainPurchaseState || {}, state);
        var badge = document.getElementById('site-builder-domain-status-badge');
        var messageEl = document.getElementById('site-builder-domain-message');
        var domainEl = document.getElementById('site-builder-domain-progress-domain');
        var stageEl = document.getElementById('site-builder-domain-stage');
        var orderEl = document.getElementById('site-builder-domain-order-id');
        var buttonEl = document.getElementById('site-builder-start-domain-purchase');

        if (badge) {
            badge.className = 'badge ' + (
                domainPurchaseState.status === 'completed' ? 'bg-soft-success text-success'
                    : (domainPurchaseState.status === 'failed' ? 'bg-soft-danger text-danger'
                        : ((domainPurchaseState.status === 'queued' || domainPurchaseState.status === 'running') ? 'bg-soft-info text-info' : 'bg-soft-secondary text-muted'))
            );
            badge.textContent = String(domainPurchaseState.status_label || "待启动");
        }
        if (messageEl) {
            messageEl.textContent = String(domainPurchaseState.message || '');
        }
        if (domainEl) {
            domainEl.textContent = String(domainPurchaseState.domain || getTargetDomainTrimmed());
        }
        if (stageEl) {
            stageEl.textContent = String(domainPurchaseState.stage_label || '璐拱鍩熷悕');
        }
        if (orderEl) {
            var orderId = parseInt(String(domainPurchaseState.order_id || 0), 10) || parseInt(String(domainPurchaseState.purchase_order_id || 0), 10) || 0;
            orderEl.textContent = orderId > 0 ? String(orderId) : '绛夊緟鐢熸垚';
        }
        if (buttonEl) {
            buttonEl.disabled = ['queued', 'running'].indexOf(String(domainPurchaseState.status || 'idle')) !== -1;
        }
    }

    function closeDomainPurchaseSource() {
        if (domainPurchaseSourceStartTimer) {
            window.clearTimeout(domainPurchaseSourceStartTimer);
            domainPurchaseSourceStartTimer = null;
        }
        if (!domainPurchaseSource) {
            return;
        }
        try {
            domainPurchaseSource.close();
        } catch (error) {
        }
        domainPurchaseSource = null;
    }

    function startDomainPurchaseStream(linkedPublicId, executionToken) {
        if (!domainPurchaseSseBaseUrl || !linkedPublicId || !executionToken) {
            return false;
        }
        closeDomainPurchaseSource();
        try {
            var requestUrl = new URL(domainPurchaseSseBaseUrl, window.location.origin);
            requestUrl.searchParams.set('public_id', linkedPublicId);
            requestUrl.searchParams.set('execution_token', executionToken);
        } catch (error) {
            toast('error', "");
            return false;
        }
        domainPurchaseSourceStartTimer = window.setTimeout(function () {
            domainPurchaseSourceStartTimer = null;
            try {
                domainPurchaseSource = new EventSource(requestUrl.toString());
            } catch (error) {
                toast('error', "");
                return;
            }

            domainPurchaseSource.addEventListener('start', function (event) {
                var payload = parsePayload(event);
                if (payload && payload.message) {
                    syncDomainPurchaseCard({ status: 'running', message: payload.message });
                }
            });
            domainPurchaseSource.addEventListener('progress', function (event) {
                var payload = parsePayload(event);
                if (payload && payload.state && typeof payload.state === 'object') {
                    syncDomainPurchaseCard(payload.state);
                    if (payload.state && payload.state.domain) {
                        setAllTargetDomainInputs(String(payload.state.domain || ''));
                    }
                } else if (payload && payload.message) {
                    syncDomainPurchaseCard({ status: 'running', message: payload.message });
                }
            });
            domainPurchaseSource.addEventListener('error', function (event) {
                var payload = parsePayload(event);
                if (payload && payload.message) {
                    syncDomainPurchaseCard({ status: 'failed', message: payload.message });
                    return;
                }
                var currentStatus = String((domainPurchaseState && domainPurchaseState.status) || 'idle');
                if (currentStatus === 'queued' || currentStatus === 'running') {
                    syncDomainPurchaseCard({
                        status: currentStatus,
                        message: "杩炴帴閲嶈瘯涓紝鍩熷悕浠诲姟鍙兘浠嶅湪鍚庡彴鎵ц..."                    });
                }
            });
            domainPurchaseSource.addEventListener('done', function (event) {
                var payload = parsePayload(event);
                if (payload && payload.state && typeof payload.state === 'object') {
                    syncDomainPurchaseCard(payload.state);
                } else if (payload && payload.message) {
                    syncDomainPurchaseCard({
                        status: payload.success === false ? 'failed' : 'completed',
                        message: payload.message
                    });
                }
                closeDomainPurchaseSource();
            });
        }, PB_SSE_CONNECT_DELAY_MS);

        return true;
    }

    function persistDomainSelection(extraPatch) {
        if (!mergeScopeUrl) {
            return Promise.resolve();
        }
        var registrarSelection = getRegistrarSelection();
        var patch = Object.assign({
            target_domain: getTargetDomainTrimmed(),
            preferred_registrar_account_id: registrarSelection.accountId,
            recommended_registrar_label: registrarSelection.label
        }, extraPatch || {});
        return postForm(mergeScopeUrl, {
            public_id: publicId,
            scope_patch: JSON.stringify(patch)
        }).catch(function () {});
    }

    function isGuidedFakeModeSession() {
        var f = guidedScopeDefaults().fake_mode;
        return f === 1 || f === true || f === '1' || f === 'true';
    }

    function recommendDomainsFromWorkbench() {
        var registrarSelection = getRegistrarSelection();
        var description = (briefInputEl ? String(briefInputEl.value || '').trim() : '') || (titleInputEl ? String(titleInputEl.value || '').trim() : '');
        if (!description) {
            toast('warning', messages.domainNeedBrief);
            return;
        }
        var fakeSession = isGuidedFakeModeSession();
        if (!recommendDomainUrl) {
            toast('error', messages.networkError);
            return;
        }

        setDomainRecommendationState('info', messages.domainRecommendLoading);
        postForm(recommendDomainUrl, {
            description: description,
            domain: getTargetDomainTrimmed(),
            account_id: registrarSelection.accountId > 0 ? String(registrarSelection.accountId) : '0',
            fake_mode: fakeSession ? '1' : '0',
            defer_availability_check: '1'
        }).then(function (data) {
            if (!data || !data.success) {
                setDomainRecommendationState('warning', (data && data.message) ? String(data.message) : messages.domainRecommendEmpty);
                return;
            }
            var recommendedDomain = String(data.domain || '').trim();
            var candidates = Array.isArray(data.candidate_domains) ? data.candidate_domains : (recommendedDomain ? [recommendedDomain] : []);
            if (recommendedDomain) {
                setAllTargetDomainInputs(recommendedDomain);
            }
            renderRecommendedDomains(candidates);
            setDomainRecommendationState('success', String(data.message || recommendedDomain));
            persistDomainSelection({
                target_domain: getTargetDomainTrimmed() || recommendedDomain,
                recommended_domain_list: candidates,
                preferred_registrar_account_id: registrarSelection.accountId,
                recommended_registrar_label: registrarSelection.label
            });
            autoSaveScope();
        }).catch(function () {
            setDomainRecommendationState('error', messages.networkError);
        });
    }

    function ensurePurchaseDialogLoaded() {
        if (window.WelineDomainPurchaseDialog && typeof window.WelineDomainPurchaseDialog.open === 'function') {
            return Promise.resolve(window.WelineDomainPurchaseDialog);
        }
        return Promise.reject(new Error(messages.domainPurchaseDialogLoadFailed));
    }

    function startDomainPurchaseFromWorkbench() {
        var registrarSelection = getRegistrarSelection();
        var domain = getTargetDomainTrimmed();
        if (!domain) {
            toast('warning', messages.domainRecommendEmpty);
            return;
        }
        if (!registrarSelection.accountId) {
            toast('warning', messages.domainNeedRegistrar);
            return;
        }
        if (!startDomainPurchaseUrl) {
            toast('error', messages.networkError);
            return;
        }

        ensurePurchaseDialogLoaded().then(function (dialog) {
            return dialog.open({
                labels: {
                    title: "Purchase Domain",
                    description: "After confirming the purchase, the system will continue DNS, CDN, resolve, and follow-up lifecycle handling.",
                    dnsChoice: "DNS Mode",
                    dnsFollowRegistrar: "Follow Registrar",
                    dnsCustomNameservers: "Custom Nameserver",
                    dnsNameservers: "Nameserver List",
                    dnsNameserversPlaceholder: "ns1.example.com, ns2.example.com",
                    cdnChoice: "CDN Mode",
                    cdnFollowRegistrar: "Follow Registrar",
                    cdnNone: "No CDN",
                    resolveToLocal: "Resolve to Local Deployment",
                    resolveHint: "Support @ and www host records when mapping the purchased domain to the current local deployment.",
                    subdomains: "Host Records",
                    subdomainsPlaceholder: "@,www",
                    startLifecycle: "Start Domain Lifecycle",
                    startLifecycleHint: "After the purchase starts, the system can continue DNS and HTTPS related follow-up processing.",
                    confirm: "Confirm Purchase",
                    cancel: "取消",
                },
                defaults: {
                    dnsChoice: 'follow_registrar',
                    cdnChoice: 'follow_registrar',
                    resolveToLocal: true,
                    subdomains: '@,www',
                    startLifecycle: true
                }
            });
        }).then(function (dialogResult) {
            if (!dialogResult) {
                return;
            }
            return postForm(startDomainPurchaseUrl, {
                public_id: publicId,
                scope_patch: JSON.stringify({
                    target_domain: domain,
                    selected_domain: domain,
                    preferred_registrar_account_id: registrarSelection.accountId,
                    registrar_account_id: registrarSelection.accountId,
                    recommended_registrar_label: registrarSelection.label,
                    fake_mode: isGuidedFakeModeSession() ? 1 : 0
                })
            }).then(function (data) {
                if (!data || !data.success) {
                    toast('error', (data && data.message) ? String(data.message) : messages.domainPurchaseFailed);
                    return;
                }
                syncDomainPurchaseCard(data.state || {});
                persistDomainSelection({
                    target_domain: domain,
                    preferred_registrar_account_id: registrarSelection.accountId,
                    registrar_account_id: registrarSelection.accountId,
                    recommended_registrar_label: registrarSelection.label
                });
                var nextState = data.state && typeof data.state === 'object' ? data.state : {};
                var executionToken = String((data.stream_token || nextState.execution_token || '') || '');
                if (linkedWorkbenchPublicId && executionToken) {
                    startDomainPurchaseStream(linkedWorkbenchPublicId, executionToken);
                }
                toast('success', (data.state && data.state.is_completed) ? messages.domainPurchased : messages.domainPurchaseQueued);
            });
        }).catch(function (error) {
            toast('error', (error && error.message) ? String(error.message) : messages.domainPurchaseDialogLoadFailed);
        });
    }

    // 绂佹椤甸潰鍔犺浇鏃惰嚜鍔ㄦ仮澶嶅煙鍚嶈喘涔?SSE锛岀粺涓€鏀逛负鐢ㄦ埛鏄惧紡瑙﹀彂銆?
    document.querySelectorAll('.pb-ai-workspace-track-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var track = btn.getAttribute('data-track');
            if (!mergeScopeUrl || !track) {
                return;
            }
            postForm(mergeScopeUrl, { public_id: publicId, scope_patch: JSON.stringify({ workspace_track: track }) }).then(function (data) {
                if (data && data.success) {
                    toast('success', messages.saveSuccess);
                    window.location.reload();
                } else {
                    toast('error', (data && data.message) || messages.saveFailed);
                }
            }).catch(function () { toast('error', messages.networkError); });
        });
    });

    var siteReadyBtn = document.getElementById('pb-ai-site-ready-dev');
    if (siteReadyBtn) {
        siteReadyBtn.addEventListener('click', function () {
            if (!mergeScopeUrl) {
                return;
            }
            postForm(mergeScopeUrl, { public_id: publicId, scope_patch: JSON.stringify({ site_ready: 1 }) }).then(function (data) {
                if (data && data.success) {
                    toast('success', messages.saveSuccess);
                    window.location.reload();
                } else {
                    toast('error', (data && data.message) || messages.saveFailed);
                }
            }).catch(function () { toast('error', messages.networkError); });
        });
    }

    var recommendDomainBtn = document.getElementById('pb-ai-recommend-domain-btn');
    if (recommendDomainBtn) {
        recommendDomainBtn.addEventListener('click', function () {
            recommendDomainsFromWorkbench();
        });
    }
    var targetDomainAiRefreshBtn = document.getElementById('pb-ai-target-domain-ai-refresh');
    if (targetDomainAiRefreshBtn) {
        targetDomainAiRefreshBtn.addEventListener('click', function () {
            recommendDomainsFromWorkbench();
        });
    }
    var startDomainPurchaseBtn = document.getElementById('site-builder-start-domain-purchase');
    if (startDomainPurchaseBtn) {
        startDomainPurchaseBtn.addEventListener('click', function () {
            startDomainPurchaseFromWorkbench();
        });
    }

    // T36: 鏌ョ湅鍘嗗彶纭鏂规寮圭獥
    function showConfirmedPlanModal(planType) {
        var modalEl = document.getElementById('pb-ai-confirmed-plan-view-modal');
        if (!modalEl) {
            return;
        }
        var planTypeBadge = document.getElementById('pb-ai-confirmed-plan-type-badge');
        var planRenderedContent = document.getElementById('pb-ai-confirmed-plan-rendered-content');
        var planConfirmedAt = document.getElementById('pb-ai-confirmed-plan-confirmed-at');
        var planSignature = document.getElementById('pb-ai-confirmed-plan-signature');
        var planLocale = document.getElementById('pb-ai-confirmed-plan-locale');

        var planData = null;
        var markdown = '';
        var confirmedAt = '';
        var signature = '';
        var locale = '';
        var typeLabel = '';

        if (planType === 'stage1') {
            typeLabel = "阶段一方案";
            planData = window.__pbWorkspaceConfirmedPlan || {};
            markdown = planData.markdown || planData.markdown_content || '';
            confirmedAt = planData.confirmed_at || '';
            signature = planData.signature || planData.execution_blueprint_signature || '';
            locale = planData.plan_locale || '';
        } else if (planType === 'stage2') {
            typeLabel = "阶段二任务方案";
            planData = window.__pbWorkspaceConfirmedTaskPlan || {};
            markdown = planData.markdown || planData.confirmed_markdown || '';
            confirmedAt = planData.confirmed_at || planData.task_plan_confirmed_at || '';
            signature = planData.signature || planData.task_plan_signature || '';
            locale = planData.plan_locale || '';
        }

        if (planTypeBadge) planTypeBadge.textContent = typeLabel;
        if (planRenderedContent) {
            var previewPayload = {};
            if (planType === 'stage1') {
                previewPayload = {
                    structured: planData.structured && typeof planData.structured === 'object' ? planData.structured : {},
                    json: planData.plan_json && typeof planData.plan_json === 'object' ? planData.plan_json : {},
                    markdown: markdown
                };
            } else if (planType === 'stage2') {
                previewPayload = {
                    structured: planData.structured && typeof planData.structured === 'object' ? planData.structured : {},
                    json: {},
                    markdown: markdown
                };
            }
            planRenderedContent.innerHTML = buildPlanPreviewHtml(markdown, previewPayload);
        }
        if (planConfirmedAt) planConfirmedAt.textContent = confirmedAt || '-';
        if (planSignature) planSignature.textContent = signature || '-';
        if (planLocale) planLocale.textContent = locale || '-';

        // 瀛樺偍褰撳墠鏁版嵁渚涘鍒朵娇鐢?
        window.__pbCurrentConfirmedPlanData = planData;

        // 鏄剧ず Modal
        var modal = window.bootstrap && window.bootstrap.Modal ? new window.bootstrap.Modal(modalEl) : null;
        if (modal) {
            modal.show();
        } else if (typeof $ !== 'undefined' && $.fn.modal) {
            $(modalEl).modal('show');
        }
    }

    // T36: 澶嶅埗纭鏂规 JSON
    function copyConfirmedPlanJson() {
        var data = window.__pbCurrentConfirmedPlanData || {};
        var json = JSON.stringify(data, null, 2);
        if (!json || json === '{}') {
            toast('warning', "没有可复制的内容");
            return;
        }
        navigator.clipboard.writeText(json).then(function () {
            toast('success', "结构化数据已复制到剪贴板");
        }).catch(function () {
            toast('error', "复制失败，请手动复制");
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        // 缁戝畾鏌ョ湅鍘嗗彶鏂规鎸夐挳
        document.querySelectorAll('.pb-ai-view-confirmed-plan').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var planType = btn.getAttribute('data-plan-type') || 'stage1';
                showConfirmedPlanModal(planType);
            });
        });
        // 缁戝畾澶嶅埗鎸夐挳
        var copyJsonBtn = document.getElementById('pb-ai-copy-plan-json');
        if (copyJsonBtn) copyJsonBtn.addEventListener('click', copyConfirmedPlanJson);
    });

    function normalizePageTypesUserCustomizedFlag(raw) {
        if (raw === true || raw === 1 || raw === '1') {
            return true;
        }
        if (typeof raw === 'string') {
            var normalized = raw.trim().toLowerCase();
            return normalized === 'true' || normalized === 'yes' || normalized === 'on';
        }
        return false;
    }


    var REQUIRED_PAGE_TYPE = 'home_page';
    var TASK_ALWAYS_VISIBLE_PAGE_TYPE_MAP = {
        shared: true,
        home_page: true
    };

    function ensureRequiredPageTypeSelected(types) {
        var seen = Object.create(null);
        var out = [];
        (Array.isArray(types) ? types : []).forEach(function (type) {
            var normalized = String(type || '');
            if (!normalized || seen[normalized]) {
                return;
            }
            seen[normalized] = true;
            out.push(normalized);
        });
        if (!seen[REQUIRED_PAGE_TYPE]) {
            out.unshift(REQUIRED_PAGE_TYPE);
        }
        return out;
    }

    // Backward compatibility: older cached scripts still call this name.
    function ensureRequiredPageSelected(types) {
        return ensureRequiredPageTypeSelected(types);
    }

    function syncPageTypeCheckLabelState(check) {
        if (!check || !check.closest) {
            return;
        }
        var label = check.closest('label');
        if (!label) {
            return;
        }
        if (label.classList.contains('form-check-label')) {
            label.classList.toggle('bg-primary', !!check.checked);
            label.classList.toggle('text-white', !!check.checked);
            label.classList.toggle('bg-light', !check.checked);
        }
    }

    function syncDomPageTypeChecks(selectedTypes) {
        var selectedMap = Object.create(null);
        ensureRequiredPageTypeSelected(selectedTypes).forEach(function (pageType) {
            selectedMap[pageType] = true;
        });
        document.querySelectorAll('.pb-ai-page-type-check').forEach(function (check) {
            var type = String(check.value || '');
            var isRequired = type === REQUIRED_PAGE_TYPE;
            check.disabled = isRequired;
            check.checked = isRequired ? true : !!selectedMap[type];
            syncPageTypeCheckLabelState(check);
        });
    }

    function syncWorkspacePageTypeState(workspaceState) {
        if (!workspaceState || typeof workspaceState !== 'object') {
            return;
        }
        var scope = workspaceState.scope && typeof workspaceState.scope === 'object'
            ? workspaceState.scope
            : null;
        var scopePageTypes = scope && Array.isArray(scope.page_types) ? scope.page_types : [];
        if (scopePageTypes.length > 0) {
            serverPageTypesFallback = scopePageTypes.slice();
            syncDomPageTypeChecks(serverPageTypesFallback);
            if (typeof window.applyTaskSummarySelectionFilter === 'function') {
                window.applyTaskSummarySelectionFilter();
            }
        }
        if (scope && Object.prototype.hasOwnProperty.call(scope, 'page_types_user_customized')) {
            pageTypesUserCustomized = normalizePageTypesUserCustomizedFlag(scope.page_types_user_customized);
        }
    }

    function selectedPageTypes() {
        var checks = Array.from(document.querySelectorAll('.pb-ai-page-type-check'));
        if (checks.length === 0) {
            return ensureRequiredPageTypeSelected(Array.isArray(serverPageTypesFallback) ? serverPageTypesFallback.slice() : []);
        }
        var seen = Object.create(null);
        var out = [];
        checks.forEach(function (c) {
            if (!c.checked) {
                return;
            }
            var v = String(c.value || '');
            if (v && !seen[v]) {
                seen[v] = true;
                out.push(v);
            }
        });
        return ensureRequiredPageTypeSelected(out);
    }
    workspaceApi.ensureRequiredPageTypeSelected = ensureRequiredPageTypeSelected;
    workspaceApi.selectedPageTypes = selectedPageTypes;
    workspaceApi.getTaskAlwaysVisiblePageTypeMap = function () {
        return TASK_ALWAYS_VISIBLE_PAGE_TYPE_MAP;
    };

    function syncSameValuePageTypeChecks(pageType, isChecked) {
        var normalized = String(pageType || '');
        if (!normalized) {
            return;
        }
        if (normalized === REQUIRED_PAGE_TYPE && !isChecked) {
            isChecked = true;
        }
        document.querySelectorAll('.pb-ai-page-type-check').forEach(function (check) {
            if (String(check.value || '') !== normalized) {
                return;
            }
            check.checked = normalized === REQUIRED_PAGE_TYPE ? true : !!isChecked;
            if (normalized === REQUIRED_PAGE_TYPE) {
                check.disabled = true;
            }
            syncPageTypeCheckLabelState(check);
        });
    }

    function clearAllDomPageTypeChecksAndPersist() {
        pageTypesUserCustomized = true;
        syncDomPageTypeChecks([REQUIRED_PAGE_TYPE]);
        autoSaveScope();
        syncPreviewTabsWithSelection();
        if (typeof window.applyTaskSummarySelectionFilter === 'function') {
            window.applyTaskSummarySelectionFilter();
        }
    }

    function ensureDefaultPageTypes() {
        var checks = Array.from(document.querySelectorAll('.pb-ai-page-type-check'));
        if (checks.length === 0) {
            return false;
        }
        if (!pageTypesUserCustomized) {
            var unchecked = checks.filter(function (item) { return !item.checked; });
            unchecked.forEach(function (item) { item.checked = true; });
            syncSameValuePageTypeChecks(REQUIRED_PAGE_TYPE, true);
            return unchecked.length > 0;
        }
        var selected = checks.filter(function (item) { return item.checked; });
        if (selected.length > 0) {
            syncSameValuePageTypeChecks(REQUIRED_PAGE_TYPE, true);
            return false;
        }
        syncSameValuePageTypeChecks(REQUIRED_PAGE_TYPE, true);
        return false;
    }

    function guidedScopeDefaults() {
        var el = document.getElementById('pb-ai-guided-scope-defaults');
        if (!el) {
            return {};
        }
        try {
            return JSON.parse(el.textContent || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    function patchGuidedScopeDefaults(patch) {
        var el = document.getElementById('pb-ai-guided-scope-defaults');
        if (!el || !patch || typeof patch !== 'object') {
            return;
        }
        var current = guidedScopeDefaults();
        var merged = Object.assign({}, current, patch);
        el.textContent = JSON.stringify(merged);
    }

    function syncGuidedProfileDefaultsFromWorkspaceState(workspaceState) {
        var scope = workspaceState && workspaceState.scope && typeof workspaceState.scope === 'object'
            ? workspaceState.scope
            : null;
        var websiteProfile = workspaceState && workspaceState.website_profile && typeof workspaceState.website_profile === 'object'
            ? workspaceState.website_profile
            : {};
        if (!scope) {
            return;
        }
        var patch = {
            site_title: typeof scope.site_title === 'string' && String(scope.site_title).trim() !== ''
                ? scope.site_title
                : (websiteProfile.site_title || ''),
            site_tagline: typeof scope.site_tagline === 'string' ? scope.site_tagline : (websiteProfile.site_tagline || ''),
            brief_description: typeof scope.brief_description === 'string' && String(scope.brief_description).trim() !== ''
                ? scope.brief_description
                : (typeof scope.user_description === 'string' ? scope.user_description : (websiteProfile.brief_description || '')),
            target_domain: typeof scope.target_domain === 'string' && String(scope.target_domain).trim() !== ''
                ? scope.target_domain
                : (typeof scope.selected_domain === 'string' && String(scope.selected_domain).trim() !== ''
                    ? scope.selected_domain
                    : (websiteProfile.target_domain || '')),
            default_locale: typeof scope.default_locale === 'string' && String(scope.default_locale).trim() !== ''
                ? scope.default_locale
                : (scope.default_language || websiteProfile.default_locale || ''),
            plan_locale: typeof scope.plan_locale === 'string' && String(scope.plan_locale).trim() !== ''
                ? scope.plan_locale
                : (scope.default_language || ''),
            site_profile_manual: scope.site_profile_manual && typeof scope.site_profile_manual === 'object'
                ? scope.site_profile_manual
                : {}
        };
        patchGuidedScopeDefaults(patch);
        siteProfileManual = normalizeSiteProfileManual(patch.site_profile_manual || {});
        hydrateGuidedProfileInputs();
    }

    function normalizeSiteProfileManual(raw) {
        var flags = {
            site_title: false,
            site_tagline: false,
            target_domain: false,
            brief_description: false,
            default_locale: false,
            plan_locale: false
        };
        if (!raw || typeof raw !== 'object') {
            return flags;
        }
        Object.keys(flags).forEach(function (key) {
            flags[key] = raw[key] === true || raw[key] === 1 || raw[key] === '1' || raw[key] === 'true';
        });
        return flags;
    }

    var siteProfileManual = normalizeSiteProfileManual(guidedScopeDefaults().site_profile_manual || {});

    function hydrateGuidedProfileInputs() {
        var fb = guidedScopeDefaults();
        [
            ['pb-ai-site-title', 'site_title'],
            ['pb-ai-site-tagline', 'site_tagline'],
            ['pb-ai-brief-description', 'brief_description']
        ].forEach(function (pair) {
            var el = document.getElementById(pair[0]);
            if (!el) {
                return;
            }
            var currentValue = typeof el.value === 'string' ? el.value.trim() : '';
            var fallbackValue = typeof fb[pair[1]] === 'string' ? String(fb[pair[1]]) : '';
            if (currentValue === '' && fallbackValue !== '') {
                el.value = fallbackValue;
            }
        });
        var fallbackDomain = typeof fb.target_domain === 'string' ? String(fb.target_domain).trim() : '';
        if (fallbackDomain !== '' && getTargetDomainTrimmed() === '') {
            setAllTargetDomainInputs(fallbackDomain);
        }
        var fbLocale = typeof fb.default_locale === 'string' ? String(fb.default_locale).trim() : '';
        if (fbLocale !== '' && getPrimaryDefaultLocaleValue() === '') {
            setAllDefaultLocaleSelects(fbLocale);
        }
        var fbPlanLocale = typeof fb.plan_locale === 'string' ? String(fb.plan_locale).trim() : '';
        if (fbPlanLocale !== '' && getPrimaryPlanLocaleValue() === '') {
            setAllPlanLocaleSelects(fbPlanLocale);
        }
        var extraPanel = document.getElementById('pb-guided-extra-types-panel');
        if (extraPanel) {
            extraPanel.classList.add('open');
        }
    }

    function buildVirtualThemePatch(selectedTypesOverride) {
        var fb = guidedScopeDefaults();
        var pageTypes = Array.isArray(selectedTypesOverride) && selectedTypesOverride.length
            ? ensureRequiredPageTypeSelected(selectedTypesOverride)
            : selectedPageTypes();
        var titleEl = document.getElementById('pb-ai-site-title');
        var taglineEl = document.getElementById('pb-ai-site-tagline');
        var briefEl = document.getElementById('pb-ai-brief-description');
        var rawDomain = getTargetDomainTrimmed();
        var domainVal = rawDomain !== '' ? rawDomain.toLowerCase() : '';
        var fbDomain = typeof fb.target_domain === 'string' ? String(fb.target_domain).trim().toLowerCase() : '';
        var localeVal = getPrimaryDefaultLocaleValue();
        var planLocaleVal = getPrimaryPlanLocaleValue();
        var extraPanelEl = document.getElementById('pb-guided-extra-types-panel');
        var extraPageTypesPanelOpen = 1;
        var patch = {
            site_title: titleEl ? titleEl.value : (fb.site_title || ''),
            site_tagline: taglineEl ? taglineEl.value : (fb.site_tagline || ''),
            target_domain: domainVal !== '' ? domainVal : fbDomain,
            default_locale: localeVal !== '' ? localeVal : (fb.default_locale || ''),
            plan_locale: planLocaleVal,
            brief_description: briefEl ? briefEl.value : (fb.brief_description || ''),
            user_description: briefEl ? briefEl.value : (fb.brief_description || ''),
            page_types: pageTypes,
            page_types_user_customized: pageTypesUserCustomized ? 1 : 0,
            extra_page_types_panel_open: extraPageTypesPanelOpen,
            site_profile_manual: Object.assign({}, siteProfileManual)
        };
        // SSE 鏂规娴佸畬鎴愬悗锛屾妸鐢熸垚鐨勬柟妗堝唴瀹瑰悎骞惰繘 scope patch锛岃Е鍙戣嚜鍔ㄤ繚瀛?
        var draftMarkdown = currentPlanPayload && typeof currentPlanPayload === 'object'
            ? String(currentPlanPayload.markdown || '').trim()
            : '';
        if (draftMarkdown !== '') {
            patch.plan_markdown = draftMarkdown;
            // 閬垮厤鍦ㄢ€滃紑濮嬫瀯寤?闃舵浜屾搷浣溾€濇椂璇妸宸茬‘璁ょ殑闃舵涓€鐘舵€佹墦鍥炴湭纭銆?
            // 浠呭湪褰撳墠浼氳瘽鏈氨鏈‘璁ら樁娈典竴鏃讹紝鎵嶆樉寮忎繚鎸?0銆?
            if (!planConfirmedState) {
                patch.plan_confirmed = 0;
            }
            if (currentPlanPayload.json && typeof currentPlanPayload.json === 'object' && Object.keys(currentPlanPayload.json).length > 0) {
                patch.plan_json = currentPlanPayload.json;
            }
        }
        return patch;
    }

    function validateVirtualThemeInputs() {
        var fb = guidedScopeDefaults();
        var titleEl = document.getElementById('pb-ai-site-title');
        var titleValue = titleEl ? String(titleEl.value || '').trim() : '';
        if (titleValue === '') {
            titleValue = typeof fb.site_title === 'string' ? String(fb.site_title).trim() : '';
        }
        if (titleValue === '') {
            toast('warning', messages.siteTitleRequired);
            if (titleEl && typeof titleEl.focus === 'function') {
                titleEl.focus();
            }
            return false;
        }

        var briefEl = document.getElementById('pb-ai-brief-description');
        var briefValue = briefEl ? String(briefEl.value || '').trim() : '';
        if (briefValue === '') {
            briefValue = typeof fb.brief_description === 'string' ? String(fb.brief_description).trim() : '';
        }
        if (briefValue === '') {
            toast('warning', messages.briefRequired);
            if (briefEl && typeof briefEl.focus === 'function') {
                briefEl.focus();
            }
            return false;
        }

        setTargetDomainFieldInvalid(false);
        return true;
    }

    /** 闃舵浜屽叆鍙ｏ細鏄惁宸插叿澶囬樁娈典竴寤虹珯鏂规锛堜笌鐩爣鍩熷悕鏃犲叧锛?*/
    function hasPhaseOnePlanPresent() {
        var md = String((currentPlanPayload && currentPlanPayload.markdown) || '').trim();
        if (md !== '') {
            return true;
        }
        var cp = window.__pbWorkspaceConfirmedPlan;
        if (cp && typeof cp === 'object' && String(cp.markdown || '').trim() !== '') {
            return true;
        }
        if (planConfirmedState || hasVirtualThemePlanState) {
            return true;
        }
        var pj = currentPlanPayload && currentPlanPayload.json;
        if (pj && typeof pj === 'object' && !Array.isArray(pj) && Object.keys(pj).length > 0) {
            return true;
        }
        return false;
    }

    function ensureTargetDomainOkBeforeBuild() {
        var domain = getTargetDomainTrimmed();
        if (domain === '') {
            var fbDom = guidedScopeDefaults();
            var fbDomRaw = typeof fbDom.target_domain === 'string' ? String(fbDom.target_domain).trim() : '';
            if (fbDomRaw !== '') {
                domain = fbDomRaw.toLowerCase();
            }
        }
        if (domain === '') {
            bindTargetDomainInvalidClearOnce();
            setTargetDomainFieldInvalid(true);
            toast('warning', messages.domainRequiredForBuild);
            return Promise.resolve(false);
        }
        if (/\.weline\.local$/i.test(domain)) {
            return Promise.resolve(true);
        }
        if (isGuidedFakeModeSession()) {
            return Promise.resolve(true);
        }
        var registrarSelection = getRegistrarSelection();
        if (registrarSelection.accountId <= 0) {
            toast('warning', messages.domainCheckNeedRegistrar);
            return Promise.resolve(false);
        }
        if (!checkDomainUrl) {
            return Promise.resolve(true);
        }
        return postForm(checkDomainUrl, {
            domain: domain,
            account_id: String(registrarSelection.accountId)
        }).then(function (data) {
            if (data && data.success && data.available) {
                return true;
            }
            toast('warning', (data && data.message) ? String(data.message) : messages.domainConfirmCheckFailed);
            return false;
        }).catch(function () {
            toast('error', messages.networkError);
            return false;
        });
    }

    function resetBuildStartUi() {
        setRunVirtualThemeButtonsDisabled(false);
    }

    function debounce(fn, wait) {
        var timer = null;
        return function () {
            var args = arguments;
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { fn.apply(null, args); }, wait);
        };
    }

    var autoSaveScope = debounce(function () {
        if (!mergeScopeUrl || !publicId) {
            console.warn('Auto-save skipped: missing mergeScopeUrl or publicId', { mergeScopeUrl, publicId });
            return;
        }
        try {
            var scopePatch = buildVirtualThemePatch();
            postForm(mergeScopeUrl, { public_id: publicId, autosave: '1', scope_patch: JSON.stringify(scopePatch) }).then(function (data) {
                if (!data || !data.success) {
                    toast('error', (data && data.message) ? String(data.message) : messages.saveFailed);
                    return;
                }
                if (data.data && typeof data.data === 'object') {
                    syncWorkspacePageTypeState(data.data);
                    hydrateWorkspaceFromState(data.data);
                }
            }).catch(function (err) {
                console.error('Auto-save error:', err);
                toast('error', typeof err === 'object' && err.message ? String(err.message) : messages.networkError);
            });
        } catch (err) {
            console.error('Auto-save scope construction error:', err);
            toast('error', messages.networkError);
        }
    }, 500);

    var tabClickLocked = false;
    function getPreviewTabContainers() {
        var containers = Array.from(document.querySelectorAll('.pb-ai-preview-tabs'));
        if (containers.length > 0) {
            return containers;
        }
        var ensured = ensurePreviewTabsList();
        return ensured ? [ensured] : [];
    }

    function normalizePageTypeLabel(pageType) {
        return pageTypeLabelMap[pageType] || pageType;
    }

    function compactPageDisplayLabel(pageLabel, pageType) {
        var fallback = normalizePageTypeLabel(pageType || '');
        var normalized = String(pageLabel || '').trim();
        if (!normalized) {
            return fallback || String(pageType || '');
        }
        var match = normalized.match(/^(.*?)\s*-\s*.+$/);
        if (match && match[1] && match[1].trim()) {
            normalized = match[1].trim();
        }
        normalized = normalized.replace(/^[\s(（\[【]+|[\s)）\]】]+$/g, '').trim();
        return normalized || fallback || String(pageType || '');
    }

    function focusEmbeddedEditor() {
        var frame = document.getElementById('pb-ai-visual-preview-frame');
        if (!frame) {
            return;
        }
        if (typeof frame.scrollIntoView === 'function') {
            frame.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function setPreviewFrameUrl(url) {
        var frame = document.getElementById('pb-ai-visual-preview-frame');
        if (!frame || !url) {
            return;
        }
        clearVisualPreviewErrorOverlay();
        applyPreviewDeviceMode(currentPreviewDevice);
        var s = String(url);
        try {
            var u = new URL(s, window.location.href);
            u.searchParams.set('_pb_pv', String(Date.now()));
            frame.setAttribute('src', u.toString());
        } catch (e) {
            var sep = s.indexOf('?') >= 0 ? '&' : '?';
            frame.setAttribute('src', s + sep + '_pb_pv=' + encodeURIComponent(String(Date.now())));
        }
        // T31: 璁剧疆 iframe 鍔犺浇瀹屾垚鍚庨噸鍐欏唴閮ㄩ摼鎺?
        frame.onload = function () {
            applyPreviewLinkRewrite(frame);
        };
    }

    /**
     * T31: 棰勮鍐呴摼鎺ラ噸鍐欏櫒
     * 鎷︽埅 iframe 鍐呯殑鍐呴儴椤甸潰绫诲瀷閾炬帴锛岄噸鍐欏埌鍙鍖栫紪杈戜笂涓嬫枃
     * 澶栭儴閾炬帴淇濇寔鍘熻涔変笉鍙?     */
    function applyPreviewLinkRewrite(frame) {
        if (!frame || !frame.contentWindow || !frame.contentDocument) {
            return;
        }
        try {
            var doc = frame.contentDocument;
            if (!doc) {
                return;
            }
            var currentBaseUrl = frame.getAttribute('src') || '';
            var currentHost = '';
            try {
                var currentUrlObj = new URL(currentBaseUrl, window.location.href);
                currentHost = currentUrlObj.origin;
            } catch (e) {
                currentHost = '';
            }
            var links = doc.querySelectorAll('a[href]');
            links.forEach(function (link) {
                var href = link.getAttribute('href');
                if (!href) {
                    return;
                }
                // 璺宠繃绌洪敋鐐广€丣avaScript 鍗忚
                if (href.trim() === '' || href.trim().startsWith('#') || href.trim().startsWith('javascript:')) {
                    return;
                }
                var fullHref = href;
                try {
                    var linkUrl = new URL(href, currentBaseUrl);
                    fullHref = linkUrl.href;
                } catch (e) {
                    // 鐩稿璺緞锛屼繚鎸佸師鍊?
                }
                // 妫€鏌ユ槸鍚︽槸澶栭儴閾炬帴锛堜笉鍚屽煙鍚嶏級
                if (currentHost !== '' && !fullHref.startsWith(currentHost)) {
                    // 澶栭儴閾炬帴锛氭爣璁颁负鏂扮獥鍙ｆ墦寮€锛屼繚鎸佸師璇箟
                    link.setAttribute('target', '_blank');
                    link.setAttribute('rel', 'noopener noreferrer');
                    return;
                }
                // 鍐呴儴閾炬帴锛氳В鏋愰〉闈㈢被鍨嬪苟閲嶅啓涓哄彲瑙嗗寲缂栬緫涓婁笅鏂?
                var internalPath = fullHref.replace(currentHost, '');
                // 鎻愬彇璺緞涓殑椤甸潰绫诲瀷鏍囪瘑锛堝 /about/, /contact/, /products/锛?
                var pageType = detectPageTypeFromPath(internalPath);
                if (pageType && typeof rewriteInternalLinkToVisualEdit === 'function') {
                    var rewrittenUrl = rewriteInternalLinkToVisualEdit(pageType, fullHref);
                    if (rewrittenUrl) {
                        link.setAttribute('href', rewrittenUrl);
                        link.removeAttribute('target');
                    }
                }
            });
        } catch (e) {
            // 璺ㄥ煙璁块棶鎴?iframe 鏈畬鍏ㄥ姞杞芥椂闈欓粯蹇界暐
        }
    }

    /**
     * T31: 浠?URL 璺緞妫€娴嬮〉闈㈢被鍨?     * @param {string} path URL 璺緞
     * @returns {string|null} 椤甸潰绫诲瀷鏍囪瘑绗?     */
    function detectPageTypeFromPath(path) {
        if (!path) {
            return null;
        }
        // 瑙勮寖鍖栬矾寰?
        path = path.split('?')[0].split('#')[0].replace(/\/+$/, '').toLowerCase();
        // 甯歌椤甸潰绫诲瀷璺緞鏄犲皠
        var pageTypePatterns = {
            'home_page': ['', '/', '/index', '/index.html', '/home'],
            'about_page': ['/about', '/about-us', '/aboutus', '/guanyu'],
            'contact_page': ['/contact', '/contact-us', '/contactus', '/lianxi'],
            'product_page': ['/products', '/product', '/products.html', '/chanpin'],
            'service_page': ['/services', '/service', '/services.html', '/fuwu'],
            'blog_page': ['/blog', '/news', '/blogs', '/xinwen'],
            'privacy_page': ['/privacy', '/privacy-policy', '/yinsizhengce'],
            'terms_page': ['/terms', '/terms-of-service', '/fuwutiaokuan'],
        };
        for (var pageType in pageTypePatterns) {
            if (!pageTypePatterns.hasOwnProperty(pageType)) {
                continue;
            }
            var patterns = pageTypePatterns[pageType];
            for (var i = 0; i < patterns.length; i++) {
                if (path === patterns[i] || path.endsWith(patterns[i] + '/')) {
                    return pageType;
                }
            }
        }
        return null;
    }

    /**
     * T31: 灏嗗唴閮ㄩ〉闈㈢被鍨嬮摼鎺ラ噸鍐欎负鍙鍖栫紪杈戜笂涓嬫枃
     * @param {string} pageType 椤甸潰绫诲瀷鏍囪瘑绗?     * @param {string} originalUrl 鍘熷閾炬帴
     * @returns {string|null} 閲嶅啓鍚庣殑 URL
     */
    function rewriteInternalLinkToVisualEdit(pageType, originalUrl) {
        // 浼樺厛浣跨敤褰撳墠椤甸潰鐨?page_id 杩涜缂栬緫
        var pageId = getPageIdByType(pageType);
        if (pageId && visualEditBaseUrl) {
            try {
                var editUrl = new URL(visualEditBaseUrl, window.location.href);
                editUrl.searchParams.set('page_id', String(pageId));
                editUrl.searchParams.set('_pb_ref', encodeURIComponent(originalUrl));
                return editUrl.toString();
            } catch (e) {
                // Fallback
            }
        }
        // 濡傛灉娌℃湁 page_id锛屽皾璇曚娇鐢ㄩ〉闈㈢被鍨嬪弬鏁?
        if (visualEditBaseUrl) {
            try {
                var typeUrl = new URL(visualEditBaseUrl, window.location.href);
                typeUrl.searchParams.set('page_type', pageType);
                typeUrl.searchParams.set('_pb_ref', encodeURIComponent(originalUrl));
                return typeUrl.toString();
            } catch (e) {
                // Fallback to original
            }
        }
        // 鏃犳硶閲嶅啓鏃惰繑鍥炲師濮嬮摼鎺?
        return originalUrl;
    }

    /**
     * T31: 鏍规嵁椤甸潰绫诲瀷鑾峰彇 page_id
     * @param {string} pageType 椤甸潰绫诲瀷鏍囪瘑绗?     * @returns {number|string|null} page_id
     */
    function getPageIdByType(pageType) {
        if (!pageType || !window.__pbWorkspacePagesByType) {
            return null;
        }
        var pageData = window.__pbWorkspacePagesByType[pageType];
        if (pageData && pageData.page_id) {
            return parseInt(pageData.page_id, 10) || null;
        }
        return null;
    }

    function setVisualEditorUrl(url) {
        if (!url) {
            visualEditBaseUrl = '';
            return;
        }
        visualEditBaseUrl = String(url);
    }

    function normalizeEmbeddedEditorTriggers() {
        document.querySelectorAll('.pb-ai-focus-embedded-editor').forEach(function (trigger) {
            if (trigger.dataset.embeddedLabelApplied === '1') {
                return;
            }
            trigger.dataset.embeddedLabelApplied = '1';
            trigger.textContent = messages.focusEmbeddedPreview;
            trigger.setAttribute('title', messages.focusEmbeddedPreview);
        });
    }

    function syncPreviewMetaFromState(workspaceState) {
        if (!workspaceState || typeof workspaceState !== 'object') {
            return;
        }
        if (workspaceState.visual_edit_url) {
            setVisualEditorUrl(String(workspaceState.visual_edit_url));
        }
        if (workspaceState.visual_preview_url) {
            currentEmbeddedVisualUrl = String(workspaceState.visual_preview_url);
        } else if (workspaceState.visual_edit_url) {
            currentEmbeddedVisualUrl = String(workspaceState.visual_edit_url);
        } else {
            currentEmbeddedVisualUrl = '';
        }
        renderVisualPreviewHost(currentEmbeddedVisualUrl);
        if (currentEmbeddedVisualUrl) {
            setPreviewFrameUrl(currentEmbeddedVisualUrl);
        } else {
            clearVisualPreviewErrorOverlay();
        }
        if (workspaceState.preview_page_type) {
            currentPreviewPageType = String(workspaceState.preview_page_type);
        }
        if (workspaceState.preview_page_id) {
            currentPreviewPageId = parseInt(workspaceState.preview_page_id || 0, 10) || 0;
        }
        if (workspaceState.virtual_pages_by_type && typeof workspaceState.virtual_pages_by_type === 'object') {
            mergeVirtualPagesByTypeState(workspaceState.virtual_pages_by_type);
        }
        var pagesByType = workspaceState.pagebuilder_pages_by_type && typeof workspaceState.pagebuilder_pages_by_type === 'object'
            ? workspaceState.pagebuilder_pages_by_type
            : {};
        renderMaterializedPagesByType(pagesByType);
        syncVisualActionBar(workspaceState);
        Object.keys(pagesByType).forEach(function (pageType) {
            var pageData = pagesByType[pageType] && typeof pagesByType[pageType] === 'object' ? pagesByType[pageType] : {};
            var pageId = parseInt(pageData.page_id || 0, 10) || 0;
            if (!pageId) {
                return;
            }
            document.querySelectorAll('.pb-ai-preview-tab[data-page-type="' + pageType + '"]').forEach(function (tab) {
                tab.setAttribute('data-page-id', String(pageId));
                tab.setAttribute('data-page-value', String(pageId));
            });
        });
        if (currentPreviewPageType) {
            setActiveTab(currentPreviewPageType);
        }
    }

    function upsertPreviewTab(pageType, pageLabel, pageId, shouldActivate) {
        if (!pageType) {
            return;
        }
        getPreviewTabContainers().forEach(function (container) {
            var tab = container.querySelector('.pb-ai-preview-tab[data-page-type="' + pageType + '"]');
            if (!tab) {
                var li = document.createElement('li');
                li.className = 'nav-item';
                li.setAttribute('role', 'presentation');
                tab = document.createElement('button');
                tab.type = 'button';
                tab.className = 'nav-link pb-ai-preview-tab';
                tab.setAttribute('data-page-type', pageType);
                tab.addEventListener('click', function () { switchPreviewByTab(tab); });
                li.appendChild(tab);
                container.appendChild(li);
            }
            tab.setAttribute('data-page-id', String(pageId > 0 ? pageId : 0));
            tab.setAttribute('data-page-value', String(pageId > 0 ? pageId : pageType));
            tab.setAttribute('data-page-label', compactPageDisplayLabel(pageLabel, pageType));
            if (!tab.getAttribute('data-generation-status')) {
                tab.setAttribute('data-generation-status', 'idle');
            }
            if (!tab.getAttribute('data-generation-text')) {
                tab.setAttribute('data-generation-text', '');
            }
            applyPreviewTabGenerationStatus(tab);
            if (shouldActivate) {
                setActiveTab(pageType);
            }
        });
    }

    function applyPreviewTabGenerationStatus(tab) {
        if (!tab) {
            return;
        }
        var baseLabel = String(tab.getAttribute('data-page-label') || tab.textContent || '').trim();
        var status = String(tab.getAttribute('data-generation-status') || 'idle').trim();
        var statusText = String(tab.getAttribute('data-generation-text') || '').trim();
        var suffix = statusText;
        if (suffix.length > 14) {
            suffix = status === 'done'
                ? ""                : (status === 'ready'
                    ? ""                    : "");
        }
        tab.textContent = suffix !== '' ? (baseLabel + ' 路 ' + suffix) : baseLabel;
        tab.classList.toggle('text-success', status === 'done');
        tab.classList.toggle('text-warning', status === 'running' || status === 'ready');
    }

    function buildLabelMapFromChecks() {
        var map = Object.create(null);
        document.querySelectorAll('.pb-ai-page-type-check').forEach(function (check) {
            var type = String(check.value || '');
            if (!type) {
                return;
            }
            var labelEl = check.closest('label');
            var text = labelEl ? String(labelEl.textContent || '').trim() : type;
            map[type] = text || type;
        });
        return map;
    }

    // 浠诲姟杩涘害缁熶竴鍦ㄤ晶杈规爮闈㈡澘灞曠ず锛涗繚鐣欑┖瀹炵幇渚?runtime 鍥炶皟鍏煎銆?
    function markPageGenerationStatus(pageType, status, text) {
        var normalizedPageType = String(pageType || '').trim();
        if (!normalizedPageType) {
            return;
        }
        var normalizedStatus = String(status || 'idle').trim().toLowerCase();
        var normalizedText = String(text || '').trim();
        getPreviewTabContainers().forEach(function (container) {
            var tab = container.querySelector('.pb-ai-preview-tab[data-page-type="' + normalizedPageType + '"]');
            if (!tab) {
                return;
            }
            tab.setAttribute('data-generation-status', normalizedStatus);
            tab.setAttribute('data-generation-text', normalizedText);
            applyPreviewTabGenerationStatus(tab);
        });
    }

    // 鍏煎鍘嗗彶璋冪敤鏂癸細鏃у懡鍚?queueGenerationStatus 宸茶澶栭儴鑴氭湰寮曠敤銆?
    function queueGenerationStatus(pageType, status, text) {
        return markPageGenerationStatus(pageType, status, text);
    }

    function syncPreviewTabsWithSelection() {
        var selectedMap = Object.create(null);
        selectedPageTypes().forEach(function (type) { selectedMap[type] = true; });
        var labelMap = buildLabelMapFromChecks();
        getPreviewTabContainers().forEach(function (container) {
            var existing = Array.from(container.querySelectorAll('.pb-ai-preview-tab'));
            existing.forEach(function (tab) {
                var pageType = String(tab.getAttribute('data-page-type') || '');
                if (pageType && !selectedMap[pageType]) {
                    var li = tab.closest('li');
                    if (li) {
                        li.remove();
                    }
                }
            });
            Object.keys(selectedMap).forEach(function (pageType) {
                if (container.querySelector('.pb-ai-preview-tab[data-page-type="' + pageType + '"]')) {
                    return;
                }
                var li = document.createElement('li');
                li.className = 'nav-item';
                li.setAttribute('role', 'presentation');
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'nav-link pb-ai-preview-tab';
                btn.setAttribute('data-page-id', '0');
                btn.setAttribute('data-page-type', pageType);
                btn.setAttribute('data-page-value', pageType);
                btn.setAttribute('data-page-label', labelMap[pageType] || normalizePageTypeLabel(pageType));
                btn.setAttribute('data-generation-status', 'idle');
                btn.setAttribute('data-generation-text', '');
                applyPreviewTabGenerationStatus(btn);
                btn.addEventListener('click', function () { switchPreviewByTab(btn); });
                li.appendChild(btn);
                container.appendChild(li);
            });
        });
    }

    function setActiveTab(pageType) {
        document.querySelectorAll('.pb-ai-preview-tab').forEach(function (tab) {
            var tabType = String(tab.getAttribute('data-page-type') || '');
            tab.classList.toggle('active', tabType === pageType);
        });
        if (pageType) {
            currentPreviewPageType = String(pageType);
        }
    }

    function getActivePreviewPageType() {
        var activeTab = document.querySelector('.pb-ai-preview-tab.active');
        if (activeTab) {
            return String(activeTab.getAttribute('data-page-type') || '');
        }
        return currentPreviewPageType || '';
    }

    function findPreviewTabByPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return null;
        }
        var pageType = String(payload.page_type || '');
        if (pageType) {
            return document.querySelector('.pb-ai-preview-tab[data-page-type="' + pageType + '"]');
        }
        var pageId = parseInt(payload.page_id || 0, 10) || 0;
        if (pageId > 0) {
            return document.querySelector('.pb-ai-preview-tab[data-page-id="' + pageId + '"]');
        }
        return null;
    }

    function buildComponentLabel(payload) {
        if (!payload || typeof payload !== 'object') {
            return '';
        }
        var region = String(payload.region || '');
        var componentCode = String(payload.component || '');
        var regionLabelMap = {
            header: '椤靛ご',
            content: '鍐呭',
            footer: '椤佃剼'
        };
        var regionLabel = regionLabelMap[region] || '鍖哄潡';
        var shortCode = componentCode;
        if (shortCode.indexOf('/') >= 0) {
            shortCode = shortCode.split('/').pop() || shortCode;
        }
        shortCode = shortCode.replace(/[-_]+/g, ' ').trim();
        return regionLabel + ' / ' + (shortCode || componentCode || 'block');
    }

    function cloneJson(value) {
        try {
            return JSON.parse(JSON.stringify(value || {}));
        } catch (error) {
            return {};
        }
    }

    function getVirtualPageState(pageType) {
        if (!pageType || !virtualPagesByTypeState || typeof virtualPagesByTypeState !== 'object') {
            return null;
        }
        return virtualPagesByTypeState[pageType] && typeof virtualPagesByTypeState[pageType] === 'object'
            ? virtualPagesByTypeState[pageType]
            : null;
    }

    function findVirtualBlock(pageType, blockId) {
        var pageState = getVirtualPageState(pageType);
        if (!pageState || !Array.isArray(pageState.blocks)) {
            return null;
        }
        for (var i = 0; i < pageState.blocks.length; i += 1) {
            var block = pageState.blocks[i];
            if (!block || typeof block !== 'object') {
                continue;
            }
            if (String(block.block_id || '') === String(blockId || '')) {
                return block;
            }
        }
        return null;
    }

    function updateVirtualBlockState(pageType, block) {
        if (!pageType || !block || typeof block !== 'object') {
            return;
        }
        if (!virtualPagesByTypeState || typeof virtualPagesByTypeState !== 'object') {
            virtualPagesByTypeState = {};
        }
        if (!virtualPagesByTypeState[pageType] || typeof virtualPagesByTypeState[pageType] !== 'object') {
            virtualPagesByTypeState[pageType] = { blocks: [] };
        }
        if (!Array.isArray(virtualPagesByTypeState[pageType].blocks)) {
            virtualPagesByTypeState[pageType].blocks = [];
        }
        var blocks = virtualPagesByTypeState[pageType].blocks;
        var replaced = false;
        for (var i = 0; i < blocks.length; i += 1) {
            if (String(blocks[i] && blocks[i].block_id || '') !== String(block.block_id || '')) {
                continue;
            }
            blocks[i] = block;
            replaced = true;
            break;
        }
        if (!replaced) {
            blocks.push(block);
        }
    }

    function fieldValueToText(fieldKey, fieldDef, config) {
        var raw = config && Object.prototype.hasOwnProperty.call(config, fieldKey) ? config[fieldKey] : '';
        var format = String(fieldDef && fieldDef.format || '');
        if (format === 'lines' && Array.isArray(raw)) {
            return raw.join('\n');
        }
        if (format === 'nav-lines') {
            if (Array.isArray(raw)) {
                return raw.map(function (item) {
                    if (!item || typeof item !== 'object') {
                        return '';
                    }
                    return [String(item.label || item.text || ''), String(item.href || item.url || '')].join('=>');
                }).filter(Boolean).join('\n');
            }
            return raw == null ? '' : String(raw);
        }
        if (format === 'nav-items' && Array.isArray(raw)) {
            return raw.map(function (item) {
                if (!item || typeof item !== 'object') {
                    return '';
                }
                return [String(item.label || ''), String(item.href || '')].join('|');
            }).filter(Boolean).join('\n');
        }
        if (format === 'card-items' && Array.isArray(raw)) {
            return raw.map(function (item) {
                if (!item || typeof item !== 'object') {
                    return '';
                }
                return [String(item.eyebrow || ''), String(item.title || ''), String(item.description || '')].join('|');
            }).filter(Boolean).join('\n');
        }
        if (Array.isArray(raw)) {
            return raw.join('\n');
        }
        return raw == null ? '' : String(raw);
    }

    function parseFieldValue(fieldDef, textValue) {
        var format = String(fieldDef && fieldDef.format || '');
        var raw = String(textValue || '').trim();
        if (format === 'lines') {
            if (raw === '') {
                return [];
            }
            return raw.split(/\r?\n/).map(function (line) { return line.trim(); }).filter(Boolean);
        }
        if (format === 'nav-lines') {
            if (raw === '') {
                return '';
            }
            return raw.split(/\r?\n/).map(function (line) {
                var text = String(line || '').trim();
                if (!text) {
                    return '';
                }
                if (text.indexOf('=>') >= 0) {
                    return text;
                }
                var parts = text.split('|');
                var label = String(parts[0] || '').trim();
                var href = String(parts[1] || '').trim();
                if (!label) {
                    return '';
                }
                return label + '=>' + (href || '#');
            }).filter(Boolean).join('\n');
        }
        if (format === 'nav-items') {
            if (raw === '') {
                return [];
            }
            return raw.split(/\r?\n/).map(function (line, index) {
                var parts = line.split('|');
                var label = String(parts[0] || '').trim();
                var href = String(parts[1] || '').trim();
                if (!label) {
                    return null;
                }
                return {
                    label: label,
                    href: href || '#',
                    active: index === 0
                };
            }).filter(Boolean);
        }
        if (format === 'card-items') {
            if (raw === '') {
                return [];
            }
            return raw.split(/\r?\n/).map(function (line) {
                var parts = line.split('|');
                var title = String(parts[1] || parts[0] || '').trim();
                if (!title) {
                    return null;
                }
                return {
                    eyebrow: String(parts[0] || '').trim(),
                    title: title,
                    description: String(parts[2] || '').trim()
                };
            }).filter(Boolean);
        }
        return raw;
    }

    function renderBlockEditorFields(fieldSchema, blockConfig) {
        var container = document.getElementById('pb-ai-edit-block-fields');
        var hintEl = document.getElementById('pb-ai-edit-block-format-hint');
        if (!container || !hintEl) {
            return;
        }
        container.innerHTML = '';
        hintEl.classList.add('d-none');
        hintEl.textContent = '';

        Object.keys(fieldSchema || {}).forEach(function (groupKey) {
            var group = fieldSchema[groupKey] || {};
            var card = document.createElement('div');
            card.className = 'border rounded p-3';

            var title = document.createElement('div');
            title.className = 'fw-semibold mb-3';
            title.textContent = String(group.label || groupKey);
            card.appendChild(title);

            Object.keys(group.fields || {}).forEach(function (fieldKey) {
                var field = group.fields[fieldKey] || {};
                var wrap = document.createElement('div');
                wrap.className = 'mb-3';
                var label = document.createElement('label');
                label.className = 'form-label';
                label.setAttribute('for', 'pb-ai-edit-field-' + fieldKey.replace(/[^a-zA-Z0-9_-]/g, '-'));
                label.textContent = String(field.label || fieldKey);
                wrap.appendChild(label);

                var input;
                var fieldType = String(field.type || 'text');
                if (fieldType === 'textarea') {
                    input = document.createElement('textarea');
                    input.rows = 5;
                    if (field.format === 'nav-items') {
                        hintEl.classList.remove('d-none');
                        hintEl.textContent = "导航项格式：标题|链接，每行一项";
                    } else if (field.format === 'nav-lines') {
                        hintEl.classList.remove('d-none');
                        hintEl.textContent = "导航项格式：标题=>链接，每行一项";
                    } else if (field.format === 'card-items') {
                        hintEl.classList.remove('d-none');
                        hintEl.textContent = "卡片格式：上方标签|标题|描述，每行一项";
                    } else if (field.format === 'lines') {
                        hintEl.classList.remove('d-none');
                        hintEl.textContent = "列表格式：每行一项";
                    }
                } else if (fieldType === 'select' && Array.isArray(field.options) && field.options.length > 0) {
                    input = document.createElement('select');
                    field.options.forEach(function (optionValue) {
                        var option = document.createElement('option');
                        option.value = String(optionValue);
                        option.textContent = String(optionValue);
                        input.appendChild(option);
                    });
                } else {
                    input = document.createElement('input');
                    input.type = fieldType === 'number' ? 'number' : 'text';
                }

                input.className = 'form-control';
                input.id = 'pb-ai-edit-field-' + fieldKey.replace(/[^a-zA-Z0-9_-]/g, '-');
                input.setAttribute('data-field-key', fieldKey);
                input.setAttribute('data-field-type', fieldType);
                input.setAttribute('data-field-format', String(field.format || ''));
                input.value = fieldValueToText(fieldKey, field, blockConfig);
                wrap.appendChild(input);
                card.appendChild(wrap);
            });

            container.appendChild(card);
        });
    }

    function serializeBlockEditorConfig() {
        var config = {};
        document.querySelectorAll('#pb-ai-edit-block-fields [data-field-key]').forEach(function (input) {
            var fieldKey = String(input.getAttribute('data-field-key') || '');
            if (!fieldKey) {
                return;
            }
            var fieldDef = {
                type: String(input.getAttribute('data-field-type') || 'text'),
                format: String(input.getAttribute('data-field-format') || '')
            };
            config[fieldKey] = parseFieldValue(fieldDef, input.value);
        });
        return config;
    }

    function replaceCurrentBlockHtml(pageType, block) {
        var frame = getEmbeddedPreviewFrame();
        if (!frame || !block || typeof block !== 'object') {
            return false;
        }
        var doc;
        try {
            doc = frame.contentDocument;
        } catch (error) {
            return false;
        }
        if (!doc) {
            return false;
        }
        var selector = '.pb-ai-block-wrapper[data-page-type="' + pageType + '"][data-component="' + String(block.block_id || '') + '"]';
        var wrapper = doc.querySelector(selector);
        if (!wrapper) {
            return false;
        }
        var label = wrapper.querySelector('.pb-ai-block-label');
        var actions = wrapper.querySelector('.component-actions');
        wrapper.innerHTML = ''
            + (label ? label.outerHTML : '')
            + (actions ? actions.outerHTML : '')
            + String(block.html || '');
        return true;
    }

    function resolveBootstrapModalInstance(modalEl) {
        if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return null;
        }
        if (typeof bootstrap.Modal.getOrCreateInstance === 'function') {
            return bootstrap.Modal.getOrCreateInstance(modalEl);
        }
        if (typeof bootstrap.Modal.getInstance === 'function') {
            return bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
        }
        return new bootstrap.Modal(modalEl);
    }

    function openBlockSseModal(title, contextText, url, formData) {
        var modalEl = document.getElementById('pb-ai-block-sse-modal');
        var titleEl = document.getElementById('pb-ai-block-sse-modal-title');
        var contextEl = document.getElementById('pb-ai-block-sse-context');
        var applyBtn = document.getElementById('pb-ai-block-sse-apply');
        var terminal = window.WelineSseTerminal ? window.WelineSseTerminal['pb-ai-block-sse-terminal'] : null;
        if (!modalEl || !terminal) {
            toast('error', messages.blockSseUnavailable);
            return false;
        }

        if (titleEl) {
            titleEl.textContent = String(title || 'AI Block Stream');
        }
        if (contextEl) {
            contextEl.textContent = String(contextText || '');
        }

        if (terminal.stop) {
            terminal.stop();
        }
        if (terminal.clear) {
            terminal.clear();
        }
        pendingBlockSseResult = null;
        if (applyBtn) {
            applyBtn.classList.add('d-none');
            applyBtn.disabled = true;
        }

        terminal.on('done', function (event) {
            var payload = parsePayload(event);
            if (payload && payload.state) {
                hydrateWorkspaceFromState(payload.state);
            }
            if (payload && payload.state && payload.state.virtual_pages_by_type) {
                mergeVirtualPagesByTypeState(payload.state.virtual_pages_by_type);
            }
            pendingBlockSseResult = payload && typeof payload === 'object' ? payload : null;
            if (applyBtn) {
                applyBtn.classList.remove('d-none');
                applyBtn.disabled = false;
            }
            if (contextEl) {
                contextEl.textContent = messages.blockSseDoneConfirm;
            }
        });

        terminal.on('page_generated', function (event) {
            var payload = parsePayload(event);
            if (payload && payload.state) {
                hydrateWorkspaceFromState(payload.state);
            }
            if (payload && payload.state && payload.state.virtual_pages_by_type) {
                mergeVirtualPagesByTypeState(payload.state.virtual_pages_by_type);
            }
        });

        var modalInstance = resolveBootstrapModalInstance(modalEl);
        if (modalInstance && typeof modalInstance.show === 'function') {
            modalInstance.show();
        }

        window.setTimeout(function () {
            terminal.start(url, { method: 'POST', body: formData });
        }, PB_SSE_CONNECT_DELAY_MS);

        return true;
    }

    function ensureWrapperActionButtons(wrapper) {
        if (!wrapper) {
            return;
        }
        var actions = wrapper.querySelector('.component-actions');
        if (!actions) {
            return;
        }

        var refineBtn = actions.querySelector('[data-pb-action="refine"]');
        if (refineBtn) {
            refineBtn.textContent = 'AI Refine';
            refineBtn.setAttribute('title', 'AI Refine current block');
        }

        var editBtn = actions.querySelector('[data-pb-action="open-editor"]');
        if (editBtn) {
            editBtn.textContent = 'Edit';
            editBtn.setAttribute('title', 'Open block editor');
        }

        var rebuildBtn = actions.querySelector('[data-pb-action="regenerate-block"]');
        if (!rebuildBtn) {
            rebuildBtn = document.createElement('button');
            rebuildBtn.type = 'button';
            rebuildBtn.className = 'component-action-btn component-action-rebuild';
            rebuildBtn.setAttribute('data-pb-action', 'regenerate-block');
            rebuildBtn.setAttribute('title', 'AI Regenerate current block');
            rebuildBtn.textContent = 'AI Rebuild';
            rebuildBtn.style.background = '#f59e0b';
            rebuildBtn.style.color = '#ffffff';
            if (editBtn && editBtn.parentNode === actions) {
                actions.insertBefore(rebuildBtn, editBtn);
            } else {
                actions.appendChild(rebuildBtn);
            }
        }
    }

    (function initBlockSseModal() {
        var modalEl = document.getElementById('pb-ai-block-sse-modal');
        var applyBtn = document.getElementById('pb-ai-block-sse-apply');
        if (!modalEl) {
            return;
        }
        if (applyBtn) {
            applyBtn.addEventListener('click', function () {
                if (!pendingBlockSseResult || !pendingBlockSseResult.state || !pendingBlockSseResult.state.virtual_pages_by_type) {
                    toast('warning', messages.blockSseNoResult);
                    return;
                }
                var pageState = pendingBlockSseResult.state.virtual_pages_by_type[blockRefreshState.pageType] || null;
                var nextBlock = pageState && Array.isArray(pageState.blocks)
                    ? pageState.blocks.find(function (item) { return String(item.block_id || '') === String(blockRefreshState.blockId || ''); })
                    : null;
                if (!nextBlock) {
                    toast('warning', messages.blockSseNoResult);
                    clearBlockStreamingState();
                } else {
                    updateVirtualBlockState(blockRefreshState.pageType, nextBlock);
                    replaceCurrentBlockHtml(blockRefreshState.pageType, nextBlock);
                    bindEmbeddedPreviewFrameBridge();
                    clearBlockStreamingState();
                    toast('success', messages.blockSseApplied);
                }
                pendingBlockSseResult = null;
                applyBtn.classList.add('d-none');
                applyBtn.disabled = true;
                var modalInstance = resolveBootstrapModalInstance(modalEl);
                if (modalInstance && typeof modalInstance.hide === 'function') {
                    modalInstance.hide();
                }
            });
        }
        modalEl.addEventListener('hidden.bs.modal', function () {
            var terminal = window.WelineSseTerminal ? window.WelineSseTerminal['pb-ai-block-sse-terminal'] : null;
            if (terminal && terminal.stop) {
                terminal.stop();
            }
            pendingBlockSseResult = null;
            if (applyBtn) {
                applyBtn.classList.add('d-none');
                applyBtn.disabled = true;
            }
            clearBlockStreamingState();
        });
    })();

    function openRefineComponentModal(payload) {
        var modalEl = document.getElementById('pb-ai-refine-component-modal');
        var contextEl = document.getElementById('pb-ai-refine-component-context');
        var instructionEl = document.getElementById('pb-ai-refine-component-instruction');
        if (!modalEl || !contextEl || !instructionEl) {
            toast('error', messages.refineUnavailable);
            return;
        }
        var pageType = String((payload && payload.page_type) || getActivePreviewPageType() || '');
        var componentCode = String((payload && payload.component) || '');
        if (!pageType || !componentCode) {
            toast('error', messages.refineUnavailable);
            return;
        }
        refineComponentState.pageType = pageType;
        refineComponentState.componentCode = componentCode;
        refineComponentState.componentLabel = buildComponentLabel(payload);
        contextEl.textContent = (normalizePageTypeLabel(pageType) || pageType) + ' 路 ' + (refineComponentState.componentLabel || messages.refineContextFallback);
        instructionEl.value = '';
        var modalInstance = resolveBootstrapModalInstance(modalEl);
        if (!modalInstance || typeof modalInstance.show !== 'function') {
            toast('error', messages.refineUnavailable);
            return;
        }
        modalInstance.show();
        window.setTimeout(function () {
            instructionEl.focus();
        }, 120);
    }

    function openBlockEditorModal(payload) {
        var pageType = String((payload && payload.page_type) || getActivePreviewPageType() || '');
        var blockId = String((payload && payload.component) || '');
        var modalEl = document.getElementById('pb-ai-edit-block-modal');
        var contextEl = document.getElementById('pb-ai-edit-block-context');
        if (!pageType || !blockId || !modalEl || !contextEl) {
            toast('error', messages.editUnavailable);
            return;
        }

        var block = findVirtualBlock(pageType, blockId);
        if (!block || !block.field_schema || Object.keys(block.field_schema).length === 0) {
            toast('error', messages.editUnavailable);
            return;
        }

        blockEditorState.pageType = pageType;
        blockEditorState.blockId = blockId;
        blockEditorState.blockType = String(block.type || '');
        blockEditorState.blockLabel = buildComponentLabel(payload);
        blockEditorState.blockConfig = cloneJson(block.config || {});
        blockEditorState.fieldSchema = cloneJson(block.field_schema || {});
        contextEl.textContent = (normalizePageTypeLabel(pageType) || pageType) + ' 路 ' + blockEditorState.blockLabel;
        renderBlockEditorFields(blockEditorState.fieldSchema, blockEditorState.blockConfig);

        var modalInstance = resolveBootstrapModalInstance(modalEl);
        if (!modalInstance || typeof modalInstance.show !== 'function') {
            toast('error', messages.editUnavailable);
            return;
        }
        modalInstance.show();
    }

    function switchPreviewByTab(tab) {
        if (!tab || !switchPreviewPageUrl || tabClickLocked) {
            return;
        }
        var pageType = String(tab.getAttribute('data-page-type') || '');
        if (!pageType) {
            return;
        }
        var pageId = String(tab.getAttribute('data-page-id') || '0');
        currentPreviewPageType = pageType;
        currentPreviewPageId = parseInt(pageId || '0', 10) || 0;
        tabClickLocked = true;
        postForm(switchPreviewPageUrl, {
            public_id: publicId,
            preview_page_id: pageId,
            preview_page_type: pageType
        }).then(function (data) {
            tabClickLocked = false;
            if (data && data.success) {
                clearVisualPreviewErrorOverlay();
                setActiveTab(pageType);
                toast('success', messages.switched);
                syncPreviewMetaFromState(data.data || {});
                return;
            }
            var errLine = (data && data.message) ? String(data.message) : messages.networkError;
            toast('error', errLine);
            setVisualPreviewErrorOverlay(true, errLine);
        }).catch(function () {
            tabClickLocked = false;
            toast('error', messages.networkError);
            setVisualPreviewErrorOverlay(true, messages.visualPreviewUnreachable);
        });
    }

    function triggerIncrementalRegenerateForAddedPageTypes() {
        var activeTypes = selectedPageTypes();
        var tabs = Array.from(document.querySelectorAll('.pb-ai-preview-tab'));
        if (!startRegeneratePageUrl || tabs.length === 0) {
            return;
        }
        var existingTypeMap = Object.create(null);
        tabs.forEach(function (tab) {
            var t = String(tab.getAttribute('data-page-type') || '');
            if (t) {
                existingTypeMap[t] = true;
            }
        });
        activeTypes.forEach(function (pageType) {
            if (existingTypeMap[pageType]) {
                return;
            }
            postForm(startRegeneratePageUrl, { public_id: publicId, page_type: pageType }).then(function (data) {
                if (data && data.success && window.PbAiOperationRunner && typeof window.PbAiOperationRunner.startFromResponse === 'function') {
                    window.PbAiOperationRunner.startFromResponse(data);
                }
            }).catch(function () {
                // no-op: avoid noisy toasts for background incremental task
            });
        });
    }

    function submitRefineComponent() {
        var instructionEl = document.getElementById('pb-ai-refine-component-instruction');
        var submitBtn = document.getElementById('pb-ai-refine-component-submit');
        var modalEl = document.getElementById('pb-ai-refine-component-modal');
        var instruction = instructionEl ? String(instructionEl.value || '').trim() : '';
        if (!instruction) {
            toast('warning', messages.refineInstructionRequired);
            if (instructionEl) {
                instructionEl.focus();
            }
            return;
        }
        if (!startBlockRefineSseUrl || !refineComponentState.pageType || !refineComponentState.componentCode) {
            toast('error', messages.refineUnavailable);
            return;
        }
        if (submitBtn) {
            submitBtn.disabled = true;
        }
        var formData = new FormData();
        formData.append('public_id', publicId);
        formData.append('page_type', refineComponentState.pageType);
        formData.append('component_code', refineComponentState.componentCode);
        formData.append('instruction', instruction);
        if (modalEl) {
            var modalInstance = resolveBootstrapModalInstance(modalEl);
            if (modalInstance && typeof modalInstance.hide === 'function') {
                modalInstance.hide();
            }
        }
        blockRefreshState.active = true;
        blockRefreshState.pageType = refineComponentState.pageType;
        blockRefreshState.blockId = refineComponentState.componentCode;
        if (typeof renderBlockStreamingState === 'function') {
            renderBlockStreamingState(messages.refineQueued);
        }
        openBlockSseModal(
            'AI Block Refine',
            (normalizePageTypeLabel(refineComponentState.pageType) || refineComponentState.pageType) + ' / ' + (refineComponentState.componentLabel || refineComponentState.componentCode),
            startBlockRefineSseUrl,
            formData
        );
        if (submitBtn) {
            submitBtn.disabled = false;
        }
        toast('success', messages.refineStreaming);
    }

    function startBlockRegenerate(payload) {
        var pageType = String((payload && payload.page_type) || getActivePreviewPageType() || '');
        var componentCode = String((payload && payload.component) || '');
        if (!startBlockRegenerateSseUrl || !pageType || !componentCode) {
            toast('error', messages.refineUnavailable);
            return;
        }

        var formData = new FormData();
        formData.append('public_id', publicId);
        formData.append('page_type', pageType);
        formData.append('component_code', componentCode);

        blockRefreshState.active = true;
        blockRefreshState.pageType = pageType;
        blockRefreshState.blockId = componentCode;
        if (typeof renderBlockStreamingState === 'function') {
            renderBlockStreamingState(messages.generating);
        }
        openBlockSseModal(
            'AI Block Regenerate',
            (normalizePageTypeLabel(pageType) || pageType) + ' / ' + buildComponentLabel(payload),
            startBlockRegenerateSseUrl,
            formData
        );
        toast('success', messages.regenerateStreaming);
    }

    function submitBlockEditor() {
        var modalEl = document.getElementById('pb-ai-edit-block-modal');
        var submitBtn = document.getElementById('pb-ai-edit-block-submit');
        if (!updateBlockConfigUrl || !blockEditorState.pageType || !blockEditorState.blockId) {
            toast('error', messages.editUnavailable);
            return;
        }
        if (submitBtn) {
            submitBtn.disabled = true;
        }
        var blockConfig = serializeBlockEditorConfig();
        postForm(updateBlockConfigUrl, {
            public_id: publicId,
            page_type: blockEditorState.pageType,
            block_id: blockEditorState.blockId,
            block_config: JSON.stringify(blockConfig)
        }).then(function (data) {
            if (submitBtn) {
                submitBtn.disabled = false;
            }
            if (!data || !data.success || !data.block) {
                toast('error', (data && data.message) ? String(data.message) : messages.networkError);
                return;
            }
            updateVirtualBlockState(blockEditorState.pageType, data.block);
            if (data.data && data.data.virtual_pages_by_type) {
                        mergeVirtualPagesByTypeState(data.data.virtual_pages_by_type);
            }
            replaceCurrentBlockHtml(blockEditorState.pageType, data.block);
            bindEmbeddedPreviewFrameBridge();
            if (modalEl) {
                var modalInstance = resolveBootstrapModalInstance(modalEl);
                if (modalInstance && typeof modalInstance.hide === 'function') {
                    modalInstance.hide();
                }
            }
            toast('success', messages.editSaved);
        }).catch(function () {
            if (submitBtn) {
                submitBtn.disabled = false;
            }
            toast('error', messages.networkError);
        });
    }

    function getEmbeddedPreviewFrame() {
        return document.getElementById('pb-ai-visual-preview-frame');
    }

    function resolvePayloadComponent(wrapper, sourceEl) {
        var candidates = [];
        var actions = sourceEl && sourceEl.closest ? sourceEl.closest('.component-actions') : null;
        if (sourceEl && sourceEl.getAttribute) {
            candidates.push(String(sourceEl.getAttribute('data-component') || ''));
            candidates.push(String(sourceEl.getAttribute('data-component-code') || ''));
            candidates.push(String(sourceEl.getAttribute('data-block-id') || ''));
        }
        if (actions && actions.getAttribute) {
            candidates.push(String(actions.getAttribute('data-component') || ''));
            candidates.push(String(actions.getAttribute('data-component-code') || ''));
            candidates.push(String(actions.getAttribute('data-block-id') || ''));
        }
        if (wrapper && wrapper.getAttribute) {
            candidates.push(String(wrapper.getAttribute('data-component') || ''));
            candidates.push(String(wrapper.getAttribute('data-component-code') || ''));
            candidates.push(String(wrapper.getAttribute('data-block-id') || ''));
        }
        for (var i = 0; i < candidates.length; i++) {
            if (candidates[i]) {
                return candidates[i];
            }
        }
        return '';
    }

    function buildEmbeddedPreviewPayload(wrapper, sourceEl) {
        var actions = sourceEl && sourceEl.closest ? sourceEl.closest('.component-actions') : null;
        if (!wrapper && !actions) {
            return null;
        }
        return {
            component: resolvePayloadComponent(wrapper, sourceEl),
            region: String(
                (wrapper && wrapper.getAttribute ? wrapper.getAttribute('data-region') : '')
                || (actions && actions.getAttribute ? actions.getAttribute('data-region') : '')
                || (sourceEl && sourceEl.getAttribute ? sourceEl.getAttribute('data-region') : '')
                || ''
            ),
            index: String(
                (wrapper && wrapper.getAttribute ? wrapper.getAttribute('data-index') : '')
                || (actions && actions.getAttribute ? actions.getAttribute('data-index') : '')
                || (sourceEl && sourceEl.getAttribute ? sourceEl.getAttribute('data-index') : '')
                || ''
            ),
            page_type: String(
                (wrapper && wrapper.getAttribute ? wrapper.getAttribute('data-page-type') : '')
                || (actions && actions.getAttribute ? actions.getAttribute('data-page-type') : '')
                || (sourceEl && sourceEl.getAttribute ? sourceEl.getAttribute('data-page-type') : '')
                || getActivePreviewPageType()
                || ''
            )
        };
    }

    function bindEmbeddedPreviewFrameBridge() {
        var frame = getEmbeddedPreviewFrame();
        if (!frame) {
            return;
        }

        function attachBridge() {
            var doc;
            try {
                doc = frame.contentDocument;
            } catch (error) {
                return;
            }
            if (!doc || !doc.documentElement) {
                return;
            }

            var wrapperSelector = '.pb-ai-block-wrapper, .tpmst-component-wrapper, .pb-component-wrapper';
            var selectedWrapperSelector = '.pb-ai-block-wrapper.selected, .tpmst-component-wrapper.selected, .pb-component-wrapper.selected';
            doc.querySelectorAll(wrapperSelector).forEach(function (wrapper) {
                if (wrapper.dataset.pbWorkspaceBridgeBound === '1') {
                    return;
                }
                wrapper.dataset.pbWorkspaceBridgeBound = '1';

                wrapper.addEventListener('mouseenter', function () {
                    var actions = wrapper.querySelector('.component-actions');
                    if (actions) {
                        actions.classList.add('pb-actions-visible');
                    }
                });

                wrapper.addEventListener('mouseleave', function () {
                    var actions = wrapper.querySelector('.component-actions');
                    if (actions) {
                        actions.classList.remove('pb-actions-visible');
                        var region = String(wrapper.getAttribute('data-region') || '');
                        if (region === 'header' || region === 'footer') {
                            actions.classList.add('pb-actions-visible');
                        }
                    }
                });

                var region = String(wrapper.getAttribute('data-region') || '');
                if (region === 'header' || region === 'footer') {
                    var initialActions = wrapper.querySelector('.component-actions');
                    if (initialActions) {
                        initialActions.classList.add('pb-actions-visible');
                    }
                }
                ensureWrapperActionButtons(wrapper);

                wrapper.addEventListener('click', function (event) {
                    if (event.target && event.target.closest && event.target.closest('.component-actions')) {
                        return;
                    }
                    doc.querySelectorAll(selectedWrapperSelector).forEach(function (selected) {
                        selected.classList.remove('selected');
                    });
                    wrapper.classList.add('selected');
                });
            });

            doc.querySelectorAll('.component-actions [data-pb-action]').forEach(function (button) {
                if (button.dataset.pbWorkspaceActionBound === '1') {
                    return;
                }
                button.dataset.pbWorkspaceActionBound = '1';

                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    event.stopImmediatePropagation();

                    var wrapper = button.closest(wrapperSelector);
                    var payload = buildEmbeddedPreviewPayload(wrapper, button);
                    if (!payload || !payload.component) {
                        toast('error', messages.refineUnavailable);
                        return;
                    }

                    if (String(button.getAttribute('data-pb-action') || '') === 'refine') {
                        openRefineComponentModal(payload);
                        return;
                    }

                    if (String(button.getAttribute('data-pb-action') || '') === 'regenerate-block') {
                        startBlockRegenerate(payload);
                        return;
                    }

                    if (String(button.getAttribute('data-pb-action') || '') === 'open-editor') {
                        openBlockEditorModal(payload);
                    }
                }, true);
            });

            doc.querySelectorAll('a[data-ve-page-type]').forEach(function (link) {
                if (link.dataset.pbWorkspaceNavBound === '1') {
                    return;
                }
                link.dataset.pbWorkspaceNavBound = '1';
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                    var pageType = String(link.getAttribute('data-ve-page-type') || '');
                    if (!pageType) {
                        return;
                    }
                    var targetTab = findPreviewTabByPayload({ page_type: pageType });
                    if (targetTab) {
                        switchPreviewByTab(targetTab);
                    }
                }, true);
            });
        }

        if (frame.dataset.pbWorkspaceLoadBound !== '1') {
            frame.dataset.pbWorkspaceLoadBound = '1';
            frame.addEventListener('load', function () {
                window.setTimeout(attachBridge, 60);
            });
        }

        window.setTimeout(attachBridge, 60);
    }

    function bindWorkspacePreviewMessages() {
        window.addEventListener('message', function (event) {
            var payload = event && event.data && typeof event.data === 'object' ? event.data : null;
            if (!payload) {
                return;
            }
            if (payload.type === 'pb-component-action') {
                if (String(payload.action || '') === 'refine') {
                    openRefineComponentModal(payload);
                    return;
                }
                if (String(payload.action || '') === 'regenerate-block') {
                    startBlockRegenerate(payload);
                    return;
                }
                if (String(payload.action || '') === 'open-editor') {
                    openBlockEditorModal(payload);
                }
                return;
            }
            if (payload.type === 'PageBuilderVisualEditor' && String(payload.action || '') === 'navigate') {
                var targetTab = findPreviewTabByPayload(payload);
                if (targetTab) {
                    switchPreviewByTab(targetTab);
                }
            }
        });
    }

    try {
        currentPreviewDevice = normalizePreviewDevice(window.localStorage.getItem('pb-ai-preview-device') || 'desktop');
    } catch (error) {
        currentPreviewDevice = 'desktop';
    }
    bindPreviewDeviceButtons();
    applyPreviewDeviceMode(currentPreviewDevice);
    var pageTypesInitialized = ensureDefaultPageTypes();
    bindWorkspacePreviewMessages();
    bindEmbeddedPreviewFrameBridge();
    if (pageTypesInitialized && !pageTypesUserCustomized) {
        autoSaveScope();
    }

    var refineSubmitBtn = document.getElementById('pb-ai-refine-component-submit');
    if (refineSubmitBtn) {
        refineSubmitBtn.addEventListener('click', submitRefineComponent);
    }
    var editBlockSubmitBtn = document.getElementById('pb-ai-edit-block-submit');
    if (editBlockSubmitBtn) {
        editBlockSubmitBtn.addEventListener('click', submitBlockEditor);
    }

    var refineInstructionInput = document.getElementById('pb-ai-refine-component-instruction');
    if (refineInstructionInput) {
        refineInstructionInput.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                event.preventDefault();
                submitRefineComponent();
            }
        });
    }

    document.querySelectorAll('.pb-guided-step[data-goto-stage]').forEach(function (el) {
        el.addEventListener('click', function () {
            var stage = el.getAttribute('data-goto-stage');
            if (!stage || !setStageUrl) { return; }
            postForm(setStageUrl, { public_id: publicId, stage: stage }).then(function (data) {
                if (data && data.success) {
                    window.location.href = guidedUrl || window.location.href;
                } else {
                    toast('error', (data && data.message) ? String(data.message) : messages.networkError);
                }
            }).catch(function () { toast('error', messages.networkError); });
        });
    });

    document.querySelectorAll('.pb-ai-set-stage').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var stage = btn.getAttribute('data-stage') || '';
            if (!stage || !setStageUrl) { return; }
            postForm(setStageUrl, { public_id: publicId, stage: stage }).then(function (data) {
                if (data && data.success) {
                    window.location.reload();
                    return;
                }
                toast('error', (data && data.message) ? String(data.message) : messages.networkError);
            }).catch(function () { toast('error', messages.networkError); });
        });
    });

    var extraToggle = document.getElementById('pb-guided-toggle-extra-types');
    var extraPanel = document.getElementById('pb-guided-extra-types-panel');
    if (extraPanel) {
        extraPanel.classList.add('open');
    }

    var siteProfileManualFieldMap = {
        'pb-ai-site-title': 'site_title',
        'pb-ai-site-tagline': 'site_tagline',
        'pb-ai-brief-description': 'brief_description',
        'pb-ai-target-domain': 'target_domain',
        'pb-ai-target-domain-summary': 'target_domain'
    };

    function markNeedsSectionFieldManual(el) {
        if (!el || el.nodeType !== 1) {
            return;
        }
        var id = el.id || '';
        var mapped = siteProfileManualFieldMap[id];
        if (mapped) {
            siteProfileManual[mapped] = true;
        }
        if (el.classList && el.classList.contains('pb-ai-page-type-check')) {
            pageTypesUserCustomized = true;
        }
        if (el.classList && el.classList.contains('pb-ai-target-domain-sync')) {
            siteProfileManual.target_domain = true;
        }
        if (el.classList && el.classList.contains('pb-ai-default-locale-sync')) {
            siteProfileManual.default_locale = true;
        }
        if (el.classList && el.classList.contains('pb-ai-plan-locale-sync')) {
            siteProfileManual.plan_locale = true;
        }
    }

    var needsSectionRoot = document.getElementById('pb-guided-needs-section');
    if (needsSectionRoot) {
        var onNeedsSectionPersist = function (ev) {
            markNeedsSectionFieldManual(ev.target);
            autoSaveScope();
        };
        needsSectionRoot.addEventListener('input', onNeedsSectionPersist);
        needsSectionRoot.addEventListener('change', onNeedsSectionPersist);
    }

    ['pb-ai-site-title', 'pb-ai-site-tagline', 'pb-ai-brief-description'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) {
            return;
        }
        var manualKey = siteProfileManualFieldMap[id] || '';
        var markManual = function () {
            if (!manualKey) {
                return;
            }
            siteProfileManual[manualKey] = true;
        };
        el.addEventListener('input', function () {
            markManual();
            autoSaveScope();
        });
        el.addEventListener('change', function () {
            markManual();
            autoSaveScope();
        });
    });

    getTargetDomainInputs().forEach(function (el) {
        el.addEventListener('input', function () {
            var v = String(el.value || '');
            getTargetDomainInputs().forEach(function (peer) {
                if (peer !== el) {
                    peer.value = v;
                }
            });
            siteProfileManual.target_domain = true;
            autoSaveScope();
        });
        el.addEventListener('change', function () {
            siteProfileManual.target_domain = true;
            autoSaveScope();
        });
    });

    getDefaultLocaleInputs().forEach(function (el) {
        el.addEventListener('change', function () {
            var v = String(el.value || '');
            getDefaultLocaleInputs().forEach(function (peer) {
                if (peer !== el) {
                    peer.value = v;
                }
            });
            siteProfileManual.default_locale = true;
            autoSaveScope();
        });
    });
    getPlanLocaleInputs().forEach(function (el) {
        el.addEventListener('change', function () {
            var v = String(el.value || '');
            getPlanLocaleInputs().forEach(function (peer) {
                if (peer !== el) {
                    peer.value = v;
                }
            });
            siteProfileManual.plan_locale = true;
            autoSaveScope();
        });
    });
    hydrateGuidedProfileInputs();
    syncDomPageTypeChecks(selectedPageTypes());
    if (typeof window.applyTaskSummarySelectionFilter === 'function') {
        window.applyTaskSummarySelectionFilter();
    }

    document.querySelectorAll('.pb-ai-page-type-check').forEach(function (check) {
        check.addEventListener('change', function () {
            pageTypesUserCustomized = true;
            if (String(check.value || '') === REQUIRED_PAGE_TYPE && !check.checked) {
                check.checked = true;
                syncPageTypeCheckLabelState(check);
                return;
            }
            syncSameValuePageTypeChecks(check.value, check.checked);
            autoSaveScope();
            syncPreviewTabsWithSelection();
            if (typeof window.applyTaskSummarySelectionFilter === 'function') {
                window.applyTaskSummarySelectionFilter();
            }
            triggerIncrementalRegenerateForAddedPageTypes();
        });
    });

    document.querySelectorAll('.pb-ai-clear-page-type-selection').forEach(function (btn) {
        btn.addEventListener('click', function (ev) {
            if (ev && typeof ev.stopPropagation === 'function') {
                ev.stopPropagation();
            }
            if (ev && typeof ev.preventDefault === 'function') {
                ev.preventDefault();
            }
            clearAllDomPageTypeChecksAndPersist();
        });
    });

    document.querySelectorAll('.pb-ai-preview-tab').forEach(function (tab) {
        tab.addEventListener('click', function () { switchPreviewByTab(tab); });
    });
    document.querySelectorAll('.pb-ai-focus-embedded-editor').forEach(function (button) {
        button.addEventListener('click', function () {
            focusEmbeddedEditor();
        });
    });
    normalizeEmbeddedEditorTriggers();
    syncPreviewTabsWithSelection();

    if (currentStageCode === 'visual_edit' && !hasGeneratedPages && !isOperationRunning) {
        window.setTimeout(function () {
            var selected = selectedPageTypes();
            toast('warning', messages.autoGenerateNeeded);
        }, 260);
    }

    getRunVirtualThemeButtons().forEach(function (runVTBtn) {
        runVTBtn.addEventListener('click', function () {
            if (!runVirtualThemeUrl) { return; }
            var pageTypes = selectedPageTypes();
            if (pageTypes.length === 0) {
                toast('warning', messages.noPageType);
                return;
            }
            bindTargetDomainInvalidClearOnce();
            setTargetDomainFieldInvalid(false);
            if (!validateVirtualThemeInputs()) {
                return;
            }
            var triggerBtn = this;
            startPlanGenerationForSelection(triggerBtn, pageTypes);
        });
    });
    applyRunVirtualThemeButtonsDisabledState();

    var currentPlanSelection = [];
    var currentPlanPayload = null;
    var currentPlanTriggerButton = null;
    var currentPlanMode = 'refine';
    var currentPlanModeTargetScope = '';
    var rebuildPlanDefaultPrompt = "请完整重建当前阶段一方案，保持目标页类型覆盖，但允许重写结构与表达。";
    var planSseHandlersBound = false;
    var planSseRunning = false;
    var planModeLastSuccessNotifyFingerprint = '';
    var planModeLastSuccessNotifyAt = 0;
    var planStartRequestPending = false;
    var allowPlanModalClose = false;
    var currentTaskPlanPayload = null;
    var currentTaskPlanMode = 'refine_task_plan';
    var currentTaskPlanModeTargetScope = '';
    var taskPlanSseHandlersBound = false;
    var taskPlanSseRunning = false;
    var taskPlanStreamAccumMarkdown = '';
    var TASK_PLAN_PROMPT_MODE_DETECT_BOOTSTRAP = 'detect_bootstrap_task_plan';
    var taskPlanStartRequestInFlight = false;
    var pendingTaskPlanModeAfterBootstrap = '';
    var visualEditResumePrompted = false;

    function escapeHtml(text) {
        return String(text || '').replace(/[&<>\"']/g, function (char) {
            return ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            })[char] || char;
        });
    }

    function tryParseJsonObject(text) {
        var raw = String(text || '').trim();
        if (!raw) {
            return null;
        }
        if (!(raw.charAt(0) === '{' || raw.charAt(0) === '[')) {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    function isNonEmptyObject(value) {
        return !!(value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length > 0);
    }

    function pickStructuredPlanRoot(payload) {
        if (!payload || typeof payload !== 'object') {
            return null;
        }
        if (isNonEmptyObject(payload.structured)) {
            return payload.structured;
        }
        if (isNonEmptyObject(payload.json)) {
            return payload.json;
        }
        if (isNonEmptyObject(payload.plan_json)) {
            return payload.plan_json;
        }
        if (payload.virtual_theme_plan && typeof payload.virtual_theme_plan === 'object') {
            if (isNonEmptyObject(payload.virtual_theme_plan.draft)) {
                return payload.virtual_theme_plan.draft;
            }
            if (isNonEmptyObject(payload.virtual_theme_plan.confirmed)) {
                return payload.virtual_theme_plan.confirmed;
            }
        }
        return null;
    }

    function coerceStructuredPlanRootFromParsedJson(parsed) {
        if (!parsed || typeof parsed !== 'object') {
            return null;
        }
        if (isNonEmptyObject(parsed.pages)) {
            return parsed;
        }
        if (isNonEmptyObject(parsed.plan_json)) {
            return parsed.plan_json;
        }
        if (isNonEmptyObject(parsed.structured)) {
            return parsed.structured;
        }
        return null;
    }

    function renderKeywordBadges(keywords) {
        if (!Array.isArray(keywords) || keywords.length === 0) {
            return '';
        }
        var chips = keywords.map(function (kw) {
            var t = String(kw || '').trim();
            if (!t) {
                return '';
            }
            return '<span class="badge bg-soft-secondary text-secondary me-1 mb-1">' + escapeHtml(t) + '</span>';
        }).filter(Boolean).join('');
        if (!chips) {
            return '';
        }
        return '<div class="d-flex flex-wrap mt-2">' + chips + '</div>';
    }

    function renderFieldPlanTable(fieldPlan) {
        if (!Array.isArray(fieldPlan) || fieldPlan.length === 0) {
            return '';
        }
        var rows = fieldPlan.map(function (row) {
            if (!row || typeof row !== 'object') {
                return '';
            }
            var field = escapeHtml(String(row.field || ''));
            var sample = escapeHtml(String(row.sample || '')).replace(/\n/g, '<br>');
            var reason = escapeHtml(String(row.reason || '')).replace(/\n/g, '<br>');
            if (!field && !sample && !reason) {
                return '';
            }
            return '<tr>'
                + '<td class="text-muted small" style="width:160px;vertical-align:top;">' + (field || '-') + '</td>'
                + '<td style="vertical-align:top;"><div class="small">' + (sample || '-') + '</div></td>'
                + '<td style="vertical-align:top;"><div class="small text-muted">' + (reason || '-') + '</div></td>'
                + '</tr>';
        }).filter(Boolean).join('');
        if (!rows) {
            return '';
        }
        return ''
            + '<div class="table-responsive mt-2">'
            + '<table class="table table-sm align-middle mb-0">'
            + '<thead><tr>'
            + '<th class="small text-muted">' + escapeHtml(previewLabels.field) + '</th>'
            + '<th class="small text-muted">' + escapeHtml(previewLabels.sample) + '</th>'
            + '<th class="small text-muted">' + escapeHtml(previewLabels.reason) + '</th>'
            + '</tr></thead>'
            + '<tbody>' + rows + '</tbody>'
            + '</table>'
            + '</div>';
    }

    function renderExecutionScriptSection(script) {
        if (!script || typeof script !== 'object') {
            return '';
        }
        var parts = [];
        if (Array.isArray(script.feature_points) && script.feature_points.length > 0) {
            var items = script.feature_points.map(function (p) {
                var t = String(p || '').trim();
                return t ? '<li class="small">' + escapeHtml(t) + '</li>' : '';
            }).filter(Boolean).join('');
            if (items) {
                parts.push('<div class="fw-semibold small mt-2">' + escapeHtml(previewLabels.featurePoints) + '</div><ul class="mb-0 ps-3">' + items + '</ul>');
            }
        }
        var keys = ['core_copy', 'typography', 'style_tone', 'background_direction'];
        keys.forEach(function (k) {
            var v = String(script[k] || '').trim();
            if (!v) {
                return;
            }
            parts.push('<div class="mt-2"><div class="fw-semibold small">' + escapeHtml(k) + '</div><div class="small text-muted">' + escapeHtml(v).replace(/\n/g, '<br>') + '</div></div>');
        });
        if (Array.isArray(script.media_assets) && script.media_assets.length > 0) {
            parts.push('<div class="mt-2"><div class="fw-semibold small">' + escapeHtml(previewLabels.mediaAssets) + '</div><div class="small text-muted">' + escapeHtml(script.media_assets.join(', ')) + '</div></div>');
        }
        if (parts.length === 0) {
            return '';
        }
        return '<div class="border-top pt-2 mt-2">' + parts.join('') + '</div>';
    }

    function normalizeStructuredItems(value) {
        if (Array.isArray(value)) {
            return value.map(function (item) {
                if (item === null || typeof item === 'undefined') {
                    return '';
                }
                if (typeof item === 'string') {
                    return item.trim();
                }
                if (typeof item === 'object') {
                    return String(item.title || item.name || item.label || item.text || item.value || '').trim();
                }
                return String(item).trim();
            }).filter(Boolean);
        }
        if (value && typeof value === 'object') {
            return Object.keys(value).map(function (key) {
                var raw = value[key];
                var text = '';
                if (raw && typeof raw === 'object') {
                    text = String(raw.title || raw.name || raw.label || raw.text || raw.value || '').trim();
                } else {
                    text = String(raw || '').trim();
                }
                if (text === '') {
                    return String(key || '').trim();
                }
                return String(key || '').trim() + ': ' + text;
            }).filter(Boolean);
        }
        var single = String(value || '').trim();
        return single ? [single] : [];
    }

    function renderStructuredListSection(title, items, options) {
        var normalizedItems = normalizeStructuredItems(items);
        if (normalizedItems.length === 0) {
            return '';
        }
        var opts = options && typeof options === 'object' ? options : {};
        var body = '';
        if (opts.badges) {
            body = renderKeywordBadges(normalizedItems);
        } else {
            var listItems = normalizedItems.map(function (item) {
                return '<li class="small">' + escapeHtml(String(item)) + '</li>';
            }).join('');
            body = '<ul class="mb-0 ps-3">' + listItems + '</ul>';
        }
        return ''
            + '<div class="card border-0 shadow-sm mb-3">'
            + '<div class="card-body">'
            + '<div class="fw-semibold">' + escapeHtml(String(title || '')) + '</div>'
            + '<div class="mt-2">' + body + '</div>'
            + '</div>'
            + '</div>';
    }

    function renderThemeSummarySection(planRoot) {
        if (!planRoot || typeof planRoot !== 'object') {
            return '';
        }
        var palette = planRoot.palette || planRoot.color_palette || planRoot.theme_palette || null;
        var styleTone = planRoot.style_tone || planRoot.visual_style || planRoot.theme_style || null;
        var typography = planRoot.typography || planRoot.font_plan || null;
        var parts = [];

        if (palette && typeof palette === 'object' && !Array.isArray(palette)) {
            var paletteRows = Object.keys(palette).map(function (key) {
                var value = String(palette[key] || '').trim();
                if (!value) {
                    return '';
                }
                return '<li class="small"><span class="text-muted">' + escapeHtml(String(key)) + '</span>: '
                    + '<code>' + escapeHtml(value) + '</code></li>';
            }).filter(Boolean).join('');
            if (paletteRows) {
                parts.push('<div class="mt-2"><div class="fw-semibold small">' + escapeHtml(previewLabels.palette) + '</div><ul class="mb-0 ps-3">' + paletteRows + '</ul></div>');
            }
        } else {
            var paletteItems = normalizeStructuredItems(palette);
            if (paletteItems.length > 0) {
                parts.push('<div class="mt-2"><div class="fw-semibold small">' + escapeHtml(previewLabels.palette) + '</div>' + renderKeywordBadges(paletteItems) + '</div>');
            }
        }

        var styleItems = normalizeStructuredItems(styleTone);
        if (styleItems.length > 0) {
            parts.push('<div class="mt-2"><div class="fw-semibold small">' + escapeHtml(previewLabels.styleTone) + '</div><ul class="mb-0 ps-3">' + styleItems.map(function (item) {
                return '<li class="small">' + escapeHtml(item) + '</li>';
            }).join('') + '</ul></div>');
        }

        var typoItems = normalizeStructuredItems(typography);
        if (typoItems.length > 0) {
            parts.push('<div class="mt-2"><div class="fw-semibold small">' + escapeHtml(previewLabels.typography) + '</div><ul class="mb-0 ps-3">' + typoItems.map(function (item) {
                return '<li class="small">' + escapeHtml(item) + '</li>';
            }).join('') + '</ul></div>');
        }

        if (parts.length === 0) {
            return '';
        }
        return ''
            + '<div class="card border-0 shadow-sm mb-3">'
            + '<div class="card-body">'
            + '<div class="fw-semibold">' + escapeHtml(previewLabels.themeSummary) + '</div>'
            + parts.join('')
            + '</div>'
            + '</div>';
    }

    function buildPreviewActionButton(action, label, tone, meta) {
        var attrs = '';
        var payload = meta && typeof meta === 'object' ? meta : {};
        Object.keys(payload).forEach(function (key) {
            var value = payload[key];
            if (value === null || typeof value === 'undefined' || String(value).trim() === '') {
                return;
            }
            attrs += ' data-' + escapeHtml(String(key)) + '="' + escapeHtml(String(value)) + '"';
        });
        return ''
            + '<button type="button" class="btn btn-sm ' + escapeHtml(String(tone || 'btn-outline-primary')) + ' pb-ai-preview-action-btn"'
            + ' data-pb-preview-action="' + escapeHtml(String(action || '')) + '"' + attrs + '>'
            + escapeHtml(String(label || ''))
            + '</button>';
    }

    function buildPreviewActionToolbar(stage, scopeKind, meta) {
        var payload = meta && typeof meta === 'object' ? meta : {};
        var attrs = {
            'pb-preview-stage': stage,
            'pb-scope-kind': scopeKind,
            'page-type': payload.pageType || '',
            'block-key': payload.blockKey || '',
            'task-key': payload.taskKey || '',
            'block-label': payload.blockLabel || '',
            'page-label': payload.pageLabel || '',
            'target-scope': payload.targetScope || ''
        };
        return ''
            + '<div class="pb-ai-plan-preview-actions">'
            + buildPreviewActionButton('refine', 'Refine', 'btn-outline-primary', attrs)
            + buildPreviewActionButton('rebuild', 'Rebuild', 'btn-outline-warning', attrs)
            + buildPreviewActionButton('delete', 'Delete', 'btn-outline-danger', attrs)
            + buildPreviewActionButton('add-block', 'Add Block', 'btn-outline-success', attrs)
            + '</div>';
    }

    function buildPreviewStageToolbar(stage) {
        return ''
            + '<div class="pb-ai-plan-preview-stage-toolbar mb-3">'
            + '<div class="small text-muted">' + escapeHtml(previewLabels.stageToolbarHint) + '</div>'
            + '<div class="d-flex flex-wrap gap-2">'
            + buildPreviewActionButton('refine-stage', previewLabels.refineStage, 'btn-outline-primary', {
                'pb-preview-stage': stage,
                'pb-scope-kind': 'stage'
            })
            + buildPreviewActionButton('rebuild-stage', previewLabels.rebuildStage, 'btn-outline-danger', {
                'pb-preview-stage': stage,
                'pb-scope-kind': 'stage'
            })
            + '</div>'
            + '</div>';
    }

    function renderPlanPagesSection(pages) {
        if (!pages || typeof pages !== 'object') {
            return '';
        }
        var pageKeys = Object.keys(pages);
        if (pageKeys.length === 0) {
            return '';
        }
        var cards = pageKeys.map(function (pageType) {
            var page = pages[pageType];
            if (!page || typeof page !== 'object') {
                return '';
            }
            var title = escapeHtml(String(page.title || pageType));
            var goal = escapeHtml(String(page.page_goal || page.goal || '')).replace(/\n/g, '<br>');
            var primary = Array.isArray(page.primary_keywords) ? page.primary_keywords : [];
            var secondary = Array.isArray(page.secondary_keywords) ? page.secondary_keywords : [];
            var blocks = Array.isArray(page.blocks) ? page.blocks : [];
            var blockCards = blocks.map(function (block, idx) {
                if (!block || typeof block !== 'object') {
                    return '';
                }
                var rawBlockKey = String(block.block_key || block.section_code || ('block_' + (idx + 1)));
                var blockKey = escapeHtml(String(block.block_key || block.section_code || ('block_' + (idx + 1))));
                var blockGoal = escapeHtml(String(block.goal || '')).replace(/\n/g, '<br>');
                var content = escapeHtml(String(block.content || '')).replace(/\n/g, '<br>');
                var keywords = Array.isArray(block.keywords) ? block.keywords : [];
                var fieldTable = renderFieldPlanTable(block.field_plan);
                var exec = renderExecutionScriptSection(block.execution_script);
                var targetScope = 'pages.' + String(pageType) + '.blocks.' + rawBlockKey;
                return ''
                    + '<div class="border rounded p-3 mb-2 bg-white pb-ai-plan-preview-block"'
                    + ' data-pb-preview-stage="plan"'
                    + ' data-pb-preview-page-type="' + escapeHtml(String(pageType)) + '"'
                    + ' data-pb-preview-block-key="' + blockKey + '">'
                    + '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pb-ai-plan-preview-block-head">'
                    + '<div class="fw-semibold">' + blockKey + '</div>'
                    + buildPreviewActionToolbar('plan', 'block', {
                        pageType: String(pageType),
                        blockKey: rawBlockKey,
                        blockLabel: rawBlockKey,
                        pageLabel: String(page.title || pageType),
                        targetScope: targetScope
                    })
                    + '</div>'
                    + (blockGoal ? '<div class="small text-muted mt-2">' + blockGoal + '</div>' : '')
                    + (content ? '<div class="small mt-2">' + content + '</div>' : '')
                    + renderKeywordBadges(keywords)
                    + fieldTable
                    + exec
                    + '</div>';
            }).filter(Boolean).join('');
            return ''
                + '<div class="card border-0 shadow-sm mb-3 pb-ai-plan-preview-page-card" data-pb-preview-page-type="' + escapeHtml(String(pageType)) + '">'
                + '<div class="card-body">'
                + '<div class="d-flex flex-wrap align-items-start justify-content-between gap-2">'
                + '<div><div class="fw-semibold">' + title + '</div>'
                + '<div class="text-muted small mt-1">' + escapeHtml(String(pageType)) + '</div></div>'
                + buildPreviewActionToolbar('plan', 'page', {
                    pageType: String(pageType),
                    pageLabel: String(page.title || pageType),
                    targetScope: 'pages.' + String(pageType)
                })
                + '</div>'
                + (goal ? '<div class="small mt-2">' + goal + '</div>' : '')
                + (primary.length ? ('<div class="mt-2"><div class="fw-semibold small">' + escapeHtml(previewLabels.primaryKeywords) + '</div>' + renderKeywordBadges(primary) + '</div>') : '')
                + (secondary.length ? ('<div class="mt-2"><div class="fw-semibold small">' + escapeHtml(previewLabels.secondaryKeywords) + '</div>' + renderKeywordBadges(secondary) + '</div>') : '')
                + (blockCards ? ('<div class="mt-3">' + blockCards + '</div>') : '<div class="text-muted small mt-3">' + escapeHtml(previewLabels.noBlocks) + '</div>')
                + '</div>'
                + '</div>';
        }).filter(Boolean).join('');
        return cards || '';
    }

    function isTaskPlanStructuredRoot(planRoot) {
        if (!planRoot || typeof planRoot !== 'object') {
            return false;
        }
        if (Array.isArray(planRoot.shared_tasks) && planRoot.shared_tasks.length > 0) {
            return true;
        }
        if (isNonEmptyObject(planRoot.page_tasks)) {
            return true;
        }
        if (isNonEmptyObject(planRoot.task_script_brief)) {
            return true;
        }
        return false;
    }

    function renderTaskPlanTaskCard(task, idx, pageType) {
        if (!task || typeof task !== 'object') {
            return '';
        }
        var title = escapeHtml(String(task.label || task.task_key || ('task_' + (idx + 1))));
        var taskKey = escapeHtml(String(task.task_key || ''));
        var rawTaskKey = String(task.task_key || '');
        var groupKey = escapeHtml(String(task.group_key || pageType || 'shared'));
        var planContext = (task.plan_context && typeof task.plan_context === 'object') ? task.plan_context : {};
        var taskScript = (task.task_script && typeof task.task_script === 'object') ? task.task_script : {};
        var implementationContract = (task.implementation_contract && typeof task.implementation_contract === 'object') ? task.implementation_contract : {};
        var pageGoal = escapeHtml(String(planContext.page_goal || '')).replace(/\n/g, '<br>');
        var blockGoal = escapeHtml(String(planContext.block_goal || '')).replace(/\n/g, '<br>');
        var scene = escapeHtml(String(taskScript.scene || '')).replace(/\n/g, '<br>');
        var storyGoal = escapeHtml(String(taskScript.story_goal || '')).replace(/\n/g, '<br>');
        var fillRule = escapeHtml(String(taskScript.content_fill_rule || '')).replace(/\n/g, '<br>');
        var stage3Directive = escapeHtml(String(taskScript.stage3_directive || '')).replace(/\n/g, '<br>');
        var acceptance = normalizeStructuredItems(implementationContract.acceptance || []);
        var fieldRequirements = Array.isArray(taskScript.field_content_requirements) ? taskScript.field_content_requirements : [];
        var fieldTable = renderFieldPlanTable(fieldRequirements);
        var targetScope = String(taskScript.scene || (pageType ? ('page:' + pageType) : (rawTaskKey || 'shared')));

        return ''
            + '<div class="border rounded p-3 mb-2 bg-white pb-ai-plan-preview-block"'
            + ' data-pb-preview-stage="task-plan"'
            + ' data-pb-preview-page-type="' + escapeHtml(String(pageType || 'shared')) + '"'
            + ' data-pb-preview-task-key="' + taskKey + '">'
            + '<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 pb-ai-plan-preview-block-head">'
            + '<div><div class="fw-semibold">' + title + '</div>'
            + '<div class="text-muted small mt-1">' + taskKey + '</div></div>'
            + '<div class="d-flex flex-wrap align-items-center gap-2">'
            + '<span class="badge text-bg-light border">' + groupKey + '</span>'
            + buildPreviewActionToolbar('task-plan', 'block', {
                pageType: String(pageType || 'shared'),
                taskKey: rawTaskKey,
                blockLabel: String(task.label || task.task_key || ('task_' + (idx + 1))),
                pageLabel: String(pageType || 'shared'),
                targetScope: targetScope
            })
            + '</div>'
            + '</div>'
            + (pageGoal ? '<div class="small mt-2"><span class="fw-semibold">' + escapeHtml(previewLabels.pageGoal) + ':</span> ' + pageGoal + '</div>' : '')
            + (blockGoal ? '<div class="small mt-2"><span class="fw-semibold">' + escapeHtml(previewLabels.blockGoal) + ':</span> ' + blockGoal + '</div>' : '')
            + (scene ? '<div class="small mt-2"><span class="fw-semibold">' + escapeHtml(previewLabels.scriptScene) + ':</span> <code>' + scene + '</code></div>' : '')
            + (storyGoal ? '<div class="small mt-2"><span class="fw-semibold">' + escapeHtml(previewLabels.scriptGoal) + ':</span> ' + storyGoal + '</div>' : '')
            + (fillRule ? '<div class="small mt-2"><span class="fw-semibold">' + escapeHtml(previewLabels.fillRule) + ':</span> ' + fillRule + '</div>' : '')
            + fieldTable
            + (acceptance.length ? ('<div class="mt-2"><div class="fw-semibold small">' + escapeHtml(previewLabels.acceptance) + '</div><ul class="mb-0 ps-3">' + acceptance.map(function (item) {
                return '<li class="small">' + escapeHtml(item) + '</li>';
            }).join('') + '</ul></div>') : '')
            + (stage3Directive ? '<div class="small mt-2"><span class="fw-semibold">' + escapeHtml(previewLabels.stage3Directive) + ':</span> ' + stage3Directive + '</div>' : '')
            + '</div>';
    }

    function renderTaskPlanStructuredPreviewHtml(planRoot) {
        if (!planRoot || typeof planRoot !== 'object') {
            return '<div class="text-muted small">' + escapeHtml(String(messages.planStructuredPreviewEmpty || '')) + '</div>';
        }
        var sections = [];
        var scriptBrief = planRoot.task_script_brief && typeof planRoot.task_script_brief === 'object' ? planRoot.task_script_brief : null;
        if (scriptBrief) {
            sections.push(renderStructuredListSection(previewLabels.taskScriptBrief, scriptBrief));
        }

        var sharedTasks = Array.isArray(planRoot.shared_tasks) ? planRoot.shared_tasks : [];
        if (sharedTasks.length > 0) {
            sections.push(
                '<div class="card border-0 shadow-sm mb-3">'
                + '<div class="card-body">'
                + '<div class="fw-semibold">' + escapeHtml(previewLabels.sharedTasks) + '</div>'
                + '<div class="mt-3">' + sharedTasks.map(function (task, idx) {
                    return renderTaskPlanTaskCard(task, idx, 'shared');
                }).filter(Boolean).join('') + '</div>'
                + '</div>'
                + '</div>'
            );
        }

        var pageTasks = planRoot.page_tasks && typeof planRoot.page_tasks === 'object' ? planRoot.page_tasks : {};
        Object.keys(pageTasks).forEach(function (pageType) {
            var tasks = Array.isArray(pageTasks[pageType]) ? pageTasks[pageType] : [];
            if (tasks.length === 0) {
                return;
            }
            sections.push(
                '<div class="card border-0 shadow-sm mb-3">'
                + '<div class="card-body">'
                + '<div class="d-flex flex-wrap align-items-start justify-content-between gap-2">'
                + '<div class="fw-semibold">' + escapeHtml(String(pageType)) + '</div>'
                + '<span class="text-muted small">' + escapeHtml(String(tasks.length)) + ' ' + escapeHtml(previewLabels.tasksUnit) + '</span>'
                + '</div>'
                + '<div class="mt-3">' + tasks.map(function (task, idx) {
                    return renderTaskPlanTaskCard(task, idx, pageType);
                }).filter(Boolean).join('') + '</div>'
                + '</div>'
                + '</div>'
            );
        });

        var riskHtml = renderStructuredListSection(previewLabels.riskNotes, planRoot.risk_notes || null);
        if (riskHtml) {
            sections.push(riskHtml);
        }

        if (sections.length === 0) {
            return '<div class="text-muted small">' + escapeHtml(String(messages.planStructuredPreviewEmpty || '')) + '</div>';
        }

        return ''
            + buildPreviewStageToolbar('task-plan')
            + '<div class="mb-2">'
            + '<div class="fw-semibold">' + escapeHtml(String(messages.planStructuredPreviewTitle || '')) + '</div>'
            + '<div class="text-muted small">' + escapeHtml(previewLabels.taskPlanHint) + '</div>'
            + '</div>'
            + sections.join('');
    }

    function renderPlanStructuredPreviewHtml(planRoot) {
        if (!planRoot || typeof planRoot !== 'object') {
            return '<div class="text-muted small">' + escapeHtml(String(messages.planStructuredPreviewEmpty || '')) + '</div>';
        }
        if (isTaskPlanStructuredRoot(planRoot)) {
            return renderTaskPlanStructuredPreviewHtml(planRoot);
        }
        function looksLikePagePlanObject(value) {
            if (!value || typeof value !== 'object' || Array.isArray(value)) {
                return false;
            }
            if (Array.isArray(value.blocks) && value.blocks.length > 0) {
                return true;
            }
            if (String(value.page_goal || value.goal || '').trim() !== '') {
                return true;
            }
            if (String(value.title || '').trim() !== '') {
                return true;
            }
            if (Array.isArray(value.primary_keywords) && value.primary_keywords.length > 0) {
                return true;
            }
            if (Array.isArray(value.secondary_keywords) && value.secondary_keywords.length > 0) {
                return true;
            }
            return false;
        }

        var pages = planRoot.pages && typeof planRoot.pages === 'object' ? planRoot.pages : null;
        if (!pages) {
            // 鍏煎锛歱lan_json 鐩存帴浠?page_type 涓?key锛堟棤 pages 鍖呰９锛?
            var reservedKeys = {
                pages: true,
                page_types: true,
                seo_strategy: true,
                navigation_plan: true,
                footer_plan: true,
                palette: true,
                execution_steps: true,
                stage2_task_hints: true
            };
            var candidate = {};
            var hit = 0;
            Object.keys(planRoot).forEach(function (k) {
                if (reservedKeys[k]) {
                    return;
                }
                var v = planRoot[k];
                if (looksLikePagePlanObject(v)) {
                    candidate[k] = v;
                    hit += 1;
                }
            });
            if (hit > 0) {
                pages = candidate;
            } else if (looksLikePagePlanObject(planRoot)) {
                pages = { plan: planRoot };
            }
        }
        if (Array.isArray(pages) && pages.length > 0) {
            var mapped = {};
            pages.forEach(function (page, idx) {
                if (!page || typeof page !== 'object') {
                    return;
                }
                var key = String(page.page_type || page.type || page.title || ('page_' + (idx + 1)));
                mapped[key] = page;
            });
            pages = mapped;
        }
        var themeHtml = renderThemeSummarySection(planRoot);
        var headerHtml = renderStructuredListSection(previewLabels.headerPlan, planRoot.navigation_plan || planRoot.header_plan || planRoot.header || null);
        var footerHtml = renderStructuredListSection(previewLabels.footerPlan, planRoot.footer_plan || planRoot.footer || null);
        var pagesHtml = renderPlanPagesSection(pages);
        var detailHtml = themeHtml + headerHtml + footerHtml + pagesHtml;
        if (!detailHtml) {
            return '<div class="text-muted small">' + escapeHtml(String(messages.planStructuredPreviewEmpty || '')) + '</div>';
        }
        return ''
            + buildPreviewStageToolbar('plan')
            + '<div class="mb-2">'
            + '<div class="fw-semibold">' + escapeHtml(String(messages.planStructuredPreviewTitle || '')) + '</div>'
            + '<div class="text-muted small">' + escapeHtml(String(messages.planStructuredPreviewNote || '')) + '</div>'
            + '</div>'
            + detailHtml;
    }

    function buildPlanPreviewHtml(markdownText, payload) {
        var md = String(markdownText || '').trim();
        var parsedFromMd = tryParseJsonObject(md);
        if (parsedFromMd && typeof parsedFromMd === 'object' && typeof parsedFromMd.markdown === 'string' && String(parsedFromMd.markdown || '').trim() !== '') {
            md = String(parsedFromMd.markdown || '').trim();
        } else if (parsedFromMd && typeof parsedFromMd === 'object' && coerceStructuredPlanRootFromParsedJson(parsedFromMd)) {
            md = '';
        }

        var structuredRoot = pickStructuredPlanRoot(payload);
        if (!structuredRoot && parsedFromMd && typeof parsedFromMd === 'object') {
            structuredRoot = coerceStructuredPlanRootFromParsedJson(parsedFromMd);
        }

        // 涓ら樁娈垫柟妗堝墠绔粺涓€浠呭睍绀虹粨鏋勫寲鏂规棰勮銆?
        return renderPlanStructuredPreviewHtml(structuredRoot);
    }

    function buildPreviewActionPrompt(stage, action, pageLabel, blockLabel) {
        var pageText = String(pageLabel || '').trim();
        var blockText = String(blockLabel || '').trim();
        if (stage === 'task-plan') {
            if (action === 'delete') {
                return '??' + (pageText || '????') + '????????' + (blockText || '?????') + '???????????????????';
            }
            if (action === 'add-block') {
                return '??' + (pageText || '????') + '????????????????????????????';
            }
            if (action === 'rebuild-stage') {
                return '??????????????????????????????????????';
            }
            if (action === 'refine-stage') {
                return '???????????????????????????????????';
            }
            if (action === 'rebuild') {
                return '?????' + (blockText || '?????') + '???????????????';
            }
            return '???' + (blockText || '?????') + '????????????????';
        }
        if (action === 'delete') {
            return '??' + (pageText || '????') + '?????????' + (blockText || '????') + '?????????????????????';
        }
        if (action === 'add-block') {
            return '??' + (pageText || '????') + '?????????????????????????????????';
        }
        if (action === 'rebuild-stage') {
            return String(rebuildPlanDefaultPrompt || '???????????????????');
        }
        if (action === 'refine-stage') {
            return '?????????????????????????????????????????';
        }
        if (action === 'rebuild') {
            return '?????' + (blockText || '????') + '????????????????';
        }
        return '???' + (blockText || '????') + '???????????????';
    }

    function scrollIntoPlanInlinePanel() {
        var panel = document.getElementById('pb-ai-plan-inline-panel');
        if (panel && typeof panel.scrollIntoView === 'function') {
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function showPlanPreviewActionFlow(mode, promptText, targetScope) {
        bindPlanModalViewSwitchers();
        bindPlanModeSwitchers();
        setPlanMode(mode);
        currentPlanModeTargetScope = String(targetScope || '').trim();
        var promptInput = document.getElementById('pb-ai-plan-mode-prompt');
        if (promptInput) {
            promptInput.value = String(promptText || '');
        }
        updatePlanModalContent(String((currentPlanPayload && currentPlanPayload.markdown) || ''));
        setPlanViewMode('pb-ai-plan-preview-content');
        setPlanModeStatus(String(planSseUrl || '').trim() ? messages.planModeReady : messages.planModeUnavailable);
        scrollIntoPlanInlinePanel();
        startPlanModeStream(mode, {
            promptText: promptText,
            targetScope: targetScope
        });
    }

    function showTaskPlanPreviewActionFlow(mode, promptText, targetScope) {
        bindTaskPlanModeSwitchers();
        showTaskPlanPanel();
        setTaskPlanMode(mode);
        currentTaskPlanModeTargetScope = String(targetScope || '').trim();
        var promptInput = document.getElementById('pb-ai-task-plan-mode-prompt');
        if (promptInput) {
            promptInput.value = String(promptText || '');
        }
        setTaskPlanModeStatus(String(taskPlanSseUrl || '').trim() ? messages.taskPlanModeReady : messages.taskPlanModeUnavailable);
        startTaskPlanModeStream(mode, {
            promptText: promptText,
            targetScope: targetScope
        });
    }

    function handlePreviewActionClick(button) {
        if (!button || !button.getAttribute) {
            return;
        }
        var stage = String(button.getAttribute('data-pb-preview-stage') || '').trim();
        var action = String(button.getAttribute('data-pb-preview-action') || '').trim();
        var targetScope = String(button.getAttribute('data-target-scope') || '').trim();
        var pageLabel = String(button.getAttribute('data-page-label') || button.getAttribute('data-page-type') || '').trim();
        var blockLabel = String(button.getAttribute('data-block-label') || button.getAttribute('data-block-key') || button.getAttribute('data-task-key') || '').trim();
        var promptText = buildPreviewActionPrompt(stage, action, pageLabel, blockLabel);
        if (stage === 'task-plan') {
            var taskMode = (action === 'rebuild' || action === 'rebuild-stage') ? 'rebuild_task_plan' : 'refine_task_plan';
            if (action === 'refine-stage' || action === 'rebuild-stage') {
                targetScope = '';
            }
            showTaskPlanPreviewActionFlow(taskMode, promptText, targetScope);
            return;
        }
        var planMode = (action === 'rebuild' || action === 'rebuild-stage') ? 'rebuild' : 'refine';
        if (action === 'refine-stage' || action === 'rebuild-stage') {
            targetScope = '';
        }
        showPlanPreviewActionFlow(planMode, promptText, targetScope);
    }

    function bindPreviewActionButtons(container) {
        var root = typeof container === 'string' ? document.getElementById(container) : container;
        if (!root || root.dataset.pbPreviewActionBound === '1') {
            return;
        }
        root.dataset.pbPreviewActionBound = '1';
        root.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('.pb-ai-preview-action-btn') : null;
            if (!button || !root.contains(button)) {
                return;
            }
            event.preventDefault();
            handlePreviewActionClick(button);
        });
    }

    function renderPlanPreviewHtml(markdown) {
        var escaped = escapeHtml(markdown).replace(/\r\n/g, '\n').replace(/\r/g, '\n');
        return escaped
            .replace(/^###\s+(.+)$/gm, '<h6>$1</h6>')
            .replace(/^##\s+(.+)$/gm, '<h5>$1</h5>')
            .replace(/^#\s+(.+)$/gm, '<h4>$1</h4>')
            .replace(/^- (.+)$/gm, '<li>$1</li>')
            .replace(/(<li>[\s\S]*<\/li>)/g, '<ul>$1</ul>')
            .replace(/\n{2,}/g, '</p><p>')
            .replace(/\n/g, '<br>');
    }

    function getPlanModalInstance() {
        var modal = document.getElementById('pb-ai-plan-generation-modal');
        if (!modal || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return { element: modal, instance: null };
        }
        var instance = bootstrap.Modal.getInstance(modal);
        if (!instance) {
            instance = new bootstrap.Modal(modal, { backdrop: 'static', keyboard: false });
        }
        return { element: modal, instance: instance };
    }

    function setPlanViewMode(targetId) {
        var markdownView = document.getElementById('pb-ai-plan-md-content');
        var previewView = document.getElementById('pb-ai-plan-preview-content');
        var mdBtn = document.getElementById('pb-ai-plan-md-view');
        var previewBtn = document.getElementById('pb-ai-plan-preview-view');
        var showPreview = targetId === 'pb-ai-plan-preview-content';
        if (markdownView) {
            markdownView.style.display = showPreview ? 'none' : '';
        }
        if (previewView) {
            previewView.style.display = showPreview ? '' : 'none';
        }
        if (mdBtn) {
            mdBtn.classList.toggle('active', !showPreview);
        }
        if (previewBtn) {
            previewBtn.classList.toggle('active', showPreview);
        }
    }

    function bindPlanModalViewSwitchers() {
        var mdBtn = document.getElementById('pb-ai-plan-md-view');
        var previewBtn = document.getElementById('pb-ai-plan-preview-view');
        if (mdBtn && mdBtn.dataset.pbBound !== '1') {
            mdBtn.dataset.pbBound = '1';
            mdBtn.addEventListener('click', function () {
                setPlanViewMode('pb-ai-plan-md-content');
            });
        }
        if (previewBtn && previewBtn.dataset.pbBound !== '1') {
            previewBtn.dataset.pbBound = '1';
            previewBtn.addEventListener('click', function () {
                setPlanViewMode('pb-ai-plan-preview-content');
            });
        }
    }

    function updatePlanModalContent(markdown) {
        var markdownText = normalizePlanMarkdownText(markdown);
        var previewContent = document.getElementById('pb-ai-plan-rendered-content');
        var markdownContent = document.getElementById('pb-ai-plan-md-content');
        if (previewContent) {
            previewContent.innerHTML = buildPlanPreviewHtml(markdownText, currentPlanPayload || {});
            bindPreviewActionButtons(previewContent);
        }
        if (markdownContent) {
            markdownContent.textContent = markdownText;
        }
        syncConfirmPlanButtonEnabled();
    }

    function normalizePlanMarkdownText(raw) {
        var text = String(raw || '').trim();
        if (!text) {
            return '';
        }
        // 闃叉鎶?AI JSON 鍘熸枃鐩存帴娓叉煋鍒伴瑙堬細浼樺厛鎻愬彇 markdown 瀛楁銆?
        if (text.charAt(0) === '{' || text.charAt(0) === '[') {
            try {
                var parsed = JSON.parse(text);
                if (parsed && typeof parsed === 'object') {
                    if (typeof parsed.markdown === 'string' && String(parsed.markdown || '').trim() !== '') {
                        return String(parsed.markdown || '').trim();
                    }
                    // 绾粨鏋勫寲 JSON锛堝惈 pages/plan_json 绛夛級涓嶅簲浣滀负 Markdown 鍘熸枃灞曠ず
                    if (coerceStructuredPlanRootFromParsedJson(parsed)) {
                        return '';
                    }
                }
            } catch (e) {
                // ignore
            }
        }
        return text;
    }

    function setPlanModalProgress(message, percent, enableConfirm) {
        var statusEl = document.getElementById('pb-ai-plan-status');
        var progressBar = document.getElementById('pb-ai-plan-progress-bar');
        if (statusEl) {
            statusEl.textContent = String(message || '');
        }
        if (progressBar) {
            progressBar.style.width = String(Math.max(0, Math.min(100, percent || 0))) + '%';
        }
        syncConfirmPlanButtonEnabled();
    }

    function normalizePlanFailureMessage(rawMessage) {
        var text = String(rawMessage || '').trim();
        if (text === '') {
            return String(messages.networkError || '');
        }
        if (/invalid\s+ai\s+json/i.test(text)) {
            return String(messages.planFriendlyInvalidAiJson || text);
        }
        return text;
    }

    function setPlanRetryButtonVisible(visible) {
        var retryBtn = document.getElementById('pb-ai-plan-retry-generate');
        if (!retryBtn) {
            return;
        }
        var shouldShow = !!visible;
        retryBtn.classList.toggle('d-none', !shouldShow);
        retryBtn.disabled = !shouldShow || planStartRequestPending || planSseRunning;
    }

    function hidePlanGenerationModal(options) {
        var opts = options && typeof options === 'object' ? options : {};
        var planTerminal = window.WelineSseTerminal ? window.WelineSseTerminal['pb-ai-plan-sse-terminal'] : null;
        if (planTerminal && typeof planTerminal.stop === 'function') {
            planTerminal.stop({ suppressTransportError: true });
        }
        planSseRunning = false;
        if (opts.allowClose !== false) {
            allowPlanModalClose = true;
        }
    }

    function requestClosePlanGenerationModal() {
        hidePlanGenerationModal({ allowClose: true });
    }

    function isPlanGenerationModalVisible() {
        return false;
    }

    function blockPlanGenerationModalBackdropClose(event) {
        if (allowPlanModalClose || !isPlanGenerationModalVisible() || !event || !event.target) {
            return;
        }
        var target = event.target;
        var isBackdrop = !!(target.classList && target.classList.contains('modal-backdrop'));
        var isModalContainer = !!(target.id && target.id === 'pb-ai-plan-generation-modal');
        if (!isBackdrop && !isModalContainer) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }
    }

    function setPlanMode(mode) {
        currentPlanMode = String(mode || 'refine') === 'rebuild' ? 'rebuild' : 'refine';
        var refineBtn = document.getElementById('pb-ai-plan-mode-refine');
        var rebuildBtn = document.getElementById('pb-ai-plan-mode-rebuild');
        var promptInput = document.getElementById('pb-ai-plan-mode-prompt');
        if (refineBtn) {
            refineBtn.classList.toggle('active', currentPlanMode === 'refine');
        }
        if (rebuildBtn) {
            rebuildBtn.classList.toggle('active', currentPlanMode === 'rebuild');
        }
        if (currentPlanMode === 'rebuild' && promptInput && String(promptInput.value || '').trim() === '') {
            promptInput.value = String(rebuildPlanDefaultPrompt || '');
        }
        var runBtn = document.getElementById('pb-ai-plan-run-mode');
        if (runBtn && !planSseRunning) {
            runBtn.disabled = false;
        }
    }

    function setPlanModeStatus(message) {
        var statusEl = document.getElementById('pb-ai-plan-mode-status');
        if (statusEl) {
            statusEl.textContent = String(message || '');
        }
    }

    function setPlanModeRunButtonLoading(loading) {
        var runBtn = document.getElementById('pb-ai-plan-run-mode');
        if (!runBtn) {
            return;
        }
        if (loading) {
            if (!runBtn.dataset.originalText) {
                runBtn.dataset.originalText = String(runBtn.textContent || '');
            }
            runBtn.disabled = true;
            runBtn.textContent = "澶勭悊涓?..";
            return;
        }
        runBtn.disabled = false;
        runBtn.textContent = String(
            runBtn.dataset.originalText
            || runBtn.textContent
            || ""        );
    }

    function bindPlanModeSwitchers() {
        ['refine', 'rebuild'].forEach(function (mode) {
            var btn = document.getElementById(mode === 'refine' ? 'pb-ai-plan-mode-refine' : 'pb-ai-plan-mode-rebuild');
            if (!btn || btn.dataset.pbBound === '1') {
                return;
            }
            btn.dataset.pbBound = '1';
            btn.addEventListener('click', function () {
                if (planSseRunning) {
                    return;
                }
                setPlanMode(mode);
                setPlanModeStatus(
                    String(planSseUrl || '').trim() ? messages.planModeReady : messages.planModeUnavailable
                );
            });
        });

        var runBtn = document.getElementById('pb-ai-plan-run-mode');
        if (runBtn && runBtn.dataset.pbBound !== '1') {
            runBtn.dataset.pbBound = '1';
            runBtn.addEventListener('click', function () {
                currentPlanModeTargetScope = '';
                startPlanModeStream(currentPlanMode);
            });
        }
    }

    function parsePlanTerminalPayload(event) {
        if (!event) {
            return {};
        }
        if (event.data && typeof event.data === 'string') {
            try {
                return JSON.parse(event.data);
            } catch (error) {
                return {};
            }
        }
        if (event.data && typeof event.data === 'object') {
            return event.data;
        }
        return {};
    }

    function resolvePlanTerminalErrorMessage(event, payload) {
        var parsed = payload && typeof payload === 'object' ? payload : {};
        var message = String((parsed && parsed.message) || '');
        if (message !== '') {
            return message;
        }
        if (event && typeof event.data === 'string' && String(event.data).trim() !== '') {
            return String(event.data);
        }
        return messages.networkError || '';
    }

    function shouldNotifyPlanModeSuccess(payload) {
        var now = Date.now();
        var fingerprint = [
            String((payload && payload.message) || ''),
            String((payload && payload.prompt_mode) || ''),
            String((payload && payload.plan_locale) || '')
        ].join('|');
        if (
            planModeLastSuccessNotifyFingerprint === fingerprint
            && (now - planModeLastSuccessNotifyAt) < 5000
        ) {
            return false;
        }
        planModeLastSuccessNotifyFingerprint = fingerprint;
        planModeLastSuccessNotifyAt = now;
        return true;
    }

    function extractPlanMarkdownFromPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return '';
        }
        if (payload.plan && typeof payload.plan === 'object') {
            var fromPlan = String(payload.plan.markdown || '');
            if (fromPlan.trim() !== '') {
                return fromPlan;
            }
        }
        if (payload.state && typeof payload.state === 'object' && payload.state.plan && typeof payload.state.plan === 'object') {
            var fromState = String(payload.state.plan.markdown || '');
            if (fromState.trim() !== '') {
                return fromState;
            }
        }
        return String(payload.markdown || payload.updated_markdown || '');
    }

    function bindPlanSseTerminalHandlers() {
        var terminal = window.WelineSseTerminal ? window.WelineSseTerminal['pb-ai-plan-sse-terminal'] : null;
        if (!terminal) {
            return null;
        }
        function markPlanTerminalConnected() {
            var statusEl = document.getElementById('pb-ai-plan-sse-terminal_status');
            if (statusEl) {
                statusEl.textContent = "";
            }
        }
        if (planSseHandlersBound) {
            return terminal;
        }
        terminal.on('start', function () {
            planSseRunning = true;
            setPlanModeRunButtonLoading(true);
            setPlanModeStatus(messages.planModeRunning);
            setPlanModalProgress(messages.planModeRunning, 40, false);
            if (typeof terminal.log === 'function') {
                markPlanTerminalConnected();
                terminal.log(messages.planModeRunning, 'start');
            }
        });
        terminal.on('progress', function (event) {
            var payload = parsePlanTerminalPayload(event);
            if (payload && payload.message) {
                var progressMsg = String(payload.message);
                var isAiHintOnly = /AI\s*姝ｅ湪鐢熸垚闃舵涓€鏂规鍐呭/.test(progressMsg);
                setPlanModeStatus(progressMsg);
                setPlanModalProgress(progressMsg, parseInt(String(payload.progress_percent || 60), 10) || 60, false);
                if (!isAiHintOnly && typeof terminal.log === 'function') {
                    markPlanTerminalConnected();
                    terminal.log(progressMsg, 'progress', payload);
                }
            }
        });
        terminal.on('chunk', function (event) {
            var payload = parsePlanTerminalPayload(event);
            var chunk = String((payload && (payload.content || payload.chunk || payload.message)) || '');
            if (!chunk) {
                return;
            }
            if (typeof terminal.log === 'function') {
                markPlanTerminalConnected();
                terminal.log(chunk, 'chunk', payload);
            }
            updatePlanModalContent(String((document.getElementById('pb-ai-plan-md-content') && document.getElementById('pb-ai-plan-md-content').textContent) || '') + chunk);
        });
        terminal.on('done', function (event) {
            planSseRunning = false;
            setPlanModeRunButtonLoading(false);
            var payload = parsePlanTerminalPayload(event);
            var nextPlan = (payload && payload.plan && typeof payload.plan === 'object') ? payload.plan : null;
            var payloadMarkdown = extractPlanMarkdownFromPayload(payload);
            if (nextPlan) {
                currentPlanPayload = nextPlan;
                updatePlanModalContent(String(nextPlan.markdown || ''));
            } else if (String(payloadMarkdown || '').trim() !== '') {
                currentPlanPayload = Object.assign({}, currentPlanPayload || {}, { markdown: String(payloadMarkdown) });
                updatePlanModalContent(String(payloadMarkdown));
            }
            if (payload && payload.state && typeof payload.state === 'object') {
                hydrateWorkspaceFromState(payload.state);
            }
            setPlanModeStatus(messages.planModeDone);
            var doneLatestMd = String((currentPlanPayload && currentPlanPayload.markdown) || '').trim();
            if (doneLatestMd === '') {
                var doneMdEl = document.getElementById('pb-ai-plan-md-content');
                doneLatestMd = String(doneMdEl && doneMdEl.textContent ? doneMdEl.textContent : '').trim();
            }
            setPlanModalProgress(messages.planModeDone, 100, !planConfirmedState && (doneLatestMd !== '' || hasCurrentPhaseOnePlanDraft()));
            setPlanRetryButtonVisible(true);
            if (typeof terminal.log === 'function') {
                markPlanTerminalConnected();
                terminal.log((payload && payload.message) ? String(payload.message) : messages.planModeDone, 'done', payload || {});
            }
            if (shouldNotifyPlanModeSuccess(payload)) {
                if (window.BackendConfirm && typeof window.BackendConfirm.show === 'function') {
                    window.BackendConfirm.show(
                        (payload && payload.message) ? String(payload.message) : messages.planModeDone,
                        { title: "" }
                    );
                } else if (window.BackendToast && typeof window.BackendToast.success === 'function') {
                    window.BackendToast.success((payload && payload.message) ? String(payload.message) : messages.planModeDone);
                }
            }
            if (typeof terminal.stop === 'function') {
                terminal.stop({ suppressTransportError: true });
            }
            // SSE 鏂规娴佸畬鎴愬悗锛岀珛鍗宠Е鍙戣嚜鍔ㄤ繚瀛橈紝灏嗘柟妗堝唴瀹规寔涔呭寲鍒板悗绔?            autoSaveScope();
        });
        terminal.on('error', function (event) {
            planSseRunning = false;
            setPlanModeRunButtonLoading(false);
            var payload = parsePlanTerminalPayload(event);
            var message = resolvePlanTerminalErrorMessage(event, payload);
            setPlanModeStatus(message);
            setPlanModalProgress(message, 100, false);
            setPlanRetryButtonVisible(true);
            if (typeof terminal.log === 'function') {
                markPlanTerminalConnected();
                terminal.log(message, 'error', payload || {});
            }
            if (typeof terminal.stop === 'function') {
                terminal.stop({ suppressTransportError: true });
            }
        });
        terminal.on('failed', function (event) {
            planSseRunning = false;
            setPlanModeRunButtonLoading(false);
            var payload = parsePlanTerminalPayload(event);
            var message = resolvePlanTerminalErrorMessage(event, payload);
            setPlanModeStatus(message);
            setPlanModalProgress(message, 100, false);
            setPlanRetryButtonVisible(true);
            if (typeof terminal.log === 'function') {
                markPlanTerminalConnected();
                terminal.log(message, 'error', payload || {});
            }
            if (typeof terminal.stop === 'function') {
                terminal.stop({ suppressTransportError: true });
            }
        });
        planSseHandlersBound = true;
        return terminal;
    }

    function startPlanModeStream(mode, options) {
        var opts = options && typeof options === 'object' ? options : {};
        var streamUrl = normalizePlanSseUrl(planSseUrl || '');
        if (!streamUrl) {
            setPlanModeStatus(messages.planModeUnavailable);
            toast('warning', messages.planModeUnavailable);
            return false;
        }
        var terminal = bindPlanSseTerminalHandlers();
        if (!terminal) {
            setPlanModeStatus(messages.planModeUnavailable);
            return false;
        }
        var runBtn = document.getElementById('pb-ai-plan-run-mode');
        var promptInput = document.getElementById('pb-ai-plan-mode-prompt');
        var promptText = String(Object.prototype.hasOwnProperty.call(opts, 'promptText') ? opts.promptText : (promptInput ? promptInput.value : '')).trim();
        var payloadMode = String(mode || currentPlanMode || 'refine');
        var targetScope = String(Object.prototype.hasOwnProperty.call(opts, 'targetScope') ? opts.targetScope : currentPlanModeTargetScope).trim();
        var formData = new FormData();
        formData.append('public_id', publicId);
        formData.append('scope_patch', JSON.stringify(buildVirtualThemePatch()));
        formData.append('prompt_mode', payloadMode);
        if (promptText !== '') {
            formData.append('prompt', promptText);
            formData.append('instruction', promptText);
        }
        if (targetScope !== '') {
            formData.append('target_scope', targetScope);
        }
        formData.append('current_markdown', String((currentPlanPayload && currentPlanPayload.markdown) || ''));
        if (terminal.stop) {
            terminal.stop({ suppressTransportError: true });
        }
        if (terminal.clear) {
            terminal.clear();
        }
        setPlanModeRunButtonLoading(true);
        planSseRunning = true;
        setPlanModeStatus(messages.planModeRunning);
        setPlanModalProgress(messages.planModeRunning, 40, false);
        window.setTimeout(function () {
            try {
                terminal.start(streamUrl, { method: 'POST', body: formData });
            } catch (error) {
                planSseRunning = false;
                setPlanModeRunButtonLoading(false);
                setPlanModeStatus((error && error.message) ? String(error.message) : messages.networkError);
                setPlanModalProgress(messages.networkError, 100, false);
            }
        }, PB_SSE_CONNECT_DELAY_MS);
        return true;
    }

    function getTaskPlanPanelCollapseEl() {
        return document.getElementById('pb-ai-task-plan-panel-collapse');
    }

    function showTaskPlanPanel() {
        var el = getTaskPlanPanelCollapseEl();
        if (!el || typeof bootstrap === 'undefined' || !bootstrap.Collapse) {
            return;
        }
        var c = bootstrap.Collapse.getOrCreateInstance(el, { toggle: false });
        c.show();
    }

    function setTaskPlanModeStatus(message) {
        var statusEl = document.getElementById('pb-ai-task-plan-mode-status');
        if (statusEl) {
            statusEl.textContent = String(message || '');
        }
    }

    function setTaskPlanModeRunButtonLoading(loading) {
        var runBtn = document.getElementById('pb-ai-task-plan-run-mode');
        if (!runBtn) {
            return;
        }
        if (loading) {
            if (!runBtn.dataset.originalText) {
                runBtn.dataset.originalText = String(runBtn.textContent || '');
            }
            runBtn.disabled = true;
            runBtn.textContent = "澶勭悊涓?..";
            return;
        }
        runBtn.disabled = false;
        runBtn.textContent = String(
            runBtn.dataset.originalText
            || runBtn.textContent
            || "鎵ц褰撳墠妯″紡"        );
    }

    function hasTaskPlanDraft() {
        return !!String((currentTaskPlanPayload && currentTaskPlanPayload.markdown) || '').trim();
    }

    function hasCachedTaskPlanForAccordion() {
        if (hasTaskPlanDraft()) {
            return true;
        }
        if (isNonEmptyObject(pickStructuredPlanRoot(currentTaskPlanPayload || {}))) {
            return true;
        }
        var cp = window.__pbWorkspaceConfirmedTaskPlan && typeof window.__pbWorkspaceConfirmedTaskPlan === 'object'
            ? window.__pbWorkspaceConfirmedTaskPlan
            : {};
        if (String(cp.markdown || cp.confirmed_markdown || '').trim() !== '') {
            return true;
        }
        if (isNonEmptyObject(cp.structured)) {
            return true;
        }
        return false;
    }

    function applyCachedTaskPlanToPanel() {
        if (!hasCachedTaskPlanForAccordion()) {
            return false;
        }
        var cp = window.__pbWorkspaceConfirmedTaskPlan && typeof window.__pbWorkspaceConfirmedTaskPlan === 'object'
            ? window.__pbWorkspaceConfirmedTaskPlan
            : {};
        if (!currentTaskPlanPayload || typeof currentTaskPlanPayload !== 'object') {
            currentTaskPlanPayload = {};
        }
        if (String((currentTaskPlanPayload && currentTaskPlanPayload.markdown) || '').trim() === '') {
            currentTaskPlanPayload.markdown = String(cp.markdown || cp.confirmed_markdown || '');
        }
        if (
            (!currentTaskPlanPayload.structured || typeof currentTaskPlanPayload.structured !== 'object' || Object.keys(currentTaskPlanPayload.structured).length === 0)
            && isNonEmptyObject(cp.structured)
        ) {
            currentTaskPlanPayload.structured = cp.structured;
        }
        updateTaskPlanModalContent(String((currentTaskPlanPayload && currentTaskPlanPayload.markdown) || ''));
        setTaskPlanModalProgress(messages.taskPlanGenerated, 100, true);
        return true;
    }

    function buildTaskPlanSaveScopePatch() {
        var payload = currentTaskPlanPayload && typeof currentTaskPlanPayload === 'object' ? currentTaskPlanPayload : {};
        var markdown = String(payload.markdown || '').trim();
        var structured = pickStructuredPlanRoot(payload);
        if (markdown === '' && !isNonEmptyObject(structured)) {
            return null;
        }
        var vt = (payload.virtual_theme_plan && typeof payload.virtual_theme_plan === 'object') ? payload.virtual_theme_plan : {};
        var draftPlan = isNonEmptyObject(structured) ? structured : (isNonEmptyObject(vt.draft) ? vt.draft : {});
        var patch = {
            virtual_theme_plan: {
                draft: draftPlan,
                draft_markdown: markdown,
                draft_generated_at: String(vt.draft_generated_at || ''),
                confirmed: (vt.confirmed && typeof vt.confirmed === 'object') ? vt.confirmed : {},
                confirmed_markdown: String(vt.confirmed_markdown || ''),
                confirmed_at: String(vt.confirmed_at || ''),
                confirmed_signature: String(vt.confirmed_signature || ''),
                plan_signature: String(vt.plan_signature || '')
            },
            task_plan_structured: isNonEmptyObject(structured) ? structured : draftPlan,
            task_plan_confirmed: taskPlanConfirmedState ? 1 : 0
        };
        return patch;
    }

    function hasRunningTaskWorkForMutationWarning() {
        var summary = getTaskSummarySnapshotFromState(null);
        var activeStatus = String((currentActiveOperationState && currentActiveOperationState.status) || '').toLowerCase();
        var activeOpRunning = activeStatus === 'queued' || activeStatus === 'running';
        return !!isOperationRunning || activeOpRunning || summary.running > 0;
    }

    function confirmTaskPlanMutationRiskIfNeeded() {
        if (!hasRunningTaskWorkForMutationWarning()) {
            return Promise.resolve(true);
        }
        if (window.BackendConfirm && typeof window.BackendConfirm.show === 'function') {
            return window.BackendConfirm.show(messages.taskPlanRunningMutationWarningMessage, {
                title: messages.taskPlanRunningMutationWarningTitle,
                confirmText: messages.continueGenerateNow || messages.immediateGenerate,
                cancelText: messages.continueGenerateLater || messages.laterGenerate
            }).then(function (confirmed) {
                return !!confirmed;
            }).catch(function () {
                return false;
            });
        }
        return Promise.resolve(window.confirm(messages.taskPlanRunningMutationWarningMessage));
    }

    function saveTaskPlanDraftThen(callback) {
        var cb = typeof callback === 'function' ? callback : function () {};
        if (!mergeScopeUrl || !String(publicId || '').trim()) {
            cb(false);
            return;
        }
        var scopePatch = buildTaskPlanSaveScopePatch();
        if (!scopePatch) {
            cb(false);
            return;
        }
        confirmTaskPlanMutationRiskIfNeeded().then(function (confirmed) {
            if (!confirmed) {
                toast('info', messages.resumeDeferred);
                cb(false);
                return;
            }
            postForm(mergeScopeUrl, {
                public_id: publicId,
                autosave: '1',
                scope_patch: JSON.stringify(scopePatch)
            }).then(function (data) {
                if (data && data.success && data.data && typeof data.data === 'object') {
                    hydrateWorkspaceFromState(data.data);
                    toast('success', messages.taskPlanDraftSaved);
                    cb(true);
                    return;
                }
                toast('warning', (data && data.message) ? String(data.message) : messages.taskPlanDraftSaveFailed);
                cb(false);
            }).catch(function () {
                toast('error', messages.taskPlanDraftSaveFailed);
                cb(false);
            });
        });
    }

    function savePhaseOnePlanDraftThen(callback) {
        var cb = typeof callback === 'function' ? callback : function () {};
        if (!mergeScopeUrl || !String(publicId || '').trim()) {
            cb(false);
            return;
        }
        var scopePatch = buildVirtualThemePatch();
        var hasPlanMarkdown = !!String(scopePatch.plan_markdown || '').trim();
        var hasPlanJson = !!(scopePatch.plan_json && typeof scopePatch.plan_json === 'object' && Object.keys(scopePatch.plan_json).length > 0);
        if (!hasPlanMarkdown && !hasPlanJson) {
            toast('warning', messages.planStartUnavailable);
            cb(false);
            return;
        }
        postForm(mergeScopeUrl, {
            public_id: publicId,
            autosave: '1',
            scope_patch: JSON.stringify(scopePatch)
        }).then(function (data) {
            if (data && data.success && data.data && typeof data.data === 'object') {
                hydrateWorkspaceFromState(data.data);
                toast('success', messages.planDraftSaved);
                cb(true);
                return;
            }
            toast('warning', (data && data.message) ? String(data.message) : messages.planDraftSaveFailed);
            cb(false);
        }).catch(function () {
            toast('error', messages.planDraftSaveFailed);
            cb(false);
        });
    }

    function setTaskPlanMode(mode) {
        var nextMode = String(mode || 'refine_task_plan').toLowerCase() === 'rebuild_task_plan'
            ? 'rebuild_task_plan'
            : 'refine_task_plan';
        var refineBtn = document.getElementById('pb-ai-task-plan-mode-refine');
        var rebuildBtn = document.getElementById('pb-ai-task-plan-mode-rebuild');
        currentTaskPlanMode = nextMode;
        if (refineBtn) {
            refineBtn.classList.toggle('active', nextMode === 'refine_task_plan');
            refineBtn.setAttribute('aria-pressed', nextMode === 'refine_task_plan' ? 'true' : 'false');
        }
        if (rebuildBtn) {
            rebuildBtn.classList.toggle('active', nextMode === 'rebuild_task_plan');
            rebuildBtn.setAttribute('aria-pressed', nextMode === 'rebuild_task_plan' ? 'true' : 'false');
        }
        var runBtn = document.getElementById('pb-ai-task-plan-run-mode');
        if (runBtn) {
            runBtn.textContent = nextMode === 'rebuild_task_plan'
                ? messages.taskPlanModeRebuild
                : messages.taskPlanModeRefine;
            runBtn.disabled = taskPlanSseRunning || !String(taskPlanSseUrl || '').trim();
        }
    }

    function bindTaskPlanModeSwitchers() {
        var refineBtn = document.getElementById('pb-ai-task-plan-mode-refine');
        var rebuildBtn = document.getElementById('pb-ai-task-plan-mode-rebuild');
        var runBtn = document.getElementById('pb-ai-task-plan-run-mode');
        if (refineBtn && refineBtn.dataset.pbBound !== '1') {
            refineBtn.dataset.pbBound = '1';
            refineBtn.addEventListener('click', function () {
                setTaskPlanMode('refine_task_plan');
            });
        }
        if (rebuildBtn && rebuildBtn.dataset.pbBound !== '1') {
            rebuildBtn.dataset.pbBound = '1';
            rebuildBtn.addEventListener('click', function () {
                setTaskPlanMode('rebuild_task_plan');
            });
        }
        if (runBtn && runBtn.dataset.pbBound !== '1') {
            runBtn.dataset.pbBound = '1';
            runBtn.addEventListener('click', function () {
                currentTaskPlanModeTargetScope = '';
                startTaskPlanModeStream(currentTaskPlanMode);
            });
        }
    }

    function parseTaskPlanTerminalPayload(event) {
        if (!event || typeof event.data !== 'string' || event.data === '') {
            return {};
        }
        try {
            return JSON.parse(event.data);
        } catch (error) {
            return {};
        }
    }

    function extractTaskPlanMarkdownFromPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return '';
        }
        var result = '';
        if (payload.task_plan && typeof payload.task_plan === 'object') {
            result = String(payload.task_plan.markdown || '');
        }
        if (!result && payload.plan && typeof payload.plan === 'object') {
            result = String(payload.plan.markdown || '');
        }
        if (!result && payload.data && typeof payload.data === 'object') {
            if (payload.data.task_plan && typeof payload.data.task_plan === 'object') {
                result = String(payload.data.task_plan.markdown || '');
            }
            if (!result && payload.data.plan && typeof payload.data.plan === 'object') {
                result = String(payload.data.plan.markdown || '');
            }
            if (!result) {
                result = String(payload.data.markdown || '');
            }
        }
        if (!result) {
            result = String(payload.markdown || payload.updated_markdown || '');
        }
        return String(result || '').trim();
    }

    function extractTaskPlanPromptModeFromPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return '';
        }
        var mode = String(payload.prompt_mode || '').trim();
        if (mode !== '') {
            return mode;
        }
        if (payload.data && typeof payload.data === 'object') {
            mode = String(payload.data.prompt_mode || '').trim();
            if (mode !== '') {
                return mode;
            }
        }
        return '';
    }

    function bindTaskPlanSseTerminalHandlers() {
        var terminal = window.WelineSseTerminal ? window.WelineSseTerminal['pb-ai-task-plan-sse-terminal'] : null;
        if (!terminal || taskPlanSseHandlersBound) {
            return terminal;
        }
        terminal.on('start', function () {
            taskPlanStreamAccumMarkdown = '';
            taskPlanSseRunning = true;
            setTaskPlanModeRunButtonLoading(true);
            setTaskPlanModeStatus(messages.taskPlanModeRunning);
            setTaskPlanModalProgress(messages.taskPlanModeRunning, 45, false);
            if (typeof terminal.log === 'function') {
                terminal.log(messages.taskPlanModeRunning, 'start');
            }
        });
        terminal.on('progress', function (event) {
            var payload = parseTaskPlanTerminalPayload(event);
            if (!payload || !payload.message) {
                return;
            }
            var progressMsg = String(payload.message);
            var pct = parseInt(String(payload.progress_percent || 0), 10) || 0;
            setTaskPlanModeStatus(progressMsg);
            setTaskPlanModalProgress(progressMsg, pct > 0 ? pct : 50, false);
            if (typeof terminal.log === 'function') {
                terminal.log(progressMsg, 'progress', payload);
            }
        });
        terminal.on('info', function (event) {
            var payload = parseTaskPlanTerminalPayload(event);
            var msg = String((payload && (payload.message || payload.phase)) || '');
            if (msg !== '' && typeof terminal.log === 'function') {
                terminal.log(msg, 'info', payload);
            }
        });
        terminal.on('chunk', function (event) {
            var payload = parseTaskPlanTerminalPayload(event);
            var chunk = String((payload && (payload.content || payload.chunk)) || '');
            if (!chunk) {
                return;
            }
            taskPlanStreamAccumMarkdown += chunk;
            if (typeof terminal.log === 'function') {
                terminal.log(chunk, 'chunk', payload);
            }
            updateTaskPlanModalContent(taskPlanStreamAccumMarkdown);
            var approx = Math.min(92, 35 + Math.floor(taskPlanStreamAccumMarkdown.length / 600));
            setTaskPlanModalProgress(messages.taskPlanModeRunning, approx, false);
        });
        terminal.on('done', function (event) {
            taskPlanSseRunning = false;
            setTaskPlanModeRunButtonLoading(false);
            var payload = parseTaskPlanTerminalPayload(event);
            var payloadPromptMode = extractTaskPlanPromptModeFromPayload(payload);
            if (payload && payload.state && typeof payload.state === 'object') {
                hydrateWorkspaceFromState(payload.state);
            } else if (payload && payload.data && typeof payload.data === 'object') {
                hydrateWorkspaceFromState(payload.data);
            }
            if (payload && payload.task_plan && typeof payload.task_plan === 'object') {
                currentTaskPlanPayload = Object.assign({}, currentTaskPlanPayload || {}, payload.task_plan);
                var mergedMd = String((currentTaskPlanPayload && currentTaskPlanPayload.markdown) || '').trim();
                if (mergedMd !== '') {
                    updateTaskPlanModalContent(mergedMd);
                }
            } else {
                var markdown = extractTaskPlanMarkdownFromPayload(payload);
                if (markdown) {
                    currentTaskPlanPayload = Object.assign({}, currentTaskPlanPayload || {}, { markdown: markdown });
                    updateTaskPlanModalContent(markdown);
                }
            }
            taskPlanStreamAccumMarkdown = '';
            setTaskPlanModeStatus(messages.taskPlanModeDone);
            setTaskPlanModalProgress(messages.taskPlanModeDone, 100, hasTaskPlanDraft());
            setTaskPlanMode(currentTaskPlanMode);
            if (typeof terminal.log === 'function') {
                terminal.log((payload && payload.message) ? String(payload.message) : messages.taskPlanModeDone, 'done', payload || {});
            }
            if (payloadPromptMode === TASK_PLAN_PROMPT_MODE_DETECT_BOOTSTRAP) {
                var queuedMode = String(pendingTaskPlanModeAfterBootstrap || '').trim();
                pendingTaskPlanModeAfterBootstrap = '';
                if (queuedMode !== '') {
                    startTaskPlanModeStream(queuedMode);
                }
            }
            // 涓庨樁娈典竴鏂规娴佷竴鑷达細蹇呴』涓诲姩 stop锛屽惁鍒欐湇鍔＄缁撴潫杩炴帴鍚庡師鐢?EventSource 浼氶噸杩炲悓涓€ URL锛?
            // detect_bootstrap 绛夐暱浠诲姟浼氳〃鐜颁负銆屽崱浣忓悗鍙堥噸澶嶅彂璧?post-task-plan-sse銆嶃€?
            if (typeof terminal.stop === 'function') {
                terminal.stop({ suppressTransportError: true });
            }
        });
        terminal.on('error', function (event) {
            taskPlanSseRunning = false;
            setTaskPlanModeRunButtonLoading(false);
            taskPlanStreamAccumMarkdown = '';
            var payload = parseTaskPlanTerminalPayload(event);
            var payloadPromptMode = extractTaskPlanPromptModeFromPayload(payload);
            var message = String((payload && payload.message) || messages.networkError || '');
            setTaskPlanModeStatus(message);
            setTaskPlanModalProgress(message, 100, hasTaskPlanDraft());
            setTaskPlanMode(currentTaskPlanMode);
            if (payloadPromptMode === TASK_PLAN_PROMPT_MODE_DETECT_BOOTSTRAP && pendingTaskPlanModeAfterBootstrap) {
                pendingTaskPlanModeAfterBootstrap = '';
                toast('warning', messages.taskPlanModeBootstrapFailed);
            }
            if (typeof terminal.log === 'function') {
                terminal.log(message, 'error', payload || {});
            }
            if (typeof terminal.stop === 'function') {
                terminal.stop({ suppressTransportError: true });
            }
        });
        taskPlanSseHandlersBound = true;
        return terminal;
    }

    /**
     * 闃舵浜屼换鍔℃柟妗?SSE锛欵ventSource 浠?GET銆?     * 鍥哄畾锛歱ublic_id銆乸rompt_mode锛涘井璋?閲嶅缓鏃惰嫢鏈夋枃妗堝垯杩藉姞 instruction锛堟煡璇覆锛夈€?     */
    function buildTaskPlanSseGetUrl(streamUrl, promptMode, instructionText, targetScope) {
        var base = String(streamUrl || '').trim();
        var mode = String(promptMode || '').trim();
        if (!base || !mode) {
            return '';
        }
        var pid = String(publicId || '').trim();
        if (pid === '') {
            return '';
        }
        var instr = String(instructionText || '').trim();
        var scope = String(targetScope || '').trim();
        try {
            var u = new URL(base, window.location.href);
            u.searchParams.set('public_id', pid);
            u.searchParams.set('prompt_mode', mode);
            if (instr !== '') {
                u.searchParams.set('instruction', instr);
            }
            if (scope !== '') {
                u.searchParams.set('target_scope', scope);
            }
            return u.toString();
        } catch (e1) {
            var sep = base.indexOf('?') >= 0 ? '&' : '?';
            var q = new URLSearchParams();
            q.set('public_id', pid);
            q.set('prompt_mode', mode);
            if (instr !== '') {
                q.set('instruction', instr);
            }
            if (scope !== '') {
                q.set('target_scope', scope);
            }
            return base + sep + q.toString();
        }
    }

    /**
     * 鎵嬮鐞村睍寮€锛歋SE detect_bootstrap 鈥?宸叉湁鏂规鍒欐祦寮忓洖濉紝鍚﹀垯鐢熸垚骞惰惤搴撳悗鍐嶈緭鍑恒€?     */
    function startTaskPlanDetectBootstrapSse(options) {
        var opts = options && typeof options === 'object' ? options : {};
        if (taskPlanSseRunning || (!opts.ignoreStartRequestGuard && taskPlanStartRequestInFlight)) {
            return false;
        }
        if (!startTaskPlanUrl) {
            if (!opts.suppressStartFallback && startTaskPlanUrl) {
                return startTaskPlanGenerationForBuild(currentPlanTriggerButton || null, selectedPageTypes(), {
                    suppressAutoShowPanel: true,
                    silentSuccessToast: true
                });
            }
            toast('warning', messages.taskPlanModeUnavailable);
            return false;
        }
        if (!String(publicId || '').trim()) {
            toast('warning', messages.taskPlanStartUnavailable);
            return false;
        }
        currentPlanSelection = ensureRequiredPageTypeSelected(selectedPageTypes()).slice();
        currentPlanTriggerButton = currentPlanTriggerButton || null;
        bindTaskPlanModeSwitchers();
        setTaskPlanMode(currentTaskPlanMode);
        setTaskPlanModeStatus(String(taskPlanSseUrl || '').trim() ? messages.taskPlanModeReady : messages.taskPlanModeUnavailable);
        currentTaskPlanPayload = null;
        updateTaskPlanModalContent(messages.taskPlanDetecting);
        setTaskPlanModalProgress(messages.taskPlanDetecting, 8, false);
        taskPlanSseRunning = true;
        postForm(startTaskPlanUrl, {
            public_id: publicId,
            scope_patch: JSON.stringify(buildVirtualThemePatch()),
            prompt_mode: TASK_PLAN_PROMPT_MODE_DETECT_BOOTSTRAP,
            round: '1'
        }).then(function (data) {
            if (data && data.data && typeof data.data === 'object') {
                hydrateWorkspaceFromState(data.data);
            }
            if (data && data.success && window.PbAiOperationRunner && typeof window.PbAiOperationRunner.startFromResponse === 'function' && window.PbAiOperationRunner.startFromResponse(data, 'task_plan')) {
                return;
            }
            taskPlanSseRunning = false;
            var message = (data && data.message) ? String(data.message) : messages.operationStreamMissing;
            setTaskPlanModeStatus(message);
            setTaskPlanModalProgress(message, 100, false);
            toast((data && data.success) ? 'warning' : 'error', message);
        }).catch(function (error) {
            taskPlanSseRunning = false;
            var message = (error && error.message) ? String(error.message) : messages.networkError;
            setTaskPlanModeStatus(message);
            setTaskPlanModalProgress(message, 100, false);
            toast('error', message);
        });
        return true;
    }

    function startTaskPlanModeStream(mode, options) {
        var opts = options && typeof options === 'object' ? options : {};
        if (taskPlanSseRunning) {
            return false;
        }
        var normalizedMode = String(mode || currentTaskPlanMode || 'refine_task_plan').toLowerCase() === 'rebuild_task_plan'
            ? 'rebuild_task_plan'
            : 'refine_task_plan';
        if (!hasCachedTaskPlanForAccordion()) {
            pendingTaskPlanModeAfterBootstrap = normalizedMode;
            setTaskPlanModeStatus(messages.taskPlanModeBootstrapRequired);
            toast('info', messages.taskPlanModeBootstrapRequired);
            return Promise.resolve(startTaskPlanDetectBootstrapSse()).then(function (started) {
                if (!started) {
                    pendingTaskPlanModeAfterBootstrap = '';
                    toast('warning', messages.taskPlanModeBootstrapFailed);
                }
                return !!started;
            }).catch(function () {
                pendingTaskPlanModeAfterBootstrap = '';
                toast('warning', messages.taskPlanModeBootstrapFailed);
                return false;
            });
        }
        var streamUrl = String(taskPlanSseUrl || '').trim();
        if (!streamUrl) {
            setTaskPlanModeStatus(messages.taskPlanModeUnavailable);
            toast('warning', messages.taskPlanModeUnavailable);
            return false;
        }
        if (!String(publicId || '').trim()) {
            toast('warning', messages.taskPlanStartUnavailable);
            return false;
        }
        var terminal = bindTaskPlanSseTerminalHandlers();
        if (!terminal) {
            setTaskPlanModeStatus(messages.taskPlanModeUnavailable);
            return false;
        }
        var promptInput = document.getElementById('pb-ai-task-plan-mode-prompt');
        var promptText = String(Object.prototype.hasOwnProperty.call(opts, 'promptText') ? opts.promptText : (promptInput ? promptInput.value : '')).trim();
        var payloadMode = normalizedMode;
        var targetScope = String(Object.prototype.hasOwnProperty.call(opts, 'targetScope') ? opts.targetScope : currentTaskPlanModeTargetScope).trim();
        var sseUrl = buildTaskPlanSseGetUrl(streamUrl, payloadMode, promptText, targetScope);
        if (!sseUrl) {
            toast('warning', messages.taskPlanStartUnavailable);
            return false;
        }
        if (terminal.stop) {
            terminal.stop({ suppressTransportError: true });
        }
        if (terminal.clear) {
            terminal.clear();
        }
        setTaskPlanModeRunButtonLoading(true);
        setTaskPlanModeStatus(messages.taskPlanModeRunning);
        setTaskPlanModalProgress(messages.taskPlanModeRunning, 40, false);
        window.setTimeout(function () {
            try {
                taskPlanSseRunning = true;
                terminal.start(sseUrl);
            } catch (error) {
                taskPlanSseRunning = false;
                setTaskPlanModeRunButtonLoading(false);
                setTaskPlanModeStatus((error && error.message) ? String(error.message) : messages.networkError);
                setTaskPlanModalProgress(messages.networkError, 100, hasTaskPlanDraft());
                setTaskPlanMode(currentTaskPlanMode);
            }
        }, PB_SSE_CONNECT_DELAY_MS);
        return true;
    }

    function updateTaskPlanModalContent(markdown) {
        var markdownText = normalizePlanMarkdownText(markdown);
        var previewContent = document.getElementById('pb-ai-task-plan-rendered-content');
        if (previewContent) {
            previewContent.innerHTML = buildPlanPreviewHtml(markdownText, currentTaskPlanPayload || {});
            bindPreviewActionButtons(previewContent);
        }
    }

    function setTaskPlanModalProgress(message, percent, enableConfirm) {
        var statusEl = document.getElementById('pb-ai-task-plan-status');
        var progressBar = document.getElementById('pb-ai-task-plan-progress-bar');
        var confirmBtn = document.getElementById('pb-ai-confirm-task-plan');
        if (statusEl) {
            statusEl.textContent = String(message || '');
        }
        if (progressBar) {
            progressBar.style.width = String(Math.max(0, Math.min(100, percent || 0))) + '%';
        }
        if (confirmBtn) {
            confirmBtn.disabled = !enableConfirm;
        }
    }

    function hideTaskPlanGenerationModal() {
        var taskPlanTerminal = window.WelineSseTerminal ? window.WelineSseTerminal['pb-ai-task-plan-sse-terminal'] : null;
        if (taskPlanTerminal && typeof taskPlanTerminal.stop === 'function') {
            taskPlanTerminal.stop({ suppressTransportError: true });
        }
        taskPlanSseRunning = false;
        var el = getTaskPlanPanelCollapseEl();
        if (el && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
            var c = bootstrap.Collapse.getInstance(el);
            if (c) {
                c.hide();
            }
        }
    }

    /**
     * 绗簩闃舵浠诲姟鏂规锛氭墜椋庣惔灞曞紑鏃舵寜闇€鎷夊彇棣栫増锛涙敹璧锋椂鍋滄 SSE銆?     */
    function fetchLatestWorkspaceStateForTaskPlanGate() {
        if (!stateJsonUrl || typeof fetch !== 'function') {
            return Promise.resolve(null);
        }
        return fetch(stateJsonUrl, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.text();
        }).then(function (text) {
            var payload = null;
            try {
                payload = JSON.parse(text);
            } catch (error) {
                return null;
            }
            if (!payload || !payload.success || !payload.data || typeof payload.data !== 'object') {
                return null;
            }
            hydrateWorkspaceFromState(payload.data);
            return payload.data;
        }).catch(function () {
            return null;
        });
    }

    function verifyTaskPlanAccordionPrerequisiteByAjax() {
        // 鑻ュ綋鍓嶉〉闈㈠凡鍏峰闃舵涓€鏂规鍐呭锛屽垯鐩存帴鏀捐锛堥伩鍏嶅洜 stateJson 鎷夊彇鎱?涓嶅叏瀵艰嚧璇垽锛?
        if (lastPhaseOnePlanPresent || (typeof hasCurrentPhaseOnePlanDraft === 'function' && hasCurrentPhaseOnePlanDraft())) {
            return Promise.resolve(true);
        }

        var startAt = Date.now();
        var timeoutMs = 12000; // 缁欌€滄柟妗堟暟鎹紓姝ュ～鍏呪€濈暀鍑烘椂闂?
        var pollIntervalMs = 1200;

        function computePhaseOneReady(workspaceState) {
            if (!workspaceState || typeof workspaceState !== 'object') {
                return !!lastPhaseOnePlanPresent;
            }
            var ready = !!phaseOnePlanPresentFromWorkspaceState(workspaceState);
            if (!ready) {
                var sc = workspaceState.scope && typeof workspaceState.scope === 'object'
                    ? workspaceState.scope
                    : {};
                // 鏈変簺鎯呭喌涓嬮樁娈典竴鈥滅‘璁ゆ墽琛岃摑鍥锯€濅笉浠?plan_confirmed 瀛楁鍑虹幇
                var execBp = sc.execution_blueprint || sc.execution_blueprint_draft || workspaceState.execution_blueprint || workspaceState.execution_blueprint_draft;
                if (isPlainObjectNonEmpty(execBp)) {
                    ready = true;
                }
                if (!ready && Array.isArray(execBp) && execBp.length > 0) {
                    ready = true;
                }
            }
            return ready;
        }

        return new Promise(function (resolve) {
            var tick = function () {
                if (lastPhaseOnePlanPresent || (typeof hasCurrentPhaseOnePlanDraft === 'function' && hasCurrentPhaseOnePlanDraft())) {
                    resolve(true);
                    return;
                }
                fetchLatestWorkspaceStateForTaskPlanGate().then(function (workspaceState) {
                    var phaseOneReady = computePhaseOneReady(workspaceState);
                    lastPhaseOnePlanPresent = phaseOneReady;
                    applyRunVirtualThemeButtonsDisabledState();
                    if (phaseOneReady) {
                        resolve(true);
                        return;
                    }
                    if (Date.now() - startAt >= timeoutMs) {
                        resolve(false);
                        return;
                    }
                    window.setTimeout(tick, pollIntervalMs);
                }).catch(function () {
                    if (Date.now() - startAt >= timeoutMs) {
                        resolve(!!lastPhaseOnePlanPresent);
                        return;
                    }
                    window.setTimeout(tick, pollIntervalMs);
                });
            };
            tick();
        });
    }

    function bindTaskPlanAccordionOnce() {
        var panel = getTaskPlanPanelCollapseEl();
        if (!panel || panel.dataset.pbCollapseLifecycleBound === '1') {
            return;
        }
        panel.dataset.pbCollapseLifecycleBound = '1';
        var taskPlanAccordionDataCheckInFlight = false;
        var trigger = document.getElementById('pb-ai-task-plan-accordion-trigger');
        if (trigger && trigger.dataset.pbAjaxPrecheckBound !== '1') {
            trigger.dataset.pbAjaxPrecheckBound = '1';
            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopImmediatePropagation();
                if (globalRunVirtualThemeDisabled || trigger.hasAttribute('disabled')) {
                    return;
                }
                verifyTaskPlanAccordionPrerequisiteByAjax().then(function (ready) {
                    if (!ready) {
                        toast('warning', messages.phaseOneRequiredForTaskPlan || "");
                        return;
                    }
                    if (typeof bootstrap === 'undefined' || !bootstrap.Collapse) {
                        return;
                    }
                    var collapse = bootstrap.Collapse.getOrCreateInstance(panel);
                    if (panel.classList.contains('show')) {
                        collapse.hide();
                    } else {
                        collapse.show();
                    }
                });
            }, true);
        }
        panel.addEventListener('show.bs.collapse', function () {
            if (taskPlanStartRequestInFlight || taskPlanSseRunning || taskPlanAccordionDataCheckInFlight) {
                return;
            }
            taskPlanAccordionDataCheckInFlight = true;
            fetchLatestWorkspaceStateForTaskPlanGate().then(function () {
                var needConfirm = !hasCachedTaskPlanForAccordion();
                if (!needConfirm && applyCachedTaskPlanToPanel()) {
                    setTaskPlanModeStatus(taskPlanConfirmedState ? messages.taskPlanConfirmSaved : messages.taskPlanGenerated);
                    return;
                }
                if (needConfirm && window.BackendConfirm && typeof window.BackendConfirm.show === 'function') {
                    return window.BackendConfirm.show(messages.taskPlanGenerateConfirmMessage, {
                        title: messages.taskPlanGenerateConfirmTitle,
                        confirmText: messages.immediateGenerate,
                        cancelText: messages.laterGenerate
                    }).then(function (confirmed) {
                        if (!confirmed) {
                            toast('info', messages.taskPlanGenerateCancelled);
                            var c = (typeof bootstrap !== 'undefined' && bootstrap.Collapse)
                                ? bootstrap.Collapse.getInstance(panel)
                                : null;
                            if (c && typeof c.hide === 'function') {
                                c.hide();
                            }
                            return;
                        }
                        startTaskPlanDetectBootstrapSse();
                    }).catch(function () {});
                }
                startTaskPlanDetectBootstrapSse();
            }).finally(function () {
                taskPlanAccordionDataCheckInFlight = false;
            });
        });
        panel.addEventListener('hidden.bs.collapse', function () {
            var terminal = window.WelineSseTerminal ? window.WelineSseTerminal['pb-ai-task-plan-sse-terminal'] : null;
            if (terminal && typeof terminal.stop === 'function') {
                terminal.stop({ suppressTransportError: true });
            }
            taskPlanSseRunning = false;
            setTaskPlanMode(currentTaskPlanMode);
        });
    }

    function extractResponseCode(data) {
        if (!data || typeof data !== 'object') {
            return '';
        }
        return String(data.code || data.error_code || data.errorCode || '').trim().toUpperCase();
    }

    function isTaskPlanRequiredResponse(data) {
        var code = extractResponseCode(data);
        if (code && (code.indexOf('TASK_PLAN') >= 0 || code.indexOf('TASKPLAN') >= 0)) {
            return true;
        }
        var message = String((data && data.message) || '').toUpperCase();
        if (message.indexOf('TASK_PLAN') >= 0 || message.indexOf('TASK PLAN') >= 0) {
            return true;
        }
        return false;
    }

    function startTaskPlanGenerationForBuild(triggerBtn, selectedTypes, options) {
        var normalizedTypes = ensureRequiredPageTypeSelected(selectedTypes);
        var opts = options && typeof options === 'object' ? options : {};
        if (!startTaskPlanUrl) {
            toast('error', messages.taskPlanStartUnavailable);
            return Promise.resolve(false);
        }
        if (taskPlanStartRequestInFlight) {
            return Promise.resolve(false);
        }
        taskPlanStartRequestInFlight = true;
        currentPlanSelection = normalizedTypes.slice();
        currentPlanTriggerButton = triggerBtn || currentPlanTriggerButton || null;
        currentTaskPlanPayload = null;

        bindTaskPlanModeSwitchers();
        setTaskPlanMode(currentTaskPlanMode);
        setTaskPlanModeStatus(String(taskPlanSseUrl || '').trim() ? messages.taskPlanModeReady : messages.taskPlanModeUnavailable);
        updateTaskPlanModalContent(messages.taskPlanGenerating);
        setTaskPlanModalProgress(messages.taskPlanGenerating, 20, false);

        if (!opts.suppressAutoShowPanel) {
            showTaskPlanPanel();
        }

        var settleInFlight = function () {
            taskPlanStartRequestInFlight = false;
        };

        function startTaskPlanSseFromStartRequest() {
            var maxAttempts = 35;
            function attemptStart(attempt) {
                var started = startTaskPlanDetectBootstrapSse({
                    ignoreStartRequestGuard: true,
                    suppressStartFallback: true
                });
                if (started) {
                    return true;
                }
                if (attempt >= maxAttempts) {
                    var failMsg = messages.taskPlanModeUnavailable;
                    setTaskPlanModeStatus(failMsg);
                    setTaskPlanModalProgress(failMsg, 100, false);
                    if (!opts.silentFailure) {
                        toast('warning', failMsg);
                    }
                    return false;
                }
                window.setTimeout(function () {
                    attemptStart(attempt + 1);
                }, 80);
                return false;
            }
            return attemptStart(0);
        }

        return postForm(startTaskPlanUrl, {
            public_id: publicId,
            scope_patch: JSON.stringify(buildVirtualThemePatch())
        }).then(function (data) {
            if (!data || !data.success) {
                setTaskPlanModalProgress((data && data.message) ? String(data.message) : messages.networkError, 100, false);
                if (!opts.silentFailure) {
                    toast('error', (data && data.message) ? String(data.message) : messages.networkError);
                }
                return false;
            }
            if (data.data && typeof data.data === 'object') {
                hydrateWorkspaceFromState(data.data);
            }
            if (jsonTruthyFlag(data && data.start_sse)) {
                setTaskPlanModalProgress((data && data.message) ? String(data.message) : messages.taskPlanGenerating, 28, false);
                if (!(window.PbAiOperationRunner && typeof window.PbAiOperationRunner.startFromResponse === 'function' && window.PbAiOperationRunner.startFromResponse(data, 'task_plan'))) {
                    startTaskPlanSseFromStartRequest();
                }
                if (!opts.silentSuccessToast && data && data.message) {
                    toast('success', String(data.message));
                }
                return true;
            }
            currentTaskPlanPayload = (data.task_plan && typeof data.task_plan === 'object')
                ? data.task_plan
                : ((data.plan && typeof data.plan === 'object') ? data.plan : (currentTaskPlanPayload || {}));
            updateTaskPlanModalContent(String((currentTaskPlanPayload && currentTaskPlanPayload.markdown) || ''));
            setTaskPlanModalProgress(messages.taskPlanGenerated, 100, true);
            if (!opts.silentSuccessToast) {
                toast('success', messages.taskPlanGenerated);
            }
            return true;
        }).catch(function (error) {
            var message = (error && error.message) ? String(error.message) : messages.networkError;
            setTaskPlanModalProgress(message, 100, false);
            if (!opts.silentFailure) {
                toast('error', message);
            }
            return false;
        }).finally(settleInFlight);
    }

    function startPlanGenerationForSelection(triggerBtn, selectedTypes) {
        var normalizedTypes = ensureRequiredPageTypeSelected(selectedTypes);
        if (!startPlanUrl) {
            toast('error', messages.planStartUnavailable);
            return;
        }
        if (planStartRequestPending) {
            return;
        }
        var previousPlanMarkdown = String((currentPlanPayload && currentPlanPayload.markdown) || '');
        currentPlanSelection = normalizedTypes.slice();
        currentPlanTriggerButton = triggerBtn || null;
        pageTypesUserCustomized = true;
        syncDomPageTypeChecks(normalizedTypes);
        if (typeof window.applyTaskSummarySelectionFilter === 'function') {
            window.applyTaskSummarySelectionFilter();
        }

        proceedToShowPlanModalAndRequestStart(previousPlanMarkdown);
    }

    function proceedToShowPlanModalAndRequestStart(previousPlanMarkdown) {
        bindPlanModalViewSwitchers();
        bindPlanModeSwitchers();
        setPlanMode(currentPlanMode);
        setPlanModeStatus(String(planSseUrl || '').trim() ? messages.planModeReady : messages.planModeUnavailable);
        updatePlanModalContent(previousPlanMarkdown !== '' ? previousPlanMarkdown : messages.planGenerating);
        setPlanViewMode('pb-ai-plan-preview-content');
        setPlanModeStatus(messages.planSaving);
        setPlanModalProgress(messages.planSaving, 20, false);
        setPlanRetryButtonVisible(false);
        scrollIntoPlanInlinePanel();

        function resolveInitialPlanPromptMode() {
            var hasDraft = hasCurrentPhaseOnePlanDraft();
            if (!hasDraft) {
                return 'rebuild';
            }
            return String(currentPlanMode || 'refine') === 'rebuild' ? 'rebuild' : 'refine';
        }

        function startPlanSseFromStartPlan(startReason, options) {
            var reason = String(startReason || 'start-plan');
            var opts = options && typeof options === 'object' ? options : {};
            var payloadMode = String(opts.mode || resolveInitialPlanPromptMode());
            var maxAttempts = 35;
            function attemptStart(attempt) {
                var started = startPlanModeStream(payloadMode);
                if (started) {
                    return true;
                }
                if (attempt >= maxAttempts) {
                    var failMsg = messages.planModeUnavailable;
                    setPlanModeStatus(failMsg);
                    setPlanModalProgress(failMsg, 100, false);
                    setPlanRetryButtonVisible(true);
                    toast('warning', failMsg);
                    return false;
                }
                window.setTimeout(function () {
                    attemptStart(attempt + 1);
                }, 80);
                return false;
            }
            if (typeof previewBridge === 'object' && previewBridge && typeof previewBridge.recordWorkspaceActivity === 'function') {
                previewBridge.recordWorkspaceActivity('plan', reason);
            }
            return attemptStart(0);
        }

        function requestStartPlan(confirmRegenerate) {
            return postForm(startPlanUrl, {
                public_id: publicId,
                scope_patch: JSON.stringify(buildVirtualThemePatch()),
                prompt_mode: resolveInitialPlanPromptMode(),
                confirm_regenerate: confirmRegenerate ? '1' : '0'
            });
        }

        planStartRequestPending = true;
        requestStartPlan(false).then(function (data) {
            if (!data || !data.success) {
                var startErrorMessage = normalizePlanFailureMessage((data && data.message) ? String(data.message) : messages.networkError);
                setPlanModalProgress(startErrorMessage, 100, false);
                setPlanRetryButtonVisible(true);
                toast('error', startErrorMessage);
                planStartRequestPending = false;
                return;
            }
            if (data.data && typeof data.data === 'object') {
                hydrateWorkspaceFromState(data.data);
            }
            var markdownAfterHydrate = String((currentPlanPayload && currentPlanPayload.markdown) || '');
            if (markdownAfterHydrate === '' && previousPlanMarkdown !== '') {
                updatePlanModalContent(previousPlanMarkdown);
            }
            if (data.requires_confirmation) {
                var confirmMessage = String((data && data.confirm_message) || (data && data.message) || '');
                setPlanModalProgress((data && data.message) ? String(data.message) : messages.planGenerating, 30, false);
                toast('info', (data && data.message) ? String(data.message) : messages.planGenerating);
                if (window.BackendConfirm && typeof window.BackendConfirm.show === 'function') {
                    window.BackendConfirm.show(confirmMessage, { title: messages.resumePromptTitle }).then(function (confirmed) {
                        if (!confirmed) {
                            planStartRequestPending = false;
                            return;
                        }
                        requestStartPlan(true).then(function (confirmData) {
                            if (!confirmData || !confirmData.success) {
                                var confirmStartErrorMessage = normalizePlanFailureMessage((confirmData && confirmData.message) ? String(confirmData.message) : messages.networkError);
                                setPlanModalProgress(confirmStartErrorMessage, 100, false);
                                setPlanRetryButtonVisible(true);
                                toast('error', confirmStartErrorMessage);
                                planStartRequestPending = false;
                                return;
                            }
                            if (confirmData.data && typeof confirmData.data === 'object') {
                                hydrateWorkspaceFromState(confirmData.data);
                            }
                            if (jsonTruthyFlag(confirmData && confirmData.start_sse)) {
                                if (!(window.PbAiOperationRunner && typeof window.PbAiOperationRunner.startFromResponse === 'function' && window.PbAiOperationRunner.startFromResponse(confirmData, 'plan'))) {
                                    startPlanSseFromStartPlan('start-plan-confirmed', { mode: resolveInitialPlanPromptMode() });
                                }
                            }
                            var confirmNextMessage = (confirmData && confirmData.message) ? String(confirmData.message) : messages.planGenerating;
                            setPlanModalProgress(confirmNextMessage, 35, false);
                            setPlanRetryButtonVisible(false);
                            toast('success', confirmNextMessage);
                            planStartRequestPending = false;
                        }).catch(function (error) {
                            var confirmErrMsg = normalizePlanFailureMessage((error && error.message) ? String(error.message) : messages.networkError);
                            setPlanModalProgress(confirmErrMsg, 100, false);
                            setPlanRetryButtonVisible(true);
                            toast('error', confirmErrMsg);
                            planStartRequestPending = false;
                        });
                    });
                }
                return;
            }
            var reuseRunningPlan = String((data && data.operation) || '') === 'plan'
                && !!String((data && data.execution_token) || '').trim();
            if (reuseRunningPlan) {
                if (!(window.PbAiOperationRunner && typeof window.PbAiOperationRunner.startFromResponse === 'function' && window.PbAiOperationRunner.startFromResponse(data, 'plan'))) {
                    startPlanSseFromStartPlan('resume-running-plan', { mode: resolveInitialPlanPromptMode() });
                }
            }
            if (data.start_sse === false && !reuseRunningPlan) {
                var keepMessage = (data && data.message) ? String(data.message) : messages.planGenerated;
                setPlanModalProgress(keepMessage, 100, !planConfirmedState && hasCurrentPhaseOnePlanDraft());
                setPlanRetryButtonVisible(false);
                toast('success', keepMessage);
                planStartRequestPending = false;
                return;
            }
            if (jsonTruthyFlag(data && data.start_sse) && !reuseRunningPlan) {
                if (!(window.PbAiOperationRunner && typeof window.PbAiOperationRunner.startFromResponse === 'function' && window.PbAiOperationRunner.startFromResponse(data, 'plan'))) {
                    startPlanSseFromStartPlan('start-plan', { mode: resolveInitialPlanPromptMode() });
                }
            }
            if (reuseRunningPlan && window.__pbWorkspaceApi && typeof window.__pbWorkspaceApi.refreshWorkspaceStateFromServer === 'function') {
                if (typeof previewBridge.resumeWorkspaceStream === 'function') {
                    previewBridge.resumeWorkspaceStream('plan');
                }
                window.__pbWorkspaceApi.refreshWorkspaceStateFromServer().then(function (stateData) {
                    if (!stateData || !stateData.plan || typeof stateData.plan !== 'object') {
                        return;
                    }
                    var refreshedMarkdown = String((stateData.plan.markdown || ''));
                    if (refreshedMarkdown.trim() !== '') {
                        updatePlanModalContent(refreshedMarkdown);
                    }
                });
            }
            var serverDecisionMessage = '';
            if (data && data.plan_rebuild_required) {
                if (data.plan_locale_changed && data.plan_page_types_changed) {
                    serverDecisionMessage = messages.planRegeneratingByPageTypesAndLocale;
                } else {
                    serverDecisionMessage = messages.planRegeneratingByPageTypes;
                }
            } else if (data && data.plan_translation_required) {
                serverDecisionMessage = messages.planRegeneratingByLocale;
            }
            var nextMessage = serverDecisionMessage || ((data && data.message) ? String(data.message) : messages.planGenerating);
            setPlanModalProgress(nextMessage, 35, false);
            setPlanRetryButtonVisible(false);
            toast('success', nextMessage);
            planStartRequestPending = false;
        }).catch(function (error) {
            var message = normalizePlanFailureMessage((error && error.message) ? String(error.message) : messages.networkError);
            setPlanModalProgress(message, 100, false);
            setPlanRetryButtonVisible(true);
            toast('error', message);
            planStartRequestPending = false;
        });
    }

    function startConfirmedBuild(triggerBtn, selectedTypes, options) {
        var opts = options && typeof options === 'object' ? options : {};
        var normalizedTypes = ensureRequiredPageTypeSelected(selectedTypes);
        pageTypesUserCustomized = true;
        syncDomPageTypeChecks(normalizedTypes);
        pbAiConfirmGenerateThemeContinue(triggerBtn || currentPlanTriggerButton, normalizedTypes, opts);
    }

    function confirmCurrentTaskPlanAndMaybeBuild(triggerBtn, selectedTypes) {
        if (!confirmTaskPlanUrl) {
            toast('error', messages.taskPlanStartUnavailable);
            return Promise.resolve(false);
        }
        var confirmBtn = document.getElementById('pb-ai-confirm-task-plan');
        return confirmTaskPlanMutationRiskIfNeeded().then(function (mutationConfirmed) {
            if (!mutationConfirmed) {
                toast('info', messages.resumeDeferred);
                return false;
            }
            if (confirmBtn) {
                confirmBtn.disabled = true;
            }
            return postForm(confirmTaskPlanUrl, {
                public_id: publicId
            }).then(function (data) {
                if (!data || !data.success) {
                    if (confirmBtn) {
                        confirmBtn.disabled = false;
                    }
                    toast('error', (data && data.message) ? String(data.message) : messages.networkError);
                    return false;
                }
                if (data.data && typeof data.data === 'object') {
                    hydrateWorkspaceFromState(data.data);
                }
                taskPlanConfirmedState = true;
                hideTaskPlanGenerationModal();
                toast('success', messages.taskPlanConfirmSaved);
                // T26: 鏂规宸蹭繚瀛橈紝璇㈤棶鐢ㄦ埛鏄惁绔嬪嵆鐢熸垚锛堥粯璁や笉鑷姩寮€璺戯級
                if (window.BackendConfirm && typeof window.BackendConfirm.show === 'function') {
                    window.BackendConfirm.show(messages.taskPlanConfirmStartBuildQuestion, {
                        title: messages.taskPlanConfirmSaved,
                        confirmText: messages.immediateGenerate,
                        cancelText: messages.laterGenerate
                    }).then(function (confirmed) {
                        if (confirmed) {
                            startConfirmedBuild(triggerBtn || currentPlanTriggerButton, selectedTypes || currentPlanSelection, { allowTaskPlanRetry: false });
                        } else {
                            toast('info', messages.taskPlanDeferred);
                        }
                    }).catch(function () {
                        toast('info', messages.taskPlanDeferred);
                    });
                } else {
                    // Fallback: 涓嶈嚜鍔ㄥ紑璺戯紝鎻愮ず鐢ㄦ埛绋嶅悗鎵嬪姩寮€濮?                    toast('info', messages.taskPlanDeferred);
                }
                return true;
            }).catch(function (error) {
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                }
                toast('error', (error && error.message) ? String(error.message) : messages.networkError);
                return false;
            });
        });
    }

    function ensureTaskPlanConfirmedBeforeBuild(triggerBtn, selectedTypes, options) {
        var opts = options && typeof options === 'object' ? options : {};
        var normalizedTypes = ensureRequiredPageTypeSelected(selectedTypes);
        var canUseTaskPlanFlow = !!(startTaskPlanUrl && confirmTaskPlanUrl);
        if (!canUseTaskPlanFlow) {
            startConfirmedBuild(triggerBtn || currentPlanTriggerButton, normalizedTypes, opts);
            return;
        }
        if (!lastPhaseOnePlanPresent) {
            toast('warning', messages.phaseOnePlanMissingForTaskPlan);
            return;
        }
        if (taskPlanConfirmedState) {
            startConfirmedBuild(triggerBtn || currentPlanTriggerButton, normalizedTypes, opts);
            return;
        }
        startTaskPlanGenerationForBuild(triggerBtn || currentPlanTriggerButton, normalizedTypes, {
            silentSuccessToast: true,
            silentFailure: false
        });
    }

    /**
     * 宓屽叆棰勮 iframe锛堥瑙堝崰浣嶉〉锛夐€氳繃 postMessage 璇锋眰鍦ㄥ伐浣滃尯鍚姩 AI 椤甸潰鐢熸垚銆?     */
    function handleEmbeddedPreviewGenerateRequest(pageType) {
        var pt = String(pageType || '').trim();
        if (pt !== '' && window.PbAiWorkspacePreview && typeof window.PbAiWorkspacePreview.switchPreviewByType === 'function') {
            window.PbAiWorkspacePreview.switchPreviewByType(pt);
        }
        var types = pt !== '' ? [pt] : selectedPageTypes();
        if (!types || types.length === 0) {
            toast('warning', messages.noPageType);
            return;
        }
        if (!lastPhaseOnePlanPresent) {
            toast('warning', messages.phaseOnePlanMissingForTaskPlan);
            return;
        }
        ensureTaskPlanConfirmedBeforeBuild(null, types, {});
    }

    function confirmCurrentPlanAndMaybeBuild() {
        if (!confirmPlanUrl) {
            toast('error', messages.planStartUnavailable);
            return;
        }
        postForm(confirmPlanUrl, {
            public_id: publicId
        }).then(function (data) {
            if (!data || !data.success) {
                toast('error', (data && data.message) ? String(data.message) : messages.networkError);
                syncConfirmPlanButtonEnabled();
                return;
            }
            if (data.data && typeof data.data === 'object') {
                hydrateWorkspaceFromState(data.data);
            }
            hidePlanGenerationModal();
            toast('success', (data && data.message) ? String(data.message) : messages.planConfirmSaved);
            // 寮曞寮忓竷灞€鎸夋湇鍔＄闃舵鍒嗙墖娓叉煋锛岀‘璁ゅ悗宸茬敱鎺ュ彛 setStage(visual_edit)锛岄渶鏁撮〉杩涘叆绗簩闃舵 DOM銆?
            var guidedHref = typeof guidedUrl === 'string' ? guidedUrl.trim() : '';
            if (guidedHref !== '') {
                window.location.href = guidedHref;
            } else {
                window.location.reload();
            }
        }).catch(function (error) {
            toast('error', (error && error.message) ? String(error.message) : messages.networkError);
            syncConfirmPlanButtonEnabled();
        });
    }

    function bindPlanStageLogic() {
        var planCloseBtn = document.getElementById('pb-ai-plan-close');
        if (planCloseBtn && planCloseBtn.dataset.pbBound !== '1') {
            planCloseBtn.dataset.pbBound = '1';
            planCloseBtn.addEventListener('click', function () {
                requestClosePlanGenerationModal();
            });
        }

        var planCancelBtn = document.getElementById('pb-ai-plan-cancel');
        if (planCancelBtn && planCancelBtn.dataset.pbBound !== '1') {
            planCancelBtn.dataset.pbBound = '1';
            planCancelBtn.addEventListener('click', function () {
                requestClosePlanGenerationModal();
            });
        }

        var confirmPlanBtn = document.getElementById('pb-ai-confirm-plan');
        if (confirmPlanBtn && confirmPlanBtn.dataset.pbBound !== '1') {
            confirmPlanBtn.dataset.pbBound = '1';
            confirmPlanBtn.addEventListener('click', function () {
                confirmCurrentPlanAndMaybeBuild();
            });
        }
        syncConfirmPlanButtonEnabled();

        var retryPlanBtn = document.getElementById('pb-ai-plan-retry-generate');
        if (retryPlanBtn && retryPlanBtn.dataset.pbBound !== '1') {
            retryPlanBtn.dataset.pbBound = '1';
            retryPlanBtn.addEventListener('click', function () {
                if (planStartRequestPending || planSseRunning) {
                    return;
                }
                var selectedTypes = Array.isArray(currentPlanSelection) && currentPlanSelection.length > 0
                    ? currentPlanSelection.slice()
                    : selectedPageTypes();
                selectedTypes = ensureRequiredPageTypeSelected(selectedTypes);
                startPlanGenerationForSelection(currentPlanTriggerButton || this, selectedTypes);
            });
        }
        var savePlanBtn = document.getElementById('pb-ai-save-plan-draft');
        if (savePlanBtn && savePlanBtn.dataset.pbBound !== '1') {
            savePlanBtn.dataset.pbBound = '1';
            savePlanBtn.addEventListener('click', function () {
                savePhaseOnePlanDraftThen(function () {});
            });
        }

        var confirmTaskPlanBtn = document.getElementById('pb-ai-confirm-task-plan');
        if (confirmTaskPlanBtn && confirmTaskPlanBtn.dataset.pbBound !== '1') {
            confirmTaskPlanBtn.dataset.pbBound = '1';
            confirmTaskPlanBtn.addEventListener('click', function () {
                confirmCurrentTaskPlanAndMaybeBuild(currentPlanTriggerButton, currentPlanSelection);
            });
        }
        var saveTaskPlanBtn = document.getElementById('pb-ai-save-task-plan-draft');
        if (saveTaskPlanBtn && saveTaskPlanBtn.dataset.pbBound !== '1') {
            saveTaskPlanBtn.dataset.pbBound = '1';
            saveTaskPlanBtn.addEventListener('click', function () {
                saveTaskPlanDraftThen(function () {});
            });
        }
    }

    function bindVisualEditStageLogic() {
        bindPlanStageLogic();
        bindTaskPlanAccordionOnce();
        scheduleVisualEditResumePrompt();
    }

    function getTaskSummarySnapshotFromState(workspaceState) {
        var summary = null;
        if (workspaceState && typeof workspaceState === 'object' && workspaceState.build_task_summary && typeof workspaceState.build_task_summary === 'object') {
            summary = workspaceState.build_task_summary;
        }
        if (!summary && window.__pbTaskSummary && typeof window.__pbTaskSummary === 'object') {
            summary = window.__pbTaskSummary;
        }
        if (!summary) {
            summary = {};
        }
        return {
            total: parseInt(String(summary.total || 0), 10) || 0,
            done: parseInt(String(summary.done || 0), 10) || 0,
            pending: parseInt(String(summary.pending || 0), 10) || 0,
            running: parseInt(String(summary.running || 0), 10) || 0
        };
    }

    function renderTaskProgressBarText(percent) {
        var p = Math.max(0, Math.min(100, parseInt(String(percent || 0), 10) || 0));
        var totalBlocks = 10;
        var filled = Math.max(0, Math.min(totalBlocks, Math.round((p / 100) * totalBlocks)));
        return '#'.repeat(filled) + '-'.repeat(totalBlocks - filled);
    }

    function buildVisualEditResumePromptMessage(summary, runningOperation) {
        var progressPercent = summary.total > 0 ? Math.round((summary.done / summary.total) * 100) : 0;
        var header = runningOperation ? messages.resumeRunningPrompt : messages.resumePendingPrompt;
        var progressLine = "褰撳墠杩涘害"            + ': ' + progressPercent + '% [' + renderTaskProgressBarText(progressPercent) + ']';
        var countLine = "浠诲姟缁熻"            + ': '
            + "总计" + ' ' + summary.total + '，'
            + "已完成" + ' ' + summary.done + '，'
            + "进行中" + ' ' + summary.running + '，'
            + "待处理" + ' ' + summary.pending;
        return header + '\n\n' + progressLine + '\n' + countLine;
    }

    function startOrObserveBuildFromVisualEditEntry() {
        var active = currentActiveOperationState && typeof currentActiveOperationState === 'object'
            ? currentActiveOperationState
            : {};
        var activeStatus = String(active.status || '').toLowerCase();
        var isActiveRunning = (activeStatus === 'queued' || activeStatus === 'running');
        if (isActiveRunning && String(active.execution_token || '').trim() !== '') {
            if (window.PbAiOperationRunner && typeof window.PbAiOperationRunner.startFromResponse === 'function') {
                var payload = Object.assign({ success: true }, active);
                if (window.PbAiOperationRunner.startFromResponse(payload, String(active.operation || 'build'))) {
                    toast('success', messages.resumeStarted);
                    return;
                }
            }
        }
        if (!resumeBuildUrl) {
            toast('warning', messages.taskPlanStartUnavailable || messages.networkError);
            return;
        }
        postForm(resumeBuildUrl, { public_id: publicId }).then(function (data) {
            if (!data || !data.success) {
                toast('error', (data && data.message) ? String(data.message) : messages.networkError);
                return;
            }
            if (data.data && typeof data.data === 'object') {
                hydrateWorkspaceFromState(data.data);
            }
            if (window.PbAiOperationRunner && typeof window.PbAiOperationRunner.startFromResponse === 'function') {
                if (window.PbAiOperationRunner.startFromResponse(data, 'build')) {
                    toast('success', messages.resumeStarted);
                    return;
                }
            }
            toast('warning', messages.operationStreamMissing || messages.networkError);
        }).catch(function (error) {
            toast('error', (error && error.message) ? String(error.message) : messages.networkError);
        });
    }

    function scheduleVisualEditResumePrompt() {
        if (visualEditResumePrompted || currentStageCode !== 'visual_edit') {
            return;
        }
        visualEditResumePrompted = true;
        window.setTimeout(function () {
            if (!taskPlanConfirmedState) {
                return;
            }
            var summary = getTaskSummarySnapshotFromState(null);
            var runningOperation = !!isOperationRunning || summary.running > 0;
            var hasUnfinishedTasks = summary.total > 0 && summary.done < summary.total;
            if (!runningOperation && !hasPendingBuildTasksState && !hasUnfinishedTasks && summary.pending <= 0) {
                return;
            }
            var confirmMessage = buildVisualEditResumePromptMessage(summary, runningOperation);
            if (window.BackendConfirm && typeof window.BackendConfirm.show === 'function') {
                window.BackendConfirm.show(confirmMessage, {
                    title: messages.resumePromptTitle,
                    confirmText: messages.continueGenerateNow || messages.immediateGenerate,
                    cancelText: messages.continueGenerateLater || messages.laterGenerate
                }).then(function (confirmed) {
                    if (!confirmed) {
                        toast('info', messages.resumeDeferred);
                        return;
                    }
                    startOrObserveBuildFromVisualEditEntry();
                }).catch(function () {
                    toast('info', messages.resumeDeferred);
                });
                return;
            }
            var proceed = window.confirm(confirmMessage);
            if (proceed) {
                startOrObserveBuildFromVisualEditEntry();
            } else {
                toast('info', messages.resumeDeferred);
            }
        }, 360);
    }

    function pbAiConfirmGenerateThemeContinue(confirmBtn, selectedTypes, options) {
            var opts = options && typeof options === 'object' ? options : {};
            if (!runVirtualThemeUrl) {
                toast('error', messages.missingRunVirtualTheme);
                return;
            }
            try {
                pageTypesUserCustomized = true;
                buildStartupPending = true;
                showBuildGuard(messages.buildPreparing);
                setBuildNavigationGuard(true);

                // 鍚屾鍥炴簮閫夋嫨鍣?                syncDomPageTypeChecks(selectedTypes);

                setRunVirtualThemeButtonsDisabled(true);
                if (confirmBtn) {
                    confirmBtn.disabled = true;
                }

                // 鍚姩 SSE 娴佸紡鐢熸垚
                postForm(runVirtualThemeUrl, {
                    public_id: publicId,
                    scope_patch: JSON.stringify(buildVirtualThemePatch())
                }).then(function (data) {
                if (!data || !data.success) {
                    if (data && isTaskPlanRequiredResponse(data) && startTaskPlanUrl && confirmTaskPlanUrl && opts.allowTaskPlanRetry !== false) {
                        if (data.data && typeof data.data === 'object') {
                            hydrateWorkspaceFromState(data.data);
                        }
                        buildStartupPending = false;
                        hideBuildGuard();
                        setBuildNavigationGuard(false);
                        setRunVirtualThemeButtonsDisabled(false);
                        if (confirmBtn) {
                            confirmBtn.disabled = false;
                        }
                        toast('info', (data && data.message) ? String(data.message) : messages.taskPlanRequiredByBuild);
                        startTaskPlanGenerationForBuild(confirmBtn || currentPlanTriggerButton, selectedTypes, {
                            silentSuccessToast: true,
                            silentFailure: false
                        });
                        return;
                    }
                    var canResumeRunning = !!(
                        data
                        && data.operation
                        && data.execution_token
                        && (String(data.operation) === 'build' || String(data.operation) === 'regenerate_page' || String(data.operation) === 'publish')
                    );
                    if (canResumeRunning && window.PbAiOperationRunner && typeof window.PbAiOperationRunner.startFromResponse === 'function') {
                        var resumePayload = Object.assign({}, data, { success: true });
                        if (window.PbAiOperationRunner.startFromResponse(resumePayload, String(data.operation || 'build'))) {
                            toast('warning', (data && data.message)
                                ? String(data.message)
                                : (messages.operationResumed || "检测到已有操作，已自动接管任务流。"));
                            return;
                        }
                    }
                    buildStartupPending = false;
                    hideBuildGuard();
                    setBuildNavigationGuard(false);
                    setRunVirtualThemeButtonsDisabled(false);
                    if (confirmBtn) {
                        confirmBtn.disabled = false;
                    }
                    toast('error', (data && data.message) ? String(data.message) : messages.networkError);
                    return;
                }
                toast('success', messages.orchestrated);

                // 鍚姩 SSE 娴佸紡鐩戝惉
                if (window.PbAiOperationRunner && typeof window.PbAiOperationRunner.startFromResponse === 'function') {
                    if (window.PbAiOperationRunner.startFromResponse(data)) {
                        return;
                    }
                }

                buildStartupPending = false;
                hideBuildGuard();
                setBuildNavigationGuard(false);
                resetBuildStartUi();
                toast('error', messages.operationStreamMissing);
                }).catch(function () {
                    buildStartupPending = false;
                    hideBuildGuard();
                    setBuildNavigationGuard(false);
                    resetBuildStartUi();
                    toast('error', messages.networkError);
                });
            } catch (syncError) {
                buildStartupPending = false;
                hideBuildGuard();
                setBuildNavigationGuard(false);
                resetBuildStartUi();
                toast('error', (syncError && syncError.message) ? String(syncError.message) : messages.networkError);
            }
    }

    window.PbAiWorkspacePreview = {
        isGuidedUi: isGuidedUi,
        messages: messages,
        toast: toast,
        validateVirtualThemeInputs: validateVirtualThemeInputs,
        ensureTargetDomainOkBeforeBuild: ensureTargetDomainOkBeforeBuild,
        setTargetDomainFieldInvalid: setTargetDomainFieldInvalid,
        bindTargetDomainInvalidClearOnce: bindTargetDomainInvalidClearOnce,
        resetBuildStartUi: resetBuildStartUi,
        compactPageDisplayLabel: compactPageDisplayLabel,
        upsertPreviewTab: upsertPreviewTab,
        switchPreviewByType: function (pageType) {
            var tab = document.querySelector('.pb-ai-preview-tab[data-page-type="' + pageType + '"]');
            if (!tab) {
                return false;
            }
            switchPreviewByTab(tab);
            return true;
        },
        markPageGenerationStatus: markPageGenerationStatus,
        queueGenerationStatus: queueGenerationStatus,
        buildVirtualThemePatch: buildVirtualThemePatch,
        syncPreviewMetaFromState: syncPreviewMetaFromState,
        switchWorkspaceStage: switchWorkspaceStage,
        showBuildGuard: showBuildGuard,
        hideBuildGuard: hideBuildGuard,
        updateBuildGuardProgress: updateBuildGuardProgress,
        resetBuildGuardProgress: resetBuildGuardProgress,
        setBuildNavigationGuard: setBuildNavigationGuard,
        updateVirtualBlockState: updateVirtualBlockState,
        replaceCurrentBlockHtml: replaceCurrentBlockHtml,
        bindEmbeddedPreviewFrameBridge: bindEmbeddedPreviewFrameBridge,
        pauseWorkspaceStream: function () {
            window.__pbWorkspaceStreamPaused = true;
            var workspaceTerminalInst = window.WelineSseTerminal
                ? window.WelineSseTerminal['pagebuilder-workspace-terminal']
                : null;
            if (workspaceTerminalInst && typeof workspaceTerminalInst.stop === 'function') {
                workspaceTerminalInst.stop({ suppressTransportError: true });
            }
        },
        resumeWorkspaceStream: function () {
            window.__pbWorkspaceStreamPaused = false;
            var workspaceTerminalInst = window.WelineSseTerminal
                ? window.WelineSseTerminal['pagebuilder-workspace-terminal']
                : null;
            if (workspaceTerminalInst && typeof workspaceTerminalInst.isRunning === 'function' && workspaceTerminalInst.isRunning()) {
                return;
            }
        },
        setVirtualPagesByType: function (pages) {
            if (pages && typeof pages === 'object') {
                mergeVirtualPagesByTypeState(pages);
            }
        },
        handleEmbeddedPreviewGenerateRequest: handleEmbeddedPreviewGenerateRequest
    };

    function bindPublishStageLogic() {
        var openPublishPreviewBtn = document.getElementById('pb-ai-open-publish-preview');
        if (openPublishPreviewBtn && openPublishPreviewBtn.dataset.pbBound !== '1') {
            openPublishPreviewBtn.dataset.pbBound = '1';
            openPublishPreviewBtn.addEventListener('click', function () {
                var previewUrl = String(previewFullUrl || visualPreviewUrl || currentEmbeddedVisualUrl || '').trim();
                if (!previewUrl) {
                    toast('warning', messages.publishPreviewUnavailable || messages.networkError);
                    return;
                }
                window.open(previewUrl, '_blank', 'noopener');
            });
        }

        var publishBtn = document.getElementById('pb-ai-run-publish-check');
        var publishOutput = document.getElementById('pb-ai-publish-check-output');
        if (publishBtn && publishOutput && publishBtn.dataset.pbBound !== '1') {
            publishBtn.dataset.pbBound = '1';
            publishBtn.addEventListener('click', function () {
                if (!publishCheckUrl) { return; }
                postForm(publishCheckUrl, { public_id: publicId }).then(function (data) {
                    if (data && data.success) {
                        publishOutput.textContent = JSON.stringify(data.data || {}, null, 2);
                        publishOutput.classList.remove('d-none');
                        publishOutput.style.display = 'block';
                        var passed = data.data && data.data.passed;
                        toast(passed ? 'success' : 'warning', passed
                            ? "鍙戝竷妫€鏌ラ€氳繃"                            : ""                        );
                    } else {
                        toast('error', (data && data.message) ? String(data.message) : messages.networkError);
                    }
                }).catch(function () { toast('error', messages.networkError); });
            });
        }
    }

    window.__pbStageLogic = {
        bindPlanStageLogic: bindPlanStageLogic,
        bindVisualEditStageLogic: bindVisualEditStageLogic,
        bindPublishStageLogic: bindPublishStageLogic
    };

    var mergeBtn = document.getElementById('pb-ai-merge-scope');
    var replaceBtn = document.getElementById('pb-ai-replace-scope');
    var patchTA = document.getElementById('pb-ai-scope-patch');
    var fullTA = document.getElementById('pb-ai-scope-full');
    if (mergeBtn && patchTA) {
        mergeBtn.addEventListener('click', function () {
            postForm(mergeScopeUrl, { public_id: publicId, scope_patch: patchTA.value }).then(function (data) {
                if (data && data.success) { toast('success', messages.saveSuccess); window.location.reload(); return; }
                toast('error', (data && data.message) ? String(data.message) : messages.networkError);
            }).catch(function () { toast('error', messages.networkError); });
        });
    }
    if (replaceBtn && fullTA) {
        replaceBtn.addEventListener('click', function () {
            postForm(replaceScopeUrl, { public_id: publicId, scope: fullTA.value }).then(function (data) {
                if (data && data.success) { toast('success', messages.saveSuccess); window.location.reload(); return; }
                toast('error', (data && data.message) ? String(data.message) : messages.networkError);
            }).catch(function () { toast('error', messages.networkError); });
        });
    }

    if (!window.__pbAiEmbeddedPreviewMessageBound) {
        window.__pbAiEmbeddedPreviewMessageBound = true;
        window.addEventListener('message', function (event) {
            if (!event || !event.data || typeof event.data !== 'object') {
                return;
            }
            if (event.origin !== window.location.origin) {
                return;
            }
            if (event.data.type !== 'pb-ai-workspace-preview' || event.data.action !== 'request-ai-generate') {
                return;
            }
            var incomingPageType = String(event.data.page_type || '').trim();
            handleEmbeddedPreviewGenerateRequest(incomingPageType);
        });
    }

    if (initialWorkspaceState && typeof initialWorkspaceState === 'object') {
        hydrateWorkspaceFromState(initialWorkspaceState);
    }
})();
