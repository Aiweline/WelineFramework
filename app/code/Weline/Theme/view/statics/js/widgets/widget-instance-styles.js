(function (document) {
    'use strict';
    if (window.WelineWidgetInstanceStyles) return;
    window.WelineWidgetInstanceStyles = true;
    const values = new WeakMap();
    const rules = new WeakMap();
    let serial = 0;
    let sheet;
    function resolveSheet() {
        if (sheet) return sheet;
        const link = Array.from(document.querySelectorAll('link[rel="stylesheet"]')).find(function (item) {
            return item.href.indexOf('widget-instance-styles') !== -1;
        });
        const candidate = link && link.sheet;
        if (!candidate) return null;
        try {
            // Reading rules is forbidden when the static URL is served by a cross-origin CDN.
            void candidate.cssRules;
            sheet = candidate;
        } catch (error) {
            if (error.name !== 'SecurityError' || typeof CSSStyleSheet !== 'function' || !('adoptedStyleSheets' in document)) throw error;
            sheet = new CSSStyleSheet();
            document.adoptedStyleSheets = document.adoptedStyleSheets.concat(sheet);
        }
        return sheet;
    }
    function apply(element) {
        if (!resolveSheet()) return;
        const declarations = element.getAttribute('data-widget-style') || '';
        if (values.get(element) === declarations) return;
        let rule = rules.get(element);
        if (!rule) {
            const key = 'wi-' + (++serial);
            element.setAttribute('data-widget-style-id', key);
            // An ID-level selector preserves instance values over component class selectors.
            const index = sheet.cssRules.length;
            sheet.insertRule(':is(#weline-widget-instance-priority, [data-widget-style-id="' + key + '"]) {}', index);
            rule = sheet.cssRules[index];
            rules.set(element, rule);
        }
        // Replace, including removal, so old declarations cannot leak into a new instance configuration.
        rule.style.cssText = declarations;
        values.set(element, declarations);
    }
    function scan(root) {
        if (root.nodeType !== 1 && root.nodeType !== 9) return;
        if (root.matches && root.matches('[data-widget-style]')) apply(root);
        root.querySelectorAll('[data-widget-style]').forEach(apply);
    }
    function start() {
        scan(document);
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                if (record.type === 'attributes') apply(record.target);
                else record.addedNodes.forEach(scan);
            });
        }).observe(document.documentElement, {childList: true, subtree: true, attributes: true, attributeFilter: ['data-widget-style']});
        document.addEventListener('load', function (event) { if (event.target.tagName === 'LINK' && !sheet) scan(document); }, true);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
    else start();
})(document);
