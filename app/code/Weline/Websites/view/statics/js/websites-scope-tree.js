/**
 * 网站管理：左树点击 → 右栏异步加载（不整页刷新）
 */
(function () {
    'use strict';

    const root = document.querySelector('[data-websites-scope-tree]');
    if (!(root instanceof HTMLElement) || root.dataset.scopeTreeAsyncBound === '1') {
        return;
    }
    root.dataset.scopeTreeAsyncBound = '1';

    const tree = root.querySelector('[data-testid="websites-scope-tree"]');
    const editor = root.querySelector('[data-testid="websites-scope-editor"]');
    if (!(tree instanceof HTMLElement) || !(editor instanceof HTMLElement)) {
        return;
    }

    let abort = null;
    let seq = 0;

    function toast(message, tone) {
        try {
            window.Weline?.UI?.toast?.show?.(message, { tone: tone || 'danger', duration: 3200 });
        } catch (_e) {
            /* ignore */
        }
    }

    function panelUrl(href) {
        const url = new URL(href, window.location.href);
        url.searchParams.set('panel', '1');
        return url;
    }

    function setSelected(link) {
        if (!(link instanceof HTMLAnchorElement)) {
            return;
        }
        tree.querySelectorAll('.w-catalog-tree__row[data-state="selected"]').forEach((row) => {
            row.setAttribute('data-state', 'idle');
        });
        tree.querySelectorAll('.w-catalog-tree__link[aria-current="page"]').forEach((a) => {
            a.removeAttribute('aria-current');
        });
        const row = link.closest('.w-catalog-tree__row');
        if (row instanceof HTMLElement) {
            row.setAttribute('data-state', 'selected');
        }
        link.setAttribute('aria-current', 'page');
    }

    function clearDomainSelectRegistry(scope) {
        const mount = window.WelineDomainSelectMount;
        if (!mount || typeof mount.unregister !== 'function' || !(scope instanceof Element)) {
            return;
        }
        scope.querySelectorAll('[id$="_wrapper"]').forEach((el) => {
            const id = String(el.id || '').replace(/_wrapper$/, '');
            if (id) {
                mount.unregister(id);
            }
        });
    }

    function activateScripts(scope) {
        if (!(scope instanceof Element)) {
            return;
        }
        scope.querySelectorAll('script').forEach((oldScript) => {
            const script = document.createElement('script');
            [...oldScript.attributes].forEach((attr) => {
                script.setAttribute(attr.name, attr.value);
            });
            if (!oldScript.src) {
                script.textContent = oldScript.textContent || '';
            }
            oldScript.replaceWith(script);
        });
    }

    function remount(panelRoot) {
        if (!(panelRoot instanceof Element)) {
            return;
        }
        clearDomainSelectRegistry(panelRoot);
        try {
            window.Weline?.UI?.unmount?.(panelRoot);
        } catch (_e) {
            /* ignore */
        }
        activateScripts(panelRoot);
        try {
            window.Weline?.UI?.mount?.(panelRoot);
        } catch (_e) {
            /* ignore */
        }
        panelRoot.querySelectorAll(
            '[data-w-component~="currency-select"], [data-w-component~="language-select"], [data-w-component~="website-form"]',
        ).forEach((node) => {
            try {
                window.Weline?.UI?.mount?.(node);
            } catch (_e) {
                /* ignore */
            }
        });
    }

    async function loadPanel(link) {
        if (!(link instanceof HTMLAnchorElement)) {
            return;
        }
        const href = link.getAttribute('href');
        if (!href) {
            return;
        }
        const requestUrl = panelUrl(href);
        const historyUrl = new URL(href, window.location.href);
        historyUrl.searchParams.delete('panel');

        if (abort) {
            abort.abort();
        }
        abort = new AbortController();
        const mySeq = ++seq;
        editor.setAttribute('aria-busy', 'true');
        editor.dataset.loading = '1';

        try {
            const response = await fetch(requestUrl.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Weline-Scope-Panel': '1',
                    Accept: 'application/json',
                },
                signal: abort.signal,
            });
            const payload = await response.json().catch(() => null);
            if (mySeq !== seq) {
                return;
            }
            if (!response.ok || !payload || payload.success !== true || typeof payload.html !== 'string' || !payload.kind) {
                throw new Error((payload && payload.message) || '加载失败');
            }

            clearDomainSelectRegistry(editor);
            try {
                window.Weline?.UI?.unmount?.(editor);
            } catch (_e) {
                /* ignore */
            }
            editor.innerHTML = payload.html;
            if (payload.kind) {
                editor.setAttribute('data-editor-kind', String(payload.kind));
            }
            setSelected(link);
            remount(editor);
            window.history.pushState({ websitesScopeNode: payload.node || '' }, '', historyUrl.toString());
        } catch (error) {
            if (error && error.name === 'AbortError') {
                return;
            }
            toast((error && error.message) || '右侧编辑加载失败', 'danger');
            window.location.assign(historyUrl.toString());
        } finally {
            if (mySeq === seq) {
                editor.removeAttribute('aria-busy');
                delete editor.dataset.loading;
            }
        }
    }

    tree.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        if (target.closest('[data-w-tree-toggle]')) {
            return;
        }
        const link = target.closest('a.w-catalog-tree__link, a.w-catalog-tree__add');
        if (!(link instanceof HTMLAnchorElement) || !tree.contains(link)) {
            return;
        }
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        loadPanel(link);
    });

    window.addEventListener('popstate', () => {
        window.location.reload();
    });
}());
