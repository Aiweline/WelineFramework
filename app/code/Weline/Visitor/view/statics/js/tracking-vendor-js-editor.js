/**
 * 事件供应商 — 脚本 Tab 简易 JS 在线编辑器
 * 仅：高亮 + 格式化 + CodeMirror 右上角悬浮两暗色。
 */
(function () {
    'use strict';

    var THEMES = [
        { id: 'material-darker', label: '墨夜' },
        { id: 'dracula', label: '深渊' }
    ];
    var STORAGE_KEY = 'tvp.jsEditor.theme';
    var editors = [];

    function currentTheme() {
        var saved = '';
        try {
            saved = localStorage.getItem(STORAGE_KEY) || '';
        } catch (e) {}
        if (THEMES.some(function (t) { return t.id === saved; })) {
            return saved;
        }
        return THEMES[0].id;
    }

    function syncThemeButtons(theme) {
        document.querySelectorAll('[data-tv-js-theme]').forEach(function (btn) {
            var on = btn.getAttribute('data-tv-js-theme') === theme;
            btn.setAttribute('data-active', on ? '1' : '0');
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    function setTheme(theme) {
        if (!THEMES.some(function (t) { return t.id === theme; })) {
            theme = THEMES[0].id;
        }
        try {
            localStorage.setItem(STORAGE_KEY, theme);
        } catch (e) {}
        editors.forEach(function (cm) {
            cm.setOption('theme', theme);
        });
        syncThemeButtons(theme);
    }

    function formatJs(src) {
        if (window.js_beautify) {
            return window.js_beautify(src, {
                indent_size: 2,
                space_in_empty_paren: true,
                end_with_newline: true
            });
        }
        return src;
    }

    function pinFloatToEditor(wrap, cm) {
        if (!wrap || !cm) {
            return;
        }
        var floatEl = wrap.querySelector('[data-tv-js-theme-float]');
        var host = cm.getWrapperElement();
        if (!floatEl || !host) {
            return;
        }
        host.style.position = 'relative';
        host.appendChild(floatEl);
        floatEl.querySelectorAll('[data-tv-js-theme]').forEach(function (btn) {
            if (btn.getAttribute('data-bound') === '1') {
                return;
            }
            btn.setAttribute('data-bound', '1');
            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                setTheme(btn.getAttribute('data-tv-js-theme') || THEMES[0].id);
            });
        });
    }

    function mountOne(textarea, theme) {
        if (!window.CodeMirror || !textarea || textarea.getAttribute('data-tv-cm') === '1') {
            return null;
        }
        var wrap = textarea.closest('[data-tv-js-editor]');
        var cm = window.CodeMirror.fromTextArea(textarea, {
            mode: 'javascript',
            theme: theme,
            lineNumbers: true,
            lineWrapping: true,
            indentUnit: 2,
            tabSize: 2,
            matchBrackets: false,
            autofocus: false
        });
        textarea.setAttribute('data-tv-cm', '1');
        cm.setSize('100%', '12rem');
        editors.push(cm);
        pinFloatToEditor(wrap, cm);

        if (wrap) {
            var fmt = wrap.querySelector('[data-tv-js-format]');
            if (fmt) {
                fmt.addEventListener('click', function () {
                    var formatted = formatJs(cm.getValue());
                    cm.setValue(formatted);
                    cm.save();
                });
            }
        }

        cm.on('change', function () {
            cm.save();
        });

        return cm;
    }

    function refreshVisible() {
        editors.forEach(function (cm) {
            try {
                cm.refresh();
            } catch (e) {}
        });
    }

    function boot() {
        if (!window.CodeMirror) {
            return;
        }
        var theme = currentTheme();
        document.querySelectorAll('textarea[data-tv-js-source]').forEach(function (ta) {
            mountOne(ta, theme);
        });
        syncThemeButtons(theme);

        var tabs = document.getElementById('tv-tabs');
        if (tabs) {
            tabs.querySelectorAll('[data-tab]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (btn.getAttribute('data-tab') === 'js') {
                        window.setTimeout(refreshVisible, 30);
                    }
                });
            });
        }

        var form = document.getElementById('tv-save-form');
        if (form) {
            form.addEventListener('submit', function () {
                editors.forEach(function (cm) {
                    cm.save();
                });
            });
        }

        window.__tvFlushJsEditors = function () {
            editors.forEach(function (cm) {
                try { cm.save(); } catch (e) {}
            });
        };

        editors.forEach(function (cm) {
            cm.on('change', function () {
                try {
                    document.dispatchEvent(new CustomEvent('tv:js-dirty'));
                } catch (e) {}
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
