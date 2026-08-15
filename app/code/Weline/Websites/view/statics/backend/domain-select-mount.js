/**
 * DomainSelect mount contract: sub_path UI, conflict styling, getValue()/isValid().
 * Activated for wrappers with data-with-sub-path="true".
 */
(function (global) {
    'use strict';

    if (global.WelineDomainSelectMount) {
        return;
    }

    var REGISTRY = Object.create(null);
    var CONFLICT_URL_DEFAULT = '';

    function normalizeSubPath(value) {
        var raw = String(value || '').trim();
        if (!raw || raw === '/') {
            return '';
        }
        if (raw.indexOf('://') !== -1) {
            try {
                raw = new URL(raw).pathname || '';
            } catch (_e) {
                raw = '';
            }
        }
        var hostPath = raw.match(/^[a-z0-9.-]+\.[a-z]{2,}(\/.*)$/i);
        if (hostPath) {
            raw = hostPath[1] || '';
        }
        raw = raw.replace(/^\/+/, '').replace(/\/+$/, '');
        if (!raw) {
            return '';
        }
        if (!/^(?:[A-Za-z0-9][A-Za-z0-9_-]{0,62})(?:\/(?:[A-Za-z0-9][A-Za-z0-9_-]{0,62})){0,4}$/.test(raw)) {
            return '';
        }
        var first = String(raw.split('/')[0] || '').toLowerCase();
        var reserved = {
            static: 1, pub: 1, media: 1, api: 1, admin: 1,
            'favicon.ico': 1, 'robots.txt': 1, 'sitemap.xml': 1
        };
        if (reserved[first] || /^[a-z]{2}(?:[_-][a-z0-9]{2,8}){1,2}$/.test(first)) {
            return '';
        }
        return '/' + raw;
    }

    function texts(wrapper) {
        return {
            localBadge: wrapper.getAttribute('data-text-local') || '本地域名',
            boundBadge: wrapper.getAttribute('data-text-bound') || '已占用',
            suggestPath: wrapper.getAttribute('data-text-suggest-path')
                || '该域名已被站点「%{name}」使用，请填写子路径以共用此域名。',
            pathConflict: wrapper.getAttribute('data-text-path-conflict')
                || '该地址已被站点「%{name}」使用。',
            pathInvalid: wrapper.getAttribute('data-text-path-invalid') || '子路径格式无效或使用了保留字。',
            mountPreview: wrapper.getAttribute('data-text-mount-preview') || '站点地址：%{url}',
            mountEmpty: wrapper.getAttribute('data-text-mount-empty') || '请选择域名。',
            addLocal: wrapper.getAttribute('data-text-add-local') || '添加本地域名',
            addPurchase: wrapper.getAttribute('data-text-add-purchase') || '购买正式域名'
        };
    }

    function format(template, map) {
        return String(template || '').replace(/%\{(\w+)\}/g, function (_m, key) {
            return map && map[key] != null ? String(map[key]) : '';
        });
    }

    function ensureUi(wrapper, id) {
        if (wrapper.querySelector('[data-domain-select-sub-path]')) {
            return;
        }
        var t = texts(wrapper);
        var block = document.createElement('div');
        block.className = 'weline-domain-select-mount';
        block.setAttribute('data-domain-select-sub-path', 'true');
        block.innerHTML = [
            '<label class="weline-domain-select-mount__label" for="' + id + '_sub_path">',
            (wrapper.getAttribute('data-text-sub-path-label') || '网站子路径（可选）'),
            '</label>',
            '<div class="weline-domain-select-mount__row">',
            '<span class="weline-domain-select-mount__prefix" aria-hidden="true">/</span>',
            '<input type="text" class="weline-domain-select-mount__input" id="' + id + '_sub_path" ',
            'name="' + (wrapper.getAttribute('data-sub-path-name') || 'sub_path') + '" ',
            'autocomplete="off" spellcheck="false" ',
            'placeholder="' + (wrapper.getAttribute('data-text-sub-path-placeholder') || '留空使用整域，例如 shop') + '">',
            '</div>',
            '<input type="hidden" id="' + id + '_mount_url" name="' + (wrapper.getAttribute('data-mount-url-name') || 'mount_url') + '" value="">',
            '<input type="hidden" id="' + id + '_domain_source" name="' + (wrapper.getAttribute('data-domain-source-name') || 'domain_source') + '" value="pool">',
            '<input type="hidden" id="' + id + '_is_local" name="' + (wrapper.getAttribute('data-is-local-name') || 'is_local') + '" value="0">',
            '<input type="hidden" id="' + id + '_valid" name="' + (wrapper.getAttribute('data-valid-name') || 'domain_select_valid') + '" value="0">',
            '<small class="weline-domain-select-mount__help" data-mount-help></small>',
            '<small class="weline-domain-select-mount__preview" data-mount-preview role="status" aria-live="polite"></small>'
        ].join('');
        wrapper.appendChild(block);

        if (!document.getElementById('weline-domain-select-mount-style')) {
            var style = document.createElement('style');
            style.id = 'weline-domain-select-mount-style';
            style.textContent = [
                '.weline-domain-select.is-conflict .weline-domain-select-trigger{border-color:var(--backend-color-danger,#dc3545)!important;box-shadow:0 0 0 .15rem rgba(220,53,69,.2);}',
                '.weline-domain-select-mount{margin-top:.65rem;}',
                '.weline-domain-select-mount__label{display:block;font-size:.875rem;margin-bottom:.25rem;}',
                '.weline-domain-select-mount__row{display:flex;align-items:stretch;}',
                '.weline-domain-select-mount__prefix{display:inline-flex;align-items:center;padding:0 .65rem;border:1px solid var(--backend-color-border-default,#dee2e6);border-right:0;border-radius:8px 0 0 8px;background:var(--backend-color-bg-secondary,#f8f9fa);font-weight:600;color:var(--backend-color-text-secondary,#6c757d);}',
                '.weline-domain-select-mount__input{flex:1;min-width:0;border:1px solid var(--backend-color-border-default,#dee2e6);border-radius:0 8px 8px 0;padding:.45rem .75rem;}',
                '.weline-domain-select-mount__input.is-conflict{border-color:var(--backend-color-danger,#dc3545)!important;box-shadow:0 0 0 .15rem rgba(220,53,69,.15);}',
                '.weline-domain-select-mount__help{display:block;margin-top:.3rem;color:var(--backend-color-danger,#dc3545);font-size:.75rem;min-height:1em;}',
                '.weline-domain-select-mount__preview{display:block;margin-top:.2rem;color:var(--backend-color-text-secondary,#6c757d);font-size:.75rem;}',
                '.weline-domain-select-badge{display:inline-block;margin-left:.35rem;padding:.05rem .35rem;border-radius:999px;font-size:.7rem;font-weight:600;vertical-align:middle;}',
                '.weline-domain-select-badge--local{background:rgba(13,110,253,.12);color:var(--backend-color-info,#0d6efd);}',
                '.weline-domain-select-badge--bound{background:rgba(220,53,69,.12);color:var(--backend-color-danger,#dc3545);}'
            ].join('');
            document.head.appendChild(style);
        }
        void t;
    }

    function readDomain(wrapper, id) {
        var hidden = document.getElementById(id + '_value');
        if (!hidden) {
            return {domain: '', pool_id: 0};
        }
        var domain = String(hidden.dataset.domain || '').trim().toLowerCase();
        if (!domain && wrapper.getAttribute('data-value-type') !== 'pool_id') {
            domain = String(hidden.value || '').trim().toLowerCase();
        }
        var poolId = parseInt(hidden.dataset.poolid || '0', 10) || 0;
        return {domain: domain, pool_id: poolId};
    }

    function buildContract(state) {
        var domain = String(state.domain || '').trim().toLowerCase();
        var subPath = normalizeSubPath(state.sub_path);
        var mountUrl = domain ? ('https://' + domain + subPath) : '';
        return {
            domain: domain,
            pool_id: state.pool_id || 0,
            sub_path: subPath,
            mount_url: mountUrl,
            is_local: !!state.is_local,
            domain_source: state.domain_source || 'pool',
            valid: !!state.valid,
            domain_conflict: !!state.domain_conflict,
            sub_path_conflict: !!state.sub_path_conflict,
            conflict_website_name: state.conflict_website_name || '',
            path_invalid: !!state.path_invalid
        };
    }

    function applyUi(api, contract, message) {
        var wrapper = api.wrapper;
        var input = api.subPathInput;
        var help = wrapper.querySelector('[data-mount-help]');
        var preview = wrapper.querySelector('[data-mount-preview]');
        var t = texts(wrapper);
        wrapper.classList.toggle('is-conflict', !!(contract.domain_conflict && !contract.sub_path) || (!contract.valid && !!contract.domain && !contract.sub_path));
        if (input) {
            input.classList.toggle('is-conflict', !!(contract.sub_path_conflict || contract.path_invalid));
        }
        if (help) {
            help.textContent = message || '';
        }
        if (preview) {
            preview.textContent = contract.domain
                ? format(t.mountPreview, {url: contract.mount_url})
                : t.mountEmpty;
        }
        var validEl = document.getElementById(api.id + '_valid');
        var mountEl = document.getElementById(api.id + '_mount_url');
        var localEl = document.getElementById(api.id + '_is_local');
        var sourceEl = document.getElementById(api.id + '_domain_source');
        if (validEl) validEl.value = contract.valid ? '1' : '0';
        if (mountEl) mountEl.value = contract.mount_url || '';
        if (localEl) localEl.value = contract.is_local ? '1' : '0';
        if (sourceEl) sourceEl.value = contract.domain_source || 'pool';
        wrapper.dataset.mountValid = contract.valid ? '1' : '0';
        try {
            wrapper.dispatchEvent(new CustomEvent('weline-domain-select:change', {
                bubbles: true,
                detail: contract
            }));
        } catch (_e) {}
        if (typeof api.onChange === 'function') {
            api.onChange(contract);
        }
    }

    function scheduleValidate(api) {
        if (api._timer) {
            clearTimeout(api._timer);
        }
        api._timer = setTimeout(function () {
            void validate(api);
        }, 220);
    }

    function validate(api) {
        var t = texts(api.wrapper);
        var picked = readDomain(api.wrapper, api.id);
        var typed = api.subPathInput ? String(api.subPathInput.value || '') : '';
        var normalized = normalizeSubPath(typed);
        var pathLooksInvalid = typed.trim() !== '' && typed.trim() !== '/' && normalized === '';
        api.state.domain = picked.domain;
        api.state.pool_id = picked.pool_id;
        api.state.sub_path = normalized;
        if (api.metaByDomain && api.metaByDomain[picked.domain]) {
            api.state.is_local = !!api.metaByDomain[picked.domain].is_local;
        }

        if (!picked.domain) {
            api.state.valid = false;
            api.state.domain_conflict = false;
            api.state.sub_path_conflict = false;
            api.state.path_invalid = false;
            applyUi(api, buildContract(api.state), '');
            return Promise.resolve(buildContract(api.state));
        }

        if (pathLooksInvalid) {
            api.state.valid = false;
            api.state.path_invalid = true;
            api.state.domain_conflict = false;
            api.state.sub_path_conflict = false;
            applyUi(api, buildContract(api.state), t.pathInvalid);
            return Promise.resolve(buildContract(api.state));
        }

        var conflictUrl = api.wrapper.getAttribute('data-conflict-url') || CONFLICT_URL_DEFAULT;
        if (!conflictUrl) {
            api.state.valid = true;
            api.state.path_invalid = false;
            applyUi(api, buildContract(api.state), '');
            return Promise.resolve(buildContract(api.state));
        }

        var exclude = api.wrapper.getAttribute('data-website-id') || '0';
        var url = conflictUrl
            + (conflictUrl.indexOf('?') >= 0 ? '&' : '?')
            + 'domain=' + encodeURIComponent(picked.domain)
            + '&sub_path=' + encodeURIComponent(normalized)
            + '&exclude_website_id=' + encodeURIComponent(exclude);

        var request = (global.bqAdmin && typeof global.bqAdmin.websites === 'function')
            ? global.bqAdmin.websites(url, { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            : (global.Weline && global.Weline.load
                ? global.Weline.load('api').then(function (api) {
                    return api.resource('websites').adminRequest({
                        url: url,
                        method: 'GET',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: ''
                    });
                }).then(function (data) {
                    var biz = global.WelineApiBusiness || (global.Weline && global.Weline.ApiBusiness);
                    if (biz && typeof biz.wrapAdminBridgeResult === 'function') {
                        return biz.wrapAdminBridgeResult(data);
                    }
                    return {
                        json: function () {
                            return Promise.resolve(data && typeof data === 'object' ? data : { success: true, data: data });
                        }
                    };
                })
                : Promise.reject(new Error('Weline.Api unavailable')));

        return request
            .then(function (r) { return typeof r.json === 'function' ? r.json() : r; })
            .then(function (res) {
                var data = (res && res.data) || {};
                api.state.domain_conflict = !!data.domain_conflict;
                api.state.sub_path_conflict = !!data.sub_path_conflict;
                api.state.path_invalid = !!data.path_invalid;
                api.state.conflict_website_name = data.conflict_website_name || '';
                api.state.sub_path = data.sub_path != null ? String(data.sub_path) : normalized;
                api.state.valid = !!data.valid;
                var message = '';
                if (api.state.path_invalid) {
                    message = data.path_invalid_reason || t.pathInvalid;
                } else if (api.state.sub_path_conflict) {
                    message = format(t.pathConflict, {name: api.state.conflict_website_name || '-'});
                } else if (api.state.domain_conflict && !api.state.sub_path) {
                    message = format(t.suggestPath, {name: api.state.conflict_website_name || '-'});
                }
                applyUi(api, buildContract(api.state), message);
                return buildContract(api.state);
            })
            .catch(function () {
                api.state.valid = false;
                applyUi(api, buildContract(api.state), t.pathInvalid);
                return buildContract(api.state);
            });
    }

    function enhanceItemBadges(listEl, t) {
        if (!listEl) return;
        listEl.querySelectorAll('.weline-domain-select-item').forEach(function (el) {
            if (el.querySelector('.weline-domain-select-badge')) return;
            var isLocal = el.getAttribute('data-is-local') === '1';
            var siteCreated = el.getAttribute('data-site-created') === '1';
            var html = '';
            if (isLocal) {
                html += '<span class="weline-domain-select-badge weline-domain-select-badge--local">' + t.localBadge + '</span>';
            }
            if (siteCreated) {
                html += '<span class="weline-domain-select-badge weline-domain-select-badge--bound">' + t.boundBadge + '</span>';
            }
            if (html) {
                el.insertAdjacentHTML('beforeend', html);
            }
        });
    }

    function register(id, options) {
        options = options || {};
        var wrapper = document.getElementById(id + '_wrapper');
        if (!wrapper) {
            return null;
        }
        if (REGISTRY[id]) {
            return REGISTRY[id];
        }
        if (wrapper.getAttribute('data-with-sub-path') !== 'true') {
            return null;
        }
        ensureUi(wrapper, id);
        var api = {
            id: id,
            wrapper: wrapper,
            subPathInput: document.getElementById(id + '_sub_path'),
            onChange: options.onChange || null,
            metaByDomain: Object.create(null),
            state: {
                domain: '',
                pool_id: 0,
                sub_path: '',
                is_local: false,
                domain_source: 'pool',
                valid: false,
                domain_conflict: false,
                sub_path_conflict: false,
                conflict_website_name: '',
                path_invalid: false
            },
            getValue: function () { return buildContract(api.state); },
            isValid: function () { return !!api.state.valid; },
            setDomainMeta: function (domain, meta) {
                if (!domain) return;
                api.metaByDomain[String(domain).toLowerCase()] = meta || {};
            },
            setDomainSource: function (source) {
                api.state.domain_source = source || 'pool';
                var sourceEl = document.getElementById(api.id + '_domain_source');
                if (sourceEl) {
                    sourceEl.value = api.state.domain_source;
                }
            },
            hydrate: function (partial) {
                partial = partial || {};
                var hiddenEl = document.getElementById(api.id + '_value');
                var displayEl = document.getElementById(api.id + '_display');
                var domain = String(partial.domain || '').trim().toLowerCase();
                if (domain && hiddenEl) {
                    hiddenEl.value = domain;
                    hiddenEl.dataset.domain = domain;
                    if (partial.pool_id) {
                        hiddenEl.dataset.poolid = String(partial.pool_id);
                    }
                    if (displayEl) {
                        displayEl.textContent = domain;
                    }
                }
                if (api.subPathInput && Object.prototype.hasOwnProperty.call(partial, 'sub_path')) {
                    var n = normalizeSubPath(partial.sub_path);
                    api.subPathInput.value = n ? n.replace(/^\//, '') : '';
                }
                if (partial.domain_source) {
                    api.setDomainSource(partial.domain_source);
                }
                if (Object.prototype.hasOwnProperty.call(partial, 'is_local')) {
                    api.state.is_local = !!partial.is_local;
                    if (domain) {
                        api.setDomainMeta(domain, {
                            is_local: api.state.is_local,
                            pool_id: partial.pool_id || 0
                        });
                    }
                }
                return validate(api);
            },
            refresh: function () { return validate(api); },
            enhanceList: function (listEl) { enhanceItemBadges(listEl, texts(wrapper)); }
        };
        REGISTRY[id] = api;

        var hidden = document.getElementById(id + '_value');
        if (hidden) {
            hidden.addEventListener('change', function () { scheduleValidate(api); });
        }
        if (api.subPathInput) {
            api.subPathInput.addEventListener('input', function () { scheduleValidate(api); });
            api.subPathInput.addEventListener('blur', function () {
                var n = normalizeSubPath(api.subPathInput.value);
                api.subPathInput.value = n ? n.replace(/^\//, '') : '';
                scheduleValidate(api);
            });
        }
        scheduleValidate(api);
        return api;
    }

    function get(id) {
        return REGISTRY[id] || null;
    }

    global.WelineDomainSelectMount = {
        register: register,
        get: get,
        getValue: function (id) {
            var api = get(id);
            return api ? api.getValue() : null;
        },
        isValid: function (id) {
            var api = get(id);
            return api ? api.isValid() : false;
        },
        hydrate: function (id, partial) {
            var api = get(id);
            return api && api.hydrate ? api.hydrate(partial) : Promise.resolve(null);
        },
        normalizeSubPath: normalizeSubPath
    };
})(typeof window !== 'undefined' ? window : globalThis);
