(function () {
    'use strict';

    function debounce(fn, delay) {
        var timer = null;
        return function () {
            var args = arguments;
            var context = this;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(context, args);
            }, delay);
        };
    }

    function apiResource() {
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            return Promise.reject(new Error('Weline.Api is unavailable.'));
        }
        if (typeof window.Weline.load === 'function') {
            return window.Weline.load('api').then(function () {
                return window.Weline.Api.resource('customer_admin');
            });
        }
        return Promise.resolve(window.Weline.Api.resource('customer_admin'));
    }

    function businessResult(value) {
        var current = value;
        for (var depth = 0; depth < 3; depth += 1) {
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

    function searchCustomers(keyword, limit) {
        return apiResource().then(function (resource) {
            if (!resource || typeof resource.search !== 'function') {
                throw new Error('customer_admin.search is unavailable');
            }
            return resource.search({
                q: String(keyword || ''),
                keyword: String(keyword || ''),
                limit: limit
            }, { keepBusinessResult: true, silent: true });
        }).then(businessResult);
    }

    function resolveCustomer(customerId) {
        return apiResource().then(function (resource) {
            if (!resource || typeof resource.resolve !== 'function') {
                throw new Error('customer_admin.resolve is unavailable');
            }
            return resource.resolve({
                customer_id: customerId
            }, { keepBusinessResult: true, silent: true });
        }).then(businessResult);
    }

    function normalizeOption(row) {
        return {
            value: String(row.value != null ? row.value : row.customer_id || ''),
            label: String(row.label || row.username || row.email || row.value || ''),
            meta: String(row.meta || (row.email && row.email !== row.label ? row.email : '') || '')
        };
    }

    function readConfig(wrapper) {
        return {
            id: String(wrapper.getAttribute('data-select-id') || wrapper.id.replace(/_wrapper$/, '') || ''),
            emptyLabel: String(wrapper.getAttribute('data-empty-label') || ''),
            notFound: String(wrapper.getAttribute('data-not-found') || ''),
            allowEmpty: wrapper.getAttribute('data-allow-empty') === '1',
            clearable: wrapper.getAttribute('data-clearable') === '1',
            disabled: wrapper.getAttribute('data-disabled') === '1',
            onChangeCode: String(wrapper.getAttribute('data-on-change') || ''),
            limit: Math.max(1, Math.min(50, parseInt(wrapper.getAttribute('data-limit') || '20', 10) || 20)),
            initialDisplay: String(wrapper.getAttribute('data-initial-display') || '')
        };
    }

    function mount(config) {
        var id = String(config.id || '');
        var emptyLabel = String(config.emptyLabel || '');
        var notFound = String(config.notFound || '');
        var allowEmpty = !!config.allowEmpty;
        var clearable = !!config.clearable;
        var disabled = !!config.disabled;
        var onChangeCode = String(config.onChangeCode || '');
        var limit = Math.max(1, Math.min(50, parseInt(config.limit, 10) || 20));
        var initialDisplay = String(config.initialDisplay || '');

        var wrapper = document.getElementById(id + '_wrapper');
        var trigger = document.getElementById(id + '_trigger');
        var dropdown = document.getElementById(id + '_dropdown');
        var search = document.getElementById(id + '_search');
        var list = document.getElementById(id + '_list');
        var loading = document.getElementById(id + '_loading');
        var display = document.getElementById(id + '_display');
        var hidden = document.getElementById(id);
        var clearBtn = document.getElementById(id + '_clear');

        if (!wrapper || !trigger || !display || !hidden) {
            return;
        }

        if (disabled) {
            if (initialDisplay) {
                display.textContent = initialDisplay;
                display.classList.remove('is-empty');
            } else if (hidden.value) {
                resolveCustomer(parseInt(hidden.value, 10)).then(function (payload) {
                    var item = payload.item || null;
                    if (item) {
                        display.textContent = String(item.label || hidden.value);
                        display.classList.remove('is-empty');
                    }
                }).catch(function () {});
            }
            return;
        }

        if (!dropdown || !search || !list) {
            return;
        }

        var selected = String(hidden.value == null ? '' : hidden.value);
        var open = false;
        var requestToken = 0;

        function syncDisplay(label) {
            var text = String(label || '');
            if (text) {
                display.textContent = text;
                display.classList.remove('is-empty');
            } else if (selected === '') {
                display.textContent = emptyLabel;
                display.classList.add('is-empty');
            } else {
                display.textContent = selected;
                display.classList.remove('is-empty');
            }
            if (clearBtn) {
                clearBtn.hidden = !(clearable && selected !== '');
            }
        }

        function fireChange() {
            try {
                hidden.dispatchEvent(new Event('input', { bubbles: true }));
                hidden.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (e) {}
            if (!onChangeCode) {
                return;
            }
            try {
                (new Function(onChangeCode))();
            } catch (e) {
                console.error(e);
            }
        }

        function setValue(value, label, fire) {
            selected = String(value == null ? '' : value);
            hidden.value = selected;
            syncDisplay(label || (selected !== '' ? selected : ''));
            if (fire) {
                fireChange();
            }
        }

        function renderList(options) {
            list.innerHTML = '';
            var normalized = Array.isArray(options) ? options.map(normalizeOption) : [];

            if (allowEmpty) {
                var emptyItem = document.createElement('button');
                emptyItem.type = 'button';
                emptyItem.className = 'weline-code-select-item' + (selected === '' ? ' is-selected' : '');
                emptyItem.innerHTML = '<span class="weline-code-select-item-label"></span>';
                emptyItem.querySelector('.weline-code-select-item-label').textContent = emptyLabel;
                emptyItem.addEventListener('click', function () {
                    setValue('', '', true);
                    close();
                });
                list.appendChild(emptyItem);
            }

            if (!normalized.length) {
                var empty = document.createElement('div');
                empty.className = 'weline-code-select-empty';
                empty.textContent = notFound;
                list.appendChild(empty);
                return;
            }

            normalized.forEach(function (item) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'weline-code-select-item' + (String(item.value) === selected ? ' is-selected' : '');
                btn.innerHTML = '<span class="weline-code-select-item-label"></span>'
                    + (item.meta ? '<span class="weline-code-select-item-meta"></span>' : '');
                btn.querySelector('.weline-code-select-item-label').textContent = item.label;
                if (item.meta) {
                    btn.querySelector('.weline-code-select-item-meta').textContent = item.meta;
                }
                btn.addEventListener('click', function () {
                    setValue(item.value, item.label, true);
                    close();
                });
                list.appendChild(btn);
            });
        }

        function fetchList(keyword) {
            var token = ++requestToken;
            if (loading) {
                loading.hidden = false;
            }
            list.innerHTML = '';
            return searchCustomers(keyword, limit).then(function (payload) {
                if (token !== requestToken) {
                    return;
                }
                if (loading) {
                    loading.hidden = true;
                }
                renderList(payload.items || []);
            }).catch(function () {
                if (token !== requestToken) {
                    return;
                }
                if (loading) {
                    loading.hidden = true;
                }
                list.innerHTML = '<div class="weline-code-select-empty">' + notFound + '</div>';
            });
        }

        var debouncedFetch = debounce(function (keyword) {
            fetchList(keyword);
        }, 280);

        function openDropdown() {
            if (open || disabled) {
                return;
            }
            open = true;
            wrapper.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
            dropdown.hidden = false;
            fetchList(search.value);
            if (window.WelineTaglibFloatingDropdown) {
                window.WelineTaglibFloatingDropdown.mount(trigger, dropdown, {
                    minWidth: Math.max(trigger.getBoundingClientRect().width, 240),
                    preferredHeight: 320,
                    zIndex: 4200
                });
            }
            setTimeout(function () {
                search.focus();
            }, 0);
        }

        function close() {
            if (!open) {
                return;
            }
            open = false;
            wrapper.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
            dropdown.hidden = true;
            if (window.WelineTaglibFloatingDropdown) {
                window.WelineTaglibFloatingDropdown.unmount(dropdown);
            }
        }

        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            if (open) {
                close();
            } else {
                openDropdown();
            }
        });

        search.addEventListener('input', function () {
            debouncedFetch(search.value);
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                setValue('', '', true);
                close();
            });
        }

        document.addEventListener('click', function (e) {
            if (!open) {
                return;
            }
            if (wrapper.contains(e.target) || dropdown.contains(e.target)) {
                return;
            }
            close();
        });

        window.addEventListener('resize', function () {
            if (open && window.WelineTaglibFloatingDropdown) {
                window.WelineTaglibFloatingDropdown.mount(trigger, dropdown, {
                    minWidth: Math.max(trigger.getBoundingClientRect().width, 240),
                    preferredHeight: 320,
                    zIndex: 4200
                });
            }
        });

        window.addEventListener('scroll', function () {
            if (open) {
                close();
            }
        }, true);

        if (initialDisplay) {
            syncDisplay(initialDisplay);
        } else if (selected !== '') {
            resolveCustomer(parseInt(selected, 10)).then(function (payload) {
                var item = payload.item || null;
                if (item) {
                    syncDisplay(String(item.label || selected));
                } else {
                    syncDisplay(selected);
                }
            }).catch(function () {
                syncDisplay(selected);
            });
        } else {
            syncDisplay('');
        }
    }

    window.WelineCustomerAdminSelect = window.WelineCustomerAdminSelect || {};
    window.WelineCustomerAdminSelect.mount = mount;

    function boot() {
        document.querySelectorAll('[data-customer-admin-select]').forEach(function (wrapper) {
            mount(readConfig(wrapper));
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
