/* Weline UI source: js/language-switcher.js */
function cookieNames(explicitWebsiteId = '') {
    const names = [];
    const add = (name) => {
        const value = String(name || '').trim();
        if (value && !names.includes(value)) names.push(value);
    };
    const websiteId = explicitWebsiteId || window.site?.website_id || window.site?.websiteId || '';
    if (String(websiteId) !== '') add(`WELINE_USER_LANG_w${websiteId}`);
    // Keep already-scoped site cookies in sync when panel is portaled / site id missing.
    String(document.cookie || '').split(';').forEach((part) => {
        const key = String(part.split('=')[0] || '').trim();
        if (/^WELINE_USER_LANG_w\d+$/.test(key)) add(key);
    });
    add('WELINE_USER_LANG');
    return names;
}

function expireCookieVariants(name) {
    const key = String(name || '').trim();
    if (!key) return;
    const past = 'Thu, 01 Jan 1970 00:00:00 GMT';
    const host = String(window.location.hostname || '').trim();
    // Clear host-only and Domain=host copies; Domain cookies are not overwritten by host-only writes.
    document.cookie = `${key}=;expires=${past};path=/;SameSite=Lax`;
    if (host) {
        document.cookie = `${key}=;expires=${past};path=/;domain=${host};SameSite=Lax`;
        if (!host.startsWith('.') && host.includes('.')) {
            document.cookie = `${key}=;expires=${past};path=/;domain=.${host};SameSite=Lax`;
        }
    }
}

function writeLanguagePreference(locale, websiteId = '') {
    try {
        localStorage.setItem('weline_user_lang', locale);
        localStorage.removeItem('api_doc_locale');
        localStorage.removeItem('WELINE_USER_LANG');
    } catch (_error) {
    }
    const expires = new Date(Date.now() + 365 * 24 * 60 * 60 * 1000).toUTCString();
    cookieNames(websiteId).forEach((name) => {
        expireCookieVariants(name);
        document.cookie = `${name}=${encodeURIComponent(locale)};expires=${expires};path=/;SameSite=Lax`;
    });
}

function shortLocale(locale) {
    const parts = String(locale || '').replaceAll('-', '_').split('_');
    const language = String(parts[0] || '').toUpperCase();
    const region = String(parts[1] || '').toUpperCase();
    if (language === 'ZH') return region === 'HANT' ? 'TW' : 'ZH';
    return language.slice(0, 2);
}

const LOCALE_PATH_PATTERN = /^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/i;
const CURRENCY_PATH_PATTERN = /^[A-Z]{3}$/;

/**
 * Rebuild pathname with a new locale while keeping backend prefix / currency / page.
 * Backend chrome is cached across routes; option.href may point at a stale page
 * (e.g. /dev/tool/docs). Always prefer the live location when swapping language.
 */
function rebuildPathWithLocale(pathname, locale, websiteMount = '') {
    const targetLang = String(locale || '').trim();
    if (!targetLang || !LOCALE_PATH_PATTERN.test(targetLang)) {
        return String(pathname || '/') || '/';
    }
    const mount = String(websiteMount || '').replace(/^\/+|\/+$/g, '');
    const parts = String(pathname || '/')
        .split('/')
        .filter(Boolean);
    const remain = [];
    let currency = '';
    for (const part of parts) {
        if (LOCALE_PATH_PATTERN.test(part)) {
            continue;
        }
        if (CURRENCY_PATH_PATTERN.test(part) && currency === '') {
            currency = part.toUpperCase();
            continue;
        }
        if (mount !== '' && remain.length === 0 && part.toLowerCase() === mount.toLowerCase()) {
            // Defer mount to explicit out placement.
            continue;
        }
        remain.push(part);
    }
    const out = [];
    if (mount !== '') {
        out.push(mount);
    } else if (remain.length > 0) {
        const maybeBackend = remain[0];
        // Opaque backend area key (not a short route token like "dev" / "media").
        if (/^[A-Za-z0-9_-]{16,}$/.test(maybeBackend)) {
            out.push(remain.shift());
        }
    }
    if (currency) {
        out.push(currency);
    }
    out.push(targetLang);
    out.push(...remain);
    return `/${out.join('/')}`;
}

function normalizeLangCode(value) {
    return String(value || '').trim().replace(/-/g, '_');
}

