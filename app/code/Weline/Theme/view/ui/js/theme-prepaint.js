(function applyWelineThemeBeforePaint() {
    const root = document.documentElement;
    // Frontend storefront defaults to light (Amazon white body). Backend keeps system.
    const area = root.dataset.wArea || 'frontend';
    const fallback = area === 'backend' ? 'system' : 'light';
    let preference = root.dataset.themePreference || fallback;
    try {
        preference = localStorage.getItem('weline_theme_preference') || preference;
    } catch (_error) {
    }
    if (!['system', 'light', 'dark'].includes(preference)) preference = fallback;
    // Storefront: coerce legacy system preference to light so OS-dark CSS cannot paint #020617.
    if (area !== 'backend' && preference === 'system') preference = 'light';
    const systemDark = typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-color-scheme: dark)').matches;
    // Storefront: only explicit dark preference enables dark; OS dark must not paint #020617 body.
    const theme = area === 'backend'
        ? (preference === 'dark' || (preference === 'system' && systemDark) ? 'dark' : 'light')
        : (preference === 'dark' ? 'dark' : 'light');
    root.dataset.themePreference = preference;
    root.dataset.theme = theme;
    root.style.colorScheme = theme;
})();
