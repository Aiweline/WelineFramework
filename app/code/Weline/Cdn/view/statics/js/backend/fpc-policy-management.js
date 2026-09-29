/* CDN 管理页仅通过 Weline.Api 调用冻结的 Query 操作。 */
(function () {
    'use strict';
    const forbidden = row => row.code_enabled === false || Number(row.code_ttl) === 0;
    const own = (object, key) => Object.prototype.hasOwnProperty.call(object, key);
    function buildChanges(row, draft) {
        const enabled = draft.inheritEnabled ? null : !!draft.enabled;
        let ttl = null;
        if (!draft.inheritTtl) {
            const raw = String(draft.ttl).trim();
            ttl = Number(raw);
            if (!/^\d+$/.test(raw) || !Number.isSafeInteger(ttl) || ttl < 1) throw new Error('ttl');
        }
        const changes = {};
        if (enabled !== (row.override_enabled ?? null)) changes.enabled = enabled;
        if (ttl !== (row.override_ttl ?? null)) changes.ttl = ttl;
        if (changes.enabled === true && forbidden(row)) throw new Error('code');
        return changes;
    }
    function receiptProven(receipt, stage) {
        if (!receipt || typeof receipt !== 'object') return false;
        if (receipt.success === false || receipt.accepted === false || receipt.verified === false) return false;
        if (stage === 'purge' && receipt.targets && Object.keys(receipt.targets).length) {
            return Object.values(receipt.targets).every(target => Number(target.accepted_version) > 0);
        }
        return receipt.success === true || receipt.accepted === true || (stage === 'verified' && receipt.verified === true);
    }
    function stageFacts(row) {
        const desired = Number(row.desired_version) || 0;
        const originOnly = row.job_key === 'origin' && Array.isArray(row.domain_ids) && row.domain_ids.length === 0;
        const facts = {};
        for (const [stage, key, receipt] of [
            ['origin', 'origin_version', null], ['cloud', 'cloud_version', row.cloud_receipt],
            ['purge', 'purge_version', row.purge_receipt], ['verified', 'verified_version', row.http_verification]
        ]) {
            const version = Number(row[key]) || 0;
            facts[stage] = {
                version, pending: desired > version,
                proven: version > 0 && (stage === 'origin' || receiptProven(receipt, stage)),
                notApplicable: originOnly && (stage === 'cloud' || stage === 'purge'),
                failed: stage === 'verified' && !!receipt && receipt.status !== 'no_public_sample' && (receipt.success === false || receipt.verified === false)
            };
        }
        return facts;
    }
    function snapshotFacts(row) {
        return {version: row.snapshot_version ?? null, revision: row.snapshot_revision ?? null, error: row.snapshot_error ?? null};
    }
    const module = {buildChanges, stageFacts, snapshotFacts};
    window.WelineCdnFpcPolicyModule = module;
    const root = document.querySelector('[data-cdn-fpc-management]');
    if (!root || root.dataset.cdnMounted === 'true') return;
    root.dataset.cdnMounted = 'true';
    const $ = selector => root.querySelector(selector);
    const texts = JSON.parse(root.dataset.cdnI18n || '{}');
    const t = (key, ...values) => values.reduce((text, value, index) => text.replaceAll('%{' + (index + 1) + '}', String(value)), texts[key] || key);
    const h = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));
    const line = (value, muted = false) => '<p class="w-text"' + (muted ? ' data-tone="muted" data-size="sm"' : '') + '>' + h(value) + '</p>';
    const button = (action, label, attributes = '') => '<button type="button" class="w-button" data-tone="neutral" data-variant="outline" data-size="sm" data-cdn-action="' + action + '" ' + attributes + '>' + h(label) + '</button>';
    const scopeRoot = $('#cdn-fpc-scope_container');
    const scopeField = $('#cdn-fpc-scope');
    const modeField = $('#cdn-fpc-mode_value');
    const keywordField = $('#cdn-fpc-keyword');
    const dialog = $('#cdn-fpc-editor');
    const form = $('[data-cdn-editor-form]');
    const input = name => form.elements.namedItem(name);
    const notice = $('[data-cdn-notice]');
    const state = {tab: ['fpc', 'cdn', 'sync'].includes(root.dataset.tab) ? root.dataset.tab : 'fpc', epoch: 0, sequence: {fpc: 0, sync: 0}, pages: {fpc: 1, sync: 1}, rows: [], editor: null};
    const context = () => ({target_scope: String(scopeField?.value || 'default.default.default'), store_mode: String(modeField?.value || 'normal')});
    const sameContext = captured => {
        const current = context();
        return captured.target_scope === current.target_scope && captured.store_mode === current.store_mode;
    };
    function scopeLabel() {
        const tree = document.getElementById('cdn-fpc-scope_tree');
        const selected = Array.from(tree?.querySelectorAll('[data-w-scope-node]') || []).find(node => node.dataset.value === context().target_scope);
        if (selected) return selected.dataset.titleLabel || selected.dataset.displayLabel || selected.dataset.label;
        const label = scopeRoot?.querySelector('[data-w-scope-label], .w-search-select-display, .w-tree-select-display');
        return label?.textContent.trim() || context().target_scope;
    }
    function contextLabel() { return scopeLabel() + ' · ' + t(context().store_mode === 'test' ? 'test' : 'normal'); }
    function sourceLabel(source) {
        if (source === 'code') return t('code');
        const kind = String(source || '').split('|')[0];
        return ['global', 'website', 'store', 'channel'].includes(kind) ? t(kind) : String(source || '—');
    }
    const statusLabel = value => ['pending', 'running', 'success', 'error'].includes(value) ? t(value) : String(value || '—');
    function showNotice(message, error = false) {
        notice.textContent = String(message || t('failure'));
        notice.dataset.tone = error ? 'danger' : 'info';
        notice.setAttribute('role', error ? 'alert' : 'status');
        notice.hidden = false;
    }
    function showEditorError(error) {
        const target = $('[data-cdn-editor-error]');
        target.textContent = errorText(error);
        target.hidden = false;
    }
    function errorText(error) { return String(error?.message || t('failure')) + (error?.error_code ? ' (' + error.error_code + ')' : ''); }
    let apiPromise;
    function api() {
        if (!apiPromise) apiPromise = Promise.resolve(window.Weline?.load ? window.Weline.load('api') : window.Weline?.Api).then(value => {
            const service = value?.resource ? value : window.Weline?.Api;
            if (!service?.resource) throw new Error(t('transportMissing'));
            return service.resource('cdn');
        }).catch(error => { apiPromise = null; throw error; });
        return apiPromise;
    }
    async function request(operation, params) {
        const resource = await api();
        const result = await resource[operation](params);
        if (!result || result.success === false) {
            const error = new Error(result?.message || t('failure'));
            error.error_code = result?.error_code;
            throw error;
        }
        return result;
    }
    async function busy(control, operation) {
        if (control?.dataset.busy === 'true') return;
        const label = control?.querySelector('[data-cdn-main-label]') || control;
        const original = label?.textContent;
        if (control) { control.dataset.busy = 'true'; control.disabled = true; control.setAttribute('aria-busy', 'true'); label.textContent = t(control.hasAttribute('data-cdn-save') ? 'saving' : 'loading'); }
        try { return await operation(); }
        finally {
            if (control) { delete control.dataset.busy; control.disabled = false; control.removeAttribute('aria-busy'); label.textContent = original; }
            updateMainAction();
        }
    }
    function updateUrl() {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', state.tab);
        const current = context();
        url.searchParams.set('target_scope', current.target_scope);
        url.searchParams.set('store_mode', current.store_mode);
        history.replaceState(null, '', url);
    }
    function updateMainAction() {
        const main = $('[data-cdn-action="main"]');
        main.hidden = state.tab !== 'sync' && main.dataset.canCollect !== 'true';
        if (main.dataset.busy !== 'true') $('[data-cdn-main-label]').textContent = t(state.tab === 'fpc' ? 'collectFpc' : state.tab === 'cdn' ? 'collectCdn' : 'refreshSync');
        $('[data-cdn-context-card]').hidden = state.tab === 'cdn';
        $('[data-cdn-context-summary]').textContent = t('snapshot') + ': ' + contextLabel();
    }
    function closeEditor() {
        state.editor = null;
        window.Weline?.UI?.dialog?.close(dialog, 'cancel');
    }
    function changeTab(tab) {
        if (!['fpc', 'cdn', 'sync'].includes(tab)) tab = 'fpc';
        if (state.tab === tab) return;
        closeEditor();
        state.tab = tab;
        $('[data-cdn-versions]').textContent = '';
        updateMainAction();
        updateUrl();
        if (tab !== 'cdn') load(tab);
    }
    function selectTab(tab) {
        const target = $('#cdn-tab-' + tab);
        if (window.Weline?.UI?.tabs?.select) window.Weline.UI.tabs.select($('[data-cdn-tabs]'), target);
        else target.click();
        changeTab(tab);
    }
    function changeContext() {
        closeEditor();
        state.epoch++;
        state.pages.fpc = state.pages.sync = 1;
        state.rows = [];
        $('[data-cdn-versions]').textContent = '';
        for (const tab of ['fpc', 'sync']) { $('[data-cdn-rows="' + tab + '"]').replaceChildren(); $('[data-cdn-pager="' + tab + '"]').replaceChildren(); }
        notice.hidden = true;
        updateMainAction(); updateUrl();
        if (state.tab !== 'cdn') load(state.tab);
    }
    function renderPager(tab, data) {
        const total = Math.max(0, Number(data.total) || 0);
        const size = Math.max(1, Number(data.page_size) || 20);
        const pages = Math.max(1, Math.ceil(total / size));
        const page = Math.max(1, Number(data.page) || 1);
        state.pages[tab] = page;
        $('[data-cdn-pager="' + tab + '"]').innerHTML = '<span class="w-text" data-tone="muted" data-size="sm">' + h(t('total', total)) + ' · ' + h(t('page', page, pages)) + '</span><nav aria-label="' + h(t(tab)) + '"><ul class="w-pagination">' +
            [[page - 1, t('previous'), page <= 1], [page + 1, t('next'), page >= pages]].map(([destination, label, disabled]) => '<li class="w-pagination__item"><button type="button" class="w-pagination__link" data-cdn-action="page" data-tab="' + tab + '" data-page="' + destination + '"' + (disabled ? ' disabled aria-disabled="true"' : '') + '>' + h(label) + '</button></li>').join('') + '</ul></nav>';
    }
    function detail(label, value) { return line(label + ': ' + value, true); }
    function snapshotDetails(row) {
        const snapshot = snapshotFacts(row);
        return detail(t('snapshotVersion'), snapshot.version === null ? t('unavailable') : 'v' + snapshot.version) +
            detail(t('snapshotRevision'), snapshot.revision || t('unavailable')) +
            (snapshot.error ? detail(t('snapshotError'), snapshot.error) : '');
    }
    function renderPolicy(row) {
        const blocked = forbidden(row);
        const id = h(row.declaration_id);
        const paths = Array.isArray(row.public_path_patterns) && row.public_path_patterns.length ? row.public_path_patterns : [row.path_pattern || '—'];
        const enabled = row.override_enabled == null ? t('inherit') : t(row.override_enabled ? 'on' : 'off');
        const ttl = row.override_ttl == null ? t('inherit') : t('seconds', row.override_ttl);
        const details = detail(t('identity'), row.declaration_id) + detail(t('path'), paths.join('\n')) + detail(t('codeEnabled'), t(row.code_enabled ? 'on' : 'off')) + detail(t('codeTtl'), t('seconds', row.code_ttl)) + detail(t('enabled') + ' / ' + t('source'), row.enabled_source) + detail('TTL / ' + t('source'), row.ttl_source) + detail(t('effective'), t('seconds', row.effective_ttl)) + detail(t('fingerprint'), row.policy_fingerprint) + snapshotDetails(row);
        return '<tr data-declaration-id="' + id + '"><td><div class="w-stack">' +
            '<strong class="w-text cdn-fpc-path">' + h(row.class) + '::' + h(row.method) + '()</strong>' + line(row.module, true) + '<p class="w-text cdn-fpc-path" data-size="sm">' + h(paths[0]) + '</p>' + (paths.length > 1 ? line(t('otherPaths', paths.length - 1), true) : '') +
            (row.active === false ? line(t('withdrawn')) : '') + '<details class="w-disclosure"><summary>' + h(t('details')) + '</summary><div class="cdn-fpc-path">' + details + '</div></details></div></td>' +
            '<td><div class="w-stack">' + line(t(row.effective_enabled ? 'on' : 'off')) + line(t('layer') + ': ' + enabled, true) + line(t('source') + ': ' + sourceLabel(row.enabled_source), true) + (blocked ? line(t('codeBlocked'), true) : '') + '</div></td>' +
            '<td><div class="w-stack">' + line(row.effective_enabled ? t('seconds', row.effective_ttl) : t('uncached')) + line(t('layer') + ': ' + ttl, true) + line(t('source') + ': ' + sourceLabel(row.ttl_source), true) + '</div></td>' +
            '<td><div class="w-stack">' + line(statusLabel(row.sync_status)) + line(t('desired') + ' v' + row.desired_version + ' · ' + t('origin') + ' v' + row.origin_version, true) + (row.last_error ? line(row.last_error, true) : '') + '</div></td>' +
            '<td><div class="w-stack">' + (root.dataset.canEdit === 'true' && row.active !== false ? button('edit', t('edit'), 'data-declaration-id="' + id + '"') : line(t('readOnly'), true)) + button('sync', t('sync')) + '</div></td></tr>';
    }
    function stageLine(row, facts, name) {
        const fact = facts[name];
        if (fact.notApplicable) return line(t('noCdn'), true);
        const complete = {origin: 'published', cloud: 'accepted', purge: 'purged', verified: 'verified'};
        const absent = {origin: 'notPublished', cloud: 'notAccepted', purge: 'notPurged', verified: 'notVerified'};
        return '<div class="w-stack">' + line(t(fact.failed ? 'verifyFailed' : fact.proven ? complete[name] : absent[name], fact.version)) +
            (fact.pending && fact.proven ? line(t('pendingVersion', row.desired_version), true) : '') + '</div>';
    }
    function renderSync(row) {
        const facts = stageFacts(row);
        const target = row.job_key === 'origin' ? t('originOnly') : [row.adapter, row.zone_id].filter(Boolean).join(' / ');
        const receipt = JSON.stringify({cloud_receipt: row.cloud_receipt, purge_receipt: row.purge_receipt, http_verification: row.http_verification, ...(own(row, 'pending_targets') ? {pending_targets: row.pending_targets} : {})}, null, 2);
        const details = detail(t('domains'), (row.domain_ids || []).join(', ')) + detail(t('jobId'), row.job_key) + detail(t('desired'), row.desired_version) + detail(t('origin'), row.origin_version) + snapshotDetails(row) + detail(t('cloudStage'), row.cloud_version) + detail(t('purgeStage'), row.purge_version) + detail(t('verifyVersion'), facts.verified.proven ? 'v' + row.verified_version : t('notVerified')) + line(t('httpSampleHint'), true) + '<pre>' + h(receipt) + '</pre>';
        return '<tr><td><div class="w-stack">' + line(target) + line(t('domains') + ': ' + (row.domain_ids || []).join(', '), true) + '<details class="w-disclosure"><summary>' + h(t('receipts')) + '</summary><div class="cdn-fpc-path">' + details + '</div></details></div></td>' +
            '<td><div class="w-stack">' + line(t('desired') + ' v' + row.desired_version) + line(statusLabel(row.status), true) + (row.last_error ? line(row.last_error, true) : '') + '</div></td>' +
            ['origin', 'cloud', 'purge', 'verified'].map(stage => '<td>' + stageLine(row, facts, stage) + '</td>').join('') +
            '<td>' + h(row.updated_at || '—') + '</td><td>' + (root.dataset.canRetry === 'true' ? button('retry', t('retry'), 'data-sync-id="' + h(row.sync_id) + '"') : '') + '</td></tr>';
    }
    async function load(tab = state.tab) {
        if (tab === 'cdn') return;
        const epoch = state.epoch;
        const sequence = ++state.sequence[tab];
        const captured = context();
        const loading = $('[data-cdn-loading="' + tab + '"]');
        const table = $('[data-cdn-table="' + tab + '"]');
        const rows = $('[data-cdn-rows="' + tab + '"]');
        loading.textContent = t('loading'); loading.hidden = false;
        table.setAttribute('aria-busy', 'true');
        rows.replaceChildren(); $('[data-cdn-pager="' + tab + '"]').replaceChildren();
        if (tab === 'fpc') state.rows = [];
        try {
            const result = await request(tab === 'fpc' ? 'listFpcPolicies' : 'listFpcSyncRecords', {...captured, keyword: keywordField.value.trim(), page: state.pages[tab], page_size: 20});
            if (epoch !== state.epoch || sequence !== state.sequence[tab] || !sameContext(captured)) return;
            const data = result.data || {};
            const items = Array.isArray(data.items) ? data.items : [];
            if (tab === 'fpc') state.rows = items;
            rows.innerHTML = items.length ? items.map(tab === 'fpc' ? renderPolicy : renderSync).join('') : '<tr><td colspan="' + (tab === 'fpc' ? 5 : 8) + '">' + h(t(tab === 'sync' ? 'emptySync' : keywordField.value.trim() ? 'noMatch' : 'empty')) + '</td></tr>';
            renderPager(tab, data);
            if (tab === 'fpc' && state.tab === 'fpc') $('[data-cdn-versions]').textContent = t('desired') + ' v' + (data.desired_version ?? 0) + ' · ' + t('origin') + ' v' + (data.origin_version ?? 0);
            loading.hidden = true;
            return data;
        } catch (error) {
            if (epoch !== state.epoch || sequence !== state.sequence[tab]) return;
            loading.textContent = t('refreshError');
            showNotice(errorText(error), true);
        } finally {
            if (sequence === state.sequence[tab]) table.removeAttribute('aria-busy');
        }
    }
    function draft() { return {inheritEnabled: input('inherit_enabled').checked, enabled: input('enabled').checked, inheritTtl: input('inherit_ttl').checked, ttl: input('ttl').value}; }
    function updateEditorControls() {
        if (!state.editor) return;
        const row = state.editor.row;
        const writing = state.editor.writing;
        input('enabled').disabled = writing || input('inherit_enabled').checked || forbidden(row);
        input('ttl').readOnly = input('inherit_ttl').checked;
        input('ttl').disabled = writing;
        input('inherit_enabled').disabled = input('inherit_ttl').disabled = writing;
        for (const control of form.querySelectorAll('button')) control.disabled = writing;
        for (const control of form.querySelectorAll('[data-cdn-action="restore"]')) {
            const fields = control.dataset.fields.split(',');
            control.hidden = !fields.some(field => row['override_' + field] != null);
        }
    }
    function editorHints() {
        const row = state.editor.row;
        $('[data-cdn-enabled-hint]').textContent = t('effective') + ': ' + t(row.effective_enabled ? 'on' : 'off') + ' · ' + t('source') + ': ' + sourceLabel(row.enabled_source);
        $('[data-cdn-ttl-hint]').textContent = t('effective') + ': ' + t('seconds', row.effective_ttl) + ' · ' + t('source') + ': ' + sourceLabel(row.ttl_source);
    }
    function openEditor(id) {
        const row = state.rows.find(value => value.declaration_id === id);
        if (!row || row.active === false || root.dataset.canEdit !== 'true') return;
        state.editor = {row, context: Object.freeze({...context()}), label: contextLabel(), writing: false};
        input('inherit_enabled').checked = row.override_enabled == null;
        input('enabled').checked = row.override_enabled ?? row.effective_enabled;
        input('inherit_ttl').checked = row.override_ttl == null;
        input('ttl').value = row.override_ttl ?? row.effective_ttl;
        $('[data-cdn-editor-context]').textContent = row.class + '::' + row.method + '() · ' + state.editor.label;
        $('[data-cdn-code-blocked]').hidden = !forbidden(row);
        $('[data-cdn-editor-error]').hidden = true;
        $('#cdn-fpc-ttl-error').hidden = true;
        input('ttl').removeAttribute('aria-invalid');
        editorHints(); updateEditorControls();
        window.Weline?.UI?.dialog?.open(dialog);
    }
    function changeFeedback(result, key) {
        const data = result.data || {};
        return t(data.changed === false ? 'unchanged' : key) + ' ' + t('desired') + ' v' + (data.desired_version ?? 0) + ' · ' + t('origin') + ' v' + (data.origin_version ?? 0);
    }
    async function writeEditor(control, fields) {
        const editor = state.editor;
        if (!editor || editor.writing || !sameContext(editor.context)) return;
        $('[data-cdn-editor-error]').hidden = true;
        $('#cdn-fpc-ttl-error').hidden = true;
        input('ttl').removeAttribute('aria-invalid');
        let changes;
        try { changes = fields ? {fields} : buildChanges(editor.row, draft()); }
        catch (error) {
            if (error.message === 'ttl') { $('#cdn-fpc-ttl-error').hidden = false; input('ttl').setAttribute('aria-invalid', 'true'); input('ttl').focus(); }
            else showEditorError(new Error(t('codeBlocked')));
            return;
        }
        if (!fields && Object.keys(changes).length === 0) { showNotice(t('unchanged')); return; }
        editor.writing = true;
        updateEditorControls();
        await busy(control, async () => {
            try {
                const result = await request(fields ? 'restoreFpcPolicyInheritance' : 'saveFpcPolicyOverride', {...editor.context, declaration_id: editor.row.declaration_id, ...changes});
                if (state.editor !== editor || !sameContext(editor.context)) return;
                showNotice(changeFeedback(result, fields ? 'restored' : 'saved'));
                if (!fields) { closeEditor(); await load('fpc'); return; }
                // 单字段恢复后保留另一个字段尚未提交的输入。
                await load('fpc');
                if (state.editor !== editor || !sameContext(editor.context)) return;
                const updated = state.rows.find(row => row.declaration_id === editor.row.declaration_id);
                if (updated) {
                    editor.row = updated;
                    if (fields.includes('enabled')) { input('inherit_enabled').checked = true; input('enabled').checked = updated.effective_enabled; }
                    if (fields.includes('ttl')) { input('inherit_ttl').checked = true; input('ttl').value = updated.effective_ttl; }
                    editorHints();
                }
            } catch (error) { if (state.editor === editor) showEditorError(error); }
            finally { editor.writing = false; if (state.editor === editor) updateEditorControls(); }
        });
    }
    async function mainAction(control) {
        if (state.tab === 'sync') { await busy(control, () => load('sync')); return; }
        const tab = state.tab;
        const captured = {...context()};
        if (tab === 'cdn') {
            const result = await window.Weline.UI.dialog.request({title: t('confirmCollect'), message: t('collectQuestion'), tone: 'info', cancelable: true, confirmLabel: t('confirm'), cancelLabel: t('cancel')});
            if (!result.confirmed) return;
        }
        await busy(control, async () => {
            try {
                const result = await request(tab === 'fpc' ? 'collectFpcPolicies' : 'collectApiRules', captured);
                if (tab === 'cdn') { showNotice(result.message || t('collected')); window.location.reload(); }
                else if (sameContext(captured)) { showNotice(changeFeedback(result, 'collected')); await load('fpc'); }
            } catch (error) { showNotice(errorText(error), true); }
        });
    }
    function legacyFilters() {
        const url = new URL(root.dataset.indexUrl, window.location.href);
        url.searchParams.set('tab', 'cdn');
        for (const [key, field] of [['module', $('#cdn-module-filter_value')], ['trigger', $('#cdn-trigger-filter_value')]]) if (field?.value) url.searchParams.set(key, field.value);
        for (const [key, value] of Object.entries(context())) url.searchParams.set(key, value);
        window.location.href = url.href;
    }
    async function toggleRule(control) {
        const enabled = control.checked;
        await busy(control, async () => {
            try { await request('toggleApiRule', {id: control.dataset.ruleId, enabled: enabled ? 1 : 0}); }
            catch (error) { control.checked = !enabled; showNotice(errorText(error), true); }
        });
    }
    async function deleteRule(control) {
        const confirmation = await window.Weline.UI.dialog.request({title: t('confirmDelete'), message: t('deleteQuestion'), tone: 'warning', cancelable: true, confirmLabel: t('delete'), cancelLabel: t('cancel'), confirmTone: 'danger'});
        if (!confirmation.confirmed) return;
        await busy(control, async () => {
            try { const result = await request('deleteApiRule', {id: control.dataset.ruleId}); showNotice(result.message); control.closest('[data-cdn-rule]').remove(); }
            catch (error) { showNotice(errorText(error), true); }
        });
    }
    form.addEventListener('submit', event => { event.preventDefault(); writeEditor($('[data-cdn-save]')); });
    form.addEventListener('change', event => {
        if (event.target === input('inherit_enabled') && input('inherit_enabled').checked && state.editor) input('enabled').checked = state.editor.row.effective_enabled;
        if (event.target === input('inherit_ttl') && input('inherit_ttl').checked && state.editor) input('ttl').value = state.editor.row.effective_ttl;
        updateEditorControls();
    });
    dialog.addEventListener('close', () => { state.editor = null; });
    $('[data-cdn-tabs]').addEventListener('weline:ui:tabs:change', event => { if (event.detail?.tab) changeTab(event.detail.tab.dataset.cdnTab); });
    scopeField?.addEventListener('change', changeContext);
    modeField?.addEventListener('change', changeContext);
    $('#cdn-module-filter_value')?.addEventListener('change', legacyFilters);
    $('#cdn-trigger-filter_value')?.addEventListener('change', legacyFilters);
    keywordField.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); state.pages[state.tab] = 1; load(); } });
    // Weline.UI 把打开的 dialog 放入 body；委托同时识别本页与该既有浮层。
    document.addEventListener('click', event => {
        const control = event.target.closest('[data-cdn-action]');
        if (!control || (!root.contains(control) && !dialog.contains(control)) || control.disabled) return;
        const action = control.dataset.cdnAction;
        if (action === 'main') mainAction(control);
        else if (action === 'query') { state.pages[state.tab] = 1; busy(control, () => load()); }
        else if (action === 'refresh') busy(control, () => load());
        else if (action === 'page') { state.pages[control.dataset.tab] = Number(control.dataset.page); busy(control, () => load(control.dataset.tab)); }
        else if (action === 'edit') openEditor(control.dataset.declarationId);
        else if (action === 'cancel') closeEditor();
        else if (action === 'restore') writeEditor(control, control.dataset.fields.split(','));
        else if (action === 'sync') selectTab('sync');
        else if (action === 'retry') busy(control, async () => {
            const captured = context();
            try { await request('retryFpcSync', {sync_id: Number(control.dataset.syncId)}); if (sameContext(captured)) { showNotice(t('retryQueued')); await load('sync'); } }
            catch (error) { showNotice(errorText(error), true); }
        });
        else if (action === 'view-rule') { const url = new URL(root.dataset.viewUrl, window.location.href); url.searchParams.set('id', control.dataset.ruleId); window.location.href = url.href; }
        else if (action === 'delete-rule') deleteRule(control);
    });
    root.addEventListener('change', event => { const control = event.target.closest('[data-cdn-action="toggle-rule"]'); if (control && !control.disabled) toggleRule(control); });
    updateMainAction();
    if (state.tab !== 'cdn') load();
})();
