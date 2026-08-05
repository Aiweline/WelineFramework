(function () {
    'use strict';

    function toast(type, message) {
        if (window.BackendToast && typeof window.BackendToast[type] === 'function') {
            window.BackendToast[type](message);
            return;
        }
        if (window.console && typeof window.console[type === 'error' ? 'error' : 'info'] === 'function') {
            window.console[type === 'error' ? 'error' : 'info'](message);
        }
    }

    function message(root, key) {
        return root && root.dataset ? String(root.dataset[key] || '') : '';
    }

    function unwrap(response) {
        return response && response.data !== undefined && response.success === undefined ? response.data : response;
    }

    function businessPayload(error) {
        var response = error && error.response ? error.response : null;
        var wrapper = response && response.data ? response.data : null;
        if (wrapper && wrapper.data && typeof wrapper.data === 'object' && !Array.isArray(wrapper.data)) {
            return wrapper.data;
        }
        if (wrapper && typeof wrapper === 'object' && (wrapper.success !== undefined || Array.isArray(wrapper.errors))) {
            return wrapper;
        }
        return null;
    }

    function formatApiError(error, root) {
        var business = businessPayload(error);
        if (business) {
            var details = [];
            if (Array.isArray(business.errors)) {
                business.errors.forEach(function (item) {
                    var text = String(item || '').trim();
                    if (text) details.push(text);
                });
            }
            var nestedResults = business.data && Array.isArray(business.data.results) ? business.data.results : (Array.isArray(business.results) ? business.results : []);
            nestedResults.forEach(function (result) {
                if (!result || typeof result !== 'object') return;
                if (Array.isArray(result.error_messages)) {
                    result.error_messages.forEach(function (item) {
                        var text = String(item || '').trim();
                        if (text) details.push(text);
                    });
                }
                if (result.message) {
                    var messageText = String(result.message || '').trim();
                    if (messageText && result.success === false) details.push(messageText);
                }
            });
            if (details.length) {
                return details.filter(function (item, index, list) { return list.indexOf(item) === index; }).slice(0, 3).join('\n');
            }
            if (business.message) return String(business.message);
        }
        return error && error.message ? error.message : message(root, 'backendFailed');
    }

    async function resource(root) {
        if (!window.Weline) {
            throw new Error(message(root, 'apiUnavailable'));
        }
        var api = typeof window.Weline.load === 'function' ? await window.Weline.load('api') : window.Weline.Api;
        if (!api || typeof api.resource !== 'function') {
            throw new Error(message(root, 'resourceUnavailable'));
        }
        return await api.resource('seo_admin');
    }

    function setButtonLoading(button, loading) {
        if (!button) return;
        if (loading) {
            button.dataset.originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>' + (button.dataset.loadingLabel || '');
        } else {
            button.disabled = false;
            if (button.dataset.originalHtml) button.innerHTML = button.dataset.originalHtml;
        }
    }

    function initSitemap(root) {
        var cards = Array.from(root.querySelectorAll('[data-seo-site]'));
        var provider = root.querySelector('[data-seo-filter="provider"]');
        var status = root.querySelector('[data-seo-filter="status"]');
        var empty = root.querySelector('[data-seo-filter-empty]');
        var result = root.querySelector('[data-seo-operation-result]');
        var syncingSelection = false;
        var websiteSelectId = 'seo_website_ids';

        function getWebsiteSelectApi() {
            return window.WelineWebsiteSelect && window.WelineWebsiteSelect[websiteSelectId]
                ? window.WelineWebsiteSelect[websiteSelectId]
                : null;
        }

        function getSelectedWebsiteIds() {
            var api = getWebsiteSelectApi();
            if (api && typeof api.getValues === 'function') {
                return api.getValues().map(function (value) { return Number.parseInt(value, 10); }).filter(Number.isInteger);
            }
            var hidden = document.getElementById(websiteSelectId + '_value');
            if (!hidden) return [];
            return String(hidden.value || '').split(',').map(function (value) { return Number.parseInt(value.trim(), 10); }).filter(Number.isInteger);
        }

        function setSelectedWebsiteIds(ids) {
            var api = getWebsiteSelectApi();
            var unique = Array.from(new Set((ids || []).map(String)));
            syncingSelection = true;
            if (api && typeof api.setValue === 'function') {
                api.setValue(unique);
            } else {
                var hidden = document.getElementById(websiteSelectId + '_value');
                if (hidden) {
                    hidden.value = unique.join(',');
                    hidden.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
            syncingSelection = false;
            syncCardCheckboxes(unique);
        }

        function syncCardCheckboxes(ids) {
            var selected = {};
            (ids || []).forEach(function (id) { selected[String(id)] = true; });
            cards.forEach(function (card) {
                var input = card.querySelector('[data-seo-website-id]');
                if (input) input.checked = !!selected[String(input.value)];
            });
        }

        function applyFilters() {
            var providerValue = provider && provider.value || '';
            var statusValue = status && status.value || '';
            var selectedIds = getSelectedWebsiteIds();
            var selectedSet = {};
            selectedIds.forEach(function (id) { selectedSet[String(id)] = true; });
            // Empty website selection = show all sites; non-empty = filter to picked sites.
            var filterByWebsite = selectedIds.length > 0;
            var visible = 0;
            cards.forEach(function (card) {
                var input = card.querySelector('[data-seo-website-id]');
                var websiteId = input ? String(input.value) : String(card.dataset.websiteId || '');
                var matchesWebsite = !filterByWebsite || !!selectedSet[websiteId];
                var matches = matchesWebsite
                    && (!providerValue || (card.dataset.providers || '').split(' ').includes(providerValue))
                    && (!statusValue || (card.dataset.status || '').split(' ').includes(statusValue));
                card.classList.toggle('d-none', !matches);
                if (matches) visible++;
            });
            if (empty) empty.classList.toggle('d-none', visible !== 0);
        }

        window.handleSeoWebsiteSelectChange = function () {
            if (syncingSelection) return;
            syncCardCheckboxes(getSelectedWebsiteIds());
            applyFilters();
        };

        [provider, status].forEach(function (control) {
            if (control) control.addEventListener('change', applyFilters);
        });

        var websiteValueInput = document.getElementById(websiteSelectId + '_value');
        if (websiteValueInput) {
            websiteValueInput.addEventListener('change', function () {
                if (syncingSelection) return;
                syncCardCheckboxes(getSelectedWebsiteIds());
                applyFilters();
            });
        }

        cards.forEach(function (card) {
            var input = card.querySelector('[data-seo-website-id]');
            if (!input) return;
            input.addEventListener('change', function () {
                if (syncingSelection) return;
                var ids = cards.map(function (item) {
                    var checkbox = item.querySelector('[data-seo-website-id]');
                    return checkbox && checkbox.checked ? Number.parseInt(checkbox.value, 10) : null;
                }).filter(Number.isInteger);
                setSelectedWebsiteIds(ids);
                applyFilters();
            });
        });

        var selectAll = root.querySelector('[data-seo-select-all]');
        if (selectAll) selectAll.addEventListener('click', function () {
            var visible = cards.filter(function (card) { return !card.classList.contains('d-none'); });
            var current = getSelectedWebsiteIds().map(String);
            var visibleIds = visible.map(function (card) {
                var input = card.querySelector('[data-seo-website-id]');
                return input ? String(input.value) : '';
            }).filter(Boolean);
            var allSelected = visibleIds.length > 0 && visibleIds.every(function (id) { return current.indexOf(id) > -1; });
            if (allSelected) {
                setSelectedWebsiteIds(current.filter(function (id) { return visibleIds.indexOf(id) === -1; }));
            } else {
                setSelectedWebsiteIds(current.concat(visibleIds));
            }
            applyFilters();
        });

        syncCardCheckboxes(getSelectedWebsiteIds());
        applyFilters();
        initSitemapUrlManager(root);

        root.addEventListener('click', function (event) {
            var copy = event.target.closest('[data-seo-copy]');
            if (copy) {
                event.preventDefault();
                navigator.clipboard.writeText(copy.dataset.seoCopy || '').then(function () { toast('success', message(root, 'copied')); }).catch(function () { toast('error', message(root, 'copyFailed')); });
                return;
            }
            var button = event.target.closest('[data-seo-operation]');
            if (!button) return;
            var allSites = !!(root.querySelector('[data-seo-all-sites]') || {}).checked;
            var websiteIds = getSelectedWebsiteIds();
            if (!websiteIds.length) {
                websiteIds = Array.from(root.querySelectorAll('[data-seo-website-id]:checked')).map(function (input) { return Number.parseInt(input.value, 10); }).filter(Number.isInteger);
            }
            if (!allSites && websiteIds.length === 0) {
                toast('warning', message(root, 'selectWebsite'));
                return;
            }
            var operation = button.dataset.seoOperation;
            var payload = { website_ids: websiteIds, all_sites: allSites };
            if (provider && provider.value && operation === 'syncSitemapUrls') payload.module = provider.value;
            setButtonLoading(button, true);
            if (result) {
                result.className = 'seo-operation-result is-visible is-loading';
                result.textContent = message(root, 'running');
            }
            resource(root).then(function (api) { return api[operation](payload); }).then(function (response) {
                var data = unwrap(response);
                var success = !!(data && data.success);
                var resultMessage = data && data.message ? data.message : message(root, success ? 'completed' : 'failed');
                if (result) {
                    result.className = 'seo-operation-result is-visible ' + (success ? 'is-success' : 'is-error');
                    result.textContent = resultMessage;
                }
                toast(success ? 'success' : 'error', resultMessage);
                if (success && operation !== 'submitSitemaps') window.setTimeout(function () { window.location.reload(); }, 700);
            }).catch(function (error) {
                var errorMessage = formatApiError(error, root);
                if (result) { result.className = 'seo-operation-result is-visible is-error'; result.textContent = errorMessage; }
                toast('error', errorMessage);
            }).finally(function () { setButtonLoading(button, false); });
        });
    }

    function initSitemapUrlManager(root) {
        var panel = root.querySelector('[data-seo-url-manager]');
        if (!panel) return;
        var tbody = panel.querySelector('[data-seo-url-tbody]');
        var subtitle = panel.querySelector('[data-seo-url-manager-subtitle]');
        var resultBox = panel.querySelector('[data-seo-url-result]');
        var moduleSelect = panel.querySelector('[data-seo-url-module]');
        var localeSelect = panel.querySelector('[data-seo-url-locale]');
        var statusSelect = panel.querySelector('[data-seo-url-status]');
        var keywordInput = panel.querySelector('[data-seo-url-keyword]');
        var pageInfo = panel.querySelector('[data-seo-url-page-info]');
        var selectAll = panel.querySelector('[data-seo-url-select-all]');
        var dismissButton = panel.querySelector('[data-seo-url-manager-close]');
        var activeOpener = null;
        var panelVisibilityBeforeOpen = '';
        var bodyOverflowBeforeOpen = '';
        var bodyPaddingRightBeforeOpen = '';
        var state = {
            websiteId: null,
            page: 1,
            pageSize: 20,
            total: 0,
            changefreqOptions: ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'],
            loading: false,
        };
        var offcanvas = null;
        var Offcanvas = window.bootstrap && window.bootstrap.Offcanvas;
        if (typeof Offcanvas === 'function') {
            offcanvas = typeof Offcanvas.getOrCreateInstance === 'function'
                ? Offcanvas.getOrCreateInstance(panel)
                : new Offcanvas(panel);
        }
        function closeUrlManagerFallback() {
            panel.classList.remove('show', 'showing', 'hiding');
            panel.style.visibility = panelVisibilityBeforeOpen;
            panel.setAttribute('aria-hidden', 'true');
            panel.removeAttribute('aria-modal');
            panel.removeAttribute('role');
            document.body.style.overflow = bodyOverflowBeforeOpen;
            document.body.style.paddingRight = bodyPaddingRightBeforeOpen;
        }
        if (dismissButton) {
            dismissButton.addEventListener('click', function (event) {
                event.preventDefault();
                if (offcanvas && typeof offcanvas.hide === 'function') {
                    offcanvas.hide();
                    window.setTimeout(function () {
                        if (panel.classList.contains('show')) closeUrlManagerFallback();
                    }, 350);
                } else {
                    closeUrlManagerFallback();
                }
                if (activeOpener) activeOpener.focus();
            });
        }

        function showResult(type, text) {
            if (!resultBox) return;
            if (!text) {
                resultBox.hidden = true;
                resultBox.textContent = '';
                return;
            }
            resultBox.hidden = false;
            resultBox.className = 'seo-url-manager__result is-' + type;
            resultBox.textContent = text;
        }

        function selectedUrlIds() {
            return Array.from(panel.querySelectorAll('[data-seo-url-id]:checked'))
                .map(function (input) { return Number.parseInt(input.value, 10); })
                .filter(function (id) { return Number.isInteger(id) && id > 0; });
        }

        function fillFacet(select, values, emptyLabel, current, localeMode) {
            if (!select) return;
            var previous = current || select.value || '';
            select.innerHTML = '';
            var empty = document.createElement('option');
            empty.value = '';
            empty.textContent = emptyLabel;
            select.appendChild(empty);
            (values || []).forEach(function (value) {
                var option = document.createElement('option');
                if (localeMode) {
                    option.value = value === '' ? '__default__' : value;
                    option.textContent = value === '' ? message(root, 'urlDefaultLocale') : value;
                } else {
                    option.value = value;
                    option.textContent = value;
                }
                select.appendChild(option);
            });
            if (previous) {
                select.value = previous;
                if (select.value !== previous) select.value = '';
            }
        }

        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function renderRows(items) {
            if (!tbody) return;
            tbody.innerHTML = '';
            if (!items || !items.length) {
                var emptyRow = document.createElement('tr');
                emptyRow.innerHTML = '<td colspan="8" class="text-muted text-center py-4">' + escapeHtml(message(root, 'urlEmpty')) + '</td>';
                tbody.appendChild(emptyRow);
                return;
            }
            items.forEach(function (item) {
                var tr = document.createElement('tr');
                tr.dataset.urlId = String(item.url_id || '');
                var localeLabel = item.locale ? item.locale : message(root, 'urlDefaultLocale');
                var statusLabel = Number(item.status) === 1 ? message(root, 'urlActive') : message(root, 'urlInactive');
                var freqOptions = (state.changefreqOptions || []).map(function (freq) {
                    return '<option value="' + escapeHtml(freq) + '"' + (freq === item.changefreq ? ' selected' : '') + '>' + escapeHtml(freq) + '</option>';
                }).join('');
                tr.innerHTML =
                    '<td><input class="form-check-input" type="checkbox" value="' + escapeHtml(item.url_id) + '" data-seo-url-id></td>' +
                    '<td class="seo-url-table__url"><code title="' + escapeHtml(item.url || '') + '">' + escapeHtml(item.url || '') + '</code>' +
                    '<div class="seo-url-table__meta">' + escapeHtml(item.scope || '') + (item.entity_type ? ' · ' + escapeHtml(item.entity_type) : '') + '</div></td>' +
                    '<td><code>' + escapeHtml(item.module || '') + '</code></td>' +
                    '<td>' + escapeHtml(localeLabel) + '</td>' +
                    '<td><select class="form-select form-select-sm" data-seo-url-changefreq>' + freqOptions + '</select></td>' +
                    '<td><input class="form-control form-control-sm" type="number" min="0" max="1" step="0.1" value="' + escapeHtml(item.priority || '0.5') + '" data-seo-url-priority></td>' +
                    '<td><button type="button" class="btn btn-sm ' + (Number(item.status) === 1 ? 'btn-success' : 'btn-secondary') + '" data-seo-url-toggle-status data-status="' + Number(item.status) + '">' + escapeHtml(statusLabel) + '</button></td>' +
                    '<td><div class="seo-url-table__actions">' +
                    '<button type="button" class="btn btn-sm btn-outline-primary" data-seo-url-save>' + escapeHtml(message(root, 'urlSave')) + '</button>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger" data-seo-url-delete>' + escapeHtml(message(root, 'urlDelete')) + '</button>' +
                    '</div></td>';
                tbody.appendChild(tr);
            });
            if (selectAll) selectAll.checked = false;
        }

        function updatePager() {
            if (!pageInfo) return;
            var totalPages = Math.max(1, Math.ceil((state.total || 0) / state.pageSize));
            pageInfo.textContent = state.page + ' / ' + totalPages + ' · ' + state.total;
            var prev = panel.querySelector('[data-seo-url-prev]');
            var next = panel.querySelector('[data-seo-url-next]');
            if (prev) prev.disabled = state.page <= 1 || state.loading;
            if (next) next.disabled = state.page >= totalPages || state.loading;
        }

        function loadUrls() {
            if (state.websiteId === null || state.websiteId < 0 || state.loading) return;
            state.loading = true;
            showResult('loading', message(root, 'running'));
            updatePager();
            var payload = {
                website_id: state.websiteId,
                page: state.page,
                page_size: state.pageSize,
                module: moduleSelect ? moduleSelect.value : '',
                locale: localeSelect ? localeSelect.value : '',
                status: statusSelect ? statusSelect.value : '',
                keyword: keywordInput ? keywordInput.value.trim() : '',
            };
            resource(root).then(function (api) {
                return api.listSitemapUrls(payload);
            }).then(function (response) {
                var data = unwrap(response) || {};
                if (!data.success) {
                    showResult('error', data.message || message(root, 'urlLoadFailed'));
                    return;
                }
                state.total = Number(data.total || 0);
                state.page = Number(data.page || state.page);
                state.pageSize = Number(data.page_size || state.pageSize);
                if (Array.isArray(data.changefreq_options) && data.changefreq_options.length) {
                    state.changefreqOptions = data.changefreq_options;
                }
                fillFacet(moduleSelect, data.modules || [], message(root, 'urlAllProviders'), moduleSelect ? moduleSelect.value : '', false);
                fillFacet(localeSelect, data.locales || [], message(root, 'urlAllLocales'), localeSelect ? localeSelect.value : '', true);
                renderRows(data.items || []);
                showResult('', '');
                updatePager();
            }).catch(function (error) {
                showResult('error', formatApiError(error, root) || message(root, 'urlLoadFailed'));
            }).finally(function () {
                state.loading = false;
                updatePager();
            });
        }

        function openForWebsite(websiteId, websiteName, websiteCode) {
            state.websiteId = websiteId;
            state.page = 1;
            if (subtitle) {
                subtitle.textContent = (websiteName || '') + (websiteCode ? ' · ' + websiteCode : '') + ' · ID ' + websiteId;
            }
            if (keywordInput) keywordInput.value = '';
            if (moduleSelect) moduleSelect.value = '';
            if (localeSelect) localeSelect.value = '';
            if (statusSelect) statusSelect.value = '';
            if (!panel.classList.contains('show')) {
                panelVisibilityBeforeOpen = panel.style.visibility;
                bodyOverflowBeforeOpen = document.body.style.overflow;
                bodyPaddingRightBeforeOpen = document.body.style.paddingRight;
            }
            if (offcanvas) {
                offcanvas.show();
            } else {
                panel.classList.add('show');
                panel.removeAttribute('aria-hidden');
                panel.setAttribute('aria-modal', 'true');
                panel.setAttribute('role', 'dialog');
                if (dismissButton) dismissButton.focus();
            }
            loadUrls();
        }

        root.addEventListener('click', function (event) {
            var opener = event.target.closest('[data-seo-manage-urls]');
            if (!opener || !root.contains(opener)) return;
            event.preventDefault();
            var websiteId = Number.parseInt(opener.dataset.websiteId || '', 10);
            if (!Number.isInteger(websiteId) || websiteId < 0) return;
            activeOpener = opener;
            openForWebsite(websiteId, opener.dataset.websiteName || '', opener.dataset.websiteCode || '');
        });

        [moduleSelect, localeSelect, statusSelect].forEach(function (control) {
            if (!control) return;
            control.addEventListener('change', function () {
                state.page = 1;
                loadUrls();
            });
        });
        if (keywordInput) {
            var keywordTimer = null;
            keywordInput.addEventListener('input', function () {
                window.clearTimeout(keywordTimer);
                keywordTimer = window.setTimeout(function () {
                    state.page = 1;
                    loadUrls();
                }, 280);
            });
        }
        var reloadBtn = panel.querySelector('[data-seo-url-reload]');
        if (reloadBtn) reloadBtn.addEventListener('click', function () { loadUrls(); });
        var prevBtn = panel.querySelector('[data-seo-url-prev]');
        if (prevBtn) prevBtn.addEventListener('click', function () {
            if (state.page > 1) { state.page -= 1; loadUrls(); }
        });
        var nextBtn = panel.querySelector('[data-seo-url-next]');
        if (nextBtn) nextBtn.addEventListener('click', function () {
            var totalPages = Math.max(1, Math.ceil((state.total || 0) / state.pageSize));
            if (state.page < totalPages) { state.page += 1; loadUrls(); }
        });
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                panel.querySelectorAll('[data-seo-url-id]').forEach(function (input) {
                    input.checked = !!selectAll.checked;
                });
            });
        }

        var pendingDeleteToken = '';
        var pendingDeleteTimer = null;
        function requestDeleteConfirm(token) {
            if (pendingDeleteToken === token) {
                pendingDeleteToken = '';
                if (pendingDeleteTimer) window.clearTimeout(pendingDeleteTimer);
                return true;
            }
            pendingDeleteToken = token;
            if (pendingDeleteTimer) window.clearTimeout(pendingDeleteTimer);
            pendingDeleteTimer = window.setTimeout(function () { pendingDeleteToken = ''; }, 4000);
            toast('warning', message(root, 'urlReconfirmDelete') || message(root, 'urlConfirmDelete'));
            return false;
        }

        panel.addEventListener('click', function (event) {
            var saveBtn = event.target.closest('[data-seo-url-save]');
            if (saveBtn) {
                var row = saveBtn.closest('tr');
                if (!row) return;
                var urlId = Number.parseInt(row.dataset.urlId || '', 10);
                var changefreq = row.querySelector('[data-seo-url-changefreq]');
                var priority = row.querySelector('[data-seo-url-priority]');
                setButtonLoading(saveBtn, true);
                resource(root).then(function (api) {
                    return api.updateSitemapUrl({
                        url_id: urlId,
                        changefreq: changefreq ? changefreq.value : undefined,
                        priority: priority ? priority.value : undefined,
                    });
                }).then(function (response) {
                    var data = unwrap(response) || {};
                    toast(data.success ? 'success' : 'error', data.message || message(root, data.success ? 'urlUpdated' : 'failed'));
                    if (data.success) loadUrls();
                }).catch(function (error) {
                    toast('error', formatApiError(error, root));
                }).finally(function () { setButtonLoading(saveBtn, false); });
                return;
            }

            var toggleBtn = event.target.closest('[data-seo-url-toggle-status]');
            if (toggleBtn) {
                var toggleRow = toggleBtn.closest('tr');
                if (!toggleRow) return;
                var toggleId = Number.parseInt(toggleRow.dataset.urlId || '', 10);
                var nextStatus = Number(toggleBtn.dataset.status) === 1 ? 0 : 1;
                setButtonLoading(toggleBtn, true);
                resource(root).then(function (api) {
                    return api.updateSitemapUrl({ url_id: toggleId, status: nextStatus });
                }).then(function (response) {
                    var data = unwrap(response) || {};
                    toast(data.success ? 'success' : 'error', data.message || message(root, data.success ? 'urlUpdated' : 'failed'));
                    if (data.success) loadUrls();
                }).catch(function (error) {
                    toast('error', formatApiError(error, root));
                }).finally(function () { setButtonLoading(toggleBtn, false); });
                return;
            }

            var deleteBtn = event.target.closest('[data-seo-url-delete]');
            if (deleteBtn) {
                var deleteRow = deleteBtn.closest('tr');
                if (!deleteRow) return;
                var deleteId = Number.parseInt(deleteRow.dataset.urlId || '', 10);
                if (!requestDeleteConfirm('one:' + deleteId)) return;
                setButtonLoading(deleteBtn, true);
                resource(root).then(function (api) {
                    return api.deleteSitemapUrls({ url_ids: [deleteId] });
                }).then(function (response) {
                    var data = unwrap(response) || {};
                    toast(data.success ? 'success' : 'error', data.message || message(root, data.success ? 'urlDeleted' : 'failed'));
                    if (data.success) loadUrls();
                }).catch(function (error) {
                    toast('error', formatApiError(error, root));
                }).finally(function () { setButtonLoading(deleteBtn, false); });
                return;
            }

            var bulkStatus = event.target.closest('[data-seo-url-bulk-status]');
            if (bulkStatus) {
                var ids = selectedUrlIds();
                if (!ids.length) {
                    toast('warning', message(root, 'urlSelectRows'));
                    return;
                }
                var statusValue = Number.parseInt(bulkStatus.dataset.seoUrlBulkStatus || '', 10);
                setButtonLoading(bulkStatus, true);
                Promise.all(ids.map(function (urlId) {
                    return resource(root).then(function (api) {
                        return api.updateSitemapUrl({ url_id: urlId, status: statusValue });
                    });
                })).then(function () {
                    toast('success', message(root, 'urlUpdated'));
                    loadUrls();
                }).catch(function (error) {
                    toast('error', formatApiError(error, root));
                }).finally(function () { setButtonLoading(bulkStatus, false); });
                return;
            }

            var bulkDelete = event.target.closest('[data-seo-url-bulk-delete]');
            if (bulkDelete) {
                var deleteIds = selectedUrlIds();
                if (!deleteIds.length) {
                    toast('warning', message(root, 'urlSelectRows'));
                    return;
                }
                if (!requestDeleteConfirm('bulk:' + deleteIds.join(','))) return;
                setButtonLoading(bulkDelete, true);
                resource(root).then(function (api) {
                    return api.deleteSitemapUrls({ url_ids: deleteIds });
                }).then(function (response) {
                    var data = unwrap(response) || {};
                    toast(data.success ? 'success' : 'error', data.message || message(root, data.success ? 'urlDeleted' : 'failed'));
                    if (data.success) loadUrls();
                }).catch(function (error) {
                    toast('error', formatApiError(error, root));
                }).finally(function () { setButtonLoading(bulkDelete, false); });
            }
        });
    }

    function initAccount(root) {
        var search = root.querySelector('[data-seo-account-search]');
        if (search) search.addEventListener('input', function () {
            var term = search.value.trim().toLowerCase();
            root.querySelectorAll('[data-seo-account-row]').forEach(function (row) {
                row.classList.toggle('d-none', term !== '' && !(row.dataset.search || '').includes(term));
            });
        });
        root.addEventListener('click', function (event) {
            var button = event.target.closest('[data-seo-sync-account]');
            if (!button) return;
            setButtonLoading(button, true);
            resource(root).then(function (api) { return api.syncAccountStats({ account_id: Number.parseInt(button.dataset.seoSyncAccount, 10) }); }).then(function (response) {
                var data = unwrap(response);
                toast(data && data.success ? 'success' : 'error', data && data.message || message(root, 'statsCompleted'));
                if (data && data.success) window.setTimeout(function () { window.location.reload(); }, 600);
            }).catch(function (error) { toast('error', formatApiError(error, root)); }).finally(function () { setButtonLoading(button, false); });
        });
    }

    function initAccountForm(root) {
        var form = root.querySelector('[data-seo-account-form]');
        if (!form) return;
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var button = form.querySelector('[type="submit"]');
            var formData = new FormData(form);
            var payload = {};
            formData.forEach(function (value, key) { payload[key] = value; });
            payload.account_id = Number.parseInt(payload.account_id || payload.id || '0', 10) || 0;
            payload.is_active = form.querySelector('[name="is_active"]:checked') ? 1 : 0;
            payload.enable_cron_push_urls = !!form.querySelector('[name="enable_cron_push_urls"]:checked');
            payload.enable_cron_sitemap = !!form.querySelector('[name="enable_cron_sitemap"]:checked');
            if (!String(payload.config_json || '').trim()) delete payload.config_json;
            setButtonLoading(button, true);
            resource(root).then(function (api) { return api.saveAccount(payload); }).then(function (response) {
                var data = unwrap(response);
                toast(data && data.success ? 'success' : 'error', data && data.message || message(root, 'saveCompleted'));
                if (data && data.success) window.setTimeout(function () { window.location.href = root.dataset.returnUrl; }, 600);
            }).catch(function (error) { toast('error', formatApiError(error, root) || message(root, 'saveFailed')); }).finally(function () { setButtonLoading(button, false); });
        });
    }

    function initWebsiteBindings(root) {
        if (root.dataset.seoAdminInitialized === '1') return;
        root.dataset.seoAdminInitialized = '1';

        function showMessage(type, text) {
            var target = root.querySelector('[data-role="seo-message"]');
            if (target) {
                target.className = 'seo-message ' + (type === 'success' ? 'is-success' : 'is-error');
                target.textContent = text || '';
            }
            toast(type, text || '');
        }

        function syncCard(card) {
            var checkbox = card.querySelector('[data-account-checkbox]');
            if (checkbox) card.classList.toggle('is-selected', checkbox.checked);
        }

        root.querySelectorAll('[data-account-card]').forEach(function (card) {
            var checkbox = card.querySelector('[data-account-checkbox]');
            if (checkbox) {
                checkbox.addEventListener('change', function () { syncCard(card); });
                syncCard(card);
            }
            card.addEventListener('click', function (event) {
                if (!checkbox || event.target.closest('input, select, textarea, button, a, label')) return;
                checkbox.checked = !checkbox.checked;
                checkbox.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });

        var form = root.querySelector('.seo-widget-form');
        if (!form) return;
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var submit = root.querySelector('[data-role="seo-submit"]');
            if (submit) submit.dataset.loadingLabel = message(root, 'saving');
            var accountIds = [];
            var configs = {};
            root.querySelectorAll('[data-account-card]').forEach(function (card) {
                var checkbox = card.querySelector('[data-account-checkbox]');
                if (!checkbox || !checkbox.checked) return;
                var accountId = Number.parseInt(checkbox.value || '0', 10);
                if (!Number.isInteger(accountId) || accountId <= 0) return;
                accountIds.push(accountId);
                var sitemapFrequency = card.querySelector('select[name*="[sitemap_frequency]"]');
                var crawlFrequency = card.querySelector('select[name*="[crawl_frequency]"]');
                var priority = card.querySelector('input[name*="[priority]"]');
                var autoSubmit = card.querySelector('input[type="checkbox"][name*="[is_auto_submit]"]');
                var urlPush = card.querySelector('input[type="checkbox"][name*="[enable_url_push]"]');
                configs[accountId] = {
                    sitemap_frequency: sitemapFrequency ? sitemapFrequency.value : 'daily',
                    crawl_frequency: crawlFrequency ? crawlFrequency.value : 'weekly',
                    priority: priority ? priority.value : '0.5',
                    is_auto_submit: !!(autoSubmit && autoSubmit.checked),
                    enable_url_push: !!(urlPush && urlPush.checked)
                };
            });
            setButtonLoading(submit, true);
            resource(root).then(function (api) {
                return api.saveWebsiteBindings({
                    website_id: Number.parseInt(root.dataset.websiteId || '0', 10),
                    account_ids: accountIds,
                    configs: configs
                });
            }).then(function (response) {
                var data = unwrap(response);
                if (!data || !data.success) {
                    showMessage('error', data && data.message || message(root, 'saveFailed'));
                    return;
                }
                showMessage('success', data.message || message(root, 'saveSuccess'));
                document.dispatchEvent(new CustomEvent('websiteBindingSaved', { detail: { success: true, message: data.message || '' } }));
                window.setTimeout(function () {
                    if (window.opener && !window.opener.closed) {
                        window.opener.location.reload();
                        window.close();
                    } else if (!window.parent || window.parent === window) {
                        window.location.reload();
                    }
                }, 900);
            }).catch(function (error) {
                showMessage('error', formatApiError(error, root) || message(root, 'requestFailed'));
            }).finally(function () { setButtonLoading(submit, false); });
        });
    }

    function start() {
        document.querySelectorAll('[data-seo-admin-page]').forEach(function (root) {
            var page = root.dataset.seoAdminPage;
            if (page === 'sitemap') initSitemap(root);
            if (page === 'account') initAccount(root);
            if (page === 'account-form') initAccountForm(root);
        });
        document.querySelectorAll('[data-seo-website-account-widget]').forEach(initWebsiteBindings);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})();
