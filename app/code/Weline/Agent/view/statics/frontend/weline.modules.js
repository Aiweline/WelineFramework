/**
 * Weline_Agent 前端模块注册（weline.modules.js）
 *
 * data-weline-load="agentChat" / "agentFlow" → 加载对应 JS 并挂载。
 * 必须使用 Object.assign 形式：resource 编译器只解析该写法。
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    agentChat: {
        paths: [
            "Weline_Agent::js/agent-chat.js?v=20261201-agent-chat-4"
        ],
        globalVar: null,
        load: "eager",
        description: "通用智能体聊天页"
    },
    agentFlow: {
        paths: [
            "Weline_Agent::js/agent-flow.js?v=20261203-agent-flow-6"
        ],
        globalVar: null,
        load: "eager",
        description: "可视化角色流程编辑/运行页"
    }
});
