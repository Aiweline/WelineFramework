/**
 * 评论管理：把审核动作写入隐藏表单并提交。
 * 由根节点 data-weline-load="reviewAdmin" 声明加载。
 */
(function () {
    'use strict';

    function init() {
        var root = document.querySelector('[data-review-role="management"]');
        if (!root) return;
        var form = root.querySelector('[data-review-role="moderation-form"]');
        if (!form) return;

        function readFilter(selector) {
            var el = document.querySelector(selector);
            return el ? String(el.value || '') : '';
        }

        root.addEventListener('click', function (event) {
            var button = event.target.closest('[data-review-action]');
            if (!button || button.disabled) return;
            form.elements.namedItem('review_id').value = String(button.dataset.reviewId || '');
            form.elements.namedItem('status').value = String(button.dataset.reviewAction || '');
            form.elements.namedItem('status_filter').value = readFilter('#review-status');
            form.elements.namedItem('website_id_filter').value = readFilter('#review-website_value');
            form.elements.namedItem('store_id_filter').value = readFilter('#review-store');
            form.requestSubmit();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
