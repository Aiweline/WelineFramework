/**
 * Weline_Server 后台 JS 模块登记。
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};

Object.assign(window.WelineModulesConfig.modules, {
    serverReverseProxy: {
        paths: [
            'Weline_Server::backend/reverse-proxy.js?v=20261021-reverse-proxy1'
        ],
        globalVar: 'WelineServerReverseProxy',
        description: '反向代理规则列表：应用配置、测试连接、启停与删除'
    },
    serverMonitor: {
        paths: [
            'Weline_Server::backend/server-monitor.js?v=20261021-monitor1'
        ],
        globalVar: 'WelineServerMonitor',
        description: '服务器监控页：30 秒自动刷新、进程明细、流量与压测、日志清理'
    }
});
