/**
 * Weline Location 前台 JS 模块登记
 * paths 相对 view/statics/（勿再写 statics/ 前缀，否则 DEV 解析成双 statics）。
 */
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    location: {
        paths: [
            "Weline_Location::frontend/js/location.js"
        ],
        globalVar: "WelineLocation",
        description: "Location定位模块（浏览器定位和IP定位）"
    }
});

Object.assign(window.WelineModulesConfig.moduleAliases, {
    geolocation: "location"
});
