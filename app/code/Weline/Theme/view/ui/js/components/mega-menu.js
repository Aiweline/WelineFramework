/**
 * Weline.UI mega-menu — split chrome (sidebar tabs + content panels).
 * Open/close remains on parent `popover`; this component owns tab activation.
 * Inactive panels may ship as `data-mega-panel-lazy` + compact JSON payloads.
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

        const text = (value) => (value == null ? '' : String(value));

        const hydrateLazyPanel = (panel) => {
            if (!panel || panel.getAttribute('data-mega-panel-lazy') !== '1') {
                return;
            }
            const raw = panel.getAttribute('data-mega-lazy-payload') || '';
            panel.removeAttribute('data-mega-panel-lazy');
            panel.removeAttribute('data-mega-lazy-payload');
            let payload = null;
            try {
                payload = raw ? JSON.parse(raw) : null;
            } catch (_) {
                payload = null;
            }
            if (!payload || typeof payload !== 'object') {
                return;
            }

            const banner = text(payload.banner).trim();
            const intro = text(payload.intro).trim();
            if (banner || intro) {
                const introEl = document.createElement('div');
                introEl.className = 'mega-menu-panel__intro';
                introEl.setAttribute('data-testid', 'mega-menu-category-intro');
                if (banner) {
                    const wrap = document.createElement('div');
                    wrap.className = 'mega-menu-panel__banner';
                    const img = document.createElement('img');
                    img.src = banner;
                    img.alt = text(payload.bannerAlt);
                    img.width = 1500;
                    img.height = 300;
                    img.loading = 'lazy';
                    img.decoding = 'async';
                    wrap.appendChild(img);
                    introEl.appendChild(wrap);
                }
                if (intro) {
                    const p = document.createElement('p');
                    p.className = 'mega-menu-panel__intro-desc';
                    p.textContent = intro;
                    introEl.appendChild(p);
                }
                panel.appendChild(introEl);
            }

            const cards = Array.isArray(payload.cards) ? payload.cards : [];
            if (cards.length === 0) {
                return;
            }
            const list = document.createElement('ul');
            list.className = 'mega-menu-subgrid mega-menu-subgrid--cards';
            list.setAttribute('role', 'list');
            cards.forEach((card) => {
                if (!card || typeof card !== 'object') {
                    return;
                }
                const name = text(card.n).trim();
                if (!name) {
                    return;
                }
                const leaves = Array.isArray(card.c) ? card.c : [];
                const hasExpand = leaves.length > 0;
                const li = document.createElement('li');
                li.className = 'mega-menu-card' + (hasExpand ? ' has-expand is-expanded' : '');
                if (hasExpand) {
                    li.setAttribute('data-mega-card-expand', '1');
                }
                const link = document.createElement('a');
                link.className = 'mega-menu-card__link';
                link.href = text(card.u) || '#';
                const media = document.createElement('span');
                media.className = 'mega-menu-card__media';
                const img = document.createElement('img');
                img.src = text(card.i);
                img.alt = '';
                img.loading = 'lazy';
                img.width = 72;
                img.height = 72;
                media.appendChild(img);
                const body = document.createElement('span');
                body.className = 'mega-menu-card__body';
                const title = document.createElement('span');
                title.className = 'mega-menu-card__title';
                title.textContent = name;
                const desc = document.createElement('span');
                desc.className = 'mega-menu-card__desc';
                desc.textContent = text(card.d);
                body.appendChild(title);
                body.appendChild(desc);
                link.appendChild(media);
                link.appendChild(body);
                li.appendChild(link);
                if (hasExpand) {
                    const children = document.createElement('ul');
                    children.className = 'mega-menu-card__children';
                    children.setAttribute('role', 'list');
                    leaves.forEach((leaf) => {
                        if (!leaf || typeof leaf !== 'object') {
                            return;
                        }
                        const leafName = text(leaf.n).trim();
                        if (!leafName) {
                            return;
                        }
                        const leafLi = document.createElement('li');
                        const leafLink = document.createElement('a');
                        leafLink.href = text(leaf.u) || '#';
                        leafLink.textContent = leafName;
                        leafLi.appendChild(leafLink);
                        children.appendChild(leafLi);
                    });
                    li.appendChild(children);
                }
                list.appendChild(li);
            });
            panel.appendChild(list);
        };

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
                if (active) {
                    hydrateLazyPanel(panel);
                }
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
                const href = (tab.getAttribute?.('href') || '').trim();
                const navigable = href !== '' && href !== '#' && !/^javascript:/i.test(href);
                if (navigable) {
                    event.preventDefault();
                    event.stopPropagation();
                    activateFromTab(tab);
                    const absolute = tab instanceof HTMLAnchorElement && tab.href ? tab.href : href;
                    window.location.assign(absolute);
                    return;
                }
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
