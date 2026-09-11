/**
 * Weline Customer 前端 JS 模块注册
 * - account：会话 / 顶栏账户 chrome（原 Frontend weline-api-account）
 * - customerAccount / customerLogout / customerSocialQuick：用户中心页 UI
 * 模块名必须是可解析的裸标识符（编译器不识别 "hyphen-keys"）。
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    account: {
        paths: [
            "Weline_Customer::js/account-session.js"
        ],
        globalVar: "WelineAccountModule",
        load: "defer",
        description: "前台账户会话与顶栏账户 chrome"
    },
    customerAccount: {
        paths: [
            "Weline_Customer::js/account-index.js?v=20260906-profile-header-sync-1"
        ],
        globalVar: "WelineCustomerAccount",
        description: "前台用户中心账户页交互"
    },
    customerLogout: {
        paths: [
            "Weline_Customer::js/account-logout.js"
        ],
        globalVar: "WelineCustomerLogout",
        description: "前台账户退出确认"
    },
    customerSocialQuick: {
        paths: [
            "Weline_Customer::js/account-social-quick.js?v=20260910-mount-fw2"
        ],
        globalVar: "WelineSocialQuick",
        description: "未登录右下角社媒快捷登录条（由 account JS 动态拉起）"
    },
    customerLoginPanel: {
        paths: [
            "Weline_Customer::js/account-login-panel.js?v=20260910-mount-self-provide"
        ],
        globalVar: "WelineLoginPanel",
        description: "可挂载完整登录面板（快捷+账密）"
    }
});
