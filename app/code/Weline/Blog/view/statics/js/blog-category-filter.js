/**
 * Blog category sidebar filter (expandable 2-level tree).
 * Registered as module `blogCategoryFilter`; host declares data-weline-load="blogCategoryFilter".
 */
(function () {
    'use strict';
    var root = document.querySelector('[data-blog-category-filter]');
    if (!root) {
        return;
    }
    root.addEventListener('click', function (event) {
        var toggle = event.target && event.target.closest ? event.target.closest('[data-blog-filter-toggle]') : null;
        if (!toggle || !root.contains(toggle)) {
            return;
        }
        event.preventDefault();
        var node = toggle.closest('[data-blog-filter-node]');
        if (!node) {
            return;
        }
        var children = node.querySelector('[data-blog-filter-children]');
        var open = node.getAttribute('data-state') !== 'open';
        node.setAttribute('data-state', open ? 'open' : 'closed');
        node.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (children) {
            if (open) {
                children.removeAttribute('hidden');
            } else {
                children.setAttribute('hidden', '');
            }
        }
    });
    window.WelineBlogCategoryFilter = window.WelineBlogCategoryFilter || { ready: true };
}());
