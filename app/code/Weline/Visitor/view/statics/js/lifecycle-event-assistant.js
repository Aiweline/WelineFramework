/**
 * Thin re-export: lifecycle assistant entry now loads Event Sandbox Monitor.
 * Kept one cycle for cached bootstrap URLs.
 */
(function (global, document) {
    'use strict';
    if (global.WelineEventSandboxMonitor) {
        global.WelineLifecycleAssistant = global.WelineLifecycleAssistant || {
            enable: function () { return global.WelineEventSandboxMonitor.enable(); },
            disable: function () { return global.WelineEventSandboxMonitor.disable(); },
            isEnabled: function () { return global.WelineEventSandboxMonitor.isEnabled(); },
            getConfigRevision: function () {
                return typeof global.WelineEventSandboxMonitor.getConfigRevision === 'function'
                    ? global.WelineEventSandboxMonitor.getConfigRevision()
                    : 0;
            }
        };
        return;
    }
    if (document.querySelector('script[data-weline-event-sandbox-monitor-bundle="true"]')) {
        return;
    }
    var node = document.currentScript;
    var src = (function(d){try{var c=JSON.parse((document.getElementById('weline-frontend-runtime-config')||document.getElementById('weline-backend-runtime-config')||{}).textContent||'{}');if(c&&c.env&&c.env.useProductionPublicUrls){var m=String(d).match(/^\/([^/]+)\/([^/]+)\/view\/statics\/(.*)$/);if(m)return'/static/'+m[1]+'/'+m[2]+'/'+m[3];}}catch(e){}return d;})('/Weline/Visitor/view/statics/js/event-sandbox-monitor.js') + '?v=20260922-ga4-debug1';
    if (node && node.src) {
        src = String(node.src).replace('lifecycle-event-assistant.js', 'event-sandbox-monitor.js');
    }
    var s = document.createElement('script');
    s.src = src;
    s.async = false;
    s.setAttribute('data-weline-event-sandbox-monitor-bundle', 'true');
    (document.head || document.documentElement).appendChild(s);
})(window, document);
