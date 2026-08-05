(function () {
    'use strict';

    function boot() {
        var root = document.getElementById('framework-test-app');
        if (!root) {
            return;
        }

        var state = {
            api: null,
            page: root.dataset.page || 'modules',
            module: root.dataset.module || '',
            runId: Number(root.dataset.runId || 0) || 0,
            tab: 'e2e',
            cases: { e2e: [], unit: [], integration: [] },
            pollTimer: null
        };

        var els = {
            status: document.getElementById('ft-status'),
            moduleRows: document.getElementById('ft-module-rows'),
            caseRows: document.getElementById('ft-case-rows'),
            historyRows: document.getElementById('ft-history-rows'),
            moduleTitle: document.getElementById('ft-module-title'),
            moduleCounts: document.getElementById('ft-module-counts'),
            uiEnabled: document.getElementById('ft-ui-enabled'),
            checkAll: document.getElementById('ft-check-all'),
            runStatus: document.getElementById('ft-run-status'),
            runPercent: document.getElementById('ft-run-percent'),
            runScore: document.getElementById('ft-run-score'),
            runCurrent: document.getElementById('ft-run-current'),
            runBar: document.getElementById('ft-run-bar'),
            runLog: document.getElementById('ft-run-log'),
            rerun: document.getElementById('ft-rerun'),
            runSelected: document.getElementById('ft-run-selected')
        };

        function text(key, fallback) {
            return root.dataset[key] || fallback || '';
        }

        function interpolate(pattern, values) {
            var result = String(pattern || '');
            (values || []).forEach(function (value, index) {
                result = result.replaceAll('%{' + (index + 1) + '}', String(value));
            });
            return result;
        }

        function showStatus(kind, message) {
            if (!els.status) {
                return;
            }
            els.status.hidden = false;
            els.status.className = 'ft-status alert alert-' + (kind === 'error' ? 'danger' : kind === 'success' ? 'success' : 'info');
            els.status.textContent = message;
        }

        function hideStatus() {
            if (!els.status) {
                return;
            }
            els.status.hidden = true;
            els.status.textContent = '';
        }

        function getApi() {
            if (state.api) {
                return state.api;
            }
            if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                throw new Error(text('textApiUnavailable', 'api_unavailable'));
            }
            state.api = Promise.resolve(window.Weline.Api.resource(root.dataset.provider || 'test'))
                .catch(function (error) {
                    state.api = null;
                    throw error;
                });
            return state.api;
        }

        function defaultCall(operation, params) {
            return getApi().then(function (api) {
                if (!api || typeof api[operation] !== 'function') {
                    throw new Error('operation_unavailable:' + operation);
                }
                return api[operation](params || {});
            });
        }

        var callImpl = defaultCall;

        function call(operation, params) {
            return callImpl(operation, params);
        }

        function moduleUrl(moduleName) {
            var base = root.dataset.urlModule || '';
            var sep = base.indexOf('?') >= 0 ? '&' : '?';
            return base + sep + 'module=' + encodeURIComponent(moduleName);
        }

        function runUrl(runId) {
            var base = root.dataset.urlRun || '';
            var sep = base.indexOf('?') >= 0 ? '&' : '?';
            return base + sep + 'run_id=' + encodeURIComponent(String(runId));
        }

        function renderModules(payload) {
            var modules = (payload && payload.modules) || {};
            var names = Object.keys(modules).sort();
            if (!els.moduleRows) {
                return;
            }
            if (!names.length) {
                els.moduleRows.innerHTML = '<tr><td colspan="5" class="text-muted text-center py-4">' +
                    text('textEmpty', 'empty') + '</td></tr>';
                return;
            }
            els.moduleRows.innerHTML = names.map(function (name) {
                var row = modules[name] || {};
                var counts = row.counts || {};
                return '<tr>' +
                    '<td><code>' + escapeHtml(name) + '</code></td>' +
                    '<td class="text-end">' + Number(counts.e2e || 0) + '</td>' +
                    '<td class="text-end">' + Number(counts.unit || 0) + '</td>' +
                    '<td class="text-end">' + Number(counts.integration || 0) + '</td>' +
                    '<td class="text-end"><div class="d-inline-flex flex-wrap justify-content-end gap-1">' +
                    '<a class="btn btn-sm btn-outline-secondary" href="' + escapeHtml(moduleUrl(name)) + '">' +
                    escapeHtml(text('textEnterCases', 'cases')) + '</a>' +
                    moduleRunButton(name, 'e2e', counts.e2e, text('textRunE2e', 'E2E')) +
                    moduleRunButton(name, 'unit', counts.unit, text('textRunUnit', 'Unit')) +
                    moduleRunButton(name, 'integration', counts.integration, text('textRunIntegration', 'Integration')) +
                    '</div></td>' +
                    '</tr>';
            }).join('');
        }

        function moduleRunButton(moduleName, type, count, label) {
            var disabled = Number(count || 0) <= 0;
            return '<button type="button" class="btn btn-sm btn-outline-primary ft-run-action" ' +
                'data-ft-run-module data-module="' + escapeHtml(moduleName) + '" data-type="' +
                escapeHtml(type) + '"' + (disabled ? ' disabled' : '') + '>' +
                escapeHtml(label) + '</button>';
        }

        function renderCases() {
            if (!els.caseRows) {
                return;
            }
            var files = state.cases[state.tab] || [];
            if (!files.length) {
                els.caseRows.innerHTML = '<tr><td colspan="3" class="text-muted text-center py-3">' +
                    text('textEmpty', 'empty') + '</td></tr>';
                syncSelectionState();
                return;
            }
            els.caseRows.innerHTML = files.map(function (file) {
                return '<tr><td><input type="checkbox" class="ft-case-check" value="' +
                    escapeHtml(file) + '"></td><td><code>' + escapeHtml(file) +
                    '</code></td><td class="text-end"><button type="button" ' +
                    'class="btn btn-sm btn-primary ft-run-action" data-ft-run-case data-file="' +
                    escapeHtml(file) + '">' + escapeHtml(text('textRunCase', 'Run case')) +
                    '</button></td></tr>';
            }).join('');
            syncSelectionState();
        }

        function selectedFiles() {
            return Array.prototype.slice.call(document.querySelectorAll('.ft-case-check:checked'))
                .map(function (el) { return el.value; });
        }

        function syncSelectionState() {
            var boxes = Array.prototype.slice.call(document.querySelectorAll('.ft-case-check'));
            var selected = boxes.filter(function (box) { return box.checked; }).length;
            if (els.runSelected) {
                els.runSelected.textContent = text('textRunSelected', 'Run selected') + ' (' + selected + ')';
                els.runSelected.dataset.selectedCount = String(selected);
            }
            if (els.checkAll) {
                els.checkAll.checked = boxes.length > 0 && selected === boxes.length;
                els.checkAll.indeterminate = selected > 0 && selected < boxes.length;
            }
        }

        function renderHistory(payload) {
            if (!els.historyRows) {
                return;
            }
            var items = (payload && payload.items) || [];
            if (!items.length) {
                els.historyRows.innerHTML = '<tr><td colspan="6" class="text-muted text-center py-3">' +
                    text('textEmpty', 'empty') + '</td></tr>';
                return;
            }
            els.historyRows.innerHTML = items.map(function (item) {
                return '<tr>' +
                    '<td><a href="' + escapeHtml(runUrl(item.run_id)) + '">#' + item.run_id + '</a></td>' +
                    '<td><code>' + escapeHtml(item.module || '') + '</code></td>' +
                    '<td>' + escapeHtml(item.type || '') + '</td>' +
                    '<td>' + escapeHtml(item.status || '') + '</td>' +
                    '<td>' + (item.ui_enabled ? 'ON' : 'OFF') + '</td>' +
                    '<td>' + escapeHtml(item.created_at || '') + '</td>' +
                    '</tr>';
            }).join('');
        }

        function renderRun(run) {
            if (!run) {
                return;
            }
            var progress = run.progress || {};
            var percent = Number(progress.percent || 0);
            if (els.runStatus) {
                els.runStatus.textContent = run.status || '—';
            }
            if (els.runPercent) {
                els.runPercent.textContent = percent + '%';
            }
            if (els.runScore) {
                els.runScore.textContent = Number(progress.passed || 0) + ' / ' + Number(progress.failed || 0);
            }
            if (els.runCurrent) {
                els.runCurrent.textContent = progress.current || '—';
            }
            if (els.runBar) {
                els.runBar.style.width = Math.max(0, Math.min(100, percent)) + '%';
            }
            if (els.runLog) {
                els.runLog.textContent = run.log || '';
                els.runLog.scrollTop = els.runLog.scrollHeight;
            }
            if (els.rerun) {
                var terminal = ['success', 'failed', 'error'].indexOf(String(run.status || '')) >= 0;
                els.rerun.hidden = !terminal;
                els.rerun.dataset.module = run.module || '';
                els.rerun.dataset.type = run.type || 'e2e';
                els.rerun.dataset.ui = run.ui_enabled ? '1' : '0';
            }
            if (['pending', 'running'].indexOf(String(run.status || '')) >= 0) {
                schedulePoll();
            } else if (state.pollTimer) {
                clearTimeout(state.pollTimer);
                state.pollTimer = null;
            }
        }

        function schedulePoll() {
            if (state.pollTimer) {
                clearTimeout(state.pollTimer);
            }
            state.pollTimer = setTimeout(function () {
                loadRun(false);
            }, 1000);
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function loadModules() {
            return call('listModules', {}).then(renderModules);
        }

        function loadCases() {
            if (!state.module) {
                return Promise.resolve();
            }
            if (els.moduleTitle) {
                els.moduleTitle.textContent = state.module;
            }
            return call('listCases', { module: state.module }).then(function (payload) {
                state.cases = {
                    e2e: (payload.tests && payload.tests.e2e) || [],
                    unit: (payload.tests && payload.tests.unit) || [],
                    integration: (payload.tests && payload.tests.integration) || []
                };
                var counts = payload.counts || {};
                if (els.moduleCounts) {
                    els.moduleCounts.textContent = 'E2E ' + Number(counts.e2e || 0) +
                        ' / Unit ' + Number(counts.unit || 0) +
                        ' / Integration ' + Number(counts.integration || 0);
                }
                renderCases();
            });
        }

        function loadHistory() {
            return call('listRuns', { page: 1, page_size: 10 }).then(renderHistory);
        }

        function loadRun(showLoading) {
            if (!state.runId) {
                return Promise.resolve();
            }
            if (showLoading) {
                showStatus('info', text('textLoading', 'loading'));
            }
            return call('getRun', { run_id: state.runId }).then(function (run) {
                hideStatus();
                renderRun(run);
            });
        }

        function startRun(type, files, moduleName) {
            hideStatus();
            var targetModule = String(moduleName || state.module || '').trim();
            if (!targetModule) {
                return Promise.reject(new Error(text('textNoSelection', 'module_required')));
            }
            state.module = targetModule;
            var operation = type === 'e2e' ? 'runE2e' : 'runUnit';
            var params = {
                module: targetModule,
                files: files || []
            };
            if (type === 'e2e') {
                params.ui_enabled = isUiEnabled();
            } else {
                params.type = type === 'integration' ? 'integration' : 'unit';
            }
            return call(operation, params).then(function (result) {
                var runId = result && result.run_id;
                var uiLabel = type === 'e2e' && params.ui_enabled ? ' UI=ON' : (type === 'e2e' ? ' UI=OFF' : '');
                showStatus('success', interpolate(text('textRunStarted', 'started %{1}'), [runId]) + uiLabel);
                if (runId && !(window.__WelineFrameworkTest && window.__WelineFrameworkTest.suppressNavigate)) {
                    window.location.href = runUrl(runId);
                }
                return result;
            });
        }

        function isUiEnabled() {
            return !!(els.uiEnabled && els.uiEnabled.checked);
        }

        function persistUiEnabled() {
            var enabled = isUiEnabled();
            return call('setUiEnabled', { ui_enabled: enabled }).then(function (result) {
                var saved = !(result && result.saved === false);
                if (!saved) {
                    throw new Error(text('textError', 'save_failed'));
                }
                showStatus(
                    'success',
                    enabled
                        ? text('textUiSavedOn', 'ui_saved_on')
                        : text('textUiSavedOff', 'ui_saved_off')
                );
                return result;
            });
        }

        if (els.uiEnabled) {
            var initialUi = root.dataset.uiEnabled === '1';
            els.uiEnabled.checked = initialUi;
            els.uiEnabled.addEventListener('change', function () {
                persistUiEnabled().catch(function (error) {
                    els.uiEnabled.checked = !els.uiEnabled.checked;
                    onError(error);
                });
            });
        }

        document.getElementById('ft-refresh') && document.getElementById('ft-refresh').addEventListener('click', function () {
            call('refreshCatalog', { module: state.module || null }).then(function () {
                return Promise.all([
                    state.page === 'module' ? loadCases() : loadModules(),
                    loadHistory()
                ]);
            }).then(function () {
                showStatus('success', 'OK');
            }).catch(onError);
        });

        document.querySelectorAll('[data-ft-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                state.tab = btn.getAttribute('data-ft-tab') || 'e2e';
                document.querySelectorAll('[data-ft-tab]').forEach(function (el) {
                    el.classList.toggle('active', el === btn);
                });
                renderCases();
            });
        });

        if (els.checkAll) {
            els.checkAll.addEventListener('change', function () {
                document.querySelectorAll('.ft-case-check').forEach(function (el) {
                    el.checked = !!els.checkAll.checked;
                });
                syncSelectionState();
            });
        }

        if (els.caseRows) {
            els.caseRows.addEventListener('change', function (event) {
                if (event.target && event.target.classList.contains('ft-case-check')) {
                    syncSelectionState();
                }
            });
            els.caseRows.addEventListener('click', function (event) {
                var button = event.target && event.target.closest('[data-ft-run-case]');
                if (!button) {
                    return;
                }
                startRun(
                    state.tab === 'e2e' ? 'e2e' : state.tab,
                    [button.getAttribute('data-file') || '']
                ).catch(onError);
            });
        }

        if (els.moduleRows) {
            els.moduleRows.addEventListener('click', function (event) {
                var button = event.target && event.target.closest('[data-ft-run-module]');
                if (!button || button.disabled) {
                    return;
                }
                startRun(
                    button.getAttribute('data-type') || 'e2e',
                    [],
                    button.getAttribute('data-module') || ''
                ).catch(onError);
            });
        }

        if (els.runSelected) {
            els.runSelected.addEventListener('click', function () {
                var files = selectedFiles();
                if (!files.length) {
                    showStatus('error', text('textNoSelection', 'no_selection'));
                    return;
                }
                startRun(state.tab === 'e2e' ? 'e2e' : state.tab, files).catch(onError);
            });
        }
        var runAll = document.getElementById('ft-run-all');
        if (runAll) {
            runAll.addEventListener('click', function () {
                startRun(state.tab === 'e2e' ? 'e2e' : state.tab, []).catch(onError);
            });
        }
        if (els.rerun) {
            els.rerun.addEventListener('click', function () {
                state.module = els.rerun.dataset.module || state.module;
                var type = els.rerun.dataset.type || 'e2e';
                if (els.uiEnabled) {
                    els.uiEnabled.checked = els.rerun.dataset.ui !== '0';
                }
                startRun(type === 'e2e' ? 'e2e' : type, []).catch(onError);
            });
        }

        function onError(error) {
            var message = '';
            if (error && typeof error === 'object') {
                message = error.message || error.msg || error.error || '';
                if (!message && error.body && typeof error.body === 'object') {
                    message = error.body.message || error.body.error || '';
                }
                if (!message && error.data && typeof error.data === 'object') {
                    message = error.data.message || error.data.error || '';
                }
            }
            if (!message) {
                message = String(error || 'error');
            }
            showStatus('error', interpolate(text('textError', 'error %{1}'), [message]));
        }

        var booters = [loadHistory()];
        if (state.page === 'module') {
            booters.push(loadCases());
        } else if (state.page === 'run') {
            booters.push(loadRun(true));
        } else {
            booters.push(loadModules());
        }

        Promise.all(booters).catch(onError);

        window.__WelineFrameworkTest = {
            getState: function () {
                return {
                    page: state.page,
                    module: state.module,
                    tab: state.tab,
                    runId: state.runId
                };
            },
            selectedFiles: selectedFiles,
            defaultCall: defaultCall,
            setCallImpl: function (fn) {
                callImpl = typeof fn === 'function' ? fn : defaultCall;
            },
            resetCallImpl: function () {
                callImpl = defaultCall;
            },
            call: call,
            startRun: startRun,
            suppressNavigate: false
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
