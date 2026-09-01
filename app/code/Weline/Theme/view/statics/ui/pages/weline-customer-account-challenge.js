/* Weline UI source: js/account-challenge.js — document POST (not QueryBin Worker). */
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

function normalizeChallengePayload(raw) {
    if (raw == null) return null;
    if (typeof raw === 'object' && raw !== null && !Array.isArray(raw)) {
        if (
            typeof raw.success !== 'undefined'
            || typeof raw.redirect !== 'undefined'
            || raw.status === 'authenticated'
        ) {
            return raw;
        }
        if (raw.data && typeof raw.data === 'object') return normalizeChallengePayload(raw.data);
    }
    if (typeof raw === 'string') {
        const trimmed = raw.trim();
        if (trimmed.charAt(0) === '{' || trimmed.charAt(0) === '[') {
            try {
                return normalizeChallengePayload(JSON.parse(trimmed));
            } catch (_error) {
                return null;
            }
        }
    }
    return null;
}

function isChallengeSuccessPayload(payload) {
    if (!payload || typeof payload !== 'object') return false;
    if (payload.success === true || payload.success === 'true') return true;
    return payload.status === 'authenticated';
}

function sanitizeDigits(value) {
    return String(value || '').replace(/\D/g, '').slice(0, 8);
}

function formatChallengeCode(digits) {
    if (digits.length === 8) {
        return `${digits.slice(0, 4)}-${digits.slice(4)}`;
    }
    return digits;
}

function readChallengeTokenFromLocation() {
    try {
        return String(new URLSearchParams(window.location.search).get('challenge_token') || '').trim();
    } catch (_error) {
        return '';
    }
}

export function register(UI) {
    UI.define('account-challenge', ({ element, listen }) => {
        const form = element.querySelector('[data-w-challenge-form]');
        const submitButton = element.querySelector('[data-w-challenge-submit]');
        const idleLabel = element.querySelector('[data-w-challenge-idle-label]');
        const busyLabel = element.querySelector('[data-w-challenge-busy-label]');
        const feedback = element.querySelector('[data-w-challenge-feedback]');
        const messages = element.querySelector('[data-w-challenge-messages]');
        const codeInput = element.querySelector('[data-w-challenge-code]');
        const tokenInput = element.querySelector('[data-w-challenge-token]');
        let submitting = false;

        const resolveChallengeToken = () => {
            const fromInput = tokenInput instanceof HTMLInputElement
                ? String(tokenInput.value || '').trim()
                : '';
            if (fromInput) return fromInput;
            const fromDataset = String(element.dataset.challengeToken || '').trim();
            if (fromDataset) return fromDataset;
            return readChallengeTokenFromLocation();
        };

        const syncChallengeToken = () => {
            const token = resolveChallengeToken();
            if (tokenInput instanceof HTMLInputElement && token) {
                tokenInput.value = token;
            }
            if (token) {
                element.dataset.challengeToken = token;
            }
            return token;
        };

        const setBusy = (busy) => {
            submitting = busy;
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = busy;
                submitButton.classList.toggle('w-auth-login__submit--busy', busy);
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
            if (feedback instanceof HTMLElement) {
                feedback.hidden = true;
                feedback.textContent = '';
            }
            // Flash alerts from MessageManager stay visible after typing; remove them too.
            if (messages instanceof HTMLElement) {
                messages.querySelectorAll('.w-alert:not([data-w-challenge-feedback])').forEach((node) => {
                    node.remove();
                });
            }
        };

        const shake = () => {
            element.classList.add('w-auth-login--shake');
            window.setTimeout(() => element.classList.remove('w-auth-login--shake'), 450);
        };

        const postChallengeDocument = async (digits, token) => {
            if (!(form instanceof HTMLFormElement)) {
                throw new Error('challenge_form_missing');
            }
            const action = form.getAttribute('action') || window.location.href;
            const formData = new FormData(form);
            formData.set('challenge_token', token);
            formData.set('code', digits);
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
                throw new Error(`challenge_http_${response.status}`);
            }
            return raw;
        };

        const handleError = (message) => {
            showFeedback(message, 'danger');
            setBusy(false);
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.dataset.tone = 'primary';
            }
            shake();
            if (codeInput instanceof HTMLInputElement) codeInput.focus();
        };

        syncChallengeToken();

        if (codeInput instanceof HTMLInputElement) {
            listen(codeInput, 'input', () => {
                clearFeedback();
                const digits = sanitizeDigits(codeInput.value);
                codeInput.value = formatChallengeCode(digits);
            });
            window.setTimeout(() => codeInput.focus(), 0);
        }

        const submit = async (event) => {
            event.preventDefault();
            if (submitting) return;
            clearFeedback();

            if (!(codeInput instanceof HTMLInputElement)) return;
            const digits = sanitizeDigits(codeInput.value);
            const token = syncChallengeToken();
            if (!token) {
                handleError(element.dataset.tokenRequiredMessage || '');
                return;
            }
            if (digits.length !== 6 && digits.length !== 8) {
                handleError(element.dataset.codeRequiredMessage || '');
                return;
            }
            codeInput.value = formatChallengeCode(digits);
            setBusy(true);

            try {
                // Document-level fetch (not QueryBin Worker): challenge token lives in
                // the page session jar. Worker fetch cannot see that session.
                const response = await postChallengeDocument(digits, token);
                const payload = normalizeChallengePayload(response);
                const ok = response && response.success !== false;

                if (ok && isChallengeSuccessPayload(payload)) {
                    if (submitButton instanceof HTMLButtonElement) {
                        submitButton.dataset.tone = 'success';
                    }
                    setBusy(false);
                    window.setTimeout(() => {
                        window.location.assign(
                            safeStorefrontDestination(payload.redirect, '/customer/account')
                        );
                    }, 400);
                    return;
                }

                handleError(
                    (payload && payload.message)
                    || element.dataset.verifyFailureMessage
                    || ''
                );
            } catch (error) {
                handleError(
                    (error && error.message)
                    || element.dataset.requestFailureMessage
                    || ''
                );
            }
        };

        if (form instanceof HTMLFormElement) listen(form, 'submit', submit);
    });
}
