/* Weline UI source: js/docs-browser.js */
const configElement = document.querySelector('[data-docs-browser-config]');
const config = configElement ? JSON.parse(configElement.textContent || '{}') : {};
const treeRoot = document.querySelector('[data-docs-tree]');
const contentRoot = document.querySelector('[data-docs-content]');
const searchInput = document.querySelector('[data-docs-search]');
const backButton = document.querySelector('[data-docs-back]');
const contextBadge = document.querySelector('[data-docs-context]');
const text = config.text || {};
const state = { catalogId: Number(config.catalogId || 0), documentId: Number(config.documentId || 0) };
const markedEngine = window.marked || null;

try { delete window.marked; } catch (_error) { window.marked = undefined; }

function highlightEngine() {
    return window.hljs || null;
}

function syncHighlightTheme() {
    const theme = String(document.documentElement.getAttribute('data-theme') || '').toLowerCase();
    const preferDark = theme === 'dark'
        || (theme !== 'light' && window.matchMedia?.('(prefers-color-scheme: dark)')?.matches);
    document.querySelectorAll('[data-docs-hljs-theme]').forEach((link) => {
        const mode = String(link.getAttribute('data-docs-hljs-theme') || '');
        link.disabled = preferDark ? mode !== 'dark' : mode !== 'light';
    });
}

function detectCodeLanguage(codeElement) {
    const className = String(codeElement?.className || '');
    const match = className.match(/(?:^|\s)(?:language|lang)-([a-z0-9_+-]+)/i);
    return match ? String(match[1]).toLowerCase() : '';
}

function containsPhpShortOrOpenTag(source) {
    return /<\?(?:php|=|\s)/i.test(String(source || ''));
}

/**
 * Prefer php-template for HTML mixed with <?= / <?php so short tags are tokenized.
 * Fence labels like html/xml/phtml often miss attribute/body short tags otherwise.
 */
function resolveHighlightLanguage(hljs, declaredLanguage, source) {
    const aliases = {
        phtml: 'php-template',
        'php-html': 'php-template',
        phphtml: 'php-template',
        blade: 'php-template',
    };
    let language = String(declaredLanguage || '').toLowerCase();
    if (aliases[language]) language = aliases[language];

    const hasPhp = containsPhpShortOrOpenTag(source);
    if (hasPhp) {
        const htmlLike = !language
            || language === 'html'
            || language === 'xml'
            || language === 'htm'
            || language === 'svg'
            || language === 'javascript'
            || language === 'js'
            || language === 'typescript'
            || language === 'ts'
            || language === 'plaintext'
            || language === 'text'
            || language === 'code';
        if (htmlLike && hljs.getLanguage?.('php-template')) {
            return 'php-template';
        }
        if ((language === 'php' || language === 'php-template') && hljs.getLanguage?.(language)) {
            return language === 'php' && /<[a-zA-Z]/.test(source) && hljs.getLanguage?.('php-template')
                ? 'php-template'
                : language;
        }
    }

    if (language && hljs.getLanguage?.(language)) return language;

    if (!language && hljs.highlightAuto) {
        const result = hljs.highlightAuto(String(source || ''));
        const detected = String(result?.language || '').toLowerCase();
        if (hasPhp && hljs.getLanguage?.('php-template')) return 'php-template';
        if (detected && hljs.getLanguage?.(detected)) return detected;
    }

    return language || '';
}

function applyHighlight(codeElement, language) {
    const hljs = highlightEngine();
    if (!hljs || !(codeElement instanceof HTMLElement)) return language || '';
    const source = codeElement.textContent || '';
    const resolved = resolveHighlightLanguage(hljs, language, source);
    try {
        if (resolved && hljs.getLanguage?.(resolved)) {
            codeElement.className = `language-${resolved} hljs`;
            const result = hljs.highlight(source, { language: resolved, ignoreIllegals: true });
            codeElement.innerHTML = result.value;
            return resolved;
        }
        if (hljs.highlightAuto) {
            const result = hljs.highlightAuto(source);
            if (result?.value) {
                codeElement.innerHTML = result.value;
                codeElement.classList.add('hljs');
                if (result.language) {
                    const detected = String(result.language).toLowerCase();
                    codeElement.classList.add(`language-${detected}`);
                    return detected;
                }
            }
        }
    } catch (_error) {
        // Keep plain code when highlighter fails.
    }
    return resolved || language || '';
}

