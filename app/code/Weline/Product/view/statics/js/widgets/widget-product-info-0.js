window.WelineWidgetAssets.register('product-product-info-0', function (widgetScript) {
(function () {
    'use strict';
    const root = document.querySelector('[data-testid="storefront-product-detail"]');
    if (!root) return;

    (function ensurePurchaseActionsFromFailsafe() {
        // Nested w:slot may compile to bare markers with no wrapper when empty.
        // Always materialize .product-native-detail__actions so failsafe CTAs can land.
        let slot = root.querySelector('.product-native-detail__actions');
        const failsafe = root.querySelector('[data-purchase-failsafe]');
        if (!failsafe) {
            return;
        }
        const ctaSelector = '[data-action="add"], [data-action="buy-now"], [data-testid="product-add-to-cart"], [data-testid="product-buy-now"]';
        const isOutsideFailsafe = function (el) {
            return !!(el && !el.closest('[data-purchase-failsafe]'));
        };
        const hasCta = function (node) {
            if (!node) {
                return false;
            }
            return [...node.querySelectorAll(ctaSelector)].some(isOutsideFailsafe);
        };
        // Only trust live purchase CTAs outside the failsafe bag.
        // Matching failsafe itself used to delete the only add/buy controls (HelpPay-only slot).
        const liveCta = [...root.querySelectorAll(ctaSelector)].find(isOutsideFailsafe);
        if (hasCta(slot)
            || hasCta(root.querySelector('[data-wslot="product-purchase-actions"]'))
            || liveCta) {
            failsafe.remove();
            return;
        }
        if (!slot) {
            slot = document.createElement('div');
            slot.className = 'product-native-detail__actions';
            slot.setAttribute('data-wslot', 'product-purchase-actions');
            slot.setAttribute('data-testid', 'product-purchase-actions');
            const express = root.querySelector('.product-native-detail__express, [data-wslot="product-express-payment"]');
            const qty = root.querySelector('.product-native-detail__qty, .product-native-detail__quantity');
            if (express && express.parentNode) {
                express.parentNode.insertBefore(slot, express);
            } else if (qty && qty.parentNode) {
                qty.parentNode.insertBefore(slot, qty.nextSibling);
            } else {
                const buybox = root.querySelector('.product-native-detail__buybox, .product-native-detail__purchase, .product-native-detail__info') || root;
                buybox.appendChild(slot);
            }
        }
        failsafe.hidden = false;
        while (failsafe.firstChild) {
            slot.appendChild(failsafe.firstChild);
        }
        failsafe.remove();
    })();

    (function coalesceBuyboxPurchaseActions() {
        const buybox = root.querySelector('.product-native-detail__buybox, .product-native-detail__purchase');
        if (!buybox) {
            return;
        }
        const widgetRoot = function (el) {
            return el.closest('.weline-cart-product-add-to-cart, .weline-checkout-product-buy-now, .widget-wrapper') || el;
        };
        const keepFirst = function (nodes) {
            if (!nodes.length) {
                return null;
            }
            for (let i = 1; i < nodes.length; i++) {
                widgetRoot(nodes[i]).remove();
            }
            return nodes[0];
        };
        const add = keepFirst([...buybox.querySelectorAll('[data-testid="product-add-to-cart"], [data-action="add"]')]);
        const buy = keepFirst([...buybox.querySelectorAll('[data-testid="product-buy-now"], [data-action="buy-now"]')]);
        if (!add && !buy) {
            return;
        }
        let slot = buybox.querySelector('.product-native-detail__actions, [data-wslot="product-purchase-actions"]');
        if (!slot) {
            slot = document.createElement('div');
            slot.className = 'product-native-detail__actions';
            slot.setAttribute('data-wslot', 'product-purchase-actions');
            slot.setAttribute('data-testid', 'product-purchase-actions');
            const anchor = widgetRoot(add || buy);
            if (anchor.parentNode) {
                anchor.parentNode.insertBefore(slot, anchor);
            } else {
                buybox.appendChild(slot);
            }
        }
        [add, buy].forEach(function (el) {
            if (!el) {
                return;
            }
            const wr = widgetRoot(el);
            if (!slot.contains(wr)) {
                slot.appendChild(wr);
            }
        });
        [...buybox.querySelectorAll('.w-helppay-cta--quiet')].forEach(function (el) {
            const wr = widgetRoot(el);
            if (wr.parentElement === buybox && !slot.contains(wr)) {
                slot.appendChild(wr);
            }
        });
    })();

    const primaryImage = root.querySelector('.product-native-detail__primary-image');
    const primaryVideo = root.querySelector('.product-native-detail__primary-video');

    function constrainPrimaryImageScale() {
        if (!primaryImage || primaryImage.hidden) {
            return;
        }
        const naturalWidth = primaryImage.naturalWidth || 0;
        if (naturalWidth < 1) {
            primaryImage.style.maxWidth = '100%';
            return;
        }
        // Never CSS-upscale past 1 CSS px per source px; HD assets still fill the stage.
        primaryImage.style.maxWidth = 'min(100%, ' + naturalWidth + 'px)';
    }

    if (primaryImage) {
        primaryImage.addEventListener('load', constrainPrimaryImageScale);
        if (primaryImage.complete) {
            constrainPrimaryImageScale();
        }
        window.addEventListener('resize', constrainPrimaryImageScale);
    }

    function renderGalleryVideo(type, src, poster, provider, mime) {
        if (!primaryVideo) {
            return;
        }
        primaryVideo.innerHTML = '';
        if (type !== 'video' || !src) {
            primaryVideo.hidden = true;
            if (primaryImage) {
                primaryImage.hidden = false;
            }
            return;
        }
        if (primaryImage) {
            primaryImage.hidden = true;
            if (poster) {
                primaryImage.src = poster;
            }
        }
        primaryVideo.hidden = false;
        if (provider === 'youtube' || provider === 'vimeo') {
            const frame = document.createElement('iframe');
            frame.className = 'product-native-detail__video-frame';
            frame.src = src;
            frame.title = 'Product video';
            frame.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
            frame.setAttribute('allowfullscreen', 'allowfullscreen');
            frame.loading = 'lazy';
            frame.referrerPolicy = 'strict-origin-when-cross-origin';
            primaryVideo.appendChild(frame);
            return;
        }
        const video = document.createElement('video');
        video.className = 'product-native-detail__video-file';
        video.src = src;
        video.controls = true;
        video.playsInline = true;
        if (poster) {
            video.poster = poster;
        }
        if (mime) {
            video.setAttribute('type', mime);
        }
        primaryVideo.appendChild(video);
    }

    let onGalleryThumbActivate = null;

    function bindGalleryThumb(thumb) {
        thumb.addEventListener('click', function () {
            if (typeof onGalleryThumbActivate === 'function') {
                const axis = thumb.dataset.galleryAxis || '';
                const value = thumb.dataset.galleryValue || '';
                if (axis !== '' && value !== '' && onGalleryThumbActivate(thumb)) {
                    return;
                }
            }
            const type = thumb.dataset.galleryType || 'image';
            const src = thumb.dataset.gallerySrc || '';
            const poster = thumb.dataset.galleryPoster || '';
            const provider = thumb.dataset.galleryProvider || '';
            const mime = thumb.dataset.galleryMime || '';
            if (type === 'video') {
                renderGalleryVideo('video', src, poster, provider, mime);
            } else if (primaryImage) {
                primaryImage.src = src || primaryImage.src;
                primaryImage.hidden = false;
                renderGalleryVideo('image', '', '', '', '');
            }
            if (typeof syncImageZoomSource === 'function') {
                syncImageZoomSource();
            }
            root.querySelectorAll('[data-gallery-src]').forEach(function (item) {
                const active = item === thumb;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            if (typeof thumb.scrollIntoView === 'function') {
                thumb.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        });
    }

    root.querySelectorAll('[data-gallery-src]').forEach(bindGalleryThumb);
    const initialThumb = root.querySelector('[data-gallery-src].is-active')
        || root.querySelector('[data-gallery-src]');
    if (initialThumb && (initialThumb.dataset.galleryType || 'image') === 'video') {
        renderGalleryVideo(
            'video',
            initialThumb.dataset.gallerySrc || '',
            initialThumb.dataset.galleryPoster || '',
            initialThumb.dataset.galleryProvider || '',
            initialThumb.dataset.galleryMime || ''
        );
    }

    (function bindSpecificationsClamp() {
        const clamp = root.querySelector('[data-testid="product-specifications-clamp"]');
        const body = clamp ? clamp.querySelector('.product-native-detail__spec-clamp-body') : null;
        const more = clamp ? clamp.querySelector('[data-testid="product-specifications-more"]') : null;
        if (!clamp || !body || !more) {
            return;
        }

        const labelMore = more.getAttribute('data-label-more') || more.textContent.trim() || '展示更多';
        const labelLess = more.getAttribute('data-label-less') || '收起';

        function clampLimitPx() {
            const raw = getComputedStyle(clamp).getPropertyValue('--product-spec-clamp-max').trim();
            const probe = document.createElement('div');
            probe.style.cssText = 'position:absolute;visibility:hidden;height:' + (raw || '15.5rem');
            clamp.appendChild(probe);
            const px = probe.getBoundingClientRect().height;
            probe.remove();
            return px > 0 ? px : 248;
        }

        function setCollapsed(collapsed) {
            clamp.setAttribute('data-collapsed', collapsed ? '1' : '0');
            more.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            more.textContent = collapsed ? labelMore : labelLess;
            if (!collapsed) {
                clamp.classList.add('is-overflow');
                more.hidden = false;
                return;
            }
            refresh();
        }

        function refresh() {
            const overflow = body.scrollHeight > clampLimitPx() + 2;
            const collapsed = clamp.getAttribute('data-collapsed') !== '0';
            if (!overflow) {
                clamp.classList.remove('is-overflow');
                clamp.setAttribute('data-collapsed', '1');
                more.hidden = true;
                more.textContent = labelMore;
                more.setAttribute('aria-expanded', 'false');
                return;
            }
            clamp.classList.add('is-overflow');
            more.hidden = false;
            if (collapsed) {
                clamp.setAttribute('data-collapsed', '1');
                more.textContent = labelMore;
                more.setAttribute('aria-expanded', 'false');
            } else {
                more.textContent = labelLess;
                more.setAttribute('aria-expanded', 'true');
            }
        }

        more.addEventListener('click', function () {
            setCollapsed(clamp.getAttribute('data-collapsed') === '0');
        });

        refresh();
        if (typeof ResizeObserver === 'function') {
            const observer = new ResizeObserver(refresh);
            observer.observe(body);
            const section = clamp.closest('.product-native-detail__specifications');
            if (section) {
                observer.observe(section);
            }
        } else {
            window.addEventListener('resize', refresh);
        }
    })();

    let syncImageZoomSource = null;
    (function bindImageZoom() {
        const wrap = root.querySelector('[data-image-zoom="1"]');
        const stage = wrap ? wrap.querySelector('.product-native-detail__stage') : null;
        const lens = wrap ? wrap.querySelector('.product-native-detail__zoom-lens') : null;
        const result = wrap ? wrap.querySelector('.product-native-detail__zoom-result') : null;
        if (!wrap || !stage || !primaryImage || !lens || !result) {
            return;
        }

        const zoomFactor = 2.4;
        let active = false;

        syncImageZoomSource = function () {
            const src = primaryImage.currentSrc || primaryImage.src || '';
            result.style.backgroundImage = src ? ('url(' + JSON.stringify(src) + ')') : '';
        };

        function hideZoom() {
            active = false;
            lens.hidden = true;
            result.hidden = true;
        }

        function canZoom() {
            const videoVisible = primaryVideo && !primaryVideo.hidden;
            return !videoVisible
                && !primaryImage.hidden
                && !window.matchMedia('(max-width: 992px)').matches
                && primaryImage.naturalWidth > 0
                && primaryImage.clientWidth > 0;
        }

        function placeZoomResult() {
            const gallery = root.querySelector('.product-native-detail__gallery') || wrap;
            const galleryRect = gallery.getBoundingClientRect();
            const stageRect = stage.getBoundingClientRect();
            const gap = 16;
            const margin = 12;
            const spaceRight = window.innerWidth - galleryRect.right - gap - margin;
            const spaceLeft = galleryRect.left - gap - margin;
            let size = Math.min(440, Math.max(240, Math.round(window.innerWidth * 0.3)));
            let left;

            // Prefer the right of the whole gallery (over buy column). Never cover the stage/image.
            if (spaceRight >= 220) {
                size = Math.min(size, spaceRight);
                left = galleryRect.right + gap;
            } else if (spaceLeft >= 220) {
                size = Math.min(size, spaceLeft);
                left = galleryRect.left - gap - size;
            } else {
                // Shrink to remaining right space instead of flipping over the main image.
                size = Math.max(180, Math.min(size, Math.max(spaceRight, 180)));
                left = galleryRect.right + gap;
                if (left + size > window.innerWidth - margin) {
                    size = Math.max(180, window.innerWidth - margin - left);
                }
            }

            let top = stageRect.top;
            if (top + size > window.innerHeight - margin) {
                top = Math.max(margin, window.innerHeight - size - margin);
            }

            result.style.width = size + 'px';
            result.style.height = size + 'px';
            result.style.left = left + 'px';
            result.style.top = top + 'px';
        }

        function showZoom() {
            if (!canZoom()) {
                hideZoom();
                return;
            }
            active = true;
            syncImageZoomSource();
            placeZoomResult();
            lens.hidden = false;
            result.hidden = false;
        }

        function moveZoom(event) {
            if (!active && canZoom()) {
                showZoom();
            }
            if (!active) {
                return;
            }

            const rect = stage.getBoundingClientRect();
            const imageWidth = primaryImage.clientWidth;
            const imageHeight = primaryImage.clientHeight;
            const lensWidth = lens.offsetWidth || 120;
            const lensHeight = lens.offsetHeight || 120;
            let x = event.clientX - rect.left - lensWidth / 2;
            let y = event.clientY - rect.top - lensHeight / 2;
            x = Math.max(0, Math.min(x, Math.max(0, imageWidth - lensWidth)));
            y = Math.max(0, Math.min(y, Math.max(0, imageHeight - lensHeight)));
            lens.style.transform = 'translate(' + x + 'px, ' + y + 'px)';
            result.style.backgroundSize = (imageWidth * zoomFactor) + 'px ' + (imageHeight * zoomFactor) + 'px';
            result.style.backgroundPosition = (-x * zoomFactor) + 'px ' + (-y * zoomFactor) + 'px';
        }

        stage.addEventListener('mouseenter', showZoom);
        stage.addEventListener('mousemove', moveZoom);
        stage.addEventListener('mouseleave', hideZoom);
        primaryImage.addEventListener('load', syncImageZoomSource);
        syncImageZoomSource();
    })();

    const qtySelect = root.querySelector('[data-testid="product-qty"]');
    const syncPurchaseQty = function () {
        const qty = Math.max(1, Number(qtySelect && qtySelect.value ? qtySelect.value : 1) || 1);
        root.querySelectorAll('[data-action="add"], [data-action="buy-now"]').forEach(function (button) {
            button.dataset.qty = String(qty);
        });
    };
    if (qtySelect) {
        qtySelect.addEventListener('change', syncPurchaseQty);
        syncPurchaseQty();
    }

    const observer = new MutationObserver(syncPurchaseQty);
    const actionsSlot = root.querySelector('.product-native-detail__actions');
    if (actionsSlot) {
        observer.observe(actionsSlot, { childList: true, subtree: true });
    }

    const variantAxes = root.querySelector('[data-variant-interactive="1"]');
    const catalogElement = document.getElementById(('product-variant-catalog-' + widgetScript.dataset.v0));
    if (!variantAxes || !catalogElement) {
        return;
    }

    let catalog = {};
    try {
        catalog = JSON.parse(catalogElement.textContent || '{}');
    } catch (error) {
        return;
    }

    const offers = Array.isArray(catalog.offers) ? catalog.offers : [];
    const axes = Array.isArray(catalog.axes) ? catalog.axes : [];
    const baseImages = Array.isArray(catalog.base_images) ? catalog.base_images : [];
    // Snapshot PHP-rendered product gallery before JS rebuilds thumbs (main images).
    const pageProductImages = [];
    root.querySelectorAll('.product-native-detail__thumbs [data-gallery-src]').forEach(function (el) {
        const type = el.dataset.galleryType || 'image';
        const src = String(el.dataset.gallerySrc || '').trim();
        if (type === 'image' && src !== '' && pageProductImages.indexOf(src) < 0) {
            pageProductImages.push(src);
        }
    });
    const productVideos = JSON.parse(widgetScript.dataset.v1 || 'null');
    if (offers.length === 0 || axes.length === 0) {
        return;
    }

    let selection = Object.assign({}, catalog.selected || {});
    const basePath = window.location.pathname;
    const isQuickAdd = String(root.getAttribute('data-quick-add') || '') === '1';

    function normalizeSelection(input) {
        const normalized = {};
        Object.keys(input || {}).sort().forEach(function (axisCode) {
            const value = String(input[axisCode] || '').trim();
            if (value !== '') {
                normalized[axisCode] = value;
            }
        });
        return normalized;
    }

    function combinationMatches(combination, current) {
        const combo = normalizeSelection(combination);
        const selected = normalizeSelection(current);
        return Object.keys(selected).every(function (axisCode) {
            return combo[axisCode] === selected[axisCode];
        });
    }

    function findExactOffer(current) {
        const selected = normalizeSelection(current);
        return offers.find(function (offer) {
            const combo = normalizeSelection(offer.combination || {});
            return Object.keys(selected).length > 0
                && Object.keys(selected).length === Object.keys(combo).length
                && combinationMatches(combo, selected);
        }) || null;
    }

    function findBestOffer(current) {
        const exact = findExactOffer(current);
        if (exact) {
            return exact;
        }
        let best = null;
        let bestScore = -1;
        offers.forEach(function (offer) {
            const combo = normalizeSelection(offer.combination || {});
            if (!combinationMatches(combo, current)) {
                return;
            }
            const score = Object.keys(normalizeSelection(current)).length;
            if (score > bestScore) {
                bestScore = score;
                best = offer;
            }
        });
        return best;
    }

    function mergeLiveOffers(liveOffers) {
        const byUuid = {};
        liveOffers.forEach(function (row) {
            if (row && row.global_offer_uuid) {
                byUuid[row.global_offer_uuid] = row;
            }
        });
        offers.forEach(function (offer, index) {
            const live = byUuid[offer.global_offer_uuid];
            if (!live) {
                return;
            }
            // Availability API returns raw catalog unit_price_minor (no Assembler
            // deal). Overwriting would wipe SSR deal price (e.g. 19620 → 21800).
            const next = Object.assign({}, offer, {
                stock: live.stock,
                sellable: live.sellable,
                currency_unavailable: live.currency_unavailable != null
                    ? !!live.currency_unavailable
                    : !!offer.currency_unavailable,
                quote_only: live.quote_only,
                currency: live.currency || offer.currency,
                message: live.message,
            });
            const liveHasDealFields = live.catalog_price_minor != null
                || live.compare_at_minor != null
                || live.has_deal != null;
            if (liveHasDealFields) {
                if (live.unit_price_minor != null) {
                    next.unit_price_minor = live.unit_price_minor;
                }
                if (live.catalog_price_minor != null) {
                    next.catalog_price_minor = live.catalog_price_minor;
                }
                if (live.compare_at_minor != null) {
                    next.compare_at_minor = live.compare_at_minor;
                }
                if (live.has_deal != null) {
                    next.has_deal = live.has_deal;
                }
            }
            offers[index] = next;
        });
    }

    let liveRefreshTimer = null;
    let liveRefreshToken = 0;

    function scheduleLiveAvailabilityRefresh() {
        const liveUrl = variantAxes.dataset.variantLiveUrl || '';
        if (!liveUrl) {
            return;
        }
        if (liveRefreshTimer) {
            clearTimeout(liveRefreshTimer);
        }
        liveRefreshTimer = setTimeout(refreshLiveAvailability, 120);
    }

    async function refreshLiveAvailability() {
        const liveUrl = variantAxes.dataset.variantLiveUrl || '';
        if (!liveUrl) {
            return;
        }
        const token = ++liveRefreshToken;
        try {
            const params = new URLSearchParams();
            params.set('product_id', String(Number(widgetScript.dataset.v2)));
            const response = await fetch(liveUrl + '?' + params.toString(), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (!response.ok || token !== liveRefreshToken) {
                return;
            }
            const payload = await response.json();
            if (token !== liveRefreshToken || !payload || !payload.success || !Array.isArray(payload.offers)) {
                return;
            }
            mergeLiveOffers(payload.offers);
            applySelection(selection, { pinnedAxes: [], syncUrl: false });
        } catch (error) {
            // Embedded catalog snapshot remains usable when live refresh fails.
        }
    }

    function isOfferStocked(offer) {
        if (!offer || offer.quote_only) {
            return false;
        }
        const stock = Math.max(0, Number(offer.stock) || 0);
        if (offer.currency_unavailable) {
            return stock > 0;
        }
        return isOfferPurchasable(offer);
    }

    function isOptionStocked(axisCode, axisValue, current) {
        const probe = Object.assign({}, current);
        probe[axisCode] = axisValue;
        return offers.some(function (offer) {
            return combinationMatches(offer.combination || {}, probe) && isOfferStocked(offer);
        });
    }

    function isOfferPurchasable(offer) {
        if (!offer || !offer.sellable || offer.quote_only) {
            return false;
        }
        return Math.max(0, Number(offer.stock) || 0) > 0;
    }

    function isOfferSellable(offer) {
        return isOfferPurchasable(offer);
    }

    function isOptionAvailable(axisCode, axisValue, current) {
        const probe = Object.assign({}, current);
        probe[axisCode] = axisValue;
        return offers.some(function (offer) {
            return combinationMatches(offer.combination || {}, probe);
        });
    }

    function isOptionPurchasable(axisCode, axisValue, current) {
        const probe = Object.assign({}, current);
        probe[axisCode] = axisValue;
        return offers.some(function (offer) {
            return combinationMatches(offer.combination || {}, probe) && isOfferPurchasable(offer);
        });
    }

    function isOptionQuoteOnly(axisCode, axisValue, current) {
        const probe = Object.assign({}, current);
        probe[axisCode] = axisValue;
        return offers.some(function (offer) {
            return combinationMatches(offer.combination || {}, probe) && !!offer.quote_only;
        });
    }

    function isOptionSellable(axisCode, axisValue, current) {
        return isOptionPurchasable(axisCode, axisValue, current);
    }

    function resolveSelectionToInStock(desired, pinnedAxes) {
        desired = normalizeSelection(desired);
        pinnedAxes = Array.isArray(pinnedAxes)
            ? pinnedAxes.map(function (code) {
                return String(code || '').trim();
            }).filter(Boolean)
            : [];

        let candidates = offers.filter(function (offer) {
            const combo = normalizeSelection(offer.combination || {});
            return pinnedAxes.every(function (code) {
                return !desired[code] || combo[code] === desired[code];
            });
        });
        if (candidates.length === 0) {
            return desired;
        }

        const purchasable = candidates.filter(isOfferPurchasable);
        const quoteOnly = candidates.filter(function (offer) { return !!offer.quote_only; });
        const pool = purchasable.length > 0
            ? purchasable
            : (quoteOnly.length > 0 ? quoteOnly : candidates);

        let best = pool[0];
        let bestScore = -1;
        pool.forEach(function (offer) {
            const combo = normalizeSelection(offer.combination || {});
            let score = isOfferPurchasable(offer) ? 1000 : (!!offer.quote_only ? 500 : 0);
            Object.keys(desired).forEach(function (code) {
                if (combo[code] === desired[code]) {
                    score += 10;
                }
            });
            if (score > bestScore) {
                bestScore = score;
                best = offer;
            }
        });

        return normalizeSelection(best.combination || desired);
    }

    const currencySymbolMap = JSON.parse(widgetScript.dataset.v3 || 'null') || {};
    function currencySymbol(code) {
        const normalized = String(code || '').toUpperCase().trim();
        if (normalized !== '' && currencySymbolMap[normalized]) {
            return String(currencySymbolMap[normalized]);
        }
        switch (normalized) {
            case 'CNY':
            case 'RMB':
            case 'JPY':
                return '¥';
            case 'USD':
                return '$';
            case 'EUR':
                return '€';
            case 'GBP':
                return '£';
            default:
                return normalized !== '' ? normalized : '¥';
        }
    }

    function applyDealToMinor(minor) {
        const type = String(root.getAttribute('data-deal-type') || buyboxDealType()).trim();
        const value = Number(root.getAttribute('data-deal-value') || buyboxDealValue() || 0);
        const catalog = Math.max(0, Number(minor) || 0);
        if (!type || type === 'none' || !(value > 0) || catalog <= 0) {
            return { minor: catalog, original: catalog, hasDeal: false };
        }
        let deal = catalog;
        if (type === 'percentage' || type === 'percent' || type === 'pct') {
            deal = Math.round(catalog * (1 - Math.min(100, value) / 100));
        } else if (type === 'fixed_amount' || type === 'fixed' || type === 'amount') {
            deal = Math.max(0, catalog - Math.round(value * 100));
        }
        return {
            minor: deal,
            original: catalog,
            hasDeal: deal < catalog,
        };
    }

    /**
     * Prefer server Assembler fields. Never treat unit_price_minor (often already
     * deal-baked by catalog projection) as catalog input — that stacks discounts.
     */
    function priceFromOffer(offer) {
        const unit = Math.max(0, Number(offer && offer.unit_price_minor) || 0);
        const catalog = Math.max(0, Number(offer && offer.catalog_price_minor) || 0);
        const compare = Math.max(0, Number(offer && offer.compare_at_minor) || catalog || unit);
        if (catalog > 0 && unit > 0) {
            const original = compare > unit ? compare : catalog;
            return {
                minor: unit,
                original: original,
                hasDeal: unit < original,
            };
        }
        if (catalog > 0) {
            return applyDealToMinor(catalog);
        }
        // Legacy catalog rows without catalog_price_minor: unit is authoritative.
        return { minor: unit, original: unit, hasDeal: false };
    }

    function buyboxDealType() {
        const box = root.querySelector('.product-native-detail__buybox');
        return box ? String(box.getAttribute('data-deal-type') || '') : '';
    }

    function buyboxDealValue() {
        const box = root.querySelector('.product-native-detail__buybox');
        return box ? String(box.getAttribute('data-deal-value') || '') : '';
    }

    function renderPrice(offerOrMinor, currency) {
        if (offerOrMinor && typeof offerOrMinor === 'object' && offerOrMinor.currency_unavailable) {
            return;
        }
        const priced = (offerOrMinor && typeof offerOrMinor === 'object')
            ? priceFromOffer(offerOrMinor)
            : applyDealToMinor(offerOrMinor);
        const displayCurrency = (offerOrMinor && typeof offerOrMinor === 'object')
            ? (currency || offerOrMinor.currency)
            : currency;
        const whole = Math.floor(Math.max(0, Number(priced.minor) || 0) / 100);
        const fraction = String(Math.max(0, Number(priced.minor) || 0) % 100).padStart(2, '0');
        const symbol = currencySymbol(displayCurrency);
        // HelpPay / Visitor pixel：minor 权威 + major/currency 镜像（规格切换须同步）。
        const offerMinor = Math.max(0, Number(priced.minor) || 0);
        const offerMajor = (offerMinor / 100).toFixed(2);
        root.setAttribute('data-offer-price-minor', String(offerMinor));
        root.setAttribute('data-price', offerMajor);
        root.setAttribute('data-pixel-value', offerMajor);
        if (displayCurrency) {
            const code = String(displayCurrency).trim().toUpperCase();
            if (code) {
                root.setAttribute('data-pixel-currency', code);
                root.setAttribute('data-currency', code);
            }
        }
        if (offerOrMinor && typeof offerOrMinor === 'object') {
            const catalog = Math.max(0, Number(offerOrMinor.catalog_price_minor) || 0);
            if (catalog > 0) {
                root.setAttribute('data-catalog-price-minor', String(catalog));
            }
        }
        root.querySelectorAll('.product-native-detail__price-symbol').forEach(function (node) {
            if (node.closest('.product-native-detail__price-original')) {
                return;
            }
            node.textContent = symbol;
        });
        root.querySelectorAll('.product-native-detail__price-whole').forEach(function (node) {
            node.textContent = String(whole);
        });
        root.querySelectorAll('.product-native-detail__price-fraction').forEach(function (node) {
            node.textContent = '.' + fraction;
        });
        root.querySelectorAll('.product-native-detail__price-original').forEach(function (node) {
            node.hidden = !priced.hasDeal;
            if (!priced.hasDeal) {
                return;
            }
            const originalWhole = Math.floor(Math.max(0, Number(priced.original) || 0) / 100);
            const originalFraction = String(Math.max(0, Number(priced.original) || 0) % 100).padStart(2, '0');
            node.querySelectorAll('.product-native-detail__price-symbol').forEach(function (symbolNode) {
                symbolNode.textContent = symbol;
            });
            node.querySelectorAll('.product-native-detail__price-original-whole').forEach(function (wholeNode) {
                wholeNode.textContent = String(originalWhole);
            });
            node.querySelectorAll('.product-native-detail__price-original-fraction').forEach(function (fractionNode) {
                fractionNode.textContent = '.' + originalFraction;
            });
        });
        root.querySelectorAll('.product-native-detail__price-campaign').forEach(function (node) {
            node.hidden = !priced.hasDeal;
        });
    }

    function syncPromotionThemeToPurchaseButtons(themeId) {
        const id = String(Math.max(0, Number(themeId) || 0));
        root.setAttribute('data-promotion-theme-id', id);
        root.querySelectorAll('[data-testid="product-add-to-cart"], [data-testid="product-buy-now"]').forEach(function (button) {
            button.dataset.promotionThemeId = id;
        });
    }

    function setActiveCampaign(option) {
        if (!option) {
            return;
        }
        const themeId = String(option.getAttribute('data-theme-id') || '');
        const type = String(option.getAttribute('data-deal-type') || '');
        const value = String(option.getAttribute('data-deal-value') || '');
        const label = String(option.textContent || '').trim();
        root.setAttribute('data-deal-type', type);
        root.setAttribute('data-deal-value', value);
        const buybox = root.querySelector('.product-native-detail__buybox');
        if (buybox) {
            buybox.setAttribute('data-deal-type', type);
            buybox.setAttribute('data-deal-value', value);
        }
        root.querySelectorAll('[data-campaign-label]').forEach(function (node) {
            node.textContent = label;
        });
        root.querySelectorAll('.product-native-detail__campaign-option').forEach(function (row) {
            const selected = row === option;
            row.classList.toggle('is-selected', selected);
            row.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
        syncPromotionThemeToPurchaseButtons(themeId);
        const catalogMinor = Math.max(0, Number(root.getAttribute('data-catalog-price-minor') || 0));
        renderPrice({
            unit_price_minor: applyDealToMinor(catalogMinor).minor,
            catalog_price_minor: catalogMinor,
            compare_at_minor: catalogMinor,
            currency: root.getAttribute('data-currency') || '',
        });
        // Re-apply using current offer fields so variant unit stays deal-aware.
        const currentOffer = findExactOffer(selection) || findBestOffer(selection);
        if (currentOffer) {
            const catalog = Math.max(0, Number(currentOffer.catalog_price_minor) || catalogMinor);
            const priced = applyDealToMinor(catalog);
            currentOffer.unit_price_minor = priced.minor;
            currentOffer.compare_at_minor = priced.original;
            currentOffer.has_deal = priced.hasDeal;
            currentOffer.campaign_label = label;
            renderPrice(currentOffer);
        }
    }

    function bindCampaignPicker() {
        const picker = root.querySelector('[data-testid="product-campaign-picker"]');
        if (!picker) {
            syncPromotionThemeToPurchaseButtons(root.getAttribute('data-promotion-theme-id') || '0');
            return;
        }
        const trigger = picker.querySelector('.product-native-detail__campaign-trigger');
        const menu = picker.querySelector('[data-testid="product-campaign-menu"]');
        if (!trigger || !menu) {
            return;
        }
        function closeMenu() {
            menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        }
        function openMenu() {
            menu.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
        }
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            if (menu.hidden) {
                openMenu();
            } else {
                closeMenu();
            }
        });
        menu.querySelectorAll('.product-native-detail__campaign-option').forEach(function (option) {
            option.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                setActiveCampaign(option);
                closeMenu();
            });
        });
        document.addEventListener('click', function (event) {
            if (!picker.contains(event.target)) {
                closeMenu();
            }
        });
        syncPromotionThemeToPurchaseButtons(root.getAttribute('data-promotion-theme-id') || '0');
    }

    bindCampaignPicker();

    function renderStock(offer) {
        const stockNode = root.querySelector('.product-native-detail__stock');
        if (!stockNode || !offer) {
            return;
        }
        const stock = Math.max(0, Number(offer.stock) || 0);
        const quoteOnly = !!offer.quote_only;
        stockNode.classList.remove('product-native-detail__stock--in-stock', 'product-native-detail__stock--out-stock');
        if (quoteOnly) {
            stockNode.textContent = JSON.parse(widgetScript.dataset.v4 || 'null');
            stockNode.classList.add('product-native-detail__stock--out-stock');
            return;
        }
        if (isOfferStocked(offer)) {
            stockNode.textContent = stock <= 10
                // __() defaults args='' and would strip %{1} to empty; keep sentinel for JS replace.
                ? (JSON.parse(widgetScript.dataset.v5 || 'null')).replace('%{1}', String(stock))
                : JSON.parse(widgetScript.dataset.v6 || 'null');
            stockNode.classList.add('product-native-detail__stock--in-stock');
            return;
        }
        stockNode.textContent = JSON.parse(widgetScript.dataset.v7 || 'null');
        stockNode.classList.add('product-native-detail__stock--out-stock');
    }

    function resolveOptionGallery(option, selected) {
        const color = String(selected.color || '').trim();
        const byColor = option.gallery_by_color;
        if (color !== '' && byColor && typeof byColor === 'object' && Array.isArray(byColor[color]) && byColor[color].length) {
            return byColor[color].slice();
        }
        return Array.isArray(option.gallery_images) ? option.gallery_images.slice() : [];
    }

    function resolveOptionPreviewSrc(option, selected) {
        const gallery = resolveOptionGallery(option, selected || {});
        if (gallery.length > 0) {
            return String(gallery[0] || '').trim();
        }
        return String(option.swatch_image || '').trim();
    }

    function resolveSelectionPreview(current) {
        const gallery = resolveSelectionGallery(current);
        if (gallery.length > 0) {
            return gallery[0];
        }
        const selected = normalizeSelection(current);
        for (let index = 0; index < axes.length; index++) {
            const axis = axes[index];
            const axisCode = String(axis.code || '').trim();
            const axisValue = selected[axisCode];
            if (!axisCode || !axisValue) {
                continue;
            }
            const options = Array.isArray(axis.options) ? axis.options : [];
            for (let optionIndex = 0; optionIndex < options.length; optionIndex++) {
                const option = options[optionIndex];
                if (String(option.value || '').trim() !== axisValue) {
                    continue;
                }
                const preview = resolveOptionPreviewSrc(option, selected);
                if (preview !== '') {
                    return preview;
                }
            }
        }
        return '';
    }

    function resolveSelectionGallery(current) {
        const selected = normalizeSelection(current);
        const exactOffer = findExactOffer(selected);
        if (exactOffer) {
            const exactImages = [];
            const seenExactImages = {};
            function pushExactImage(src) {
                src = String(src || '').trim();
                if (src === '' || seenExactImages[src]) {
                    return;
                }
                seenExactImages[src] = true;
                exactImages.push(src);
            }
            pushExactImage(exactOffer.image || '');
            (Array.isArray(exactOffer.images) ? exactOffer.images : []).forEach(pushExactImage);
            if (exactImages.length > 0) {
                return exactImages;
            }
        }
        const styleType = String(selected.style_type || '').trim();
        let images = [];
        axes.forEach(function (axis) {
            const axisCode = String(axis.code || '').trim();
            const axisValue = selected[axisCode];
            if (!axisCode || !axisValue) {
                return;
            }
            const options = Array.isArray(axis.options) ? axis.options : [];
            options.forEach(function (option) {
                if (String(option.value || '').trim() !== axisValue) {
                    return;
                }
                const gallery = resolveOptionGallery(option, selected);
                const swatch = String(option.swatch_image || '').trim();
                const optionImages = gallery.length > 0
                    ? gallery.slice()
                    : (swatch !== '' ? [swatch] : []);
                if (optionImages.length === 0) {
                    return;
                }
                if (images.length === 0) {
                    images = optionImages.slice();
                    return;
                }
                const intersection = images.filter(function (src) {
                    return optionImages.indexOf(src) >= 0;
                });
                if (intersection.length > 0) {
                    images = intersection;
                } else if (axisCode === 'color' && styleType !== '' && styleType !== 'set') {
                    return;
                } else {
                    images = optionImages.slice();
                }
            });
        });
        return images;
    }

    function collectSpecGalleryItems(selected) {
        const items = [];
        const seen = {};
        axes.forEach(function (axis) {
            const axisCode = String(axis.code || '').trim();
            if (!axisCode) {
                return;
            }
            const options = Array.isArray(axis.options) ? axis.options : [];
            options.forEach(function (option) {
                const value = String(option.value || '').trim();
                if (value === '') {
                    return;
                }
                const src = resolveOptionPreviewSrc(option, selected);
                if (src === '') {
                    return;
                }
                const key = axisCode + '|' + value;
                if (seen[key]) {
                    return;
                }
                seen[key] = true;
                items.push({
                    type: 'image',
                    src: src,
                    poster: src,
                    provider: '',
                    mime_type: '',
                    kind: 'spec',
                    axis: axisCode,
                    value: value,
                    label: String(option.label || value).trim() || value,
                });
            });
        });
        return items;
    }

    function collectProductGalleryImages(offer) {
        const images = [];
        const seen = {};
        function push(src) {
            src = String(src || '').trim();
            if (src === '' || seen[src]) {
                return;
            }
            seen[src] = true;
            images.push(src);
        }
        pageProductImages.forEach(push);
        (Array.isArray(baseImages) ? baseImages : []).forEach(push);
        if (offer) {
            (Array.isArray(offer.images) ? offer.images : []).forEach(push);
            push(offer.image || '');
        }
        return images;
    }

    function buildGalleryItems(offer) {
        const selected = normalizeSelection(selection);
        const galleryItems = [];
        const productImages = collectProductGalleryImages(offer);

        // Main product gallery first — never promote these to 「规格」.
        productImages.forEach(function (src) {
            galleryItems.push({
                type: 'image',
                src: src,
                poster: src,
                provider: '',
                mime_type: '',
                kind: 'product',
            });
        });

        // Spec option images are appended with 「规格」 badge for reverse-select.
        const usedSpecKeys = {};
        collectSpecGalleryItems(selected).forEach(function (item) {
            const key = item.axis + '|' + item.value;
            if (usedSpecKeys[key]) {
                return;
            }
            usedSpecKeys[key] = true;
            galleryItems.push(item);
        });

        (Array.isArray(productVideos) ? productVideos : []).forEach(function (video) {
            if (!video || !video.src) {
                return;
            }
            galleryItems.push(Object.assign({ kind: 'video' }, video));
        });

        return galleryItems;
    }

    function activateGalleryItem(galleryItems, thumbs, activeIndex) {
        const active = galleryItems[activeIndex] || galleryItems[0];
        if (!active) {
            return;
        }
        if (active.type === 'video') {
            renderGalleryVideo(
                'video',
                active.src,
                active.poster || '',
                active.provider || '',
                active.mime_type || ''
            );
        } else if (primaryImage) {
            primaryImage.src = active.src;
            primaryImage.hidden = false;
            renderGalleryVideo('image', '', '', '', '');
        }
        if (typeof syncImageZoomSource === 'function') {
            syncImageZoomSource();
        }
        if (!thumbs) {
            return;
        }
        const buttons = thumbs.querySelectorAll('[data-gallery-src]');
        buttons.forEach(function (item, index) {
            const isActive = index === activeIndex;
            item.classList.toggle('is-active', isActive);
            item.setAttribute('aria-selected', isActive ? 'true' : 'false');
            if (isActive && typeof item.scrollIntoView === 'function') {
                item.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        });
    }

    function resolveGalleryActiveIndex(galleryItems, renderOptions) {
        renderOptions = renderOptions || {};
        const focusAxis = String(renderOptions.galleryFocusAxis || '').trim();
        const focusValue = String(renderOptions.galleryFocusValue || '').trim();
        const focusSrc = String(renderOptions.galleryFocusSrc || '').trim();
        if (focusAxis !== '' && focusValue !== '') {
            const byAxis = galleryItems.findIndex(function (item) {
                return item.kind === 'spec'
                    && String(item.axis || '') === focusAxis
                    && String(item.value || '') === focusValue;
            });
            if (byAxis >= 0) {
                return byAxis;
            }
        }
        if (focusSrc !== '') {
            // Prefer matching spec thumb when focusing a selection-driven src.
            const bySpecSrc = galleryItems.findIndex(function (item) {
                return item.kind === 'spec' && String(item.src || '') === focusSrc;
            });
            if (bySpecSrc >= 0) {
                return bySpecSrc;
            }
            const bySrc = galleryItems.findIndex(function (item) {
                return String(item.src || '') === focusSrc;
            });
            if (bySrc >= 0) {
                return bySrc;
            }
        }
        // Option click / URL selection: highlight the selected spec thumb.
        if (focusAxis !== '' || focusValue !== '' || focusSrc !== '') {
            const selected = normalizeSelection(selection);
            const selectedSpec = galleryItems.findIndex(function (item) {
                return item.kind === 'spec' && selected[item.axis] === item.value;
            });
            if (selectedSpec >= 0) {
                return selectedSpec;
            }
        }
        const preview = resolveSelectionPreview(selection);
        if (preview !== '') {
            const bySpecPreview = galleryItems.findIndex(function (item) {
                return item.kind === 'spec' && String(item.src || '') === preview;
            });
            if (bySpecPreview >= 0) {
                return bySpecPreview;
            }
        }
        // Default: first main product image (not a 规格 thumb).
        const firstProduct = galleryItems.findIndex(function (item) {
            return item.kind === 'product';
        });
        if (firstProduct >= 0) {
            return firstProduct;
        }
        return 0;
    }

    function renderGallery(offer, renderOptions) {
        if (!primaryImage || !offer) {
            return;
        }
        renderOptions = renderOptions || {};
        const galleryItems = buildGalleryItems(offer);
        if (galleryItems.length === 0) {
            return;
        }

        const gallery = root.querySelector('.product-native-detail__gallery');
        if (!gallery) {
            return;
        }

        const specBadgeLabel = JSON.parse(widgetScript.dataset.v8 || 'null');
        let thumbs = gallery.querySelector('.product-native-detail__thumbs');
        const activeIndex = resolveGalleryActiveIndex(galleryItems, renderOptions);

        if (galleryItems.length <= 1) {
            if (thumbs) {
                thumbs.remove();
            }
            activateGalleryItem(galleryItems, null, 0);
            return;
        }

        if (!thumbs) {
            thumbs = document.createElement('div');
            thumbs.className = 'product-native-detail__thumbs';
            thumbs.setAttribute('role', 'tablist');
            thumbs.setAttribute('aria-label', JSON.parse(widgetScript.dataset.v9 || 'null'));
            gallery.insertBefore(thumbs, gallery.firstChild);
        }

        thumbs.innerHTML = '';
        galleryItems.forEach(function (item, index) {
            const button = document.createElement('button');
            button.type = 'button';
            button.role = 'tab';
            const isSpec = item.kind === 'spec';
            button.className = 'product-native-detail__thumb'
                + (index === activeIndex ? ' is-active' : '')
                + (item.type === 'video' ? ' is-video' : '')
                + (isSpec ? ' is-spec' : '');
            button.dataset.galleryType = item.type || 'image';
            button.dataset.gallerySrc = item.src || '';
            button.dataset.galleryPoster = item.poster || '';
            button.dataset.galleryProvider = item.provider || '';
            button.dataset.galleryMime = item.mime_type || '';
            if (isSpec) {
                button.dataset.galleryAxis = item.axis || '';
                button.dataset.galleryValue = item.value || '';
                button.dataset.galleryKind = 'spec';
            }
            button.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');
            const ariaLabel = isSpec
                ? (specBadgeLabel + ': ' + (item.label || item.value || ''))
                : (item.type === 'video'
                    ? JSON.parse(widgetScript.dataset.v10 || 'null')
                    : JSON.parse(widgetScript.dataset.v11 || 'null'));
            button.setAttribute('aria-label', ariaLabel);
            if (isSpec) {
                const badge = document.createElement('span');
                badge.className = 'product-native-detail__thumb-spec-badge';
                badge.textContent = specBadgeLabel;
                badge.setAttribute('aria-hidden', 'true');
                button.appendChild(badge);
            }
            const thumbSrc = item.poster || (item.type === 'image' ? item.src : '');
            if (thumbSrc) {
                const img = document.createElement('img');
                img.src = thumbSrc;
                img.alt = '';
                img.width = 64;
                img.height = 64;
                img.setAttribute('width', '64');
                img.setAttribute('height', '64');
                button.appendChild(img);
            } else {
                const label = document.createElement('span');
                label.className = 'product-native-detail__thumb-video-label';
                label.setAttribute('aria-hidden', 'true');
                label.textContent = '▶';
                button.appendChild(label);
            }
            if (item.type === 'video') {
                const play = document.createElement('span');
                play.className = 'product-native-detail__thumb-play';
                play.setAttribute('aria-hidden', 'true');
                play.textContent = '▶';
                button.appendChild(play);
            }
            bindGalleryThumb(button);
            thumbs.appendChild(button);
        });
        activateGalleryItem(galleryItems, thumbs, activeIndex);
    }

    function renderPurchaseButtons(offer) {
        const exactOffer = offer && findExactOffer(selection);
        if (exactOffer && exactOffer.currency_unavailable) {
            return;
        }
        const quoteOnly = !!(exactOffer && exactOffer.quote_only);
        const purchasable = !!(exactOffer && isOfferPurchasable(exactOffer) && exactOffer.global_offer_uuid);
        const deliveryNode = root.querySelector('.product-native-detail__delivery');
        if (deliveryNode) {
            deliveryNode.textContent = quoteOnly
                ? JSON.parse(widgetScript.dataset.v12 || 'null')
                : (purchasable
                    ? JSON.parse(widgetScript.dataset.v13 || 'null')
                    : JSON.parse(widgetScript.dataset.v14 || 'null'));
        }
        const qtyRow = root.querySelector('.product-native-detail__qty-row');
        if (qtyRow) {
            qtyRow.hidden = quoteOnly || !purchasable;
        }
        if (qtySelect && exactOffer && purchasable) {
            const stock = Math.max(0, Number(exactOffer.stock) || 0);
            const maxQty = Math.max(1, Math.min(30, stock > 0 ? stock : 1));
            const current = Math.max(1, Number(qtySelect.value) || 1);
            qtySelect.setAttribute('data-qty-max', String(maxQty));
            if (qtySelect.options.length !== maxQty) {
                qtySelect.innerHTML = '';
                for (let quantity = 1; quantity <= maxQty; quantity += 1) {
                    const option = document.createElement('option');
                    option.value = String(quantity);
                    option.textContent = String(quantity);
                    qtySelect.appendChild(option);
                }
            }
            qtySelect.value = String(Math.min(current, maxQty));
        }
        const addLabel = JSON.parse(widgetScript.dataset.v15 || 'null');
        const unavailableLabel = JSON.parse(widgetScript.dataset.v16 || 'null');
        root.querySelectorAll('[data-action="add"], [data-action="buy-now"]').forEach(function (button) {
            if (exactOffer) {
                button.dataset.globalOfferUuid = exactOffer.global_offer_uuid || '';
                button.dataset.productId = String(exactOffer.product_id || '');
                button.dataset.providerCode = exactOffer.provider_code || 'product';
            }
            button.hidden = quoteOnly;
            button.disabled = quoteOnly || !purchasable;
            if (button.getAttribute('data-action') === 'add') {
                button.textContent = purchasable ? addLabel : unavailableLabel;
            }
        });
        // Keep PDP PayPal/express CTAs in sync with add/buy — SSR may start disabled
        // before a purchasable variant is selected; without this the yellow button
        // stays disabled (or looks "gone") while cart/checkout already work.
        const expressDisabled = quoteOnly || !purchasable;
        root.querySelectorAll(
            '[data-express-pay], [data-product-express-pay], [data-testid="product-express-paypal"]'
        ).forEach(function (button) {
            if (exactOffer) {
                button.dataset.globalOfferUuid = exactOffer.global_offer_uuid || '';
                button.dataset.productId = String(exactOffer.product_id || '');
            }
            button.hidden = quoteOnly;
            button.disabled = expressDisabled;
        });
        root.querySelectorAll('[data-payment-express][data-product-express]').forEach(function (section) {
            if (exactOffer) {
                section.dataset.globalOfferUuid = exactOffer.global_offer_uuid || '';
                section.dataset.productId = String(exactOffer.product_id || '');
            }
        });
        const quoteButton = root.querySelector('[data-action="open-product-quote"]');
        if (quoteButton) {
            quoteButton.hidden = !quoteOnly;
            quoteButton.disabled = !quoteOnly || !exactOffer;
            if (exactOffer) {
                syncQuoteButtonContext(exactOffer);
            }
        }
        const skuElement = root.querySelector('[data-product-sku]');
        if (skuElement && exactOffer) {
            skuElement.textContent = 'SKU: ' + (exactOffer.sku || '');
        }
        syncPurchaseQty();
    }

    function selectionLabelFor(offer) {
        const combo = normalizeSelection((offer && offer.combination) || selection || {});
        const parts = [];
        axes.forEach(function (axis) {
            const code = String(axis.code || '').trim();
            const value = String(combo[code] || '').trim();
            if (!code || !value) {
                return;
            }
            const option = (axis.options || []).find(function (row) {
                return String(row.value || '').trim() === value;
            });
            parts.push(String((option && (option.label || option.value)) || value));
        });
        return parts.join(' · ');
    }

    function syncQuoteButtonContext(offer) {
        const quoteButton = root.querySelector('[data-action="open-product-quote"]');
        if (!quoteButton || !offer) {
            return;
        }
        const priced = priceFromOffer(offer);
        quoteButton.dataset.productId = String(offer.product_id || '');
        quoteButton.dataset.sku = String(offer.sku || '');
        quoteButton.dataset.globalOfferUuid = String(offer.global_offer_uuid || '');
        quoteButton.dataset.currency = String(offer.currency || 'CNY');
        quoteButton.dataset.referencePriceMinor = String(priced.minor || 0);
        quoteButton.dataset.campaignLabel = String(
            (root.querySelector('[data-testid="product-price-campaign"]') || {}).textContent
            || quoteButton.dataset.campaignLabel
            || ''
        );
        quoteButton.dataset.productUrl = window.location.pathname + window.location.search;
        quoteButton.dataset.selectionLabel = selectionLabelFor(offer);
        quoteButton.dataset.selectionJson = JSON.stringify(normalizeSelection(offer.combination || selection || {}));
    }

    function renderSelectionUi() {
        const outOfStockLabel = JSON.parse(widgetScript.dataset.v17 || 'null');
        root.querySelectorAll('[data-variant-option]').forEach(function (button) {
            const axisCode = button.dataset.variantAxis || '';
            const axisValue = button.dataset.variantValue || '';
            const selected = selection[axisCode] === axisValue;
            const exists = isOptionAvailable(axisCode, axisValue, selection);
            const stocked = isOptionStocked(axisCode, axisValue, selection);
            const quoteOnlyOption = isOptionQuoteOnly(axisCode, axisValue, selection);
            button.classList.toggle('is-selected', selected);
            button.classList.toggle('is-disabled', !exists);
            button.classList.toggle('is-out-of-stock', exists && !stocked && !quoteOnlyOption);
            button.setAttribute('aria-current', selected ? 'true' : 'false');
            button.disabled = !exists;
            if (exists && !stocked && !quoteOnlyOption) {
                const baseLabel = button.dataset.variantLabel || axisValue;
                button.setAttribute('title', outOfStockLabel);
                button.setAttribute('aria-label', baseLabel + ' · ' + outOfStockLabel);
            } else {
                button.removeAttribute('title');
                const baseLabel = button.dataset.variantLabel || '';
                if (baseLabel !== '') {
                    button.setAttribute('aria-label', baseLabel);
                }
            }
        });
    }

    function syncUrl(current) {
        const params = new URLSearchParams();
        Object.keys(normalizeSelection(current)).forEach(function (axisCode) {
            params.set(axisCode, publicCodeFor(axisCode, current[axisCode]));
        });
        const existing = new URLSearchParams(window.location.search || '');
        if (existing.get('quote') === '1') {
            params.set('quote', '1');
        }
        const hash = window.location.hash || '';
        const nextUrl = basePath + (params.toString() ? ('?' + params.toString()) : '') + hash;
        if (window.location.pathname + window.location.search + window.location.hash !== nextUrl) {
            window.history.replaceState(null, '', nextUrl);
        }
    }

    function publicCodeFor(axisCode, value) {
        const token = String(value || '').trim();
        if (token === '') {
            return token;
        }
        const axis = axes.find(function (row) {
            return String(row.code || '').trim() === axisCode;
        });
        if (!axis || !Array.isArray(axis.options)) {
            return token;
        }
        const option = axis.options.find(function (row) {
            return String(row.value || '').trim() === token;
        });
        const code = option ? String(option.code || '').trim() : '';
        return code !== '' ? code : token;
    }

    function canonicalValueFor(axisCode, token) {
        const value = String(token || '').trim();
        if (value === '') {
            return value;
        }
        const axis = axes.find(function (row) {
            return String(row.code || '').trim() === axisCode;
        });
        if (!axis || !Array.isArray(axis.options)) {
            return value;
        }
        const option = axis.options.find(function (row) {
            return String(row.value || '').trim() === value
                || String(row.code || '').trim() === value;
        });
        return option ? String(option.value || value).trim() : value;
    }

    function applySelection(nextSelection, options) {
        options = options || {};
        const pinnedAxes = Array.isArray(options.pinnedAxes) ? options.pinnedAxes : [];
        selection = resolveSelectionToInStock(nextSelection, pinnedAxes);
        renderSelectionUi();
        const offer = findBestOffer(selection);
        if (offer) {
            renderGallery(offer, {
                galleryFocusAxis: options.galleryFocusAxis || '',
                galleryFocusValue: options.galleryFocusValue || '',
                galleryFocusSrc: options.galleryFocusSrc || '',
            });
            renderPrice(offer);
            renderStock(offer);
        }
        renderPurchaseButtons(offer);
        // Listing purchase panel must not rewrite the host page URL.
        if (!isQuickAdd && (!options || options.syncUrl !== false)) {
            syncUrl(selection);
        }
    }

    onGalleryThumbActivate = function (thumb) {
        const axisCode = thumb.dataset.galleryAxis || '';
        const axisValue = thumb.dataset.galleryValue || '';
        if (axisCode === '' || axisValue === '') {
            return false;
        }
        if (selection[axisCode] === axisValue) {
            return false;
        }
        if (!isOptionAvailable(axisCode, axisValue, selection)) {
            return false;
        }
        const nextSelection = Object.assign({}, selection);
        nextSelection[axisCode] = axisValue;
        applySelection(nextSelection, {
            pinnedAxes: [axisCode],
            galleryFocusAxis: axisCode,
            galleryFocusValue: axisValue,
            galleryFocusSrc: thumb.dataset.gallerySrc || '',
        });
        scheduleLiveAvailabilityRefresh();
        return true;
    };

    root.querySelectorAll('[data-variant-option]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (button.disabled) {
                return;
            }
            const axisCode = button.dataset.variantAxis || '';
            const axisValue = button.dataset.variantValue || '';
            if (axisCode === '' || axisValue === '') {
                return;
            }
            const nextSelection = Object.assign({}, selection);
            if (nextSelection[axisCode] === axisValue) {
                return;
            }
            nextSelection[axisCode] = axisValue;
            applySelection(nextSelection, {
                pinnedAxes: [axisCode],
                galleryFocusAxis: axisCode,
                galleryFocusValue: axisValue,
            });
            scheduleLiveAvailabilityRefresh();
        });
    });

    const urlParams = new URLSearchParams(window.location.search);
    const urlSelection = {};
    axes.forEach(function (axis) {
        const axisCode = String(axis.code || '').trim();
        const axisValue = canonicalValueFor(
            axisCode,
            String(urlParams.get(axisCode) || '').trim()
        );
        if (axisCode !== '' && axisValue !== '') {
            urlSelection[axisCode] = axisValue;
        }
    });
    if (Object.keys(urlSelection).length > 0) {
        applySelection(Object.assign({}, selection, urlSelection), {
            pinnedAxes: Object.keys(urlSelection),
            syncUrl: false,
        });
    } else {
        applySelection(selection, { pinnedAxes: [] });
    }
    refreshLiveAvailability();
})();
});
