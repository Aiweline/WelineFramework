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

function isAsciiDiagram(source) {
    return /[\u2500-\u257F\u2580-\u259F]/.test(String(source || ''));
}

function ensureCodeFullscreenDialog() {
    let dialog = document.querySelector('[data-docs-code-fs]');
    if (dialog instanceof HTMLDialogElement) return dialog;

    dialog = document.createElement('dialog');
    dialog.className = 'w-docs-code-fs';
    dialog.dataset.docsCodeFs = 'true';
    dialog.setAttribute('aria-label', text.fullscreen || '全屏查看');

    const bar = document.createElement('div');
    bar.className = 'w-docs-code-fs__bar w-cluster';
    bar.dataset.justify = 'between';
    bar.dataset.align = 'center';

    const lang = document.createElement('span');
    lang.className = 'w-docs-code-fs__lang w-text';
    lang.dataset.tone = 'muted';
    lang.dataset.docsCodeFsLang = 'true';

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'w-button';
    closeButton.dataset.tone = 'quiet';
    closeButton.dataset.size = 'sm';
    closeButton.dataset.docsCodeFsClose = 'true';
    closeButton.textContent = text.fullscreenClose || '关闭';

    bar.append(lang, closeButton);

    const body = document.createElement('div');
    body.className = 'w-docs-code-fs__scroll';
    body.dataset.docsCodeFsBody = 'true';

    dialog.append(bar, body);
    document.body.append(dialog);

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
    closeButton.addEventListener('click', () => dialog.close());
    return dialog;
}

function openCodeFullscreen(wrap) {
    if (!(wrap instanceof Element)) return;
    const sourcePre = wrap.querySelector('pre');
    if (!sourcePre) return;
    const dialog = ensureCodeFullscreenDialog();
    const langNode = dialog.querySelector('[data-docs-code-fs-lang]');
    const body = dialog.querySelector('[data-docs-code-fs-body]');
    if (!(body instanceof Element)) return;
    const displayLang = String(wrap.dataset.language || '').replace(/^php-template$/, 'php');
    if (langNode) {
        langNode.textContent = displayLang || (text.fullscreen || '全屏查看');
        langNode.hidden = !displayLang;
    }
    body.replaceChildren(sourcePre.cloneNode(true));
    const clonedPre = body.querySelector('pre');
    if (clonedPre) {
        clonedPre.classList.add('w-docs-code-fs__pre');
    }
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
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
        const sourceText = code instanceof HTMLElement ? (code.textContent || '') : (pre.textContent || '');
        if (isAsciiDiagram(sourceText)) wrap.dataset.diagram = '1';

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

        const actions = document.createElement('div');
        actions.className = 'w-docs-code__actions w-cluster';
        actions.dataset.align = 'center';

        const fullscreenButton = document.createElement('button');
        fullscreenButton.type = 'button';
        fullscreenButton.className = 'w-button';
        fullscreenButton.dataset.tone = 'quiet';
        fullscreenButton.dataset.size = 'sm';
        fullscreenButton.dataset.docsFullscreen = 'true';
        fullscreenButton.textContent = text.fullscreen || '全屏';

        const copyButton = document.createElement('button');
        copyButton.type = 'button';
        copyButton.className = 'w-button';
        copyButton.dataset.tone = 'quiet';
        copyButton.dataset.size = 'sm';
        copyButton.dataset.docsCopy = 'true';
        copyButton.textContent = text.copy || '复制';

        actions.append(fullscreenButton, copyButton);
        bar.append(langLabel, actions);
        pre.replaceWith(wrap);
        wrap.append(bar, pre);
    });
    markI18nPlaceholders(root);
}

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const fullscreenButton = target.closest('[data-docs-fullscreen="true"]');
    if (fullscreenButton) {
        const wrap = fullscreenButton.closest('.w-docs-code');
        if (!wrap) return;
        event.preventDefault();
        openCodeFullscreen(wrap);
        return;
    }

    const button = target.closest('[data-docs-copy="true"]');
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


const tocState = {
    scrollRoot: null,
    onScroll: null,
    host: null,
    activeId: null,
    lockUntil: 0,
};

function isMobileViewport() {
    return window.matchMedia('(max-width: 768px)').matches;
}

function docsScrollRoot() {
    return contentRoot?.closest('.w-docs-browser__body')
        || document.querySelector('.w-docs-browser__body')
        || null;
}

