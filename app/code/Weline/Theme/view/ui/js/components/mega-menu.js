/**
 * Weline.UI mega-menu — split chrome (sidebar tabs + content panels).
 * Open/close remains on parent `popover`; this component owns tab activation.
 */
export function register(UI) {
    UI.define('mega-menu', ({ element: root, listen }) => {
        const menuRoot = () => (
            root.matches('[data-w-mega-menu], [data-mega-menu], .w-mega-menu')
                ? root
                : (root.querySelector('[data-w-mega-menu], [data-mega-menu], .w-mega-menu') || root)
        );

        const owns = (el, menu) => !!(el && el.closest && el.closest('[data-w-mega-menu], [data-mega-menu], .w-mega-menu') === menu);

        const tabAttr = (el) => el?.getAttribute?.('data-w-mega-tab') || el?.getAttribute?.('data-mega-tab') || '';
        const panelAttr = (el) => el?.getAttribute?.('data-w-mega-panel') || el?.getAttribute?.('data-mega-panel') || '';

        const activateFromTab = (tab) => {
            const menu = menuRoot();
            if (!tab || !owns(tab, menu)) return false;
            const panelId = tabAttr(tab);
            if (!panelId) return false;
            const tabs = Array.from(menu.querySelectorAll('[data-w-mega-tab], [data-mega-tab]')).filter((item) => owns(item, menu));
            const panels = Array.from(menu.querySelectorAll('[data-w-mega-panel], [data-mega-panel]')).filter((item) => owns(item, menu));
            tabs.forEach((item) => {
                const active = item === tab;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach((panel) => {
                const active = panelAttr(panel) === panelId;
                panel.classList.toggle('is-active', active);
                panel.hidden = !active;
                if (active) panel.removeAttribute('hidden');
            });
            return true;
        };

        const expandDefaultCards = () => {
            const menu = menuRoot();
            menu.querySelectorAll('[data-w-mega-card-expand="1"], [data-mega-card-expand="1"]').forEach((card) => {
                if (!owns(card, menu)) return;
                card.classList.add('is-expanded');
                const children = card.querySelector('.w-mega-menu__card-children, .mega-menu-card__children');
                if (children) {
                    children.hidden = false;
                    children.removeAttribute('hidden');
                }
            });
        };

        const onActivateEvent = (event) => {
            const tab = event.target?.closest?.('[data-w-mega-tab], [data-mega-tab]');
            if (!tab) return;
            if (event.type === 'click') {
                event.preventDefault();
                event.stopPropagation();
            }
            activateFromTab(tab);
        };

        listen(root, 'mouseover', onActivateEvent);
        listen(root, 'focusin', onActivateEvent);
        listen(root, 'click', onActivateEvent);
        listen(root, 'pointerdown', (event) => {
            const tab = event.target?.closest?.('[data-w-mega-tab], [data-mega-tab]');
            if (!tab) return;
            if (event.pointerType === 'mouse' || event.pointerType === 'touch' || event.pointerType === 'pen') {
                activateFromTab(tab);
            }
        });

        if (!root.classList.contains('w-mega-menu')) {
            root.classList.add('w-mega-menu');
        }
        if (!root.hasAttribute('data-w-mega-menu') && !root.hasAttribute('data-mega-menu')) {
            root.setAttribute('data-w-mega-menu', '');
        }
        expandDefaultCards();

        return {
            element: root,
            activateTab: activateFromTab,
            refresh: expandDefaultCards,
            destroy() {},
        };
    });
}
