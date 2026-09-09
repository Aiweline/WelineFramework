function safeRegisterComponent(mod, UI) {
    if (!mod || typeof mod.register !== 'function' || !UI) {
        return;
    }
    try {
        mod.register(UI);
    } catch (error) {
        if (!(error instanceof Error) || !/already (defined|registered)/i.test(error.message)) {
            throw error;
        }
    }
}

async function ensureFormSelectComponents(UI) {
    // Compiled into Theme ui/pages/; relative imports resolve next to that output.
    const moduleUrls = [
        new URL('../components/weline-currency-select.js', import.meta.url).href,
        new URL('../components/weline-language-select.js', import.meta.url).href,
    ];
    const modules = await Promise.all(moduleUrls.map((url) => import(url).catch(() => null)));
    for (const mod of modules) {
        safeRegisterComponent(mod, UI);
    }
}

function remountFormSelects(root) {
    if (!(root instanceof Element) || !window.Weline?.UI?.mount) {
        return;
    }
    root.querySelectorAll('[data-w-component~="currency-select"], [data-w-component~="language-select"]').forEach((node) => {
        window.Weline.UI.mount(node);
    });
}

function defineWebsiteForm(UI) {
    UI.define('website-form', ({ element, listen, floating }) => {
        const timezoneRoot = element.querySelector('#website_timezone_selector');
        const timezoneTrigger = element.querySelector('#website_timezone_trigger');
        const timezoneLabel = element.querySelector('[data-w-timezone-label]');
        const timezoneSelect = element.querySelector('#default_timezone');
        const timezonePopover = element.querySelector('#website_timezone_popover');
        const timezoneSearch = element.querySelector('[data-w-timezone-search]');
        const timezoneList = element.querySelector('[data-w-timezone-list]');
        const defaultLanguage = element.querySelector('#website_default_language_selector_wrapper');
        const relatedLanguages = element.querySelector('#website_related_languages_selector_wrapper');
        const defaultCurrency = element.querySelector('#website_default_currency_selector_wrapper');
        const relatedCurrencies = element.querySelector('#website_related_currencies_selector_wrapper');
        let filterTimer = 0;
        let syncingLanguages = false;
        let syncingCurrencies = false;
        let timezoneOpen = false;
        let timezonePointerReference = null;
        let timezoneMonitor = null;
        let timezonePortal = null;

        if (timezoneRoot instanceof HTMLElement
            && timezoneTrigger instanceof HTMLButtonElement
            && timezoneLabel instanceof HTMLElement
            && timezoneSelect instanceof HTMLSelectElement
            && timezonePopover instanceof HTMLElement
            && timezoneList instanceof HTMLElement
            && floating) {
            const emptyText = timezoneRoot.dataset.wEmptyText || '';
            const options = [...timezoneSelect.options].map((option) => ({
                value: option.value,
                label: (option.textContent || '').trim(),
                disabled: option.disabled,
            }));
            timezonePortal = floating.portal(timezonePopover, 'website-timezone');

            const syncTimezoneLabel = () => {
                const selected = timezoneSelect.selectedOptions[0];
                const value = selected?.value || '';
                const label = value ? (selected?.textContent || value).trim() : emptyText;
                timezoneLabel.textContent = label || emptyText;
                timezoneLabel.dataset.empty = value ? 'false' : 'true';
                timezoneTrigger.setAttribute('aria-label', label || emptyText);
            };

            const renderTimezoneList = (query = '') => {
                const term = String(query).trim().toLocaleLowerCase();
                const currentValue = timezoneSelect.value;
                const fragment = document.createDocumentFragment();
                let matched = 0;

                for (const record of options) {
                    if (term !== '' && !record.label.toLocaleLowerCase().includes(term)) {
                        continue;
                    }
                    matched += 1;
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'w-website-timezone__option';
                    button.setAttribute('role', 'option');
                    button.dataset.wTimezoneValue = record.value;
                    button.textContent = record.label || emptyText;
                    button.disabled = record.disabled;
                    button.setAttribute('aria-selected', String(record.value === currentValue));
                    fragment.append(button);
                }

                if (matched === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'w-website-timezone__empty';
                    empty.textContent = emptyText;
                    fragment.append(empty);
                }

                timezoneList.replaceChildren(fragment);
                const selectedOption = timezoneList.querySelector('[aria-selected="true"]');
                selectedOption?.scrollIntoView({ block: 'nearest' });
            };

            const closeTimezone = (reason = 'api', restoreFocus = false, force = false) => {
                if (!timezoneOpen) return false;
                timezoneOpen = false;
                timezonePopover.hidden = true;
                timezonePopover.dataset.state = 'closed';
                timezonePopover.setAttribute('aria-hidden', 'true');
                timezoneMonitor?.unobserve(timezonePopover);
                timezoneMonitor?.reset();
                floating.clear(timezonePopover);
                timezonePortal?.restore();
                timezoneTrigger.setAttribute('aria-expanded', 'false');
                timezoneRoot.dataset.state = 'closed';
                timezonePointerReference = null;
                if (restoreFocus) timezoneTrigger.focus({ preventScroll: true });
                return true;
            };

            timezoneMonitor = floating.monitor(
                timezoneTrigger,
                () => timezonePopover,
                () => timezonePopover.dataset.wPlacement || 'bottom-start',
                () => closeTimezone('anchor-hidden', false, true),
            );

            const openTimezone = (reference = null) => {
                if (timezoneOpen) return false;
                timezoneOpen = true;
                timezonePopover.style.setProperty(
                    '--w-website-timezone-anchor-width',
                    `${Math.round(timezoneTrigger.getBoundingClientRect().width)}px`,
                );
                timezonePopover.dataset.wFloatingPositioned = 'pending';
                timezonePortal?.mount();
                timezonePopover.hidden = false;
                timezonePopover.dataset.state = 'open';
                timezonePopover.setAttribute('aria-hidden', 'false');
                timezoneTrigger.setAttribute('aria-expanded', 'true');
                timezoneRoot.dataset.state = 'open';
                if (timezoneSearch instanceof HTMLInputElement) {
                    timezoneSearch.value = '';
                }
                renderTimezoneList();
                timezoneMonitor?.observe(timezonePopover);
                if (timezoneMonitor?.place(reference)?.anchorVisible === false) {
                    closeTimezone('anchor-hidden', false, true);
                    return false;
                }
                window.setTimeout(() => {
                    if (timezoneOpen && timezoneSearch instanceof HTMLInputElement) {
                        timezoneSearch.focus({ preventScroll: true });
                    }
                }, 0);
                return true;
            };

            const commitTimezone = (value) => {
                const next = String(value || '');
                for (const option of timezoneSelect.options) {
                    option.selected = option.value === next;
                }
                syncTimezoneLabel();
                timezoneSelect.dispatchEvent(new Event('change', { bubbles: true }));
            };

            listen(timezoneTrigger, 'pointerdown', (event) => {
                if (!event.isPrimary || event.button !== 0) return;
                timezonePointerReference = floating.capture(
                    timezoneTrigger,
                    event,
                    timezoneTrigger.dataset.wAnchorMode || 'element',
                );
            });
            listen(timezoneTrigger, 'click', (event) => {
                if (timezoneOpen) {
                    closeTimezone('trigger');
                    return;
                }
                const recent = timezonePointerReference
                    && performance.now() - timezonePointerReference.capturedAt < 1200
                    ? timezonePointerReference
                    : floating.capture(
                        timezoneTrigger,
                        event.detail > 0 ? event : null,
                        timezoneTrigger.dataset.wAnchorMode || 'element',
                    );
                timezonePointerReference = null;
                openTimezone(recent);
            });
            listen(timezoneTrigger, 'keydown', (event) => {
                if (!timezoneOpen && ['ArrowDown', 'Enter', ' '].includes(event.key)) {
                    event.preventDefault();
                    openTimezone(floating.capture(timezoneTrigger));
                }
            });
            if (timezoneSearch instanceof HTMLInputElement) {
                const applySearch = () => {
                    window.clearTimeout(filterTimer);
                    filterTimer = window.setTimeout(() => renderTimezoneList(timezoneSearch.value), 120);
                };
                listen(timezoneSearch, 'input', applySearch);
                listen(timezoneSearch, 'search', applySearch);
                listen(timezoneSearch, 'pointerdown', (event) => event.stopPropagation());
                listen(timezoneSearch, 'click', (event) => event.stopPropagation());
                listen(timezoneSearch, 'keydown', (event) => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        closeTimezone('escape', true);
                    }
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        timezoneList.querySelector('.w-website-timezone__option:not(:disabled)')?.focus();
                    }
                });
            }
            listen(timezoneList, 'click', (event) => {
                const option = event.target instanceof Element
                    ? event.target.closest('[data-w-timezone-value]')
                    : null;
                if (!(option instanceof HTMLButtonElement) || option.disabled) return;
                commitTimezone(option.dataset.wTimezoneValue || '');
                closeTimezone('select', true);
            });
            listen(document, 'pointerdown', (event) => {
                if (!timezoneOpen) return;
                const target = event.target;
                if (!(target instanceof Node)) return;
                if (timezoneRoot.contains(target) || timezonePopover.contains(target)) return;
                closeTimezone('outside');
            });
            listen(document, 'keydown', (event) => {
                if (timezoneOpen && event.key === 'Escape') closeTimezone('escape', true);
            });
            listen(window, 'pagehide', () => closeTimezone('pagehide', false, true));
            listen(window, 'pageshow', () => closeTimezone('history-restore', false, true));

            timezonePopover.hidden = true;
            timezonePopover.dataset.state = 'closed';
            timezonePopover.setAttribute('aria-hidden', 'true');
            timezoneTrigger.setAttribute('aria-expanded', 'false');
            timezoneRoot.dataset.state = 'closed';
            syncTimezoneLabel();
        }

        const syncLanguages = () => {
            if (syncingLanguages
                || !(defaultLanguage instanceof HTMLElement)
                || !(relatedLanguages instanceof HTMLElement)) {
                return;
            }
            const single = UI.get(defaultLanguage, 'language-select');
            const multiple = UI.get(relatedLanguages, 'language-select');
            if (!single || !multiple) return;

            syncingLanguages = true;
            try {
                const current = String(single.getValue() || '').trim();
                const values = [...new Set(multiple.getValues().map((value) => String(value).trim()).filter(Boolean))];
                if (current && !values.includes(current)) values.unshift(current);
                multiple.setReadonlyValues(current ? [current] : []);
                multiple.setValues(values);
            } finally {
                syncingLanguages = false;
            }
        };

        listen(element, 'weline:ui:language-select:ready', syncLanguages);
        listen(element, 'weline:ui:language-select:change', (event) => {
            if (event.target === defaultLanguage) syncLanguages();
        });
        queueMicrotask(syncLanguages);

        const syncCurrencies = () => {
            if (syncingCurrencies
                || !(defaultCurrency instanceof HTMLElement)
                || !(relatedCurrencies instanceof HTMLElement)) {
                return;
            }
            const single = UI.get(defaultCurrency, 'currency-select');
            const multiple = UI.get(relatedCurrencies, 'currency-select');
            if (!single || !multiple) return;

            syncingCurrencies = true;
            try {
                const current = String(single.getValue() || '').trim().toUpperCase();
                const values = [...new Set(
                    multiple.getValues().map((value) => String(value).trim().toUpperCase()).filter(Boolean),
                )];
                if (current && !values.includes(current)) values.unshift(current);
                multiple.setReadonlyValues(current ? [current] : []);
                multiple.setValues(values);
            } finally {
                syncingCurrencies = false;
            }
        };

        listen(element, 'weline:ui:currency-select:ready', syncCurrencies);
        listen(element, 'weline:ui:currency-select:change', (event) => {
            if (event.target === defaultCurrency) syncCurrencies();
        });
        queueMicrotask(syncCurrencies);

        const subPathInput = element.querySelector('[data-w-sub-path-input], #sub_path');
        const subPathError = element.querySelector('[data-w-sub-path-error], #sub_path_error');
        const form = element.querySelector('form.w-form') || element.closest('form') || element.querySelector('form');
        let subPathTimer = 0;
        let subPathValid = true;

        const parseCodeList = (raw) => {
            try {
                const parsed = JSON.parse(String(raw || '[]'));
                return Array.isArray(parsed) ? parsed.map((value) => String(value || '').trim()).filter(Boolean) : [];
            } catch (_error) {
                return [];
            }
        };

        const languageBan = new Set(
            parseCodeList(element.dataset.subPathBanLanguages).flatMap((code) => {
                const lower = code.toLowerCase();
                return [lower, lower.replace(/-/g, '_'), lower.replace(/_/g, '-')];
            }),
        );
        const currencyBan = new Set(
            parseCodeList(element.dataset.subPathBanCurrencies).map((code) => code.toUpperCase()),
        );
        const reservedSegments = new Set([
            'static', 'pub', 'media', 'api', 'admin',
            'favicon.ico', 'robots.txt', 'sitemap.xml',
        ]);
        const msgLanguagePrefix = element.dataset.subPathBanMsgLanguagePrefix || '网站子路径不允许使用语言编码';
        const msgCurrencyPrefix = element.dataset.subPathBanMsgCurrencyPrefix || '网站子路径不允许使用货币编码';
        const msgReserved = element.dataset.subPathBanMsgReserved || '子路径首段为保留字，请更换。';
        const msgFormat = element.dataset.subPathBanMsgFormat || '子路径格式无效。';

        const formatBanMessage = (prefix, code) => `${String(prefix || '')}「${String(code || '')}」。`;
        const normalizeSubPathValue = (value) => {
            let raw = String(value || '').trim();
            if (!raw || raw === '/') {
                return '';
            }
            if (raw.indexOf('://') !== -1) {
                try {
                    raw = new URL(raw).pathname || '';
                } catch (_error) {
                    raw = '';
                }
            }
            const hostPath = raw.match(/^[a-z0-9.-]+\.[a-z]{2,}(\/.*)$/i);
            if (hostPath) {
                raw = hostPath[1] || '';
            }
            raw = raw.replace(/^\/+/, '').replace(/\/+$/, '');
            return raw ? `/${raw}` : '';
        };

        const validateSubPathValue = (value) => {
            const normalized = normalizeSubPathValue(value);
            if (normalized === '') {
                return { valid: true, message: '' };
            }
            if (!/^(?:\/(?:[A-Za-z0-9][A-Za-z0-9_-]{0,62})){1,5}$/.test(normalized)) {
                return { valid: false, message: msgFormat };
            }
            const segments = normalized.replace(/^\/+/, '').split('/').filter(Boolean);
            for (const segment of segments) {
                const lower = segment.toLowerCase();
                const upper = segment.toUpperCase();
                if (reservedSegments.has(lower)) {
                    return { valid: false, message: msgReserved };
                }
                if (currencyBan.has(upper)) {
                    return { valid: false, message: formatBanMessage(msgCurrencyPrefix, upper) };
                }
                if (languageBan.has(lower) || /^[a-z]{2}(?:[_-][a-z0-9]{2,8}){1,2}$/.test(lower)) {
                    return { valid: false, message: formatBanMessage(msgLanguagePrefix, segment) };
                }
            }
            return { valid: true, message: '' };
        };

        const setSubPathError = (message) => {
            subPathValid = !message;
            if (subPathInput instanceof HTMLInputElement) {
                subPathInput.setAttribute('aria-invalid', message ? 'true' : 'false');
                subPathInput.classList.toggle('is-invalid', Boolean(message));
            }
            if (subPathError instanceof HTMLElement) {
                if (message) {
                    subPathError.hidden = false;
                    subPathError.textContent = message;
                } else {
                    subPathError.hidden = true;
                    subPathError.textContent = '';
                }
            }
        };

        const runSubPathValidation = () => {
            if (!(subPathInput instanceof HTMLInputElement)) {
                subPathValid = true;
                return true;
            }
            const result = validateSubPathValue(subPathInput.value);
            setSubPathError(result.valid ? '' : result.message);
            return result.valid;
        };

        if (subPathInput instanceof HTMLInputElement) {
            const scheduleSubPathValidation = () => {
                window.clearTimeout(subPathTimer);
                subPathTimer = window.setTimeout(runSubPathValidation, 300);
            };
            listen(subPathInput, 'input', scheduleSubPathValidation);
            listen(subPathInput, 'change', scheduleSubPathValidation);
            listen(subPathInput, 'blur', runSubPathValidation);
            queueMicrotask(runSubPathValidation);
        }

        if (form instanceof HTMLFormElement) {
            listen(form, 'submit', (event) => {
                if (!runSubPathValidation()) {
                    event.preventDefault();
                    event.stopPropagation();
                    subPathInput?.focus({ preventScroll: true });
                }
            });
        }

        return {
            syncLanguages,
            syncCurrencies,
            validateSubPath: runSubPathValidation,
            destroy() {
                window.clearTimeout(filterTimer);
                window.clearTimeout(subPathTimer);
                if (timezonePopover instanceof HTMLElement) {
                    timezonePopover.hidden = true;
                }
                timezoneMonitor?.destroy();
                timezonePortal?.destroy();
                if (timezonePopover instanceof HTMLElement && floating) {
                    floating.clear(timezonePopover);
                }
            },
        };
    });
}

function register(UI) {
    if (!UI) {
        return;
    }

    const boot = () => {
        try {
            defineWebsiteForm(UI);
        } catch (error) {
            if (!(error instanceof Error) || !/already (defined|registered)/i.test(error.message)) {
                throw error;
            }
        }
        UI.mount(document);
        remountFormSelects(document);
    };

    ensureFormSelectComponents(UI).then(boot).catch(boot);
}

if (window.Weline?.UI) {
    register(window.Weline.UI);
} else {
    document.addEventListener('weline:ui:ready', () => register(window.Weline.UI), { once: true });
}
