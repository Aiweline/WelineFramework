(function () {
    'use strict';

    var COMPARE_MAX_ITEMS = 8;
    var booted = false;

  function texts() {
        return {
            wishlistAdded: '已加入收藏',
            wishlistFailed: '加入收藏失败，请稍后重试',
            compareAdded: '已加入对比',
            compareFull: '对比栏已满',
            compareFailed: '加入对比失败',
            quickViewFailed: '无法加载商品预览',
            loading: '加载中…'
        };
    }

    function toast(message, tone) {
        var mappedTone = tone === 'error' ? 'danger' : (tone || 'info');
        if (window.Weline && window.Weline.UI && window.Weline.UI.toast && typeof window.Weline.UI.toast.show === 'function') {
            window.Weline.UI.toast.show(message, { tone: mappedTone });
        }
    }

    function showCompareAddedNotice(message, count, max) {
        var notice = window.Weline && window.Weline.ShopperNotice;
        if (notice && typeof notice.showCompareAdded === 'function') {
            notice.showCompareAdded(message, {
                count: count,
                max: max || COMPARE_MAX_ITEMS,
            });
            return;
        }
        toast(message, 'success');
    }

    function normalizePayload(payload) {
        if (payload && typeof payload === 'object' && payload.data && typeof payload.data === 'object') {
            if ('success' in payload.data || 'message' in payload.data || 'product' in payload.data) {
                return payload.data;
            }
        }
        return payload && typeof payload === 'object' ? payload : {};
    }

    async function apiResource(name) {
        if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
            return window.Weline.Api.resource(name);
        }
        if (window.Weline && typeof window.Weline.use === 'function') {
            await window.Weline.use('api');
        }
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            throw new Error('Weline.Api.resource unavailable');
        }
        return window.Weline.Api.resource(name);
    }

    function productIdFromButton(button) {
        var card = button.closest('.product-card, article[data-product-id], .product-storefront__card, .amz-card, .cross-product, .wpc-card, .wpr-card, .wpf-item');
        var fromButton = Number(button.getAttribute('data-product-id') || 0);
        if (fromButton > 0) {
            return fromButton;
        }
        if (card) {
            return Number(card.getAttribute('data-product-id') || card.querySelector('[data-product-id]')?.getAttribute('data-product-id') || 0);
        }
        return 0;
    }

    function cardFallback(card) {
        if (!card) {
            return {};
        }
        var nameNode = card.querySelector('.product-name, .product-title, h3');
        var priceNode = card.querySelector('.price-current, .product-price .price-current');
        var imageNode = card.querySelector('.product-image, img');
        var linkNode = card.querySelector('.product-name a, .product-image-link, a[href*="/product"]');
        return {
            name: nameNode ? nameNode.textContent.trim() : '',
            formatted_price: priceNode ? priceNode.textContent.trim() : '',
            image: imageNode ? (imageNode.getAttribute('src') || '') : '',
            url: linkNode ? (linkNode.getAttribute('href') || '') : ''
        };
    }

    function updateWishlistCount(count) {
        if (count === undefined || count === null) {
            return;
        }
        var n = Number(count) || 0;
        var label = n > 99 ? '99+' : String(n);
        // Only badge nodes (.wishlist-count). Never [data-wishlist-count] alone —
        // header root also used that attr and textContent would wipe the heart icon.
        document.querySelectorAll('.wishlist-count').forEach(function (node) {
            node.textContent = label;
            node.hidden = n <= 0;
        });
        document.querySelectorAll('[data-w-wishlist-icon]').forEach(function (root) {
            root.setAttribute('data-wishlist-total', String(n));
            root.classList.toggle('is-empty', n <= 0);
        });
    }

    function compareBarRemoveLabel() {
        var bar = document.querySelector('[data-compare-bar]');
        return (bar && bar.getAttribute('data-compare-bar-remove-label')) || '移除';
    }

    function renderCompareBar(items, max) {
        var bar = document.querySelector('[data-compare-bar]');
        if (!bar) {
            return;
        }
        var count = Array.isArray(items) ? items.length : 0;
        var countNode = bar.querySelector('[data-compare-bar-count]');
        var thumbs = bar.querySelector('[data-compare-bar-thumbs]');
        if (countNode) {
            countNode.textContent = count + '/' + (max || COMPARE_MAX_ITEMS);
        }
        if (thumbs) {
            thumbs.innerHTML = '';
            (items || []).forEach(function (item) {
                var productId = Number(item && item.product_id || 0);
                if (productId <= 0) {
                    return;
                }
                var wrap = document.createElement('div');
                wrap.className = 'w-compare-bar__thumb-item';
                if (item.image) {
                    var img = document.createElement('img');
                    img.src = item.image;
                    img.alt = item.name || '';
                    img.className = 'w-compare-bar__thumb';
                    wrap.appendChild(img);
                } else {
                    var placeholder = document.createElement('span');
                    placeholder.className = 'w-compare-bar__thumb w-compare-bar__thumb--empty';
                    placeholder.textContent = String(item.name || '?').trim().slice(0, 1) || '?';
                    wrap.appendChild(placeholder);
                }
                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'w-compare-bar__thumb-remove';
                removeBtn.setAttribute('data-compare-remove', '');
                removeBtn.setAttribute('data-product-id', String(productId));
                removeBtn.setAttribute('aria-label', compareBarRemoveLabel());
                removeBtn.textContent = '×';
                wrap.appendChild(removeBtn);
                thumbs.appendChild(wrap);
            });
        }
        bar.hidden = count <= 0;
    }

    window.WelineCompareShopper = {
        renderCompareBar: renderCompareBar
    };

    function refreshCompareState() {
        return apiResource('compare').then(function (api) {
            return api.list({}, { silent: true });
        }).then(function (payload) {
            var data = normalizePayload(payload);
            if (data.success === false) {
                throw new Error(data.message || texts().compareFailed);
            }
            renderCompareBar(data.items || [], data.max || COMPARE_MAX_ITEMS);
            if (window.WelineComparePage && typeof window.WelineComparePage.renderFromPayload === 'function') {
                window.WelineComparePage.renderFromPayload(data);
            }
            return data;
        }).catch(function () {
            if (window.WelineComparePage && typeof window.WelineComparePage.handleLoadError === 'function') {
                window.WelineComparePage.handleLoadError();
            }
        });
    }

    window.WelineCompareShopper.refreshCompareState = refreshCompareState;

    function refreshCompareBar() {
        return refreshCompareState();
    }

    function openQuickViewDialog() {
        var dialog = document.getElementById('weline-product-quickview-dialog');
        if (!dialog) {
            return null;
        }
        if (window.Weline && window.Weline.UI && window.Weline.UI.dialog && typeof window.Weline.UI.dialog.open === 'function') {
            if (window.Weline.UI.dialog.open(dialog)) {
                return dialog;
            }
        }
        if (typeof dialog.showModal === 'function' && !dialog.open) {
            dialog.showModal();
        }
        return dialog;
    }

    function closeQuickViewDialog() {
        var dialog = document.getElementById('weline-product-quickview-dialog');
        if (!dialog) {
            return;
        }
        if (window.Weline && window.Weline.UI && window.Weline.UI.dialog && typeof window.Weline.UI.dialog.close === 'function') {
            if (window.Weline.UI.dialog.close(dialog, 'user')) {
                return;
            }
        }
        if (typeof dialog.close === 'function' && dialog.open) {
            dialog.close();
        }
    }

    function renderQuickView(product) {
        var body = document.querySelector('[data-quickview-body]');
        if (!body || !product) {
            return;
        }
        var rating = Number(product.rating || 0);
        var reviewCount = Number(product.review_count || 0);
        body.innerHTML = ''
            + '<div class="w-product-quickview__grid">'
            + '  <div class="w-product-quickview__media">'
            + (product.image ? '<img src="' + product.image + '" alt="" class="w-product-quickview__image">' : '')
            + '  </div>'
            + '  <div class="w-product-quickview__info">'
            + '    <h3 class="w-product-quickview__name">' + (product.name || '') + '</h3>'
            + '    <p class="w-product-quickview__rating">' + rating.toFixed(1) + ' (' + reviewCount + ')</p>'
            + '    <p class="w-product-quickview__price">' + (product.formatted_price || '') + '</p>'
            + '    <p class="w-product-quickview__desc">' + (product.short_description || product.sku || '') + '</p>'
            + '    <div class="w-product-quickview__actions">'
            + '      <button type="button" class="w-button" data-variant="primary" data-quickview-add-cart data-product-id="' + product.product_id + '">加入购物车</button>'
            + '      <a class="w-button" data-variant="outline" href="' + (product.url || '#') + '">查看完整详情</a>'
            + '      <button type="button" class="w-button" data-variant="ghost" data-quickview-wishlist data-product-id="' + product.product_id + '">收藏</button>'
            + '      <button type="button" class="w-button" data-variant="ghost" data-quickview-compare data-product-id="' + product.product_id + '">加入对比</button>'
            + '    </div>'
            + '  </div>'
            + '</div>';
    }

    function handleWishlist(button) {
        var productId = productIdFromButton(button);
        if (productId <= 0 || button.disabled) {
            return;
        }
        button.disabled = true;
        apiResource('wishlist').then(function (api) {
            return api.toggle({ product_id: productId });
        }).then(function (payload) {
            var data = normalizePayload(payload);
            if (data.success === false) {
                toast(data.message || texts().wishlistFailed, 'warning');
                return;
            }
            button.classList.toggle('is-active', !!data.active);
            button.setAttribute('aria-pressed', data.active ? 'true' : 'false');
            updateWishlistCount(data.wishlist_count);
            toast(data.message || texts().wishlistAdded, 'success');
        }).catch(function () {
            toast(texts().wishlistFailed, 'error');
        }).finally(function () {
            button.disabled = false;
        });
    }

    function handleCompare(button) {
        var productId = productIdFromButton(button);
        if (productId <= 0) {
            return;
        }
        apiResource('compare').then(function (api) {
            return api.add({ product_id: productId });
        }).then(function (payload) {
            var data = normalizePayload(payload);
            if (data.success === false) {
                toast(data.message || texts().compareFull, 'warning');
            } else {
                showCompareAddedNotice(
                    data.message || texts().compareAdded,
                    Number(data.compare_count) || 0,
                    Number(data.max) || COMPARE_MAX_ITEMS,
                );
            }
            return refreshCompareBar();
        }).catch(function () {
            toast(texts().compareFailed, 'error');
        });
    }

    function handleCompareRemove(button) {
        var productId = Number(button.getAttribute('data-product-id') || 0);
        if (productId <= 0 || button.disabled) {
            return;
        }
        button.disabled = true;
        apiResource('compare').then(function (api) {
            return api.remove({ product_id: productId });
        }).then(function () {
            if (window.WelineComparePage && typeof window.WelineComparePage.refresh === 'function' && window.WelineComparePage.isActive()) {
                return window.WelineComparePage.refresh();
            }
            return refreshCompareBar();
        }).catch(function () {
            button.disabled = false;
            toast(texts().compareFailed, 'error');
        });
    }

    function handleQuickView(button) {
        var productId = productIdFromButton(button);
        if (productId <= 0) {
            return;
        }
        var dialog = openQuickViewDialog();
        var body = document.querySelector('[data-quickview-body]');
        if (body) {
            body.innerHTML = '<div class="w-product-quickview__loading">' + texts().loading + '</div>';
        }
        var fallback = cardFallback(button.closest('.product-card'));
        apiResource('compare').then(function (api) {
            return api.quickView({ product_id: productId, fallback: fallback });
        }).then(function (payload) {
            var data = normalizePayload(payload);
            if (!data.success || !data.product) {
                toast(data.message || texts().quickViewFailed, 'error');
                closeQuickViewDialog();
                return;
            }
            renderQuickView(data.product);
        }).catch(function () {
            toast(texts().quickViewFailed, 'error');
            closeQuickViewDialog();
        });
    }

    function handleQuickViewAddToCart(button) {
        var productId = Number(button.getAttribute('data-product-id') || 0);
        if (productId <= 0) {
            return;
        }
        apiResource('cart').then(function (api) {
            return api.add({ product_id: productId, qty: 1 }, { silent: true });
        }).then(function (payload) {
            var data = normalizePayload(payload);
            if (data.success !== false) {
                toast('已加入购物车', 'success');
            }
        }).catch(function () {});
    }

    function bindGlobal() {
        if (booted) {
            return;
        }
        booted = true;

        document.addEventListener('click', function (event) {
            var target = event.target;
            var wishlist = target && target.closest ? target.closest('.btn-wishlist') : null;
            if (wishlist) {
                event.preventDefault();
                event.stopPropagation();
                handleWishlist(wishlist);
                return;
            }
            var compare = target && target.closest ? target.closest('.btn-compare') : null;
            if (compare) {
                event.preventDefault();
                event.stopPropagation();
                handleCompare(compare);
                return;
            }
            var quickview = target && target.closest ? target.closest('.btn-quickview') : null;
            if (quickview) {
                event.preventDefault();
                event.stopPropagation();
                handleQuickView(quickview);
                return;
            }
            if (target && target.closest && target.closest('[data-quickview-close]')) {
                event.preventDefault();
                closeQuickViewDialog();
                return;
            }
            if (target && target.closest && target.closest('[data-quickview-add-cart]')) {
                event.preventDefault();
                handleQuickViewAddToCart(target.closest('[data-quickview-add-cart]'));
                return;
            }
            if (target && target.closest && target.closest('[data-quickview-wishlist]')) {
                event.preventDefault();
                handleWishlist(target.closest('[data-quickview-wishlist]'));
                return;
            }
            if (target && target.closest && target.closest('[data-quickview-compare]')) {
                event.preventDefault();
                handleCompare(target.closest('[data-quickview-compare]'));
                return;
            }
            if (target && target.closest && target.closest('[data-compare-remove]')) {
                event.preventDefault();
                event.stopPropagation();
                handleCompareRemove(target.closest('[data-compare-remove]'));
                return;
            }
            if (target && target.closest && target.closest('[data-compare-clear]')) {
                event.preventDefault();
                apiResource('compare').then(function (api) {
                    return api.clear();
                }).then(function () {
                    if (window.WelineComparePage && typeof window.WelineComparePage.refresh === 'function' && window.WelineComparePage.isActive()) {
                        return window.WelineComparePage.refresh();
                    }
                    return refreshCompareBar();
                });
            }
        });

        refreshCompareBar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindGlobal);
    } else {
        bindGlobal();
    }
})();
