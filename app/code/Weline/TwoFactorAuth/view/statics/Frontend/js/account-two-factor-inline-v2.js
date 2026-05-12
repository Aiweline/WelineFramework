(function () {
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
        var payload = new URLSearchParams();
        Array.prototype.forEach.call(form.elements, function (element) {
            if (!element.name) {
                return;
            }
            payload.append(element.name, element.value);
        });
        return payload;
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

        var html = '<h3>新的备份码</h3><div class="twofa-backup-result__grid">';
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
        var endpoint = form.getAttribute('data-endpoint');
        var action = form.getAttribute('data-twofa-action');

        if (!panel || !endpoint) {
            return;
        }

        if (code.length !== 6) {
            setMessage(panel, '请输入 6 位验证码。', 'error');
            return;
        }

        var payload = buildPayload(form);
        lockForm(form, true);
        setMessage(panel, '', 'success');

        try {
            var response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: payload
            });
            var result = await response.json();

            if (!result.success) {
                setMessage(panel, result.message || '操作失败，请检查验证码后重试。', 'error');
                return;
            }

            setMessage(panel, result.message || '操作成功。', 'success');

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
            setMessage(panel, '操作失败，请稍后重试。', 'error');
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
