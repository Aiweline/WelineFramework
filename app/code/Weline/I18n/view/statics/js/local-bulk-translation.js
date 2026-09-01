/* Weline UI source: js/local-bulk-translation.js */
(function (root, factory) {
    'use strict';

    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.Weline = root.Weline || {};
        root.Weline.I18n = root.Weline.I18n || {};
        root.Weline.I18n.LocalBulkTranslation = api;
    }
})(typeof window !== 'undefined' ? window : globalThis, function () {
    'use strict';

    const TYPE_CODE = 'i18n.taglib_local_bulk';

    function translate(text) {
        return typeof __ === 'function' ? __(text) : text;
    }

    function parseJson(value, fallback) {
        if (!value) {
            return fallback;
        }
        try {
            const parsed = JSON.parse(value);
            return parsed ?? fallback;
        } catch (error) {
            return fallback;
        }
    }

    function resolveApiModule(value) {
        const candidates = [value, value && value.default, value && value.api, value && value.default && value.default.api];
        return candidates.find(function (candidate) {
            return candidate && typeof candidate.resource === 'function';
        }) || null;
    }

    async function loadRuntimeApi(host) {
        if (host.Weline && host.Weline.Api && typeof host.Weline.Api.resource === 'function') {
            return host.Weline.Api;
        }
        if (!host.Weline || typeof host.Weline.load !== 'function') {
            throw new Error(translate('Weline.Api bin-query 不可用'));
        }
        const api = resolveApiModule(await host.Weline.load('api'));
        if (!api) {
            throw new Error(translate('Weline.Api bin-query 不可用'));
        }
        host.Weline.Api = api;
        return host.Weline.Api;
    }

    function unwrapRuntimeValue(value) {
        let current = value;
        for (let depth = 0; depth < 5; depth++) {
            if (!current || typeof current !== 'object') {
                return {};
            }
            if ('task_id' in current || 'status' in current || 'result' in current || 'checkpoint' in current) {
                return current;
            }
            const next = current.data && typeof current.data === 'object'
                ? current.data
                : (current.task && typeof current.task === 'object' ? current.task : null);
            if (!next || next === current) {
                return current;
            }
            current = next;
        }
        return current && typeof current === 'object' ? current : {};
    }

    function normalizeRuntimeTaskHandle(value) {
        const task = unwrapRuntimeValue(value);
        if (typeof task.task_id !== 'string' || !task.task_id
            || typeof task.lease_id !== 'string' || !task.lease_id) {
            throw new Error(translate('批量翻译任务响应无效'));
        }
        return {
            task_id: task.task_id,
            lease_id: task.lease_id,
            stream_channel: typeof task.stream_channel === 'string' ? task.stream_channel : 'runtime_task.events',
        };
    }

    function streamPayload(event) {
        if (!event || typeof event !== 'object') {
            return {};
        }
        if (event.detail && typeof event.detail === 'object') {
            if (event.detail.payload && typeof event.detail.payload === 'object') {
                return event.detail.payload;
            }
            return event.detail;
        }
        if (typeof event.data === 'string' && event.data !== '') {
            try {
                return JSON.parse(event.data);
            } catch (error) {
                return { message: event.data };
            }
        }
        return {};
    }

    function createRequestId(prefix) {
        const base = String(prefix || 'local-bulk').replace(/[^\w.-]+/g, '-');
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            const bytes = new Uint8Array(8);
            window.crypto.getRandomValues(bytes);
            return base + '-' + Array.from(bytes, function (byte) {
                return byte.toString(16).padStart(2, '0');
            }).join('');
        }
        return base + '-' + Date.now().toString(36);
    }

    function getSharedDialog() {
        return document.querySelector('[data-w-local-bulk-dialog]');
    }

    function getDialogCloseButtons(dialog) {
        if (!dialog) {
            return [];
        }
        return Array.from(dialog.querySelectorAll('[data-w-local-bulk-close]'));
    }

    function setDialogClosable(dialog, closable) {
        if (!dialog) {
            return;
        }
        dialog.dataset.wClosable = closable ? 'true' : 'false';
        dialog.dataset.wBackdrop = closable ? 'dismissible' : 'static';
        getDialogCloseButtons(dialog).forEach(function (closeButton) {
            closeButton.hidden = !closable;
        });
        const footer = dialog.querySelector('[data-w-local-bulk-footer]');
        if (footer) {
            footer.hidden = !closable;
        }
    }

    function openDialog(dialog) {
        if (!dialog) {
            return;
        }
        dialog.hidden = false;
        dialog.removeAttribute('hidden');
        setDialogClosable(dialog, false);
        if (window.Weline && window.Weline.UI && window.Weline.UI.dialog && typeof window.Weline.UI.dialog.open === 'function') {
            window.Weline.UI.dialog.open(dialog);
            return;
        }
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        }
    }

    function closeDialog(dialog) {
        if (!dialog) {
            return;
        }
        if (window.Weline && window.Weline.UI && window.Weline.UI.dialog && typeof window.Weline.UI.dialog.close === 'function') {
            window.Weline.UI.dialog.close(dialog, 'done');
            return;
        }
        if (typeof dialog.close === 'function') {
            dialog.close();
        }
    }

    function resetDialog(dialog) {
        if (!dialog) {
            return;
        }
        const progressBar = dialog.querySelector('[data-w-local-bulk-progress-bar]');
        const progressText = dialog.querySelector('[data-w-local-bulk-progress-text]');
        const stepsNode = dialog.querySelector('[data-w-local-bulk-steps]');
        const logNode = dialog.querySelector('[data-w-local-bulk-log]');
        const statusNode = dialog.querySelector('[data-w-local-bulk-status]');
        if (progressBar) {
            progressBar.style.width = '0%';
        }
        if (progressText) {
            progressText.textContent = translate('准备开始…');
        }
        if (stepsNode) {
            stepsNode.innerHTML = '';
        }
        if (logNode) {
            logNode.textContent = '';
        }
        if (statusNode) {
            statusNode.textContent = translate('翻译进行中');
            statusNode.dataset.tone = 'info';
        }
        setDialogClosable(dialog, false);
    }

    function appendLog(dialog, message) {
        const logNode = dialog && dialog.querySelector('[data-w-local-bulk-log]');
        if (!logNode || !message) {
            return;
        }
        const line = document.createElement('div');
        line.textContent = String(message);
        logNode.appendChild(line);
        logNode.scrollTop = logNode.scrollHeight;
    }

    function renderSteps(dialog, steps) {
        const stepsNode = dialog.querySelector('[data-w-local-bulk-steps]');
        if (!stepsNode) {
            return {};
        }
        stepsNode.innerHTML = '';
        const map = {};
        steps.forEach(function (step, index) {
            const field = String(step.field || '');
            const label = String(step.label || field || ('#' + (index + 1)));
            const item = document.createElement('div');
            item.className = 'w-local-bulk-step is-waiting';
            item.dataset.field = field;
            item.innerHTML = ''
                + '<div class="w-local-bulk-step__icon" aria-hidden="true">'
                + '<span class="w-spinner" data-size="sm" hidden></span>'
                + '<span data-w-local-bulk-step-dot>•</span>'
                + '</div>'
                + '<div class="w-local-bulk-step__label">' + escapeHtml(label) + '</div>'
                + '<div class="w-local-bulk-step__message" data-w-local-bulk-step-message></div>';
            stepsNode.appendChild(item);
            map[field] = item;
        });
        return map;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = String(text || '');
        return div.innerHTML;
    }

    function setStepState(stepNode, status, message) {
        if (!stepNode) {
            return;
        }
        stepNode.classList.remove('is-waiting', 'is-running', 'is-success', 'is-skipped', 'is-failed');
        const spinner = stepNode.querySelector('.w-spinner');
        const dot = stepNode.querySelector('[data-w-local-bulk-step-dot]');
        const messageNode = stepNode.querySelector('[data-w-local-bulk-step-message]');
        if (messageNode) {
            messageNode.textContent = String(message || '');
        }
        if (status === 'running') {
            stepNode.classList.add('is-running');
            if (spinner) spinner.hidden = false;
            if (dot) dot.hidden = true;
            return;
        }
        if (spinner) spinner.hidden = true;
        if (dot) dot.hidden = false;
        if (status === 'skipped_empty' || status === 'skipped_done' || status === 'skipped') {
            stepNode.classList.add('is-skipped');
            if (dot) dot.textContent = '⊘';
            return;
        }
        if (status === 'failed') {
            stepNode.classList.add('is-failed');
            if (dot) dot.textContent = '✗';
            return;
        }
        stepNode.classList.add('is-success');
        if (dot) dot.textContent = '✓';
    }

    function updateProgress(dialog, completed, total, message) {
        const progressBar = dialog.querySelector('[data-w-local-bulk-progress-bar]');
        const progressText = dialog.querySelector('[data-w-local-bulk-progress-text]');
        const safeTotal = Math.max(1, Number(total) || 1);
        const safeCompleted = Math.max(0, Math.min(Number(completed) || 0, safeTotal));
        const percent = Math.round((safeCompleted / safeTotal) * 100);
        if (progressBar) {
            progressBar.style.width = percent + '%';
        }
        if (progressText) {
            progressText.textContent = String(message || (translate('翻译进度') + ' ' + safeCompleted + '/' + safeTotal));
        }
    }

    function finishDialog(dialog, success, message) {
        const statusNode = dialog.querySelector('[data-w-local-bulk-status]');
        if (statusNode) {
            statusNode.textContent = success ? translate('翻译完成') : translate('翻译失败');
            statusNode.dataset.tone = success ? 'success' : 'danger';
        }
        appendLog(dialog, message || (success ? translate('AI 批量翻译完成') : translate('AI 批量翻译失败')));
        setDialogClosable(dialog, true);
    }

    function collectFieldsFromRoot(root) {
        const configured = parseJson(root.getAttribute('data-bulk-fields') || '[]', []);
        if (Array.isArray(configured) && configured.length > 0) {
            return configured.map(function (item) {
                if (!item || typeof item !== 'object') {
                    return null;
                }
                const field = String(item.field || '').trim();
                const inputId = String(item.input || '').trim();
                const label = String(item.label || field).trim();
                let value = String(item.value || '').trim();
                if (value === '' && inputId !== '') {
                    const input = document.getElementById(inputId);
                    value = input ? String(input.value || '').trim() : '';
                }
                if (field === '') {
                    return null;
                }
                return { field: field, label: label || field, value: value };
            }).filter(Boolean);
        }

        const model = String(root.getAttribute('data-model') || '').trim();
        const recordId = String(root.getAttribute('data-record-id') || '').trim();
        const participants = document.querySelectorAll('[data-w-local-bulk-participant]');
        const fields = [];
        participants.forEach(function (node) {
            if (String(node.getAttribute('data-model') || '').trim() !== model) {
                return;
            }
            if (String(node.getAttribute('data-record-id') || '').trim() !== recordId) {
                return;
            }
            const field = String(node.getAttribute('data-field') || '').trim();
            const inputId = String(node.getAttribute('data-bulk-input') || '').trim();
            const label = String(node.getAttribute('data-bulk-label') || field).trim();
            let value = '';
            if (inputId !== '') {
                const input = document.getElementById(inputId);
                value = input ? String(input.value || '').trim() : '';
            }
            if (field === '') {
                return;
            }
            fields.push({ field: field, label: label || field, value: value });
        });
        return fields;
    }

    function runtimeResultData(snapshot) {
        const result = snapshot && snapshot.result && typeof snapshot.result === 'object'
            ? snapshot.result
            : {};
        if (result.data && typeof result.data === 'object') {
            return result.data;
        }
        if (result.state && typeof result.state === 'object') {
            return result.state;
        }
        return result;
    }

    function isRuntimeTerminal(status) {
        return [
            'completed',
            'failed',
            'cancelled',
            'canceled',
            'expired',
            'recovery_unsafe',
        ].includes(String(status || '').toLowerCase());
    }

    function defaultSleep(ms) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, ms);
        });
    }

    function resolveRuntimeStartError(error) {
        const response = error && error.response ? error.response : null;
        const payload = response && response.error && typeof response.error === 'object'
            ? response.error
            : (response && response.data && response.data.error ? response.data.error : null);
        const message = String(
            (payload && payload.message)
            || (error && error.message)
            || translate('AI 批量翻译失败')
        ).trim();
        const code = String((payload && payload.code) || (error && error.code) || '').trim().toLowerCase();
        if (code === 'backend_attestation_invalid' || code === 'auth_error') {
            return translate('后台 Worker 授权已失效，请刷新页面后重试。');
        }
        if (code === 'backend_acl_denied') {
            return message || translate('当前后台账号无权执行词典翻译，请联系管理员授权。');
        }
        if (message === 'Runtime task was not found.') {
            return translate('无法启动翻译任务，请刷新页面后重试；若仍失败，请确认账号拥有「词典」权限。');
        }
        return message;
    }

    function applySnapshotProgress(dialog, stepMap, snapshot, fields, stateBag) {
        const checkpoint = snapshot && snapshot.checkpoint && typeof snapshot.checkpoint === 'object'
            ? snapshot.checkpoint
            : null;
        const state = checkpoint && checkpoint.state && typeof checkpoint.state === 'object'
            ? checkpoint.state
            : runtimeResultData(snapshot);
        const total = Number(state.total || fields.length);
        const nextIndex = Number(state.next_index || 0);
        const currentField = String(state.current_field || '');
        const results = state.results && typeof state.results === 'object' ? state.results : {};
        const message = String(state.message || state.current_label || '');

        if (!stateBag.started && (snapshot.status === 'running' || snapshot.status === 'starting' || nextIndex > 0 || currentField !== '')) {
            stateBag.started = true;
            appendLog(dialog, translate('开始一键 AI 批量翻译'));
        }

        Object.keys(results).forEach(function (field) {
            const row = results[field];
            if (!row || !stepMap[field]) {
                return;
            }
            const status = String(row.status || 'success');
            if (status === 'skipped_empty' || status === 'skipped_done') {
                setStepState(stepMap[field], status, String(row.message || ''));
            } else {
                setStepState(stepMap[field], status === 'translated' ? 'success' : status, String(row.message || ''));
            }
        });

        if (currentField && stepMap[currentField]) {
            setStepState(stepMap[currentField], 'running', message);
        }

        const completed = Math.max(nextIndex, Object.keys(results).length);
        updateProgress(
            dialog,
            Math.min(completed, total),
            total,
            message || (translate('翻译进度') + ' ' + Math.min(completed, total) + '/' + total)
        );
        if (message) {
            appendLog(dialog, message);
        }
    }

    async function runTranslationTaskPolling(options) {
        const settings = options || {};
        const taskApi = settings.taskApi;
        if (!taskApi) {
            throw new Error(translate('Weline.Api bin-query 不可用'));
        }

        let task = settings.task ? normalizeRuntimeTaskHandle(settings.task) : null;
        if (!task) {
            if (settings.api && typeof settings.api.bootstrapBackend === 'function') {
                await settings.api.bootstrapBackend();
            }
            try {
                task = normalizeRuntimeTaskHandle(await taskApi.start(settings.input || {}, { silent: true }));
            } catch (error) {
                throw new Error(resolveRuntimeStartError(error));
            }
        }

        const sleep = typeof settings.sleep === 'function' ? settings.sleep : defaultSleep;
        const waitMs = Number(settings.intervalMs) > 0 ? Number(settings.intervalMs) : 1000;
        const touchEveryMs = Number(settings.touchEveryMs) > 0 ? Number(settings.touchEveryMs) : 15000;
        let nextTouchAt = 0;

        while (!(typeof settings.isStopped === 'function' && settings.isStopped())) {
            const timestamp = Date.now();
            if (timestamp >= nextTouchAt) {
                nextTouchAt = timestamp + touchEveryMs;
                try {
                    await taskApi.touch({
                        task_id: task.task_id,
                        lease_id: task.lease_id,
                    }, { silent: true });
                } catch (error) {
                    /* best effort */
                }
            }

            const snapshot = unwrapRuntimeValue(await taskApi.status({
                task_id: task.task_id,
            }, { silent: true }));
            if (typeof settings.onUpdate === 'function') {
                settings.onUpdate(snapshot, task);
            }
            if (isRuntimeTerminal(snapshot.status)) {
                return {
                    task: task,
                    snapshot: snapshot,
                    data: runtimeResultData(snapshot),
                };
            }
            await sleep(waitMs);
        }

        return { task: task, snapshot: null, data: {}, detached: true };
    }

    async function runBulkTranslation(root) {
        const dialog = getSharedDialog();
        if (!dialog) {
            throw new Error(translate('批量翻译弹窗未初始化'));
        }

        const model = String(root.getAttribute('data-model') || '').trim();
        const recordId = Number(root.getAttribute('data-record-id') || 0);
        const fields = collectFieldsFromRoot(root).filter(function (item) {
            return item && item.field;
        });

        if (!model || recordId <= 0) {
            throw new Error(String(root.getAttribute('data-msg-save-first') || translate('请先保存后再翻译')));
        }
        if (fields.length === 0) {
            throw new Error(String(root.getAttribute('data-msg-no-fields') || translate('没有可翻译的字段，请先填写文案')));
        }

        resetDialog(dialog);
        const stepMap = renderSteps(dialog, fields);
        updateProgress(dialog, 0, fields.length, translate('正在启动翻译任务…'));
        openDialog(dialog);

        const api = await loadRuntimeApi(window);
        const taskApi = api.resource('runtime_task');
        const progressState = { started: false };

        const outcome = await runTranslationTaskPolling({
            api: api,
            taskApi: taskApi,
            input: {
                type_code: TYPE_CODE,
                input: {
                    model: model,
                    record_id: recordId,
                    fields: fields,
                    retranslate_all: '0',
                    request_id: createRequestId('local-bulk-' + recordId),
                },
            },
            onUpdate: function (snapshot) {
                applySnapshotProgress(dialog, stepMap, snapshot, fields, progressState);
            },
        });

        if (outcome.detached) {
            throw new Error(translate('翻译任务已中断'));
        }

        const status = String(outcome.snapshot && outcome.snapshot.status || '').toLowerCase();
        if (status === 'completed') {
            Object.keys(stepMap).forEach(function (field) {
                const node = stepMap[field];
                if (node && !node.classList.contains('is-success') && !node.classList.contains('is-skipped')) {
                    setStepState(node, 'success', translate('已完成'));
                }
            });
            const message = String(outcome.data.message || translate('AI 批量翻译完成'));
            updateProgress(dialog, fields.length, fields.length, message);
            finishDialog(dialog, true, message);
            return;
        }

        const failureMessage = String(
            outcome.data.message
            || (outcome.snapshot && outcome.snapshot.terminal_reason)
            || translate('AI 批量翻译失败')
        );
        finishDialog(dialog, false, failureMessage);
    }

    function bindRoot(root) {
        if (!root || root.dataset.wLocalBulkBound === '1') {
            return;
        }
        root.dataset.wLocalBulkBound = '1';
        const trigger = root.querySelector('[data-w-local-bulk-trigger]');
        if (!trigger) {
            return;
        }
        trigger.addEventListener('click', function () {
            if (trigger.disabled) {
                return;
            }
            trigger.disabled = true;
            trigger.setAttribute('aria-busy', 'true');
            runBulkTranslation(root).catch(function (error) {
                const dialog = getSharedDialog();
                const message = error instanceof Error ? error.message : String(error || translate('AI 批量翻译失败'));
                if (dialog && !dialog.open) {
                    resetDialog(dialog);
                    openDialog(dialog);
                }
                if (dialog) {
                    finishDialog(dialog, false, message);
                } else if (window.Weline && window.Weline.UI && window.Weline.UI.toast) {
                    window.Weline.UI.toast.error(message);
                }
            }).finally(function () {
                trigger.disabled = false;
                trigger.setAttribute('aria-busy', 'false');
            });
        });

        const dialog = getSharedDialog();
        if (dialog && dialog.dataset.wLocalBulkDialogBound !== '1') {
            dialog.dataset.wLocalBulkDialogBound = '1';
            getDialogCloseButtons(dialog).forEach(function (closeButton) {
                closeButton.addEventListener('click', function () {
                    if (dialog.dataset.wClosable === 'false') {
                        return;
                    }
                    closeDialog(dialog);
                });
            });
        }
    }

    function init() {
        document.querySelectorAll('[data-w-local-bulk-root]').forEach(bindRoot);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

    return {
        init: init,
        bindRoot: bindRoot,
        runBulkTranslation: runBulkTranslation,
        TYPE_CODE: TYPE_CODE,
    };
});
