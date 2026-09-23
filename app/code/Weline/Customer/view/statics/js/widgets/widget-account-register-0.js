window.WelineWidgetAssets.register('customer-account-register-0', function (widgetScript) {
(function () {
    'use strict';

    function resolveStorefrontRedirect(target, fallback) {
        var destination = String(target || fallback || '').trim();
        try {
            var parsed = new URL(destination, window.location.origin);
            if (parsed.origin !== window.location.origin) {
                destination = String(fallback || '');
            } else {
                destination = parsed.pathname + parsed.search + parsed.hash;
            }
        } catch (error) {
            destination = String(fallback || '');
        }
        if (destination.charAt(0) !== '/') destination = '/' + destination;
        var segments = window.location.pathname.split('/').filter(Boolean);
        var customerIndex = segments.findIndex(function (segment) {
            return segment.toLowerCase() === 'customer';
        });
        var prefix = customerIndex > 0 ? '/' + segments.slice(0, customerIndex).join('/') : '';
        if (prefix && destination !== prefix && destination.indexOf(prefix + '/') !== 0) {
            destination = prefix + destination;
        }
        return destination;
    }

    var accountApiPromise = null;
    function asPromise(value) {
        return (value && typeof value.then === 'function') ? value : Promise.resolve(value);
    }
    function getAccountApi() {
        if (!accountApiPromise) {
            if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
                // Full Api.resource() is sync Proxy; stub resource() may return a Promise.
                accountApiPromise = asPromise(window.Weline.Api.resource('account'));
            } else if (window.Weline && typeof window.Weline.load === 'function') {
                accountApiPromise = asPromise(window.Weline.load('api')).then(function () {
                    if (!window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                        throw new Error('Weline.Api is unavailable.');
                    }
                    return window.Weline.Api.resource('account');
                });
            } else {
                accountApiPromise = Promise.reject(new Error('Weline.Api is unavailable.'));
            }
        }
        return asPromise(accountApiPromise);
    }

    function canUseAccountApi() {
        return Boolean(
            window.Weline && (
                (window.Weline.Api && typeof window.Weline.Api.resource === 'function')
                || typeof window.Weline.load === 'function'
            )
        );
    }

    function formDataToObject(formData) {
        var payload = {};
        formData.forEach(function (value, key) {
            payload[key] = value;
        });
        delete payload.form_key;
        payload.agree_terms = Object.prototype.hasOwnProperty.call(payload, 'agree_terms')
            && ['1', 'true', 'yes', 'on'].indexOf(String(payload.agree_terms).toLowerCase()) !== -1;
        return payload;
    }

    function showRegisterError(clientError, message, focusEl) {
        clientError.textContent = message;
        clientError.hidden = false;
        clientError.classList.add('auth-form__error--shake');
        if (focusEl && typeof focusEl.focus === 'function') {
            focusEl.focus();
        }
        setTimeout(function () {
            clientError.classList.remove('auth-form__error--shake');
        }, 500);
    }

    function setRegisterLoading(form, loading) {
        var submitButton = form.querySelector('[data-w-register-submit]');
        if (submitButton) {
            submitButton.disabled = loading;
            submitButton.setAttribute('aria-busy', loading ? 'true' : 'false');
        }
    }

    function submitNative(form) {
        HTMLFormElement.prototype.submit.call(form);
    }

    function bindPasswordToggle(root) {
        root.querySelectorAll('[data-w-password-toggle][data-w-password-target]').forEach(function (btn) {
            var targetId = btn.getAttribute('data-w-password-target');
            var pwdInput = targetId ? document.getElementById(targetId) : null;
            if (!pwdInput) return;
            var showLabel = btn.getAttribute('data-label-show') || '';
            var hideLabel = btn.getAttribute('data-label-hide') || '';
            var showEl = btn.querySelector('[data-w-password-show]');
            var hideEl = btn.querySelector('[data-w-password-hide]');
            btn.addEventListener('click', function () {
                var wasPassword = pwdInput.type === 'password';
                pwdInput.type = wasPassword ? 'text' : 'password';
                var nowPlain = pwdInput.type === 'text';
                btn.setAttribute('aria-pressed', nowPlain ? 'true' : 'false');
                btn.setAttribute('aria-label', nowPlain ? hideLabel : showLabel);
                if (showEl && hideEl) {
                    if (nowPlain) {
                        showEl.setAttribute('hidden', 'hidden');
                        hideEl.removeAttribute('hidden');
                    } else {
                        showEl.removeAttribute('hidden');
                        hideEl.setAttribute('hidden', 'hidden');
                    }
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('[data-customer-register-form]');
        var passwordEl = document.getElementById('password');
        var confirmPasswordEl = document.getElementById('confirm_password');
        var clientError = document.getElementById('registerClientError');
        var root = document.querySelector('[data-w-component="account-register"]');
        if (!form || !passwordEl || !confirmPasswordEl || !clientError) {
            return;
        }
        if (root) {
            bindPasswordToggle(root);
        }

        form.addEventListener('submit', function (e) {
            var password = passwordEl.value;
            var confirmPassword = confirmPasswordEl.value;
            clientError.hidden = true;
            clientError.classList.remove('auth-form__error--shake');
            clientError.textContent = '';

            if (password !== confirmPassword) {
                e.preventDefault();
                showRegisterError(clientError, JSON.parse(widgetScript.dataset.v0 || 'null'), confirmPasswordEl);
                return;
            }
            if (password.length < 8) {
                e.preventDefault();
                showRegisterError(clientError, JSON.parse(widgetScript.dataset.v1 || 'null'), passwordEl);
                return;
            }
            if (!canUseAccountApi()) {
                return;
            }
            e.preventDefault();
            setRegisterLoading(form, true);
            getAccountApi()
                .then(function (AccountApi) {
                    return AccountApi.register(formDataToObject(new FormData(form)), {silent: true});
                })
                .then(function (data) {
                    if (data && data.success !== false) {
                        window.location.assign(resolveStorefrontRedirect(
                            data.redirect,
                            form.getAttribute('data-account-home-url') || ''
                        ));
                        return;
                    }
                    setRegisterLoading(form, false);
                    showRegisterError(clientError, (data && data.message) || JSON.parse(widgetScript.dataset.v2 || 'null'), null);
                })
                .catch(function (error) {
                    if (error && error.message === 'Weline.Api is unavailable.') {
                        submitNative(form);
                        return;
                    }
                    setRegisterLoading(form, false);
                    showRegisterError(clientError, (error && error.message) || JSON.parse(widgetScript.dataset.v3 || 'null'), null);
                });
        });
    });
})();
});