/** Framework Phrase placeholders: %{name}, %{count}, %{}, %{1} */
const I18N_PLACEHOLDER_RE = /%\{(?:[A-Za-z_][A-Za-z0-9_]*|\d*)\}/g;

function markI18nPlaceholders(root) {
    if (!(root instanceof Node)) return;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
        acceptNode(node) {
            if (!node?.nodeValue || !I18N_PLACEHOLDER_RE.test(node.nodeValue)) {
                I18N_PLACEHOLDER_RE.lastIndex = 0;
                return NodeFilter.FILTER_REJECT;
            }
            I18N_PLACEHOLDER_RE.lastIndex = 0;
            const parent = node.parentElement;
            if (!parent) return NodeFilter.FILTER_REJECT;
            if (parent.closest('.w-docs-i18n-ph, .w-docs-code__bar, script, style')) {
                return NodeFilter.FILTER_REJECT;
            }
            return NodeFilter.FILTER_ACCEPT;
        },
    });
    const targets = [];
    while (walker.nextNode()) targets.push(walker.currentNode);
    targets.forEach((textNode) => {
        const value = String(textNode.nodeValue || '');
        I18N_PLACEHOLDER_RE.lastIndex = 0;
        if (!I18N_PLACEHOLDER_RE.test(value)) return;
        I18N_PLACEHOLDER_RE.lastIndex = 0;
        const frag = document.createDocumentFragment();
        let last = 0;
        value.replace(I18N_PLACEHOLDER_RE, (match, offset) => {
            if (offset > last) frag.append(document.createTextNode(value.slice(last, offset)));
            const mark = document.createElement('span');
            mark.className = 'w-docs-i18n-ph';
            mark.setAttribute('title', text.i18nPlaceholder || '翻译占位变量');
            mark.textContent = match;
            frag.append(mark);
            last = offset + match.length;
            return match;
        });
        if (last < value.length) frag.append(document.createTextNode(value.slice(last)));
        textNode.replaceWith(frag);
    });
}

async function copyCodeText(text) {
    const value = String(text || '');
    if (!value) return false;
    try {
        window.focus?.();
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(value);
            return true;
        }
    } catch (_error) {
        // Fall through to legacy copy path (some embedded browsers block Clipboard API).
    }
    const area = document.createElement('textarea');
    area.value = value;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.top = '0';
    area.style.left = '0';
    area.style.opacity = '0';
    document.body.append(area);
    area.focus();
    area.select();
    area.setSelectionRange(0, area.value.length);
    let ok = false;
    try {
        ok = document.execCommand('copy');
    } catch (_error) {
        ok = false;
    }
    area.remove();
    return ok;
}

function enhanceCodeBlocks(root) {
    if (!(root instanceof Element)) return;
    syncHighlightTheme();
    root.querySelectorAll('pre').forEach((pre) => {
        if (pre.closest('.w-docs-code')) return;
        const code = pre.querySelector('code') || pre;
        const language = detectCodeLanguage(code instanceof HTMLElement ? code : null);
        let resolvedLanguage = language;
        if (code instanceof HTMLElement) {
            resolvedLanguage = applyHighlight(code, language);
            markI18nPlaceholders(code);
        }

        const wrap = document.createElement('div');
        wrap.className = 'w-docs-code';
        if (resolvedLanguage) wrap.dataset.language = resolvedLanguage;

        const bar = document.createElement('div');
        bar.className = 'w-docs-code__bar w-cluster';
        bar.dataset.justify = 'between';
        bar.dataset.align = 'center';

        const langLabel = document.createElement('span');
        langLabel.className = 'w-docs-code__lang w-text';
        langLabel.dataset.tone = 'muted';
        const displayLang = resolvedLanguage === 'php-template' ? 'php' : resolvedLanguage;
        langLabel.textContent = displayLang || '';
        langLabel.hidden = !displayLang;

        const copyButton = document.createElement('button');
        copyButton.type = 'button';
        copyButton.className = 'w-button';
        copyButton.dataset.tone = 'quiet';
        copyButton.dataset.size = 'sm';
        copyButton.dataset.docsCopy = 'true';
        copyButton.textContent = text.copy || '复制';

        bar.append(langLabel, copyButton);
        pre.replaceWith(wrap);
        wrap.append(bar, pre);
    });
    markI18nPlaceholders(root);
}

