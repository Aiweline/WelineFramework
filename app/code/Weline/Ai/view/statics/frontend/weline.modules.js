/**
 * Weline Ai 前台 JS 模块注册
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    aiIndex: {
        paths: [
            "Weline_Ai::js/ai-index.js?v=20261021-ai-index-1"
        ],
        globalVar: null,
        load: "eager",
        description: "AI 首页粒子背景与滚动显现"
    },
    aiChat: {
        paths: [
            "Weline_Ai::js/ai-chat.js?v=20261021-ai-chat-1"
        ],
        globalVar: null,
        load: "eager",
        description: "AI 聊天页应用"
    }
});
