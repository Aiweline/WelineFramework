(function (window, document) {
    'use strict';

    var root = document.querySelector('.weline-cache-admin');
    var configNode = document.getElementById('weline-cache-page-config');

    if (!root || !configNode) {
        return;
    }

    var config = {};
    try {
        config = JSON.parse(configNode.textContent || '{}');
    } catch (error) {
        console.error('[Weline Cache Admin] Failed to parse config.', error);
    }

    var urls = config.urls || {};
    var strings = config.strings || {};
    var selectAll = document.getElementById('cache-select-all');
    var selectedCountNode = document.getElementById('cache-selected-count');
    var enableSelectedButton = document.getElementById('cache-enable-selected');
    var disableSelectedButton = document.getElementById('cache-disable-selected');
    var clearSelectedButton = document.getElementById('cache-clear-selected');
    var clearAllButton = document.getElementById('cache-clear-all');
    var forceFlushAllButton = document.getElementById('cache-force-flush-all');
    var runCronButton = document.getElementById('cache-run-cron');
    var refreshPageButton = document.getElementById('cache-refresh-page');

    function text(key, fallback) {
        var value = strings[key];
        return value == null || value === '' ? fallback : String(value);
    }

    function notify(type, message) {
        if (window.BackendToast && typeof window.BackendToast[type] === 'function') {
            window.BackendToast[type](message);
            return;
        }

        if (window.BackendToast && typeof window.BackendToast.info === 'function') {
            window.BackendToast.info(message);
            return;
        }

        console[type === 'error' ? 'error' : 'log'](message);
    }

    function confirmAction(message, options) {
        if (window.BackendConfirm && typeof window.BackendConfirm.show === 'function') {
            return window.BackendConfirm.show(message, options || {});
        }

        console.warn('[Weline Cache Admin] BackendConfirm is unavailable; action cancelled.');
        return Promise.resolve(false);
    }

    function requestJson(url, options) {
        var requestOptions = Object.assign({
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }, options || {});

        requestOptions.headers = Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }, requestOptions.headers || {});

        if (requestOptions.body && typeof requestOptions.body !== 'string') {
            requestOptions.headers['Content-Type'] = 'application/json';
            requestOptions.body = JSON.stringify(requestOptions.body);
        }

        return fetch(url, requestOptions).then(function (response) {
            return response.text().then(function (content) {
                var payload;

                try {
                    payload = content ? JSON.parse(content) : {};
                } catch (error) {
                    throw new Error(text('request_failed', 'Request failed'));
                }

                if (!response.ok || (typeof payload.code !== 'undefined' && Number(payload.code) >= 400)) {
                    throw new Error(payload.msg || payload.message || text('action_failed', 'Action failed'));
                }

                return payload;
            });
        });
    }

    function getCards() {
        return Array.prototype.slice.call(root.querySelectorAll('.weline-cache-pool[data-identity]'));
    }

    function getCard(identity) {
        return getCards().find(function (card) {
            return card.dataset.identity === identity;
        }) || null;
    }

    function getCheckboxes() {
        return Array.prototype.slice.call(root.querySelectorAll('.weline-cache-select'));
    }

    function getSelectedCheckboxes() {
        return getCheckboxes().filter(function (checkbox) {
            return checkbox.checked && !checkbox.disabled;
        });
    }

    function getSelectedIdentities() {
        return getSelectedCheckboxes().map(function (checkbox) {
            return checkbox.value;
        }).filter(Boolean);
    }

    function formatCount(value) {
        var number = Number(value || 0);
        if (!isFinite(number)) {
            number = 0;
        }
        return number.toLocaleString();
    }

    function formatPercent(value) {
        var number = Number(value || 0);
        if (!isFinite(number)) {
            number = 0;
        }
        return number.toFixed(2) + '%';
    }

    function formatBytes(value) {
        var size = Number(value || 0);
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var index = 0;

        if (!isFinite(size) || size <= 0) {
            return '0 B';
        }

        while (size >= 1024 && index < units.length - 1) {
            size = size / 1024;
            index += 1;
        }

        return size.toFixed(index === 0 ? 0 : 1) + ' ' + units[index];
    }

    function formatTtl(ttl) {
        var value = Number(ttl || 0);

        if (!isFinite(value) || value <= 0) {
            return text('permanent', 'Permanent');
        }
        if (value < 60) {
            return Math.floor(value) + 's';
        }
        if (value < 3600) {
            return Math.floor(value / 60) + 'm';
        }
        if (value < 86400) {
            return Math.floor(value / 3600) + 'h';
        }

        return Math.floor(value / 86400) + 'd';
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(value == null ? '' : String(value)));
        return div.innerHTML;
    }

    function setNodeText(selector, value) {
        var node = document.querySelector(selector);
        if (node) {
            node.textContent = value;
        }
    }

    function setButtonBusy(button, busy) {
        if (!button) {
            return;
        }

        button.disabled = !!busy;
        button.dataset.busy = busy ? '1' : '0';
    }

    function syncCardDisabledState(card) {
        var busy = card.dataset.busy === '1';
        var permanent = card.dataset.permanent === '1';

        Array.prototype.slice.call(card.querySelectorAll('button, .weline-cache-select')).forEach(function (element) {
            element.disabled = busy;
        });

        var toggle = card.querySelector('.weline-cache-toggle');
        if (toggle) {
            toggle.disabled = busy || permanent;
        }
    }

    function setCardBusy(card, busy) {
        if (!card) {
            return;
        }

        card.dataset.busy = busy ? '1' : '0';
        syncCardDisabledState(card);
    }

    function updateSelectionState() {
        var checkboxes = getCheckboxes().filter(function (checkbox) {
            return !checkbox.disabled;
        });
        var selected = getSelectedCheckboxes();
        var togglable = selected.filter(function (checkbox) {
            var card = checkbox.closest('.weline-cache-pool');
            return card && card.dataset.permanent !== '1';
        });

        if (selectedCountNode) {
            selectedCountNode.textContent = String(selected.length);
        }

        if (selectAll) {
            selectAll.checked = checkboxes.length > 0 && selected.length === checkboxes.length;
            selectAll.indeterminate = selected.length > 0 && selected.length < checkboxes.length;
        }

        if (enableSelectedButton) {
            enableSelectedButton.disabled = togglable.length === 0;
        }

        if (disableSelectedButton) {
            disableSelectedButton.disabled = togglable.length === 0;
        }

        if (clearSelectedButton) {
            clearSelectedButton.disabled = selected.length === 0;
        }
    }

    function applySampleKeys(card, sampleKeys) {
        var section = card.querySelector('[data-role="sample-keys-section"]');
        var footer = card.querySelector('.weline-cache-pool__footer');

        if (!Array.isArray(sampleKeys) || sampleKeys.length === 0) {
            if (section) {
                section.remove();
            }
            return;
        }

        if (!section) {
            section = document.createElement('div');
            section.dataset.role = 'sample-keys-section';
            section.innerHTML = ''
                + '<div class="weline-cache-muted mb-2">' + escapeHtml(text('sample_keys', 'Sample Keys')) + '</div>'
                + '<div class="weline-cache-keys" data-role="sample-keys"></div>';

            if (footer && footer.parentNode) {
                footer.parentNode.insertBefore(section, footer);
            }
        }

        var list = section.querySelector('[data-role="sample-keys"]');
        if (list) {
            list.innerHTML = sampleKeys.map(function (value) {
                return '<span class="weline-cache-key">' + escapeHtml(value) + '</span>';
            }).join('');
        }
    }

    function applyCardData(card, item) {
        var enabled = !!item.enabled;
        var permanent = !!item.permanent;
        var sharedKeys = Number(item.shared_keys || 0);
        var statusBadge = card.querySelector('[data-role="status-badge"]');
        var statusText = card.querySelector('[data-role="status-text"]');
        var statusIcon = statusBadge ? statusBadge.querySelector('i') : null;
        var toggle = card.querySelector('.weline-cache-toggle');
        var toggleLabel = card.querySelector('[data-role="toggle-label"]');
        var clearLabel = card.querySelector('[data-role="clear-label"]');

        card.dataset.enabled = enabled ? '1' : '0';
        card.dataset.permanent = permanent ? '1' : '0';
        card.classList.toggle('is-disabled', !enabled);
        card.classList.toggle('is-permanent', permanent);

        if (statusBadge) {
            statusBadge.classList.toggle('weline-cache-pill--status-on', enabled);
            statusBadge.classList.toggle('weline-cache-pill--status-off', !enabled);
        }

        if (statusIcon) {
            statusIcon.className = enabled ? 'ri-checkbox-circle-line' : 'ri-close-circle-line';
        }

        if (statusText) {
            statusText.textContent = enabled ? text('enabled', 'Enabled') : text('disabled', 'Disabled');
        }

        if (toggle) {
            toggle.checked = enabled;
        }

        if (toggleLabel) {
            toggleLabel.textContent = enabled ? text('enabled', 'Enabled') : text('disabled', 'Disabled');
            toggleLabel.classList.toggle('is-off', !enabled);
        }

        if (clearLabel) {
            clearLabel.textContent = permanent ? text('force_clear', 'Force Clear') : text('clear', 'Clear');
        }

        var mappings = [
            ['.weline-cache-pool__module', item.module || 'Weline_Framework'],
            ['.weline-cache-pool__name', item.name || item.identity || ''],
            ['.weline-cache-pool__identity', item.identity || ''],
            ['.weline-cache-pool__description', item.description || item.identity || ''],
            ['[data-role="storage-label"]', item.storage_label || text('unknown', 'Unknown')],
            ['[data-role="adapter-label"]', item.adapter_label || text('unknown', 'Unknown')],
            ['[data-role="shared-namespace"]', item.shared_namespace || text('none', 'None')],
            ['[data-role="tip"]', item.tip || text('none', 'None')]
        ];

        mappings.forEach(function (mapping) {
            var node = card.querySelector(mapping[0]);
            if (node) {
                node.textContent = mapping[1];
            }
        });

        var ttlLabel = card.querySelector('[data-role="ttl-label"]');
        if (ttlLabel) {
            ttlLabel.dataset.ttl = String(item.default_ttl || 0);
            ttlLabel.textContent = formatTtl(item.default_ttl);
        }

        var sharedLabel = card.querySelector('[data-role="shared-label"]');
        if (sharedLabel) {
            sharedLabel.textContent = sharedKeys > 0
                ? text('shared_keys', 'Shared Keys') + ' ' + formatCount(sharedKeys)
                : text('no_shared_keys', 'No Shared Keys');
        }

        setNodeTextForCard(card, '[data-role="hit-ratio"]', formatPercent(item.hit_ratio || 0));
        setNodeTextForCard(card, '[data-role="hit-miss"]', formatCount(item.hits || 0) + ' / ' + formatCount(item.misses || 0));
        applySampleKeys(card, item.sample_keys || []);
        syncCardDisabledState(card);
        updateSelectionState();
    }

    function setNodeTextForCard(card, selector, value) {
        var node = card.querySelector(selector);
        if (node) {
            node.textContent = value;
        }
    }

    function refreshSummary() {
        if (!urls.stats) {
            return Promise.resolve();
        }

        return requestJson(urls.stats, { method: 'GET' }).then(function (payload) {
            var data = payload.data || {};
            var summary = data.summary || {};
            var runtime = data.runtime || {};
            var memoryStats = ((runtime.memory_overview || {}).stats) || {};

            setNodeText('#cache-summary-total', formatCount(summary.total || 0));
            setNodeText('#cache-summary-enabled', formatCount(summary.enabled || 0));
            setNodeText('#cache-summary-disabled', formatCount(summary.disabled || 0));
            setNodeText('#cache-summary-permanent', formatCount(summary.permanent || 0));
            setNodeText('#cache-summary-wls', formatCount(summary.wls_backed || 0));
            setNodeText('#cache-total-reference', formatCount(summary.total || 0));
            setNodeText('#cache-filtered-total', formatCount(getCards().length));
            setNodeText('#cache-runtime-mode', String((runtime.mode || 'unknown')).toUpperCase());
            setNodeText('#cache-runtime-memory-state', runtime.memory_connected ? text('connected', 'Connected') : text('disconnected', 'Disconnected'));
            setNodeText('#cache-runtime-namespace-count', formatCount(summary.shared_namespace_count || 0));
            setNodeText('#cache-runtime-shared-keys', formatCount(summary.shared_key_total || 0));

            var runtimeItems = Array.prototype.slice.call(document.querySelectorAll('.weline-cache-runtime-item strong'));
            if (runtimeItems.length >= 4) {
                runtimeItems[0].textContent = runtime.persistent ? text('yes', 'Yes') : text('no', 'No');
                runtimeItems[1].textContent = runtime.file_hijacked ? text('enabled', 'Enabled') : text('disabled', 'Disabled');
                runtimeItems[2].textContent = formatCount(memoryStats.client_count || 0);
                runtimeItems[3].textContent = formatBytes(memoryStats.memory_usage || 0);
            }
        });
    }

    function refreshCard(identity, silent) {
        if (!urls.pool_stats || !identity) {
            return Promise.resolve();
        }

        var card = getCard(identity);
        if (card) {
            setCardBusy(card, true);
        }

        var url = urls.pool_stats + (urls.pool_stats.indexOf('?') === -1 ? '?' : '&') + 'identity=' + encodeURIComponent(identity);

        return requestJson(url, { method: 'GET' }).then(function (payload) {
            if (card) {
                applyCardData(card, payload.data || {});
            }
            return payload.data || {};
        }).catch(function (error) {
            if (!silent) {
                notify('error', error.message || text('request_failed', 'Request failed'));
            }
            throw error;
        }).finally(function () {
            if (card) {
                setCardBusy(card, false);
            }
        });
    }

    function refreshCards(identities) {
        return Promise.allSettled((identities || []).map(function (identity) {
            return refreshCard(identity, true);
        }));
    }

    function handlePoolToggle(card, enabled) {
        var identity = card.dataset.identity;
        var toggle = card.querySelector('.weline-cache-toggle');
        var previous = card.dataset.enabled === '1';

        setCardBusy(card, true);

        requestJson(urls.status, {
            method: 'POST',
            body: {
                identity: identity,
                cache: enabled ? 1 : 0
            }
        }).then(function (payload) {
            notify('success', payload.msg || text('status_updated', 'Status updated'));
            return Promise.all([refreshCard(identity, true), refreshSummary()]);
        }).catch(function (error) {
            if (toggle) {
                toggle.checked = previous;
            }
            notify('error', error.message || text('action_failed', 'Action failed'));
        }).finally(function () {
            setCardBusy(card, false);
        });
    }

    function updateSelectedStatuses(enabled) {
        var cards = getSelectedCheckboxes().map(function (checkbox) {
            return checkbox.closest('.weline-cache-pool');
        }).filter(function (card) {
            return card && card.dataset.permanent !== '1';
        });
        var identities = cards.map(function (card) {
            return card.dataset.identity;
        });

        if (identities.length === 0) {
            notify('warning', text('select_toggle_empty', 'No cache pools available for status changes.'));
            return;
        }

        var proceed = enabled ? Promise.resolve(enabled === true) : confirmAction(
            text('confirm_disable_selected', 'Disable the selected cache pools?'),
            {
                title: text('title_disable_selected', 'Disable Selected'),
                type: 'warning',
                confirmText: text('confirm_continue', 'Continue'),
                cancelText: text('confirm_cancel', 'Cancel')
            }
        );

        proceed.then(function (confirmed) {
            if (!confirmed) {
                return;
            }

            cards.forEach(function (card) {
                setCardBusy(card, true);
            });
            setButtonBusy(enabled ? enableSelectedButton : disableSelectedButton, true);

            requestJson(urls.status_batch, {
                method: 'POST',
                body: {
                    identities: identities,
                    cache: enabled ? 1 : 0
                }
            }).then(function (payload) {
                notify('success', payload.msg || text('status_batch_updated', 'Statuses updated'));
                return Promise.all([refreshCards(identities), refreshSummary()]);
            }).catch(function (error) {
                notify('error', error.message || text('action_failed', 'Action failed'));
            }).finally(function () {
                cards.forEach(function (card) {
                    setCardBusy(card, false);
                });
                setButtonBusy(enabled ? enableSelectedButton : disableSelectedButton, false);
            });
        });
    }

    function clearPool(identity, force, silent) {
        var card = getCard(identity);

        if (card) {
            setCardBusy(card, true);
        }

        return requestJson(urls.clear, {
            method: 'POST',
            body: {
                identity: identity,
                force: force ? 1 : 0
            }
        }).then(function (payload) {
            if (!silent) {
                notify('success', payload.msg || text('clear_done', 'Cache cleared'));
            }
            return Promise.all([refreshCard(identity, true), refreshSummary()]);
        }).catch(function (error) {
            if (!silent) {
                notify('error', error.message || text('clear_failed', 'Clear failed'));
            }
            throw error;
        }).finally(function () {
            if (card) {
                setCardBusy(card, false);
            }
        });
    }

    function clearSelected() {
        var identities = getSelectedIdentities();
        var cards = identities.map(getCard).filter(Boolean);
        var hasPermanent = cards.some(function (card) {
            return card.dataset.permanent === '1';
        });

        if (identities.length === 0) {
            return;
        }

        confirmAction(
            hasPermanent ? text('confirm_clear_selected_force', 'Force clear the selected cache pools?') : text('confirm_clear_selected', 'Clear the selected cache pools?'),
            {
                title: hasPermanent ? text('title_clear_selected_force', 'Force Clear Selected') : text('title_clear_selected', 'Clear Selected'),
                type: hasPermanent ? 'danger' : 'warning',
                confirmText: hasPermanent ? text('confirm_force', 'Force Clear') : text('confirm_clear', 'Clear'),
                cancelText: text('confirm_cancel', 'Cancel')
            }
        ).then(function (confirmed) {
            if (!confirmed) {
                return;
            }

            cards.forEach(function (card) {
                setCardBusy(card, true);
            });
            setButtonBusy(clearSelectedButton, true);

            Promise.allSettled(identities.map(function (identity) {
                return clearPool(identity, hasPermanent, true);
            })).then(function (results) {
                var failed = results.filter(function (result) {
                    return result.status === 'rejected';
                });

                if (failed.length === 0) {
                    notify('success', text('clear_selected_done', 'Selected cache pools cleared.'));
                } else {
                    notify('warning', text('clear_selected_partial', 'Some cache pools could not be cleared.'));
                }

                return refreshSummary();
            }).finally(function () {
                cards.forEach(function (card) {
                    setCardBusy(card, false);
                });
                setButtonBusy(clearSelectedButton, false);
                updateSelectionState();
            });
        });
    }

    function clearAll(force) {
        confirmAction(
            force ? text('confirm_force_flush_all', 'Force clear all cache pools?') : text('confirm_clear_all', 'Clear all non-permanent cache pools?'),
            {
                title: force ? text('title_force_flush_all', 'Force Clear All') : text('title_clear_all', 'Clear All'),
                type: force ? 'danger' : 'warning',
                confirmText: force ? text('confirm_force', 'Force Clear') : text('confirm_clear', 'Clear'),
                cancelText: text('confirm_cancel', 'Cancel')
            }
        ).then(function (confirmed) {
            if (!confirmed) {
                return;
            }

            setButtonBusy(force ? forceFlushAllButton : clearAllButton, true);
            getCards().forEach(function (card) {
                setCardBusy(card, true);
            });

            requestJson(urls.clear_all, {
                method: 'POST',
                body: {
                    force: force ? 1 : 0
                }
            }).then(function (payload) {
                notify('success', payload.msg || text('clear_done', 'Cache cleared'));
                return Promise.all([refreshCards(getCards().map(function (card) {
                    return card.dataset.identity;
                })), refreshSummary()]);
            }).catch(function (error) {
                notify('error', error.message || text('clear_failed', 'Clear failed'));
            }).finally(function () {
                setButtonBusy(force ? forceFlushAllButton : clearAllButton, false);
                getCards().forEach(function (card) {
                    setCardBusy(card, false);
                });
            });
        });
    }

    function runCron() {
        setButtonBusy(runCronButton, true);

        requestJson(urls.run_cron, {
            method: 'POST',
            body: {}
        }).then(function (payload) {
            notify('success', payload.msg || text('cron_done', 'Cron task completed'));
            return refreshSummary();
        }).catch(function (error) {
            notify('error', error.message || text('cron_failed', 'Execution failed'));
        }).finally(function () {
            setButtonBusy(runCronButton, false);
        });
    }

    function bindSelection() {
        getCheckboxes().forEach(function (checkbox) {
            checkbox.addEventListener('change', updateSelectionState);
        });

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                getCheckboxes().forEach(function (checkbox) {
                    if (!checkbox.disabled) {
                        checkbox.checked = selectAll.checked;
                    }
                });
                updateSelectionState();
            });
        }

        updateSelectionState();
    }

    function bindPoolActions() {
        root.addEventListener('change', function (event) {
            var toggle = event.target.closest('.weline-cache-toggle');
            if (!toggle) {
                return;
            }

            var card = toggle.closest('.weline-cache-pool');
            if (!card || card.dataset.permanent === '1') {
                toggle.checked = true;
                return;
            }

            handlePoolToggle(card, toggle.checked);
        });

        root.addEventListener('click', function (event) {
            var clearTrigger = event.target.closest('.weline-cache-clear-pool');
            if (clearTrigger) {
                var clearCard = clearTrigger.closest('.weline-cache-pool');
                var force = clearCard && clearCard.dataset.permanent === '1';

                confirmAction(
                    force ? text('confirm_clear_single_force', 'Force clear this cache pool?') : text('confirm_clear_single', 'Clear this cache pool?'),
                    {
                        title: force ? text('title_clear_single_force', 'Force Clear Persistent Cache') : text('title_clear_single', 'Clear Cache Pool'),
                        type: force ? 'danger' : 'warning',
                        confirmText: force ? text('confirm_force', 'Force Clear') : text('confirm_clear', 'Clear'),
                        cancelText: text('confirm_cancel', 'Cancel')
                    }
                ).then(function (confirmed) {
                    if (confirmed && clearCard) {
                        clearPool(clearCard.dataset.identity, force, false).catch(function () {});
                    }
                });
                return;
            }

            var refreshTrigger = event.target.closest('.weline-cache-refresh-pool');
            if (refreshTrigger) {
                var refreshCardNode = refreshTrigger.closest('.weline-cache-pool');
                if (refreshCardNode) {
                    refreshCard(refreshCardNode.dataset.identity, false).then(function () {
                        notify('info', text('stats_refreshed', 'Cache stats refreshed'));
                    }).catch(function () {});
                }
            }
        });
    }

    function bindToolbar() {
        if (enableSelectedButton) {
            enableSelectedButton.addEventListener('click', function () {
                updateSelectedStatuses(true);
            });
        }

        if (disableSelectedButton) {
            disableSelectedButton.addEventListener('click', function () {
                updateSelectedStatuses(false);
            });
        }

        if (clearSelectedButton) {
            clearSelectedButton.addEventListener('click', clearSelected);
        }

        if (clearAllButton) {
            clearAllButton.addEventListener('click', function () {
                clearAll(false);
            });
        }

        if (forceFlushAllButton) {
            forceFlushAllButton.addEventListener('click', function () {
                clearAll(true);
            });
        }

        if (runCronButton) {
            runCronButton.addEventListener('click', runCron);
        }

        if (refreshPageButton) {
            refreshPageButton.addEventListener('click', function () {
                window.location.reload();
            });
        }
    }

    bindSelection();
    bindPoolActions();
    bindToolbar();
    refreshSummary().catch(function () {});
})(window, document);
