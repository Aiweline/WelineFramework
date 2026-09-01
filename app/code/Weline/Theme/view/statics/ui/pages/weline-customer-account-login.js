/* Weline UI source: js/account-login.js */
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
        const idleLabel = element.querySelector('[data-w-login-idle-label]');
        const busyLabel = element.querySelector('[data-w-login-busy-label]');
        const feedback = element.querySelector('[data-w-login-feedback]');
        const username = element.querySelector('#username');
        const password = element.querySelector('#password');
        let submitting = false;

        const setBusy = (busy) => {
            submitting = busy;
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = busy;
                submitButton.classList.toggle('w-auth-login__submit--busy', busy);
                // Foundation .w-button[aria-busy="true"]::after draws the only spinner.
                submitButton.setAttribute('aria-busy', String(busy));
            }
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

        const postLoginDocument = async () => {
            if (!(form instanceof HTMLFormElement)) {
                throw new Error('login_form_missing');
            }
            const action = form.getAttribute('action') || window.location.href;
            const formData = new FormData(form);
            if (!formData.get('username') && formData.get('email')) {
                formData.set('username', String(formData.get('email')));
            }
            const response = await fetch(action, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            });
            let raw = null;
            try {
                raw = await response.json();
            } catch (_error) {
                raw = null;
            }
            if (!response.ok && !raw) {
                throw new Error(`login_http_${response.status}`);
            }
            return raw;
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
            // Always take over submit when this component is mounted: classic POST races
            // lazy captcha and can double-prefix locale on failure redirects.
            event.preventDefault();
            if (submitting) return;
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
                // Document-level fetch (not QueryBin Worker): Set-Cookie must land in the
                // page jar. Worker fetch has left Cursor/embedded browsers logged out.
                const response = await postLoginDocument();
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

        const storefrontChallengeUrl = (route, intent, formId) => {
            const segments = window.location.pathname.split('/').filter(Boolean);
            const maybeLocale = segments[0] || '';
            const hasLocale = /^[a-z]{2}(_[A-Za-z0-9-]+)?$/i.test(maybeLocale);
            const prefix = hasLocale ? `/${maybeLocale}` : '';
            const clean = String(route || 'weline_captcha/frontend/challenge').replace(/^\/+/, '');
            const params = new URLSearchParams({
                intent: String(intent || 'customer.login'),
                form_id: String(formId || 'loginForm'),
                _: String(Date.now()),
            });
            return `${prefix}/${clean}?${params.toString()}`;
        };

        const ensureLoginCaptcha = async (force = false) => {
            if (!(form instanceof HTMLFormElement)) return;
            if (window.Weline?.Captcha?.ensure) {
                const host = form.querySelector('[data-weline-captcha-lazy]');
                const existing = form.querySelector('[data-weline-captcha-provider]');
                const target = host || existing || form;
                return window.Weline.Captcha.ensure(target, force);
            }
            const host = form.querySelector('[data-weline-captcha-lazy]');
            const existing = form.querySelector('[data-weline-captcha-provider]');
            if (!force && !host) return;
            if (!force && host && host.getAttribute('data-loaded') === '1') return;
            const anchor = host || existing?.closest('.weline-captcha') || existing;
            if (!(anchor instanceof HTMLElement)) return;
            const intent = anchor.getAttribute('data-intent')
                || form.getAttribute('data-weline-form-intent')
                || 'customer.login';
            const formId = anchor.getAttribute('data-form-id') || form.id || 'loginForm';
            const route = anchor.getAttribute('data-challenge-route') || 'weline_captcha/frontend/challenge';
            const response = await fetch(storefrontChallengeUrl(route, intent, formId), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error(`captcha_http_${response.status}`);
            const payload = await response.json();
            const html = String(payload?.html || payload?.data?.html || '');
            if (!html) throw new Error('captcha_empty');
            const wrap = document.createElement('div');
            wrap.innerHTML = html.trim();
            const node = wrap.firstElementChild;
            if (!(node instanceof HTMLElement)) throw new Error('captcha_markup');
            anchor.replaceWith(node);
            if (window.Weline?.Form?.mount) window.Weline.Form.mount(form);
        };

        if (form instanceof HTMLFormElement) {
            listen(form, 'click', (event) => {
                const target = event.target;
                if (!(target instanceof Element)) return;
                const button = target.closest('[data-weline-captcha-refresh]');
                if (!(button instanceof HTMLElement)) return;
                event.preventDefault();
                ensureLoginCaptcha(true).catch(() => {});
            });
            listen(form, 'weline:captcha:refresh-requested', () => {
                ensureLoginCaptcha(true).catch(() => {});
            });
            ensureLoginCaptcha(false).catch(() => {});
        }

        return { element };
    });

    // Mirror Theme page bundle: account-register lazy alias must be defined here too.
    UI.define('account-register', ({ element }) => ({ element }));
}