function sameLang(a, b) {
    const left = normalizeLangCode(a).toLowerCase();
    const right = normalizeLangCode(b).toLowerCase();
    return left !== '' && right !== '' && left === right;
}

function pathLocale(pathname) {
    for (const part of String(pathname || '/').split('/').filter(Boolean)) {
        if (LOCALE_PATH_PATTERN.test(part)) {
            return part;
        }
    }
    return '';
}

/**
 * Resolve navigation target for a language option.
 * Prefer window.urlWithLang (backend) / live pathname rebuild over option.href,
 * because chrome partial cache intentionally omits the route from its key.
 */
function resolveLanguageNavigationHref(locale, optionHref, websiteMount = '') {
    const lang = String(locale || '').trim();
    if (!lang) {
        return String(optionHref || '').trim();
    }

    let hrefFromRebuild = '';
    try {
        const rebuiltPath = rebuildPathWithLocale(window.location.pathname, lang, websiteMount);
        hrefFromRebuild = new URL(
            `${rebuiltPath}${window.location.search || ''}`,
            window.location.href,
        ).href;
    } catch (_error) {
    }

    let hrefFromUrlWithLang = '';
    try {
        if (typeof window.urlWithLang === 'function') {
            const built = String(window.urlWithLang(
                `${window.location.pathname}${window.location.search}`,
                lang,
            ) || '').trim();
            if (built) {
                hrefFromUrlWithLang = new URL(built, window.location.href).href;
            }
        }
    } catch (_error) {
    }

    const effectiveLang = pathLocale(window.location.pathname)
        || document.documentElement.getAttribute('data-lang')
        || '';
    const localeChanging = effectiveLang !== '' && !sameLang(effectiveLang, lang);

    if (hrefFromUrlWithLang && hrefFromRebuild && localeChanging) {
        try {
            const omittedPath = new URL(hrefFromUrlWithLang).pathname;
            const explicitPath = new URL(hrefFromRebuild).pathname;
            if (omittedPath !== explicitPath) {
                return hrefFromRebuild;
            }
        } catch (_error) {
        }
    }

    if (hrefFromUrlWithLang) {
        return hrefFromUrlWithLang;
    }
    if (hrefFromRebuild) {
        return hrefFromRebuild;
    }
    return String(optionHref || '').trim();
}

/**
 * Chrome partial cache reuses the topbar across routes, so option.href may still
 * point at an older page. Rewrite live options from the current location before
 * any click/default navigation can leave the page.
 */
function refreshLanguageOptionHrefs(root, panel) {
    const mount = root instanceof HTMLElement ? (root.dataset.websiteMount || '') : '';
    const scopes = [];
    if (panel instanceof HTMLElement) scopes.push(panel);
    if (root instanceof HTMLElement) scopes.push(root);
    const seen = new Set();
    scopes.forEach((scope) => {
        scope.querySelectorAll('[data-language-option][data-lang]').forEach((option) => {
            if (!(option instanceof HTMLAnchorElement) || seen.has(option)) return;
            seen.add(option);
            const locale = String(option.dataset.lang || '').trim();
            if (!locale) return;
            const href = resolveLanguageNavigationHref(
                locale,
                option.getAttribute('href') || '',
                mount,
            );
            if (!href) return;
            try {
                const absolute = new URL(href, window.location.href);
                option.setAttribute('href', `${absolute.pathname}${absolute.search}${absolute.hash}`);
            } catch (_error) {
                option.setAttribute('href', href);
            }
        });
    });
}

function trustedFragment(markup) {
    const template = document.createElement('template');
    template.innerHTML = String(markup || '');
    return template.content.cloneNode(true);
}

/**
 * Scripts inserted via innerHTML / template fragments never run. Re-insert
 * server-trusted scripts (captcha / form runtime) so providers can bind.
 */
function activateTrustedScripts(root) {
    if (!(root instanceof HTMLElement)) return;
    root.querySelectorAll('script').forEach((oldScript) => {
        const script = document.createElement('script');
        for (const attribute of oldScript.attributes) {
            script.setAttribute(attribute.name, attribute.value);
        }
        script.textContent = oldScript.textContent || '';
        oldScript.replaceWith(script);
    });
}

