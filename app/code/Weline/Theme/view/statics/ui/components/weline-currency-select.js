/* Weline UI source: js/currency-select.js */
function parseValues(value) {
    try {
        const parsed = JSON.parse(String(value || '[]'));
        return Array.isArray(parsed)
            ? [...new Set(parsed.map((item) => String(item || '').trim()).filter(Boolean))]
            : [];
    } catch (_error) {
        return [];
    }
}

function sameValues(left, right) {
    return left.length === right.length && left.every((value, index) => value === right[index]);
}

export function register(UI) {
    try {
        UI.define('currency-select', ({ element, listen, emit, floating }) => {
        const trigger = element.querySelector('.w-currency-select__trigger');
        const tags = element.querySelector('[data-w-currency-tags]');
        const field = element.querySelector('[data-w-currency-field]');
        const popover = element.querySelector('.w-currency-select__popover');
        const search = element.querySelector('[data-w-currency-search]');
        const list = element.querySelector('[data-w-currency-list]');
        const multiple = element.dataset.wMultiple === 'true';
        const displayOnly = element.dataset.wDisplayOnly === 'true';
        const allowEmpty = element.dataset.wAllowEmpty === 'true';
        const resolveReorderUrl = () => String(
            element.dataset.wReorderUrl
            || element.closest('[data-w-currency-reorder-url]')?.getAttribute?.('data-w-currency-reorder-url')
            || ''
        ).trim();
        const resolveReorderWebsiteId = () => String(
            element.dataset.wReorderWebsiteId
            || element.closest('[data-w-currency-reorder-url]')?.getAttribute?.('data-w-currency-reorder-website-id')
            || ''
        ).trim();
        let readonlyValues = parseValues(element.dataset.wReadonlyValues);
        let selectedValues = [];
        let open = false;
        let pointerReference = null;
        let syncingField = false;
        let dragCode = null;
        let suppressTriggerClick = false;
        let reorderTimer = null;
        let reorderSeq = 0;
        let reorderDirty = false;

        if (!(trigger instanceof HTMLElement)
            || !(tags instanceof HTMLElement)
            || !(field instanceof HTMLSelectElement)) {
            return {};
        }

        const records = [...field.options]
            .filter((option) => option.value !== '')
            .map((option) => ({
                code: option.value,
                label: option.dataset.wLabel || option.textContent || option.value,
                tagLabel: option.dataset.wTagLabel || option.dataset.wLabel || option.textContent || option.value,
                meta: option.dataset.wMeta || option.value,
                search: (
                    option.getAttribute('data-w-search')
                    || option.dataset.wSearch
                    || option.textContent
                    || option.value
                ).toLocaleLowerCase(),
                disabled: option.disabled,
            }));
        const recordByCode = new Map(records.map((record) => [record.code, record]));
        const noopPortal = { mount() {}, restore() {}, destroy() {} };
        const portal = (popover instanceof HTMLElement && floating && typeof floating.portal === 'function')
            ? floating.portal(popover, 'currency-select')
            : noopPortal;
        const focusSearch = () => {
            if (!(search instanceof HTMLInputElement)) return;
            window.setTimeout(() => {
                if (!open || !(search instanceof HTMLInputElement)) return;
                search.focus({ preventScroll: true });
            }, 0);
        };

        const normalize = (values) => {
            const source = Array.isArray(values) ? values : [values];
            const normalized = [...new Set(source.map((value) => String(value || '').trim().toUpperCase()).filter(Boolean))]
                .filter((value) => recordByCode.has(value));
            return multiple ? normalized : normalized.slice(0, 1);
        };
        const valuesFromField = () => normalize([...field.selectedOptions].map((option) => option.value));
        const detail = () => ({
            componentId: element.dataset.wComponentId || element.id,
            fieldId: field.id,
            multiple,
            displayOnly,
            value: selectedValues[0] || '',
            values: [...selectedValues],
            readonlyValues: [...readonlyValues],
        });

        const makeSymbol = () => {
            const symbol = document.createElement('span');
            symbol.className = 'w-currency-select__symbol';
            try {
                if (UI?.icon?.create) {
                    symbol.append(UI.icon.create('cash', { size: 'sm' }));
                }
            } catch (_error) {
                // Keep pills visible even when icon registry is unavailable.
            }
            return symbol;
        };

        const updateValidity = () => {
            const missing = field.required && selectedValues.length === 0;
            field.setCustomValidity(missing ? element.dataset.wEmptyText || 'Please select a currency.' : '');
            trigger.setAttribute('aria-invalid', String(missing));
        };

        const renderTags = () => {
            const fragment = document.createDocumentFragment();
            if (selectedValues.length === 0) {
                const placeholder = document.createElement('span');
                placeholder.className = 'w-currency-select__placeholder';
                try {
                    if (UI?.icon?.create) {
                        placeholder.append(UI.icon.create('cash', { size: 'sm' }));
                    }
                } catch (_error) {}
                const text = document.createElement('span');
                text.textContent = element.dataset.wEmptyText || '';
                placeholder.append(text);
                fragment.append(placeholder);
            }

            for (const code of selectedValues) {
                const record = recordByCode.get(code) || { code, label: code, tagLabel: code };
                const locked = displayOnly || readonlyValues.includes(code);
                const tag = document.createElement('span');
                tag.className = 'w-currency-select__tag';
                tag.dataset.readonly = String(locked);
                tag.dataset.wCurrencyCode = code;
                if (multiple && !displayOnly) {
                    tag.draggable = true;
                    tag.classList.add('is-sortable');
                    tag.title = element.dataset.wReorderHint || '拖动排序';
                    const grip = document.createElement('span');
                    grip.className = 'w-currency-select__drag-handle';
                    grip.setAttribute('aria-hidden', 'true');
                    tag.append(grip);
                }
                tag.append(makeSymbol());

                const label = document.createElement('span');
                label.className = 'w-currency-select__tag-label';
                label.textContent = record.tagLabel;
                tag.append(label);

                const codeText = document.createElement('span');
                codeText.className = 'w-currency-select__code';
                codeText.textContent = code;
                tag.append(codeText);

                if (multiple && !locked) {
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'w-currency-select__remove';
                    remove.dataset.wRemoveCode = code;
                    remove.setAttribute('aria-label', `${code} ×`);
                    remove.draggable = false;
                    try {
                        if (UI?.icon?.create) {
                            remove.append(UI.icon.create('close', { size: 'xs' }));
                        } else {
                            remove.textContent = '×';
                        }
                    } catch (_error) {
                        remove.textContent = '×';
                    }
                    tag.append(remove);
                }
                fragment.append(tag);
            }
            tags.replaceChildren(fragment);
        };

        const reorderStatusEl = () => {
            const host = element.closest('[data-testid], .w-field, [style*="--w-span"]') || element.parentElement;
            return host?.querySelector?.('[data-w-currency-reorder-status]')
                || element.querySelector('[data-w-currency-reorder-status]')
                || null;
        };
        const setReorderStatus = (text, tone) => {
            const el = reorderStatusEl();
            if (!(el instanceof HTMLElement)) return;
            el.textContent = text || '';
            if (tone) el.setAttribute('data-tone', tone);
            else el.removeAttribute('data-tone');
        };
        const syncSelectOptionOrder = () => {
            const selectedSet = new Set(selectedValues);
            const byValue = new Map([...field.options].map((option) => [option.value, option]));
            for (const code of selectedValues) {
                const option = byValue.get(code);
                if (option) field.appendChild(option);
            }
            for (const option of byValue.values()) {
                if (!selectedSet.has(option.value)) field.appendChild(option);
            }
            for (const option of field.options) {
                option.selected = selectedSet.has(option.value);
            }
        };
        const saveReorder = () => {
            const reorderUrl = resolveReorderUrl();
            if (!reorderUrl || !multiple || displayOnly) return;
            const seq = ++reorderSeq;
            reorderDirty = false;
            setReorderStatus('保存中…', 'muted');
            const fd = new FormData();
            const reorderWebsiteId = resolveReorderWebsiteId();
            if (reorderWebsiteId !== '') fd.append('website_id', reorderWebsiteId);
            selectedValues.forEach((code) => fd.append('currency_codes[]', code));
            const form = field.form instanceof HTMLFormElement ? field.form : element.closest('form');
            if (form instanceof HTMLFormElement) {
                const csrf = form.querySelector('input[name="csrf"], input[name="form_key"]');
                if (csrf instanceof HTMLInputElement && csrf.value) {
                    fd.append(csrf.name || 'csrf', csrf.value);
                }
            }
            fetch(reorderUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                body: fd,
            })
                .then((res) => res.json().catch(() => null).then((payload) => ({ ok: res.ok, payload })))
                .then((result) => {
                    if (seq !== reorderSeq) return;
                    if (!result.ok || !result.payload || result.payload.success !== true) {
                        setReorderStatus((result.payload && result.payload.message) || '排序保存失败', 'danger');
                        emit('reorder-error', { ...detail(), payload: result.payload || null }, false);
                        return;
                    }
                    setReorderStatus('已保存', 'success');
                    emit('reorder-saved', { ...detail(), payload: result.payload }, false);
                })
                .catch(() => {
                    if (seq !== reorderSeq) return;
                    setReorderStatus('排序保存失败', 'danger');
                    emit('reorder-error', detail(), false);
                });
        };
        const scheduleReorderSave = () => {
            if (!resolveReorderUrl() || !multiple || displayOnly) return;
            reorderDirty = true;
            if (reorderTimer) window.clearTimeout(reorderTimer);
            reorderTimer = window.setTimeout(() => {
                reorderTimer = null;
                saveReorder();
            }, 180);
        };
        const applyTagOrder = (nextOrder, notifyReorder = true) => {
            const next = normalize(nextOrder);
            if (!sameValues(selectedValues, next) && next.length === selectedValues.length) {
                selectedValues = next;
                syncSelectOptionOrder();
                renderTags();
                element.classList.toggle('has-value', selectedValues.length > 0);
                element.dataset.hasValue = selectedValues.length > 0 ? 'true' : 'false';
                if (notifyReorder) {
                    emit('reorder', detail(), false);
                    scheduleReorderSave();
                }
                return true;
            }
            return false;
        };

        const makeOption = (record, clear = false) => {
            const code = clear ? '' : record.code;
            const selected = clear ? selectedValues.length === 0 : selectedValues.includes(code);
            const locked = !clear && (record.disabled || (readonlyValues.includes(code) && selected));
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'w-currency-select__option';
            button.dataset.wCurrencyCode = code;
            button.setAttribute('role', 'option');
            button.setAttribute('aria-selected', String(selected));
            button.disabled = locked;
            if (locked) {
                button.classList.add('is-disabled');
                button.setAttribute('aria-disabled', 'true');
            }
            try {
                if (UI?.icon?.create) {
                    button.append(UI.icon.create(selected ? 'check' : 'circle', { size: 'sm' }));
                    if (clear) button.append(UI.icon.create('minus', { size: 'sm' }));
                }
            } catch (_error) {}
            if (!clear) button.append(makeSymbol());

            const copy = document.createElement('span');
            copy.className = 'w-currency-select__option-copy';
            const strong = document.createElement('strong');
            strong.textContent = clear ? element.dataset.wEmptyText || '' : record.label;
            const small = document.createElement('small');
            small.textContent = clear ? '' : record.meta;
            copy.append(strong, small);
            button.append(copy);
            return button;
        };

        const renderList = (query = '') => {
            if (!(list instanceof HTMLElement)) return;
            const term = String(query).trim().toLocaleLowerCase();
            const filtered = records.filter((record) => term === '' || record.search.includes(term));
            const fragment = document.createDocumentFragment();

            if (!multiple && allowEmpty) fragment.append(makeOption({}, true));
            for (const record of filtered) {
                fragment.append(makeOption(record));
            }
            if (filtered.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'w-currency-select__empty';
                empty.textContent = search?.placeholder || element.dataset.wEmptyText || '';
                fragment.append(empty);
            }
            list.replaceChildren(fragment);
        };

        const notify = () => {
            syncingField = true;
            field.dispatchEvent(new Event('change', { bubbles: true }));
            syncingField = false;
            emit('change', detail(), false);
            if (element.dataset.wAutoSubmit === 'true' && field.form instanceof HTMLFormElement) {
                field.form.requestSubmit();
            }
        };

        const commit = (values, shouldNotify = true) => {
            const next = normalize(values);
            for (const code of readonlyValues) {
                if (recordByCode.has(code) && !next.includes(code)) next.push(code);
            }
            if (!multiple && next.length > 1) next.splice(1);
            const changed = !sameValues(selectedValues, next);
            selectedValues = next;
            for (const option of field.options) option.selected = selectedValues.includes(option.value);
            renderTags();
            renderList(search?.value || '');
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
            portal.restore();
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
            popover.style.setProperty('--w-currency-select-anchor-width', `${Math.round(trigger.getBoundingClientRect().width)}px`);
            popover.dataset.wFloatingPositioned = 'pending';
            portal.mount();
            popover.hidden = false;
            popover.dataset.state = 'open';
            popover.setAttribute('aria-hidden', 'false');
            trigger.setAttribute('aria-expanded', 'true');
            element.dataset.state = 'open';
            renderList(search?.value || '');
            monitor?.observe(popover);
            if (monitor?.place(reference)?.anchorVisible === false) {
                close('anchor-hidden', false, true);
                return false;
            }
            emit('open', {}, false);
            focusSearch();
            return true;
        };

        listen(trigger, 'pointerdown', (event) => {
            if (!event.isPrimary || event.button !== 0) return;
            pointerReference = floating.capture(trigger, event, trigger.dataset.wAnchorMode || 'element');
        });
        listen(trigger, 'click', (event) => {
            if (suppressTriggerClick) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }
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
        listen(trigger, 'keydown', (event) => {
            if (!open && ['ArrowDown', 'Enter', ' '].includes(event.key)) {
                event.preventDefault();
                openPopover(floating.capture(trigger));
                return;
            }
            if (!open || event.ctrlKey || event.metaKey || event.altKey) return;
            if (event.key.length !== 1 && event.key !== 'Backspace') return;
            if (!(search instanceof HTMLInputElement)) return;
            event.preventDefault();
            event.stopPropagation();
            if (event.key === 'Backspace') {
                search.value = search.value.slice(0, -1);
            } else {
                search.value += event.key;
            }
            renderList(search.value);
            focusSearch();
        });
        listen(tags, 'click', (event) => {
            const remove = event.target instanceof Element ? event.target.closest('[data-w-remove-code]') : null;
            if (!(remove instanceof HTMLButtonElement)) return;
            event.preventDefault();
            event.stopPropagation();
            commit(selectedValues.filter((value) => value !== remove.dataset.wRemoveCode));
        });
        if (multiple && !displayOnly) {
            listen(tags, 'pointerdown', (event) => {
                const tag = event.target instanceof Element ? event.target.closest('.w-currency-select__tag.is-sortable') : null;
                if (!(tag instanceof HTMLElement) || !tags.contains(tag)) return;
                if (event.target instanceof Element && event.target.closest('[data-w-remove-code]')) return;
                event.stopPropagation();
            });
            listen(tags, 'dragstart', (event) => {
                const tag = event.target instanceof Element ? event.target.closest('.w-currency-select__tag') : null;
                if (!(tag instanceof HTMLElement) || !tags.contains(tag)) return;
                if (event.target instanceof Element && event.target.closest('[data-w-remove-code]')) {
                    event.preventDefault();
                    return;
                }
                dragCode = tag.dataset.wCurrencyCode || '';
                suppressTriggerClick = true;
                tag.classList.add('is-dragging');
                try {
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', dragCode);
                } catch (_error) {}
                event.stopPropagation();
            });
            listen(tags, 'dragover', (event) => {
                if (!dragCode) return;
                event.preventDefault();
                event.stopPropagation();
                try {
                    event.dataTransfer.dropEffect = 'move';
                } catch (_error) {}
                // 禁止 mid-drag renderTags：会毁掉拖拽源；胶囊须 pointer-events:none 才能让位。
                // 优先 event.target（视口外 elementFromPoint 为 null）。
                const hit = (event.target instanceof Element ? event.target : null)
                    || document.elementFromPoint(event.clientX, event.clientY);
                const over = hit instanceof Element ? hit.closest('.w-currency-select__tag') : null;
                if (!(over instanceof HTMLElement) || !tags.contains(over)) return;
                const overCode = over.dataset.wCurrencyCode || '';
                if (!overCode || overCode === dragCode) return;
                const dragging = tags.querySelector(`.w-currency-select__tag[data-w-currency-code="${CSS.escape(dragCode)}"]`);
                if (!(dragging instanceof HTMLElement) || dragging === over) return;
                const rect = over.getBoundingClientRect();
                const before = event.clientX < rect.left + rect.width / 2;
                const ref = before ? over : over.nextSibling;
                if (ref === dragging.nextSibling && before) return;
                if (!before && over.nextSibling === dragging) return;
                tags.insertBefore(dragging, ref);
                dragging.classList.add('is-dragging');
                tags.querySelectorAll('.w-currency-select__tag.is-drop-target').forEach((node) => {
                    node.classList.remove('is-drop-target');
                });
                over.classList.add('is-drop-target');
                const next = [...tags.querySelectorAll('.w-currency-select__tag')]
                    .map((node) => node.dataset.wCurrencyCode || '')
                    .filter(Boolean);
                if (!sameValues(selectedValues, next)) {
                    selectedValues = next;
                    syncSelectOptionOrder();
                    emit('reorder', detail(), false);
                    scheduleReorderSave();
                }
            });
            listen(tags, 'drop', (event) => {
                event.preventDefault();
                event.stopPropagation();
            });
            listen(document, 'dragend', () => {
                if (!dragCode && !reorderDirty) return;
                dragCode = null;
                tags.querySelectorAll('.w-currency-select__tag.is-dragging, .w-currency-select__tag.is-drop-target').forEach((node) => {
                    node.classList.remove('is-dragging', 'is-drop-target');
                });
                if (reorderDirty) {
                    if (reorderTimer) window.clearTimeout(reorderTimer);
                    reorderTimer = null;
                    saveReorder();
                }
                suppressTriggerClick = true;
                window.setTimeout(() => {
                    suppressTriggerClick = false;
                }, 0);
            });
        }
        if (search) {
            const applySearch = () => renderList(search.value);
            listen(search, 'input', applySearch);
            listen(search, 'search', applySearch);
            listen(search, 'compositionend', applySearch);
            listen(search, 'pointerdown', (event) => event.stopPropagation());
            listen(search, 'click', (event) => event.stopPropagation());
            listen(search, 'keydown', (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    close('escape', true);
                }
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    list?.querySelector('.w-currency-select__option:not(:disabled)')?.focus();
                }
            });
        }
        if (list) {
            listen(list, 'click', (event) => {
                const option = event.target instanceof Element ? event.target.closest('[data-w-currency-code]') : null;
                if (!(option instanceof HTMLButtonElement) || option.disabled) return;
                const code = option.dataset.wCurrencyCode || '';
                if (!multiple) {
                    commit(code ? [code] : []);
                    close('select', true);
                    return;
                }
                commit(selectedValues.includes(code)
                    ? selectedValues.filter((value) => value !== code)
                    : [...selectedValues, code]);
                search?.focus({ preventScroll: true });
            });
        }
        listen(field, 'change', () => {
            if (!syncingField) commit(valuesFromField(), false);
        });
        listen(field, 'invalid', () => {
            trigger.setAttribute('aria-invalid', 'true');
            trigger.focus({ preventScroll: true });
        });
        listen(document, 'pointerdown', (event) => {
            if (!open || element.contains(event.target) || popover?.contains(event.target)) return;
            close('outside');
        });
        listen(document, 'keydown', (event) => {
            if (open && event.key === 'Escape') close('escape', true);
        });
        listen(window, 'pagehide', () => close('pagehide', false, true));
        listen(window, 'pageshow', () => close('history-restore', false, true));

        if (popover instanceof HTMLElement) {
            popover.hidden = true;
            popover.dataset.state = 'closed';
            popover.setAttribute('aria-hidden', 'true');
        }
        trigger.setAttribute('aria-expanded', 'false');
        element.dataset.state = 'closed';

        selectedValues = valuesFromField();
        commit(selectedValues, false);
        queueMicrotask(() => emit('ready', detail(), false));

        return {
            getValue: () => selectedValues[0] || '',
            getValues: () => [...selectedValues],
            getReadonlyValues: () => [...readonlyValues],
            getDetail: detail,
            setValue: (value) => commit(value ? [value] : []),
            setValues: (values) => commit(values),
            reorderValues: (values) => applyTagOrder(values),
            addValue: (value) => commit([...selectedValues, value]),
            removeValue: (value) => commit(selectedValues.filter((current) => current !== String(value).toUpperCase())),
            setReadonlyValues(values) {
                readonlyValues = normalize(values);
                element.dataset.wReadonlyValues = JSON.stringify(readonlyValues);
                commit(selectedValues);
            },
            refresh() {
                commit(valuesFromField(), false);
            },
            open: openPopover,
            close,
            element,
            field,
            destroy() {
                close('unmount', false, true);
                monitor?.destroy();
                floating.clear(popover);
                portal.destroy();
            },
        };
    });
    } catch (error) {
        if (!(error instanceof Error) || !/already (defined|registered)/i.test(error.message)) {
            throw error;
        }
    }
}
