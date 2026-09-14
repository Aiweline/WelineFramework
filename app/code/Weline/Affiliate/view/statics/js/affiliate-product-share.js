/**
 * PDP / quick-add「分销分享」面板。
 * 对齐账户会话：先读/等 WelineAccountModule，再拉分享链接；结果写入 sessionStorage，避免每次后台请求。
 * 游客态展示广告 +「登录前往申请分销」；登录/登出事件刷新并清理浏览器缓存。
 * Loaded via data-weline-load="api,account,affiliateProductShare".
 */
(function (window, document) {
    'use strict';

    var CACHE_KEY = 'weline_affiliate_product_share_v1';
    var CACHE_TTL_MS = 30 * 60 * 1000;
    var DEFAULT_I18N = {
        loading: 'Loading',
        pleaseLogin: 'Please sign in first.',
        loginAction: 'Sign in',
        promoTitle: 'Share products, earn commission',
        promoBody: 'Join the affiliate program to get your tracking link. Sign in to apply, then start sharing after approval.',
        applyCta: 'Sign in to apply for affiliate',
        unavailable: 'Temporarily unavailable',
        copied: 'Copied',
        shared: 'Shared',
        apiMissing: 'Affiliate API is not ready',
        shareUnsupported: 'Share links are not supported',
        loadFailed: 'Failed to load affiliate data'
    };

    var platformIcons = {
        facebook: '<svg class="affiliate-share-platform__mark" viewBox="0 0 24 24" width="18" height="18" focusable="false" aria-hidden="true"><path fill="#1877F2" d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>',
        x: '<svg class="affiliate-share-platform__mark" viewBox="0 0 24 24" width="18" height="18" focusable="false" aria-hidden="true"><path fill="#0F1419" d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
        linkedin: '<svg class="affiliate-share-platform__mark" viewBox="0 0 24 24" width="18" height="18" focusable="false" aria-hidden="true"><path fill="#0A66C2" d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>',
        whatsapp: '<svg class="affiliate-share-platform__mark" viewBox="0 0 24 24" width="18" height="18" focusable="false" aria-hidden="true"><path fill="#25D366" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>'
    };
    var platformShortLabels = {
        facebook: 'FB',
        x: 'X',
        linkedin: 'in',
        whatsapp: 'WA'
    };

    function readI18n(root) {
        var merged = Object.assign({}, DEFAULT_I18N);
        var raw = root && root.getAttribute('data-affiliate-i18n');
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
        } catch (_e) {}
        return merged;
    }

    function ensureAccountModule() {
        if (window.WelineAccountModule) {
            return Promise.resolve(window.WelineAccountModule);
        }
        if (window.Weline && typeof window.Weline.load === 'function') {
            return window.Weline.load('account').then(function () {
                return window.WelineAccountModule || null;
            }).catch(function () {
                return null;
            });
        }
        return Promise.resolve(null);
    }

    function resolveCustomerIdentity(account, status) {
        var user = (status && status.user)
            || (account && typeof account.getFrontendUser === 'function' ? account.getFrontendUser() : null)
            || null;
        if (account && typeof account.resolveUserIdentity === 'function') {
            return String(account.resolveUserIdentity(user) || '');
        }
        if (!user || typeof user !== 'object') {
            return '';
        }
        var id = user.customer_id ?? user.id ?? user.user_id ?? '';
        return id !== '' && id !== null && id !== undefined ? String(id) : '';
    }

    function readShareCache() {
        try {
            var raw = sessionStorage.getItem(CACHE_KEY);
            if (!raw) {
                return {};
            }
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (_e) {
            return {};
        }
    }

    function writeShareCache(map) {
        try {
            sessionStorage.setItem(CACHE_KEY, JSON.stringify(map || {}));
        } catch (_e) {}
    }

    function cacheEntryKey(customerId, productId) {
        return String(customerId || '') + ':' + String(productId || 0);
    }

    function getCachedShare(customerId, productId) {
        var entry = readShareCache()[cacheEntryKey(customerId, productId)];
        if (!entry || typeof entry !== 'object') {
            return null;
        }
        var savedAt = Number(entry.savedAt) || 0;
        if (!savedAt || (Date.now() - savedAt) > CACHE_TTL_MS) {
            return null;
        }
        return entry.data && typeof entry.data === 'object' ? entry.data : null;
    }

    function putCachedShare(customerId, productId, data) {
        if (!customerId || !productId || !data) {
            return;
        }
        var map = readShareCache();
        map[cacheEntryKey(customerId, productId)] = {
            savedAt: Date.now(),
            data: data
        };
        writeShareCache(map);
    }

    function clearShareCacheForCustomer(customerId) {
        if (!customerId) {
            try { sessionStorage.removeItem(CACHE_KEY); } catch (_e) {}
            return;
        }
        var map = readShareCache();
        var prefix = String(customerId) + ':';
        Object.keys(map).forEach(function (key) {
            if (key.indexOf(prefix) === 0) {
                delete map[key];
            }
        });
        writeShareCache(map);
    }

    function buildApplyLoginUrl(root) {
        var loginUrl = String(root.getAttribute('data-login-url') || '/customer/account/login').trim();
        var applyUrl = String(root.getAttribute('data-apply-url') || '/affiliate').trim() || '/affiliate';
        try {
            var url = new URL(loginUrl, window.location.origin);
            url.searchParams.set('redirect_url', applyUrl);
            url.searchParams.set('redirect', applyUrl);
            url.searchParams.set('return_url', applyUrl);
            return url.pathname + url.search + url.hash;
        } catch (_e) {
            var sep = loginUrl.indexOf('?') >= 0 ? '&' : '?';
            return loginUrl + sep + 'redirect_url=' + encodeURIComponent(applyUrl);
        }
    }

    function resolveFreeShareUrl(root) {
        var fromAttr = String(root.getAttribute('data-free-share-url') || '').trim();
        try {
            var path = String(window.location.pathname || '');
            // Listing / purchase-panel hosts: prefer SSR product URL, not the current page.
            if (/\/product\//i.test(path)) {
                return window.location.origin + path + (window.location.search || '');
            }
        } catch (_e) {}
        if (fromAttr) {
            return fromAttr;
        }
        try {
            return window.location.origin + window.location.pathname + window.location.search;
        } catch (_e2) {
            return '';
        }
    }

    function buildFreePlatformUrls(targetUrl) {
        var url = String(targetUrl || '').trim();
        if (!url) {
            try {
                url = window.location.href.split('#')[0];
            } catch (_e) {
                url = '';
            }
        }
        if (!url) {
            return [];
        }
        var encoded = encodeURIComponent(url);
        return [
            { platform: 'facebook', label: 'Facebook', url: 'https://www.facebook.com/sharer/sharer.php?u=' + encoded },
            { platform: 'x', label: 'X', url: 'https://twitter.com/intent/tweet?url=' + encoded },
            { platform: 'linkedin', label: 'LinkedIn', url: 'https://www.linkedin.com/sharing/share-offsite/?url=' + encoded },
            { platform: 'whatsapp', label: 'WhatsApp', url: 'https://api.whatsapp.com/send?text=' + encoded }
        ];
    }

    function getAffiliateApi() {
        if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
            return Promise.resolve(window.Weline.Api.resource('affiliate'));
        }
        if (window.Weline && typeof window.Weline.load === 'function') {
            return window.Weline.load('api').then(function () {
                if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                    throw new Error('api-missing');
                }
                return window.Weline.Api.resource('affiliate');
            });
        }
        return Promise.reject(new Error('api-missing'));
    }

    function normalizeResponse(response, i18n) {
        if (!response || response.success === false) {
            throw new Error((response && (response.message || response.msg)) || i18n.loadFailed);
        }
        return response.data || response;
    }

    function bindPanel(root) {
        if (!(root instanceof HTMLElement) || root.getAttribute('data-affiliate-share-bound') === '1') {
            return;
        }
        root.setAttribute('data-affiliate-share-bound', '1');

        var i18n = readI18n(root);
        var productId = Number(root.getAttribute('data-product-id') || 0);
        var channel = String(root.getAttribute('data-affiliate-channel') || 'product_detail');
        var guestNode = root.querySelector('[data-affiliate-share-guest]');
        var readyNode = root.querySelector('[data-affiliate-share-ready]');
        var applyCta = root.querySelector('[data-affiliate-share-apply-cta]');
        var linkInput = root.querySelector('[data-affiliate-share-link]');
        var copyButton = root.querySelector('[data-affiliate-share-copy]');
        var nativeButton = root.querySelector('[data-affiliate-share-native]');
        var statusNode = root.querySelector('[data-affiliate-share-status]');
        var platformsNode = root.querySelector('[data-affiliate-share-platforms]');
        var shareData = null;
        var customerId = '';
        var loadSeq = 0;
        var trackPlatformClicks = false;

        if (applyCta) {
            applyCta.setAttribute('href', buildApplyLoginUrl(root));
            if (i18n.applyCta) {
                applyCta.textContent = i18n.applyCta;
            }
        }

        function setStatus(message, type) {
            if (!statusNode) {
                return;
            }
            statusNode.textContent = message || '';
            statusNode.classList.remove('is-loading', 'is-success', 'is-error');
            if (type) {
                statusNode.classList.add('is-' + type);
            }
        }

        function setMode(mode) {
            var isGuest = mode === 'guest';
            if (guestNode) {
                if (isGuest) {
                    guestNode.removeAttribute('hidden');
                } else {
                    guestNode.setAttribute('hidden', '');
                }
            }
            if (readyNode) {
                if (isGuest) {
                    readyNode.setAttribute('hidden', '');
                } else {
                    readyNode.removeAttribute('hidden');
                }
            }
            root.setAttribute('data-affiliate-share-mode', mode);
        }

        function disableControls() {
            if (copyButton) copyButton.disabled = true;
            if (nativeButton) nativeButton.disabled = true;
            if (linkInput) linkInput.value = '';
            shareData = null;
        }

        function enableControls() {
            if (copyButton) copyButton.disabled = false;
            if (nativeButton) nativeButton.disabled = false;
        }

        function renderPlatforms(platforms, options) {
            if (!platformsNode) {
                return;
            }
            var opts = options || {};
            var list = (platforms || []).filter(function (item) {
                return item && item.platform !== 'copy' && item.url;
            });
            // Never wipe SSR default icons with an empty list (trust + share affordance).
            if (list.length === 0) {
                return;
            }
            trackPlatformClicks = !!opts.track;
            platformsNode.innerHTML = '';
            list.forEach(function (item) {
                var platform = String(item.platform || '').toLowerCase();
                var label = item.label || item.platform;
                var shortLabel = platformShortLabels[platform] || label;
                var button = document.createElement('a');
                button.className = 'affiliate-share-platform' + (platform ? ' affiliate-share-platform--' + platform : '');
                button.href = item.url;
                button.target = '_blank';
                button.rel = 'noopener noreferrer';
                button.setAttribute('aria-label', label);
                button.setAttribute('title', label);
                button.setAttribute(
                    trackPlatformClicks ? 'data-affiliate-tracking-platform' : 'data-affiliate-default-platform',
                    platform
                );
                if (platformIcons[platform]) {
                    var logo = document.createElement('span');
                    logo.className = 'affiliate-share-platform__logo';
                    logo.innerHTML = platformIcons[platform];
                    button.appendChild(logo);
                }
                var text = document.createElement('span');
                text.className = 'affiliate-share-platform__label';
                text.textContent = shortLabel;
                button.appendChild(text);
                button.addEventListener('click', function () {
                    if (trackPlatformClicks) {
                        recordOutbound(item.platform || 'social');
                    }
                });
                platformsNode.appendChild(button);
            });
        }

        function syncDefaultPlatformHrefs() {
            var urls = buildFreePlatformUrls(resolveFreeShareUrl(root));
            if (!platformsNode) {
                return;
            }
            var existing = platformsNode.querySelectorAll('[data-affiliate-default-platform]');
            if (existing.length > 0 && urls.length > 0) {
                trackPlatformClicks = false;
                urls.forEach(function (item) {
                    var el = platformsNode.querySelector(
                        '[data-affiliate-default-platform="' + String(item.platform || '').toLowerCase() + '"]'
                    );
                    if (el && item.url) {
                        el.setAttribute('href', item.url);
                    }
                });
                return;
            }
            renderPlatforms(urls, { track: false });
        }

        function applyShareData(data) {
            setMode('ready');
            shareData = data || {};
            if (linkInput) {
                linkInput.value = shareData.tracking_url || '';
            }
            renderPlatforms(shareData.platform_urls || [], { track: true });
            enableControls();
            setStatus('');
        }

        function showGuestPromo() {
            disableControls();
            setMode('guest');
            setStatus('');
            if (applyCta) {
                applyCta.setAttribute('href', buildApplyLoginUrl(root));
            }
            syncDefaultPlatformHrefs();
        }

        function recordOutbound(platform) {
            if (!shareData || !shareData.share_code) {
                return Promise.resolve();
            }
            return getAffiliateApi().then(function (api) {
                if (!api || typeof api.recordOutboundShare !== 'function') {
                    return null;
                }
                return api.recordOutboundShare({
                    share_code: shareData.share_code,
                    platform: platform || 'copy'
                }, { silent: true });
            }).catch(function () {
                return null;
            });
        }

        function writeShareLinkToClipboard() {
            return new Promise(function (resolve) {
                var resolved = false;
                var finish = function () {
                    if (resolved) return;
                    resolved = true;
                    resolve(null);
                };
                var fallback = function () {
                    if (linkInput) {
                        linkInput.focus();
                        linkInput.select();
                        document.execCommand('copy');
                    }
                    finish();
                };
                if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function' && shareData && shareData.tracking_url) {
                    var timer = window.setTimeout(fallback, 800);
                    navigator.clipboard.writeText(shareData.tracking_url).then(function () {
                        window.clearTimeout(timer);
                        finish();
                    }).catch(function () {
                        window.clearTimeout(timer);
                        fallback();
                    });
                    return;
                }
                fallback();
            });
        }

        function copyShareLink() {
            if (!shareData || !shareData.tracking_url) {
                return;
            }
            recordOutbound('copy').then(function () {
                return writeShareLinkToClipboard();
            }).then(function () {
                setStatus(i18n.copied, 'success');
            });
        }

        function nativeShare() {
            if (!shareData || !shareData.tracking_url || !navigator.share) {
                copyShareLink();
                return;
            }
            recordOutbound('native').then(function () {
                return navigator.share({
                    title: document.title || '',
                    url: shareData.tracking_url
                });
            }).then(function () {
                setStatus(i18n.shared, 'success');
            }).catch(function () {});
        }

        function loadShareLinks(forceNetwork) {
            if (productId <= 0) {
                setStatus(i18n.unavailable, 'error');
                return Promise.resolve();
            }
            var seq = ++loadSeq;
            setStatus(i18n.loading, 'loading');
            disableControls();

            return ensureAccountModule().then(function (account) {
                var statusPromise = account && typeof account.checkFrontendUserLogin === 'function'
                    ? account.checkFrontendUserLogin({ force: !!forceNetwork })
                    : Promise.resolve({ isLogin: false, user: null, fromCache: false });
                return statusPromise.then(function (status) {
                    if (seq !== loadSeq) {
                        return null;
                    }
                    var loggedIn = !!(status && status.isLogin);
                    customerId = loggedIn ? resolveCustomerIdentity(account, status) : '';
                    if (!loggedIn) {
                        showGuestPromo();
                        return null;
                    }

                    setMode('ready');

                    if (!forceNetwork) {
                        var cached = getCachedShare(customerId, productId);
                        if (cached && cached.tracking_url) {
                            applyShareData(cached);
                            return cached;
                        }
                    }

                    return getAffiliateApi().then(function (api) {
                        if (!api || typeof api.getProductShareLinks !== 'function') {
                            throw new Error(i18n.shareUnsupported);
                        }
                        return api.getProductShareLinks({
                            product_id: productId,
                            channel: channel
                        }, { silent: true });
                    }).then(function (response) {
                        if (seq !== loadSeq) {
                            return null;
                        }
                        if (response && response.success === false) {
                            var msg = String(response.message || response.msg || '');
                            if (/请先登录|sign in|log ?in|未登录/i.test(msg)) {
                                showGuestPromo();
                                return null;
                            }
                        }
                        var data = normalizeResponse(response, i18n);
                        putCachedShare(customerId, productId, data);
                        applyShareData(data);
                        return data;
                    });
                });
            }).catch(function (error) {
                if (seq !== loadSeq) {
                    return;
                }
                var message = error && error.message ? error.message : i18n.unavailable;
                if (message === 'api-missing') {
                    message = i18n.apiMissing;
                }
                if (/请先登录|sign in|log ?in|未登录/i.test(message)) {
                    showGuestPromo();
                    return;
                }
                setMode('ready');
                setStatus(message, 'error');
            });
        }

        if (copyButton) {
            copyButton.addEventListener('click', copyShareLink);
        }
        if (nativeButton) {
            nativeButton.addEventListener('click', nativeShare);
        }

        root._affiliateShareReload = function (forceNetwork) {
            return loadShareLinks(!!forceNetwork);
        };

        // FPC guest shell already shows promo; hydrate after account session.
        loadShareLinks(false);
    }

    function scan(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('[data-affiliate-share-root="1"]').forEach(bindPanel);
    }

    function boot() {
        scan(document);
        window.addEventListener('weline:account:frontend:login', function () {
            clearShareCacheForCustomer('');
            document.querySelectorAll('[data-affiliate-share-root="1"]').forEach(function (root) {
                if (typeof root._affiliateShareReload === 'function') {
                    root._affiliateShareReload(true);
                }
            });
        });
        window.addEventListener('weline:account:frontend:logout', function () {
            clearShareCacheForCustomer('');
            document.querySelectorAll('[data-affiliate-share-root="1"]').forEach(function (root) {
                if (typeof root._affiliateShareReload === 'function') {
                    root._affiliateShareReload(false);
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }

    window.WelineAffiliateProductShare = {
        scan: scan,
        clearCache: clearShareCacheForCustomer
    };
})(window, document);
