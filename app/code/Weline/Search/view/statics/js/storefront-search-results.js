/**
 * Storefront search: Extra FPC query_keys SSR when present; else path-only shell.
 * Prefer embedded SSR payload (Shared HIT); otherwise BinQuery mode=page.
 */
(function () {
    'use strict';

    function parseJsonAttr(el, name) {
        try {
            var raw = el.getAttribute(name) || '{}';
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            return {};
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

    function formatNamed(template, value) {
        var t = String(template || '');
        var v = String(value == null ? '' : value);
        if (t.indexOf('%{1}') !== -1) {
            return t.split('%{1}').join(v);
        }
        // __() without args may eat %{1} → e.g. "搜索：" / "共  条"
        return t;
    }

    function formatCount(template, count) {
        var t = String(template || '');
        var n = String(count);
        if (t.indexOf('%{1}') !== -1) {
            return formatNamed(t, n);
        }
        // Recover "共  条" / "Total  items" style after placeholder was eaten.
        var recovered = t.replace(/\s{2,}/g, ' ' + n + ' ');
        if (recovered !== t) {
            return recovered.replace(/\s+/g, ' ').trim();
        }
        return t + n;
    }

    function formatMore(template, label) {
        return formatNamed(template, label || '');
    }

    function formatTitle(template, q) {
        var t = String(template || '');
        var query = String(q || '');
        if (t.indexOf('%{1}') !== -1) {
            return formatNamed(t, query);
        }
        if (!query) {
            return t;
        }
        // Recover "搜索：" when %{1} was eaten by SSR __().
        if (/[:：]\s*$/.test(t)) {
            return t.replace(/\s*$/, '') + query;
        }
        return t + query;
    }

    function readUrlParams() {
        var params = new URLSearchParams(window.location.search || '');
        var q = String(params.get('q') || '').trim();
        var type = String(params.get('type') || 'all').trim() || 'all';
        var page = String(params.get('page') || '1').trim() || '1';
        var pageSize = String(params.get('page_size') || '24').trim() || '24';
        var categoryId = String(params.get('category_id') || '').trim();
        return {
            q: q,
            type: type,
            page: page,
            page_size: pageSize,
            category_id: categoryId,
        };
    }

    function hydrateFromUrl(root) {
        if (!root || root.getAttribute('data-search-hydrate-url') !== '1') {
            return readUrlParams();
        }
        var params = readUrlParams();
        root.setAttribute('data-search-q', params.q);
        root.setAttribute('data-search-type', params.type);
        root.setAttribute('data-search-page', params.page);
        root.setAttribute('data-search-page-size', params.page_size);
        root.setAttribute('data-search-category-id', params.category_id || '0');
        root.setAttribute('data-search-async', '1');

        var i18n = parseJsonAttr(root, 'data-search-i18n');
        var titleEl = root.querySelector('[data-search-title]');
        var meta = root.querySelector('[data-search-meta]');
        var crumb = root.querySelector('[data-search-breadcrumb]');
        if (params.q) {
            var titled = formatTitle(i18n.title_with_q || '', params.q) || params.q;
            if (titleEl) {
                titleEl.textContent = titled;
            }
            if (meta) {
                meta.textContent = i18n.loading || '';
            }
            if (crumb) {
                crumb.hidden = false;
            }
            try {
                document.title = titled;
            } catch (e) {}
        } else if (titleEl) {
            titleEl.textContent = i18n.title || titleEl.textContent;
            if (meta) {
                meta.textContent = i18n.prompt || '';
            }
            if (crumb) {
                crumb.hidden = true;
            }
        }

        document.querySelectorAll('form[data-search-type-filter] input[name="q"]').forEach(function (input) {
            input.value = params.q;
        });
        document.querySelectorAll('form[data-search-type-filter] input[name="category_id"]').forEach(function (input) {
            input.value = params.category_id && params.category_id !== '0' ? params.category_id : '';
        });

        // Product facet host stays out of shared FPC HTML; create only for type=product.
        var aside = document.querySelector('.search-layout__filters');
        if (aside) {
            var host = aside.querySelector('[data-search-product-filters-host]');
            if (params.type === 'product') {
                if (!host) {
                    host = document.createElement('div');
                    host.setAttribute('data-search-product-filters-host', '1');
                    var slot = aside.querySelector('[data-slot-id="search-filters"]');
                    if (slot && slot.parentNode === aside) {
                        aside.insertBefore(host, slot);
                    } else {
                        aside.appendChild(host);
                    }
                }
                host.hidden = false;
                host.setAttribute('aria-hidden', 'false');
            } else if (host) {
                host.hidden = true;
                host.setAttribute('aria-hidden', 'true');
            }
        }

        return params;
    }

    async function searchApi() {
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            return null;
        }
        try {
            return await window.Weline.Api.resource('search');
        } catch (e) {
            return null;
        }
    }

    function unwrapPayload(result) {
        if (result && result.data && typeof result.data === 'object') {
            return result.data;
        }
        return result && typeof result === 'object' ? result : {};
    }

    function hitUrl(hit) {
        var url = String((hit && hit.url) || '').trim();
        if (url) {
            return url;
        }
        var productId = parseInt(String((hit && hit.product_id) || '0'), 10) || 0;
        if (productId > 0) {
            return '/product/' + productId;
        }
        var entityId = String((hit && hit.entity_id) || '').trim();
        if (entityId) {
            return '/product/' + encodeURIComponent(entityId);
        }
        return '';
    }

    function offerFromHit(hit) {
        if (!hit || typeof hit !== 'object') {
            return null;
        }
        if (hit.storefront_offer && typeof hit.storefront_offer === 'object') {
            return hit.storefront_offer;
        }
        if (hit.payload && hit.payload.storefront_offer && typeof hit.payload.storefront_offer === 'object') {
            return hit.payload.storefront_offer;
        }
        return null;
    }

    function formatOfferPrice(offer) {
        if (!offer || typeof offer !== 'object') {
            return '';
        }
        if (offer.quote_only || offer.currency_unavailable) {
            return '';
        }
        var minor = parseInt(String(offer.unit_price_minor || 0), 10) || 0;
        if (minor <= 0) {
            return '';
        }
        var currency = String(offer.currency || '').trim();
        var major = (minor / 100).toFixed(2);
        return currency ? currency + ' ' + major : major;
    }

    function resolveImage(src, placeholder) {
        var image = String(src || '').trim();
        if (image && image.indexOf('data:image/') !== 0) {
            return image;
        }
        return String(placeholder || '').trim();
    }

    function renderProductOfferCard(hit, offer, placeholder) {
        var productId = parseInt(String((offer && offer.product_id) || (hit && hit.product_id) || '0'), 10) || 0;
        var entityId = String((hit && hit.entity_id) || productId || '').trim();
        var title = String((offer && offer.name) || (hit && (hit.title || hit.name)) || '').trim();
        var url = hitUrl(hit);
        if (!url && productId > 0) {
            url = '/product/' + productId;
        }
        var image = resolveImage((offer && (offer.image || (offer.images && offer.images[0]))) || '', placeholder);
        var price = formatOfferPrice(offer);
        var sku = String((offer && offer.sku) || '').trim();
        var clickable = url ? ' is-clickable' : '';
        var hitLink = url
            ? '<a class="product-storefront__card-hit" href="' +
              escapeHtml(url) +
              '" aria-label="' +
              escapeHtml(title || entityId) +
              '"></a>'
            : '';
        return (
            '<article class="product-storefront__card' +
            clickable +
            '" data-testid="storefront-search-product-card" data-entity-id="' +
            escapeHtml(entityId) +
            '"' +
            (productId > 0 ? ' data-product-id="' + productId + '"' : '') +
            '>' +
            hitLink +
            '<div class="product-storefront__media">' +
            (image
                ? '<img src="' +
                  escapeHtml(image) +
                  '" alt="" width="400" height="400" loading="lazy" decoding="async" data-storefront-img="1" data-fallback="' +
                  escapeHtml(placeholder || image) +
                  '">'
                : '') +
            '</div>' +
            '<div class="product-storefront__body">' +
            '<h2>' +
            escapeHtml(title || entityId || '') +
            '</h2>' +
            (sku ? '<p class="product-storefront__sku">' + escapeHtml(sku) + '</p>' : '') +
            (price ? '<p class="product-storefront__price">' + escapeHtml(price) + '</p>' : '') +
            '</div></article>'
        );
    }

    function renderBlogCard(hit, placeholder) {
        var payload = hit && hit.payload && typeof hit.payload === 'object' ? hit.payload : hit || {};
        var title = String((payload && payload.title) || (hit && hit.title) || '').trim();
        var entityId = String((payload && payload.entity_id) || (hit && hit.entity_id) || '').trim();
        var url = String((payload && payload.url) || (hit && hit.url) || '').trim() || hitUrl(hit);
        var image = resolveImage((payload && payload.image) || '', placeholder);
        var excerpt = String((payload && payload.excerpt) || '').trim();
        var clickable = url ? ' is-clickable' : '';
        var hitLink = url
            ? '<a class="blog-storefront__card-hit" href="' +
              escapeHtml(url) +
              '" aria-label="' +
              escapeHtml(title || entityId) +
              '" data-testid="search-hit-blog-post"></a>'
            : '';
        return (
            '<article class="blog-storefront__card' +
            clickable +
            '" data-testid="storefront-blog-card" data-entity-id="' +
            escapeHtml(entityId) +
            '">' +
            hitLink +
            '<div class="blog-storefront__media w-frame" data-ratio="4/3" data-fit="cover">' +
            (image
                ? '<img src="' +
                  escapeHtml(image) +
                  '" alt="" width="1200" height="800" loading="lazy" decoding="async" data-storefront-img="1" data-fallback="' +
                  escapeHtml(placeholder || image) +
                  '">'
                : '') +
            '</div>' +
            '<div class="blog-storefront__body"><h2>' +
            escapeHtml(title || entityId || '') +
            '</h2>' +
            (excerpt ? '<p class="blog-storefront__excerpt">' + escapeHtml(excerpt) + '</p>' : '') +
            '</div></article>'
        );
    }

    function renderCard(hit, placeholder) {
        var offer = offerFromHit(hit);
        if (offer) {
            return renderProductOfferCard(hit, offer, placeholder);
        }
        var indexer = String((hit && (hit.type || hit.indexer || hit.entity_type)) || '').trim();
        var payload = hit && hit.payload && typeof hit.payload === 'object' ? hit.payload : null;
        if (indexer === 'blog' || (payload && (payload.image || payload.excerpt || payload.content_kind))) {
            return renderBlogCard(hit, placeholder);
        }
        var title = String((hit && (hit.title || hit.name)) || '').trim();
        var entityId = String((hit && hit.entity_id) || '').trim();
        var url = hitUrl(hit);
        var label = escapeHtml(title || entityId || '');
        var link = url
            ? '<a href="' + escapeHtml(url) + '">' + label + '</a>'
            : label;
        return (
            '<article class="storefront-search__card" data-entity-id="' +
            escapeHtml(entityId) +
            '"><h3>' +
            link +
            '</h3></article>'
        );
    }

    function gridClass(sectionType, activeType) {
        var resolved = String(sectionType || activeType || '').trim();
        var classes = 'storefront-search__grid';
        if (resolved === 'product' || resolved === 'blog') {
            classes += ' storefront-search__grid--' + resolved;
        }
        return classes;
    }

    function typeLabel(code, labels) {
        var key = String(code || '').trim();
        if (key && labels[key]) {
            return String(labels[key]);
        }
        return key || '';
    }

    function renderResults(payload, root, i18n, labels) {
        var host = root.querySelector('[data-search-results-host]');
        var meta = root.querySelector('[data-search-meta]');
        if (!host) {
            return;
        }
        var type = String(root.getAttribute('data-search-type') || 'all') || 'all';
        var q = String(root.getAttribute('data-search-q') || '');
        var placeholder = String(root.getAttribute('data-search-img-placeholder') || '').trim();
        var success = payload.success !== false;
        var errorMessage = String(payload.message || payload.error || '').trim();
        var hitCount = parseInt(String(payload.hit_count || 0), 10) || 0;
        var hits = Array.isArray(payload.hits) ? payload.hits : [];
        var sections = payload.sections && typeof payload.sections === 'object' ? payload.sections : {};

        if (meta) {
            meta.textContent = success
                ? formatCount(i18n.count || '', hitCount)
                : (errorMessage || i18n.error || '');
        }

        // Shared FPC already painted provider hit templates (cards with images).
        if (host.getAttribute('data-search-ssr-html') === '1' && success) {
            return;
        }

        if (!success) {
            host.innerHTML =
                '<div class="storefront-search__error" role="alert">' +
                escapeHtml(errorMessage || i18n.error || '') +
                '</div>';
            return;
        }

        if (type === 'all') {
            var keys = Object.keys(sections);
            if (!keys.length && !hits.length) {
                host.innerHTML =
                    '<div class="storefront-search__empty" data-testid="storefront-search-empty">' +
                    escapeHtml(i18n.empty || '') +
                    '</div>';
                return;
            }
            var html = '<div class="storefront-search__results">';
            keys.forEach(function (sectionType) {
                var sectionHits = Array.isArray(sections[sectionType]) ? sections[sectionType] : [];
                var isEmptyProduct = sectionType === 'product' && sectionHits.length === 0;
                if (!sectionHits.length && !isEmptyProduct) {
                    return;
                }
                var sectionLabel = typeLabel(sectionType, labels);
                html +=
                    '<div class="storefront-search__section" data-section-type="' +
                    escapeHtml(sectionType) +
                    '"><h2 class="storefront-search__section-title">' +
                    escapeHtml(sectionLabel) +
                    '</h2>';
                if (isEmptyProduct) {
                    html +=
                        '<p class="storefront-search__section-empty" data-testid="storefront-search-product-empty" role="status">' +
                        escapeHtml(i18n.product_empty || '') +
                        ' · ' +
                        escapeHtml(i18n.product_empty_hint || '') +
                        '</p>';
                } else {
                    html += '<div class="' + escapeHtml(gridClass(sectionType, type)) + '">';
                    sectionHits.forEach(function (hit) {
                        html += renderCard(hit, placeholder);
                    });
                    html += '</div>';
                    html +=
                        '<p class="storefront-search__section-more"><a href="/search?q=' +
                        encodeURIComponent(q) +
                        '&amp;type=' +
                        encodeURIComponent(sectionType) +
                        '">' +
                        escapeHtml(formatMore(i18n.more || '', sectionLabel)) +
                        ' →</a></p>';
                }
                html += '</div>';
            });
            html += '</div>';
            host.innerHTML = html;
            return;
        }

        if (!hits.length) {
            host.innerHTML =
                '<div class="storefront-search__empty" data-testid="storefront-search-empty">' +
                escapeHtml(i18n.empty || '') +
                '</div>';
            return;
        }
        var typedHtml = '<div class="storefront-search__results"><div class="' +
            escapeHtml(gridClass(null, type)) +
            '">';
        hits.forEach(function (hit) {
            typedHtml += renderCard(hit, placeholder);
        });
        typedHtml += '</div></div>';
        host.innerHTML = typedHtml;
    }

    async function loadRoot(root) {
        if (!root || root.getAttribute('data-search-async') !== '1') {
            return;
        }
        if (root.getAttribute('data-search-async-init') === '1') {
            return;
        }
        root.setAttribute('data-search-async-init', '1');

        var paramsFromUrl = hydrateFromUrl(root);
        var q = String(paramsFromUrl.q || root.getAttribute('data-search-q') || '').trim();
        if (!q) {
            return;
        }
        var i18n = parseJsonAttr(root, 'data-search-i18n');
        var labels = parseJsonAttr(root, 'data-search-type-labels');
        var host = root.querySelector('[data-search-results-host]');

        // Shared FPC SSR: payload already matches allowlisted query identity.
        if (root.getAttribute('data-search-ssr-ready') === '1') {
            var ssrNode = root.querySelector('[data-search-ssr-payload]');
            if (ssrNode) {
                try {
                    var ssrPayload = JSON.parse(String(ssrNode.textContent || '{}'));
                    if (ssrPayload && typeof ssrPayload === 'object') {
                        renderResults(ssrPayload, root, i18n, labels);
                        return;
                    }
                } catch (e) {
                    // Fall through to BinQuery.
                }
            }
        }

        if (host) {
            host.innerHTML =
                '<div class="storefront-search__loading" data-testid="storefront-search-loading" role="status">' +
                escapeHtml(i18n.loading || '') +
                '</div>';
        }
        var params = {
            q: q,
            type: String(root.getAttribute('data-search-type') || 'all') || 'all',
            page: String(root.getAttribute('data-search-page') || '1') || '1',
            page_size: String(root.getAttribute('data-search-page-size') || '24') || '24',
            area: 'frontend',
            mode: 'page',
        };
        var categoryId = String(root.getAttribute('data-search-category-id') || '').trim();
        if (categoryId && categoryId !== '0') {
            params.category_id = categoryId;
        }

        try {
            var api = await searchApi();
            if (!api || typeof api.search !== 'function') {
                renderResults({ success: false, message: i18n.error || '' }, root, i18n, labels);
                return;
            }
            var result = await api.search(params);
            renderResults(unwrapPayload(result), root, i18n, labels);
        } catch (e) {
            renderResults({ success: false, message: i18n.error || '' }, root, i18n, labels);
        }
    }

    function boot(root) {
        var nodes = root
            ? [root]
            : Array.prototype.slice.call(document.querySelectorAll('[data-testid="storefront-search"][data-search-async="1"]'));
        nodes.forEach(function (node) {
            loadRoot(node);
        });
    }

    window.WelineStorefrontSearchResults = {
        init: boot,
        boot: boot,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            boot();
        });
    } else {
        boot();
    }
})();
