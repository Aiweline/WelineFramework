/**
 * Account-center affiliate workbench (AJAX sidebar + discovery card).
 * Loaded via data-weline-load="affiliateAccount".
 */
(function () {
    'use strict';

    var DEFAULT_I18N = {
        copied: 'Copied',
        nothingToCopy: 'Nothing to copy',
        generating: 'Generating...',
        apiNotReady: 'Affiliate API is not ready',
        shareUnsupported: 'Share link is not supported',
        generateFailed: 'Failed to generate link',
        generateDone: 'Share link generated',
        invalidWithdrawAmount: 'Enter a valid withdrawal amount.',
        withdrawSubmitting: 'Submitting withdrawal...',
        withdrawUnsupported: 'Withdrawal is not supported',
        withdrawFailed: 'Withdrawal request failed',
        withdrawDone: 'Withdrawal submitted. Refreshing...',
        search: 'Search',
        searchPlaceholder: 'Search this report',
        perPage: 'Per page',
        showing: 'Showing',
        rows: 'rows',
        previous: 'Previous',
        next: 'Next',
        noMatch: 'No matching records'
    };

    var REPORT_SPECS = [
        { key: 'platform-funnel', selector: '[data-affiliate-platform-funnel-table]' },
        { key: 'withdrawals', selector: '[data-affiliate-withdrawals-table]' },
        { key: 'share-links', selector: '[data-affiliate-share-links-table]' },
        { key: 'referred-customers', selector: '[data-affiliate-referred-customers-table]' },
        { key: 'products', selector: '[data-affiliate-products-table]' },
        { key: 'orders', selector: '[data-affiliate-orders-table]' },
        { key: 'commissions', selector: '[data-affiliate-commissions-table]' }
    ];

    function readI18n(panel) {
        var merged = Object.assign({}, DEFAULT_I18N);
        var raw = panel && panel.getAttribute('data-affiliate-i18n');
        if (!raw) {
            return merged;
        }
        try {
            var parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object') {
                Object.keys(parsed).forEach(function (key) {
                    if (typeof parsed[key] === 'string' && parsed[key] !== '') {
                        merged[key] = parsed[key];
                    }
                });
            }
        } catch (error) {}
        return merged;
    }

    function copyText(value, fallbackInput, done) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(done).catch(function () {
                if (fallbackInput && typeof fallbackInput.select === 'function') {
                    fallbackInput.select();
                    document.execCommand('copy');
                }
                done();
            });
            return;
        }
        if (fallbackInput && typeof fallbackInput.select === 'function') {
            fallbackInput.select();
            document.execCommand('copy');
        }
        done();
    }

    function createReportField(labelText, control) {
        var label = document.createElement('label');
        label.className = 'weline-affiliate-account-card__report-field';
        var labelSpan = document.createElement('span');
        labelSpan.textContent = labelText;
        label.appendChild(labelSpan);
        label.appendChild(control);
        return label;
    }

    function enhanceReportTable(root, spec, reportText) {
        var table = root.querySelector(spec.selector);
        if (!table || table.getAttribute('data-affiliate-report-ready') === '1') {
            return;
        }
        table.setAttribute('data-affiliate-report-ready', '1');
        var tbody = table.tBodies && table.tBodies.length ? table.tBodies[0] : null;
        if (!tbody) {
            return;
        }
        var rows = Array.prototype.slice.call(tbody.rows || []);
        var dataRows = rows.filter(function (row) {
            return !row.querySelector('.weline-affiliate-account-card__empty');
        });
        if (dataRows.length === 0) {
            return;
        }

        var tableWrap = table.closest('.weline-affiliate-account-card__table-wrap') || table.parentNode;
        if (!tableWrap || !tableWrap.parentNode) {
            return;
        }

        var controls = document.createElement('div');
        controls.className = 'weline-affiliate-account-card__report-tools';
        controls.setAttribute('data-affiliate-report-controls', spec.key);

        var searchInput = document.createElement('input');
        searchInput.type = 'search';
        searchInput.className = 'weline-affiliate-account-card__report-search';
        searchInput.placeholder = reportText.searchPlaceholder;
        searchInput.setAttribute('data-affiliate-report-search', spec.key);
        searchInput.setAttribute('autocomplete', 'off');

        var sizeSelect = document.createElement('select');
        sizeSelect.className = 'weline-affiliate-account-card__report-size';
        sizeSelect.setAttribute('data-affiliate-report-size', spec.key);
        [2, 5, 10, 20].forEach(function (size) {
            var option = document.createElement('option');
            option.value = String(size);
            option.textContent = String(size);
            sizeSelect.appendChild(option);
        });
        sizeSelect.value = '5';

        var pager = document.createElement('div');
        pager.className = 'weline-affiliate-account-card__report-pager';
        var previous = document.createElement('button');
        previous.type = 'button';
        previous.className = 'weline-affiliate-account-card__report-page-button';
        previous.textContent = reportText.previous;
        previous.setAttribute('data-affiliate-report-prev', spec.key);
        var summary = document.createElement('span');
        summary.setAttribute('data-affiliate-report-summary', spec.key);
        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'weline-affiliate-account-card__report-page-button';
        next.textContent = reportText.next;
        next.setAttribute('data-affiliate-report-next', spec.key);
        pager.appendChild(previous);
        pager.appendChild(summary);
        pager.appendChild(next);

        controls.appendChild(createReportField(reportText.search, searchInput));
        controls.appendChild(createReportField(reportText.perPage, sizeSelect));
        controls.appendChild(pager);
        tableWrap.parentNode.insertBefore(controls, tableWrap);

        var noMatch = document.createElement('div');
        noMatch.className = 'weline-affiliate-account-card__empty weline-affiliate-account-card__report-empty';
        noMatch.setAttribute('data-affiliate-report-empty', spec.key);
        noMatch.textContent = reportText.noMatch;
        noMatch.hidden = true;
        tableWrap.parentNode.insertBefore(noMatch, tableWrap.nextSibling);

        var state = {
            page: 1,
            pageSize: parseInt(sizeSelect.value || '5', 10),
            query: ''
        };

        function render() {
            var query = state.query.toLowerCase();
            var filtered = dataRows.filter(function (row) {
                return query === '' || (row.textContent || '').toLowerCase().indexOf(query) !== -1;
            });
            var pageCount = Math.max(1, Math.ceil(filtered.length / state.pageSize));
            if (state.page > pageCount) {
                state.page = pageCount;
            }
            var start = filtered.length === 0 ? 0 : (state.page - 1) * state.pageSize;
            var end = filtered.length === 0 ? 0 : Math.min(start + state.pageSize, filtered.length);

            dataRows.forEach(function (row) {
                row.style.display = 'none';
            });
            filtered.slice(start, end).forEach(function (row) {
                row.style.display = '';
            });

            noMatch.hidden = filtered.length !== 0;
            summary.textContent = filtered.length === 0
                ? reportText.showing + ' 0 / 0 ' + reportText.rows
                : reportText.showing + ' ' + (start + 1) + '-' + end + ' / ' + filtered.length + ' ' + reportText.rows;
            previous.disabled = state.page <= 1;
            next.disabled = state.page >= pageCount;
        }

        searchInput.addEventListener('input', function () {
            state.query = searchInput.value || '';
            state.page = 1;
            render();
        });
        sizeSelect.addEventListener('change', function () {
            state.pageSize = parseInt(sizeSelect.value || '5', 10);
            state.page = 1;
            render();
        });
        previous.addEventListener('click', function () {
            if (state.page > 1) {
                state.page -= 1;
                render();
            }
        });
        next.addEventListener('click', function () {
            state.page += 1;
            render();
        });
        render();
    }

    function initPanel(panel) {
        if (!panel || panel.getAttribute('data-affiliate-copy-ready') === '1') {
            return;
        }
        panel.setAttribute('data-affiliate-copy-ready', '1');

        var root = panel.querySelector('.weline-affiliate-account-card__body') || panel;
        var i18n = readI18n(panel);
        var input = root.querySelector('[data-affiliate-referral-link]');
        var copy = root.querySelector('[data-affiliate-copy-link]');
        var status = root.querySelector('[data-affiliate-copy-status]');

        if (input && copy) {
            copy.addEventListener('click', function () {
                var value = input.value || '';
                copyText(value, input, function () {
                    if (status) {
                        status.textContent = i18n.copied;
                    }
                });
            });
        }

        root.querySelectorAll('[data-affiliate-copy-source]').forEach(function (button) {
            button.addEventListener('click', function () {
                var selector = button.getAttribute('data-affiliate-copy-source') || '';
                var source = selector ? root.querySelector(selector) : null;
                var value = source && source.value ? source.value : '';
                if (!value) {
                    if (status) {
                        status.textContent = i18n.nothingToCopy;
                    }
                    return;
                }
                copyText(value, source, function () {
                    if (status) {
                        status.textContent = i18n.copied;
                    }
                });
            });
        });

        var generateButton = root.querySelector('[data-affiliate-generate-link]');
        var targetInput = root.querySelector('[data-affiliate-custom-target]');
        var generatedInput = root.querySelector('[data-affiliate-generated-link]');
        if (generateButton && targetInput && generatedInput) {
            generateButton.addEventListener('click', function () {
                var targetUrl = targetInput.value || '';
                generateButton.disabled = true;
                if (status) {
                    status.textContent = i18n.generating;
                }
                Promise.resolve()
                    .then(function () {
                        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                            throw new Error(i18n.apiNotReady);
                        }
                        return window.Weline.Api.resource('affiliate');
                    })
                    .then(function (api) {
                        if (!api || typeof api.getShareLink !== 'function') {
                            throw new Error(i18n.shareUnsupported);
                        }
                        return api.getShareLink({ target_url: targetUrl, channel: '' });
                    })
                    .then(function (response) {
                        if (!response || response.success === false) {
                            throw new Error((response && response.message) || i18n.generateFailed);
                        }
                        generatedInput.value = response.data && response.data.tracking_url ? response.data.tracking_url : '';
                        if (status) {
                            status.textContent = i18n.generateDone;
                        }
                    })
                    .catch(function (error) {
                        if (status) {
                            status.textContent = error && error.message ? error.message : i18n.generateFailed;
                        }
                    })
                    .finally(function () {
                        generateButton.disabled = false;
                    });
            });
        }

        var withdrawalButton = root.querySelector('[data-affiliate-withdrawal-submit]');
        var withdrawalAmount = root.querySelector('[data-affiliate-withdrawal-amount]');
        var withdrawalAccount = root.querySelector('[data-affiliate-withdrawal-account]');
        if (withdrawalButton && withdrawalAmount) {
            withdrawalButton.addEventListener('click', function () {
                var amount = parseFloat(withdrawalAmount.value || '0');
                var accountLabel = withdrawalAccount ? (withdrawalAccount.value || '') : '';
                if (!amount || amount <= 0) {
                    if (status) {
                        status.textContent = i18n.invalidWithdrawAmount;
                    }
                    return;
                }
                withdrawalButton.disabled = true;
                if (status) {
                    status.textContent = i18n.withdrawSubmitting;
                }
                Promise.resolve()
                    .then(function () {
                        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                            throw new Error(i18n.apiNotReady);
                        }
                        return window.Weline.Api.resource('affiliate');
                    })
                    .then(function (api) {
                        if (!api || typeof api.requestWithdrawal !== 'function') {
                            throw new Error(i18n.withdrawUnsupported);
                        }
                        return api.requestWithdrawal({
                            amount: amount,
                            method: 'manual',
                            account_label: accountLabel
                        });
                    })
                    .then(function (response) {
                        if (!response || response.success === false) {
                            throw new Error((response && response.message) || i18n.withdrawFailed);
                        }
                        if (status) {
                            status.textContent = i18n.withdrawDone;
                        }
                        window.setTimeout(function () {
                            window.location.reload();
                        }, 600);
                    })
                    .catch(function (error) {
                        if (status) {
                            status.textContent = error && error.message ? error.message : i18n.withdrawFailed;
                        }
                    })
                    .finally(function () {
                        withdrawalButton.disabled = false;
                    });
            });
        }

        REPORT_SPECS.forEach(function (spec) {
            enhanceReportTable(root, spec, i18n);
        });
    }

    function initAll(scope) {
        var root = scope && scope.querySelectorAll ? scope : document;
        root.querySelectorAll('[data-affiliate-account-panel]').forEach(initPanel);
    }

    initAll(document);
    window.addEventListener('weline:account-sidebar-content-loaded', function () {
        initAll(document);
    });
})();