document.addEventListener('click', (event) => {
    const button = event.target instanceof Element
        ? event.target.closest('[data-docs-copy="true"]')
        : null;
    if (!button) return;
    const wrap = button.closest('.w-docs-code');
    const code = wrap?.querySelector('code') || wrap?.querySelector('pre');
    if (!code) return;
    event.preventDefault();
    const original = button.textContent;
    copyCodeText(code.textContent || '').then((ok) => {
        button.textContent = ok ? (text.copied || '已复制') : (text.copyFailed || '复制失败');
        window.setTimeout(() => {
            button.textContent = original || text.copy || '复制';
        }, 1600);
    }).catch(() => {
        button.textContent = text.copyFailed || '复制失败';
        window.setTimeout(() => {
            button.textContent = original || text.copy || '复制';
        }, 1600);
    });
});

const themeObserver = new MutationObserver(() => syncHighlightTheme());
themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
syncHighlightTheme();

function icon(name, size = 'sm') {
    return window.Weline?.UI?.icon?.create(name, { size }) || document.createTextNode('');
}

function empty(message, iconName = 'book') {
    const box = document.createElement('div');
    box.className = 'w-empty';
    box.append(icon(iconName, 'lg'));
    const paragraph = document.createElement('p');
    paragraph.textContent = message;
    box.append(paragraph);
    return box;
}

function setBusy(target, message = text.loading || '加载中…') {
    const status = document.createElement('div');
    status.className = 'w-empty';
    const spinner = document.createElement('span');
    spinner.className = 'w-spinner';
    spinner.setAttribute('role', 'status');
    status.append(spinner);
    const label = document.createElement('p');
    label.textContent = message;
    status.append(label);
    target.replaceChildren(status);
}

function withLocale(path) {
    const url = new URL(`${String(config.baseUrl || '').replace(/\/$/, '')}${path}`, window.location.origin);
    if (config.locale) url.searchParams.set('doc_locale', String(config.locale));
    return `${url.pathname}${url.search}`;
}

async function request(path) {
    const Weline = window.Weline;
    let api = Weline?.Api;
    // Fallback stub's resource() is async; never call .op() on its Promise.
    if (!api || api.__fallback === true || typeof api.resource !== 'function') {
        api = await Weline?.load?.('api');
    }
    if (!api || typeof api.resource !== 'function') {
        throw new Error('Weline API runtime is unavailable.');
    }
    const resource = await Promise.resolve(api.resource('developer_workspace'));
    if (!resource || typeof resource.docsRequest !== 'function') {
        throw new Error('developer_workspace.docsRequest is unavailable.');
    }
    const result = await resource.docsRequest(
        { url: withLocale(path), method: 'GET' },
        { keepBusinessResult: true, silent: true },
    );
    if (result && result.success === false) throw new Error(String(result.message || text.requestFailed || 'Request failed'));
    return result;
}

function safeUrl(value, allowImage = false) {
    const raw = String(value || '').trim();
    if (raw.startsWith('#') || raw.startsWith('/')) return raw;
    try {
        const url = new URL(raw, window.location.origin);
        const allowed = allowImage ? ['http:', 'https:', 'data:'] : ['http:', 'https:', 'mailto:'];
        if (!allowed.includes(url.protocol)) return '';
        if (url.protocol === 'data:' && !/^data:image\/(?:png|gif|jpe?g|webp);base64,/i.test(raw)) return '';
        return raw;
    } catch (_error) {
        return '';
    }
}

