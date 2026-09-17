

(function() {
    if (window.__welineAccountIndexInitialized) {
        return;
    }
    window.__welineAccountIndexInitialized = true;

    function readAccountConfig() {
        var el = document.getElementById('weline-account-index-config');
        if (!el) {
            return {};
        }

        try {
            return JSON.parse(el.textContent || '{}') || {};
        } catch (error) {
            console.error(error);
            return {};
        }
    }

    function mergeI18n(serverI18n) {
        var fallback = {
            saving: 'Saving...',
            changing: 'Changing...',
            profileUpdateFailed: 'Update failed. Please try again later.',
            securityUpdateFailed: 'Password change failed. Please try again later.',
            fillAllFields: 'Please fill in all fields.',
            passwordTooShort: 'New password must be at least 6 characters.',
            passwordMismatch: 'The two passwords do not match.',
            invalidServerResponse: 'Invalid server response. Please try again later.',
            endpointNotFound: 'The save endpoint was not found.',
            sectionLoading: 'Loading...',
            sectionLoadFailed: 'Failed to load this section. Please retry.'
        };

        serverI18n = serverI18n && typeof serverI18n === 'object' ? serverI18n : {};
        Object.keys(serverI18n).forEach(function(key) {
            if (typeof serverI18n[key] === 'string' && serverI18n[key] !== '') {
                fallback[key] = serverI18n[key];
            }
        });

        return fallback;
    }

    function initAccountIndex() {
        var accountConfig = readAccountConfig();
        var i18nAccount = mergeI18n(accountConfig.i18n);
        var sidebarContentReady = true;
        var accountApiPromise = null;

        if (window.Weline && window.Weline.Api && window.Weline.Api.Account
            && typeof window.Weline.Api.Account.refreshAccountMenuSignals === 'function') {
            window.Weline.Api.Account.refreshAccountMenuSignals().catch(function () {});
        }

        function welineDecodeHtmlEntities(message) {
            var s = String(message || '');
            if (s.indexOf('&') === -1) {
                return s;
            }

            var div = document.createElement('div');
            var prev;
            for (var i = 0; i < 5; i++) {
                prev = s;
                div.innerHTML = s;
                s = div.textContent || div.innerText || '';
                if (s === prev || s.indexOf('&') === -1) {
                    break;
                }
            }

            return s;
        }

        function parseJsonResponse(response) {
            var contentType = String(response.headers.get('content-type') || '').toLowerCase();
            if (contentType.indexOf('application/json') === -1) {
                return response.text().then(function(text) {
                    if (response.status === 404) {
                        throw new Error(i18nAccount.endpointNotFound);
                    }

                    var normalized = welineDecodeHtmlEntities(text);
                    var trimmed = normalized.trim();

                    if (trimmed.indexOf('{') === 0 || trimmed.indexOf('[') === 0) {
                        var parsed = null;
                        try {
                            parsed = JSON.parse(trimmed);
                        } catch (jsonError) {}

                        if (parsed && typeof parsed === 'object') {
                            if (!response.ok) {
                                throw new Error(welineDecodeHtmlEntities(parsed && parsed.message ? parsed.message : '') || i18nAccount.invalidServerResponse);
                            }

                            return parsed;
                        }
                    }

                    if (normalized.indexOf('<') !== -1 || normalized.indexOf('>') !== -1) {
                        throw new Error(i18nAccount.invalidServerResponse);
                    }

                    throw new Error(normalized || i18nAccount.invalidServerResponse);
                });
            }

            return response.json().then(function(data) {
                if (!response.ok) {
                    var message = welineDecodeHtmlEntities(data && data.message ? data.message : '');
                    throw new Error(message || i18nAccount.invalidServerResponse);
                }

                return data;
            });
        }

        function getAccountApi() {
            if (!accountApiPromise) {
                if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
                    // Api.resource() returns a sync Proxy — wrap so callers can always .then()
                    accountApiPromise = Promise.resolve(window.Weline.Api.resource('account'));
                } else if (window.Weline && typeof window.Weline.load === 'function') {
                    accountApiPromise = window.Weline.load('api').then(function() {
                        if (!window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                            throw new Error('Weline.Api is unavailable.');
                        }

                        return window.Weline.Api.resource('account');
                    });
                } else {
                    accountApiPromise = Promise.reject(new Error('Weline.Api is unavailable.'));
                }
            }

            return accountApiPromise;
        }

        function formDataToObject(formData) {
            var payload = {};
            formData.forEach(function(value, key) {
                payload[key] = value;
            });
            delete payload.form_key;
            return payload;
        }

        var navLinks = document.querySelectorAll('[data-account-nav-link][data-section]');
        var sidebarContentMount = document.querySelector('[data-account-sidebar-content-mount]');
        var loadedSidebarSections = Object.create(null);
        var sidebarContentLoading = Object.create(null);
        var loadedSidebarScripts = Object.create(null);
        var loadedSidebarStyles = Object.create(null);

        function isSafeSidebarAssetUrl(rawValue, extension) {
            var value = String(rawValue || '').trim();
            var compactValue = value.replace(/[\u0000-\u001F\u007F\s]+/g, '').toLowerCase();
            if (
                compactValue.indexOf('javascript:') === 0 ||
                compactValue.indexOf('vbscript:') === 0 ||
                compactValue.indexOf('data:') === 0 ||
                compactValue.indexOf('//') === 0
            ) {
                return false;
            }

            try {
                var url = new URL(value, window.location.href);
                return url.origin === window.location.origin && url.pathname.toLowerCase().indexOf(extension, url.pathname.length - extension.length) !== -1;
            } catch (error) {
                return false;
            }
        }

        function loadTrustedSidebarScripts(html) {
            var template = document.createElement('template');
            template.innerHTML = String(html || '');

            Array.prototype.slice.call(template.content.querySelectorAll('script[src]')).forEach(function(scriptNode) {
                var src = scriptNode.getAttribute('src') || '';
                if (!isSafeSidebarAssetUrl(src, '.js') || loadedSidebarScripts[src]) {
                    return;
                }

                loadedSidebarScripts[src] = true;
                var script = document.createElement('script');
                script.src = src;
                script.defer = true;
                document.head.appendChild(script);
            });
        }

        // Body-injected <link rel="stylesheet"> is unreliable across browsers; hoist to <head>.
        function loadTrustedSidebarStyles(html) {
            var template = document.createElement('template');
            template.innerHTML = String(html || '');

            Array.prototype.slice.call(template.content.querySelectorAll('link[rel="stylesheet"][href], link[rel=stylesheet][href]')).forEach(function(linkNode) {
                var href = linkNode.getAttribute('href') || '';
                if (!isSafeSidebarAssetUrl(href, '.css') || loadedSidebarStyles[href]) {
                    return;
                }

                loadedSidebarStyles[href] = true;
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.type = 'text/css';
                link.href = href;
                document.head.appendChild(link);
            });
        }

        function clearPendingSectionFlag(sectionName) {
            // Pending only exists to hide built-in profile/security until a hash
            // target (e.g. #social-login after FB OAuth) finishes loading.
            // Always clear when ANY section is revealed — otherwise switching to
            // 个人资料 while pending=social-login leaves CSS display:none on profile.
            try {
                document.documentElement.removeAttribute('data-account-pending-section');
            } catch (err) {}
            void sectionName;
        }

        // AJAX-injected sidebar HTML never re-runs the initial data-weline-load scan.
        function loadDeclaredSidebarModules(root) {
            if (!root || !window.Weline || typeof window.Weline.load !== 'function') {
                return Promise.resolve();
            }

            var names = [];
            var seen = Object.create(null);
            root.querySelectorAll('[data-weline-load]').forEach(function(el) {
                String(el.getAttribute('data-weline-load') || '').split(',').forEach(function(raw) {
                    var name = String(raw || '').trim();
                    if (!name || seen[name]) {
                        return;
                    }
                    seen[name] = true;
                    names.push(name);
                });
            });

            if (!names.length) {
                return Promise.resolve();
            }

            return Promise.all(names.map(function(name) {
                return window.Weline.load(name).catch(function(error) {
                    console.error(error);
                    return null;
                });
            }));
        }

        function reloadSidebarSection(sectionName) {
            if (!sectionName) {
                return Promise.resolve(false);
            }

            delete loadedSidebarSections[sectionName];
            delete sidebarContentLoading[sectionName];

            return loadSidebarContent(sectionName, { force: true }).then(function(ok) {
                if (ok !== false) {
                    showAccountSection(sectionName);
                }
                return ok !== false;
            });
        }

        function sanitizeSidebarHtml(html) {
            var template = document.createElement('template');
            template.innerHTML = String(html || '');

            template.content.querySelectorAll('script, style, meta, iframe, frame, frameset, object, embed, base').forEach(function(node) {
                node.remove();
            });
            template.content.querySelectorAll('link').forEach(function(node) {
                var rel = String(node.getAttribute('rel') || '').toLowerCase();
                var href = node.getAttribute('href') || '';
                if (rel !== 'stylesheet' || !isSafeSidebarAssetUrl(href, '.css')) {
                    node.remove();
                }
            });

            template.content.querySelectorAll('*').forEach(function(node) {
                Array.prototype.slice.call(node.attributes).forEach(function(attribute) {
                    var name = String(attribute.name || '').toLowerCase();
                    var compactValue = String(attribute.value || '').trim().replace(/[\u0000-\u001F\u007F\s]+/g, '').toLowerCase();

                    if (name.indexOf('on') === 0 || name === 'srcdoc' || name === 'style') {
                        node.removeAttribute(attribute.name);
                        return;
                    }

                    if ((name === 'href' || name === 'src' || name === 'xlink:href' || name === 'action' || name === 'formaction' || name === 'poster') && (
                        compactValue.indexOf('javascript:') === 0 ||
                        compactValue.indexOf('vbscript:') === 0 ||
                        (compactValue.indexOf('data:') === 0 && compactValue.indexOf('data:image/') !== 0)
                    )) {
                        node.removeAttribute(attribute.name);
                    }
                });
            });

            return template.innerHTML;
        }

        function buildSidebarContentUrl(sectionName) {
            var baseUrl = sidebarContentMount ? (sidebarContentMount.getAttribute('data-account-sidebar-content-url') || '') : '';
            if (!baseUrl || !sectionName) {
                return '';
            }

            var separator = baseUrl.indexOf('?') >= 0 ? '&' : '?';
            return baseUrl + separator + 'section=' + encodeURIComponent(sectionName);
        }

        function revealAccountSection(sectionName) {
            if (!sectionName) {
                return null;
            }
            var section = document.querySelector('[data-account-section="' + sectionName + '"]')
                || document.getElementById(sectionName + '-section');
            if (!section) {
                return null;
            }
            hideAllAccountSections();
            section.classList.remove('d-none');
            section.hidden = false;
            section.removeAttribute('aria-busy');
            section.removeAttribute('data-account-section-loading');
            clearPendingSectionFlag(sectionName);
            return section;
        }

        function markSectionLoadFailed(sectionName, message) {
            var section = document.querySelector('[data-account-section="' + sectionName + '"]')
                || document.getElementById(sectionName + '-section');
            if (!section) {
                section = ensureSectionLoadingPlaceholder(sectionName);
            }
            if (!section) {
                return;
            }
            section.setAttribute('data-account-section-loading', 'failed');
            section.removeAttribute('aria-busy');
            section.classList.remove('d-none');
            section.hidden = false;
            var body = section.querySelector('.account-index__section-loading') || section;
            body.innerHTML = '<p class="account-index__section-loading-text" data-tone="danger"></p>';
            var text = body.querySelector('.account-index__section-loading-text');
            if (text) {
                text.textContent = message || i18nAccount.sectionLoadFailed || 'Failed to load this section. Please retry.';
            }
            clearPendingSectionFlag(sectionName);
        }

        function loadSidebarContent(sectionName, options) {
            options = options || {};
            if (!sidebarContentMount || !sectionName) {
                return Promise.resolve(sidebarContentReady);
            }

            var existingSection = document.querySelector('[data-account-section="' + sectionName + '"]')
                || document.getElementById(sectionName + '-section');
            var stuckLoading = existingSection
                && existingSection.getAttribute('data-account-section-loading') === 'true';

            // Cached "loaded" but DOM still shows loading/failed → force refetch.
            if (!options.force && loadedSidebarSections[sectionName] && !stuckLoading
                && existingSection
                && existingSection.getAttribute('data-account-section-loading') !== 'failed') {
                revealAccountSection(sectionName);
                return Promise.resolve(sidebarContentReady);
            }

            if (sidebarContentLoading[sectionName]) {
                return sidebarContentLoading[sectionName];
            }

            // Only forward params declared on account.getSidebarSection (section, order_uuid).
            // Merging location.search wholesale forwards 2FA secrets and trips FrontendQueryGateway 422.
            var sidebarPayload = { section: sectionName };
            var sidebarQuery = parseAccountHash().query;
            if (sidebarQuery.order_uuid) {
                sidebarPayload.order_uuid = sidebarQuery.order_uuid;
            }
            sidebarContentLoading[sectionName] = (window.Weline && window.Weline.load
                ? window.Weline.load('api')
                : Promise.resolve(window.Weline && window.Weline.Api)
            ).then(function(api) {
                if (!api || typeof api.resource !== 'function') {
                    if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                        throw new Error('Weline.Api is unavailable.');
                    }
                    api = window.Weline.Api;
                }
                return api.resource('account').getSidebarSection(sidebarPayload);
            }).then(function(payload) {
                if (!payload || payload.success === false) {
                    if (payload && payload.redirect) {
                        window.location.href = payload.redirect;
                        return false;
                    }

                    delete loadedSidebarSections[sectionName];
                    markSectionLoadFailed(
                        sectionName,
                        payload && payload.message ? String(payload.message) : ''
                    );
                    return false;
                }

                if (payload.html) {
                    loadTrustedSidebarStyles(payload.html);
                    loadTrustedSidebarScripts(payload.html);
                    var safeHtml = sanitizeSidebarHtml(payload.html);
                    var existing = document.querySelector('[data-account-section="' + sectionName + '"]')
                        || document.getElementById(sectionName + '-section');
                    if (existing && existing.parentNode) {
                        existing.insertAdjacentHTML('afterend', safeHtml);
                        existing.parentNode.removeChild(existing);
                    } else {
                        sidebarContentMount.insertAdjacentHTML('beforeend', safeHtml);
                    }
                    loadDeclaredSidebarModules(sidebarContentMount);
                    revealAccountSection(sectionName);
                } else if (!options._retriedEmpty) {
                    // Rare race: worker returns success with empty hook HTML; retry once.
                    delete loadedSidebarSections[sectionName];
                    delete sidebarContentLoading[sectionName];
                    return loadSidebarContent(sectionName, Object.assign({}, options, { force: true, _retriedEmpty: true }));
                } else {
                    delete loadedSidebarSections[sectionName];
                    markSectionLoadFailed(sectionName, '');
                    return false;
                }

                loadedSidebarSections[sectionName] = true;
                window.dispatchEvent(new CustomEvent('weline:account-sidebar-content-loaded', {
                    detail: { section: sectionName, length: payload.length || 0 }
                }));
                // Sections may mark signals seen server-side; refresh JS badges.
                if (window.Weline && window.Weline.Api && window.Weline.Api.Account
                    && typeof window.Weline.Api.Account.refreshAccountMenuSignals === 'function') {
                    window.Weline.Api.Account.refreshAccountMenuSignals().catch(function () {});
                }
                return true;
            }).catch(function(error) {
                console.error(error);
                delete loadedSidebarSections[sectionName];
                markSectionLoadFailed(
                    sectionName,
                    error && error.message ? String(error.message) : ''
                );
                return false;
            }).finally(function() {
                delete sidebarContentLoading[sectionName];
            });

            return sidebarContentLoading[sectionName];
        }

        function parseHash(inputHash) {
            var rawHash = String(inputHash || '');
            if (!rawHash) {
                return { section: '', query: {} };
            }

            var trimmed = rawHash.replace(/^.*#/, '');
            if (!trimmed) {
                return { section: '', query: {} };
            }

            var section = trimmed;
            var query = {};
            var splitAt = trimmed.indexOf('?');

            if (splitAt !== -1) {
                section = trimmed.substring(0, splitAt);
                var queryString = trimmed.substring(splitAt + 1);
                if (queryString) {
                    try {
                        var parsed = new URLSearchParams(queryString);
                        parsed.forEach(function(value, key) {
                            query[String(key)] = String(value);
                        });
                    } catch (err) {}
                }
            }

            return {
                section: section,
                query: query
            };
        }

        function parseAccountHash() {
            var state = parseHash(window.location.hash || '');
            try {
                var search = new URLSearchParams(window.location.search || '');
                search.forEach(function(value, key) {
                    if (!(key in state.query)) {
                        state.query[String(key)] = String(value);
                    }
                });
            } catch (err) {}
            return state;
        }

        // Drop sensitive / accidental query keys that must never stay in the address bar
        // or ride along into BinQuery (e.g. native 2FA form GET leak: secret, backup_codes).
        function sanitizeAccountLocationSearch() {
            if (!window.history || typeof window.history.replaceState !== 'function') {
                return;
            }

            try {
                var search = new URLSearchParams(window.location.search || '');
                var stripKeys = ['secret', 'backup_codes', 'code'];
                var changed = false;
                stripKeys.forEach(function(key) {
                    if (search.has(key)) {
                        search.delete(key);
                        changed = true;
                    }
                });
                if (!changed) {
                    return;
                }

                var nextSearch = search.toString();
                var nextUrl = window.location.pathname
                    + (nextSearch ? '?' + nextSearch : '')
                    + (window.location.hash || '');
                window.history.replaceState(null, '', nextUrl);
            } catch (err) {}
        }

        function getNavTarget(link) {
            var targetId = link.getAttribute('data-section') || '';
            var hashInfo = parseHash(link.getAttribute('href') || '');
            if (hashInfo.section) {
                targetId = hashInfo.section;
            }

            return {
                section: targetId,
                query: hashInfo.query
            };
        }

        function hasNavSection(section) {
            return Array.prototype.some.call(document.querySelectorAll('[data-account-nav-link][data-section]'), function(a) {
                return a.getAttribute('data-section') === section;
            });
        }

        function updateHash(section, query) {
            var targetHash = '#' + section;
            if (query && typeof query === 'object' && Object.keys(query).length > 0) {
                var nextQuery = new URLSearchParams();
                Object.keys(query).forEach(function(key) {
                    var value = query[key];
                    if (value === '' || value === null || value === undefined) {
                        return;
                    }
                    nextQuery.set(key, String(value));
                });

                var nextQueryString = nextQuery.toString();
                if (nextQueryString) {
                    targetHash += '?' + nextQueryString;
                }
            }

            var nextSearch = '';
            try {
                var search = new URLSearchParams(window.location.search || '');
                // Keep order_uuid only in hash so soft-nav does not fight location.search merges.
                search.delete('order_uuid');
                nextSearch = search.toString();
            } catch (err) {
                nextSearch = String(window.location.search || '').replace(/^\?/, '');
            }

            if (window.history && typeof window.history.replaceState === 'function') {
                window.history.replaceState(
                    null,
                    '',
                    window.location.pathname + (nextSearch ? '?' + nextSearch : '') + targetHash
                );
            }
        }

        function openOrdersSectionViaBinQuery(orderUuid) {
            var query = {};
            if (orderUuid) {
                query.order_uuid = String(orderUuid);
            }
            setActiveNavLink('orders');
            try {
                updateHash('orders', query);
            } catch (err) {}
            return reloadSidebarSection('orders');
        }

        function bindAccountOrdersSoftNavigation(root) {
            var scope = root || document;
            if (!scope || typeof scope.addEventListener !== 'function') {
                return;
            }
            if (scope.__welineAccountOrdersSoftNavBound) {
                return;
            }
            scope.__welineAccountOrdersSoftNavBound = true;

            scope.addEventListener('click', function(event) {
                var target = event.target;
                if (!target || typeof target.closest !== 'function') {
                    return;
                }

                var detailLink = target.closest('[data-order-detail-link="true"]');
                if (detailLink) {
                    event.preventDefault();
                    var detailUuid = detailLink.getAttribute('data-order-uuid') || '';
                    if (!detailUuid) {
                        try {
                            var detailHref = new URL(detailLink.getAttribute('href') || '', window.location.href);
                            detailUuid = detailHref.searchParams.get('order_uuid')
                                || (parseHash(detailHref.hash || '').query.order_uuid || '');
                        } catch (err) {}
                    }
                    if (!detailUuid) {
                        return;
                    }
                    openOrdersSectionViaBinQuery(detailUuid);
                    return;
                }

                var backLink = target.closest('[data-account-orders-back="true"]');
                if (backLink) {
                    event.preventDefault();
                    openOrdersSectionViaBinQuery('');
                }
            });
        }

        function refreshNavLinks() {
            navLinks = document.querySelectorAll('[data-account-nav-link][data-section]');
            return navLinks;
        }

        function setActiveNavLink(targetId) {
            refreshNavLinks();
            var activeParent = '';
            navLinks.forEach(function(nav) {
                if (nav.getAttribute('data-section') === targetId) {
                    activeParent = nav.getAttribute('data-account-nav-parent') || '';
                }
            });

            navLinks.forEach(function(nav) {
                var isActive = nav.getAttribute('data-section') === targetId;
                var isActiveParent = activeParent && nav.getAttribute('data-section') === activeParent;
                nav.classList.remove('is-active');
                if (nav.classList.contains('account-sidebar__nav-link')) {
                    nav.classList.remove('account-sidebar__nav-link--active');
                }
                if (!isActive && !isActiveParent) {
                    return;
                }
                if (nav.classList.contains('account-sidebar__nav-link')) {
                    nav.classList.add('account-sidebar__nav-link--active');
                } else {
                    // Hook entries (orders etc.) always get is-active when selected,
                    // including children that declare data-account-nav-parent.
                    nav.classList.add('is-active');
                }
            });

            try {
                document.documentElement.setAttribute('data-account-active-section', targetId || '');
            } catch (err) {}
        }

        function hideAllAccountSections() {
            document.querySelectorAll('[data-account-section]').forEach(function(section) {
                section.classList.add('d-none');
                section.hidden = true;
            });
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function ensureSectionLoadingPlaceholder(sectionName) {
            if (!sectionName || !sidebarContentMount) {
                return null;
            }

            hideAllAccountSections();

            var existing = document.querySelector('[data-account-section="' + sectionName + '"]')
                || document.getElementById(sectionName + '-section');
            if (existing) {
                existing.classList.remove('d-none');
                existing.hidden = false;
                if (!existing.hasAttribute('data-account-section-loading')) {
                    return existing;
                }
                return existing;
            }

            var loadingText = escapeHtml(i18nAccount.sectionLoading || 'Loading...');
            var safeId = String(sectionName).replace(/[^a-zA-Z0-9_-]/g, '');
            if (!safeId) {
                return null;
            }

            sidebarContentMount.insertAdjacentHTML(
                'beforeend',
                '<section class="account-index__section account-index__section--loading"'
                    + ' id="' + safeId + '-section"'
                    + ' data-account-section="' + escapeHtml(sectionName) + '"'
                    + ' data-account-section-loading="true"'
                    + ' aria-busy="true">'
                    + '<div class="account-card account-index__section-loading" role="status" aria-live="polite">'
                    + '<span class="account-index__section-loading-spinner" aria-hidden="true"></span>'
                    + '<p class="account-index__section-loading-text">' + loadingText + '</p>'
                    + '</div>'
                    + '</section>'
            );

            return document.querySelector('[data-account-section="' + sectionName + '"]')
                || document.getElementById(safeId + '-section');
        }

        function showAccountSection(targetId) {
            if (!targetId) {
                return;
            }

            var targetSection = document.querySelector('[data-account-section="' + targetId + '"]');
            if (!targetSection) {
                targetSection = document.getElementById(targetId + '-section');
            }

            var loadingState = targetSection
                ? String(targetSection.getAttribute('data-account-section-loading') || '')
                : '';

            // Lazy Hook sections: switch UI immediately, then fill via binquery.
            if (!targetSection || loadingState === 'true' || loadingState === 'failed') {
                if (loadingState !== 'failed') {
                    ensureSectionLoadingPlaceholder(targetId);
                }
                return loadSidebarContent(targetId, loadingState === 'failed' ? { force: true } : {}).then(function(ok) {
                    if (ok) {
                        revealAccountSection(targetId);
                        if (targetId === 'orders') {
                            window.dispatchEvent(new CustomEvent('weshop:orders-viewed'));
                        }
                    }
                });
            }

            revealAccountSection(targetId);
            if (targetId === 'orders') {
                window.dispatchEvent(new CustomEvent('weshop:orders-viewed'));
            }
        }

        window.addEventListener('weline:account-sidebar-section-reload', function(event) {
            var section = event && event.detail ? event.detail.section : '';
            if (!section) {
                return;
            }
            reloadSidebarSection(section);
        });

        function syncFromHash() {
            var parsed = parseAccountHash();
            var targetId = parsed.section || 'profile';
            if (!hasNavSection(targetId)) {
                targetId = 'profile';
            }

            setActiveNavLink(targetId);
            showAccountSection(targetId);

            if (typeof parsed.query.return_anchor === 'string' && parsed.query.return_anchor) {
                var anchor = document.getElementById(parsed.query.return_anchor);
                if (anchor && typeof anchor.scrollIntoView === 'function') {
                    anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        }

        navLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                var navTarget = getNavTarget(link);
                var targetId = navTarget.section;
                if (!targetId) {
                    return;
                }

                setActiveNavLink(targetId);
                showAccountSection(targetId);
                try {
                    updateHash(targetId, navTarget.query);
                } catch (err) {}
            });
        });

        sanitizeAccountLocationSearch();
        bindAccountOrdersSoftNavigation(document);

        // Lazy sidebar strips on* handlers; confirm unbind via data attribute.
        document.addEventListener('submit', function(event) {
            var form = event.target;
            if (!form || !form.getAttribute) {
                return;
            }
            var message = form.getAttribute('data-customer-social-unbind-confirm');
            if (!message) {
                return;
            }
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        }, true);

        syncFromHash();
        window.addEventListener('hashchange', syncFromHash);

        var avatarInput = document.getElementById('avatar');
        var avatarTargets = [
            {
                image: document.getElementById('avatarPreview'),
                fallback: document.getElementById('avatarFallback')
            },
            {
                image: document.getElementById('sidebarAvatarPreview'),
                fallback: document.getElementById('sidebarAvatarFallback')
            }
        ];

        function syncAvatarPreview() {
            if (!avatarInput) {
                return;
            }

            var avatarUrl = avatarInput.value.trim();
            if (!avatarUrl) {
                avatarTargets.forEach(function(target) {
                    if (!target.image || !target.fallback) {
                        return;
                    }
                    target.image.hidden = true;
                    target.fallback.hidden = false;
                    target.image.removeAttribute('src');
                });
                return;
            }

            avatarTargets.forEach(function(target) {
                if (!target.image || !target.fallback) {
                    return;
                }
                target.image.hidden = false;
                target.fallback.hidden = true;
                target.image.src = avatarUrl;
            });
        }

        avatarTargets.forEach(function(target) {
            if (!target.image || !target.fallback) {
                return;
            }
            target.image.addEventListener('error', function() {
                target.image.hidden = true;
                target.fallback.hidden = false;
            });
        });

        if (avatarInput) {
            avatarInput.addEventListener('input', syncAvatarPreview);
            avatarInput.addEventListener('change', syncAvatarPreview);
            syncAvatarPreview();
        }

        var profileForm = document.getElementById('profileForm');
        if (profileForm) {
            profileForm.addEventListener('submit', function(e) {
                e.preventDefault();
                var btn = this.querySelector('button[type="submit"]');
                var originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<w:icon name="loader" size="sm"></w:icon> ' + i18nAccount.saving;

                var successMsg = document.getElementById('profileSuccessMsg');
                var errorMsg = document.getElementById('profileErrorMsg');
                successMsg.textContent = '';
                errorMsg.textContent = '';

                var formData = new FormData(this);
                getAccountApi()
                    .then(function(AccountApi) {
                        return AccountApi.updateProfile(formDataToObject(formData), {
                            onError: function(_status, error) {
                                errorMsg.textContent = welineDecodeHtmlEntities(error && error.message ? error.message : '') || i18nAccount.profileUpdateFailed;
                            }
                        });
                    })
                    .then(function(data) {
                        if (data.success) {
                            errorMsg.textContent = '';
                            successMsg.textContent = welineDecodeHtmlEntities(data.message);
                            var user = (data && data.user)
                                || (data && data.data && data.data.user)
                                || null;
                            if (user && window.WelineAccountModule
                                && typeof window.WelineAccountModule.applyFrontendProfileUpdate === 'function') {
                                window.WelineAccountModule.applyFrontendProfileUpdate(user);
                            } else if (user) {
                                window.dispatchEvent(new CustomEvent('weline:account:frontend:profile', {
                                    detail: { user: user }
                                }));
                            }
                            syncAvatarPreview();
                        } else {
                            successMsg.textContent = '';
                            errorMsg.textContent = welineDecodeHtmlEntities(data.message) || i18nAccount.profileUpdateFailed;
                        }
                    })
                    .catch(function(error) {
                        successMsg.textContent = '';
                        errorMsg.textContent = welineDecodeHtmlEntities(error && error.message ? error.message : '') || i18nAccount.profileUpdateFailed;
                    })
                    .finally(function() {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    });
            });
        }

        var securityForm = document.getElementById('securityForm');
        if (securityForm) {
            securityForm.addEventListener('submit', function(e) {
                e.preventDefault();

                var successMsg = document.getElementById('securitySuccessMsg');
                var errorMsg = document.getElementById('securityErrorMsg');
                successMsg.textContent = '';
                errorMsg.textContent = '';

                var oldPassword = document.getElementById('old_password').value;
                var newPassword = document.getElementById('new_password').value;
                var confirmPassword = document.getElementById('confirm_password').value;

                if (!oldPassword || !newPassword || !confirmPassword) {
                    errorMsg.textContent = i18nAccount.fillAllFields;
                    return;
                }

                if (newPassword.length < 6) {
                    errorMsg.textContent = i18nAccount.passwordTooShort;
                    return;
                }

                if (newPassword !== confirmPassword) {
                    errorMsg.textContent = i18nAccount.passwordMismatch;
                    return;
                }

                var btn = this.querySelector('button[type="submit"]');
                var originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<w:icon name="loader" size="sm"></w:icon> ' + i18nAccount.changing;

                var formData = new FormData(this);
                getAccountApi()
                    .then(function(AccountApi) {
                        return AccountApi.updatePassword(formDataToObject(formData), {
                            onError: function(_status, error) {
                                errorMsg.textContent = welineDecodeHtmlEntities(error && error.message ? error.message : '') || i18nAccount.securityUpdateFailed;
                            }
                        });
                    })
                    .then(function(data) {
                        if (data.success) {
                            errorMsg.textContent = '';
                            successMsg.textContent = welineDecodeHtmlEntities(data.message);
                            document.getElementById('old_password').value = '';
                            document.getElementById('new_password').value = '';
                            document.getElementById('confirm_password').value = '';
                        } else {
                            successMsg.textContent = '';
                            errorMsg.textContent = welineDecodeHtmlEntities(data.message) || i18nAccount.securityUpdateFailed;
                        }
                    })
                    .catch(function(error) {
                        successMsg.textContent = '';
                        errorMsg.textContent = welineDecodeHtmlEntities(error && error.message ? error.message : '') || i18nAccount.securityUpdateFailed;
                    })
                    .finally(function() {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    });
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAccountIndex);
    } else {
        initAccountIndex();
    }

    window.WelineCustomerAccount = {
        __full: true,
        init: initAccountIndex,
    };
})();
