function safeStorefrontDestination(target, fallback) {
    const defaultPath = String(fallback || '/customer/account');
    let destination = defaultPath;
    try {
        const parsed = new URL(String(target || defaultPath), window.location.origin);
        if (parsed.origin === window.location.origin) {
            destination = `${parsed.pathname}${parsed.search}${parsed.hash}`;
        } else {
            destination = defaultPath;
        }
    } catch (_error) {
        destination = defaultPath;
    }
    if (!destination.startsWith('/')) destination = `/${destination}`;
    const segments = window.location.pathname.split('/').filter(Boolean);
    const customerIndex = segments.findIndex((segment) => segment.toLowerCase() === 'customer');
    const prefix = customerIndex > 0 ? `/${segments.slice(0, customerIndex).join('/')}` : '';
    return prefix && destination !== prefix && !destination.startsWith(`${prefix}/`)
        ? `${prefix}${destination}`
        : destination;
}

function normalizeLoginPayload(raw) {
    if (raw == null) return null;
    if (typeof raw === 'object' && raw !== null && !Array.isArray(raw)) {
        if (
            typeof raw.success !== 'undefined'
            || typeof raw.redirect !== 'undefined'
            || raw.status === 'authenticated'
            || raw.status === 'challenge_required'
        ) {
            return raw;
        }
        if (raw.data && typeof raw.data === 'object') return normalizeLoginPayload(raw.data);
    }
    if (typeof raw === 'string') {
        const trimmed = raw.trim();
        if (trimmed.charAt(0) === '{' || trimmed.charAt(0) === '[') {
            try {
                return normalizeLoginPayload(JSON.parse(trimmed));
            } catch (_error) {
                return null;
            }
        }
    }
    return null;
}

function isLoginSuccessPayload(payload) {
    if (!payload || typeof payload !== 'object') return false;
    if (payload.success === true || payload.success === 'true') return true;
    return payload.status === 'authenticated';
}

export function register(UI) {
    UI.define('account-login', ({ element, listen }) => {
        const form = element.querySelector('[data-w-login-form]');
        const submitButton = element.querySelector('[data-w-login-submit]');
        const spinner = element.querySelector('[data-w-login-spinner]');
        const idleLabel = element.querySelector('[data-w-login-idle-label]');
        const busyLabel = element.querySelector('[data-w-login-busy-label]');
        const feedback = element.querySelector('[data-w-login-feedback]');
        const username = element.querySelector('#username');
        const password = element.querySelector('#password');
        let accountResource = null;

        const setBusy = (busy) => {
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = busy;
                submitButton.classList.toggle('w-auth-login__submit--busy', busy);
                submitButton.setAttribute('aria-busy', String(busy));
            }
            if (spinner instanceof HTMLElement) spinner.hidden = !busy;
            if (idleLabel instanceof HTMLElement) idleLabel.hidden = busy;
            if (busyLabel instanceof HTMLElement) busyLabel.hidden = !busy;
        };

        const showFeedback = (message, tone) => {
            if (!(feedback instanceof HTMLElement)) return;
            feedback.textContent = String(message || '');
            feedback.dataset.tone = tone;
            feedback.hidden = false;
            feedback.scrollIntoView({
                behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                block: 'nearest',
            });
        };

        const clearFeedback = () => {
            if (feedback instanceof HTMLElement) feedback.hidden = true;
        };

        const shake = () => {
            element.classList.add('w-auth-login--shake');
            window.setTimeout(() => element.classList.remove('w-auth-login--shake'), 450);
        };

        const resource = async () => {
            if (accountResource) return accountResource;
            if (typeof window.Weline?.load === 'function') await window.Weline.load('api');
            if (typeof window.Weline?.Api?.resource !== 'function') {
                throw new Error('Weline.Api is unavailable.');
            }
            accountResource = await Promise.resolve(window.Weline.Api.resource('account'));
            return accountResource;
        };

        const canUseAccountApi = () => !!(
            window.Weline
            && (
                (window.Weline.Api && typeof window.Weline.Api.resource === 'function')
                || typeof window.Weline.load === 'function'
            )
        );

        const formDataToObject = (formData) => {
            const payload = Object.fromEntries(formData.entries());
            delete payload.form_key;
            if (Object.prototype.hasOwnProperty.call(payload, 'remember_duration')) {
                payload.remember_duration = Number(payload.remember_duration || 0);
            }
            return payload;
        };

        const handleLoginError = (message) => {
            showFeedback(message, 'danger');
            setBusy(false);
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.dataset.tone = 'primary';
            }
            shake();
        };

        const submit = async (event) => {
            if (!canUseAccountApi()) {
                if (
                    username instanceof HTMLInputElement
                    && password instanceof HTMLInputElement
                    && username.value.trim()
                    && password.value
                ) {
                    setBusy(true);
                }
                return;
            }

            event.preventDefault();
            clearFeedback();

            if (!(username instanceof HTMLInputElement) || !(password instanceof HTMLInputElement)) return;

            const userVal = username.value.trim();
            if (!userVal) {
                showFeedback(element.dataset.usernameRequiredMessage || '', 'danger');
                username.focus();
                return;
            }
            if (!password.value) {
                showFeedback(element.dataset.passwordRequiredMessage || '', 'danger');
                password.focus();
                return;
            }

            setBusy(true);

            try {
                const client = await resource();
                const body = formDataToObject(new FormData(form));
                const response = await client.login(body, { silent: true });
                const payload = normalizeLoginPayload(response);
                const ok = response && response.success !== false;

                if (ok && payload && payload.status === 'challenge_required' && payload.redirect) {
                    window.location.assign(safeStorefrontDestination(payload.redirect, '/customer/account/login'));
                    return;
                }

                if (ok && isLoginSuccessPayload(payload)) {
                    if (submitButton instanceof HTMLButtonElement) {
                        submitButton.dataset.tone = 'success';
                    }
                    setBusy(false);
                    window.setTimeout(() => {
                        window.location.assign(safeStorefrontDestination(payload.redirect, '/customer/account'));
                    }, 800);
                    return;
                }

                handleLoginError(
                    (payload && payload.message)
                    || element.dataset.loginFailureMessage
                    || ''
                );
            } catch (error) {
                const errData = error && error.response ? error.response.data : null;
                const parsedErr = normalizeLoginPayload(errData);
                const errorMsg = (parsedErr && parsedErr.message)
                    || (errData && typeof errData === 'object' && (errData.message || errData.msg))
                    || (error && error.message)
                    || element.dataset.requestFailureMessage
                    || '';
                handleLoginError(errorMsg);
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

        if (username instanceof HTMLInputElement) {
            listen(username, 'keydown', (event) => {
                if (event.key === 'Enter' && password instanceof HTMLInputElement) {
                    event.preventDefault();
                    password.focus();
                }
            });
        }

        if (password instanceof HTMLInputElement && submitButton instanceof HTMLButtonElement) {
            listen(password, 'keydown', (event) => {
                if (event.key === 'Enter' && !submitButton.disabled) {
                    form?.requestSubmit();
                }
            });
        }

        if (form instanceof HTMLFormElement) listen(form, 'submit', submit);

        return { element };
    });
}
