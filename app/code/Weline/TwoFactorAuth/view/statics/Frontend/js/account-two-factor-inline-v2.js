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
            twoFactorApiPromise = Promise.resolve(window.Weline.Api.resource('twoFactor'));
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

    async function submitTwoFactorForm(event, form) {
        event.preventDefault();

        var panel = findPanel(form);
        var code = normalizeCode(form);
        var action = form.getAttribute('data-twofa-action');
        var operation = operationForAction(action);

        if (!panel || !operation) {
            return;
        }

        if (code.length !== 6) {
            setMessage(panel, 'Please enter a 6-digit verification code.', 'error');
            return;
        }

        var payload = buildPayload(form);
        lockForm(form, true);
        setMessage(panel, '', 'success');

        try {
            var TwoFactorApi = await getTwoFactorApi();
            var result = await TwoFactorApi[operation](payload, {silent: true});

            if (!result.success) {
                setMessage(panel, result.message || 'Operation failed. Check the code and try again.', 'error');
                return;
            }

            setMessage(panel, result.message || 'Operation succeeded.', 'success');

            if (action === 'regenerate') {
                renderBackupCodes(panel, result.backup_codes || []);
                form.reset();
                return;
            }

            window.setTimeout(function () {
                window.location.href = '/customer/account/index#twofa';
                window.location.reload();
            }, 700);
        } catch (error) {
            setMessage(panel, 'Operation failed. Please try again.', 'error');
        } finally {
            lockForm(form, false);
        }
    }

    document.addEventListener('input', function (event) {
        if (event.target.matches('[data-twofa-panel] input[name="code"]')) {
            event.target.value = event.target.value.replace(/\D/g, '').slice(0, 6);
        }
    });

    document.addEventListener('submit', function (event) {
        if (event.target.matches('[data-twofa-action]')) {
            submitTwoFactorForm(event, event.target);
        }
    });
})();
