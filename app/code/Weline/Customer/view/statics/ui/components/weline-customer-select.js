/* Weline UI: remote customer picker (backend admin) */
(function () {
    'use strict';

    function debounce(fn, delay) {
        let timer = null;
        return function (...args) {
            clearTimeout(timer);
            timer = window.setTimeout(() => fn.apply(this, args), delay);
        };
    }

    function businessResult(value) {
        let current = value;
        for (let depth = 0; depth < 3; depth += 1) {
            if (!current || typeof current !== 'object' || !current.data || typeof current.data !== 'object') {
                break;
            }
            if (Object.prototype.hasOwnProperty.call(current, 'items')
                || Object.prototype.hasOwnProperty.call(current, 'item')
                || Object.prototype.hasOwnProperty.call(current, 'success')) {
                break;
            }
            current = current.data;
        }
        return current || {};
    }

    function apiResource() {
        if (!window.Weline?.Api || typeof window.Weline.Api.resource !== 'function') {
            return Promise.reject(new Error('Weline.Api is unavailable.'));
        }
        if (typeof window.Weline.load === 'function') {
            return window.Weline.load('api').then(() => window.Weline.Api.resource('customer_admin'));
        }
        return Promise.resolve(window.Weline.Api.resource('customer_admin'));
    }

    function searchCustomers(keyword, limit) {
        return apiResource().then((resource) => {
            if (!resource || typeof resource.search !== 'function') {
                throw new Error('customer_admin.search is unavailable');
            }
            return resource.search({
                q: String(keyword || ''),
                keyword: String(keyword || ''),
                limit,
            }, { keepBusinessResult: true, silent: true });
        }).then(businessResult);
    }

    function resolveCustomer(customerId) {
        return apiResource().then((resource) => {
            if (!resource || typeof resource.resolve !== 'function') {
                throw new Error('customer_admin.resolve is unavailable');
            }
            return resource.resolve({ customer_id: customerId }, { keepBusinessResult: true, silent: true });
        }).then(businessResult);
    }

    function normalizeOption(row) {
        return {
            value: String(row.value != null ? row.value : row.customer_id || ''),
            label: String(row.label || row.username || row.email || row.value || ''),
            meta: String(row.meta || (row.email && row.email !== row.label ? row.email : '') || ''),
            search: String(row.search || `${row.label || ''} ${row.email || ''} ${row.value || row.customer_id || ''}`).toLocaleLowerCase(),
        };
    }

    function register(UI) {
        UI.define('customer-select', ({ element, listen, emit, floating }) => {
            const trigger = element.querySelector('.w-language-select__trigger');
            const tags = element.querySelector('[data-w-customer-tags]');
            const field = element.querySelector('[data-w-customer-field]');
            const popover = element.querySelector('.w-language-select__popover');
            const search = element.querySelector('[data-w-customer-search]');
            const list = element.querySelector('[data-w-customer-list]');
            const loading = element.querySelector('[data-w-customer-loading]');
            const displayOnly = element.dataset.wDisplayOnly === 'true';
            const allowEmpty = element.dataset.wAllowEmpty === 'true';
            const limit = Math.max(1, Math.min(50, Number.parseInt(element.dataset.wLimit || '20', 10) || 20));
            const notFoundText = element.dataset.wNotFound || '';
            const emptyText = element.dataset.wEmptyText || '';
            const initialDisplay = String(element.dataset.wInitialDisplay || '');
            let selectedValue = '';
            let selectedLabel = '';
            let open = false;
            let pointerReference = null;
            let requestToken = 0;
            let cachedOptions = [];

            if (!(trigger instanceof HTMLButtonElement)
                || !(tags instanceof HTMLElement)
                || !(field instanceof HTMLInputElement)) {
                return {};
            }

            const portal = popover instanceof HTMLElement ? floating.portal(popover, 'customer-select') : null;
            const focusSearch = () => {
                if (!(search instanceof HTMLInputElement)) return;
                window.setTimeout(() => {
                    if (!open || !(search instanceof HTMLInputElement)) return;
                    search.focus({ preventScroll: true });
                }, 0);
            };

            const detail = () => ({
                componentId: element.dataset.wComponentId || element.id,
                fieldId: field.id,
                value: selectedValue,
                label: selectedLabel,
            });

            const updateValidity = () => {
                const missing = field.required && selectedValue === '';
                field.setCustomValidity(missing ? emptyText || 'Please select a customer.' : '');
                trigger.setAttribute('aria-invalid', String(missing));
            };

            const renderTags = () => {
                const fragment = document.createDocumentFragment();
                if (selectedValue === '') {
                    const placeholder = document.createElement('span');
                    placeholder.className = 'w-language-select__placeholder';
                    placeholder.append(UI.icon.create('user', { size: 'sm' }));
                    const text = document.createElement('span');
                    text.textContent = emptyText;
                    placeholder.append(text);
                    fragment.append(placeholder);
                } else {
                    const tag = document.createElement('span');
                    tag.className = 'w-language-select__tag';
                    tag.append(UI.icon.create('user', { size: 'sm' }));
                    const label = document.createElement('span');
                    label.className = 'w-language-select__tag-label';
                    label.textContent = selectedLabel || selectedValue;
                    tag.append(label);
                    fragment.append(tag);
                }
                tags.replaceChildren(fragment);
            };

            const makeOption = (record, clear = false) => {
                const value = clear ? '' : record.value;
                const selected = clear ? selectedValue === '' : selectedValue === value;
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'w-language-select__option';
                button.dataset.wCustomerId = value;
                button.setAttribute('role', 'option');
                button.setAttribute('aria-selected', String(selected));
                button.append(UI.icon.create(selected ? 'check' : 'circle', { size: 'sm' }));

                if (clear) {
                    button.append(UI.icon.create('minus', { size: 'sm' }));
                } else {
                    const avatar = document.createElement('span');
                    avatar.className = 'w-customer-select__avatar';
                    avatar.setAttribute('aria-hidden', 'true');
                    avatar.textContent = (record.label || '?').trim().charAt(0).toUpperCase() || '?';
                    button.append(avatar);
                }

                const copy = document.createElement('span');
                copy.className = 'w-language-select__option-copy';
                const strong = document.createElement('strong');
                strong.textContent = clear ? emptyText : record.label;
                copy.append(strong);
                if (!clear && record.meta) {
                    const small = document.createElement('small');
                    small.textContent = record.meta;
                    copy.append(small);
                }
                button.append(copy);

                if (!clear && value) {
                    const code = document.createElement('span');
                    code.className = 'w-customer-select__code';
                    code.textContent = `#${value}`;
                    button.append(code);
                }

                return button;
            };

            const renderList = (options = []) => {
                if (!(list instanceof HTMLElement)) return;
                cachedOptions = options;
                const fragment = document.createDocumentFragment();
                if (allowEmpty) fragment.append(makeOption({}, true));
                if (!options.length) {
                    const empty = document.createElement('div');
                    empty.className = 'w-language-select__empty';
                    empty.textContent = notFoundText || emptyText;
                    fragment.append(empty);
                } else {
                    for (const record of options) {
                        fragment.append(makeOption(record));
                    }
                }
                list.replaceChildren(fragment);
            };

            const onChangeCode = String(element.dataset.wOnChange || '');

            const notify = () => {
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
                emit('change', detail(), false);
                if (onChangeCode) {
                    try {
                        // eslint-disable-next-line no-new-func
                        (new Function(onChangeCode))();
                    } catch (error) {
                        console.error(error);
                    }
                }
            };

            const commit = (value, label, shouldNotify = true) => {
                const nextValue = String(value || '');
                const nextLabel = String(label || (nextValue !== '' ? nextValue : ''));
                const changed = selectedValue !== nextValue || selectedLabel !== nextLabel;
                selectedValue = nextValue;
                selectedLabel = nextLabel;
                field.value = selectedValue;
                renderTags();
                updateValidity();
                if (changed && shouldNotify) notify();
                return changed;
            };

            const setLoading = (active) => {
                if (!(loading instanceof HTMLElement)) return;
                loading.hidden = !active;
            };

            const fetchList = (keyword) => {
                const token = ++requestToken;
                setLoading(true);
                return searchCustomers(keyword, limit).then((payload) => {
                    if (token !== requestToken) return;
                    setLoading(false);
                    const items = Array.isArray(payload.items) ? payload.items.map(normalizeOption) : [];
                    renderList(items);
                }).catch(() => {
                    if (token !== requestToken) return;
                    setLoading(false);
                    renderList([]);
                });
            };

            const debouncedFetch = debounce((keyword) => {
                fetchList(keyword);
            }, 280);

            let monitor = null;
            const close = (reason = 'api', restoreFocus = false, force = false) => {
                if (!open || !(popover instanceof HTMLElement)) return false;
                if (!force && !emit('before-close', { reason })) return false;
                open = false;
                popover.hidden = true;
                popover.dataset.state = 'closed';
                popover.setAttribute('aria-hidden', 'true');
                monitor?.unobserve(popover);
                monitor?.reset();
                floating.clear(popover);
                portal?.restore();
                trigger.setAttribute('aria-expanded', 'false');
                element.dataset.state = 'closed';
                pointerReference = null;
                emit('close', { reason }, false);
                if (restoreFocus) trigger.focus({ preventScroll: true });
                return true;
            };

            monitor = popover instanceof HTMLElement
                ? floating.monitor(
                    trigger,
                    () => popover,
                    () => popover.dataset.wPlacement || 'bottom-start',
                    () => close('anchor-hidden', false, true),
                )
                : null;

            const openPopover = (reference = null) => {
                if (open || displayOnly || !(popover instanceof HTMLElement)) return false;
                if (!emit('before-open', {})) return false;
                open = true;
                popover.style.setProperty('--w-language-select-anchor-width', `${Math.round(trigger.getBoundingClientRect().width)}px`);
                popover.dataset.wFloatingPositioned = 'pending';
                portal?.mount();
                popover.hidden = false;
                popover.dataset.state = 'open';
                popover.setAttribute('aria-hidden', 'false');
                trigger.setAttribute('aria-expanded', 'true');
                element.dataset.state = 'open';
                fetchList(search instanceof HTMLInputElement ? search.value : '');
                monitor?.observe(popover);
                if (monitor?.place(reference)?.anchorVisible === false) {
                    close('anchor-hidden', false, true);
                    return false;
                }
                emit('open', {}, false);
                focusSearch();
                return true;
            };

            if (!displayOnly && popover instanceof HTMLElement) {
                listen(trigger, 'pointerdown', (event) => {
                    if (!event.isPrimary || event.button !== 0) return;
                    pointerReference = floating.capture(trigger, event, trigger.dataset.wAnchorMode || 'element');
                });
                listen(trigger, 'click', (event) => {
                    if (open) {
                        close('trigger');
                        return;
                    }
                    const recent = pointerReference && performance.now() - pointerReference.capturedAt < 1200
                        ? pointerReference
                        : floating.capture(trigger, event.detail > 0 ? event : null, trigger.dataset.wAnchorMode || 'element');
                    pointerReference = null;
                    openPopover(recent);
                });
                if (search instanceof HTMLInputElement) {
                    listen(search, 'input', () => debouncedFetch(search.value));
                    listen(search, 'keydown', (event) => {
                        if (event.key === 'Escape') close('escape', true);
                    });
                }
                listen(list, 'click', (event) => {
                    const option = event.target instanceof Element ? event.target.closest('[data-w-customer-id]') : null;
                    if (!(option instanceof HTMLButtonElement)) return;
                    event.preventDefault();
                    const value = option.dataset.wCustomerId || '';
                    if (value === '') {
                        commit('', '', true);
                    } else {
                        const record = cachedOptions.find((item) => item.value === value);
                        commit(value, record?.label || value, true);
                    }
                    close('select');
                });
                listen(document, 'pointerdown', (event) => {
                    if (!open || element.contains(event.target) || popover.contains(event.target)) return;
                    close('outside');
                });
                listen(document, 'keydown', (event) => {
                    if (open && event.key === 'Escape') close('escape', true);
                });
                listen(window, 'pagehide', () => close('pagehide', false, true));
            }

            if (popover instanceof HTMLElement) {
                popover.hidden = true;
                popover.dataset.state = 'closed';
                popover.setAttribute('aria-hidden', 'true');
            }
            trigger.setAttribute('aria-expanded', 'false');
            element.dataset.state = 'closed';

            selectedValue = String(field.value || '');
            if (initialDisplay) {
                commit(selectedValue, initialDisplay, false);
            } else if (selectedValue !== '') {
                resolveCustomer(Number.parseInt(selectedValue, 10)).then((payload) => {
                    const item = payload.item || null;
                    commit(selectedValue, item ? String(item.label || selectedValue) : selectedValue, false);
                }).catch(() => {
                    commit(selectedValue, selectedValue, false);
                });
            } else {
                commit('', '', false);
            }

            queueMicrotask(() => emit('ready', detail(), false));

            return {
                getValue: () => selectedValue,
                getLabel: () => selectedLabel,
                getDetail: detail,
                setValue: (value, label = '') => commit(String(value || ''), label),
                open: openPopover,
                close,
                element,
                field,
                destroy() {
                    close('unmount', false, true);
                    monitor?.destroy();
                    if (popover instanceof HTMLElement) floating.clear(popover);
                    portal?.destroy();
                },
            };
        });
    }

    function boot() {
        const UI = window.Weline?.UI;
        if (!UI) return;
        register(UI);
        document.querySelectorAll('[data-w-component="customer-select"]').forEach((node) => {
            if (!(node instanceof HTMLElement)) return;
            if (typeof UI.get === 'function' && UI.get(node, 'customer-select')) return;
            UI.mount(node);
        });
    }

    if (window.Weline?.UI) {
        boot();
    } else {
        document.addEventListener('weline:ui:ready', boot, { once: true });
    }
})();
