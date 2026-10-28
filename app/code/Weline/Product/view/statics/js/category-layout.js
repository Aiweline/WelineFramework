/**
 * Category/products layout sidebar/filter drawer (Weline_Product).
 * #category-layout-main and #products-main both declare the same drawer contract.
 */
(function () {
        function bind(rootId) {
            var root = document.getElementById(rootId);
            if (!root || root.dataset.filterDrawerBound === 'true') return;
            var sidebar = root.querySelector('[data-sidebar]');
            var overlay = root.querySelector('[data-overlay]');
            var toggle = root.querySelector('[data-filter-toggle]');
            var closeBtn = root.querySelector('[data-sidebar-close]');
            if (!sidebar || !toggle) return;
            root.dataset.filterDrawerBound = 'true';

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
        }

        function bindAll() {
            bind('category-layout-main');
            bind('products-main');
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindAll, { once: true });
        } else {
            bindAll();
        }
    })();
