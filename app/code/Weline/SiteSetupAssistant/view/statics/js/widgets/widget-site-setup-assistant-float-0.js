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
    // v3: first visit defaults closed (v2 defaulted open and felt like a takeover).
    var storageKey = 'weline.ssa.float.open.v3.' + websiteId;
    var panel = root.querySelector('[data-ssa-panel]');
    var scrim = root.querySelector('[data-ssa-scrim]');
    var toggle = root.querySelector('[data-ssa-toggle]');
    var closeBtns = root.querySelectorAll('[data-ssa-close]');
    var stateEl = root.querySelector('[data-ssa-state]');
    var fabLabel = root.querySelector('[data-ssa-fab-label]');
    var fabBadge = root.querySelector('[data-ssa-fab-badge]');
    var labelOpen = (toggle && toggle.getAttribute('data-label-open')) || '建站助手';
    var labelClose = (toggle && toggle.getAttribute('data-label-close')) || '收起';

    if (remaining <= 0) {
        root.setAttribute('hidden', '');
        return;
    }
    root.removeAttribute('hidden');

    function readOpen() {
        try {
            var stored = localStorage.getItem(storageKey);
            if (stored === null) return false;
            return stored === '1';
        } catch (e) {
            return false;
        }
    }
    function writeOpen(open) {
        try { localStorage.setItem(storageKey, open ? '1' : '0'); } catch (e) {}
    }
    function paintState(open) {
        if (stateEl) {
            stateEl.textContent = stateEl.textContent.replace(/open=\S*/, 'open=' + (open ? '1' : '0'));
        }
    }
    function paintFab(open) {
        if (fabLabel) {
            fabLabel.textContent = open ? labelClose : labelOpen;
        }
        if (fabBadge) {
            if (open) {
                fabBadge.setAttribute('hidden', '');
            } else {
                fabBadge.removeAttribute('hidden');
            }
        }
        if (toggle) {
            toggle.setAttribute('aria-label', open ? labelClose : labelOpen);
        }
        root.classList.toggle('is-open', !!open);
    }
    function setOpen(open) {
        if (!panel || !toggle) return;
        if (open) {
            panel.removeAttribute('hidden');
            if (scrim) scrim.removeAttribute('hidden');
            toggle.setAttribute('aria-expanded', 'true');
        } else {
            panel.setAttribute('hidden', '');
            if (scrim) scrim.setAttribute('hidden', '');
            toggle.setAttribute('aria-expanded', 'false');
        }
        paintFab(open);
        writeOpen(open);
        paintState(open);
    }

    setOpen(readOpen());

    if (toggle) {
        toggle.addEventListener('click', function () {
            setOpen(panel && panel.hasAttribute('hidden'));
        });
    }
    closeBtns.forEach(function (btn) {
        btn.addEventListener('click', function () { setOpen(false); });
    });

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
