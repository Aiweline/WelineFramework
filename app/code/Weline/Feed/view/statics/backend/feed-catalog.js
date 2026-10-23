/**
 * 购物目录后台列表。
 *
 * provider：feed_admin。
 * operation：listFeeds、saveFeed、setFeedEnabled、archiveFeed、generateFeed、
 * listCategoryMaps、saveCategoryMap、deleteCategoryMap。
 * 请求失败只展示错误，不用失败回包改写 connection_status。
 */
(function () {
    'use strict';

    window.WelineFeedCatalog = { ready: true };

    var PROVIDER = 'feed_admin';
    var SECRET_KEYS = {
        credentials: true,
        client_secret: true,
        clientSecret: true,
        access_token: true,
        refresh_token: true,
        developer_token: true,
        developerToken: true,
        secret: true,
        token: true
    };
    var configEl = document.getElementById('feed-catalog-config');
    var root = document.querySelector('[data-feed-catalog]');
    if (!configEl || !root) {
        return;
    }

    var config;
    try {
        config = JSON.parse(configEl.textContent || '{}');
    } catch (error) {
        return;
    }
    var text = config.text || {};
    var channels = Array.isArray(config.channels) ? config.channels : [];
    var channelNames = {};
    channels.forEach(function (channel) {
        channelNames[String(channel.code || '')] = String(channel.name || '');
    });

    var rowsEl = root.querySelector('[data-feed-rows]');
    var pagerEl = root.querySelector('[data-feed-pager]');
    var errorEl = root.querySelector('[data-feed-error]');
    var form = document.getElementById('feed-catalog-form');
    var formDialog = document.getElementById('feed-catalog-dialog');
    var categoryDialog = document.getElementById('feed-catalog-category-dialog');
    var disableDialog = document.getElementById('feed-catalog-disable-dialog');
    var archiveDialog = document.getElementById('feed-catalog-archive-dialog');
    var formTitle = root.querySelector('[data-feed-form-title]');
    var formStatus = root.querySelector('[data-feed-form-status]');
    var credentialHint = root.querySelector('[data-feed-credential-hint]');
    var microsoftField = root.querySelector('[data-feed-microsoft]');
    var channelFieldset = root.querySelector('[data-feed-channels]');
    var categoryStatus = root.querySelector('[data-feed-category-status]');
    var categoryRows = root.querySelector('[data-feed-category-rows]');
    var categorySubtitle = root.querySelector('[data-feed-category-subtitle]');
    var unmappedButton = root.querySelector('[data-feed-action="toggle-unmapped"]');
    var oauthFields = root.querySelector('[data-feed-oauth]');
    var apiKeyField = root.querySelector('[data-feed-apikey]');
    var sitePicker = root.querySelector('[data-feed-site-picker]');
    var scopeLock = root.querySelector('[data-feed-scope-lock]');

    var state = {
        page: 1,
        pageSize: Number(config.pageSize) || 20,
        total: null,
        name: '',
        channel: '',
        website: '',
        rows: [],
        pendingId: '',
        categoryWebsiteId: '',
        categoryVocabulary: '',
        categoryLocale: '',
        onlyUnmapped: false
    };

    function notify(kind, message) {
        var value = String(message || '').trim();
        if (!value) {
            return;
        }
        var toast = window.Weline && window.Weline.UI && window.Weline.UI.toast;
        if (toast && typeof toast[kind] === 'function') {
            toast[kind](value);
            return;
        }
        if (toast && typeof toast.show === 'function') {
            toast.show(value, { tone: kind === 'success' ? 'success' : 'danger' });
        }
    }

    function errorMessage(error) {
        var formatter = window.Weline && window.Weline.ApiBusiness && window.Weline.ApiBusiness.formatApiError;
        if (typeof formatter === 'function') {
            var formatted = formatter(error);
            if (formatted) {
                return String(formatted);
            }
        }
        var raw = error && error.message ? String(error.message) : '';
        if (raw.indexOf('feed_site_scope_empty') !== -1) {
            return text.scopeEmpty || raw;
        }
        if (raw) {
            return raw;
        }
        return text.queryFailed || '';
    }

    function scrubText(value) {
        return String(value == null ? '' : value)
            .replace(/(client_secret|access_token|refresh_token|developer_token|credentials)\s*[:=]\s*\S+/gi, '$1=已省略');
    }

    function scrubPublicUrl(value) {
        var url = String(value || '').trim();
        if (!url) {
            return '';
        }
        try {
            var parsed = new URL(url, window.location.origin);
            ['client_secret', 'access_token', 'refresh_token', 'developer_token', 'credentials'].forEach(function (key) {
                parsed.searchParams.delete(key);
            });
            if (/^https?:\/\//i.test(url)) {
                return parsed.toString();
            }
            return parsed.pathname + parsed.search + parsed.hash;
        } catch (error) {
            return url;
        }
    }

    function scrubRow(row) {
        var copy = {};
        if (!row || typeof row !== 'object') {
            return copy;
        }
        Object.keys(row).forEach(function (key) {
            if (SECRET_KEYS[key]) {
                return;
            }
            copy[key] = row[key];
        });
        if (copy.public_url) {
            copy.public_url = scrubPublicUrl(copy.public_url);
        }
        if (copy.last_generation && typeof copy.last_generation === 'object') {
            copy.last_generation = {
                state: copy.last_generation.state || copy.last_generation.status || '',
                at: copy.last_generation.at || copy.last_generation.time || '',
                message: scrubText(copy.last_generation.message || copy.last_generation.reason || '')
            };
        }
        return copy;
    }

    function feedCatalogApi() {
        if (!window.Weline || typeof window.Weline.load !== 'function') {
            return Promise.reject(new Error(text.queryUnpublished || ''));
        }
        return window.Weline.load('api').then(function (api) {
            var client = api && typeof api.resource === 'function' ? api : window.Weline.Api;
            if (!client || typeof client.resource !== 'function') {
                throw new Error(text.queryUnpublished || '');
            }
            return client.resource(PROVIDER);
        });
    }

    function callOp(operation, params) {
        return feedCatalogApi().then(function (resource) {
            var fn = resource[operation];
            if (typeof fn !== 'function') {
                throw new Error((text.queryUnpublished || '') + ' (' + operation + ')');
            }
            return fn.call(resource, params || {});
        });
    }

    function payloadOf(res) {
        if (!res || typeof res !== 'object') {
            throw new Error(text.queryFailed || '');
        }
        if (res.success === false) {
            var reason = res.message || (Array.isArray(res.errors) ? res.errors.join(' ') : '');
            throw new Error(scrubText(reason || text.queryFailed || ''));
        }
        if (res.data && typeof res.data === 'object') {
            return res.data;
        }
        return res;
    }

    function listFeeds(params) {
        return callOp('listFeeds', params).then(payloadOf);
    }

    function saveFeed(payload) {
        return callOp('saveFeed', payload).then(payloadOf);
    }

    function setFeedEnabled(id, enabled) {
        return callOp('setFeedEnabled', { definition_id: id, enabled: enabled ? 1 : 0 }).then(payloadOf);
    }

    function archiveFeed(id) {
        return callOp('archiveFeed', { definition_id: id }).then(payloadOf);
    }

    function generateFeed(id) {
        return callOp('generateFeed', { definition_id: id }).then(payloadOf);
    }

    function listCategoryMaps(params) {
        return callOp('listCategoryMaps', params).then(payloadOf);
    }

    function saveCategoryMap(payload) {
        return callOp('saveCategoryMap', payload).then(payloadOf);
    }

    function deleteCategoryMap(mapId) {
        return callOp('deleteCategoryMap', { map_id: mapId }).then(payloadOf);
    }

    function openDialog(dialog) {
        var ui = window.Weline && window.Weline.UI && window.Weline.UI.dialog;
        if (ui && typeof ui.open === 'function') {
            ui.open(dialog);
            return;
        }
        dialog.hidden = false;
        dialog.setAttribute('data-state', 'open');
        dialog.setAttribute('aria-hidden', 'false');
    }

    function closeDialog(dialog) {
        var ui = window.Weline && window.Weline.UI && window.Weline.UI.dialog;
        if (ui && typeof ui.close === 'function') {
            ui.close(dialog);
            return;
        }
        dialog.hidden = true;
        dialog.setAttribute('data-state', 'closed');
        dialog.setAttribute('aria-hidden', 'true');
    }

    function setBanner(message) {
        if (!errorEl) {
            return;
        }
        var value = String(message || '').trim();
        errorEl.hidden = value === '';
        errorEl.textContent = value;
    }

    function setFormStatus(message) {
        if (formStatus) {
            formStatus.textContent = message || '';
        }
    }

    function rowId(row) {
        if (!row) {
            return '';
        }
        var id = row.definition_id != null ? row.definition_id : row.id;
        if (id == null || id === '') {
            return '';
        }
        return String(id);
    }

    function connectionLabel(row) {
        return String((row && row.connection_status) || '').trim();
    }

    function missingSecret(row) {
        return connectionLabel(row) === '' || connectionLabel(row) === '未连接';
    }

    function isDisabled(row) {
        return !!(row && (row.enabled === false || row.enabled === 0 || row.enabled === '0' || row.is_enabled === false));
    }

    function channelName(row) {
        if (row && row.channel_name) {
            return String(row.channel_name);
        }
        var code = String((row && row.channel_code) || '');
        return channelNames[code] || code;
    }

    function vocabularyFor(code) {
        if (code === 'naver_shopping') {
            return 'naver';
        }
        if (code === 'yandex_market') {
            return 'yandex';
        }
        if (code === 'vk_ads') {
            return '';
        }
        return 'google';
    }

    function diagnosticText(code) {
        var labels = text.diagnosticLabels || {};
        return Object.prototype.hasOwnProperty.call(labels, code) ? String(labels[code]) : code;
    }

    function generationText(row) {
        var error = scrubText(row && row.error_code);
        var at = String((row && row.last_generated_at) || '').trim();
        if (error) {
            return ((text.genFailed || '') + ' ' + error).trim();
        }
        if (at || (row && row.generated_ok === true)) {
            var line = text.genSuccess || '';
            if (at) {
                line += ' ' + at;
            }
            if (row && row.item_count != null && row.item_count !== '') {
                line += ' ' + row.item_count;
            }
            if (row && row.skipped_count) {
                line += '/' + row.skipped_count;
            }
            var diagnostic = scrubText(row && row.diagnostic_code);
            if (diagnostic) {
                line += ' · ' + diagnosticText(diagnostic);
            }
            return line.trim();
        }
        if (row && row.public_label) {
            return String(row.public_label);
        }
        return text.notGenerated || '';
    }

    function publicAddress(row) {
        return scrubPublicUrl(row && (row.public_url || row.public_address));
    }

    function localeText(row) {
        return [row && row.locale, row && row.country, row && row.currency]
            .map(function (part) { return String(part || '').trim(); })
            .filter(Boolean)
            .join(' / ');
    }

    function statusRow(message, withCreate) {
        var tr = document.createElement('tr');
        var td = document.createElement('td');
        td.colSpan = 8;
        var cluster = document.createElement('div');
        cluster.className = 'w-cluster';
        var span = document.createElement('span');
        span.className = 'w-text';
        span.setAttribute('data-tone', 'muted');
        span.textContent = message || '';
        cluster.appendChild(span);
        if (withCreate) {
            var button = document.createElement('button');
            var createLabel = root.querySelector('[data-feed-catalog] [data-feed-action="create"], [data-feed-action="create"]');
            button.type = 'button';
            button.className = 'w-button';
            button.setAttribute('data-tone', 'primary');
            button.setAttribute('data-size', 'sm');
            button.setAttribute('data-feed-action', 'create');
            button.textContent = createLabel ? createLabel.textContent : '';
            cluster.appendChild(button);
        }
        td.appendChild(cluster);
        tr.appendChild(td);
        return tr;
    }

    function actionButton(label, action, id, primary) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'w-button';
        button.setAttribute('data-size', 'sm');
        button.setAttribute('data-feed-action', action);
        button.setAttribute('data-definition-id', id);
        button.textContent = label;
        if (primary) {
            button.setAttribute('data-tone', 'primary');
        } else {
            button.setAttribute('data-variant', 'outline');
        }
        return button;
    }

    function renderActions(row, id) {
        var wrap = document.createElement('div');
        wrap.className = 'w-cluster';
        wrap.style.setProperty('--w-gap', 'var(--weline-space-2)');
        if (!id) {
            var missing = document.createElement('span');
            missing.className = 'w-text';
            missing.setAttribute('data-tone', 'danger');
            missing.setAttribute('data-size', 'sm');
            missing.textContent = text.missingId || '';
            wrap.appendChild(missing);
            return wrap;
        }
        var label = connectionLabel(row);
        if (missingSecret(row)) {
            wrap.appendChild(actionButton(text.copyAddress || '', 'copy', id, true));
        } else if (label === '已授权') {
            wrap.appendChild(actionButton(text.copyAddress || '', 'copy', id, true));
            wrap.appendChild(actionButton(text.reauthorize || '', 'authorize', id, false));
        } else {
            wrap.appendChild(actionButton(text.authorize || '', 'authorize', id, true));
            wrap.appendChild(actionButton(text.copyAddress || '', 'copy', id, false));
        }
        wrap.appendChild(actionButton(text.edit || '', 'edit', id, false));
        if (!isDisabled(row)) {
            wrap.appendChild(actionButton(text.generate || '', 'generate', id, false));
        }
        wrap.appendChild(actionButton(text.categories || '', 'categories', id, false));
        wrap.appendChild(actionButton(isDisabled(row) ? (text.enable || '') : (text.disable || ''), isDisabled(row) ? 'enable' : 'disable', id, false));
        wrap.appendChild(actionButton(text.archive || '', 'archive', id, false));
        return wrap;
    }

    function siteHeader(row) {
        var tr = document.createElement('tr');
        var cell = document.createElement('th');
        cell.colSpan = 8;
        cell.setAttribute('scope', 'rowgroup');
        var name = String(row.website_name || '').trim();
        cell.textContent = name !== '' ? name : ('#' + String(row.website_id));
        tr.appendChild(cell);
        return tr;
    }

    function renderRow(row) {
        var id = rowId(row);
        var tr = document.createElement('tr');
        if (id) {
            tr.setAttribute('data-definition-id', id);
            tr.setAttribute('data-testid', 'feed-catalog-row-' + id);
        }

        var nameCell = document.createElement('td');
        var strong = document.createElement('strong');
        strong.textContent = '└ ' + String(row.title || row.name || '');
        nameCell.appendChild(strong);
        if (isDisabled(row)) {
            nameCell.appendChild(document.createElement('br'));
            var mark = document.createElement('span');
            mark.className = 'w-text';
            mark.setAttribute('data-tone', 'muted');
            mark.setAttribute('data-size', 'sm');
            mark.textContent = text.disabledMark || '';
            nameCell.appendChild(mark);
        }

        var channelCell = document.createElement('td');
        channelCell.textContent = channelName(row);
        channelCell.appendChild(document.createElement('br'));
        var code = document.createElement('span');
        code.className = 'w-text';
        code.setAttribute('data-tone', 'muted');
        code.setAttribute('data-size', 'sm');
        code.textContent = String(row.channel_code || '');
        channelCell.appendChild(code);

        var websiteCell = document.createElement('td');
        websiteCell.textContent = '';

        var localeCell = document.createElement('td');
        localeCell.textContent = localeText(row);

        var statusCell = document.createElement('td');
        statusCell.style.whiteSpace = 'nowrap';
        statusCell.textContent = connectionLabel(row);

        var addressCell = document.createElement('td');
        addressCell.className = 'w-text';
        addressCell.setAttribute('data-size', 'sm');
        addressCell.style.overflowWrap = 'anywhere';
        var address = publicAddress(row);
        addressCell.textContent = address || (text.notGenerated || '');

        var generatedCell = document.createElement('td');
        generatedCell.className = 'w-text';
        generatedCell.setAttribute('data-size', 'sm');
        generatedCell.style.whiteSpace = 'nowrap';
        var generated = generationText(row);
        var emptyDocument = row.generated_ok === true && Number(row.item_count || 0) === 0;
        if (String(row.error_code || '').trim() !== '' || emptyDocument) {
            generatedCell.setAttribute('data-tone', 'danger');
        }
        generatedCell.textContent = generated;

        var actionCell = document.createElement('td');
        actionCell.appendChild(renderActions(row, id));

        [nameCell, channelCell, websiteCell, localeCell, statusCell, addressCell, generatedCell, actionCell].forEach(function (cell) {
            tr.appendChild(cell);
        });
        return tr;
    }

    function renderPager() {
        if (!pagerEl) {
            return;
        }
        pagerEl.replaceChildren();
        var total = state.total;
        var pages = total == null ? 0 : Math.ceil(total / state.pageSize);
        if (!pages || pages < 2) {
            pagerEl.hidden = true;
            return;
        }
        pagerEl.hidden = false;
        var list = document.createElement('ul');
        list.className = 'w-pagination';
        list.setAttribute('data-justify', 'center');
        function pageLink(page, label, disabled, active) {
            var item = document.createElement('li');
            item.className = 'w-pagination__item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
            var link = document.createElement('a');
            link.className = 'w-pagination__link';
            link.href = '#';
            link.textContent = label;
            if (!disabled) {
                link.setAttribute('data-feed-action', 'page');
                link.setAttribute('data-page', String(page));
            }
            item.appendChild(link);
            return item;
        }
        list.appendChild(pageLink(state.page - 1, '‹', state.page <= 1, false));
        var start = Math.max(1, state.page - 2);
        var end = Math.min(pages, start + 4);
        start = Math.max(1, end - 4);
        for (var i = start; i <= end; i += 1) {
            list.appendChild(pageLink(i, String(i), false, i === state.page));
        }
        list.appendChild(pageLink(state.page + 1, '›', state.page >= pages, false));
        pagerEl.appendChild(list);
    }

    function renderRows() {
        if (!rowsEl) {
            return;
        }
        rowsEl.replaceChildren();
        if (!state.rows.length) {
            rowsEl.appendChild(statusRow(text.empty || '', true));
        } else {
            var sorted = state.rows.slice().sort(function (a, b) {
                var site = Number(a.website_id) - Number(b.website_id);
                if (site !== 0) {
                    return site;
                }
                var locale = String(a.locale || '').localeCompare(String(b.locale || ''));
                if (locale !== 0) {
                    return locale;
                }
                var currency = String(a.currency || '').localeCompare(String(b.currency || ''));
                if (currency !== 0) {
                    return currency;
                }
                return Number(a.definition_id) - Number(b.definition_id);
            });
            var lastSite = null;
            sorted.forEach(function (row) {
                var siteKey = String(row.website_id);
                if (siteKey !== lastSite) {
                    rowsEl.appendChild(siteHeader(row));
                    lastSite = siteKey;
                }
                rowsEl.appendChild(renderRow(row));
            });
        }
        renderPager();
    }

    function findRow(id) {
        for (var i = 0; i < state.rows.length; i += 1) {
            if (rowId(state.rows[i]) === String(id)) {
                return state.rows[i];
            }
        }
        return null;
    }

    function replaceRow(next) {
        var id = rowId(next);
        state.rows = state.rows.map(function (row) {
            return rowId(row) === id ? next : row;
        });
        renderRows();
    }

    function readTotal(data) {
        if (!data || data.total == null || data.total === '') {
            return null;
        }
        var total = Number(data.total);
        return Number.isFinite(total) ? total : null;
    }

    function loadList() {
        setBanner('');
        if (rowsEl) {
            rowsEl.replaceChildren(statusRow(text.loading || '', false));
        }
        if (pagerEl) {
            pagerEl.hidden = true;
        }
        var query = {
            q: state.name,
            channel_code: state.channel,
            page: state.page,
            page_size: state.pageSize,
            archived: 0
        };
        if (state.website !== '') {
            query.website_id = state.website;
        }
        return listFeeds(query).then(function (data) {
            var items = Array.isArray(data) ? data : (data && (data.items || data.rows));
            if (!Array.isArray(items)) {
                throw new Error(text.queryNoList || '');
            }
            state.rows = items.map(scrubRow);
            state.total = Array.isArray(data) ? null : readTotal(data);
            if (data && data.page) {
                var page = Number(data.page);
                if (Number.isFinite(page) && page > 0) {
                    state.page = page;
                }
            }
            setBanner('');
            renderRows();
        }).catch(function (error) {
            state.rows = [];
            state.total = null;
            var message = errorMessage(error);
            setBanner(message);
            if (rowsEl) {
                rowsEl.replaceChildren(statusRow(message, false));
            }
            if (pagerEl) {
                pagerEl.hidden = true;
            }
        });
    }

    function formSelect(name) {
        return form ? form.querySelector('[data-feed-field="' + name + '"]') : null;
    }

    function setField(name, value) {
        var field = formSelect(name);
        if (field) {
            field.value = value == null ? '' : String(value);
        }
    }

    function fillIdentity(row) {
        setField('website', row ? row.website_id : '');
        setField('locale', row ? row.locale : '');
        setField('country', row ? row.country : '');
        setField('currency', row ? row.currency : '');
    }

    function selectedChannel() {
        var checked = form ? form.querySelector('input[name="channel_code"]:checked') : null;
        return checked ? String(checked.value || '') : '';
    }

    function syncSecretFields() {
        var channel = selectedChannel();
        var apiOnly = channel === 'criteo' || channel === 'naver_shopping';
        if (oauthFields) {
            oauthFields.hidden = apiOnly;
        }
        if (microsoftField) {
            microsoftField.hidden = channel !== 'microsoft_merchant';
        }
        if (apiKeyField) {
            apiKeyField.hidden = !apiOnly;
        }
    }

    function setChannel(code, locked) {
        if (!form) {
            return;
        }
        form.querySelectorAll('input[name="channel_code"]').forEach(function (input) {
            input.checked = input.value === code;
            input.disabled = !!locked;
        });
        if (channelFieldset) {
            channelFieldset.disabled = !!locked;
        }
        syncSecretFields();
    }

    function setScopeMode(creating) {
        if (sitePicker) {
            sitePicker.hidden = !creating;
        }
        if (scopeLock) {
            scopeLock.hidden = creating;
        }
    }

    function clearSiteTags() {
        if (!sitePicker) {
            return;
        }
        var guard = 0;
        var remove = sitePicker.querySelector('.weline-website-tag-remove');
        while (remove && guard < 40) {
            remove.click();
            remove = sitePicker.querySelector('.weline-website-tag-remove');
            guard += 1;
        }
    }

    function openCreate() {
        if (!form) {
            return;
        }
        form.reset();
        var idField = formSelect('definition_id');
        if (idField) {
            idField.value = '';
        }
        var enabled = formSelect('enabled');
        if (enabled) {
            enabled.checked = true;
        }
        setChannel('', false);
        setScopeMode(true);
        clearSiteTags();
        fillIdentity(null);
        ['client_id', 'client_secret', 'developer_token', 'api_key'].forEach(function (name) {
            setField(name, '');
        });
        if (formTitle) {
            formTitle.textContent = text.createTitle || '';
        }
        if (credentialHint) {
            credentialHint.textContent = text.credentialCreateHint || '';
        }
        setFormStatus('');
        openDialog(formDialog);
    }

    function openEdit(row) {
        if (!form || !row) {
            return;
        }
        var idField = formSelect('definition_id');
        if (idField) {
            idField.value = rowId(row);
        }
        var nameField = formSelect('name');
        if (nameField) {
            nameField.value = String(row.title || row.name || '');
        }
        setChannel(String(row.channel_code || ''), true);
        setScopeMode(false);
        fillIdentity(row);
        if (scopeLock) {
            var siteName = String(row.website_name || row.website_id || '');
            scopeLock.textContent = siteName + ' · ' + String(row.locale || '') + ' · ' + String(row.country || '') + ' · ' + String(row.currency || '');
        }
        var enabled = formSelect('enabled');
        if (enabled) {
            enabled.checked = !isDisabled(row);
        }
        ['client_id', 'client_secret', 'developer_token', 'api_key'].forEach(function (name) {
            setField(name, '');
        });
        if (formTitle) {
            formTitle.textContent = text.editTitle || '';
        }
        if (credentialHint) {
            credentialHint.textContent = text.credentialEditHint || '';
        }
        setFormStatus('');
        openDialog(formDialog);
    }

    function submitForm(event) {
        event.preventDefault();
        var name = String((formSelect('name') || {}).value || '').trim();
        var channel = selectedChannel();
        var definitionId = String((formSelect('definition_id') || {}).value || '').trim();
        var sitePicker = document.getElementById('feed-catalog-websites_value');
        var websiteIds = sitePicker ? String(sitePicker.value || '').trim() : '';
        var website = String((formSelect('website') || {}).value || '').trim();
        var locale = String((formSelect('locale') || {}).value || '').trim();
        var country = String((formSelect('country') || {}).value || '').trim();
        var currency = String((formSelect('currency') || {}).value || '').trim();
        if (!name) {
            setFormStatus(text.needName || '');
            return;
        }
        if (!channel) {
            setFormStatus(text.needChannel || '');
            return;
        }
        if (definitionId === '' && websiteIds === '') {
            setFormStatus(text.needWebsite || '');
            return;
        }
        if (definitionId !== '' && (!website || !locale || !country || !currency)) {
            setFormStatus(text.needLocale || '');
            return;
        }
        var payload = {
            title: name,
            channel_code: channel,
            enabled: (formSelect('enabled') || {}).checked ? 1 : 0
        };
        if (definitionId !== '') {
            payload.definition_id = definitionId;
            payload.website_id = website;
            payload.locale = locale;
            payload.country = country;
            payload.currency = currency;
        } else {
            payload.website_ids = websiteIds;
        }
        ['client_id', 'client_secret', 'developer_token', 'api_key'].forEach(function (name) {
            var secret = String((formSelect(name) || {}).value || '');
            if (secret !== '') {
                payload[name] = secret;
            }
        });
        var submit = form.querySelector('[type="submit"]');
        if (submit) {
            submit.disabled = true;
        }
        saveFeed(payload).then(function (data) {
            closeDialog(formDialog);
            if (data && data.created_count != null) {
                var note = String(text.createdTree || '').replace('{count}', String(data.created_count));
                if (Number(data.kept_count) > 0) {
                    note += ' ' + String(text.keptTree || '').replace('{count}', String(data.kept_count));
                }
                if (Array.isArray(data.skipped_locales) && data.skipped_locales.length) {
                    note += ' · ' + data.skipped_locales.join(',');
                }
                notify('success', note);
            } else if (data && data.message) {
                notify('success', scrubText(data.message));
            }
            return loadList();
        }).catch(function (error) {
            setFormStatus(errorMessage(error));
            notify('error', errorMessage(error));
        }).then(function () {
            if (submit) {
                submit.disabled = false;
            }
        });
    }

    function copyAddress(row) {
        var url = publicAddress(row);
        if (!url) {
            notify('error', text.notGenerated || '');
            return;
        }
        var writer = navigator.clipboard && navigator.clipboard.writeText;
        if (!writer) {
            notify('error', text.copyFailed || '');
            return;
        }
        writer.call(navigator.clipboard, url).then(function () {
            notify('success', text.copied || '');
        }).catch(function () {
            notify('error', text.copyFailed || '');
        });
    }

    function afterMutation(promise) {
        return promise.then(function (data) {
            if (data && data.message) {
                notify('success', scrubText(data.message));
            }
            return loadList().then(function () {
                if (errorEl && !errorEl.hidden) {
                    notify('error', text.listReloadFailed || '');
                }
            });
        }).catch(function (error) {
            notify('error', errorMessage(error));
        });
    }

    function applyGeneration(row, data) {
        if (!data || data.success === false) {
            notify('error', scrubText((data && data.message) || text.generateNoResult || ''));
            return;
        }
        var next = scrubRow(row);
        if (typeof data.connection_status === 'string' && data.connection_status !== '' && data.connection_status !== '已连接') {
            next.connection_status = data.connection_status;
        }
        if (typeof data.public_url === 'string') {
            next.public_url = scrubPublicUrl(data.public_url);
            next.public_label = data.public_url === '' ? (text.notGenerated || '') : '';
        }
        if (typeof data.last_generated_at === 'string' && data.last_generated_at !== '') {
            next.last_generated_at = data.last_generated_at;
        }
        if (data.item_count != null) {
            next.item_count = data.item_count;
        }
        if (data.skipped_count != null) {
            next.skipped_count = data.skipped_count;
        }
        next.error_code = scrubText(data.error_code || '');
        next.diagnostic_code = scrubText(data.diagnostic_code || '');
        if (Array.isArray(data.diagnostics)) {
            next.diagnostics = data.diagnostics;
        }
        next.generated_ok = data.generated_ok === true;
        replaceRow(next);
    }

    function categoryItems(data) {
        if (Array.isArray(data)) {
            return data;
        }
        if (data && Array.isArray(data.items)) {
            return data.items;
        }
        if (data && Array.isArray(data.rows)) {
            return data.rows;
        }
        return null;
    }

    function mappedLabel(value) {
        return String(value || '').trim() !== '';
    }

    function renderCategories(items) {
        if (!categoryRows) {
            return;
        }
        categoryRows.replaceChildren();
        items.forEach(function (item) {
            var tr = document.createElement('tr');
            var categoryId = item.store_category_id != null ? item.store_category_id : (item.category_id != null ? item.category_id : '');
            var codeValue = String(item.map_value || item.channel_category_code || '');
            tr.setAttribute('data-feed-category-row', '');
            tr.setAttribute('data-store-category-id', String(categoryId));
            tr.setAttribute('data-map-id', String(item.map_id || 0));
            tr.setAttribute('data-vocabulary', String(item.vocabulary || state.categoryVocabulary || ''));
            tr.setAttribute('data-original', codeValue);
            var nameCell = document.createElement('td');
            nameCell.textContent = String(item.path || item.path_name || item.name || '');
            if (item.vocabulary && !state.categoryVocabulary) {
                nameCell.appendChild(document.createElement('br'));
                var vocab = document.createElement('span');
                vocab.className = 'w-text';
                vocab.setAttribute('data-tone', 'muted');
                vocab.setAttribute('data-size', 'sm');
                vocab.textContent = String(item.vocabulary);
                nameCell.appendChild(vocab);
            }
            var codeCell = document.createElement('td');
            var input = document.createElement('input');
            input.className = 'w-input';
            input.type = 'text';
            input.value = codeValue;
            input.setAttribute('data-feed-category-code', '');
            input.setAttribute('aria-label', String(item.path || item.path_name || item.name || ''));
            codeCell.appendChild(input);
            var flagCell = document.createElement('td');
            flagCell.setAttribute('data-feed-category-flag', '');
            flagCell.style.whiteSpace = 'nowrap';
            function paint() {
                var mapped = mappedLabel(input.value);
                tr.hidden = state.onlyUnmapped && mapped;
                flagCell.textContent = mapped ? (text.mapped || '') : (text.unmapped || '');
            }
            input.addEventListener('input', paint);
            paint();
            tr.appendChild(nameCell);
            tr.appendChild(codeCell);
            tr.appendChild(flagCell);
            categoryRows.appendChild(tr);
        });
    }

    function openCategories(row) {
        state.categoryWebsiteId = row && row.website_id != null ? String(row.website_id) : '';
        state.categoryVocabulary = vocabularyFor(String((row && row.channel_code) || ''));
        state.categoryLocale = String((row && row.locale) || '');
        state.onlyUnmapped = false;
        if (unmappedButton) {
            unmappedButton.setAttribute('aria-pressed', 'false');
            unmappedButton.textContent = text.onlyUnmapped || '';
        }
        if (categorySubtitle) {
            categorySubtitle.textContent = [row.title || row.name || '', channelName(row)].filter(Boolean).join(' · ');
        }
        if (categoryStatus) {
            categoryStatus.setAttribute('data-tone', 'muted');
            categoryStatus.textContent = text.categoryLoading || '';
        }
        if (categoryRows) {
            categoryRows.replaceChildren();
        }
        openDialog(categoryDialog);
        if (state.categoryWebsiteId === '') {
            if (categoryStatus) {
                categoryStatus.setAttribute('data-tone', 'danger');
                categoryStatus.textContent = text.needWebsite || '';
            }
            return;
        }
        var categoryQuery = {
            website_id: state.categoryWebsiteId,
            locale: state.categoryLocale
        };
        if (state.categoryVocabulary) {
            categoryQuery.vocabulary = state.categoryVocabulary;
        }
        listCategoryMaps(categoryQuery).then(function (data) {
            var items = categoryItems(data);
            if (!Array.isArray(items)) {
                throw new Error(text.categoryNoList || '');
            }
            renderCategories(items);
            if (categoryStatus) {
                categoryStatus.textContent = items.length ? '' : (text.categoryEmpty || '');
            }
        }).catch(function (error) {
            if (categoryStatus) {
                categoryStatus.setAttribute('data-tone', 'danger');
                categoryStatus.textContent = errorMessage(error);
            }
        });
    }

    function toggleUnmapped() {
        state.onlyUnmapped = !state.onlyUnmapped;
        if (unmappedButton) {
            unmappedButton.setAttribute('aria-pressed', state.onlyUnmapped ? 'true' : 'false');
            unmappedButton.textContent = state.onlyUnmapped ? (text.showAll || '') : (text.onlyUnmapped || '');
        }
        if (!categoryRows) {
            return;
        }
        categoryRows.querySelectorAll('[data-feed-category-row]').forEach(function (tr) {
            var input = tr.querySelector('[data-feed-category-code]');
            var mapped = mappedLabel(input ? input.value : '');
            tr.hidden = state.onlyUnmapped && mapped;
        });
    }

    function saveCategories() {
        if (!categoryRows || state.categoryWebsiteId === '') {
            return;
        }
        var jobs = [];
        categoryRows.querySelectorAll('[data-feed-category-row]').forEach(function (tr) {
            var input = tr.querySelector('[data-feed-category-code]');
            var value = input ? String(input.value || '').trim() : '';
            var original = tr.getAttribute('data-original') || '';
            var mapId = Number(tr.getAttribute('data-map-id') || '0');
            if (value === original) {
                return;
            }
            if (value === '') {
                if (mapId > 0) {
                    jobs.push(function () {
                        return deleteCategoryMap(mapId);
                    });
                }
                return;
            }
            jobs.push(function () {
                return saveCategoryMap({
                    website_id: state.categoryWebsiteId,
                    store_category_id: tr.getAttribute('data-store-category-id') || '',
                    vocabulary: tr.getAttribute('data-vocabulary') || state.categoryVocabulary,
                    map_value: value,
                    locale: state.categoryLocale
                });
            });
        });
        var chain = Promise.resolve();
        jobs.forEach(function (job) {
            chain = chain.then(job);
        });
        chain.then(function () {
            closeDialog(categoryDialog);
        }).catch(function (error) {
            if (categoryStatus) {
                categoryStatus.setAttribute('data-tone', 'danger');
                categoryStatus.textContent = errorMessage(error);
            }
            notify('error', errorMessage(error));
        });
    }

    // Weline.UI.dialog moves an open dialog to <body>, outside root; dialogs come first so
    // a dialog still nested in root is handled by its own listener only.
    var clickSurfaces = [formDialog, categoryDialog, disableDialog, archiveDialog, root].filter(Boolean);

    function onClick(event) {
        var trigger = event.target.closest('[data-feed-action]');
        if (!trigger) {
            return;
        }
        var owner = null;
        for (var i = 0; i < clickSurfaces.length; i++) {
            if (clickSurfaces[i].contains(trigger)) {
                owner = clickSurfaces[i];
                break;
            }
        }
        if (owner !== event.currentTarget) {
            return;
        }
        var action = trigger.getAttribute('data-feed-action') || '';
        if (action === 'page') {
            event.preventDefault();
            var page = Number(trigger.getAttribute('data-page') || '1');
            if (!Number.isFinite(page) || page < 1 || page === state.page) {
                return;
            }
            state.page = page;
            loadList();
            return;
        }
        if (action === 'create') {
            openCreate();
            return;
        }
        if (action === 'toggle-unmapped') {
            toggleUnmapped();
            return;
        }
        if (action === 'save-categories') {
            saveCategories();
            return;
        }
        if (action === 'confirm-disable') {
            var disableId = state.pendingId;
            closeDialog(disableDialog);
            if (disableId) {
                afterMutation(setFeedEnabled(disableId, 0));
            }
            return;
        }
        if (action === 'confirm-archive') {
            var archiveId = state.pendingId;
            closeDialog(archiveDialog);
            if (archiveId) {
                afterMutation(archiveFeed(archiveId));
            }
            return;
        }
        var id = trigger.getAttribute('data-definition-id') || '';
        var row = findRow(id);
        if (!row) {
            return;
        }
        if (action === 'edit') {
            openEdit(row);
        } else if (action === 'copy') {
            copyAddress(row);
        } else if (action === 'generate') {
            trigger.disabled = true;
            generateFeed(id).then(function (data) {
                applyGeneration(row, data);
            }).catch(function (error) {
                notify('error', errorMessage(error));
            }).then(function () {
                trigger.disabled = false;
            });
        } else if (action === 'categories') {
            openCategories(row);
        } else if (action === 'disable') {
            state.pendingId = id;
            openDialog(disableDialog);
        } else if (action === 'archive') {
            state.pendingId = id;
            openDialog(archiveDialog);
        } else if (action === 'enable') {
            trigger.disabled = true;
            afterMutation(setFeedEnabled(id, 1)).then(function () {
                trigger.disabled = false;
            });
        }
    }

    clickSurfaces.forEach(function (surface) {
        surface.addEventListener('click', onClick);
    });
    if (form) {
        form.addEventListener('submit', submitForm);
        form.addEventListener('change', function (event) {
            if (event.target && event.target.name === 'channel_code') {
                syncSecretFields();
            }
        });
    }
    var filters = root.querySelector('[data-feed-filters]');
    if (filters) {
        var readFilters = function () {
            var website = filters.querySelector('[data-feed-filter-website] [data-website-select-value]');
            state.name = String((filters.querySelector('[data-feed-filter="name"]') || {}).value || '').trim();
            state.channel = String((filters.querySelector('[data-feed-filter="channel"]') || {}).value || '');
            state.website = String((website || {}).value || '').trim();
            state.page = 1;
        };
        filters.addEventListener('submit', function (event) {
            event.preventDefault();
            readFilters();
            loadList();
        });
        filters.addEventListener('change', function (event) {
            var target = event.target;
            if (!target || !target.getAttribute) {
                return;
            }
            var isChannel = target.getAttribute('data-feed-filter') === 'channel';
            var isWebsite = target.hasAttribute('data-website-select-value')
                && !!target.closest('[data-feed-filter-website]');
            if (!isChannel && !isWebsite) {
                return;
            }
            readFilters();
            loadList();
        });
    }
    if (config.notice) {
        notify('error', scrubText(config.notice));
    }
    loadList();
})();