function sanitizedMarkdown(markdown) {
    if (!markedEngine?.parse) {
        const pre = document.createElement('pre');
        pre.textContent = String(markdown || '');
        return pre;
    }
    const parsed = markedEngine.parse(String(markdown || ''), { breaks: true, gfm: true });
    const source = new DOMParser().parseFromString(String(parsed), 'text/html');
    const allowed = new Set(['A', 'P', 'BR', 'STRONG', 'EM', 'DEL', 'CODE', 'PRE', 'BLOCKQUOTE', 'UL', 'OL', 'LI', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'HR', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD', 'IMG']);
    [...source.body.querySelectorAll('*')].forEach((element) => {
        if (!allowed.has(element.tagName)) {
            element.replaceWith(...element.childNodes);
            return;
        }
        [...element.attributes].forEach((attribute) => {
            if (!['href', 'src', 'alt', 'title', 'class'].includes(attribute.name)) element.removeAttribute(attribute.name);
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
    return fragment;
}

function treeList(nodes, level = 1) {
    const list = document.createElement('ul');
    list.className = level === 1 ? 'w-docs-tree' : '';
    (Array.isArray(nodes) ? nodes : []).forEach((node) => {
        const id = Number(node?.id || 0);
        if (!id || Number(node?.is_active ?? 1) !== 1) return;
        const item = document.createElement('li');
        const children = Array.isArray(node.nodes) ? node.nodes : [];
        const label = String(node.name || '');
        if (children.length > 0) {
            const disclosure = document.createElement('details');
            disclosure.open = level <= 2;
            const summary = document.createElement('summary');
            summary.dataset.catalogId = String(id);
            summary.append(icon('folder'), document.createTextNode(label));
            disclosure.append(summary, treeList(children, level + 1));
            item.append(disclosure);
        } else {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'w-docs-tree__leaf';
            button.dataset.catalogId = String(id);
            button.append(icon('file'), document.createTextNode(label));
            item.append(button);
        }
        list.append(item);
    });
    return list;
}

async function loadTree() {
    setBusy(treeRoot);
    try {
        const nodes = await request('/docs/tree');
        treeRoot.replaceChildren(treeList(nodes));
        selectCatalogMarker();
    } catch (error) {
        treeRoot.replaceChildren(empty(error.message || text.requestFailed, 'warning'));
    }
}

function selectCatalogMarker() {
    treeRoot.querySelectorAll('[data-catalog-id]').forEach((element) => {
        element.dataset.docsSelected = Number(element.dataset.catalogId) === state.catalogId ? 'true' : 'false';
    });
}

function documentList(documents, heading = text.documents || '文档') {
    const wrapper = document.createElement('section');
    wrapper.className = 'w-stack';
    const title = document.createElement('h1');
    title.textContent = heading;
    wrapper.append(title);
    const list = document.createElement('div');
    list.className = 'w-docs-list';
    (Array.isArray(documents) ? documents : []).forEach((doc) => {
        const id = Number(doc?.id || 0);
        if (!id) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'w-docs-list__item';
        button.dataset.documentId = String(id);
        const name = document.createElement('strong');
        name.textContent = String(doc.title || '');
        button.append(name);
        if (doc.summary) {
            const summary = document.createElement('span');
            summary.className = 'w-text';
            summary.dataset.tone = 'muted';
            summary.textContent = String(doc.summary);
            button.append(summary);
        }
        if (doc.module_name) {
            const module = document.createElement('span');
            module.className = 'w-badge';
            module.dataset.tone = 'neutral';
            module.textContent = String(doc.module_name);
            button.append(module);
        }
        list.append(button);
    });
    wrapper.append(list);
    return wrapper;
}

async function loadDocuments(catalogId, updateHistory = true) {
    state.catalogId = Number(catalogId || 0);
    state.documentId = 0;
    selectCatalogMarker();
    backButton.hidden = true;
    contextBadge.textContent = text.documents || '文档';
    setBusy(contentRoot);
    try {
        const documents = await request(`/docs/documents?catalog_id=${encodeURIComponent(state.catalogId)}`);
        contentRoot.replaceChildren(documents.length ? documentList(documents) : empty(text.emptyCatalog || '该分类下暂无文档'));
        if (updateHistory) updateUrl();
    } catch (error) {
        contentRoot.replaceChildren(empty(error.message || text.requestFailed, 'warning'));
    }
}

async function loadDocument(documentId, updateHistory = true) {
    state.documentId = Number(documentId || 0);
    backButton.hidden = false;
    setBusy(contentRoot);
    try {
        const doc = await request(`/docs/document?id=${encodeURIComponent(state.documentId)}`);
        state.catalogId = Number(doc.category_id || state.catalogId || 0);
        selectCatalogMarker();
        const article = document.createElement('article');
        article.className = 'w-docs-article';
        const title = document.createElement('h1');
        title.textContent = String(doc.title || '');
        const meta = document.createElement('div');
        meta.className = 'w-docs-article__meta w-cluster';
        [doc.module_name, doc.file_name, doc.translation_status].filter(Boolean).forEach((value) => {
            const badge = document.createElement('span');
            badge.className = 'w-badge';
            badge.dataset.tone = 'neutral';
            badge.textContent = String(value);
            meta.append(badge);
        });
        const body = document.createElement('div');
        body.className = 'w-docs-article__content';
        body.append(sanitizedMarkdown(doc.content || ''));
        enhanceCodeBlocks(body);
        article.append(title, meta, body);
        contentRoot.replaceChildren(article);
        contextBadge.textContent = String(doc.title || text.documents || '文档');
        if (updateHistory) updateUrl();
    } catch (error) {
        contentRoot.replaceChildren(empty(error.message || text.requestFailed, 'warning'));
    }
}

async function search(keyword) {
    const query = String(keyword || '').trim();
    if (!query) {
        if (state.catalogId) await loadDocuments(state.catalogId, false);
        else contentRoot.replaceChildren(empty(text.chooseCatalog || '请选择分类查看文档'));
        return;
    }
    setBusy(contentRoot);
    try {
        const result = await request(`/docs/search?keyword=${encodeURIComponent(query)}&include_catalogs=1`);
        const documents = Array.isArray(result) ? result : (Array.isArray(result.documents) ? result.documents : []);
        contentRoot.replaceChildren(documents.length ? documentList(documents, `“${query}”`) : empty(text.emptySearch || '没有找到匹配的文档', 'search'));
        backButton.hidden = true;
        contextBadge.textContent = '搜索';
    } catch (error) {
        contentRoot.replaceChildren(empty(error.message || text.requestFailed, 'warning'));
    }
}

function updateUrl() {
    const url = new URL(window.location.href);
    if (state.catalogId) url.searchParams.set('catalog_id', String(state.catalogId)); else url.searchParams.delete('catalog_id');
    if (state.documentId) url.searchParams.set('id', String(state.documentId)); else url.searchParams.delete('id');
    window.history.pushState({ ...state }, '', url);
}

let searchTimer = 0;
searchInput?.addEventListener('input', () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => search(searchInput.value), 220);
});

treeRoot?.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-catalog-id]');
    if (trigger) loadDocuments(Number(trigger.dataset.catalogId));
});

contentRoot?.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-document-id]');
    if (trigger) loadDocument(Number(trigger.dataset.documentId));
});

backButton?.addEventListener('click', () => state.catalogId && loadDocuments(state.catalogId));

window.addEventListener('popstate', () => {
    const url = new URL(window.location.href);
    state.catalogId = Number(url.searchParams.get('catalog_id') || 0);
    state.documentId = Number(url.searchParams.get('id') || 0);
    if (state.documentId) loadDocument(state.documentId, false);
    else if (state.catalogId) loadDocuments(state.catalogId, false);
});

loadTree();
if (state.documentId) loadDocument(state.documentId, false);
else if (state.catalogId) loadDocuments(state.catalogId, false);
else contentRoot?.replaceChildren(empty(text.chooseCatalog || '请选择分类查看文档'));
