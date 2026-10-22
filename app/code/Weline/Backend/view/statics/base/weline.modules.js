// Weline Modules Configuration (Compiled)
(function() {
    window.WelineModulesConfig = window.WelineModulesConfig || {};
    window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
    window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

    // 一次性合并所有模块配置
    Object.assign(window.WelineModulesConfig.modules, {
        adminCollectorBrowser: {
            origin_paths: ["app/code/Weline/Admin/view/statics/backend/collector-browser.js?v=20261021"],
            paths: ["Weline_Admin::backend/collector-browser.js?v=20261021"],
            description: "Admin 事件/插件/路由收集器浏览器：搜索、筛选、展开折叠与结果计数"
        },
        adminRoleAssign: {
            origin_paths: ["app/code/Weline/Admin/view/statics/backend/role-assign.js?v=20261021"],
            paths: ["Weline_Admin::backend/role-assign.js?v=20261021"],
            description: "Admin 管理员角色分配：下拉选择后异步保存并同步状态徽章"
        },
        adminMenuTree: {
            origin_paths: ["app/code/Weline/Admin/view/statics/backend/menu-tree.js?v=20261021"],
            paths: ["Weline_Admin::backend/menu-tree.js?v=20261021"],
            description: "Admin 菜单树：节点展开折叠、新增/编辑弹层与删除确认"
        },
        themeComponentParams: {
            origin_paths: ["app/code/Weline/Theme/view/statics/backend/theme-component-params.js"],
            paths: ["Weline_Theme::backend/theme-component-params.js"],
            globalVar: "WelineThemeComponentParams",
            description: "主题组件预览：组件参数保存初始化（页面 data-weline-load 按需异步加载）"
        },
        mediaManagerBoot: {
            origin_paths: ["app/code/Weline/MediaManager/view/statics/backend/media-manager-boot.js?v=20261021"],
            paths: ["Weline_MediaManager::backend/media-manager-boot.js?v=20261021"],
            globalVar: "WelineMediaManagerBoot",
            description: "媒体管理器：读取页面 mmf-bootstrap-config JSON 后调用 WelineMediaManager.init"
        },
        componentLibrary: {
            origin_paths: ["app/code/Weline/Component/view/statics/backend/component-library.js?v=20261021"],
            paths: ["Weline_Component::backend/component-library.js?v=20261021"],
            globalVar: "WelineComponentLibrary",
            description: "组件库：组件详情弹窗"
        },
        marketingCouponForm: {
            origin_paths: ["app/code/Weline/Marketing/view/statics/js/backend/coupon-form.js?v=20260914-coupon-form4"],
            paths: ["Weline_Marketing::js/backend/coupon-form.js?v=20260914-coupon-form4"],
            globalVar: "WelineMarketingCouponFormModule",
            description: "后台优惠券表单：折扣单位随类型切换"
        },
        websiteMaintenance: {
            origin_paths: ["app/code/Weline/Websites/view/statics/backend/websites-maintenance.js?v=20261021"],
            paths: ["Weline_Websites::backend/websites-maintenance.js?v=20261021"],
            globalVar: "WelineWebsitesMaintenance",
            description: "网站维护模式：切换站点维护开关"
        },
        websiteBackup: {
            origin_paths: ["app/code/Weline/Websites/view/statics/backend/websites-backup.js?v=20261021"],
            paths: ["Weline_Websites::backend/websites-backup.js?v=20261021"],
            globalVar: "WelineWebsitesBackup",
            description: "网站备份管理：创建/下载/删除备份"
        },
        paymentMethodAdmin: {
            origin_paths: ["app/code/Weline/Payment/view/statics/backend/method-admin.js?v=1.0.0"],
            paths: ["Weline_Payment::backend/method-admin.js?v=1.0.0"],
            description: "支付方式管理：同步提供商与拖拽排序"
        },
        paymentDevRelay: {
            origin_paths: ["app/code/Weline/Payment/view/statics/js/backend/dev-relay.js?v=1.9.61"],
            paths: ["Weline_Payment::js/backend/dev-relay.js?v=1.9.61"],
            globalVar: "WelinePaymentDevRelay",
            description: "Dev Relay 配置/日志页面逻辑"
        },
        paymentDevRelayInit: {
            origin_paths: ["app/code/Weline/Payment/view/statics/backend/dev-relay-init.js?v=1.0.0"],
            paths: ["Weline_Payment::backend/dev-relay-init.js?v=1.0.0"],
            description: "Dev Relay 配置页初始化（读取内嵌 JSON 配置）"
        },
        productQuoteMail: {
            origin_paths: ["app/code/Weline/Product/view/statics/backend/quote-request-mail.js?v=1.0.0"],
            paths: ["Weline_Product::backend/quote-request-mail.js?v=1.0.0"],
            globalVar: "WelineProductQuoteMail",
            description: "商品询价：企业邮箱回复入口（data-quote-mail-open 绑定）"
        },
        dropshipListedAccordion: {
            origin_paths: ["app/code/Weline/Dropship/view/statics/backend/listed-accordion.js?v=1.0.70"],
            paths: ["Weline_Dropship::backend/listed-accordion.js?v=1.0.70"],
            globalVar: "WelineDropshipListedAccordion",
            description: "已刊列表手风琴：展开后再异步加载本地产品详情/规格"
        },
        dropshipOrderAccordion: {
            origin_paths: ["app/code/Weline/Dropship/view/statics/backend/order-accordion.js?v=1.0.69"],
            paths: ["Weline_Dropship::backend/order-accordion.js?v=1.0.69"],
            globalVar: "WelineDropshipOrderAccordion",
            description: "履约订单手风琴：展开后再异步加载订单商品行"
        },
        cdnFpcPolicyManagement: {
            origin_paths: ["app/code/Weline/Cdn/view/statics/js/backend/fpc-policy-management.js?v=20260928-fpc6"],
            paths: ["Weline_Cdn::js/backend/fpc-policy-management.js?v=20260928-fpc6"],
            globalVar: "WelineCdnFpcPolicyModule",
            description: "控制器 FPC 策略、注释规则与同步回执"
        },
        serverReverseProxy: {
            origin_paths: ["app/code/Weline/Server/view/statics/backend/reverse-proxy.js?v=20261021-reverse-proxy1"],
            paths: ["Weline_Server::backend/reverse-proxy.js?v=20261021-reverse-proxy1"],
            globalVar: "WelineServerReverseProxy",
            description: "反向代理规则列表：应用配置、测试连接、启停与删除"
        },
        serverMonitor: {
            origin_paths: ["app/code/Weline/Server/view/statics/backend/server-monitor.js?v=20261021-monitor1"],
            paths: ["Weline_Server::backend/server-monitor.js?v=20261021-monitor1"],
            globalVar: "WelineServerMonitor",
            description: "服务器监控页：30 秒自动刷新、进程明细、流量与压测、日志清理"
        },
        b2bControlCenter: {
            origin_paths: ["app/code/Weline/B2B/view/statics/backend/control-center.js?v=1.0.0"],
            paths: ["Weline_B2B::backend/control-center.js?v=1.0.0"],
            description: "B2B 控制中心：挂起订单复制链接与订单聊天启动"
        },
        b2bOrderChat: {
            origin_paths: ["app/code/Weline/B2B/view/statics/backend/order-chat-accordion.js?v=20260912-order-chat4"],
            paths: ["Weline_B2B::backend/order-chat-accordion.js?v=20260912-order-chat4"],
            globalVar: "WelineB2BOrderChat",
            description: "B2B order chat accordion on backend order view"
        },
        reviewAdmin: {
            origin_paths: ["app/code/Weline/Review/view/statics/backend/review-admin.js?v=1.0.0"],
            paths: ["Weline_Review::backend/review-admin.js?v=1.0.0"],
            description: "评论管理：审核动作提交"
        },
        btServerList: {
            origin_paths: ["app/code/Weline/Bt_Center/view/statics/backend/bt-server-list.js?v=1.0.0"],
            paths: ["Weline_Bt_Center::backend/bt-server-list.js?v=1.0.0"],
            description: "BT 服务器列表：删除确认"
        },
        btServerForm: {
            origin_paths: ["app/code/Weline/Bt_Center/view/statics/backend/bt-server-form.js?v=1.0.0"],
            paths: ["Weline_Bt_Center::backend/bt-server-form.js?v=1.0.0"],
            description: "BT 服务器表单：密码显隐与异步保存"
        },
        feedCatalog: {
            origin_paths: ["app/code/Weline/Feed/view/statics/backend/feed-catalog.js?v=20261022c"],
            paths: ["Weline_Feed::backend/feed-catalog.js?v=20261022c"],
            globalVar: "WelineFeedCatalog",
            description: "购物目录：列表、弹层新增编辑、分类挂钩、生成、启停与归档"
        }
    });

    // 一次性合并所有模块别名
    Object.assign(window.WelineModulesConfig.moduleAliases, {

    });
})();