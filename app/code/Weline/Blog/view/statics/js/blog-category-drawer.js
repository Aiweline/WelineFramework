/**
 * 博客分类移动端抽屉：侧栏开关 / 遮罩 / 焦点陷阱 / 断点复位。
 * Registered as weline module `blogCategoryDrawer`（由布局 data-weline-load 声明）。
 */
(function () {
    'use strict';
    var toggle = document.querySelector('[data-blog-sidebar-toggle]');
    var sidebar = document.getElementById('blog-category-sidebar');
    var overlay = document.querySelector('[data-blog-sidebar-overlay]');
    var close = document.querySelector('[data-blog-sidebar-close]');
    if (!toggle || !sidebar) return;
    var mq = window.matchMedia('(max-width: 900px)');
    var isOpen = false;

    function mobile() { return mq.matches; }
    function focusables() {
        return sidebar.querySelectorAll('a[href], button:not([disabled])');
    }
    function apply(open, restoreFocus) {
        isOpen = open && mobile();
        var drawer = mobile();
        sidebar.classList.toggle('is-open', isOpen);
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        if (overlay) overlay.classList.toggle('is-visible', isOpen);
        document.body.classList.toggle('blog-filter-drawer-open', isOpen);
        if (drawer) {
            sidebar.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
            sidebar.toggleAttribute('inert', !isOpen);
        } else {
            sidebar.removeAttribute('aria-hidden');
            sidebar.removeAttribute('inert');
        }
        if (restoreFocus) {
            if (isOpen && close) close.focus();
            if (!isOpen) toggle.focus();
        }
    }

    toggle.addEventListener('click', function () {
        apply(!isOpen, true);
    });
    if (close) close.addEventListener('click', function () {
        apply(false, true);
    });
    if (overlay) overlay.addEventListener('click', function () {
        apply(false, true);
    });
    sidebar.addEventListener('click', function (event) {
        if (event.target.closest('a[href]')) apply(false, false);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isOpen) apply(false, true);
    });
    sidebar.addEventListener('keydown', function (event) {
        if (event.key !== 'Tab' || !isOpen) return;
        var items = focusables();
        if (!items.length) return;
        var first = items[0];
        var last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
    if (mq.addEventListener) mq.addEventListener('change', function () { apply(false, false); });
    else mq.addListener(function () { apply(false, false); });
    apply(false, false);
})();