function shellMessage(shell, key, fallback) {
    if (!(shell instanceof HTMLElement)) return fallback;
    const value = String(shell.getAttribute(`data-${key}`) || '').trim();
    return value || fallback;
}

function showRequestFeedback(form, message, tone = 'muted') {
    const feedback = form?.querySelector?.('[data-language-request-feedback]');
    if (!(feedback instanceof HTMLElement)) return;
    feedback.textContent = String(message || '');
    feedback.className = 'w-text';
    if (tone === 'error' || tone === 'danger') feedback.dataset.tone = 'danger';
    else if (tone === 'success') feedback.dataset.tone = 'success';
    else feedback.dataset.tone = 'muted';
}

function notifyRequest(UI, message, tone = 'neutral') {
    const text = String(message || '').trim();
    if (!text) return;
    try {
        if (tone === 'success') UI.toast?.success?.(text);
        else if (tone === 'error' || tone === 'danger') UI.toast?.error?.(text);
        else UI.toast?.show?.(text, { tone: 'neutral' });
    } catch (_error) {
    }
}

function collectLocales(form) {
    const data = new FormData(form);
    const fromFields = data.getAll('locales')
        .map((value) => String(value || '').trim())
        .filter(Boolean);
    if (fromFields.length > 0) return fromFields;
    const select = form.querySelector('[data-w-component~="language-select"]');
    const hidden = select?.querySelectorAll?.('input[name="locales"], input[name="locales[]"]');
    if (!hidden?.length) return [];
    return Array.from(hidden)
        .map((input) => String(input.value || '').trim())
        .filter(Boolean);
}

function bindLanguageRequestForm(UI, form, requestDialog) {
    if (!(form instanceof HTMLFormElement) || form.dataset.languageRequestBound === '1') return;
    form.dataset.languageRequestBound = '1';
    const shell = form.closest('[data-language-request-form-shell]') || form;
    const submit = form.querySelector('[type=submit]');
    const maxLocales = Math.max(1, Number.parseInt(String(shell.getAttribute('data-max-locales') || '20'), 10) || 20);

    form.addEventListener('weline:form:verification-error', () => {
        const message = shellMessage(shell, 'msg-captcha-fail', '人机验证加载失败，请稍后重试');
        showRequestFeedback(form, message, 'danger');
        notifyRequest(UI, message, 'error');
        if (submit instanceof HTMLButtonElement) submit.disabled = false;
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        // Captcha providers cancel prepare-submit and set pending, then
        // requestSubmit() after the token lands. Stay out of that first pass.
        if (form.dataset.welineCaptchaPending === '1') return;

        const data = new FormData(form);
        const firstName = String(data.get('first_name') || '').trim();
        const lastName = String(data.get('last_name') || '').trim();
        if (!firstName || !lastName) {
            const message = shellMessage(shell, 'msg-name-required', '请填写名和姓');
            showRequestFeedback(form, message, 'danger');
            notifyRequest(UI, message, 'error');
            return;
        }

        const locales = collectLocales(form);
        if (locales.length < 1 || locales.length > maxLocales) {
            const message = shellMessage(shell, 'msg-locales-required', '请选择 1 到 20 种语言');
            showRequestFeedback(form, message, 'danger');
            notifyRequest(UI, message, 'error');
            return;
        }

        const payload = {
            first_name: firstName,
            last_name: lastName,
            name: [firstName, lastName].filter(Boolean).join(' '),
            email: String(data.get('email') || ''),
            locales,
            captcha_provider: String(data.get('captcha_provider') || ''),
            captcha_token: String(data.get('captcha_token') || ''),
            captcha_response: String(data.get('captcha_response') || ''),
            captcha_action: String(data.get('captcha_action') || ''),
        };

        if (submit instanceof HTMLButtonElement) submit.disabled = true;
        showRequestFeedback(form, shellMessage(shell, 'msg-submitting', '正在提交...'), 'muted');

        Promise.resolve(window.Weline?.load?.('api'))
            .then(() => Promise.resolve(window.Weline?.Api?.resource?.('i18n_language_requests')))
            .then((api) => {
                if (!api?.submitLanguageSupportRequest) {
                    throw new Error('Weline.Api unavailable');
                }
                return api.submitLanguageSupportRequest(payload);
            })
            .then((result) => {
                if (!result || result.success === false) {
                    throw new Error(result?.message || shellMessage(shell, 'msg-fail', '提交失败，请稍后重试'));
                }
                const message = result.message || shellMessage(shell, 'msg-success', '语言支持申请已提交');
                showRequestFeedback(form, message, 'success');
                notifyRequest(UI, message, 'success');
                form.dispatchEvent(new CustomEvent('weline:i18n-language-request:submitted', {
                    bubbles: true,
                    detail: result,
                }));
                if (requestDialog instanceof HTMLDialogElement && result.duplicate !== true) {
                    window.setTimeout(() => {
                        try {
                            UI.dialog?.close?.(requestDialog);
                        } catch (_error) {
                        }
                    }, 1200);
                }
            })
            .catch((error) => {
                delete form.dataset.welineCaptchaVerified;
                const message = error instanceof Error && error.message
                    ? error.message
                    : shellMessage(shell, 'msg-fail', '提交失败，请稍后重试');
                showRequestFeedback(form, message, 'danger');
                notifyRequest(UI, message, 'error');
                if (payload.captcha_provider === 'local_image') {
                    form.dispatchEvent(new CustomEvent('weline:captcha:refresh-requested', {
                        bubbles: true,
                        detail: {
                            first_name: payload.first_name,
                            last_name: payload.last_name,
                            name: payload.name,
                            email: payload.email,
                            locales: payload.locales,
                        },
                    }));
                }
            })
            .finally(() => {
                if (submit instanceof HTMLButtonElement) submit.disabled = false;
            });
    });
}

