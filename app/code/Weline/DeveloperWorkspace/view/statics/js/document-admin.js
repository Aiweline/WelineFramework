const root = document.querySelector('[data-document-admin]');

if (root) {
    const drawer = root.querySelector('#w-document-view');
    const contentRoot = root.querySelector('[data-document-view-content]');
    const text = Object.fromEntries(
        Object.entries(root.dataset)
            .filter(([key]) => key.startsWith('text'))
            .map(([key, value]) => [key.slice(4, 5).toLowerCase() + key.slice(5), String(value || '')]),
    );

    function icon(name, size = 'sm') {
        return window.Weline?.UI?.icon?.create(name, { size }) || document.createTextNode('');
    }

    function responseError(result, fallback) {
        return String(result?.message || result?.msg || result?.data?.message || result?.data?.msg || fallback);
    }

    async function apiResource() {
        const Weline = window.Weline;
        const api = Weline?.Api?.resource ? Weline.Api : await Weline?.load?.('api');
        if (!api?.resource) throw new Error('Weline API runtime is unavailable.');
        return api.resource('developer_workspace');
    }

    async function call(operation, params) {
        const resource = await apiResource();
        if (typeof resource[operation] !== 'function') {
            throw new Error(`Weline operation is unavailable: ${operation}`);
        }
        const result = await resource[operation](params, { keepBusinessResult: true, silent: true });
        if (result?.success === false || Number(result?.code || 200) >= 400) {
            throw new Error(responseError(result, text.loadFailed));
        }
        return result;
    }

    function status(message, tone = 'neutral') {
        const element = document.createElement('div');
        element.className = 'w-document-view__status';
        if (tone === 'neutral') {
            const spinner = document.createElement('span');
            spinner.className = 'w-spinner';
            spinner.setAttribute('role', 'status');
            element.append(spinner);
        } else {
            element.append(icon('warning', 'lg'));
        }
        const label = document.createElement('p');
        label.textContent = String(message || '');
        element.append(label);
        return element;
    }

    function safeUrl(value, allowImage = false) {
        const raw = String(value || '').trim();
        if (raw.startsWith('#') || raw.startsWith('/')) return raw;
        try {
            const url = new URL(raw, window.location.origin);
            const protocols = allowImage ? ['http:', 'https:', 'data:'] : ['http:', 'https:', 'mailto:'];
            if (!protocols.includes(url.protocol)) return '';
            if (url.protocol === 'data:' && !/^data:image\/(?:png|gif|jpe?g|webp);base64,/i.test(raw)) return '';
            return raw;
        } catch (_error) {
            return '';
        }
    }

    function documentContent(value) {
        const encoded = new DOMParser().parseFromString(String(value || ''), 'text/html');
        const decoded = encoded.body.textContent || '';
        const source = new DOMParser().parseFromString(decoded, 'text/html');
        const allowed = new Set([
            'A', 'P', 'BR', 'STRONG', 'EM', 'DEL', 'CODE', 'PRE', 'BLOCKQUOTE',
            'UL', 'OL', 'LI', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'HR',
            'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD', 'IMG',
        ]);
        [...source.body.querySelectorAll('*')].forEach((element) => {
            if (!allowed.has(element.tagName)) {
                element.replaceWith(...element.childNodes);
                return;
            }
            [...element.attributes].forEach((attribute) => {
                if (!['href', 'src', 'alt', 'title'].includes(attribute.name)) element.removeAttribute(attribute.name);
            });
            if (element instanceof HTMLAnchorElement) {
                const href = safeUrl(element.getAttribute('href'));
                if (href) element.setAttribute('href', href); else element.removeAttribute('href');
                element.setAttribute('rel', 'noopener noreferrer');
            }
            if (element instanceof HTMLImageElement) {
                const src = safeUrl(element.getAttribute('src'), true);
                if (src) element.setAttribute('src', src); else element.remove();
            }
        });
        const fragment = document.createDocumentFragment();
        fragment.append(...source.body.childNodes);
        if (!fragment.hasChildNodes()) {
            const empty = document.createElement('p');
            empty.className = 'w-text';
            empty.dataset.tone = 'muted';
            empty.textContent = text.noContent || '';
            fragment.append(empty);
        }
        return fragment;
    }

    function metaItem(name, label, value) {
        const item = document.createElement('div');
        const term = document.createElement('dt');
        term.append(icon(name));
        const hidden = document.createElement('span');
        hidden.className = 'w-visually-hidden';
        hidden.textContent = label;
        term.append(hidden);
        const description = document.createElement('dd');
        description.textContent = String(value || text.unknown || '');
        item.append(term, description);
        return item;
    }

    function renderDocument(documentData) {
        const article = document.createElement('article');
        article.className = 'w-document-view__article';
        const heading = document.createElement('div');
        heading.className = 'w-stack';
        const title = document.createElement('h3');
        title.textContent = String(documentData.title || '');
        heading.append(title);
        if (documentData.summary) {
            const summary = document.createElement('p');
            summary.className = 'w-text';
            summary.dataset.tone = 'muted';
            summary.textContent = String(documentData.summary);
            heading.append(summary);
        }

        const meta = document.createElement('dl');
        meta.className = 'w-document-view__meta';
        meta.append(
            metaItem('folder', text.category, documentData.category),
            metaItem('user', text.author, documentData.author),
            metaItem('calendar', text.created, documentData.create_time),
            metaItem('clock', text.updated, documentData.update_time),
        );
        if (documentData.module_name) meta.append(metaItem('box', text.module, documentData.module_name));

        const body = document.createElement('div');
        body.className = 'w-document-view__content';
        body.append(documentContent(documentData.content));
        article.append(heading, meta, body);
        return article;
    }

    async function viewDocument(id) {
        if (!drawer || !contentRoot || !id) return;
        contentRoot.replaceChildren(status(text.loading));
        window.Weline?.UI?.drawer?.open(drawer);
        try {
            const result = await call('documentAdminView', {
                id,
                locale: root.dataset.documentLocale || '',
            });
            const data = result?.data && typeof result.data === 'object' ? result.data : result;
            contentRoot.replaceChildren(renderDocument(data));
        } catch (error) {
            contentRoot.replaceChildren(status(error instanceof Error ? error.message : text.loadFailed, 'danger'));
        }
    }

    async function deleteDocument(id, trigger) {
        if (!id) return;
        const confirmed = await window.Weline?.UI?.dialog?.confirm?.(text.deleteMessage, {
            title: text.deleteTitle,
            dangerous: true,
            confirmTone: 'danger',
        });
        if (!confirmed) return;
        trigger.disabled = true;
        try {
            const result = await call('documentAdminDelete', { id });
            window.Weline?.UI?.toast?.success(responseError(result, text.deleteSuccess));
            window.location.reload();
        } catch (error) {
            trigger.disabled = false;
            window.Weline?.UI?.toast?.error(error instanceof Error ? error.message : text.deleteFailed);
        }
    }

    root.addEventListener('click', (event) => {
        const viewTrigger = event.target.closest('[data-document-view]');
        if (viewTrigger instanceof HTMLButtonElement) {
            viewDocument(Number(viewTrigger.dataset.documentView || 0));
            return;
        }
        const deleteTrigger = event.target.closest('[data-document-delete]');
        if (deleteTrigger instanceof HTMLButtonElement) {
            deleteDocument(Number(deleteTrigger.dataset.documentDelete || 0), deleteTrigger);
        }
    });
}
