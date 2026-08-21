(function (window, document) {
    'use strict';

    var Weline = window.Weline = window.Weline || {};
    var storageKey = 'weline_theme_preference';
    var supported = ['system', 'light', 'dark'];

    function normalize(value) {
        return supported.includes(String(value || '')) ? String(value) : 'system';
    }

    function storedPreference() {
        try {
            return normalize(window.localStorage.getItem(storageKey));
        } catch (_error) {
            return 'system';
        }
    }

    function apply(preference) {
        preference = normalize(preference);
        document.documentElement.dataset.themePreference = preference;
        document.dispatchEvent(new CustomEvent('weline:theme:preference', {
            detail: { preference: preference },
        }));
        return preference;
    }

    Weline.Theme = {
        isSupportedPreference: function (value) { return supported.includes(String(value || '')); },
        getPreference: function () { return normalize(document.documentElement.dataset.themePreference); },
        getCurrent: function () { return document.documentElement.dataset.theme || 'light'; },
        apply: apply,
        setPreference: function (preference) {
            preference = normalize(preference);
            try {
                window.localStorage.setItem(storageKey, preference);
            } catch (_error) {
            }
            return Promise.resolve(apply(preference));
        },
    };

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-w-theme-preference]');
        if (!(trigger instanceof HTMLButtonElement) || trigger.disabled) return;
        Weline.Theme.setPreference(trigger.dataset.wThemePreference).catch(function (error) {
            Weline.UI && Weline.UI.toast && Weline.UI.toast.error(error instanceof Error ? error.message : String(error));
        });
    });

    document.addEventListener('weline:theme:change', function (event) {
        var preference = event.detail && event.detail.preference || 'system';
        document.querySelectorAll('[data-w-theme-preference]').forEach(function (option) {
            option.dataset.state = option.dataset.wThemePreference === preference ? 'active' : '';
        });
    });

    apply(storedPreference());
})(window, document);
