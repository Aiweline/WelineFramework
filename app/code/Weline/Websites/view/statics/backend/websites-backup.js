/**
 * 网站备份管理页（Websites_Backup）
 *
 * 由模板 data-weline-load="websiteBackup" 按需加载（后端主题标准 §6.1）。
 * 配置来自 <script type="application/json" id="website-backup-config">。
 */
(function () {
    'use strict';

    window.WelineWebsitesBackup = { ready: true };

    var configEl = document.getElementById('website-backup-config');
    if (!configEl) {
        return;
    }
    var config;
    try {
        config = JSON.parse(configEl.textContent || '{}');
    } catch (error) {
        return;
    }

    function toast(type, message) {
        if (window.Weline && window.Weline.UI && window.Weline.UI.toast && typeof window.Weline.UI.toast[type] === 'function') {
            window.Weline.UI.toast[type](message);
            return;
        }
        if (window.console && typeof window.console.log === 'function') {
            window.console.log(message);
        }
    }

    function unwrapResponse(response) {
        var _biz = window.WelineApiBusiness || (window.Weline && window.Weline.ApiBusiness);
        if (_biz && typeof _biz.unwrapBusiness === 'function') {
            return _biz.unwrapBusiness(response) || {};
        }
        if (response && typeof response === 'object' && response.data && typeof response.data.success !== 'undefined') {
            return response.data;
        }
        return response || {};
    }

    function ensureApi() {
        return !!(
            window.Weline
            && (
                typeof window.Weline.load === 'function'
                || (window.Weline.Api && typeof window.Weline.Api.resource === 'function')
            )
        );
    }

    function manageBackup(action, payload) {
        payload = payload || {};
        payload.action = action;
        var invoke = function (api) {
            return api.resource('websites').manageWebsiteBackup(
                payload,
                { requestTimeoutMs: 300000 }
            );
        };
        return window.Weline.load
            ? window.Weline.load('api').then(invoke)
            : Promise.resolve(invoke(window.Weline.Api));
    }

    function markBackupFailure(submit, message) {
        if (!submit) {
            return;
        }
        submit.setAttribute('data-backup-state', 'failed');
        submit.setAttribute('data-backup-error', String(message || config.text.createFail));
    }

    function appendQuery(url, key, value) {
        var separator = url.indexOf('?') === -1 ? '?' : '&';
        return url + separator + encodeURIComponent(key) + '=' + encodeURIComponent(value);
    }

    var form = document.getElementById('backupForm');
    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!ensureApi()) {
                toast('error', config.text.apiUnavailable);
                return;
            }

            var websiteIdInput = form.querySelector('#website_id_value');
            var websiteId = websiteIdInput ? String(websiteIdInput.value || '').trim() : '';
            if (websiteId === '') {
                toast('error', config.text.websiteRequired);
                return;
            }

            var submit = form.querySelector('[data-backup-submit]');
            var submitLabel = submit ? submit.querySelector('span') : null;
            if (submit) {
                submit.disabled = true;
                submit.setAttribute('data-backup-state', 'pending');
                submit.removeAttribute('data-backup-error');
                submit.removeAttribute('data-backup-filename');
            }
            if (submitLabel) {
                submitLabel.textContent = config.text.creating;
            }

            manageBackup('create', {
                website_id: websiteId,
                backup_type: form.querySelector('#backup_type').value
            }).then(function (response) {
                var data = unwrapResponse(response);
                if (data.success) {
                    if (submit) {
                        submit.setAttribute('data-backup-state', 'created');
                        submit.setAttribute(
                            'data-backup-filename',
                            String(data.data && data.data.backup && data.data.backup.filename || '')
                        );
                    }
                    toast('success', data.message || config.text.createDefault);
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 800);
                    return;
                }
                var message = data.message || config.text.createFail;
                markBackupFailure(submit, message);
                toast('error', message);
            }).catch(function (error) {
                var message = (error && error.message) || config.text.createFail;
                markBackupFailure(submit, message);
                toast('error', message);
            }).finally(function () {
                if (submit) {
                    submit.disabled = false;
                }
                if (submitLabel) {
                    submitLabel.textContent = config.text.createDefault;
                }
            });
        });
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-backup-action]');
        if (!trigger) {
            return;
        }
        event.preventDefault();

        var filename = trigger.getAttribute('data-backup-filename') || '';
        var action = trigger.getAttribute('data-backup-action') || '';
        if (!filename) {
            return;
        }

        if (action === 'download') {
            var downloadUrl = appendQuery(config.downloadUrl, 'backup_action', 'download');
            window.location.href = appendQuery(downloadUrl, 'filename', filename);
            return;
        }

        if (action !== 'delete') {
            return;
        }

        window.Weline.UI.dialog.confirm(config.text.deleteConfirm).then(function (confirmed) {
            if (!confirmed) {
                return;
            }
            if (!ensureApi()) {
                toast('error', config.text.apiUnavailable);
                return;
            }

            manageBackup('delete', { filename: filename }).then(function (response) {
                var data = unwrapResponse(response);
                if (data.success) {
                    toast('success', data.message || '');
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 500);
                    return;
                }
                toast('error', data.message || config.text.deleteFail);
            }).catch(function (error) {
                toast('error', (error && error.message) || config.text.deleteFail);
            });
        });
    });
})();
