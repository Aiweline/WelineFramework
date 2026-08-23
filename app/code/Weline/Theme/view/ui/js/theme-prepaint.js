(function applyWelineThemeBeforePaint() {
    const root = document.documentElement;
    let preference = root.dataset.themePreference || 'system';
    try {
        preference = localStorage.getItem('weline_theme_preference') || preference;
    } catch (_error) {
    }
    if (!['system', 'light', 'dark'].includes(preference)) preference = 'system';
    const systemDark = typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const theme = preference === 'dark' || (preference === 'system' && systemDark) ? 'dark' : 'light';
    root.dataset.themePreference = preference;
    root.dataset.theme = theme;
    root.style.colorScheme = theme;
})();