function slugifyHeading(text, index) {
    let id = String(text || '')
        .toLowerCase()
        .replace(/\s+/g, '-')
        .replace(/[^\w\u4e00-\u9fa5-]/g, '')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
    if (!id || document.getElementById(id)) id = `heading-${index}`;
    return id;
}

function ensureTocHost() {
    const browser = document.querySelector('.w-docs-browser');
    if (!browser) return null;
    let host = browser.querySelector('[data-docs-toc-host]');
    if (host) return host;
    host = document.createElement('div');
    host.className = 'w-docs-toc-host';
    host.dataset.docsTocHost = 'true';
    browser.append(host);
    return host;
}

function clearDocumentToc() {
    if (tocState.onScroll) {
        const target = tocState.scrollRoot || window;
        target.removeEventListener('scroll', tocState.onScroll);
    }
    tocState.scrollRoot = null;
    tocState.onScroll = null;
    tocState.activeId = null;
    tocState.lockUntil = 0;
    const host = tocState.host || document.querySelector('[data-docs-toc-host]');
    if (host) host.replaceChildren();
    tocState.host = host;
    document.body.classList.remove('w-docs-toc-open');
}

function scrollToDocsHeading(headingId) {
    const heading = document.getElementById(headingId);
    if (!heading) return;
    tocState.lockUntil = Date.now() + 4000;
    setActiveDocsTocItem(headingId);
    heading.style.scrollMarginTop = '1.5rem';
    heading.scrollIntoView({ behavior: 'smooth', block: 'start' });
    const url = new URL(window.location.href);
    url.hash = '#' + encodeURIComponent(headingId);
    window.history.replaceState({ ...state, searchKeyword: state.searchKeyword || '' }, '', url);
    heading.classList.add('w-docs-heading-highlight');
    window.setTimeout(() => heading.classList.remove('w-docs-heading-highlight'), 1600);
    if (isMobileViewport()) {
        const panel = document.querySelector('[data-docs-toc]');
        const toggle = document.querySelector('[data-docs-toc-toggle]');
        if (panel) {
            panel.hidden = true;
            panel.classList.remove('is-open');
        }
        if (toggle) toggle.hidden = false;
        document.body.classList.remove('w-docs-toc-open');
    }
}

function setActiveDocsTocItem(headingId) {
    const panel = document.querySelector('[data-docs-toc]');
    if (!panel) return;
    const id = String(headingId || '');
    tocState.activeId = id || null;
    const items = panel.querySelectorAll('[data-docs-toc-item]');
    items.forEach((item) => {
        const active = id !== '' && item.getAttribute('data-id') === id;
        item.classList.toggle('is-active', Boolean(active));
        if (active) {
            const list = panel.querySelector('[data-docs-toc-list]');
            if (list instanceof HTMLElement) {
                const top = item.offsetTop;
                if (top < list.scrollTop) list.scrollTop = Math.max(0, top - 12);
                else if (top + item.offsetHeight > list.scrollTop + list.clientHeight) {
                    list.scrollTop = top - list.clientHeight + item.offsetHeight + 12;
                }
            }
        }
    });
}

function updateActiveDocsTocItem() {
    const panel = document.querySelector('[data-docs-toc]');
    if (!panel) return;
    const scrollRoot = tocState.scrollRoot;
    const rootTop = scrollRoot instanceof HTMLElement
        ? scrollRoot.getBoundingClientRect().top
        : 0;
    const offset = rootTop + 28;
    const headings = Array.from(document.querySelectorAll('.w-docs-article__content h1, .w-docs-article__content h2, .w-docs-article__content h3, .w-docs-article__content h4, .w-docs-article__content h5, .w-docs-article__content h6'));
    let current = headings[0] || null;
    for (let i = headings.length - 1; i >= 0; i -= 1) {
        if (headings[i].getBoundingClientRect().top <= offset) {
            current = headings[i];
            break;
        }
    }
    if (tocState.lockUntil && Date.now() < tocState.lockUntil && tocState.activeId) {
        // Keep the clicked item until spy agrees — avoid the unlock gap that briefly
        // falls back to the previous heading (lockedTop <= offset+8 but still > offset).
        if (current && current.id === tocState.activeId) {
            tocState.lockUntil = 0;
        } else {
            setActiveDocsTocItem(tocState.activeId);
            return;
        }
    }
    if (!headings.length) return;
    setActiveDocsTocItem(current?.id || '');
}

