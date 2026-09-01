const root = document.querySelector('[data-w-system-config]');
const configNode = document.getElementById('w-system-config-guide-config');

if (root instanceof HTMLElement && configNode instanceof HTMLScriptElement) {
    let config = {};
    try {
        config = JSON.parse(configNode.textContent || '{}');
    } catch (_error) {
        config = {};
    }

    const guideKeys = Array.isArray(config.guideKeys) ? config.guideKeys : [];
    if (guideKeys.length === 0) {
        // No guide keys: nothing to initialize.
    } else {
        const initialLocate = decodeGuideValue(String(config.initialLocate || ''));
        const notFoundMsg = String(config.notFoundMsg || '');
        const currentPrefix = String(config.currentPrefix || '');
        const modeSamePage = String(config.modeSamePage || '');
        const modeJump = String(config.modeJump || '');
        const modeMissing = String(config.modeMissing || '');
        const badgeCurrent = String(config.badgeCurrent || '');
        const badgeTarget = String(config.badgeTarget || '');
        const collapseLabel = String(config.collapseLabel || '');
        const expandLabel = String(config.expandLabel || '');
        const guideParamNames = ['guide_key', 'guide_keys', 'guide_title', 'guide_summary', 'guide_locate', 'guide_step', 'guide_return'];
        let currentKey = initialLocate || '';

        function decodeGuideValue(value) {
            let cur = String(value || '').trim();
            if (!cur) {
                return '';
            }
            for (let i = 0; i < 5; i++) {
                if (!/%[0-9A-Fa-f]{2}/.test(cur)) {
                    break;
                }
                try {
                    const next = decodeURIComponent(cur.replace(/\+/g, '%20'));
                    if (next === cur) {
                        break;
                    }
                    cur = next;
                } catch (_e) {
                    break;
                }
            }
            return cur.trim();
        }

        function findTarget(key) {
            key = decodeGuideValue(key);
            if (!key) {
                return null;
            }
            const rows = document.querySelectorAll('[data-guide-key]');
            for (let i = 0; i < rows.length; i++) {
                if (decodeGuideValue(rows[i].getAttribute('data-guide-key') || '') === key) {
                    return rows[i];
                }
            }
            return null;
        }

        function findTriggerByKey(key) {
            key = decodeGuideValue(key);
            const preferred = document.querySelectorAll('.w-system-config__guide-float-item[data-wsc-guide-locate-key]');
            for (let i = 0; i < preferred.length; i++) {
                if (decodeGuideValue(preferred[i].getAttribute('data-wsc-guide-locate-key') || '') === key) {
                    return preferred[i];
                }
            }
            const buttons = document.querySelectorAll('[data-wsc-guide-locate-key]');
            for (let j = 0; j < buttons.length; j++) {
                if (decodeGuideValue(buttons[j].getAttribute('data-wsc-guide-locate-key') || '') === key) {
                    return buttons[j];
                }
            }
            return null;
        }

        function syncModeLabels() {
            document.querySelectorAll('[data-wsc-guide-locate-key]').forEach((btn) => {
                const key = decodeGuideValue(btn.getAttribute('data-wsc-guide-locate-key') || '');
                const mode = btn.querySelector('[data-wsc-guide-mode]');
                if (!mode) {
                    return;
                }
                if (findTarget(key)) {
                    mode.textContent = modeSamePage;
                    btn.classList.remove('is-missing');
                } else if (btn.getAttribute('data-wsc-guide-href')) {
                    mode.textContent = modeJump;
                } else {
                    mode.textContent = modeMissing;
                    btn.classList.add('is-missing');
                }
            });
        }

        function setCurrentUi(key, label) {
            currentKey = decodeGuideValue(key || '');
            document.querySelectorAll('.w-system-config__guide-float-item, .w-system-config__guide-chip').forEach((el) => {
                const itemKey = decodeGuideValue(el.getAttribute('data-wsc-guide-locate-key') || '');
                el.classList.toggle('is-current', itemKey !== '' && itemKey === currentKey);
            });
            document.querySelectorAll('tr.is-guide-target, .w-system-config__adapter.is-guide-target').forEach((row) => {
                const rowKey = decodeGuideValue(row.getAttribute('data-guide-key') || '');
                const isCurrent = rowKey !== '' && rowKey === currentKey;
                row.classList.toggle('is-focused', isCurrent);
                const badge = row.querySelector('[data-wsc-guide-badge]');
                if (badge) {
                    badge.classList.toggle('is-current', isCurrent);
                    badge.textContent = isCurrent ? badgeCurrent : badgeTarget;
                }
            });
            const currentLabelEl = document.querySelector('[data-wsc-guide-current-label]');
            if (currentLabelEl) {
                const text = label || key || '';
                currentLabelEl.textContent = String(currentPrefix).replace('__LABEL__', text);
                currentLabelEl.setAttribute('title', text);
            }
        }

        function sanitizeGuideSearchParams(url) {
            guideParamNames.forEach((name) => {
                if (!url.searchParams.has(name)) {
                    return;
                }
                const decoded = decodeGuideValue(url.searchParams.get(name) || '');
                if (decoded === '') {
                    url.searchParams.delete(name);
                } else {
                    url.searchParams.set(name, decoded);
                }
            });
            return url;
        }

        function replaceLocateInUrl(key) {
            try {
                const url = sanitizeGuideSearchParams(new URL(window.location.href));
                key = decodeGuideValue(key);
                if (key) {
                    url.searchParams.set('guide_locate', key);
                } else {
                    url.searchParams.delete('guide_locate');
                }
                window.history.replaceState({}, '', url.toString());
            } catch (_e) {
                // ignore
            }
        }

        function openDisclosureChain(element) {
            let node = element instanceof HTMLElement ? element : null;
            while (node && node !== root) {
                if (
                    node.classList.contains('w-system-config__group-disclosure')
                    || node.hasAttribute('data-w-system-config-filters')
                ) {
                    const disclosureTrigger = node.querySelector('[data-w-disclosure-trigger]');
                    const disclosurePanel = node.querySelector('[data-w-disclosure-panel]');
                    if (
                        disclosureTrigger instanceof HTMLElement
                        && disclosurePanel instanceof HTMLElement
                        && disclosurePanel.hidden
                    ) {
                        disclosureTrigger.setAttribute('aria-expanded', 'true');
                        disclosurePanel.hidden = false;
                        node.dataset.state = 'open';
                    }
                }
                node = node.parentElement;
            }
        }

        function revealGuidePath(target) {
            let node = target instanceof HTMLElement ? target : null;
            while (node && node !== root) {
                node.hidden = false;
                node.classList.remove('is-search-hidden');
                node = node.parentElement;
            }
            openDisclosureChain(target);
        }

        function stickyScrollOffset() {
            const sticky = root.querySelector('.w-system-config__filters-sticky');
            const stickyHeight = sticky instanceof HTMLElement ? sticky.getBoundingClientRect().height : 0;
            const topbarRaw = getComputedStyle(document.documentElement).getPropertyValue('--weline-backend-topbar-height');
            const topbarHeight = Number.parseFloat(topbarRaw) || 56;

            return topbarHeight + stickyHeight + 16;
        }

        function scrollGuideTargetIntoView(target) {
            if (!(target instanceof HTMLElement)) {
                return;
            }
            revealGuidePath(target);
            const scrollNow = () => {
                const offset = stickyScrollOffset();
                const rect = target.getBoundingClientRect();
                const absoluteTop = window.scrollY + rect.top - offset;
                window.scrollTo({
                    top: Math.max(0, absoluteTop),
                    behavior: 'auto',
                });
            };
            scrollNow();
            requestAnimationFrame(scrollNow);
        }

        function isTargetVisible(target) {
            if (!(target instanceof HTMLElement)) {
                return false;
            }
            const rect = target.getBoundingClientRect();
            const offset = stickyScrollOffset();
            return rect.top >= offset - 8 && rect.top <= window.innerHeight - 48;
        }

        function locateOnPage(key, requireVisible) {
            key = decodeGuideValue(key);
            const target = findTarget(key);
            if (!target) {
                return false;
            }
            const templateCard = target.closest('.w-system-config__template');
            if (templateCard instanceof HTMLElement) {
                templateCard.hidden = false;
                templateCard.classList.remove('is-search-hidden');
                templateCard.classList.add('is-guide-target');
            }
            const moduleCard = target.closest('.w-system-config__module');
            if (moduleCard instanceof HTMLElement) {
                moduleCard.hidden = false;
                moduleCard.classList.remove('is-search-hidden');
            }
            openDisclosureChain(target);
            let label = '';
            const floatBtn = findTriggerByKey(key);
            if (floatBtn) {
                label = floatBtn.getAttribute('data-wsc-guide-label') || '';
            }
            setCurrentUi(key, label);
            replaceLocateInUrl(key);
            scrollGuideTargetIntoView(target);
            window.setTimeout(() => {
                const control = target.querySelector('input:not([type="hidden"]), select, textarea, button, a.w-button');
                if (control && typeof control.focus === 'function') {
                    try {
                        control.focus({ preventScroll: true });
                    } catch (_e) {
                        control.focus();
                    }
                }
            }, 120);
            if (requireVisible) {
                return isTargetVisible(target);
            }

            return true;
        }

        function navigateToTarget(trigger, key) {
            key = decodeGuideValue(key);
            let href = (trigger && trigger.getAttribute)
                ? (trigger.getAttribute('data-wsc-guide-href') || '')
                : '';
            if (!href) {
                const fallback = findTriggerByKey(key);
                href = fallback ? (fallback.getAttribute('data-wsc-guide-href') || '') : '';
            }
            if (!href) {
                if (typeof Weline !== 'undefined' && Weline.UI?.toast?.warning) {
                    Weline.UI.toast.warning(notFoundMsg);
                }
                return false;
            }
            try {
                const url = sanitizeGuideSearchParams(new URL(href, window.location.href));
                if (key) {
                    url.searchParams.set('guide_locate', key);
                }
                window.location.assign(url.toString());
            } catch (_e) {
                window.location.href = href;
            }
            return true;
        }

        function locateKey(key, trigger) {
            key = decodeGuideValue(key);
            if (!key) {
                return false;
            }
            if (locateOnPage(key)) {
                return true;
            }
            return navigateToTarget(trigger || findTriggerByKey(key), key);
        }

        function autoLocateWithRetry(attempt) {
            if (!initialLocate) {
                return;
            }
            if (locateOnPage(initialLocate, true)) {
                return;
            }
            if (attempt < 16) {
                window.setTimeout(() => {
                    autoLocateWithRetry(attempt + 1);
                }, 120 + attempt * 100);
            }
        }

        function scheduleAutoLocate() {
            const run = () => {
                autoLocateWithRetry(0);
            };
            document.addEventListener('w-system-config:filter-ready', run);
            window.addEventListener('load', run, { once: true });
            run();
            window.setTimeout(run, 320);
            window.setTimeout(run, 900);
        }

        const floatPanel = document.getElementById('wsc-guide-float');
        const floatToggle = floatPanel
            ? floatPanel.querySelector('[data-wsc-guide-float-toggle]')
            : null;
        const floatStorageKey = 'wsc-guide-float-collapsed';

        function setFloatCollapsed(collapsed) {
            if (!floatPanel || !floatToggle) {
                return;
            }
            floatPanel.classList.toggle('is-collapsed', !!collapsed);
            floatToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            floatToggle.setAttribute('title', collapsed ? expandLabel : collapseLabel);
            const icon = floatToggle.querySelector('w-icon, i, .w-icon');
            if (icon instanceof Element) {
                const nextIcon = collapsed ? 'chevron-left' : 'chevron-right';
                if (icon.tagName === 'W-ICON') {
                    icon.setAttribute('name', nextIcon);
                } else if (icon instanceof SVGElement) {
                    icon.setAttribute('class', nextIcon);
                } else if (icon instanceof HTMLElement) {
                    icon.className = nextIcon;
                }
            }
            try {
                window.sessionStorage.setItem(floatStorageKey, collapsed ? '1' : '0');
            } catch (_e) {
                // ignore
            }
        }

        function readFloatCollapsed() {
            try {
                return window.sessionStorage.getItem(floatStorageKey) === '1';
            } catch (_e) {
                return false;
            }
        }

        setFloatCollapsed(readFloatCollapsed());
        syncModeLabels();

        try {
            const cleanUrl = sanitizeGuideSearchParams(new URL(window.location.href));
            if (cleanUrl.toString() !== window.location.href) {
                window.history.replaceState({}, '', cleanUrl.toString());
            }
        } catch (_e) {
            // ignore
        }

        scheduleAutoLocate();

        document.addEventListener('click', (event) => {
            if (!event.target || !event.target.closest) {
                return;
            }

            const toggle = event.target.closest('[data-wsc-guide-float-toggle]');
            if (toggle) {
                event.preventDefault();
                event.stopPropagation();
                setFloatCollapsed(!(floatPanel && floatPanel.classList.contains('is-collapsed')));
                return;
            }

            if (floatPanel && floatPanel.classList.contains('is-collapsed') && floatPanel.contains(event.target)) {
                event.preventDefault();
                setFloatCollapsed(false);
                return;
            }

            const trigger = event.target.closest('[data-wsc-guide-locate-key], [data-wsc-guide-locate]');
            if (!trigger) {
                return;
            }
            event.preventDefault();
            const key = decodeGuideValue(
                trigger.getAttribute('data-wsc-guide-locate-key') || currentKey || initialLocate || '',
            );
            if (!locateKey(key, trigger)) {
                if (typeof Weline !== 'undefined' && Weline.UI?.toast?.warning) {
                    Weline.UI.toast.warning(notFoundMsg);
                }
            }
        });
    }
}
