/**
 * Storefront PLP: path-only FPC shell + BinQuery filteredListingPage.
 * Allowlisted ?price/sort/page/af_* SSR uses projectFiltered (data-listing-ssr-filtered=1)
 * — boot must NOT soft-refetch (keeps Shared FPC body). Filter clicks still soft BinQuery.
 * Sort / page / price / af_* refresh the grid in one shot; never full-page navigate.
 * Filter clicks are debounced + coalesced; stage shows loading while in flight.
 */
(function (global) {
    'use strict';

    var stateByRoot = new WeakMap();
    var DEBOUNCE_MS = 280;

    function unwrapPayload(result) {
        if (result && result.data && typeof result.data === 'object') {
            return result.data;
        }
        return result && typeof result === 'object' ? result : {};
    }

    function waitForApi() {
        if (global.Weline && global.Weline.Api && typeof global.Weline.Api.resource === 'function') {
            return Promise.resolve(global.Weline.Api);
        }
        return new Promise(function (resolve) {
            var tries = 0;
            var timer = global.setInterval(function () {
                tries += 1;
                if (global.Weline && global.Weline.Api && typeof global.Weline.Api.resource === 'function') {
                    global.clearInterval(timer);
                    resolve(global.Weline.Api);
                } else if (tries > 40) {
                    global.clearInterval(timer);
                    resolve(null);
                }
            }, 50);
        });
    }

    function cloneFilters(filters) {
        var out = {};
        if (!filters || typeof filters !== 'object') {
            return out;
        }
        Object.keys(filters).forEach(function (key) {
            if (filters[key]) {
                out[key] = String(filters[key]);
            }
        });
        return out;
    }

    function snapshotListing(state) {
        return {
            price: String(state.price || ''),
            sort: String(state.sort || 'default'),
            page: parseInt(String(state.page || 1), 10) || 1,
            filters: cloneFilters(state.filters),
        };
    }

    function applySnapshot(state, snap) {
        state.price = String((snap && snap.price) || '');
        state.sort = String((snap && snap.sort) || 'default');
        state.page = parseInt(String((snap && snap.page) || 1), 10) || 1;
        state.filters = cloneFilters(snap && snap.filters);
    }

    function getState(root) {
        var state = stateByRoot.get(root);
        if (!state) {
            state = {
                price: '',
                sort: 'default',
                page: 1,
                filters: {},
                // Last successfully painted listing — revert here if BinQuery fails
                // so facet chrome never lies about an unfiltered grid.
                committed: {
                    price: '',
                    sort: 'default',
                    page: 1,
                    filters: {},
                },
                // Last successfully painted facet_counts — re-apply on fail so numbers match committed.
                committedFacetCounts: null,
                busy: false,
                dirty: false,
                // Cap coalesced retries after BinQuery failures (Overflow/timeout)
                // so「加载中…」cannot chain across multiple 30s worker timeouts.
                failStreak: 0,
                debounceTimer: null,
            };
            stateByRoot.set(root, state);
        }
        return state;
    }

    /** Unhide all facet option rows (SSR neutral counts stay as rendered text). */
    function resetFacetVisibilitySsrNeutral() {
        document
            .querySelectorAll(
                '[data-listing-filter="price"][data-filter-value], [data-listing-filter="attr"][data-filter-code][data-filter-value]',
            )
            .forEach(function (btn) {
                var row = optionRow(btn);
                if (row) {
                    row.hidden = false;
                }
            });
    }

    function revertToCommitted(root, state) {
        applySnapshot(state, state.committed);
        paintFacetActive(state);
        if (state.committedFacetCounts && typeof state.committedFacetCounts === 'object') {
            paintFacetCounts(state.committedFacetCounts, state);
        } else {
            // Never soft-painted: restore SSR visibility (avoid sticky hides from a partial paint).
            resetFacetVisibilitySsrNeutral();
        }
        softSyncUrl(root, state);
    }

    function commitCurrent(state) {
        state.committed = snapshotListing(state);
    }

    function syncDocumentLoadingFlag() {
        var any = document.querySelector('[data-storefront-listing="1"].is-listing-loading');
        document.documentElement.classList.toggle('is-storefront-listing-loading', !!any);
    }

    function ensureLoadingEl(root) {
        var el = root.querySelector('[data-listing-loading]');
        if (el) {
            return el;
        }
        var stage = root.querySelector('[data-listing-stage]');
        if (!stage) {
            return null;
        }
        el = document.createElement('div');
        el.setAttribute('data-listing-loading', '1');
        el.className = 'storefront-listing-loading';
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        el.hidden = true;
        el.textContent = root.getAttribute('data-listing-loading-label') || '加载中…';
        stage.insertAdjacentElement('beforebegin', el);
        return el;
    }

    function setLoading(root, on) {
        root.classList.toggle('is-listing-loading', on);
        if (on) {
            root.setAttribute('aria-busy', 'true');
        } else {
            root.removeAttribute('aria-busy');
        }
        var el = ensureLoadingEl(root);
        if (el) {
            el.hidden = !on;
        }
        syncDocumentLoadingFlag();
    }

    function softSyncUrl(root, state) {
        try {
            var path = root.getAttribute('data-listing-path') || global.location.pathname;
            // Keep inbound params (utm_*, gclid, w_fpc_pierce, …); only rewrite the ones this panel owns.
            var params = new URLSearchParams(global.location.search || '');
            ['price', 'sort', 'page'].forEach(function (code) {
                params.delete(code);
            });
            Array.from(params.keys()).forEach(function (code) {
                if (code.indexOf('af_') === 0) {
                    params.delete(code);
                }
            });
            if (state.price) {
                params.set('price', state.price);
            }
            if (state.sort && state.sort !== 'default') {
                params.set('sort', state.sort);
            }
            if (state.page > 1) {
                params.set('page', String(state.page));
            }
            Object.keys(state.filters).forEach(function (code) {
                var value = state.filters[code];
                if (value) {
                    params.set('af_' + code, value);
                }
            });
            var qs = params.toString();
            var next = path + (qs ? '?' + qs : '');
            if (next !== global.location.pathname + global.location.search) {
                global.history.replaceState({}, '', next);
            }
        } catch (e) {}
    }

    function paintFacetActive(state) {
        document.querySelectorAll('[data-storefront-filters]').forEach(function (panel) {
            var clearRow = panel.querySelector('[data-listing-clear-row]');
            var hasActive = !!state.price || Object.keys(state.filters).some(function (k) {
                return !!state.filters[k];
            });
            if (clearRow) {
                clearRow.hidden = !hasActive;
            }
            panel.querySelectorAll('[data-listing-filter="price"]').forEach(function (btn) {
                var value = String(btn.getAttribute('data-filter-value') || '');
                var active = state.price !== '' && value === state.price;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            panel.querySelectorAll('[data-listing-filter="attr"]').forEach(function (btn) {
                var code = String(btn.getAttribute('data-filter-code') || '');
                var value = String(btn.getAttribute('data-filter-value') || '');
                var active = !!(code && state.filters[code] && String(state.filters[code]) === value);
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        });
    }

    function optionRow(btn) {
        return btn.closest('li') || btn.parentElement || null;
    }

    function setOptionVisibility(btn, count, selected) {
        var row = optionRow(btn);
        if (!row) {
            return;
        }
        // R3: hide unselected zero; keep selected zero visible.
        row.hidden = count === 0 && !selected;
    }

    function paintOptionCount(btn, count, selected) {
        var n = parseInt(String(count != null ? count : 0), 10);
        if (isNaN(n) || n < 0) {
            n = 0;
        }
        var countEl = btn.querySelector('.w-filters__count');
        if (countEl) {
            countEl.textContent = String(n);
        }
        setOptionVisibility(btn, n, !!selected);
    }

    /**
     * Apply slim facet_counts from filteredListingPage.
     * Gracefully no-ops when backend has not deployed the field yet.
     *
     * R3 (FE-1): when a code key is present in attributes (even if options are short),
     * DOM options for that code missing from the payload are count=0 → hide if unselected.
     * attributes=[] / {} → attribute no-op; price array still paints when present.
     * Forbidden: early return on missing item (that left SSR full residuals).
     */
    function paintFacetCounts(facetCounts, state) {
        if (!facetCounts || typeof facetCounts !== 'object') {
            return;
        }

        var priceItems = Array.isArray(facetCounts.price) ? facetCounts.price : null;
        if (priceItems) {
            var priceByValue = {};
            priceItems.forEach(function (item) {
                if (!item || typeof item !== 'object') {
                    return;
                }
                var value = String(item.value || '');
                if (value) {
                    priceByValue[value] = item;
                }
            });
            document.querySelectorAll('[data-listing-filter="price"][data-filter-value]').forEach(function (btn) {
                var value = String(btn.getAttribute('data-filter-value') || '');
                var item = priceByValue[value];
                var selected =
                    !!(state && state.price && String(state.price) === value) ||
                    !!(item && item.selected);
                // Omitted from slim price list → count 0 → R3 hide if unselected.
                paintOptionCount(btn, item ? item.count : 0, selected);
            });
        }

        var attrs = facetCounts.attributes;
        // [] or empty {} / non-map → attribute no-op (price-only with no dims).
        if (!attrs || typeof attrs !== 'object' || Array.isArray(attrs)) {
            return;
        }
        var attrCodes = Object.keys(attrs);
        if (!attrCodes.length) {
            return;
        }

        document
            .querySelectorAll('[data-listing-filter="attr"][data-filter-code][data-filter-value]')
            .forEach(function (btn) {
                var code = String(btn.getAttribute('data-filter-code') || '');
                var value = String(btn.getAttribute('data-filter-value') || '');
                if (!code || !Object.prototype.hasOwnProperty.call(attrs, code)) {
                    return;
                }
                var options = attrs[code];
                if (!Array.isArray(options)) {
                    options = [];
                }
                var item = null;
                for (var i = 0; i < options.length; i += 1) {
                    var candidate = options[i];
                    if (candidate && typeof candidate === 'object' && String(candidate.value || '') === value) {
                        item = candidate;
                        break;
                    }
                }
                var selected =
                    !!(state && state.filters && state.filters[code] && String(state.filters[code]) === value) ||
                    !!(item && item.selected);
                // Omitted from payload options → count 0 → R3 hide if unselected (no SSR residual).
                paintOptionCount(btn, item ? item.count : 0, selected);
            });

        // After R3 hide-zeros: promote visible options into primary (6) so hot colors
        // are not buried under 查看更多 when alpha SSR left size junk in the first slots.
        compactVisibleAttributeFacets();
    }

    var FACET_PRIMARY_LIMIT = 6;

    /**
     * Re-bucket attribute option rows after soft paint visibility changes.
     * Price / department sections are untouched. Does not remove 查看更多 — only
     * hides the toggle when no visible overflow remains.
     */
    function compactVisibleAttributeFacets() {
        document.querySelectorAll('[data-storefront-filters] details[data-filter-code]').forEach(function (section) {
            var primaryList = section.querySelector(':scope > ul.w-filters__list:not(.w-filters__list--more)');
            if (!primaryList) {
                return;
            }
            var moreWrap = section.querySelector(':scope > details.w-filters__more');
            var moreList = moreWrap
                ? moreWrap.querySelector('ul.w-filters__list--more')
                : section.querySelector('ul.w-filters__list--more');
            var visibleRows = [];
            function collectVisible(list) {
                if (!list) {
                    return;
                }
                Array.prototype.slice.call(list.children).forEach(function (li) {
                    if (!li || li.tagName !== 'LI' || li.hidden) {
                        return;
                    }
                    if (!li.querySelector('[data-listing-filter="attr"]')) {
                        return;
                    }
                    visibleRows.push(li);
                });
            }
            collectVisible(primaryList);
            collectVisible(moreList);
            if (!visibleRows.length) {
                if (moreWrap) {
                    moreWrap.hidden = true;
                }
                return;
            }
            var primary = visibleRows.slice(0, FACET_PRIMARY_LIMIT);
            var more = visibleRows.slice(FACET_PRIMARY_LIMIT);
            primary.forEach(function (li) {
                primaryList.appendChild(li);
            });
            if (moreList) {
                more.forEach(function (li) {
                    moreList.appendChild(li);
                });
            } else if (more.length) {
                // Defensive: unexpected DOM without more list — keep overflow in primary.
                more.forEach(function (li) {
                    primaryList.appendChild(li);
                });
                more = [];
            }
            if (moreWrap) {
                var summary = moreWrap.querySelector('.w-filters__more-summary');
                var countSpan = summary ? summary.querySelector('span[aria-hidden="true"]') : null;
                if (more.length) {
                    moreWrap.hidden = false;
                    if (countSpan) {
                        countSpan.textContent = '(' + more.length + ')';
                    }
                    var selectedStillInMore = more.some(function (li) {
                        var btn = li.querySelector('[data-listing-filter="attr"]');
                        return !!(
                            btn &&
                            (btn.classList.contains('is-active') || btn.getAttribute('aria-pressed') === 'true')
                        );
                    });
                    if (selectedStillInMore) {
                        moreWrap.open = true;
                    }
                } else {
                    moreWrap.hidden = true;
                    moreWrap.open = false;
                    if (countSpan) {
                        countSpan.textContent = '(0)';
                    }
                }
            }
        });
    }

    function hydrateStateFromLocation(state) {
        try {
            var params = new URLSearchParams(global.location.search || '');
            var price = String(params.get('price') || '');
            if (price) {
                state.price = price;
            }
            var sort = String(params.get('sort') || '');
            if (sort) {
                state.sort = sort;
            }
            var page = parseInt(String(params.get('page') || '1'), 10) || 1;
            if (page > 1) {
                state.page = page;
            }
            params.forEach(function (value, key) {
                if (String(key).indexOf('af_') === 0) {
                    var code = String(key).slice(3);
                    if (code && value) {
                        state.filters[code] = String(value);
                    }
                }
            });
        } catch (e) {}
    }

    function hasSoftListingQuery(state) {
        if (!state) {
            return false;
        }
        if (state.price) {
            return true;
        }
        if (state.page > 1) {
            return true;
        }
        if (state.sort && state.sort !== 'default') {
            return true;
        }
        return Object.keys(state.filters || {}).some(function (code) {
            return !!state.filters[code];
        });
    }

    function renderPager(root, payload) {
        var pager = root.querySelector('[data-listing-pager]');
        if (!pager) {
            return;
        }
        var totalPages = parseInt(String(payload.total_pages || 1), 10) || 1;
        var page = parseInt(String(payload.page || 1), 10) || 1;
        var options = Array.isArray(payload.page_options) ? payload.page_options : [];
        if (totalPages <= 1 || !options.length) {
            pager.hidden = true;
            pager.innerHTML = '';
            return;
        }
        pager.hidden = false;
        var html = '';
        options.forEach(function (option) {
            if (!option || typeof option !== 'object') {
                return;
            }
            var type = String(option.type || 'page');
            var pageNum = parseInt(String(option.page || 1), 10) || 1;
            var label = String(option.label || option.page || '');
            if (type === 'prev') {
                label = label || '上一页';
            } else if (type === 'next') {
                label = label || '下一页';
            }
            if (!label) {
                return;
            }
            var itemClass = pager.classList.contains('amz-plp__pager')
                ? 'amz-plp__pager-item'
                : 'product-storefront__pager-item';
            if (type === 'ellipsis') {
                html += '<span class="' + itemClass + ' is-ellipsis" aria-hidden="true">' + label + '</span>';
                return;
            }
            if (type === 'prev' || type === 'next') {
                if (option.disabled) {
                    html += '<span class="' + itemClass + ' is-nav is-disabled" aria-disabled="true">' + label + '</span>';
                } else {
                    html +=
                        '<button type="button" class="' +
                        itemClass +
                        ' is-nav" data-listing-page="' +
                        pageNum +
                        '">' +
                        label +
                        '</button>';
                }
                return;
            }
            if (option.selected || pageNum === page) {
                html += '<span class="' + itemClass + ' is-current" aria-current="page">' + label + '</span>';
            } else {
                html +=
                    '<button type="button" class="' +
                    itemClass +
                    '" data-listing-page="' +
                    pageNum +
                    '">' +
                    label +
                    '</button>';
            }
        });
        pager.innerHTML = html;
    }

    function updateCount(root, payload) {
        var countEl = root.querySelector('[data-listing-count]');
        if (!countEl) {
            return;
        }
        var from = parseInt(String(payload.from || 0), 10) || 0;
        var to = parseInt(String(payload.to || 0), 10) || 0;
        var count = parseInt(String(payload.count || 0), 10) || 0;
        var total = parseInt(String(payload.total || 0), 10) || 0;
        var surface = root.getAttribute('data-listing-surface') || 'products';
        var unit = surface === 'category' ? '结果' : '商品';
        var text =
            count > 0
                ? '第 ' + from + '–' + to + ' 件，共 ' + count + ' 件' + unit
                : '0 件' + unit;
        var querySpan = countEl.querySelector('.amz-plp__results-query');
        var hint = countEl.querySelector('[data-listing-count-hint]');
        if (!querySpan && !hint) {
            var preservedHint = hint ? hint.outerHTML : '';
            countEl.innerHTML = text + (preservedHint ? ' ' + preservedHint : '');
            hint = countEl.querySelector('[data-listing-count-hint]');
        } else if (!querySpan) {
            countEl.innerHTML = text;
            if (hint) {
                countEl.appendChild(hint);
            }
        } else {
            var rest = '';
            if (querySpan) {
                rest += querySpan.outerHTML;
            }
            if (hint) {
                rest += hint.outerHTML;
            }
            countEl.innerHTML = text + ' ' + rest;
            hint = countEl.querySelector('[data-listing-count-hint]');
        }
        if (hint) {
            if (payload.price && count !== total) {
                hint.hidden = false;
                hint.textContent = '（筛选自 ' + total + ' 件）';
            } else {
                hint.hidden = true;
                hint.textContent = '';
            }
        }
    }

    async function runRefresh(root) {
        var state = getState(root);
        if (state.busy) {
            state.dirty = true;
            return;
        }
        state.busy = true;
        state.dirty = false;
        setLoading(root, true);
        var succeeded = false;
        try {
            var api = await waitForApi();
            if (!api) {
                return;
            }
            var resource = await api.resource('product_storefront');
            if (!resource || typeof resource.filteredListingPage !== 'function') {
                return;
            }
            var result;
            try {
                result = await resource.filteredListingPage(
                    {
                        surface: root.getAttribute('data-listing-surface') || 'products',
                        listing_path: root.getAttribute('data-listing-path') || '',
                        category_path: root.getAttribute('data-category-path') || '',
                        price: state.price || '',
                        sort: state.sort || 'default',
                        page: state.page || 1,
                        filters: state.filters || {},
                    },
                    { silent: true },
                );
            } catch (err) {
                // Deploy/scope reload owned by Weline.Api; finally reverts chrome if no newer click.
                return;
            }
            var payload = unwrapPayload(result);
            if (
                !payload ||
                (payload.empty !== true &&
                    !payload.grid_html &&
                    payload.count == null &&
                    payload.total == null)
            ) {
                return;
            }
            var stage = root.querySelector('[data-listing-stage]');
            if (!stage) {
                return;
            }
            if (payload.empty || !payload.grid_html) {
                var emptyClass =
                    root.getAttribute('data-listing-surface') === 'category'
                        ? 'amz-plp__empty'
                        : 'product-storefront__empty';
                stage.innerHTML =
                    '<div class="' +
                    emptyClass +
                    '" data-listing-empty">' +
                    (state.price || Object.keys(state.filters).length
                        ? '当前筛选条件下暂无商品，请调整筛选。'
                        : root.getAttribute('data-listing-surface') === 'category'
                          ? '该分类下暂无已发布商品。'
                          : '当前网站暂无已发布商品。') +
                    '</div>';
            } else {
                var gridClass =
                    root.getAttribute('data-listing-surface') === 'category'
                        ? 'amz-plp__grid weline-product-card-shelf'
                        : 'product-storefront__grid weline-product-card-shelf';
                var testId =
                    root.getAttribute('data-listing-surface') === 'category'
                        ? ' data-testid="storefront-category-grid"'
                        : '';
                stage.innerHTML =
                    '<div class="' +
                    gridClass +
                    '"' +
                    testId +
                    ' data-listing-grid>' +
                    String(payload.grid_html || '') +
                    '</div><nav class="' +
                    (root.getAttribute('data-listing-surface') === 'category'
                        ? 'amz-plp__pager'
                        : 'product-storefront__pager') +
                    '" data-listing-pager' +
                    (root.getAttribute('data-listing-surface') === 'category'
                        ? ' data-testid="storefront-category-pager"'
                        : ' data-testid="storefront-products-pager"') +
                    '></nav>';
                renderPager(root, payload);
            }
            updateCount(root, payload);
            var sortSelect = root.querySelector('[data-listing-sort]');
            if (sortSelect && payload.sort) {
                sortSelect.value = String(payload.sort);
                state.sort = String(payload.sort);
            }
            commitCurrent(state);
            state.committedFacetCounts =
                payload.facet_counts && typeof payload.facet_counts === 'object'
                    ? payload.facet_counts
                    : null;
            paintFacetCounts(payload.facet_counts, state);
            paintFacetActive(state);
            softSyncUrl(root, state);
            succeeded = true;
            state.failStreak = 0;
        } finally {
            state.busy = false;
            if (!succeeded) {
                state.failStreak = (state.failStreak || 0) + 1;
            }
            if (state.dirty && state.failStreak < 3) {
                // Latest click(s) while in-flight: fire once more without extra debounce wait.
                runRefresh(root);
            } else {
                if (state.dirty) {
                    state.dirty = false;
                }
                if (!succeeded) {
                    // Facet checkmarks must not stay selected when the grid never changed.
                    revertToCommitted(root, state);
                }
                setLoading(root, false);
            }
        }
    }

    function scheduleRefresh(root) {
        var state = getState(root);
        state.dirty = true;
        setLoading(root, true);
        // Immediate facet chrome; URL soft-sync only after a successful paint (avoid query drift on API fail).
        paintFacetActive(state);
        if (state.debounceTimer) {
            global.clearTimeout(state.debounceTimer);
        }
        state.debounceTimer = global.setTimeout(function () {
            state.debounceTimer = null;
            runRefresh(root);
        }, DEBOUNCE_MS);
    }

    /** @deprecated use scheduleRefresh — kept for external callers */
    function refresh(root) {
        scheduleRefresh(root);
    }

    function bindRoot(root) {
        if (!root || root.getAttribute('data-listing-bound') === '1') {
            return;
        }
        root.setAttribute('data-listing-bound', '1');
        ensureLoadingEl(root);
        var state = getState(root);
        var sortSelect = root.querySelector('[data-listing-sort]');
        if (sortSelect) {
            state.sort = String(sortSelect.value || 'default');
            sortSelect.addEventListener('change', function () {
                state.sort = String(sortSelect.value || 'default');
                state.page = 1;
                scheduleRefresh(root);
            });
        }
        // Path-only SSR is neutral. Allowlisted query SSR is already projectFiltered —
        // skip boot BinQuery so Shared FPC HIT is not wiped by soft refresh.
        hydrateStateFromLocation(state);
        if (sortSelect && state.sort) {
            sortSelect.value = String(state.sort);
        }
        paintFacetActive(state);
        root.addEventListener('click', function (event) {
            var pageBtn = event.target.closest('[data-listing-page]');
            if (pageBtn && root.contains(pageBtn)) {
                event.preventDefault();
                state.page = parseInt(String(pageBtn.getAttribute('data-listing-page') || '1'), 10) || 1;
                scheduleRefresh(root);
            }
        });
        if (hasSoftListingQuery(state)) {
            if (root.getAttribute('data-listing-ssr-filtered') === '1') {
                commitCurrent(state);
                softSyncUrl(root, state);
                return;
            }
            // Keep committed = SSR neutral until first successful paint; fail → revertToCommitted.
            scheduleRefresh(root);
        }
    }

    function bindFilters() {
        if (document.documentElement.getAttribute('data-listing-filters-bound') === '1') {
            return;
        }
        document.documentElement.setAttribute('data-listing-filters-bound', '1');
        document.addEventListener('click', function (event) {
            var clearBtn = event.target.closest('[data-listing-filter-clear]');
            var filterBtn = event.target.closest('[data-listing-filter]');
            var root = document.querySelector('[data-storefront-listing="1"]');
            if (!root) {
                return;
            }
            var state = getState(root);
            if (clearBtn) {
                event.preventDefault();
                state.price = '';
                state.filters = {};
                state.page = 1;
                scheduleRefresh(root);
                return;
            }
            if (!filterBtn) {
                return;
            }
            event.preventDefault();
            var kind = String(filterBtn.getAttribute('data-listing-filter') || '');
            var value = String(filterBtn.getAttribute('data-filter-value') || '');
            if (kind === 'price') {
                state.price = state.price === value ? '' : value;
            } else if (kind === 'attr') {
                var code = String(filterBtn.getAttribute('data-filter-code') || '');
                if (!code) {
                    return;
                }
                if (state.filters[code] === value) {
                    delete state.filters[code];
                } else {
                    state.filters[code] = value;
                }
            } else {
                return;
            }
            state.page = 1;
            scheduleRefresh(root);
        });
    }

    function boot() {
        document.querySelectorAll('[data-storefront-listing="1"]').forEach(bindRoot);
        bindFilters();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    global.WelineStorefrontListing = {
        refresh: refresh,
        scheduleRefresh: scheduleRefresh,
        boot: boot,
        paintFacetCounts: paintFacetCounts,
        DEBOUNCE_MS: DEBOUNCE_MS,
    };
})(typeof window !== 'undefined' ? window : this);