function toggleDocumentToc() {
    const panel = document.querySelector('[data-docs-toc]');
    const toggle = document.querySelector('[data-docs-toc-toggle]');
    if (!panel) return;
    const show = panel.hidden;
    panel.hidden = !show;
    if (show) {
        panel.classList.add('is-open');
        if (toggle) toggle.hidden = true;
        if (isMobileViewport()) document.body.classList.add('w-docs-toc-open');
    } else {
        panel.classList.remove('is-open');
        if (toggle) toggle.hidden = false;
        document.body.classList.remove('w-docs-toc-open');
    }
}

function refreshDocumentToc(contentRootEl) {
    clearDocumentToc();
    const host = ensureTocHost();
    tocState.host = host;
    if (!host || !(contentRootEl instanceof HTMLElement)) return;

    const headings = Array.from(contentRootEl.querySelectorAll('h1, h2, h3, h4, h5, h6'))
        .filter((node) => String(node.textContent || '').trim());
    if (!headings.length) return;

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'w-docs-toc-toggle w-button';
    toggle.dataset.docsTocToggle = 'true';
    toggle.dataset.tone = 'primary';
    toggle.dataset.size = 'sm';
    toggle.hidden = true;
    toggle.setAttribute('aria-label', text.tocShow || '显示目录');
    toggle.title = text.tocShow || '显示目录';
    toggle.textContent = text.toc || '目录';

    const panel = document.createElement('nav');
    panel.className = 'w-docs-toc';
    panel.dataset.docsToc = 'true';
    panel.setAttribute('aria-label', text.toc || '目录');

    const header = document.createElement('div');
    header.className = 'w-docs-toc__header';
    const title = document.createElement('strong');
    title.textContent = text.toc || '目录';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'w-docs-toc__close';
    close.dataset.docsTocToggle = 'true';
    close.setAttribute('aria-label', text.tocHide || '隐藏目录');
    close.textContent = '×';
    header.append(title, close);

    const list = document.createElement('ul');
    list.className = 'w-docs-toc__list';
    list.dataset.docsTocList = 'true';

    headings.forEach((heading, index) => {
        const level = Number(String(heading.tagName || 'H2').slice(1)) || 2;
        const label = String(heading.textContent || '').trim();
        let id = heading.id;
        if (!id) {
            id = slugifyHeading(label, index);
            heading.id = id;
        }
        const item = document.createElement('li');
        item.className = 'w-docs-toc__item';
        item.dataset.docsTocItem = 'true';
        item.dataset.level = String(level);
        item.dataset.id = id;
        const link = document.createElement('a');
        link.href = '#' + encodeURIComponent(id);
        link.textContent = label;
        link.addEventListener('click', (event) => {
            event.preventDefault();
            scrollToDocsHeading(id);
        });
        item.append(link);
        list.append(item);
    });

    if (!list.children.length) return;

    panel.append(header, list);
    host.append(toggle, panel);

    if (isMobileViewport()) {
        panel.hidden = true;
        panel.classList.remove('is-open');
        toggle.hidden = false;
    } else {
        panel.hidden = false;
        panel.classList.add('is-open');
        toggle.hidden = true;
    }

    const scrollRoot = docsScrollRoot();
    tocState.scrollRoot = scrollRoot;
    let ticking = false;
    tocState.onScroll = () => {
        if (ticking) return;
        ticking = true;
        window.requestAnimationFrame(() => {
            updateActiveDocsTocItem();
            ticking = false;
        });
    };
    (scrollRoot || window).addEventListener('scroll', tocState.onScroll, { passive: true });
    updateActiveDocsTocItem();

    if (window.location.hash) {
        window.setTimeout(() => {
            const raw = decodeURIComponent(String(window.location.hash || '').slice(1));
            if (raw) scrollToDocsHeading(raw);
        }, 40);
    }
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
        clearDocumentToc();
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
        refreshDocumentToc(body);
        contextBadge.textContent = String(doc.title || text.documents || '文档');
        if (updateHistory) updateUrl();
    } catch (error) {
        contentRoot.replaceChildren(empty(error.message || text.requestFailed, 'warning'));
    }
}

