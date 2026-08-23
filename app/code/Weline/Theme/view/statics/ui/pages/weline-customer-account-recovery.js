/* Weline UI source: js/account-recovery.js */
function safeStorefrontDestination(target, fallback) {
    const defaultPath = String(fallback || '/customer/account/login');
    let destination = defaultPath;
    try {
        const parsed = new URL(String(target || defaultPath), window.location.origin);
        if (parsed.origin === window.location.origin) destination = `${parsed.pathname}${parsed.search}${parsed.hash}`;
    } catch (_error) {
    }
    if (!destination.startsWith('/')) destination = `/${destination}`;
    const segments = window.location.pathname.split('/').filter(Boolean);
    const customerIndex = segments.findIndex((segment) => segment.toLowerCase() === 'customer');
    const prefix = customerIndex > 0 ? `/${segments.slice(0, customerIndex).join('/')}` : '';
    return prefix && destination !== prefix && !destination.startsWith(`${prefix}/`)
        ? `${prefix}${destination}`
        : destination;
}

export function register(UI) {
    UI.define('account-recovery', ({ element, listen }) => {
        const form = element.querySelector('[data-w-recovery-form]');
        const submitButton = element.querySelector('[data-w-recovery-submit]');
        const spinner = element.querySelector('[data-w-recovery-spinner]');
        const feedback = element.querySelector('[data-w-recovery-feedback]');
        let redirectTimer = 0;
        let accountResource = null;

        const setBusy = (busy) => {
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = busy;
                submitButton.setAttribute('aria-busy', String(busy));
            }
            if (spinner instanceof HTMLElement) spinner.hidden = !busy;
        };
        const showFeedback = (message, tone) => {
            if (!(feedback instanceof HTMLElement)) return;
            feedback.textContent = String(message || '');
            feedback.dataset.tone = tone;
            feedback.hidden = false;
            feedback.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
        };
        const resource = async () => {
            if (accountResource) return accountResource;
            if (typeof window.Weline?.load === 'function') await window.Weline.load('api');
            if (typeof window.Weline?.Api?.resource !== 'function') throw new Error('Weline.Api is unavailable.');
            accountResource = await Promise.resolve(window.Weline.Api.resource('account'));
            return accountResource;
        };
        const validatePasswords = () => {
            const password = element.querySelector('#password');
            const confirmation = element.querySelector('#password_confirm');
            if (!(password instanceof HTMLInputElement) || !(confirmation instanceof HTMLInputElement)) return true;
            password.setCustomValidity(password.value.length < 8 ? element.dataset.passwordLengthMessage || '' : '');
            confirmation.setCustomValidity(password.value !== confirmation.value ? element.dataset.passwordMatchMessage || '' : '');
            return password.reportValidity() && confirmation.reportValidity();
        };
        const submit = async (event) => {
            event.preventDefault();
            if (!(form instanceof HTMLFormElement) || !validatePasswords() || !form.reportValidity()) return;
            setBusy(true);
            if (feedback instanceof HTMLElement) feedback.hidden = true;
            try {
                const payload = Object.fromEntries(new FormData(form).entries());
                delete payload.form_key;
                delete payload.redirect_url;
                const operation = payload.token ? 'resetPassword' : 'requestPasswordReset';
                const client = await resource();
                if (typeof client?.[operation] !== 'function') throw new Error(`Unknown account operation: ${operation}`);
                const result = await client[operation](payload, { silent: true });
                if (result?.success === false) {
                    showFeedback(result.message || element.dataset.failureMessage, 'danger');
                    setBusy(false);
                    return;
                }
                showFeedback(result?.message || element.dataset.successMessage, 'success');
                if (result?.redirect) {
                    redirectTimer = window.setTimeout(() => {
                        window.location.assign(safeStorefrontDestination(result.redirect, element.dataset.loginUrl));
                    }, 1500);
                } else {
                    setBusy(false);
                }
            } catch (error) {
                if (window.DEV) console.debug('Account recovery request failed.', error);
                showFeedback(element.dataset.requestFailureMessage, 'danger');
                setBusy(false);
            }
        };

        element.querySelectorAll('[data-w-password-toggle]').forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) return;
            listen(button, 'click', () => {
                const input = document.getElementById(button.dataset.wPasswordTarget || '');
                if (!(input instanceof HTMLInputElement) || !['password', 'text'].includes(input.type)) return;
                const reveal = input.type === 'password';
                input.type = reveal ? 'text' : 'password';
                button.setAttribute('aria-pressed', String(reveal));
                button.setAttribute('aria-label', reveal ? button.dataset.labelHide || '' : button.dataset.labelShow || '');
                const showIcon = button.querySelector('[data-w-password-show]');
                const hideIcon = button.querySelector('[data-w-password-hide]');
                if (showIcon instanceof HTMLElement) showIcon.hidden = reveal;
                if (hideIcon instanceof HTMLElement) hideIcon.hidden = !reveal;
            });
        });
        if (form instanceof HTMLFormElement) listen(form, 'submit', submit);

        return { element, destroy: () => window.clearTimeout(redirectTimer) };
    });
}
