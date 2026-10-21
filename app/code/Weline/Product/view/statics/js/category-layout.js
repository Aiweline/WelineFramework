/**
 * Category layout sidebar/filter drawer (Weline_Product).
 * Moved verbatim from the inline <script> in theme/frontend/layouts/category/default.phtml.
 * Registered as module `productCategoryLayout`; #category-layout-main declares data-weline-load.
 */
(function () {
        var root = document.getElementById('category-layout-main');
        if (!root) return;
        var sidebar = root.querySelector('[data-sidebar]');
        var overlay = root.querySelector('[data-overlay]');
        var toggle = root.querySelector('[data-filter-toggle]');
        var closeBtn = root.querySelector('[data-sidebar-close]');
        if (!sidebar || !toggle) return;

        function setOpen(open) {
            sidebar.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (overlay) {
                overlay.hidden = !open;
                overlay.classList.toggle('is-visible', open);
            }
            document.documentElement.classList.toggle('category-filter-drawer-open', open);
        }

        toggle.addEventListener('click', function () {
            setOpen(!sidebar.classList.contains('is-open'));
        });
        if (closeBtn) {
            closeBtn.addEventListener('click', function () { setOpen(false); });
        }
        if (overlay) {
            overlay.addEventListener('click', function () { setOpen(false); });
        }
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') setOpen(false);
        });
    })();
