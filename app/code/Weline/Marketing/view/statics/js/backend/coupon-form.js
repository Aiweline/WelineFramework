/**
 * 优惠券表单：按优惠类型切换折扣值标签 / 单位 / 帮助文案。
 * data-weline-load="marketingCouponForm"
 */
(function (global) {
    'use strict';

    function setHidden(el, hidden) {
        if (!el) {
            return;
        }
        if (hidden) {
            el.setAttribute('hidden', '');
        } else {
            el.removeAttribute('hidden');
        }
    }

    function bind(root) {
        if (!root || root.dataset.couponFormBound === '1') {
            return;
        }
        root.dataset.couponFormBound = '1';

        var typeEl = root.querySelector('[data-testid="marketing-coupon-type"]');
        var valueWrap = root.querySelector('[data-testid="marketing-coupon-value-wrap"]');
        var labelEl = root.querySelector('[data-role="discount-label"]');
        var unitPercent = root.querySelector('[data-unit-for="percentage"]');
        var unitFixed = root.querySelector('[data-unit-for="fixed_amount"]');
        var helpPercent = root.querySelector('[data-help-for="percentage"]');
        var helpFixed = root.querySelector('[data-help-for="fixed_amount"]');
        var helpNone = root.querySelector('[data-help-for="none"]');

        function sync() {
            var type = typeEl ? String(typeEl.value || '') : 'percentage';
            var needsValue = type !== 'free_shipping' && type !== 'gift';
            var isFixed = type === 'fixed_amount';
            var isPercent = type === 'percentage';

            root.setAttribute('data-discount-type', type);
            setHidden(valueWrap, !needsValue);
            setHidden(unitPercent, !isPercent);
            setHidden(unitFixed, !isFixed);
            setHidden(helpPercent, !isPercent);
            setHidden(helpFixed, !isFixed);
            setHidden(helpNone, needsValue);

            if (!labelEl) {
                return;
            }
            if (isFixed) {
                labelEl.textContent = String(labelEl.getAttribute('data-label-fixed') || '');
            } else if (isPercent) {
                labelEl.textContent = String(labelEl.getAttribute('data-label-percent') || '');
            } else {
                labelEl.textContent = String(labelEl.getAttribute('data-label-none') || '');
            }
        }

        if (typeEl) {
            // 原生 change + input；部分增强控件只冒泡其一
            typeEl.addEventListener('change', sync);
            typeEl.addEventListener('input', sync);
        }
        // 捕获阶段兜底（自定义下拉改值后可能不冒泡到目标）
        root.addEventListener('change', function (event) {
            var target = event && event.target;
            if (target && target.getAttribute && target.getAttribute('data-testid') === 'marketing-coupon-type') {
                sync();
            }
        }, true);
        sync();
    }

    function boot(root) {
        var scope = root && root.querySelector ? root : document;
        var nodes = scope.querySelectorAll
            ? scope.querySelectorAll('[data-testid="marketing-coupon-form"]')
            : [];
        if (!nodes.length && scope.matches && scope.matches('[data-testid="marketing-coupon-form"]')) {
            bind(scope);
            return;
        }
        nodes.forEach(bind);
    }

    function init() {
        boot(document);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    global.WelineMarketingCouponFormModule = {
        boot: boot,
        init: init,
        bind: bind
    };
})(typeof window !== 'undefined' ? window : this);
