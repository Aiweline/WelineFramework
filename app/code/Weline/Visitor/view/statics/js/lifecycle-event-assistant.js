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
    var src = '/Weline/Visitor/view/statics/js/event-sandbox-monitor.js?v=20260911-event-sandbox-monitor9';
    if (node && node.src) {
        src = String(node.src).replace('lifecycle-event-assistant.js', 'event-sandbox-monitor.js');
    }
    var s = document.createElement('script');
    s.src = src;
    s.async = false;
    s.setAttribute('data-weline-event-sandbox-monitor-bundle', 'true');
    (document.head || document.documentElement).appendChild(s);
})(window, document);
