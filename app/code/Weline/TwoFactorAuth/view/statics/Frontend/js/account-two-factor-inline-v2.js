(function () {
    var twoFactorApiPromise = null;

    function findPanel(form) {
        return form.closest('[data-twofa-panel]');
    }

    function setMessage(panel, message, type) {
        var box = panel.querySelector('[data-twofa-message]');
        if (!box) {
            return;
        }
        box.textContent = message || '';
        box.hidden = !message;
        box.classList.remove('twofa-message--success', 'twofa-message--error');
        if (message) {
            box.classList.add(type === 'success' ? 'twofa-message--success' : 'twofa-message--error');
        }
    }

    function showToast(message, tone) {
        var text = String(message || '').trim();
        if (!text) {
            return;
        }

        var toast = window.Weline && window.Weline.UI && window.Weline.UI.toast;
        if (!toast) {
            return;
        }

        if (tone === 'success' && typeof toast.success === 'function') {
            toast.success(text);
            return;
        }
        if (tone === 'error' && typeof toast.error === 'function') {
            toast.error(text);
            return;
        }
        if (typeof toast.show === 'function') {
            toast.show(text, { tone: tone || 'info' });
        }
    }

    function lockForm(form, locked) {
        Array.prototype.forEach.call(form.querySelectorAll('button, input'), function (item) {
            item.disabled = locked;
        });
    }

    function normalizeCode(form) {
        var input = form.querySelector('input[name="code"]');
        if (!input) {
            return '';
        }
        input.value = input.value.replace(/\D/g, '').slice(0, 6);
        return input.value;
    }

    function buildPayload(form) {
        var payload = {};
        Array.prototype.forEach.call(form.elements, function (element) {
            if (!element.name) {
                return;
            }
            payload[element.name] = element.value;
        });
        return payload;
    }

    function getTwoFactorApi() {
        if (!twoFactorApiPromise) {
            twoFactorApiPromise = (window.Weline && window.Weline.load
                ? window.Weline.load('api')
                : Promise.resolve(window.Weline && window.Weline.Api)
            ).then(function () {
                return window.Weline.Api.resource('twoFactor');
            });
        }

        return twoFactorApiPromise;
    }

    function operationForAction(action) {
        if (action === 'regenerate') {
            return 'regenerateBackupCodes';
        }

        return action;
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function renderBackupCodes(panel, codes) {
        var result = panel.querySelector('[data-twofa-backup-result]');
        if (!result || !Array.isArray(codes)) {
            return;
        }

        var html = '<h3>New backup codes</h3><div class="twofa-backup-result__grid">';
        codes.forEach(function (code) {
            html += '<span>' + escapeHtml(code) + '</span>';
        });
        html += '</div>';
        result.innerHTML = html;
        result.hidden = false;
    }

    function refreshAccountTwoFaView() {
        var path = String(window.location.pathname || '');
        var onAccount = /\/customer\/account(?:\/index)?\/?$/.test(path);
        if (onAccount) {
            if (window.location.hash !== '#twofa') {
                if (window.history && typeof window.history.replaceState === 'function') {
                    window.history.replaceState(null, '', path + (window.location.search || '') + '#twofa');
                } else {
                    window.location.hash = 'twofa';
                }
            }
            window.dispatchEvent(new CustomEvent('weline:account-sidebar-section-reload', {
                detail: { section: 'twofa' }
            }));
            return;
        }
        window.location.assign('/customer/account/index#twofa');
    }

    async function submitTwoFactorForm(event, form) {
        event.preventDefault();
        if (typeof event.stopPropagation === 'function') {
            event.stopPropagation();
        }

        var panel = findPanel(form);
        var code = normalizeCode(form);
        var action = form.getAttribute('data-twofa-action');
        var operation = operationForAction(action);

        if (!panel || !operation) {
            return;
        }

        if (code.length !== 6) {
            var invalidMessage = 'Please enter a 6-digit verification code.';
            setMessage(panel, invalidMessage, 'error');
            showToast(invalidMessage, 'error');
            return;
        }

        var payload = buildPayload(form);
        lockForm(form, true);
        setMessage(panel, '', 'success');

        try {
            var TwoFactorApi = await getTwoFactorApi();
            var result = await TwoFactorApi[operation](payload, {silent: true});

            if (!result.success) {
                var failMessage = result.message || 'Operation failed. Check the code and try again.';
                setMessage(panel, failMessage, 'error');
                showToast(failMessage, 'error');
                return;
            }

            var okMessage = result.message || 'Operation succeeded.';
            setMessage(panel, okMessage, 'success');
            showToast(okMessage, 'success');

            if (action === 'regenerate') {
                renderBackupCodes(panel, result.backup_codes || []);
                form.reset();
                return;
            }

            window.setTimeout(refreshAccountTwoFaView, 400);
        } catch (error) {
            var errorMessage = 'Operation failed. Please try again.';
            setMessage(panel, errorMessage, 'error');
            showToast(errorMessage, 'error');
        } finally {
            lockForm(form, false);
        }
    }

    document.addEventListener('input', function (event) {
        if (event.target.matches('[data-twofa-panel] input[name="code"]')) {
            event.target.value = event.target.value.replace(/\D/g, '').slice(0, 6);
        }
    });

    // Capture phase: block native POST to JSON endpoints before lazy module races
    // or other form runtimes can navigate away from the account center.
    document.addEventListener('submit', function (event) {
        if (event.target.matches('[data-twofa-action]')) {
            submitTwoFactorForm(event, event.target);
        }
    }, true);
})();
