/**
 * Weline HelpPay 前端模块：帮我付出链弹层、分享双形态（链接+二维码）
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};

Object.assign(window.WelineModulesConfig.modules, {
    helpPayCtaCapture: {
        paths: [
            "Weline_HelpPay::js/helppay-cta-capture.js?v=20261121-cta-capture1"
        ],
        globalVar: null,
        load: "eager",
        description: "帮我付 CTA 预捕获：主模块延迟加载前的首点入队 __helppayPendingOpen"
    },
    helpPayShare: {
        paths: [
            "Weline_HelpPay::js/helppay-share.js?v=20261006-cartamt1"
        ],
        globalVar: "WelineModules.helpPayShare",
        load: "defer",
        description: "帮我付 / 纯分享 / 快捷购买 / 商品找朋友代付：规则确认、出链双形态复制（样式由脚本注入主题 Token CSS）"
    }
});
