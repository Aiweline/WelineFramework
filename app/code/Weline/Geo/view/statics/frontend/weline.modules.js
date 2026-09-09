/**
 * Weline Geo 前台 JS 模块登记
 * 供 Weline.declare('geo') / Weline.load('geo')；勿回退到不存在的 weline-api-geo.js。
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    geo: {
        paths: [
            "Weline_Geo::frontend/js/geo.js"
        ],
        globalVar: "WelineGeo",
        description: "Geo定位模块（浏览器定位和IP定位）"
    }
});
