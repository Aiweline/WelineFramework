(function (window, document) {
    'use strict';
    if (window.WelineWidgetAssets) return;
    const initializers = new Map();
    const initialized = new WeakSet();
    function initialize(anchor) {
        const run = initializers.get(anchor.dataset.widgetScript);
        if (!run || initialized.has(anchor)) return;
        initialized.add(anchor);
        try { run(anchor); } catch (error) { initialized.delete(anchor); console.error(error); }
    }
    function scan(root) {
        if (root.nodeType !== 1 && root.nodeType !== 9) return;
        if (root.matches && root.matches('[data-widget-script]')) initialize(root);
        root.querySelectorAll('[data-widget-script]').forEach(initialize);
    }
    window.WelineWidgetAssets = {
        register: function (key, run) {
            initializers.set(key, run);
            if (document.readyState !== 'loading') {
                document.querySelectorAll('[data-widget-script]').forEach(function (anchor) {
                    if (anchor.dataset.widgetScript === key) initialize(anchor);
                });
            }
        }
    };
    function start() {
        scan(document);
        new MutationObserver(function (records) {
            records.forEach(function (record) { record.addedNodes.forEach(scan); });
        }).observe(document.documentElement, {childList: true, subtree: true});
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
    else start();
})(window, document);