export function register(UI) {
    UI.define('language-switcher', ({ element: root, listen, emit }) => {
        const resolvePanel = () => {
            const trigger = root.querySelector('[data-w-menu-trigger]');
            const panelId = String(trigger?.getAttribute('aria-controls') || '').trim();
            if (panelId) {
                const byId = document.getElementById(panelId);
                if (byId instanceof HTMLElement) return byId;
            }
            const nested = root.querySelector('[data-w-menu-panel]');
            return nested instanceof HTMLElement ? nested : null;
        };

        let panel = resolvePanel();
        const current = root.querySelector('.w-language-switcher__current');
        let search = panel?.querySelector?.('[data-w-language-search]') || null;
        let empty = panel?.querySelector?.('[data-w-language-empty]') || null;
        let requestButton = panel?.querySelector?.('[data-language-request-open]') || null;
        const requestDialog = root.querySelector('[data-language-request-modal]')
            || (panel ? panel.querySelector('[data-language-request-modal]') : null);
        const requestBody = root.querySelector('[data-language-request-body]')
            || (panel ? panel.querySelector('[data-language-request-body]') : null)
            || (requestDialog instanceof HTMLElement
                ? requestDialog.querySelector('[data-language-request-body]')
                : null);
        let loaded = false;
        let loading = false;
        let requestGeneration = 0;
        let draft = null;
        let searchBound = false;
        let panelClickBound = false;
        let requestBound = false;

        const applySearchFilter = (rawTerm = '') => {
            panel = resolvePanel() || panel;
            if (!(panel instanceof HTMLElement)) return;
            empty = panel.querySelector('[data-w-language-empty]');
            requestButton = panel.querySelector('[data-language-request-open]');
            const term = String(rawTerm || '').trim().toLocaleLowerCase();
            let visibleCount = 0;
            panel.querySelectorAll('.w-language-switcher__group').forEach((group) => {
                if (!(group instanceof HTMLElement)) return;
                let groupVisible = 0;
                group.querySelectorAll('[data-language-option]').forEach((option) => {
                    if (!(option instanceof HTMLElement)) return;
                    const haystack = String(
                        option.getAttribute('data-w-search')
                        || option.dataset.wSearch
                        || option.textContent
                        || '',
                    ).toLocaleLowerCase();
                    const match = term === '' || haystack.includes(term);
                    option.hidden = !match;
                    if (match) groupVisible += 1;
                });
                group.hidden = groupVisible === 0;
                visibleCount += groupVisible;
            });
            if (empty instanceof HTMLElement) {
                empty.hidden = term === '' || visibleCount > 0;
            }
            const divider = panel.querySelector('.w-menu__divider');
            if (divider instanceof HTMLElement && requestButton instanceof HTMLElement) {
                const hideExtras = visibleCount === 0 && term !== '';
                divider.hidden = hideExtras;
                requestButton.hidden = hideExtras;
            }
        };

        let boundSearchEl = null;
        const focusSearch = () => {
            panel = resolvePanel() || panel;
            search = panel?.querySelector?.('[data-w-language-search]') || search;
            if (!(search instanceof HTMLInputElement)) return;
            window.setTimeout(() => {
                if (!(search instanceof HTMLInputElement)) return;
                search.focus({ preventScroll: true });
            }, 0);
        };

        const bindSearch = () => {
            panel = resolvePanel() || panel;
            search = panel?.querySelector?.('[data-w-language-search]') || null;
            empty = panel?.querySelector?.('[data-w-language-empty]') || null;
            if (!(search instanceof HTMLInputElement)) return;
            // Panel portal keeps the same node; rebind only when the input instance changes.
            if (searchBound && boundSearchEl === search) return;
            searchBound = true;
            boundSearchEl = search;
            const apply = () => applySearchFilter(search.value);
            listen(search, 'input', apply);
            listen(search, 'search', apply);
            listen(search, 'compositionend', apply);
            listen(search, 'keydown', (event) => {
                if (event.key === 'Escape') {
                    if (search.value) {
                        event.stopPropagation();
                        search.value = '';
                        applySearchFilter('');
                        return;
                    }
                }
                if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
                    event.stopPropagation();
                }
            });
            listen(search, 'pointerdown', (event) => event.stopPropagation());
            listen(search, 'click', (event) => event.stopPropagation());
        };

        const updateSelection = (locale) => {
            panel = resolvePanel() || panel;
            panel?.querySelectorAll('[data-language-option]').forEach((option) => {
                const active = option.dataset.lang === locale;
                option.setAttribute('aria-checked', String(active));
                option.dataset.state = active ? 'active' : 'idle';
            });
            if (current) current.textContent = shortLocale(locale);
        };

        const onLanguageClick = (event) => {
            const option = event.target instanceof Element
                ? event.target.closest('[data-language-option]')
                : null;
            if (!(option instanceof HTMLAnchorElement)) return;
            // Menu panel is portaled to <body>; do not require root.contains(option).
            panel = resolvePanel() || panel;
            const inRoot = root.contains(option);
            const inPanel = panel instanceof HTMLElement && panel.contains(option);
            if (!inRoot && !inPanel) return;
            const locale = String(option.dataset.lang || '').trim();
            if (!locale) return;
            const navigation = String(root.dataset.i18nNavigation || 'path').toLowerCase();

            if (navigation === 'emit') {
                event.preventDefault();
                event.stopImmediatePropagation();
                updateSelection(locale);
                emit('change', { locale, navigation: 'emit' }, false);
                root.dispatchEvent(new CustomEvent('weline:i18n:locale-change', {
                    bubbles: true,
                    detail: { locale },
                }));
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            writeLanguagePreference(locale, root.dataset.websiteId || '');
            refreshLanguageOptionHrefs(root, panel);
            const optionHref = String(option.getAttribute('href') || option.href || '').trim();
            const href = resolveLanguageNavigationHref(
                locale,
                optionHref,
                root.dataset.websiteMount || '',
            );
            if (!href) {
                return;
            }
            try {
                const target = new URL(href, window.location.href);
                const currentUrl = new URL(window.location.href);
                if (target.origin === currentUrl.origin
                    && target.pathname === currentUrl.pathname
                    && target.search === currentUrl.search
                    && target.hash === currentUrl.hash) {
                    window.location.reload();
                    return;
                }
                window.location.assign(target.href);
            } catch (_error) {
                window.location.assign(href);
            }
        };

        const restoreDraft = () => {
            if (!draft || !(requestBody instanceof HTMLElement)) return;
            for (const name of ['first_name', 'last_name', 'name', 'email']) {
                const field = requestBody.querySelector(`[name="${name}"]`);
                if (field instanceof HTMLInputElement) field.value = String(draft[name] || '');
            }
            const languageSelect = requestBody.querySelector('[data-w-component~="language-select"]');
            const component = languageSelect ? UI.get(languageSelect, 'language-select') : null;
            component?.setValues?.(Array.isArray(draft.locales) ? draft.locales : []);
            draft = null;
        };

        const renderStatus = (message, tone = 'muted', retry = false) => {
            if (!(requestBody instanceof HTMLElement)) return;
            const stack = document.createElement('div');
            stack.className = 'w-stack';
            const text = document.createElement('p');
            text.className = 'w-text';
            text.dataset.tone = tone;
            text.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
            text.textContent = message;
            stack.append(text);
            if (retry) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'w-button';
                button.textContent = requestDialog?.dataset.retryText || 'Retry';
                button.addEventListener('click', () => loadRequestForm(true), { once: true });
                stack.append(button);
            }
            requestBody.replaceChildren(stack);
        };

        const loadRequestForm = async (force = false) => {
            if (!(requestBody instanceof HTMLElement) || (loaded && !force) || loading) return;
            const generation = ++requestGeneration;
            loading = true;
            loaded = false;
            renderStatus(requestDialog?.dataset.loadingText || 'Loading…');
            try {
                if (typeof window.Weline?.load === 'function') await window.Weline.load('api');
                const resource = await Promise.resolve(window.Weline?.Api?.resource?.('i18n_language_requests'));
                if (!resource?.getLanguageSupportRequestForm) throw new Error('Weline.Api is unavailable.');
                const result = await resource.getLanguageSupportRequestForm({});
                if (generation !== requestGeneration) return;
                const markup = String(result?.html || result?.data?.html || '');
                if (!markup) throw new Error(requestDialog?.dataset.loadFailText || 'Unable to load the form.');
                UI.unmount(requestBody);
                requestBody.replaceChildren(trustedFragment(markup));
                // Captcha / form bootstrap scripts must be re-inserted to run.
                activateTrustedScripts(requestBody);
                UI.mount(requestBody);
                const form = requestBody.querySelector('form[data-weline-form], form.weline-language-request-form, form');
                if (form instanceof HTMLFormElement) {
                    window.Weline?.Form?.mount?.(form);
                    bindLanguageRequestForm(UI, form, requestDialog);
                }
                loaded = true;
                queueMicrotask(restoreDraft);
            } catch (error) {
                if (generation !== requestGeneration) return;
                renderStatus(
                    error instanceof Error ? error.message : requestDialog?.dataset.errorText || 'Unable to load.',
                    'danger',
                    true,
                );
            } finally {
                if (generation === requestGeneration) loading = false;
            }
        };

        const bindPanelClick = () => {
            panel = resolvePanel() || panel;
            if (!(panel instanceof HTMLElement) || panelClickBound) return;
            panelClickBound = true;
            listen(panel, 'click', onLanguageClick);
        };

        const bindRequest = () => {
            panel = resolvePanel() || panel;
            requestButton = panel?.querySelector?.('[data-language-request-open]') || null;
            if (!(requestButton instanceof HTMLButtonElement)
                || !(requestDialog instanceof HTMLDialogElement)
                || requestBound) {
                return;
            }
            requestBound = true;
            listen(requestButton, 'click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                UI.get(root, 'menu')?.close(false, 'language-request');
                UI.dialog.open(requestDialog);
                loadRequestForm();
            });
            listen(requestDialog, 'weline:captcha:refresh-requested', (event) => {
                draft = event.detail && typeof event.detail === 'object' ? event.detail : null;
                loadRequestForm(true);
            });
        };

        bindPanelClick();
        bindSearch();
        bindRequest();

        listen(root, 'weline:ui:menu:open', () => {
            bindPanelClick();
            bindSearch();
            bindRequest();
            panel = resolvePanel() || panel;
            refreshLanguageOptionHrefs(root, panel);
            search = panel?.querySelector?.('[data-w-language-search]') || search;
            applySearchFilter(search instanceof HTMLInputElement ? search.value : '');
            focusSearch();
        });
        listen(root, 'weline:ui:menu:close', () => {
            panel = resolvePanel() || panel;
            search = panel?.querySelector?.('[data-w-language-search]') || search;
            if (search instanceof HTMLInputElement && search.value) {
                search.value = '';
                applySearchFilter('');
            }
        });

        return {
            element: root,
            writeLanguagePreference: (locale) => writeLanguagePreference(locale, root.dataset.websiteId || ''),
            destroy() {
                requestGeneration += 1;
                if (requestBody instanceof HTMLElement) UI.unmount(requestBody);
            },
        };
    });
}
