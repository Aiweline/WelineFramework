function refreshCaptcha(image) {
    if (!(image instanceof HTMLImageElement)) return;
    const base = image.dataset.baseSrc || image.currentSrc || image.src.split('?')[0];
    image.src = `${base}${base.includes('?') ? '&' : '?'}t=${Date.now()}`;
}

document.querySelectorAll('[data-w-login-logo]').forEach((image) => {
    image.addEventListener('error', () => {
        if (image.dataset.fallbackApplied === 'true' || !image.dataset.fallbackSrc) return;
        image.dataset.fallbackApplied = 'true';
        image.src = image.dataset.fallbackSrc;
    });
});

document.querySelectorAll('[data-w-captcha-refresh]').forEach((button) => {
    const image = button.querySelector('[data-w-captcha-image]');
    button.addEventListener('click', () => refreshCaptcha(image));
    image?.addEventListener('error', () => {
        if (image.dataset.retry === 'true') return;
        image.dataset.retry = 'true';
        refreshCaptcha(image);
    });
});

const form = document.querySelector('[data-w-login-form]');
if (form instanceof HTMLFormElement) {
    const reset = () => {
        const button = form.querySelector('[data-w-login-submit]');
        if (!(button instanceof HTMLButtonElement) || button.getAttribute('aria-disabled') === 'true') return;
        form.dataset.submitting = 'false';
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.querySelector('[data-w-login-label]')?.removeAttribute('hidden');
        button.querySelector('[data-w-login-progress]')?.setAttribute('hidden', '');
    };
    form.addEventListener('submit', (event) => {
        if (!form.reportValidity() || form.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }
        form.dataset.submitting = 'true';
        const button = form.querySelector('[data-w-login-submit]');
        if (!(button instanceof HTMLButtonElement)) return;
        button.setAttribute('aria-busy', 'true');
        button.querySelector('[data-w-login-label]')?.setAttribute('hidden', '');
        button.querySelector('[data-w-login-progress]')?.removeAttribute('hidden');
        requestAnimationFrame(() => { button.disabled = true; });
    });
    window.addEventListener('pageshow', reset);
}
