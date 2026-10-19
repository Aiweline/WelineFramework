/* Weline UI: local mailbox domain picker (backend admin) — searchable tag, mirrors customer-select. */
(function () {
    'use strict';

    function parseOptions(raw) {
        if (Array.isArray(raw)) return raw;
        if (typeof raw !== 'string' || !raw.trim()) return [];
        try {
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function normalizeOption(row) {
        const value = String(row.value != null ? row.value : (row.id != null ? row.id : ''));
        const domain = String(row.domain || row.email || row.label || '').trim().toLowerCase();
        const label = String(row.label || row.domain || domain || value).trim();
        const meta = String(row.meta || row.engine || '').trim();
        return {
            value,
            label,
            meta: meta && meta.toLowerCase() !== domain ? meta : '',
            email: domain,
            domain,
            search: String(row.search || `${label} ${meta} ${domain} ${value}`).toLocaleLowerCase(),
        };
    }

    function register(UI) {
        UI.define('mail-domain-select', ({ element, listen, emit, floating }) => {
            const trigger = element.querySelector('.w-language-select__trigger');
            const tags = element.querySelector('[data-w-mail-domain-tags]');
            const field = element.querySelector('[data-w-mail-domain-field]');
            const popover = element.querySelector('.w-language-select__popover');
            const search = element.querySelector('[data-w-mail-domain-search]');
            const list = element.querySelector('[data-w-mail-domain-list]');
            const displayOnly = element.dataset.wDisplayOnly === 'true';
            const allowEmpty = element.dataset.wAllowEmpty === 'true';
            const autoSubmit = element.dataset.wAutoSubmit === 'true';
            const formId = String(element.dataset.wForm || '');
            const notFoundText = element.dataset.wNotFound || '';
            const emptyText = element.dataset.wEmptyText || '';
            const initialDisplay = String(element.dataset.wInitialDisplay || '');
            const allOptions = parseOptions(element.dataset.wOptions || '[]').map(normalizeOption).filter((item) => item.value !== '');
            let selectedValue = '';
            let selectedLabel = '';
            let selectedEmail = '';
            let open = false;
            let pointerReference = null;
            let cachedOptions = [];

            if (!(trigger instanceof HTMLButtonElement)
                || !(tags instanceof HTMLElement)
                || !(field instanceof HTMLInputElement)) {
                return {};
            }

            const portal = popover instanceof HTMLElement ? floating.portal(popover, 'mail-domain-select') : null;
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
                email: selectedEmail,
            });

            const updateValidity = () => {
                const missing = field.required && selectedValue === '';
                field.setCustomValidity(missing ? emptyText || 'Please select a domain.' : '');
                trigger.setAttribute('aria-invalid', String(missing));
            };

            const renderTags = () => {
                const fragment = document.createDocumentFragment();
                if (selectedValue === '') {
                    const placeholder = document.createElement('span');
                    placeholder.className = 'w-language-select__placeholder';
                    placeholder.append(UI.icon.create('globe', { size: 'sm' }));
                    const text = document.createElement('span');
                    text.textContent = emptyText;
                    placeholder.append(text);
                    fragment.append(placeholder);
                } else {
                    const tag = document.createElement('span');
                    tag.className = 'w-language-select__tag';
                    tag.append(UI.icon.create('globe', { size: 'sm' }));
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
                button.dataset.wMailDomainId = value;
                button.setAttribute('role', 'option');
                button.setAttribute('aria-selected', String(selected));
                button.append(UI.icon.create(selected ? 'check' : 'circle', { size: 'sm' }));

                if (clear) {
                    button.append(UI.icon.create('minus', { size: 'sm' }));
                } else {
                    const avatar = document.createElement('span');
                    avatar.className = 'w-mail-domain-select__avatar';
                    avatar.setAttribute('aria-hidden', 'true');
                    avatar.textContent = (record.email || record.label || '?').trim().charAt(0).toUpperCase() || '?';
                    button.append(avatar);
                }

                const copy = document.createElement('span');
                copy.className = 'w-language-select__option-copy';
                const strong = document.createElement('strong');
                strong.textContent = clear ? emptyText : (record.email || record.label);
                copy.append(strong);
                if (!clear && record.meta) {
                    const small = document.createElement('small');
                    small.textContent = record.meta;
                    copy.append(small);
                }
                button.append(copy);

                if (!clear && value) {
                    const code = document.createElement('span');
                    code.className = 'w-mail-domain-select__code';
                    code.textContent = `#${value}`;
                    button.append(code);
                }

                return button;
            };

            const filterOptions = (keyword) => {
                const q = String(keyword || '').trim().toLocaleLowerCase();
                if (q === '') return allOptions.slice();
                return allOptions.filter((item) => item.search.includes(q));
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

            const resolveForm = () => {
                if (formId) {
                    const byId = document.getElementById(formId);
                    if (byId instanceof HTMLFormElement) return byId;
                }
                if (field.form instanceof HTMLFormElement) return field.form;
                return null;
            };

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
                if (autoSubmit) {
                    const form = resolveForm();
                    if (form) {
                        try {
                            if (typeof form.requestSubmit === 'function') form.requestSubmit();
                            else form.submit();
                        } catch (error) {
                            console.error(error);
                        }
                    }
                }
            };

            const syncHasValue = () => {
                // Outlined floating label：胶囊/选中值视为有内容（与 language-select / currency-select 同契约）
                const hasValue = selectedValue !== '';
                element.classList.toggle('has-value', hasValue);
                element.dataset.hasValue = hasValue ? 'true' : 'false';
            };

            const commit = (value, label, shouldNotify = true, email = '') => {
                const nextValue = String(value || '');
                const nextLabel = String(label || (nextValue !== '' ? nextValue : ''));
                const nextEmail = String(email || '').trim().toLowerCase();
                const changed = selectedValue !== nextValue
                    || selectedLabel !== nextLabel
                    || selectedEmail !== nextEmail;
                selectedValue = nextValue;
                selectedLabel = nextLabel;
                selectedEmail = nextEmail;
                field.value = selectedValue;
                field.dataset.wMailDomainEmail = selectedEmail;
                syncHasValue();
                renderTags();
                updateValidity();
                if (changed && shouldNotify) notify();
                return changed;
            };

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
                renderList(filterOptions(search instanceof HTMLInputElement ? search.value : ''));
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
                    listen(search, 'input', () => renderList(filterOptions(search.value)));
                    listen(search, 'keydown', (event) => {
                        if (event.key === 'Escape') close('escape', true);
                    });
                }
                listen(list, 'click', (event) => {
                    const option = event.target instanceof Element ? event.target.closest('[data-w-mail-domain-id]') : null;
                    if (!(option instanceof HTMLButtonElement)) return;
                    event.preventDefault();
                    const value = option.dataset.wMailDomainId || '';
                    if (value === '') {
                        commit('', '', true, '');
                    } else {
                        const record = cachedOptions.find((item) => item.value === value)
                            || allOptions.find((item) => item.value === value);
                        const tagLabel = record
                            ? (record.meta ? `${record.email || record.label} · ${record.meta}` : (record.email || record.label || value))
                            : value;
                        commit(value, tagLabel, true, record?.email || '');
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
                const matched = allOptions.find((item) => item.value === selectedValue);
                commit(
                    selectedValue,
                    initialDisplay,
                    false,
                    matched?.email || String(field.dataset.wMailDomainEmail || ''),
                );
            } else if (selectedValue !== '') {
                const matched = allOptions.find((item) => item.value === selectedValue);
                const tagLabel = matched
                    ? (matched.meta ? `${matched.email || matched.label} · ${matched.meta}` : (matched.email || matched.label || selectedValue))
                    : selectedValue;
                commit(selectedValue, tagLabel, false, matched?.email || '');
            } else {
                commit('', '', false, '');
            }

            queueMicrotask(() => emit('ready', detail(), false));

            return {
                getValue: () => selectedValue,
                getLabel: () => selectedLabel,
                getEmail: () => selectedEmail,
                getDetail: detail,
                setValue: (value, label = '', email = '') => commit(String(value || ''), label, true, email),
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
        document.querySelectorAll('[data-w-component="mail-domain-select"]').forEach((node) => {
            if (!(node instanceof HTMLElement)) return;
            if (typeof UI.get === 'function' && UI.get(node, 'mail-domain-select')) return;
            UI.mount(node);
        });
    }

    if (window.Weline?.UI) {
        boot();
    } else {
        document.addEventListener('weline:ui:ready', boot, { once: true });
    }
})();
