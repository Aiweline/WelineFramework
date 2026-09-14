/**
 * PDP sticky purchase dock: show when primary purchase actions leave the viewport,
 * proxy clicks to live Cart/Checkout CTAs, sync selected specs + primary image,
 * and jump-scroll to the variant picker.
 */
(function () {
    'use strict';

    var ROOT_SEL = '[data-testid="storefront-product-detail"]';
    var DOCK_SEL = '[data-sticky-purchase="1"]';
    var ACTIONS_SEL = '.product-native-detail__actions';
    var ADD_SEL = '[data-testid="product-add-to-cart"]';
    var BUY_SEL = '[data-testid="product-buy-now"]';
    var AXES_SEL = '[data-testid="product-variant-axes"]';

    function primaryInActions(root, selector) {
        var actions = root.querySelector(ACTIONS_SEL);
        if (!actions) {
            return root.querySelector(selector);
        }
        return actions.querySelector(selector) || root.querySelector(selector);
    }

    function syncButton(proxy, primary) {
        if (!proxy) {
            return;
        }
        if (!primary || primary.closest('[data-purchase-failsafe]')) {
            proxy.hidden = true;
            proxy.setAttribute('aria-hidden', 'true');
            return;
        }
        proxy.hidden = false;
        proxy.removeAttribute('aria-hidden');
        proxy.disabled = !!primary.disabled;
        var label = (primary.getAttribute('data-default-label') || primary.textContent || '').trim();
        if (label) {
            proxy.textContent = label;
        }
        proxy.classList.toggle('is-loading', primary.classList.contains('is-loading'));
    }

    function syncPrice(root, dock) {
        var dest = dock.querySelector('[data-sticky-price]');
        if (!dest) {
            return;
        }
        var src = root.querySelector('.product-native-detail__buybox-price')
            || root.querySelector('.product-native-detail__price-block .product-native-detail__price');
        if (!src) {
            dest.hidden = true;
            return;
        }
        dest.hidden = false;
        dest.innerHTML = src.innerHTML;
    }

    function selectedVariantNodes(axes) {
        var nodes = axes.querySelectorAll(
            '[data-variant-option].is-selected, [data-variant-option][aria-current="true"]'
        );
        if (nodes.length) {
            return Array.prototype.slice.call(nodes);
        }
        return Array.prototype.slice.call(
            axes.querySelectorAll('.product-native-detail__variant-option[aria-current="true"]')
        );
    }

    function buildSpecChip(option) {
        var axis = option.closest('[data-variant-axis]') || option.closest('.product-native-detail__variant-axis');
        var axisLabelNode = axis
            ? axis.querySelector('.product-native-detail__variant-axis-label')
            : null;
        var axisLabel = axisLabelNode
            ? String(axisLabelNode.textContent || '').replace(/:\s*$/, '').trim()
            : '';
        var optionLabel = (
            option.getAttribute('data-variant-label')
            || option.getAttribute('title')
            || option.getAttribute('aria-label')
            || option.textContent
            || ''
        ).trim();
        var img = option.querySelector('.product-native-detail__variant-swatch-image');
        var color = option.querySelector('.product-native-detail__variant-swatch-color');

        var chip = document.createElement('span');
        chip.className = 'product-native-detail__sticky-purchase-chip';
        chip.setAttribute('data-sticky-spec-chip', '1');

        if (img && img.getAttribute('src')) {
            var thumb = document.createElement('img');
            thumb.className = 'product-native-detail__sticky-purchase-chip-img';
            thumb.src = img.currentSrc || img.src;
            thumb.alt = '';
            thumb.width = 28;
            thumb.height = 28;
            thumb.loading = 'lazy';
            chip.appendChild(thumb);
        } else if (color) {
            var swatch = document.createElement('span');
            swatch.className = 'product-native-detail__sticky-purchase-chip-swatch';
            swatch.setAttribute('aria-hidden', 'true');
            var tone = color.style.getPropertyValue('--variant-swatch') || color.style.backgroundColor || '';
            if (tone) {
                swatch.style.setProperty('--variant-swatch', tone);
            }
            chip.appendChild(swatch);
        }

        var text = document.createElement('span');
        text.className = 'product-native-detail__sticky-purchase-chip-text';
        text.textContent = axisLabel && optionLabel
            ? (axisLabel + ' ' + optionLabel)
            : (optionLabel || axisLabel);
        chip.appendChild(text);
        chip.title = text.textContent;
        chip.setAttribute('role', 'button');
        chip.tabIndex = 0;
        chip.setAttribute('data-sticky-jump-trigger', '1');
        return chip;
    }

    function syncSpecs(root, dock) {
        var host = dock.querySelector('[data-sticky-specs]');
        var jump = dock.querySelector('[data-sticky-jump-variants]');
        var axes = root.querySelector(AXES_SEL);
        if (!host) {
            return;
        }
        if (!axes) {
            host.hidden = true;
            host.innerHTML = '';
            if (jump) {
                jump.hidden = true;
            }
            return;
        }
        if (jump) {
            jump.hidden = false;
        }
        host.hidden = false;
        host.innerHTML = '';
        selectedVariantNodes(axes).forEach(function (option) {
            host.appendChild(buildSpecChip(option));
        });
        if (!host.childElementCount) {
            var empty = document.createElement('span');
            empty.className = 'product-native-detail__sticky-purchase-chip-empty';
            empty.textContent = jump
                ? (jump.getAttribute('data-empty-label') || '')
                : '';
            if (empty.textContent) {
                host.appendChild(empty);
            }
        }
    }

    function syncPrimaryImage(root, dock) {
        var media = dock.querySelector('[data-sticky-media]');
        var dest = dock.querySelector('[data-sticky-primary-image]');
        if (!media || !dest) {
            return;
        }
        var src = root.querySelector('.product-native-detail__primary-image');
        if (!src || src.hidden) {
            media.hidden = true;
            return;
        }
        var url = src.currentSrc || src.getAttribute('src') || '';
        if (!url) {
            media.hidden = true;
            return;
        }
        media.hidden = false;
        if (dest.getAttribute('src') !== url) {
            dest.setAttribute('src', url);
        }
        dest.alt = src.getAttribute('alt') || '';
    }

    function setDockVisible(dock, root, visible) {
        if (visible) {
            dock.hidden = false;
            dock.setAttribute('aria-hidden', 'false');
            root.classList.add('product-native-detail--sticky-purchase-active');
            document.documentElement.classList.add('has-product-sticky-purchase');
            syncAll(root, dock);
        } else {
            dock.hidden = true;
            dock.setAttribute('aria-hidden', 'true');
            root.classList.remove('product-native-detail--sticky-purchase-active');
            document.documentElement.classList.remove('has-product-sticky-purchase');
        }
    }

    function bindProxy(dock, root) {
        dock.querySelectorAll('[data-sticky-proxy]').forEach(function (proxy) {
            if (proxy.getAttribute('data-sticky-bound') === '1') {
                return;
            }
            proxy.setAttribute('data-sticky-bound', '1');
            proxy.addEventListener('click', function (event) {
                event.preventDefault();
                var kind = proxy.getAttribute('data-sticky-proxy');
                var primary = kind === 'buy-now'
                    ? primaryInActions(root, BUY_SEL)
                    : primaryInActions(root, ADD_SEL);
                if (!primary || primary.disabled || primary.getAttribute('aria-disabled') === 'true') {
                    return;
                }
                try {
                    primary.dispatchEvent(new MouseEvent('click', {
                        bubbles: true,
                        cancelable: true,
                        view: window
                    }));
                } catch (err) {
                    primary.click();
                }
            });
        });
    }

    function jumpToVariants(root) {
        var axes = root.querySelector(AXES_SEL);
        if (!axes) {
            return;
        }
        try {
            axes.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } catch (err) {
            axes.scrollIntoView(true);
        }
        var first = axes.querySelector('[data-variant-option], .product-native-detail__variant-option');
        if (first && typeof first.focus === 'function') {
            try {
                first.focus({ preventScroll: true });
            } catch (focusErr) {
                first.focus();
            }
        }
    }

    function bindJump(dock, root) {
        if (dock.getAttribute('data-sticky-jump-bound') === '1') {
            return;
        }
        dock.setAttribute('data-sticky-jump-bound', '1');
        dock.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || !target.closest) {
                return;
            }
            if (target.closest('[data-sticky-proxy]')) {
                return;
            }
            var trigger = target.closest('[data-sticky-jump-variants], [data-sticky-jump-trigger], [data-sticky-specs]');
            if (!trigger) {
                return;
            }
            event.preventDefault();
            jumpToVariants(root);
        });
        dock.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }
            var target = event.target;
            if (!target || !target.closest || !target.closest('[data-sticky-jump-trigger]')) {
                return;
            }
            event.preventDefault();
            jumpToVariants(root);
        });
    }

    function syncAll(root, dock) {
        syncButton(dock.querySelector('[data-sticky-proxy="add"]'), primaryInActions(root, ADD_SEL));
        syncButton(dock.querySelector('[data-sticky-proxy="buy-now"]'), primaryInActions(root, BUY_SEL));
        syncPrice(root, dock);
        syncSpecs(root, dock);
        syncPrimaryImage(root, dock);
    }

    function init(dock) {
        if (!dock || dock.getAttribute('data-sticky-ready') === '1') {
            return;
        }
        var root = dock.closest(ROOT_SEL) || document.querySelector(ROOT_SEL);
        if (!root || root.getAttribute('data-quick-add') === '1') {
            dock.remove();
            return;
        }
        var actions = root.querySelector(ACTIONS_SEL);
        if (!actions || typeof IntersectionObserver !== 'function') {
            dock.remove();
            return;
        }

        dock.setAttribute('data-sticky-ready', '1');
        bindProxy(dock, root);
        bindJump(dock, root);
        syncAll(root, dock);

        var observer = new IntersectionObserver(function (entries) {
            var entry = entries && entries[0];
            if (!entry) {
                return;
            }
            setDockVisible(dock, root, !entry.isIntersecting);
        }, {
            root: null,
            threshold: 0,
            rootMargin: '0px'
        });
        observer.observe(actions);

        var mo = new MutationObserver(function () {
            syncAll(root, dock);
        });
        mo.observe(actions, {
            attributes: true,
            childList: true,
            subtree: true,
            characterData: true,
            attributeFilter: ['disabled', 'class', 'hidden', 'data-default-label']
        });
        var buybox = root.querySelector('.product-native-detail__buybox');
        if (buybox) {
            mo.observe(buybox, { childList: true, subtree: true, characterData: true });
        }
        var axes = root.querySelector(AXES_SEL);
        if (axes) {
            mo.observe(axes, {
                attributes: true,
                childList: true,
                subtree: true,
                attributeFilter: ['class', 'aria-current']
            });
        }
        var primaryImage = root.querySelector('.product-native-detail__primary-image');
        if (primaryImage) {
            mo.observe(primaryImage, {
                attributes: true,
                attributeFilter: ['src', 'srcset', 'hidden', 'alt']
            });
            primaryImage.addEventListener('load', function () {
                syncPrimaryImage(root, dock);
            });
        }
    }

    function boot(scope) {
        var nodes = (scope || document).querySelectorAll(DOCK_SEL);
        nodes.forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            boot(document);
        });
    } else {
        boot(document);
    }

    document.addEventListener('weline:widget-rendered', function (event) {
        boot(event.target || document);
    });
})();