const SEARCH_QUERY_KEYS = ['search', 'keyword', 'q', 'module'];

function readUrlSearchKeyword(url = new URL(window.location.href)) {
    const params = url.searchParams;
    for (const key of SEARCH_QUERY_KEYS) {
        const value = String(params.get(key) || '').trim();
        if (value) return value;
    }
    return '';
}

function clearUrlSearchParams(url) {
    SEARCH_QUERY_KEYS.forEach((key) => url.searchParams.delete(key));
}

async function search(keyword, updateHistory = true) {
    const query = String(keyword || '').trim();
    if (!query) {
        if (updateHistory) {
            state.searchKeyword = '';
            updateUrl();
        }
        if (state.catalogId) await loadDocuments(state.catalogId, false);
        else {
            clearDocumentToc();
            contentRoot.replaceChildren(empty(text.chooseCatalog || '请选择分类查看文档'));
        }
        return;
    }
    setBusy(contentRoot);
    try {
        const result = await request(`/docs/search?keyword=${encodeURIComponent(query)}&include_catalogs=1`);
        const documents = Array.isArray(result) ? result : (Array.isArray(result.documents) ? result.documents : []);
        clearDocumentToc();
        contentRoot.replaceChildren(documents.length ? documentList(documents, `“${query}”`) : empty(text.emptySearch || '没有找到匹配的文档', 'search'));
        backButton.hidden = true;
        contextBadge.textContent = '搜索';
        state.searchKeyword = query;
        state.catalogId = 0;
        state.documentId = 0;
        if (updateHistory) updateUrl({ search: query });
    } catch (error) {
        contentRoot.replaceChildren(empty(error.message || text.requestFailed, 'warning'));
    }
}

function updateUrl(options = {}) {
    const url = new URL(window.location.href);
    const searchKeyword = String(options.search ?? state.searchKeyword ?? '').trim();
    clearUrlSearchParams(url);
    if (searchKeyword) {
        url.searchParams.set('search', searchKeyword);
        url.searchParams.delete('catalog_id');
        url.searchParams.delete('id');
    } else {
        if (state.catalogId) url.searchParams.set('catalog_id', String(state.catalogId)); else url.searchParams.delete('catalog_id');
        if (state.documentId) url.searchParams.set('id', String(state.documentId)); else url.searchParams.delete('id');
    }
    window.history.pushState({ ...state, searchKeyword }, '', url);
}

let searchTimer = 0;
searchInput?.addEventListener('input', () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => search(searchInput.value), 220);
});

treeRoot?.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-catalog-id]');
    if (trigger) {
        state.searchKeyword = '';
        if (searchInput) searchInput.value = '';
        loadDocuments(Number(trigger.dataset.catalogId));
    }
});

contentRoot?.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-document-id]');
    if (trigger) {
        state.searchKeyword = '';
        loadDocument(Number(trigger.dataset.documentId));
    }
});

document.addEventListener('click', (event) => {
    if (event.target.closest('[data-docs-toc-toggle]')) {
        toggleDocumentToc();
    }
});

backButton?.addEventListener('click', () => state.catalogId && loadDocuments(state.catalogId));

window.addEventListener('popstate', () => {
    const url = new URL(window.location.href);
    state.catalogId = Number(url.searchParams.get('catalog_id') || 0);
    state.documentId = Number(url.searchParams.get('id') || 0);
    const keyword = readUrlSearchKeyword(url);
    state.searchKeyword = keyword;
    if (keyword) {
        if (searchInput) searchInput.value = keyword;
        search(keyword, false);
        return;
    }
    if (searchInput) searchInput.value = '';
    if (state.documentId) loadDocument(state.documentId, false);
    else if (state.catalogId) loadDocuments(state.catalogId, false);
    else contentRoot?.replaceChildren(empty(text.chooseCatalog || '请选择分类查看文档'));
});

loadTree();
const bootSearch = readUrlSearchKeyword();
if (bootSearch) {
    state.searchKeyword = bootSearch;
    if (searchInput) searchInput.value = bootSearch;
    search(bootSearch, false);
} else if (state.documentId) {
    loadDocument(state.documentId, false);
} else if (state.catalogId) {
    loadDocuments(state.catalogId, false);
} else {
    clearDocumentToc();
    contentRoot?.replaceChildren(empty(text.chooseCatalog || '请选择分类查看文档'));
}
