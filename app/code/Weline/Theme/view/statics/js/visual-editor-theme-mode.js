/**
 * Visual-editor three-state colour-mode bridge.
 *
 * Kept separate from visual-editor.js because that historical asset contains
 * non-UTF-8 bytes and must not be rewritten by automated edits. It uses the
 * canonical backend runtime when available and otherwise applies the documented
 * attribute contract once for the backend editor shell.
 */
(function () {
    'use strict';

    function normalize(preference) {
        return preference === 'light' || preference === 'dark' || preference === 'system'
            ? preference
            : 'system';
    }

    function resolved(preference) {
        if (preference === 'dark') return 'dark';
        if (preference === 'system' && window.matchMedia) {
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        return 'light';
    }

    function bootstrap() {
        var control = document.getElementById('theme-mode-preference');
        if (!control) return;

        var root = document.documentElement;
        var iframe = document.getElementById('preview-iframe');
        var preference = normalize(root.getAttribute('data-theme-preference'));

        function notifyFailure(error) {
            var translations = window.ThemeEditorConfig && window.ThemeEditorConfig.translations
                ? window.ThemeEditorConfig.translations
                : {};
            var message = error && error.message ? error.message : (translations.themeModeSaveFailed || '');
            if (window.BackendToast && typeof window.BackendToast.error === 'function') {
                window.BackendToast.error(message);
            } else if (window.Weline && window.Weline.Toast && typeof window.Weline.Toast.error === 'function') {
                window.Weline.Toast.error(message);
            }
        }

        function apply(nextPreference) {
            preference = normalize(nextPreference);
            var current = resolved(preference);

            var runtime = window.__WelineBackendThemeRuntime;
            if (runtime && typeof runtime.apply === 'function') {
                var state = runtime.apply(preference);
                current = state.theme;
            } else {
                [root, document.body].filter(Boolean).forEach(function (target) {
                    target.setAttribute('data-theme-preference', preference);
                    target.setAttribute('data-theme', current);
                    target.setAttribute('data-bs-theme', current);
                    target.setAttribute('data-theme-mode', current);
                    target.setAttribute('data-layout-mode', current);
                    target.style.colorScheme = current;
                });
            }

            control.value = preference;
            if (iframe && iframe.contentWindow) {
                iframe.contentWindow.postMessage({
                    type: 'switchThemeColor',
                    themeColor: preference
                }, window.location.origin);
            }
        }

        preference = normalize(root.getAttribute('data-theme-preference'));
        apply(preference);

        control.addEventListener('change', async function () {
            var previous = preference;
            var next = normalize(control.value);
            apply(next);
            if (typeof window.w_query !== 'function') {
                apply(previous);
                notifyFailure(new Error((window.ThemeEditorConfig && window.ThemeEditorConfig.translations && window.ThemeEditorConfig.translations.themeModeSaveFailed) || ''));
                return;
            }
            try {
                var result = await window.w_query('theme', 'setBackendThemeMode', {mode: next}, {area: 'backend'});
                if (result && (result.success === false || result.code >= 400)) {
                    throw new Error(result.message || ((window.ThemeEditorConfig && window.ThemeEditorConfig.translations && window.ThemeEditorConfig.translations.themeModeSaveFailed) || ''));
                }
            } catch (error) {
                apply(previous);
                notifyFailure(error);
            }
        });

        if (iframe) {
            iframe.addEventListener('load', function () {
                apply(preference);
            });
        }

    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
    } else {
        bootstrap();
    }
})();
