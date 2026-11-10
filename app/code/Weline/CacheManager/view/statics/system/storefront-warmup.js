(function (window, document) {
    'use strict';

    var root = document.getElementById('sfw-page');
    var configNode = document.getElementById('sfw-page-config');

    if (!root || !configNode) {
        return;
    }

    var strings = {};
    try {
        var parsed = JSON.parse(configNode.textContent || '{}');
        strings = parsed.strings || {};
    } catch (error) {
        console.error('[Storefront Warmup] Failed to parse page config.', error);
    }

    var pollTimer = 0;
    var polling = false;
    var lastPollAt = 0;

    function text(key, fallback) {
        var value = strings[key];
        return value == null || value === '' ? fallback : String(value);
    }

    function byId(id) {
        return document.getElementById(id);
    }

    function setText(id, value) {
        var node = byId(id);
        if (node) {
            node.textContent = value == null ? '' : String(value);
        }
    }

    function toast(type, message) {
        if (window.Weline && window.Weline.UI && window.Weline.UI.toast && typeof window.Weline.UI.toast[type] === 'function') {
            window.Weline.UI.toast[type](message);
            return;
        }
        console[type === 'error' ? 'error' : 'log'](message);
    }

    function api() {
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            throw new Error('Weline.Api resource bridge unavailable');
        }

        return window.Weline.Api.resource('cache_manager');
    }

    function unwrap(response) {
        if (!response || typeof response !== 'object') {
            return response;
        }

        return response.data && typeof response.data === 'object' ? response.data : response;
    }

    function percent(value) {
        var n = Number(value);
        if (!isFinite(n)) {
            return '0';
        }

        return String(Math.min(100, Math.max(0, Math.round(n * 10) / 10)));
    }

    function renderPhase(row) {
        if (!row || !row.key) {
            return;
        }
        var item = byId('sfw-phase-' + row.key);
        if (!item) {
            return;
        }
        var fill = item.querySelector('[data-role="fill"]');
        var done = item.querySelector('[data-role="done"]');
        var total = item.querySelector('[data-role="total"]');
        if (fill) {
            fill.style.width = percent(row.percent) + '%';
        }
        if (done) {
            done.textContent = String(row.done == null ? 0 : row.done);
        }
        if (total) {
            total.textContent = String(row.total == null ? 0 : row.total);
        }
        item.setAttribute('data-state', row.current ? 'current' : (Number(row.percent) >= 100 ? 'done' : 'pending'));
    }

    function render(status) {
        if (!status) {
            return;
        }
        var verdict = status.verdict || {};
        var load = status.load || {};
        var last = status.last || {};
        var totals = status.totals || {};
        var group = status.current_group || {};
        var generation = status.generation || {};
        var progress = status.progress || {};

        setText('sfw-verdict', verdict.label || '');
        setText('sfw-verdict-detail', verdict.detail || '');
        var hero = root.querySelector('.sfw-hero');
        if (hero) {
            hero.setAttribute('data-tone', verdict.state || 'never');
        }

        setText('sfw-updated', last.updated_at || '');
        setText('sfw-age', last.age_seconds === null || last.age_seconds === undefined
            ? '（' + text('never_updated', '从未更新') + '）'
            : '（' + String(last.age_seconds) + ' ' + text('seconds_ago', '秒前') + '）');
        setText('sfw-generation', generation.short || '');
        var rewarm = byId('sfw-rewarm');
        if (rewarm) {
            rewarm.hidden = !generation.needs_rewarm;
        }

        setText('sfw-phase-label', status.phase_label || '');
        setText('sfw-group-label', group.label || '');
        setText('sfw-group-position', group.position || '');
        setText('sfw-group-item', group.item || '');
        setText('sfw-last-url', last.url || '');
        setText('sfw-last-status', last.status || '');
        setText('sfw-last-reason', last.reason || '');

        setText('sfw-total-ok', totals.ok == null ? 0 : totals.ok);
        setText('sfw-total-skip', totals.skip == null ? 0 : totals.skip);
        setText('sfw-total-fail', totals.fail == null ? 0 : totals.fail);
        setText('sfw-remaining', progress.remaining == null ? 0 : progress.remaining);

        setText('sfw-progress-percent', percent(progress.percent) + '%');
        setText('sfw-progress-count', (progress.done == null ? 0 : progress.done) + '/' + (progress.total == null ? 0 : progress.total));
        var progressFill = byId('sfw-progress-fill');
        if (progressFill) {
            progressFill.style.width = percent(progress.percent) + '%';
        }
        var progressBar = byId('sfw-progress-bar');
        if (progressBar) {
            progressBar.setAttribute('aria-valuenow', percent(progress.percent));
        }

        // phases 以相位字母为键（PHP 关联数组），JSON 后是对象，不能直接 forEach。
        var phases = status.phases || {};
        Object.keys(phases).forEach(function (key) {
            renderPhase(phases[key]);
        });

        setText('sfw-cores', load.cores == null ? 0 : load.cores);
        setText('sfw-load1', load.load1 == null ? 0 : load.load1);
        setText('sfw-load-ratio', percent(load.ratio_percent) + '%');
        setText('sfw-start-pct', percent(load.start_percent) + '%');
        setText('sfw-abort-pct', percent(load.abort_percent) + '%');
        var sourceNode = byId('sfw-load-source');
        if (sourceNode) {
            sourceNode.textContent = (load.source_label || '') + ' · ' + (load.cores == null ? 0 : load.cores) + ' 核 · load1 ' + (load.load1 == null ? 0 : load.load1);
        }
        var fill = byId('sfw-load-fill');
        if (fill) {
            fill.style.width = percent(load.ratio_percent) + '%';
        }
        var startMark = byId('sfw-load-start');
        if (startMark) {
            startMark.style.left = percent(load.start_percent) + '%';
        }
        var abortMark = byId('sfw-load-abort');
        if (abortMark) {
            abortMark.style.left = percent(load.abort_percent) + '%';
        }
        setText('sfw-band', load.band_label || '');
        setText('sfw-designed', (load.designed_concurrency == null ? 0 : load.designed_concurrency) + ' / ' + (load.designed_shard == null ? 0 : load.designed_shard));
        setText('sfw-effective', (load.effective_concurrency == null ? 0 : load.effective_concurrency) + ' / ' + (load.effective_shard == null ? 0 : load.effective_shard));
        setText('sfw-wall', load.effective_wall_ms == null ? 0 : load.effective_wall_ms);
    }

    function refresh() {
        var now = Date.now();
        if (polling && now - lastPollAt < 15000) {
            return;
        }
        polling = true;
        lastPollAt = now;
        try {
            api().storefrontWarmupStatus({}).then(function (response) {
                var payload = unwrap(response);
                if (!payload || payload.success === false || !payload.status) {
                    throw new Error((payload && payload.message) || text('load_failed', '状态刷新失败'));
                }
                render(payload.status);
            }).catch(function (error) {
                console.warn('[Storefront Warmup] status refresh failed:', error && error.message ? error.message : error);
            }).finally(function () {
                polling = false;
            });
        } catch (error) {
            polling = false;
            console.warn('[Storefront Warmup] status bridge unavailable:', error && error.message ? error.message : error);
        }
    }

    function openSettings() {
        var dialog = byId('sfw-settings');
        if (!dialog) {
            return;
        }
        if (window.Weline && window.Weline.UI && window.Weline.UI.dialog && typeof window.Weline.UI.dialog.open === 'function') {
            window.Weline.UI.dialog.open('#sfw-settings');
            return;
        }
        dialog.hidden = false;
        dialog.setAttribute('data-state', 'open');
        dialog.setAttribute('aria-hidden', 'false');
    }

    function closeSettings() {
        var dialog = byId('sfw-settings');
        if (!dialog) {
            return;
        }
        if (window.Weline && window.Weline.UI && window.Weline.UI.dialog && typeof window.Weline.UI.dialog.close === 'function') {
            window.Weline.UI.dialog.close('#sfw-settings');
            return;
        }
        dialog.hidden = true;
        dialog.setAttribute('data-state', 'closed');
        dialog.setAttribute('aria-hidden', 'true');
    }

    function numberValue(id) {
        var node = byId(id);
        if (!node) {
            return null;
        }
        var raw = String(node.value == null ? '' : node.value).trim();
        if (raw === '') {
            return null;
        }
        var n = Number(raw);
        return isFinite(n) ? Math.round(n) : NaN;
    }

    function saveSettings(event) {
        if (event) {
            event.preventDefault();
        }
        var message = byId('sfw-settings-message');
        var button = byId('sfw-settings-save');
        var params = {
            busy_start_pct: numberValue('sfw-input-start'),
            busy_abort_pct: numberValue('sfw-input-abort'),
            concurrency: numberValue('sfw-input-concurrency'),
            shard: numberValue('sfw-input-shard'),
            wall_ms: numberValue('sfw-input-wall')
        };
        Object.keys(params).forEach(function (key) {
            if (params[key] === null) {
                delete params[key];
            }
        });
        if (Object.keys(params).some(function (key) { return isNaN(params[key]); })) {
            if (message) {
                message.textContent = text('save_failed', '保存失败');
            }
            toast('error', text('save_failed', '保存失败'));
            return;
        }
        if (button) {
            button.disabled = true;
        }
        if (message) {
            message.textContent = '';
        }

        api().saveStorefrontWarmupSettings(params).then(function (response) {
            var payload = unwrap(response);
            if (!payload || payload.success !== true) {
                var detail = payload && payload.errors && payload.errors.length ? payload.errors.join('；') : (payload && payload.message);
                throw new Error(detail || text('save_failed', '保存失败'));
            }
            if (payload.status) {
                render(payload.status);
            }
            if (message) {
                message.textContent = payload.message || text('saved', '已保存');
            }
            toast('success', payload.message || text('saved', '已保存'));
            closeSettings();
        }).catch(function (error) {
            var detail = error && error.message ? error.message : text('save_failed', '保存失败');
            if (message) {
                message.textContent = detail;
            }
            toast('error', detail);
        }).finally(function () {
            if (button) {
                button.disabled = false;
            }
        });
    }

    function startPolling() {
        window.clearInterval(pollTimer);
        pollTimer = window.setInterval(function () {
            if (!document.hidden) {
                refresh();
            }
        }, 5000);
    }

    function init() {
        var openButton = byId('sfw-open-settings');
        if (openButton) {
            openButton.addEventListener('click', openSettings);
        }
        var form = byId('sfw-settings-form');
        if (form) {
            form.addEventListener('submit', saveSettings);
        }
        document.querySelectorAll('#sfw-settings [data-w-action="dialog.close"]').forEach(function (button) {
            button.addEventListener('click', closeSettings);
        });
        refresh();
        startPolling();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // 验收/排障入口：手动触发一次刷新，不必等 5 秒轮询。
    window.WelineStorefrontWarmup = {
        refresh: refresh,
        render: render
    };
})(window, document);
