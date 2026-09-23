window.WelineWidgetAssets.register('sitesetupassistant-site-setup-assistant-float-0', function (widgetScript) {
(function () {
    var root = document.getElementById('ssa-float-root');
    if (!root) return;

    // Leave dashboard DOM entirely so flex/header layout cannot be disturbed.
    if (root.parentNode !== document.body) {
        document.body.appendChild(root);
    }

    var websiteId = root.getAttribute('data-ssa-website-id') || 'global';
    var remaining = parseInt(root.getAttribute('data-ssa-remaining') || '0', 10) || 0;
    var storageKey = 'weline.ssa.float.open.v2.' + websiteId;
    var panel = root.querySelector('[data-ssa-panel]');
    var toggle = root.querySelector('[data-ssa-toggle]');
    var closeBtn = root.querySelector('[data-ssa-close]');
    var stateEl = root.querySelector('[data-ssa-state]');

    if (remaining <= 0) {
        root.setAttribute('hidden', '');
        return;
    }
    root.removeAttribute('hidden');

    function readOpen() {
        try { return localStorage.getItem(storageKey) === '1'; } catch (e) { return false; }
    }
    function writeOpen(open) {
        try { localStorage.setItem(storageKey, open ? '1' : '0'); } catch (e) {}
    }
    function paintState(open) {
        if (stateEl) {
            stateEl.textContent = stateEl.textContent.replace(/open=\S*/, 'open=' + (open ? '1' : '0'));
        }
    }
    function setOpen(open) {
        if (!panel || !toggle) return;
        if (open) {
            panel.removeAttribute('hidden');
            toggle.setAttribute('aria-expanded', 'true');
        } else {
            panel.setAttribute('hidden', '');
            toggle.setAttribute('aria-expanded', 'false');
        }
        writeOpen(open);
        paintState(open);
    }

    setOpen(readOpen());

    if (toggle) {
        toggle.addEventListener('click', function () {
            setOpen(panel && panel.hasAttribute('hidden'));
        });
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', function () { setOpen(false); });
    }

    root.querySelectorAll('[data-ssa-scene]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            root.querySelectorAll('[data-ssa-scene]').forEach(function (b) { b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            var scene = btn.getAttribute('data-ssa-scene') || 'new';
            root.querySelectorAll('[data-scenes]').forEach(function (node) {
                var scenes = (node.getAttribute('data-scenes') || '').split(',');
                node.hidden = scenes.indexOf(scene) === -1;
            });
        });
    });
})();
});
