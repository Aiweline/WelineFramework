/**
 * Weline Media Manager — 完全自建前端 JS
 *
 * 通过 connector 协议（cmd/target/targets/name 等）与后端通信，
 * 实现文件浏览/上传/下载/删除/重命名等。
 */
(function () {
    'use strict';

    var CONNECTOR = '';
    var CWD_HASH = '';
    var CWD_INFO = {};
    var FILES = {};
    var TREE = {};
    var SELECTED = [];
    var LOADING = false;
    var ROOT_HASH = '';
    var EXPANDED_NODES = {};
    var STORAGE_KEY = '';
    var START_PATH = '';
    var LAST_CLICKED_HASH = null;
    var SELECTION_MODE = false;
    var GET_FILE_CALLBACK = null;
    var MULTI_SELECT = false;
    var ALLOWED_MIMES = [];
    var IFRAME_MODE = false;
    var I18N = {};

    /* ─── helpers ────────────────────────────────────────────────────── */

    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return (ctx || document).querySelectorAll(sel); }

    function t(key, params) {
        var str = (I18N && I18N[key]) || key;
        if (params) {
            for (var k in params) {
                if (params.hasOwnProperty(k)) {
                    str = str.replace(new RegExp('%\\{' + k + '\\}', 'g'), params[k]);
                }
            }
        }
        return str;
    }

    function api(params, onDone, onErr, options) {
        var isUpload = params instanceof FormData;
        var url = CONNECTOR;
        var opts = options || {};
        if (isUpload) {
            opts.method = 'POST';
            opts.body = params;
            // Linux 下 multipart 有时未正确解析出 target，导致上传到根目录；把 cmd/target 同时放在 URL 上兜底
            if (opts.uploadQuery) {
                url += (url.indexOf('?') >= 0 ? '&' : '?') + opts.uploadQuery;
            }
        } else {
            var q = [];
            for (var k in params) {
                if (params.hasOwnProperty(k)) {
                    var v = params[k];
                    if (Array.isArray(v)) {
                        v.forEach(function (item) { q.push(encodeURIComponent(k + '[]') + '=' + encodeURIComponent(item)); });
                    } else {
                        q.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
                    }
                }
            }
            url += (url.indexOf('?') >= 0 ? '&' : '?') + q.join('&');
            opts.method = 'GET';
        }
        var xhr = new XMLHttpRequest();
        var timedOut = false;
        xhr.open(opts.method, url, true);
        xhr.timeout = 30000;
        if (!isUpload) {
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        }
        xhr.ontimeout = function () {
            timedOut = true;
            (onErr || showError)(t('requestTimeout'));
        };
        xhr.onload = function () {
            if (timedOut) return;
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.error) {
                        (onErr || showError)(Array.isArray(data.error) ? data.error.join(', ') : data.error);
                    } else {
                        onDone && onDone(data);
                    }
                } catch (e) {
                    (onErr || showError)(t('invalidJson'));
                }
            } else {
                (onErr || showError)('HTTP ' + xhr.status);
            }
        };
        xhr.onerror = function () { if (!timedOut) (onErr || showError)(t('networkError')); };
        if (isUpload && xhr.upload) {
            xhr.upload.onprogress = function (ev) {
                if (ev.lengthComputable) updateUploadProgress(Math.round(ev.loaded / ev.total * 100));
            };
        }
        xhr.send(opts.body || null);
    }

    function showError(msg) {
        if (window.BackendToast) {
            window.BackendToast.error(msg);
        } else {
            console.error('[MediaManager]', msg);
        }
    }

    function showSuccess(msg) {
        if (window.BackendToast) {
            window.BackendToast.success(msg);
        } else {
            console.log('[MediaManager]', msg);
        }
    }

    function humanSize(bytes) {
        if (!bytes) return '—';
        var u = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        while (bytes >= 1024 && i < u.length - 1) { bytes /= 1024; i++; }
        return bytes.toFixed(i ? 1 : 0) + ' ' + u[i];
    }

    function isImage(mime) {
        return typeof mime === 'string' && mime.indexOf('image/') === 0;
    }

    function isSvgFile(f) {
        if (!f) return false;
        if (f.mime === 'image/svg+xml') return true;
        return /\.svg$/i.test(String(f.name || ''));
    }

    function getFileResourceUrl(hash) {
        if (!CONNECTOR || !hash) return '';
        var rel = CONNECTOR + (CONNECTOR.indexOf('?') >= 0 ? '&' : '?') + 'cmd=file&target=' + encodeURIComponent(hash);
        try {
            return new URL(rel, document.baseURI).href;
        } catch (e) {
            return rel;
        }
    }

    function getThumbnailUrl(f) {
        if (!f || f.mime === 'directory') return null;
        if (isSvgFile(f)) {
            return getFileResourceUrl(f.hash);
        }
        if (f.tmb && f.tmb !== '1') {
            return f.tmb;
        }
        if (f.tmb === '1' && CONNECTOR) {
            return CONNECTOR + (CONNECTOR.indexOf('?') >= 0 ? '&' : '?') + 'cmd=tmb&target=' + encodeURIComponent(f.hash);
        }
        return null;
    }

    function fileIcon(mime, isDir) {
        if (isDir) return '\uD83D\uDCC1';
        if (!mime) return '\uD83D\uDCC4';
        if (mime.indexOf('image/') === 0) return '\uD83D\uDDBC\uFE0F';
        if (mime.indexOf('video/') === 0) return '\uD83C\uDFA5';
        if (mime.indexOf('audio/') === 0) return '\uD83C\uDFB5';
        if (mime === 'application/pdf') return '\uD83D\uDCC4';
        if (mime.indexOf('zip') >= 0 || mime.indexOf('rar') >= 0 || mime.indexOf('tar') >= 0 || mime.indexOf('7z') >= 0) return '\uD83D\uDCE6';
        return '\uD83D\uDCC4';
    }

    /* ─── init ───────────────────────────────────────────────────────── */

    var CURRENT_STORAGE = 'local';
    var CONFIG = {};
    var AI_STREAM_CONTROLLER = null;
    var AI_GENERATING = false;
    var AI_SESSION_ID = '';
    var AI_MODE = 'text2image';
    var AI_SOURCE_HASH = '';
    var AI_GENERATIONS = [];
    var AI_CURRENT_GENERATION_ID = '';
    var AI_HAS_UNSAVED = false;
    var AI_STREAM_TERMINAL = false;

    function init(connectorUrl, startPath, options) {
        options = options || {};
        CONFIG = options;
        I18N = options.i18n || {};
        if (typeof window !== 'undefined' && window.location && window.location.search) {
            try {
                var urlParams = new URLSearchParams(window.location.search);
                var fromUrl = urlParams.get('initialValue');
                if (fromUrl !== null && fromUrl !== '') CONFIG.initialValue = fromUrl;
            } catch (e) {}
        }
        if (!CONFIG.initialValue && (options.initialValue || '').trim() !== '') {
            CONFIG.initialValue = String(options.initialValue).trim();
        }
        if (options.themeMode && document.documentElement) {
            document.documentElement.setAttribute('data-theme', 'backend');
            document.documentElement.setAttribute('data-theme-mode', options.themeMode === 'dark' ? 'dark' : 'light');
        }
        CONNECTOR = (typeof connectorUrl === 'string' ? connectorUrl : '').trim();
        if (!CONNECTOR) {
            setLoading(false);
            showError(t('connectorNotConfigured'));
            return;
        }
        START_PATH = (typeof startPath === 'string' ? startPath : '').trim();
        STORAGE_KEY = 'mmf_last_path_' + hashCode(START_PATH || '_root_');

        // 检查是否为 iframe 模式（通过 options.isIframe 或检测 window.parent）
        IFRAME_MODE = !!options.isIframe || (window.parent && window.parent !== window);
        MULTI_SELECT = !!options.multi;
        
        bindToolbar();
        bindDragDrop();
        bindContextMenu();
        bindPreviewPanel();
        bindAiDraw();
        
        // 只在非 iframe 模式下加载存储选择器
        if (!IFRAME_MODE) {
            loadStorages();
        }
        
        // iframe 模式下绑定选择工具栏
        if (IFRAME_MODE) {
            window.addEventListener('message', handleParentMessage);
            bindSelectBar();
        }

        var lastHash = loadLastPath();
        if (IFRAME_MODE && (CONFIG.initialValue || '').trim()) {
            lastHash = null;
        }
        if (lastHash) {
            openDir(lastHash, true);
        } else {
            openDir('', true);
        }
    }

    function loadStorages() {
        var select = qs('#mmf-storage-select');
        if (!select || !CONNECTOR) return;

        var url = CONNECTOR + (CONNECTOR.indexOf('?') >= 0 ? '&' : '?') + 'cmd=storages';
        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.storages && Array.isArray(data.storages)) {
                    select.innerHTML = '';
                    data.storages.forEach(function(s) {
                        var opt = document.createElement('option');
                        opt.value = s.name;
                        opt.textContent = s.display_name || s.name;
                        if (s.is_default) {
                            opt.selected = true;
                            CURRENT_STORAGE = s.name;
                        }
                        select.appendChild(opt);
                    });
                }
            })
            .catch(function(e) {
                console.warn('Failed to load storages:', e);
            });

        select.addEventListener('change', function() {
            CURRENT_STORAGE = this.value;
            SELECTED.length = 0;
            FILES = {};
            CWD_HASH = '';
            openDir('', true);
        });
    }

    function hashCode(str) {
        var hash = 0;
        for (var i = 0; i < str.length; i++) {
            var chr = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + chr;
            hash |= 0;
        }
        return 'k' + Math.abs(hash).toString(36);
    }

    function saveLastPath() {
        if (!STORAGE_KEY || !CWD_HASH) return;
        try {
            var state = {
                hash: CWD_HASH,
                expanded: EXPANDED_NODES,
                time: Date.now()
            };
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) {}
    }

    function loadLastPath() {
        if (!STORAGE_KEY) return null;
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return null;
            var state = JSON.parse(raw);
            if (state.expanded) {
                EXPANDED_NODES = state.expanded;
            }
            return state.hash || null;
        } catch (e) {
            return null;
        }
    }

    /* ─── open directory (cmd=open) ──────────────────────────────────── */

    function openDir(target, isInit) {
        saveExpandedState();
        setLoading(true);
        var params = { cmd: 'open', target: target || '' };
        if (CURRENT_STORAGE && CURRENT_STORAGE !== 'local') {
            params.storage = CURRENT_STORAGE;
        }
        if (isInit) {
            params.init = '1';
            params.tree = '1';
            if (START_PATH && !target) {
                params.path = START_PATH;
            }
        } else {
            params.tree = '1';
        }

        api(params, function (data) {
            try {
                setLoading(false);
                CWD_HASH = data.cwd ? data.cwd.hash : '';
                CWD_INFO = data.cwd || {};
                if (isInit && CWD_HASH) {
                    ROOT_HASH = CWD_HASH;
                }

                FILES = {};
                if (data.files) {
                    data.files.forEach(function (f) { FILES[f.hash] = f; });
                }

                if (data.tree) {
                    var newTreeHashes = {};
                    data.tree.forEach(function (f) {
                        newTreeHashes[f.hash] = true;
                    });
                    for (var h in TREE) {
                        if (TREE[h].phash === CWD_HASH && !newTreeHashes[h]) {
                            delete TREE[h];
                        }
                    }
                    data.tree.forEach(function (f) {
                        TREE[f.hash] = f;
                        if (!FILES[f.hash]) FILES[f.hash] = f;
                    });
                }

                SELECTED = [];
                renderTree();
                renderFiles();
                renderPath();
                updateStatus();
                updatePreviewPanel();
                if (IFRAME_MODE && CONFIG.initialValue && !CONFIG._initialSelectionApplied) {
                    CONFIG._initialSelectionApplied = true;
                    applyInitialSelection();
                }
                saveLastPath();
            } catch (e) {
                setLoading(false);
                showError(t('invalidResponse') + ': ' + (e && e.message ? e.message : String(e)));
            }
        }, function (err) {
            setLoading(false);
            showError(err);
        });
    }

    /* ─── rendering ──────────────────────────────────────────────────── */

    function saveExpandedState() {
        var toggles = document.querySelectorAll('.mmf-tree-toggle.expanded');
        toggles.forEach(function (el) {
            var item = el.closest('.mmf-tree-item');
            if (item) {
                EXPANDED_NODES[item.getAttribute('data-hash')] = true;
            }
        });
    }

    function expandToPath(hash) {
        EXPANDED_NODES[hash] = true;
        var node = TREE[hash];
        while (node && node.phash && TREE[node.phash]) {
            EXPANDED_NODES[node.phash] = true;
            node = TREE[node.phash];
        }
    }

    function renderTree() {
        var roots = [];
        var childMap = {};
        for (var h in TREE) {
            var f = TREE[h];
            if (!f.phash || !TREE[f.phash]) {
                roots.push(f);
            } else {
                if (!childMap[f.phash]) childMap[f.phash] = [];
                childMap[f.phash].push(f);
            }
        }

        expandToPath(CWD_HASH);

        var el = qs('.mmf-tree');
        if (!el) return;
        el.innerHTML = buildTreeHtml(roots, childMap);
        bindTreeEvents();
    }

    function buildTreeHtml(nodes, childMap) {
        if (!nodes || !nodes.length) return '';
        var html = '';
        nodes.forEach(function (n) {
            var kids = childMap[n.hash];
            var hasKids = kids && kids.length;
            var isActive = n.hash === CWD_HASH;
            var isExpanded = !!EXPANDED_NODES[n.hash];
            var hasPlaceholder = n.dirs && !hasKids;
            html += '<li>';
            html += '<div class="mmf-tree-item' + (isActive ? ' active' : '') + '" data-hash="' + n.hash + '">';
            html += '<span class="mmf-tree-toggle' + (isExpanded ? ' expanded' : '') + '">';
            html += (hasKids || hasPlaceholder) ? (isExpanded ? '\u25BC' : '\u25B6') : '';
            html += '</span>';
            html += '\uD83D\uDCC1 ' + escHtml(n.name);
            html += '</div>';
            if (hasKids) {
                html += '<ul style="display:' + (isExpanded ? 'block' : 'none') + '">' + buildTreeHtml(kids, childMap) + '</ul>';
            } else if (hasPlaceholder) {
                html += '<ul style="display:none" class="mmf-tree-placeholder"></ul>';
            }
            html += '</li>';
        });
        return html;
    }

    function bindTreeEvents() {
        document.querySelectorAll('.mmf-tree-toggle').forEach(function (toggle) {
            toggle.onclick = function (e) {
                e.stopPropagation();
                var item = toggle.closest('.mmf-tree-item');
                if (!item) return;
                var hash = item.getAttribute('data-hash');
                var ul = item.nextElementSibling;
                if (!ul || ul.tagName !== 'UL') {
                    ul = item.parentElement.querySelector('ul');
                }

                if (ul && ul.classList.contains('mmf-tree-placeholder')) {
                    loadSubtree(hash, ul, toggle);
                } else if (ul) {
                    var isHidden = ul.style.display === 'none';
                    ul.style.display = isHidden ? 'block' : 'none';
                    toggle.classList.toggle('expanded', isHidden);
                    toggle.textContent = isHidden ? '\u25BC' : '\u25B6';
                    if (isHidden) {
                        EXPANDED_NODES[hash] = true;
                    } else {
                        delete EXPANDED_NODES[hash];
                    }
                }
            };
        });

        document.querySelectorAll('.mmf-tree-item').forEach(function (item) {
            item.onclick = function (e) {
                if (e.target.classList.contains('mmf-tree-toggle')) return;
                var hash = item.getAttribute('data-hash');
                openDir(hash);
            };
        });
    }

    function loadSubtree(parentHash, placeholder, toggle) {
        toggle.textContent = '...';
        api({ cmd: 'open', target: parentHash, tree: 1 }, function (err, data) {
            if (err) {
                toggle.textContent = '\u25B6';
                return;
            }
            if (data.files) {
                data.files.forEach(function (f) {
                    TREE[f.hash] = f;
                });
            }
            var kids = [];
            for (var h in TREE) {
                var f = TREE[h];
                if (f.phash === parentHash && f.mime === 'directory') {
                    kids.push(f);
                }
            }
            kids.sort(function (a, b) { return (a.name || '').localeCompare(b.name || ''); });
            placeholder.innerHTML = buildTreeHtml(kids, buildChildMap());
            placeholder.classList.remove('mmf-tree-placeholder');
            placeholder.style.display = 'block';
            toggle.classList.add('expanded');
            toggle.textContent = '\u25BC';
            EXPANDED_NODES[parentHash] = true;
            bindTreeEvents();
        });
    }

    function buildChildMap() {
        var childMap = {};
        for (var h in TREE) {
            var f = TREE[h];
            if (f.phash && TREE[f.phash]) {
                if (!childMap[f.phash]) childMap[f.phash] = [];
                childMap[f.phash].push(f);
            }
        }
        return childMap;
    }

    function renderFiles() {
        var container = qs('.mmf-grid');
        if (!container) return;

        var items = [];
        for (var h in FILES) {
            var f = FILES[h];
            if (f.phash === CWD_HASH || (f.hash === CWD_HASH && f.mime === 'directory')) {
                if (f.hash !== CWD_HASH) items.push(f);
            }
        }

        items.sort(function (a, b) {
            var aDir = a.mime === 'directory' ? 0 : 1;
            var bDir = b.mime === 'directory' ? 0 : 1;
            if (aDir !== bDir) return aDir - bDir;
            return (a.name || '').localeCompare(b.name || '');
        });

        if (!items.length) {
            container.innerHTML = '<div class="mmf-empty"><div class="mmf-empty-icon">\uD83D\uDCC2</div><div>' + t('noFiles') + '</div></div>';
            return;
        }

        var html = '';
        items.forEach(function (f) {
            var isDir = f.mime === 'directory';
            var sel = SELECTED.indexOf(f.hash) >= 0;
            var thumbUrl = getThumbnailUrl(f);
            html += '<div class="mmf-item' + (sel ? ' selected' : '') + '" data-hash="' + f.hash + '" data-mime="' + (f.mime || '') + '">';
            html += '<div class="mmf-item-icon">';
            if (thumbUrl) {
                html += '<img src="' + escAttr(thumbUrl) + '" alt="" loading="lazy" class="mmf-thumb' + (isSvgFile(f) ? ' mmf-thumb-svg' : '') + '" data-fallback-icon="' + escAttr(fileIcon(f.mime, isDir)) + '">';
            } else {
                html += '<span class="mmf-icon-placeholder">' + fileIcon(f.mime, isDir) + '</span>';
            }
            html += '</div>';
            html += '<div class="mmf-item-name" title="' + escAttr(f.name) + '">' + escHtml(f.name) + '</div>';
            html += '</div>';
        });
        container.innerHTML = html;

        bindThumbnailFallbacks(container);
        bindFileEvents(container);
    }

    function bindThumbnailFallbacks(container) {
        qsa('img.mmf-thumb[data-fallback-icon]', container).forEach(function (img) {
            img.addEventListener('error', function () {
                var holder = img.parentElement;
                if (!holder) return;

                var fallback = document.createElement('span');
                fallback.className = 'mmf-icon-placeholder';
                fallback.textContent = img.dataset.fallbackIcon || fileIcon('', false);
                holder.replaceChildren(fallback);
            }, {once: true});
        });
    }

    function renderPath() {
        var el = qs('.mmf-path');
        if (!el) return;
        var parts = [];
        var cur = CWD_HASH;
        while (cur && FILES[cur]) {
            parts.unshift(FILES[cur]);
            cur = FILES[cur].phash;
        }
        var html = '';
        parts.forEach(function (p, i) {
            if (i > 0) html += '<span class="mmf-path-sep">/</span>';
            html += '<span class="mmf-path-seg" data-hash="' + p.hash + '">' + escHtml(p.name) + '</span>';
        });
        el.innerHTML = html;

        qsa('.mmf-path-seg', el).forEach(function (seg) {
            seg.addEventListener('click', function () {
                openDir(seg.dataset.hash);
            });
        });
    }

    function updateStatus() {
        var el = qs('.mmf-status-info');
        if (!el) return;
        var count = 0;
        for (var h in FILES) { if (FILES[h].phash === CWD_HASH) count++; }
        el.textContent = t('itemsCount', {count: count}) + (SELECTED.length ? ', ' + t('selectedCount', {count: SELECTED.length}) : '');
    }

    function setLoading(on) {
        LOADING = on;
        var el = qs('.mmf-content');
        if (!el) return;
        
        var grid = qs('.mmf-grid', el);
        if (!grid) {
            grid = document.createElement('div');
            grid.className = 'mmf-grid';
            el.appendChild(grid);
        }
        
        if (on) {
            grid.style.display = 'none';
            var loadingEl = qs('.mmf-loading', el);
            if (!loadingEl) {
                loadingEl = document.createElement('div');
                loadingEl.className = 'mmf-loading';
                loadingEl.innerHTML = '<span class="mmf-spinner"></span>' + t('loading');
                el.appendChild(loadingEl);
            }
        } else {
            qsa('.mmf-loading', el).forEach(function(l) { l.remove(); });
            grid.style.display = '';
        }
    }

    /* ─── file events ────────────────────────────────────────────────── */

    function bindFileEvents(container) {
        var items = qsa('.mmf-item', container);
        var itemsArray = Array.prototype.slice.call(items);
        
        items.forEach(function (el) {
            el.addEventListener('click', function (e) {
                var hash = el.dataset.hash;
                
                if (SELECTION_MODE) {
                    toggleSelect(hash);
                    LAST_CLICKED_HASH = hash;
                    updateStatus();
                    return;
                }
                
                if (e.shiftKey && LAST_CLICKED_HASH) {
                    var startIdx = -1, endIdx = -1;
                    for (var i = 0; i < itemsArray.length; i++) {
                        if (itemsArray[i].dataset.hash === LAST_CLICKED_HASH) startIdx = i;
                        if (itemsArray[i].dataset.hash === hash) endIdx = i;
                    }
                    if (startIdx >= 0 && endIdx >= 0) {
                        var minIdx = Math.min(startIdx, endIdx);
                        var maxIdx = Math.max(startIdx, endIdx);
                        if (!e.ctrlKey && !e.metaKey) {
                            SELECTED = [];
                        }
                        for (var j = minIdx; j <= maxIdx; j++) {
                            var h = itemsArray[j].dataset.hash;
                            if (SELECTED.indexOf(h) < 0) {
                                SELECTED.push(h);
                            }
                        }
                        highlightSelected();
                    }
                } else if (e.ctrlKey || e.metaKey) {
                    toggleSelect(hash);
                    LAST_CLICKED_HASH = hash;
                } else {
                    SELECTED = [hash];
                    LAST_CLICKED_HASH = hash;
                    highlightSelected();
                }
                updateStatus();
            });

            el.addEventListener('dblclick', function () {
                var hash = el.dataset.hash;
                var f = FILES[hash];
                if (!f) return;
                if (f.mime === 'directory') {
                    openDir(hash);
                } else if (IFRAME_MODE && GET_FILE_CALLBACK) {
                    confirmSelection();
                } else if (isImageMime(f.mime)) {
                    openLightbox(hash);
                } else {
                    downloadFile(hash);
                }
            });

            el.addEventListener('contextmenu', function (e) {
                e.preventDefault();
                var hash = el.dataset.hash;
                if (SELECTED.indexOf(hash) < 0) {
                    if (!SELECTION_MODE && !e.ctrlKey && !e.metaKey) {
                        SELECTED = [hash];
                    } else {
                        SELECTED.push(hash);
                    }
                    highlightSelected();
                }
                showContextMenu(e.pageX, e.pageY);
            });
        });
    }

    function toggleSelect(hash) {
        var idx = SELECTED.indexOf(hash);
        if (idx >= 0) SELECTED.splice(idx, 1);
        else SELECTED.push(hash);
        highlightSelected();
    }

    function highlightSelected() {
        qsa('.mmf-item').forEach(function (el) {
            el.classList.toggle('selected', SELECTED.indexOf(el.dataset.hash) >= 0);
        });
        updatePreviewPanel();
        if (IFRAME_MODE) {
            updateSelectBar();
        }
    }

    /* ─── preview panel ───────────────────────────────────────────────── */

    function updatePreviewPanel() {
        var emptyEl = qs('.mmf-preview-empty');
        var imageEl = qs('.mmf-preview-image');
        var infoEl = qs('.mmf-preview-info');

        if (!emptyEl || !imageEl || !infoEl) return;

        if (SELECTED.length !== 1) {
            emptyEl.style.display = '';
            imageEl.style.display = 'none';
            infoEl.style.display = 'none';
            return;
        }

        var f = FILES[SELECTED[0]];
        if (!f) {
            emptyEl.style.display = '';
            imageEl.style.display = 'none';
            infoEl.style.display = 'none';
            return;
        }

        emptyEl.style.display = 'none';
        infoEl.style.display = '';

        var nameEl = qs('.mmf-preview-name');
        var typeEl = qs('.mmf-preview-type');
        var sizeEl = qs('.mmf-preview-size');
        var dimensionsRow = qs('.mmf-preview-dimensions-row');
        var dimensionsEl = qs('.mmf-preview-dimensions');
        var btnOpen = qs('.mmf-preview-btn-open');
        var btnDownload = qs('.mmf-preview-btn-download');

        if (nameEl) nameEl.textContent = f.name || '';
        if (typeEl) typeEl.textContent = f.mime === 'directory' ? t('folder') : (f.mime || t('unknown'));
        if (sizeEl) sizeEl.textContent = f.mime === 'directory' ? '—' : humanSize(f.size);

        if (isImageMime(f.mime)) {
            imageEl.style.display = '';
            var img = qs('.mmf-preview-img');
            if (img) {
                var previewUrl = getThumbnailUrl(f) || getFileResourceUrl(f.hash) || '';
                img.src = previewUrl;
                if (isSvgFile(f)) {
                    img.style.background = '#fff';
                    img.style.padding = '12px';
                } else {
                    img.style.background = '';
                    img.style.padding = '';
                }
                img.onload = function () {
                    if (dimensionsRow && dimensionsEl) {
                        dimensionsRow.style.display = '';
                        dimensionsEl.textContent = img.naturalWidth + ' × ' + img.naturalHeight;
                    }
                };
                img.onerror = function () {
                    if (dimensionsRow) dimensionsRow.style.display = 'none';
                };
            }
            if (btnOpen) {
                btnOpen.innerHTML = '&#x1F50D; ' + t('preview');
                btnOpen.style.display = '';
            }
        } else {
            imageEl.style.display = 'none';
            if (dimensionsRow) dimensionsRow.style.display = 'none';
            if (f.mime === 'directory') {
                if (btnOpen) {
                    btnOpen.innerHTML = '&#x1F4C2; ' + t('open');
                    btnOpen.style.display = '';
                }
            } else {
                if (btnOpen) btnOpen.style.display = 'none';
            }
        }

        if (btnDownload) {
            btnDownload.style.display = f.mime === 'directory' ? 'none' : '';
        }
        var btnAiEdit = qs('.mmf-preview-btn-ai-edit');
        if (btnAiEdit) {
            btnAiEdit.style.display = (isImageMime(f.mime) && !IFRAME_MODE) ? '' : 'none';
        }
    }

    function bindPreviewPanel() {
        var imageEl = qs('.mmf-preview-image');
        var btnOpen = qs('.mmf-preview-btn-open');
        var btnDownload = qs('.mmf-preview-btn-download');

        if (imageEl) {
            imageEl.onclick = function () {
                if (SELECTED.length === 1) {
                    var f = FILES[SELECTED[0]];
                    if (f && isImageMime(f.mime)) {
                        openLightbox(SELECTED[0]);
                    }
                }
            };
        }

        if (btnOpen) {
            btnOpen.onclick = function () {
                if (SELECTED.length === 1) {
                    var f = FILES[SELECTED[0]];
                    if (!f) return;
                    if (f.mime === 'directory') {
                        openDir(SELECTED[0]);
                    } else if (isImageMime(f.mime)) {
                        openLightbox(SELECTED[0]);
                    }
                }
            };
        }

        if (btnDownload) {
            btnDownload.onclick = function () {
                if (SELECTED.length === 1) {
                    downloadFile(SELECTED[0]);
                }
            };
        }
        var btnAiEdit = qs('.mmf-preview-btn-ai-edit');
        if (btnAiEdit) {
            btnAiEdit.onclick = function () {
                if (SELECTED.length === 1) {
                    var f = FILES[SELECTED[0]];
                    if (f && isImageMime(f.mime)) {
                        openAiDrawModal({ mode: 'image2image', sourceHash: f.hash, sourceName: f.name });
                    }
                }
            };
        }
    }

    /* ─── toolbar ────────────────────────────────────────────────────── */

    function bindToolbar() {
        var btnUpload = qs('#mmf-btn-upload');
        var btnNewFolder = qs('#mmf-btn-newfolder');
        var btnRename = qs('#mmf-btn-rename');
        var btnDelete = qs('#mmf-btn-delete');
        var btnRefresh = qs('#mmf-btn-refresh');
        var btnDownload = qs('#mmf-btn-download');
        var fileInput = qs('#mmf-file-input');

        if (btnUpload) btnUpload.addEventListener('click', function () {
            var drop = qs('.mmf-upload-drop');
            if (drop) drop.classList.toggle('visible');
            if (fileInput) fileInput.click();
        });
        if (fileInput) fileInput.addEventListener('change', function () {
            if (fileInput.files.length) uploadFiles(fileInput.files);
            fileInput.value = '';
        });
        if (btnNewFolder) btnNewFolder.addEventListener('click', function () { promptNewFolder(); });
        if (btnRename) btnRename.addEventListener('click', function () { renameSelected(); });
        if (btnDelete) btnDelete.addEventListener('click', function () { deleteSelected(); });
        if (btnRefresh) btnRefresh.addEventListener('click', function () { openDir(CWD_HASH); });
        if (btnDownload) btnDownload.addEventListener('click', function () {
            if (SELECTED.length === 1) downloadFile(SELECTED[0]);
        });
        var btnAiDraw = qs('#mmf-btn-ai-draw');
        if (btnAiDraw) btnAiDraw.addEventListener('click', function () {
            openAiDrawModal(getAiDrawLaunchOptions());
        });

        /* tree delegation */
        var tree = qs('.mmf-tree');
        if (tree) {
            tree.addEventListener('click', function (e) {
                var item = e.target.closest('.mmf-tree-item');
                if (!item) return;
                var hash = item.dataset.hash;
                if (e.target.classList.contains('mmf-tree-toggle')) {
                    var ul = item.nextElementSibling;
                    if (ul && ul.tagName === 'UL') {
                        ul.style.display = ul.style.display === 'none' ? 'block' : 'none';
                        e.target.textContent = ul.style.display === 'none' ? '\u25B6' : '\u25BC';
                    }
                    return;
                }
                openDir(hash);
            });
        }
    }

    /* ─── upload ──────────────────────────────────────────────────────── */

    function bindDragDrop() {
        var drop = qs('.mmf-upload-drop');
        var content = qs('.mmf-content');
        if (!content) return;

        content.addEventListener('dragover', function (e) {
            e.preventDefault();
            if (drop) { drop.classList.add('visible', 'dragover'); }
        });
        content.addEventListener('dragleave', function (e) {
            if (e.target === content && drop) drop.classList.remove('dragover');
        });
        content.addEventListener('drop', function (e) {
            e.preventDefault();
            if (drop) drop.classList.remove('visible', 'dragover');
            if (e.dataTransfer && e.dataTransfer.files.length) {
                uploadFiles(e.dataTransfer.files);
            }
        });

        if (drop) {
            drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('dragover'); });
            drop.addEventListener('dragleave', function () { drop.classList.remove('dragover'); });
            drop.addEventListener('drop', function (e) {
                e.preventDefault();
                drop.classList.remove('visible', 'dragover');
                if (e.dataTransfer && e.dataTransfer.files.length) {
                    uploadFiles(e.dataTransfer.files);
                }
            });
        }
    }

    function uploadFiles(fileList) {
        if (!CONNECTOR) {
            showError(t('connectorNotConfigured'));
            return;
        }
        if (!fileList || fileList.length === 0) {
            showError(t('noFiles'));
            return;
        }
        if (!CWD_HASH) {
            showError(t('uploadWaitDir'));
            return;
        }
        var fd = new FormData();
        fd.append('cmd', 'upload');
        fd.append('target', CWD_HASH);
        for (var i = 0; i < fileList.length; i++) {
            fd.append('upload[]', fileList[i]);
        }
        showUploadProgress(true);
        updateUploadProgress(0);
        var uploadQuery = 'cmd=upload&target=' + encodeURIComponent(CWD_HASH);
        api(fd, function (data) {
            showUploadProgress(false);
            showSuccess(t('uploadComplete'));
            openDir(CWD_HASH);
        }, function (err) {
            showUploadProgress(false);
            showError(err);
        }, { uploadQuery: uploadQuery });
    }

    function showUploadProgress(visible) {
        var el = qs('.mmf-upload-progress');
        if (el) el.classList.toggle('visible', visible);
    }

    function updateUploadProgress(pct) {
        var bar = qs('.mmf-progress-bar');
        var txt = qs('.mmf-progress-text');
        if (bar) bar.style.width = pct + '%';
        if (txt) txt.textContent = pct + '%';
    }

    /* ─── new folder (cmd=mkdir) ──────────────────────────────────────── */

    function promptNewFolder() {
        showDialog(t('newFolder'), t('folderName'), t('untitled'), function (name) {
            if (!name) return;
            api({ cmd: 'mkdir', target: CWD_HASH, name: name }, function () {
                showSuccess(t('folderCreated'));
                openDir(CWD_HASH);
            });
        });
    }

    /* ─── rename (cmd=rename) ────────────────────────────────────────── */

    function renameSelected() {
        if (SELECTED.length !== 1) { showError(t('selectOneToRename')); return; }
        var f = FILES[SELECTED[0]];
        if (!f) return;
        var oldHash = f.hash;
        var isDir = f.mime === 'directory';
        showDialog(t('rename'), t('newName'), f.name, function (name) {
            if (!name || name === f.name) return;
            api({ cmd: 'rename', target: oldHash, name: name }, function (data) {
                showSuccess(t('renamed'));
                if (isDir) {
                    delete TREE[oldHash];
                    if (data && data.added && data.added.length) {
                        data.added.forEach(function (newFile) {
                            TREE[newFile.hash] = newFile;
                        });
                    }
                }
                openDir(CWD_HASH);
            });
        });
    }

    /* ─── delete (cmd=rm) ────────────────────────────────────────────── */

    function deleteSelected() {
        if (!SELECTED.length) { showError(t('noItemsSelected')); return; }
        var toDelete = SELECTED.slice();
        showConfirm(t('confirmDelete', {count: SELECTED.length}), function () {
            api({ cmd: 'rm', targets: toDelete }, function () {
                showSuccess(t('deleted'));
                toDelete.forEach(function (hash) {
                    delete TREE[hash];
                    delete FILES[hash];
                });
                SELECTED = [];
                openDir(CWD_HASH);
            });
        });
    }

    /* ─── download (cmd=file) ────────────────────────────────────────── */

    function downloadFile(hash) {
        var f = FILES[hash];
        if (!f || f.mime === 'directory') return;
        var url = CONNECTOR + (CONNECTOR.indexOf('?') >= 0 ? '&' : '?') + 'cmd=file&target=' + encodeURIComponent(hash) + '&download=1';
        var a = document.createElement('a');
        a.href = url;
        a.download = f.name || '';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    function copyTextToClipboard(text, onOk, onErr) {
        if (!text) {
            (onErr || showError)(t('copyUrlFailed'));
            return;
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () { onOk && onOk(); }).catch(function () {
                fallbackCopyText(text, onOk, onErr);
            });
        } else {
            fallbackCopyText(text, onOk, onErr);
        }
    }

    function fallbackCopyText(text, onOk, onErr) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            if (document.execCommand('copy')) {
                onOk && onOk();
            } else {
                (onErr || showError)(t('copyUrlFailed'));
            }
        } catch (e) {
            (onErr || showError)(t('copyUrlFailed'));
        }
        document.body.removeChild(ta);
    }

    function copyFileUrl(hash) {
        var f = FILES[hash];
        if (!f || f.mime === 'directory') return;
        var url = getFileResourceUrl(hash);
        copyTextToClipboard(url, function () { showSuccess(t('urlCopied')); }, function () {});
    }

    /* ─── context menu ───────────────────────────────────────────────── */

    function bindContextMenu() {
        document.addEventListener('click', function () { hideContextMenu(); });
        document.addEventListener('contextmenu', function (e) {
            if (!e.target.closest('.mmf-item') && !e.target.closest('.mmf-context-menu')) {
                hideContextMenu();
            }
        });
    }

    function showContextMenu(x, y) {
        var menu = qs('.mmf-context-menu');
        if (!menu) return;
        var f = SELECTED.length === 1 ? FILES[SELECTED[0]] : null;
        var isDir = f && f.mime === 'directory';
        var isImage = f && isImageMime(f.mime);

        var html = '';
        
        if (SELECTION_MODE) {
            html += '<div class="mmf-context-item mmf-context-item-active" data-action="exit-selection">\u2716 ' + t('exitSelectionMode') + '</div>';
            if (SELECTED.length > 0) {
                html += '<div class="mmf-context-item" data-action="clear-selection">\u2718 ' + t('clearSelection') + ' (' + SELECTED.length + ')</div>';
            }
            if (IFRAME_MODE && SELECTED.length > 0) {
                html += '<div class="mmf-context-item mmf-context-item-primary" data-action="confirm-selection">\u2714 ' + t('confirmSelection') + ' (' + SELECTED.length + ')</div>';
            }
            html += '<div class="mmf-context-sep"></div>';
        } else {
            html += '<div class="mmf-context-item" data-action="enter-selection">\u2610 ' + t('selectionMode') + '</div>';
            html += '<div class="mmf-context-sep"></div>';
        }
        
        if (IFRAME_MODE && SELECTED.length > 0 && !SELECTION_MODE) {
            html += '<div class="mmf-context-item mmf-context-item-primary" data-action="confirm-selection">\u2714 ' + t('selectFiles') + '</div>';
            html += '<div class="mmf-context-sep"></div>';
        }
        
        if (f && isImage && !IFRAME_MODE) {
            html += '<div class="mmf-context-item" data-action="ai-edit">\u2728 ' + t('aiEdit') + '</div>';
        }
        if (f && isImage) {
            html += '<div class="mmf-context-item" data-action="preview">\uD83D\uDD0D ' + t('preview') + '</div>';
        }
        if (f && !isDir) {
            html += '<div class="mmf-context-item" data-action="download">\uD83D\uDCE5 ' + t('download') + '</div>';
            html += '<div class="mmf-context-item" data-action="copy-url">\uD83D\uDD17 ' + t('copyUrl') + '</div>';
        }
        if (f && isDir) {
            html += '<div class="mmf-context-item" data-action="open">\uD83D\uDCC2 ' + t('open') + '</div>';
        }
        if (SELECTED.length === 1) {
            html += '<div class="mmf-context-item" data-action="rename">\u270F\uFE0F ' + t('rename') + '</div>';
        }
        if (SELECTED.length) {
            html += '<div class="mmf-context-sep"></div>';
            html += '<div class="mmf-context-item" data-action="delete">\uD83D\uDDD1\uFE0F ' + t('delete') + '</div>';
        }

        menu.innerHTML = html;
        menu.style.left = x + 'px';
        menu.style.top = y + 'px';
        menu.classList.add('visible');

        qsa('.mmf-context-item', menu).forEach(function (it) {
            it.addEventListener('click', function () {
                var action = it.dataset.action;
                hideContextMenu();
                if (action === 'enter-selection') enterSelectionMode();
                else if (action === 'exit-selection') exitSelectionMode();
                else if (action === 'clear-selection') clearSelection();
                else if (action === 'confirm-selection') confirmSelection();
                else if (action === 'preview' && SELECTED.length === 1) openLightbox(SELECTED[0]);
                else if (action === 'ai-edit' && SELECTED.length === 1) {
                    var sf = FILES[SELECTED[0]];
                    if (sf && isImageMime(sf.mime)) {
                        openAiDrawModal({ mode: 'image2image', sourceHash: sf.hash, sourceName: sf.name });
                    }
                }
                else if (action === 'download' && SELECTED.length === 1) downloadFile(SELECTED[0]);
                else if (action === 'copy-url' && SELECTED.length === 1) copyFileUrl(SELECTED[0]);
                else if (action === 'open' && SELECTED.length === 1) openDir(SELECTED[0]);
                else if (action === 'rename') renameSelected();
                else if (action === 'delete') deleteSelected();
            });
        });
    }

    function hideContextMenu() {
        var menu = qs('.mmf-context-menu');
        if (menu) menu.classList.remove('visible');
    }

    /* ─── selection mode ─────────────────────────────────────────────── */

    function enterSelectionMode() {
        SELECTION_MODE = true;
        var wrap = qs('.mmf-wrap');
        if (wrap) wrap.classList.add('mmf-selection-mode');
        updateStatus();
    }

    function exitSelectionMode() {
        SELECTION_MODE = false;
        var wrap = qs('.mmf-wrap');
        if (wrap) wrap.classList.remove('mmf-selection-mode');
        updateStatus();
    }

    function clearSelection() {
        SELECTED = [];
        highlightSelected();
        updateStatus();
        if (SELECTED.length === 0) {
            exitSelectionMode();
        }
    }

    function invertSelection() {
        var items = [];
        for (var h in FILES) {
            var f = FILES[h];
            if ((f.phash === CWD_HASH || f.hash === CWD_HASH) && f.hash !== CWD_HASH && f.mime !== 'directory') {
                items.push(f.hash);
            }
        }
        var newSelected = [];
        items.forEach(function (hash) {
            if (SELECTED.indexOf(hash) < 0) newSelected.push(hash);
        });
        SELECTED = newSelected;
        highlightSelected();
        updateStatus();
        if (IFRAME_MODE) {
            updateSelectBar();
        }
    }

    function normalizePathForMatch(p) {
        if (!p || typeof p !== 'string') return '';
        return p.trim().replace(/^\/pub\/media\//, '').replace(/^pub\/media\//, '').replace(/\\/g, '/').replace(/\/+$/, '');
    }

    function applyInitialSelection() {
        var raw = (CONFIG.initialValue || '').trim();
        if (!raw) return;
        var paths = raw.split(',').map(function (p) {
            return normalizePathForMatch(p);
        });
        var pathSet = {};
        paths.forEach(function (p) { if (p) pathSet[p] = true; });
        SELECTED = [];
        for (var h in FILES) {
            var f = FILES[h];
            if (f.mime === 'directory') continue;
            var fp = normalizePathForMatch(f.path || '');
            if (pathSet[fp]) SELECTED.push(h);
        }
        highlightSelected();
        updateStatus();
        if (IFRAME_MODE) updateSelectBar();
    }

    function confirmSelection() {
        if (!SELECTED.length) {
            showError(t('pleaseSelectFile'));
            return;
        }
        
        var selectedFiles = [];
        SELECTED.forEach(function (hash) {
            var f = FILES[hash];
            if (f && f.mime !== 'directory') {
                var relativePath = f.path || '';
                var fileUrl = '/pub/media/' + relativePath;
                var thumbUrl = getThumbnailUrl(f) || fileUrl;
                selectedFiles.push({
                    hash: f.hash,
                    name: f.name,
                    mime: f.mime,
                    size: f.size,
                    path: fileUrl,
                    url: fileUrl,
                    thumb: thumbUrl
                });
            }
        });
        
        if (!selectedFiles.length) {
            showError(t('pleaseSelectValidFiles'));
            return;
        }
        
        if (!MULTI_SELECT && selectedFiles.length > 1) {
            selectedFiles = [selectedFiles[0]];
        }
        
        if (GET_FILE_CALLBACK) {
            GET_FILE_CALLBACK(selectedFiles);
        }
        
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                type: 'weline-media-manager-select',
                target: CONFIG.target || '',
                files: selectedFiles,
                multi: MULTI_SELECT
            }, '*');
        }
        
        exitSelectionMode();
        SELECTED = [];
        highlightSelected();
        updateSelectBar();
    }

    /* ─── iframe / file-manager integration ───────────────────────────── */

    function bindSelectBar() {
        var btnSelect = qs('#mmf-btn-select');
        var btnConfirmSelect = qs('#mmf-btn-confirm-select');
        var btnClearSelect = qs('#mmf-btn-clear-select');
        var btnCancel = qs('#mmf-btn-cancel');

        if (btnSelect) {
            btnSelect.addEventListener('click', function () { confirmSelection(); });
        }
        if (btnConfirmSelect) {
            btnConfirmSelect.addEventListener('click', function () { confirmSelection(); });
        }
        if (btnClearSelect) {
            btnClearSelect.addEventListener('click', function () {
                SELECTED = [];
                highlightSelected();
                updateSelectBar();
                updateStatus();
            });
        }
        var btnInvertSelect = qs('#mmf-btn-invert-select');
        if (btnInvertSelect) {
            btnInvertSelect.addEventListener('click', function () { invertSelection(); });
        }
        if (btnCancel) {
            btnCancel.addEventListener('click', function () {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({
                        type: 'weline-media-manager-cancel',
                        target: CONFIG.target || ''
                    }, '*');
                }
            });
        }
    }

    function updateSelectBar() {
        var bar = qs('#mmf-select-bar');
        var countEl = qs('#mmf-select-count-num');
        var wrap = qs('.mmf-wrap');
        
        if (!bar) return;
        
        if (MULTI_SELECT && SELECTED.length > 0) {
            bar.style.display = 'flex';
            if (wrap) wrap.classList.add('with-select-bar');
        } else {
            bar.style.display = 'none';
            if (wrap) wrap.classList.remove('with-select-bar');
        }
        
        if (countEl) {
            countEl.textContent = SELECTED.length;
        }
    }

    function setupIframeMode(options) {
        options = options || {};
        IFRAME_MODE = true;
        MULTI_SELECT = !!options.multi;
        GET_FILE_CALLBACK = options.callback || null;
        
        if (options.mimes && Array.isArray(options.mimes)) {
            ALLOWED_MIMES = options.mimes;
        }
        
        var wrap = qs('.mmf-wrap');
        if (wrap) wrap.classList.add('mmf-iframe-mode');
    }

    function handleParentMessage(e) {
        if (!e.data || typeof e.data !== 'object') return;
        
        if (e.data.type === 'weline-media-manager-init') {
            setupIframeMode({
                multi: e.data.multi,
                mimes: e.data.mimes,
                callback: function (files) {
                    if (window.parent && window.parent !== window) {
                        window.parent.postMessage({
                            type: 'weline-media-manager-select',
                            files: files
                        }, '*');
                    }
                }
            });
        }
        
        if (e.data.type === 'weline-media-manager-close') {
            SELECTED = [];
            highlightSelected();
            exitSelectionMode();
        }
    }

    /* ─── lightbox ───────────────────────────────────────────────────── */

    var LIGHTBOX_IMAGES = [];
    var LIGHTBOX_INDEX = 0;

    function isImageMime(mime) {
        if (!mime) return false;
        return mime.indexOf('image/') === 0;
    }

    function getImagesInCurrentDir() {
        var images = [];
        for (var h in FILES) {
            var f = FILES[h];
            if (f.phash === CWD_HASH && isImageMime(f.mime)) {
                images.push(f);
            }
        }
        images.sort(function (a, b) {
            return (a.name || '').localeCompare(b.name || '');
        });
        return images;
    }

    function openLightbox(hash) {
        LIGHTBOX_IMAGES = getImagesInCurrentDir();
        LIGHTBOX_INDEX = 0;
        for (var i = 0; i < LIGHTBOX_IMAGES.length; i++) {
            if (LIGHTBOX_IMAGES[i].hash === hash) {
                LIGHTBOX_INDEX = i;
                break;
            }
        }
        if (!LIGHTBOX_IMAGES.length) return;
        showLightbox();
    }

    function showLightbox() {
        var lb = qs('.mmf-lightbox');
        if (!lb) return;

        lb.classList.add('visible');
        updateLightboxImage();
        renderLightboxThumbs();
        bindLightboxEvents();
        document.body.style.overflow = 'hidden';
    }

    function hideLightbox() {
        var lb = qs('.mmf-lightbox');
        if (lb) lb.classList.remove('visible');
        document.body.style.overflow = '';
    }

    function updateLightboxImage() {
        var f = LIGHTBOX_IMAGES[LIGHTBOX_INDEX];
        if (!f) return;

        var img = qs('.mmf-lightbox-img');
        var title = qs('.mmf-lightbox-title');
        var counter = qs('.mmf-lightbox-counter');
        var prevBtn = qs('.mmf-lightbox-prev');
        var nextBtn = qs('.mmf-lightbox-next');

        if (img) {
            img.style.opacity = '0.5';
            var imgUrl = '';
            if (f.url) {
                imgUrl = f.url;
            } else if (CONNECTOR) {
                imgUrl = getFileResourceUrl(f.hash);
            }
            if (isSvgFile(f)) {
                img.style.background = '#fff';
                img.style.padding = '24px';
            } else {
                img.style.background = '';
                img.style.padding = '';
            }
            img.onload = function () { img.style.opacity = '1'; };
            img.src = imgUrl;
        }
        if (title) title.textContent = f.name || '';
        if (counter) counter.textContent = (LIGHTBOX_INDEX + 1) + ' / ' + LIGHTBOX_IMAGES.length;
        if (prevBtn) prevBtn.disabled = LIGHTBOX_INDEX <= 0;
        if (nextBtn) nextBtn.disabled = LIGHTBOX_INDEX >= LIGHTBOX_IMAGES.length - 1;

        updateThumbActive();
    }

    function renderLightboxThumbs() {
        var container = qs('.mmf-lightbox-thumbs');
        if (!container) return;

        var html = '';
        LIGHTBOX_IMAGES.forEach(function (f, i) {
            var thumbUrl = getThumbnailUrl(f) || '';
            var activeClass = i === LIGHTBOX_INDEX ? ' active' : '';
            html += '<div class="mmf-lightbox-thumb' + activeClass + '" data-index="' + i + '">';
            if (thumbUrl) {
                html += '<img src="' + escAttr(thumbUrl) + '" alt="" loading="lazy"' + (isSvgFile(f) ? ' style="background:#fff;padding:4px;"' : '') + '>';
            } else {
                html += '<span style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:#333;">\uD83D\uDDBC</span>';
            }
            html += '</div>';
        });
        container.innerHTML = html;

        qsa('.mmf-lightbox-thumb', container).forEach(function (el) {
            el.onclick = function () {
                LIGHTBOX_INDEX = parseInt(el.dataset.index, 10) || 0;
                updateLightboxImage();
            };
        });
    }

    function updateThumbActive() {
        qsa('.mmf-lightbox-thumb').forEach(function (el, i) {
            el.classList.toggle('active', i === LIGHTBOX_INDEX);
        });
        var activeThumb = qs('.mmf-lightbox-thumb.active');
        if (activeThumb) {
            activeThumb.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
    }

    function lightboxPrev() {
        if (LIGHTBOX_INDEX > 0) {
            LIGHTBOX_INDEX--;
            updateLightboxImage();
        }
    }

    function lightboxNext() {
        if (LIGHTBOX_INDEX < LIGHTBOX_IMAGES.length - 1) {
            LIGHTBOX_INDEX++;
            updateLightboxImage();
        }
    }

    function bindLightboxEvents() {
        var lb = qs('.mmf-lightbox');
        if (!lb || lb._bound) return;
        lb._bound = true;

        qs('.mmf-lightbox-close', lb).onclick = hideLightbox;
        qs('.mmf-lightbox-prev', lb).onclick = lightboxPrev;
        qs('.mmf-lightbox-next', lb).onclick = lightboxNext;

        lb.onclick = function (e) {
            if (e.target === lb || e.target.classList.contains('mmf-lightbox-main')) {
                hideLightbox();
            }
        };

        document.addEventListener('keydown', function (e) {
            if (!lb.classList.contains('visible')) return;
            if (e.key === 'Escape') hideLightbox();
            else if (e.key === 'ArrowLeft') lightboxPrev();
            else if (e.key === 'ArrowRight') lightboxNext();
        });
    }

    /* ─── dialogs ────────────────────────────────────────────────────── */

    function showDialog(title, label, defaultVal, onOk) {
        var overlay = qs('.mmf-dialog-overlay');
        if (!overlay) return;
        qs('.mmf-dialog-title', overlay).textContent = title;
        var inp = qs('.mmf-dialog-input', overlay);
        inp.value = defaultVal || '';
        inp.placeholder = label || '';
        overlay.classList.add('visible');
        inp.focus();
        inp.select();

        var okBtn = qs('.mmf-dialog-ok', overlay);
        var cancelBtn = qs('.mmf-dialog-cancel', overlay);

        function close() {
            overlay.classList.remove('visible');
            okBtn.removeEventListener('click', handleOk);
            cancelBtn.removeEventListener('click', handleCancel);
            inp.removeEventListener('keydown', handleKey);
        }
        function handleOk() { close(); onOk(inp.value.trim()); }
        function handleCancel() { close(); }
        function handleKey(e) { if (e.key === 'Enter') handleOk(); else if (e.key === 'Escape') handleCancel(); }

        okBtn.addEventListener('click', handleOk);
        cancelBtn.addEventListener('click', handleCancel);
        inp.addEventListener('keydown', handleKey);
    }

    function showConfirm(msg, onOk) {
        showDialog(t('confirm'), '', msg, function (val) { onOk(); });
    }

    /* ─── AI 作图 ───────────────────────────────────────────────────── */

    function getAiDrawLaunchOptions() {
        if (SELECTED.length === 1) {
            var f = FILES[SELECTED[0]];
            if (f && isImageMime(f.mime)) {
                return { mode: 'image2image', sourceHash: f.hash, sourceName: f.name };
            }
        }
        return { mode: 'text2image' };
    }

    function bindAiDraw() {
        if (!CONFIG.aiDrawStreamUrl) return;
        qsa('.mmf-ai-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                if (isAiGenerating()) return;
                setAiMode(tab.dataset.mode || 'text2image');
            });
        });
        var closeBtn = qs('#mmf-ai-draw-close');
        var cancelBtn = qs('#mmf-ai-btn-cancel');
        var overlay = qs('#mmf-ai-draw-overlay');
        if (closeBtn) closeBtn.addEventListener('click', requestCloseAiDrawModal);
        if (cancelBtn) cancelBtn.addEventListener('click', requestCloseAiDrawModal);
        if (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) requestCloseAiDrawModal();
            });
        }
        var modal = qs('#mmf-ai-draw-modal');
        if (modal) {
            modal.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        }
        var genBtn = qs('#mmf-ai-btn-generate');
        if (genBtn) genBtn.addEventListener('click', startAiGeneration);
        var contBtn = qs('#mmf-ai-btn-continue');
        if (contBtn) contBtn.addEventListener('click', continueAiEdit);
        var saveBtn = qs('#mmf-ai-btn-save');
        if (saveBtn) saveBtn.addEventListener('click', openAiSaveDialog);
        var refPreviewBtn = qs('#mmf-ai-ref-preview-btn');
        if (refPreviewBtn) {
            refPreviewBtn.addEventListener('click', function () {
                if (AI_SOURCE_HASH) openLightbox(AI_SOURCE_HASH);
            });
        }
        var refImg = qs('#mmf-ai-ref-img');
        if (refImg) {
            refImg.addEventListener('click', function () {
                if (AI_SOURCE_HASH) openLightbox(AI_SOURCE_HASH);
            });
        }
        var saveCancel = qs('#mmf-ai-save-cancel');
        var saveConfirm = qs('#mmf-ai-save-confirm');
        if (saveCancel) saveCancel.addEventListener('click', closeAiSaveDialog);
        if (saveConfirm) saveConfirm.addEventListener('click', confirmAiSave);
        var refSearch = qs('#mmf-ai-ref-search');
        if (refSearch) {
            refSearch.addEventListener('input', function () {
                renderAiRefPicker();
            });
        }
    }

    function isAiGenerating() {
        return AI_GENERATING || !!AI_STREAM_CONTROLLER;
    }

    function setAiBusy(busy) {
        AI_GENERATING = !!busy;
        var modal = qs('#mmf-ai-draw-modal');
        if (modal) {
            modal.classList.toggle('is-busy', AI_GENERATING);
            modal.setAttribute('aria-busy', AI_GENERATING ? 'true' : 'false');
        }
        setAiPreviewLoading(AI_GENERATING);
        setAiStatus(AI_GENERATING ? t('aiRunningHint') : '', AI_GENERATING ? 'running' : '');

        ['#mmf-ai-btn-generate', '#mmf-ai-btn-continue', '#mmf-ai-btn-save'].forEach(function (sel) {
            var el = qs(sel);
            if (!el) return;
            if (AI_GENERATING) {
                el.disabled = true;
            } else if (sel === '#mmf-ai-btn-save') {
                el.disabled = !AI_GENERATIONS.length;
            } else {
                el.disabled = false;
            }
        });

        qsa('.mmf-ai-tab').forEach(function (tab) {
            tab.disabled = AI_GENERATING;
        });

        ['#mmf-ai-prompt', '#mmf-ai-batch-prompts', '#mmf-ai-batch-count', '#mmf-ai-size', '#mmf-ai-format'].forEach(function (sel) {
            var el = qs(sel);
            if (el) el.disabled = AI_GENERATING;
        });
    }

    function setAiPreviewLoading(visible) {
        var loading = qs('#mmf-ai-preview-loading');
        var empty = qs('#mmf-ai-preview-empty');
        var loadingText = qs('#mmf-ai-loading-text');
        if (loading) {
            loading.classList.toggle('is-visible', !!visible);
            loading.style.display = visible ? 'flex' : 'none';
        }
        if (loadingText && visible) {
            loadingText.textContent = t('aiGenerating');
        }
        if (empty) {
            empty.style.display = visible ? 'none' : (AI_GENERATIONS.length ? 'none' : '');
        }
    }

    function updateAiConfigBanner(cfg) {
        cfg = cfg || {};
        var el = qs('#mmf-ai-config-banner');
        if (!el) return;
        if (cfg.mock) {
            el.textContent = t('aiMockModeHint');
            el.className = 'mmf-ai-config-banner is-mock';
            el.style.display = '';
            return;
        }
        if (!cfg.ready) {
            el.textContent = cfg.message || t('aiModelNotReady');
            el.className = 'mmf-ai-config-banner is-warn';
            el.style.display = '';
            return;
        }
        var model = String(cfg.model || '').trim();
        if (!model) {
            el.style.display = 'none';
            el.textContent = '';
            return;
        }
        el.textContent = t('aiModelLabel') + model;
        el.className = 'mmf-ai-config-banner is-ready';
        el.style.display = '';
    }

    function refreshAiDrawConfig() {
        if (!CONFIG.aiDrawConfigUrl) return;
        fetch(CONFIG.aiDrawConfigUrl, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        }).then(function (res) {
            var data = res && res.data ? res.data : res;
            updateAiConfigBanner(data || {});
        }).catch(function () {});
    }

    function buildAiPreviewUrl(sessionId, generationId, previewToken) {
        if (!CONFIG.aiDrawPreviewUrl || !sessionId || !generationId) return '';
        var base = String(CONFIG.aiDrawPreviewUrl);
        var sep = base.indexOf('?') >= 0 ? '&' : '?';
        var url = base + sep + 'session_id=' + encodeURIComponent(sessionId) + '&generation_id=' + encodeURIComponent(generationId);
        if (previewToken) {
            url += '&preview_token=' + encodeURIComponent(previewToken);
        }
        return url;
    }

    function setAiStatus(text, state) {
        var el = qs('#mmf-ai-status');
        if (!el) return;
        el.textContent = text || '';
        el.classList.remove('is-running', 'is-success', 'is-error');
        if (state) el.classList.add('is-' + state);
        el.style.display = text ? 'inline-flex' : 'none';
    }

    function resolveAiErrorMessage(data) {
        if (!data) return t('networkError');
        if (typeof data === 'string') return data.trim() || t('networkError');
        var msg = String(data.message || data.error || '').trim();
        if (!msg && data.code) {
            msg = String(data.code);
        }
        return msg || t('networkError');
    }

    function clearAiError() {
        var errorEl = qs('#mmf-ai-error');
        if (errorEl) {
            errorEl.innerHTML = '';
            errorEl.style.display = 'none';
        }
        var progressEl = qs('#mmf-ai-progress');
        if (progressEl) progressEl.classList.remove('is-error');
    }

    function setAiError(message) {
        var msg = String(message || '').trim();
        var errorEl = qs('#mmf-ai-error');
        var empty = qs('#mmf-ai-preview-empty');
        if (!msg) {
            clearAiError();
            if (empty && !AI_GENERATIONS.length) empty.style.display = '';
            return;
        }
        if (errorEl) {
            errorEl.innerHTML = '<div class="mmf-ai-error-title">' + escHtml(t('aiGenerateFailed')) + '</div>' +
                '<div class="mmf-ai-error-message">' + escHtml(msg) + '</div>';
            errorEl.style.display = 'block';
        }
        if (empty) empty.style.display = 'none';
        setAiPreviewLoading(false);
        setAiStatus(t('aiGenerateFailed'), 'error');
    }

    function reportAiError(data) {
        var msg = resolveAiErrorMessage(data);
        setAiError(msg);
        setAiProgress(msg, true);
        showError(msg);
    }

    function requestCloseAiDrawModal() {
        if (isAiGenerating()) {
            showConfirm(t('aiCloseRunningConfirm'), function () {
                finishAiDrawClose();
            });
            return;
        }
        if (AI_HAS_UNSAVED) {
            showConfirm(t('aiCloseConfirm'), function () {
                finishAiDrawClose();
            });
            return;
        }
        finishAiDrawClose();
    }

    function finishAiDrawClose() {
        abortAiStream();
        setAiBusy(false);
        setAiStatus('');
        clearAiError();
        var overlay = qs('#mmf-ai-draw-overlay');
        if (overlay) overlay.classList.remove('visible');
        closeAiSaveDialog();
    }

    function aiModeNeedsReferencePicker(mode) {
        return mode === 'image2image' || mode === 'batch';
    }

    function resolveDefaultAiReferenceHash() {
        if (SELECTED.length === 1) {
            var selected = FILES[SELECTED[0]];
            if (selected && isImageMime(selected.mime)) {
                return selected.hash;
            }
        }
        var images = getImagesInCurrentDir();
        return images.length === 1 ? images[0].hash : '';
    }

    function selectAiReference(hash, sourceName) {
        if (isAiGenerating()) return;
        AI_SOURCE_HASH = hash || '';
        updateAiReferencePreview(sourceName || '');
        renderAiRefPicker();
    }

    function updateAiReferencePreview(sourceName) {
        var refImg = qs('#mmf-ai-ref-img');
        var refName = qs('#mmf-ai-ref-name');
        var refEmpty = qs('#mmf-ai-ref-empty');
        var previewBtn = qs('#mmf-ai-ref-preview-btn');
        if (!AI_SOURCE_HASH) {
            if (refImg) {
                refImg.style.display = 'none';
                refImg.removeAttribute('src');
            }
            if (refEmpty) refEmpty.style.display = '';
            if (refName) refName.textContent = '';
            if (previewBtn) previewBtn.style.display = 'none';
            return;
        }
        var file = FILES[AI_SOURCE_HASH];
        var previewUrl = file ? (getThumbnailUrl(file) || getFileResourceUrl(AI_SOURCE_HASH)) : getFileResourceUrl(AI_SOURCE_HASH);
        if (refImg) {
            refImg.src = previewUrl;
            refImg.style.display = previewUrl ? 'block' : 'none';
        }
        if (refEmpty) refEmpty.style.display = previewUrl ? 'none' : '';
        if (refName) refName.textContent = sourceName || (file ? file.name : '');
        if (previewBtn) previewBtn.style.display = previewUrl ? '' : 'none';
    }

    function getAiRefSearchQuery() {
        var el = qs('#mmf-ai-ref-search');
        return el ? String(el.value || '').trim().toLowerCase() : '';
    }

    function filterImagesForRefPicker(images, query) {
        if (!query) return images;
        return images.filter(function (file) {
            return String(file.name || '').toLowerCase().indexOf(query) >= 0;
        });
    }

    function resetAiRefSearch() {
        var el = qs('#mmf-ai-ref-search');
        if (el) el.value = '';
    }

    function renderAiRefPicker() {
        var picker = qs('#mmf-ai-ref-picker');
        var pickerEmpty = qs('#mmf-ai-ref-picker-empty');
        var pickerNoMatch = qs('#mmf-ai-ref-picker-no-match');
        if (!picker) return;
        if (!aiModeNeedsReferencePicker(AI_MODE)) {
            picker.innerHTML = '';
            if (pickerEmpty) pickerEmpty.style.display = 'none';
            if (pickerNoMatch) pickerNoMatch.style.display = 'none';
            return;
        }
        var images = getImagesInCurrentDir();
        var query = getAiRefSearchQuery();
        var filtered = filterImagesForRefPicker(images, query);
        if (!images.length) {
            picker.innerHTML = '';
            if (pickerEmpty) pickerEmpty.style.display = '';
            if (pickerNoMatch) pickerNoMatch.style.display = 'none';
            return;
        }
        if (!filtered.length) {
            picker.innerHTML = '';
            if (pickerEmpty) pickerEmpty.style.display = 'none';
            if (pickerNoMatch) pickerNoMatch.style.display = '';
            return;
        }
        if (pickerEmpty) pickerEmpty.style.display = 'none';
        if (pickerNoMatch) pickerNoMatch.style.display = 'none';
        picker.innerHTML = filtered.map(function (file) {
            var thumb = getThumbnailUrl(file) || getFileResourceUrl(file.hash);
            var selected = file.hash === AI_SOURCE_HASH;
            return '<button type="button" class="mmf-ai-ref-item' + (selected ? ' selected' : '') + '" data-hash="' + escAttr(file.hash) + '" title="' + escAttr(file.name || '') + '">' +
                '<img src="' + escAttr(thumb) + '" alt="' + escAttr(file.name || '') + '">' +
                '<span class="mmf-ai-ref-item-name">' + escHtml(file.name || '') + '</span>' +
                '</button>';
        }).join('');
        qsa('.mmf-ai-ref-item', picker).forEach(function (btn) {
            btn.addEventListener('click', function () {
                selectAiReference(btn.getAttribute('data-hash') || '', btn.getAttribute('title') || '');
            });
        });
    }

    function syncAiReferencePanel(sourceName) {
        var refPanel = qs('#mmf-ai-ref-panel');
        var refSide = qs('#mmf-ai-ref-side');
        var workspace = qs('#mmf-ai-draw-workspace');
        var needsRef = aiModeNeedsReferencePicker(AI_MODE);
        if (workspace) {
            workspace.classList.toggle('is-text2image', !needsRef);
        }
        if (refPanel) {
            refPanel.style.display = needsRef ? '' : 'none';
        }
        if (refSide) {
            refSide.style.display = needsRef ? '' : 'none';
        }
        if (!needsRef) {
            return;
        }
        if (!AI_SOURCE_HASH) {
            AI_SOURCE_HASH = resolveDefaultAiReferenceHash();
        }
        updateAiReferencePreview(sourceName || '');
        renderAiRefPicker();
    }

    function setAiMode(mode) {
        if (isAiGenerating()) return;
        AI_MODE = mode || 'text2image';
        qsa('.mmf-ai-tab').forEach(function (tab) {
            tab.classList.toggle('active', tab.dataset.mode === AI_MODE);
        });
        var batchPanel = qs('#mmf-ai-batch-panel');
        if (batchPanel) batchPanel.style.display = AI_MODE === 'batch' ? '' : 'none';
        syncAiReferencePanel();
        var contBtn = qs('#mmf-ai-btn-continue');
        if (contBtn) contBtn.style.display = (AI_MODE === 'image2image' || AI_CURRENT_GENERATION_ID) ? '' : 'none';
    }

    function openAiDrawModal(options) {
        options = options || {};
        if (!CONFIG.aiDrawStreamUrl) {
            showError(t('connectorNotConfigured'));
            return;
        }
        AI_SESSION_ID = '';
        AI_GENERATIONS = [];
        AI_CURRENT_GENERATION_ID = '';
        AI_HAS_UNSAVED = false;
        AI_SOURCE_HASH = options.sourceHash || '';
        AI_MODE = options.mode || (AI_SOURCE_HASH ? 'image2image' : 'text2image');
        resetAiRefSearch();
        setAiMode(AI_MODE);
        resetAiPreview();
        clearAiError();
        updateAiTargetPath();
        syncAiReferencePanel(options.sourceName || '');
        setAiProgress('');
        setAiSaveEnabled(false);
        setAiBusy(false);
        setAiStatus('');
        var overlay = qs('#mmf-ai-draw-overlay');
        if (overlay) overlay.classList.add('visible');
        refreshAiDrawConfig();
        var prompt = qs('#mmf-ai-prompt');
        if (prompt) {
            prompt.value = '';
            prompt.placeholder = t('aiPromptPlaceholder') || '';
        }
        var history = qs('#mmf-ai-history');
        var historyWrap = qs('#mmf-ai-history-wrap');
        if (history) history.innerHTML = '';
        if (historyWrap) historyWrap.style.display = 'none';
    }

    function closeAiDrawModal() {
        requestCloseAiDrawModal();
    }

    function formatAiTargetPath() {
        var parts = [];
        var cur = CWD_HASH;
        while (cur && FILES[cur]) {
            parts.unshift(FILES[cur].name);
            cur = FILES[cur].phash;
        }
        if (parts.length) {
            return parts.join(' / ');
        }
        if (CWD_INFO && CWD_INFO.path) {
            return String(CWD_INFO.path).split('/').filter(Boolean).join(' / ') || (CWD_INFO.name || '/');
        }
        return (CWD_INFO && CWD_INFO.name) || CWD_HASH || '/';
    }

    function updateAiTargetPath() {
        var el = qs('#mmf-ai-target-path');
        if (!el) return;
        el.textContent = formatAiTargetPath();
    }

    function resetAiPreview() {
        var empty = qs('#mmf-ai-preview-empty');
        var img = qs('#mmf-ai-preview-img');
        var grid = qs('#mmf-ai-preview-grid');
        var loading = qs('#mmf-ai-preview-loading');
        if (loading) {
            loading.classList.remove('is-visible');
            loading.style.display = 'none';
        }
        if (empty) empty.style.display = '';
        if (img) { img.style.display = 'none'; img.removeAttribute('src'); }
        if (grid) {
            grid.innerHTML = '';
            grid.style.display = 'none';
        }
    }

    function normalizeSseText(text) {
        return String(text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    }

    function parseSseBlock(block, onEvent) {
        var eventName = 'message';
        var dataLines = [];

        function flush() {
            if (!dataLines.length) return;
            var raw = dataLines.join('\n').trim();
            if (!raw) return;
            var data;
            try {
                data = JSON.parse(raw);
            } catch (e) {
                data = { message: raw, _sse_parse_failed: true };
            }
            onEvent(eventName, data);
            dataLines = [];
        }

        normalizeSseText(block).split('\n').forEach(function (line) {
            if (!line) return;
            if (line.indexOf('event:') === 0) {
                flush();
                eventName = line.slice(6).trim();
                return;
            }
            if (line.indexOf('data:') === 0) {
                dataLines.push(line.slice(5).replace(/^\s/, ''));
            }
        });
        flush();
    }

    function parseSseText(text, onEvent) {
        var normalized = normalizeSseText(text);
        normalized.split('\n\n').forEach(function (block) {
            if (!block.trim()) return;
            parseSseBlock(block, onEvent);
        });
    }

    function setAiProgress(msg, isError) {
        var el = qs('#mmf-ai-progress');
        if (!el) return;
        el.textContent = msg || '';
        el.classList.toggle('is-error', !!isError);
    }

    function setAiSaveEnabled(enabled) {
        var btn = qs('#mmf-ai-btn-save');
        if (btn) btn.disabled = !enabled;
    }

    function collectAiPayload(modeOverride) {
        var mode = modeOverride || AI_MODE;
        var promptEl = qs('#mmf-ai-prompt');
        var prompt = promptEl ? promptEl.value.trim() : '';
        var sizeEl = qs('#mmf-ai-size');
        var formatEl = qs('#mmf-ai-format');
        var batchPromptsEl = qs('#mmf-ai-batch-prompts');
        var batchCountEl = qs('#mmf-ai-batch-count');
        var payload = {
            mode: mode,
            prompt: prompt,
            target: CWD_HASH,
            session_id: AI_SESSION_ID,
            source_file_hash: AI_SOURCE_HASH,
            parent_generation_id: mode === 'edit_turn' ? AI_CURRENT_GENERATION_ID : '',
            size: sizeEl ? sizeEl.value : '1024x1024',
            output_format: formatEl ? formatEl.value : 'png',
            aspect_ratio: '1:1'
        };
        if (mode === 'image2image' && !AI_SOURCE_HASH) {
            return null;
        }
        if (mode === 'batch') {
            var lines = (batchPromptsEl ? batchPromptsEl.value : '').split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
            payload.prompts = lines;
            payload.batch_count = batchCountEl ? parseInt(batchCountEl.value, 10) || 2 : 2;
            if (lines.length === 0 && !prompt) {
                return null;
            }
            if (lines.length === 0) payload.prompt = prompt;
        } else if (!prompt) {
            return null;
        }
        return payload;
    }

    function startAiGeneration() {
        if (isAiGenerating()) return;
        var payload = collectAiPayload(AI_MODE);
        if (!payload) {
            if (AI_MODE === 'image2image' && !AI_SOURCE_HASH) {
                showError(t('aiNoReference'));
            } else {
                showError(t('aiNoPrompt'));
            }
            return;
        }
        runAiStream(payload, false);
    }

    function continueAiEdit() {
        if (isAiGenerating()) return;
        if (!AI_CURRENT_GENERATION_ID) {
            showError(t('aiNoPrompt'));
            return;
        }
        var payload = collectAiPayload('edit_turn');
        if (!payload || !payload.prompt) {
            showError(t('aiNoPrompt'));
            return;
        }
        runAiStream(payload, true);
    }

    function abortAiStream() {
        if (AI_STREAM_CONTROLLER) {
            try { AI_STREAM_CONTROLLER.abort(); } catch (e) {}
            AI_STREAM_CONTROLLER = null;
        }
    }

    function runAiStream(payload, isContinue) {
        if (isAiGenerating()) return;
        abortAiStream();
        if (!isContinue) {
            if (payload.mode !== 'edit_turn') resetAiPreview();
            if (payload.mode !== 'batch') AI_GENERATIONS = [];
        }
        setAiBusy(true);
        clearAiError();
        setAiProgress(t('aiGenerating'));
        setAiSaveEnabled(false);
        setAiPreviewLoading(true);
        AI_STREAM_TERMINAL = false;
        AI_STREAM_CONTROLLER = new AbortController();
        fetch(CONFIG.aiDrawStreamUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'text/event-stream',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload),
            credentials: 'same-origin',
            signal: AI_STREAM_CONTROLLER.signal
        }).then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return consumeSseResponse(res, handleAiSseEvent);
        }).then(function () {
            if (!AI_STREAM_TERMINAL) {
                reportAiError(t('aiStreamDisconnected'));
            }
        }).catch(function (err) {
            if (err && err.name === 'AbortError') return;
            reportAiError(err && err.message ? err.message : t('networkError'));
        }).finally(function () {
            AI_STREAM_CONTROLLER = null;
            setAiBusy(false);
            setAiPreviewLoading(false);
            if (!AI_GENERATIONS.length) {
                var empty = qs('#mmf-ai-preview-empty');
                if (empty) empty.style.display = '';
            }
        });
    }

    function consumeSseResponse(res, onEvent) {
        if (!res.body || !res.body.getReader) {
            return res.text().then(function (text) {
                parseSseText(text, onEvent);
            });
        }
        var reader = res.body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';
        function pump() {
            return reader.read().then(function (chunk) {
                buffer = normalizeSseText(buffer + decoder.decode(chunk.value, { stream: true }));
                if (chunk.done) {
                    if (buffer.trim()) parseSseBlock(buffer, onEvent);
                    return;
                }
                var parts = buffer.split('\n\n');
                buffer = parts.pop() || '';
                parts.forEach(function (block) {
                    if (block.trim()) parseSseBlock(block, onEvent);
                });
                return pump();
            });
        }
        return pump();
    }

    function handleAiSseEvent(eventName, data) {
        data = data || {};
        if (eventName === 'start') {
            if (data.session_id) AI_SESSION_ID = data.session_id;
            updateAiConfigBanner({
                mock: !!data.mock,
                ready: data.ready !== false,
                model: data.model || '',
                message: data.message || ''
            });
            return;
        }
        if (eventName === 'progress') {
            var progressMsg = data.message || t('aiGenerating');
            setAiProgress(progressMsg, false);
            var loadingText = qs('#mmf-ai-loading-text');
            if (loadingText) loadingText.textContent = progressMsg;
            return;
        }
        if (eventName === 'preview') {
            setAiPreviewLoading(false);
            addAiPreviewItem(data);
            setAiProgress('');
            return;
        }
        if (eventName === 'complete') {
            AI_STREAM_TERMINAL = true;
            if (data.session_id) AI_SESSION_ID = data.session_id;
            if (data.generation_id) AI_CURRENT_GENERATION_ID = data.generation_id;
            AI_HAS_UNSAVED = AI_GENERATIONS.length > 0;
            setAiSaveEnabled(AI_GENERATIONS.length > 0);
            clearAiError();
            setAiStatus(t('aiGenerateSuccess'), 'success');
            setAiProgress('');
            appendAiHistory(data);
            return;
        }
        if (eventName === 'error') {
            AI_STREAM_TERMINAL = true;
            reportAiError(data);
            if (data.partial && AI_GENERATIONS.length) {
                AI_HAS_UNSAVED = true;
                setAiSaveEnabled(true);
            } else {
                setAiSaveEnabled(false);
            }
        }
    }

    function resolveAiPreviewSrc(data) {
        if (!data) return '';
        var dataUrl = String(data.data_url || data.dataUrl || data.preview_data_url || '').trim();
        if (dataUrl) return dataUrl;
        if (data.generation_id) {
            var sessionId = AI_SESSION_ID || data.session_id || '';
            var previewToken = String(data.preview_token || data.previewToken || '').trim();
            var local = buildAiPreviewUrl(sessionId, data.generation_id, previewToken);
            if (local) return local;
        }
        return String(data.preview_url || data.previewUrl || data.url || '').trim();
    }

    function applyAiPreviewImage(img, src, onFail) {
        if (!img || !src) {
            if (typeof onFail === 'function') onFail();
            return;
        }
        function bindLoad(targetSrc) {
            img.onload = function () {
                img.onerror = null;
            };
            img.onerror = function () {
                img.onerror = null;
                if (typeof onFail === 'function') onFail();
            };
            img.src = targetSrc;
            img.style.display = 'block';
        }
        if (src.indexOf('data:') === 0 || src.indexOf('blob:') === 0) {
            bindLoad(src);
            return;
        }
        fetch(src, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.blob();
        }).then(function (blob) {
            bindLoad(URL.createObjectURL(blob));
        }).catch(function () {
            bindLoad(src);
        });
    }

    function addAiPreviewItem(data) {
        if (!data || !data.generation_id) {
            if (data && data._sse_parse_failed) {
                showError(t('aiPreviewParseFailed'));
            }
            return;
        }
        var previewSrc = resolveAiPreviewSrc(data);
        if (!previewSrc) {
            showError(t('aiPreviewEmpty'));
            return;
        }
        var item = {
            id: data.generation_id,
            previewSrc: previewSrc,
            filename: data.suggested_filename || '',
            selected: AI_MODE !== 'batch'
        };
        AI_GENERATIONS.push(item);
        AI_CURRENT_GENERATION_ID = data.generation_id;
        if (AI_MODE === 'batch') {
            var grid = qs('#mmf-ai-preview-grid');
            if (grid) grid.style.display = '';
            renderAiPreviewGrid();
        } else {
            var empty = qs('#mmf-ai-preview-empty');
            var img = qs('#mmf-ai-preview-img');
            if (empty) empty.style.display = 'none';
            if (img) {
                img.style.display = 'block';
                applyAiPreviewImage(img, previewSrc, function () {
                    showError(t('aiPreviewLoadFailed'));
                    img.style.display = 'none';
                    if (empty) empty.style.display = '';
                });
            }
        }
    }

    function renderAiPreviewGrid() {
        var grid = qs('#mmf-ai-preview-grid');
        var empty = qs('#mmf-ai-preview-empty');
        if (!grid) return;
        if (empty) empty.style.display = AI_GENERATIONS.length ? 'none' : '';
        grid.style.display = AI_GENERATIONS.length ? '' : 'none';
        grid.innerHTML = AI_GENERATIONS.map(function (item) {
            return '<label class="mmf-ai-grid-item' + (item.selected ? ' selected' : '') + '">' +
                '<input type="checkbox"' + (item.selected ? ' checked' : '') + ' data-id="' + escAttr(item.id) + '">' +
                '<img src="' + escAttr(item.previewSrc || item.dataUrl || '') + '" alt="">' +
                '</label>';
        }).join('');
        qsa('input[type="checkbox"]', grid).forEach(function (cb) {
            cb.addEventListener('change', function () {
                var id = cb.getAttribute('data-id');
                AI_GENERATIONS.forEach(function (g) { if (g.id === id) g.selected = cb.checked; });
                cb.closest('.mmf-ai-grid-item').classList.toggle('selected', cb.checked);
            });
        });
    }

    function appendAiHistory(data) {
        var history = qs('#mmf-ai-history');
        var historyWrap = qs('#mmf-ai-history-wrap');
        var promptEl = qs('#mmf-ai-prompt');
        if (!history || !promptEl) return;
        var prompt = promptEl.value.trim();
        if (!prompt) return;
        if (historyWrap) historyWrap.style.display = '';
        var div = document.createElement('div');
        div.className = 'mmf-ai-history-item';
        div.textContent = prompt;
        history.appendChild(div);
        history.scrollTop = history.scrollHeight;
    }

    function promptToAltFilenameStem(prompt) {
        var text = String(prompt || '').trim();
        if (!text) return '';
        var firstLine = text.split(/\r?\n/)[0].trim();
        if (!firstLine) return '';
        firstLine = firstLine.replace(/\s+/g, ' ');
        if (firstLine.length > 36) firstLine = firstLine.slice(0, 36);
        var stem = firstLine.replace(/[<>:"|?*\\\/\x00-\x1F\x7F]/g, '').trim().replace(/\s+/g, '-').replace(/-+/g, '-').replace(/^-+|-+$/g, '');
        if (!stem || stem === '.' || stem === '..') return '';
        return stem.length > 48 ? stem.slice(0, 48) : stem;
    }

    function openAiSaveDialog() {
        if (isAiGenerating()) return;
        var selected = AI_GENERATIONS.filter(function (g) { return g.selected; });
        if (!selected.length) selected = AI_GENERATIONS.slice(-1);
        if (!selected.length) return;
        clearAiSaveError();
        setAiSaveBusy(false);
        var overwriteWrap = qs('#mmf-ai-save-overwrite-wrap');
        if (overwriteWrap) {
            overwriteWrap.style.display = (AI_SOURCE_HASH && selected.length === 1) ? '' : 'none';
        }
        var filename = qs('#mmf-ai-save-filename');
        if (filename) {
            filename.value = selected[0].filename || '';
            if (!filename.value) {
                var promptEl = qs('#mmf-ai-prompt');
                var promptStem = promptToAltFilenameStem(promptEl ? promptEl.value : '');
                if (promptStem) filename.value = promptStem + '.png';
            }
        }
        var overlay = qs('#mmf-ai-save-overlay');
        if (overlay) overlay.classList.add('visible');
    }

    function closeAiSaveDialog() {
        setAiSaveBusy(false);
        clearAiSaveError();
        var overlay = qs('#mmf-ai-save-overlay');
        if (overlay) overlay.classList.remove('visible');
    }

    function setAiSaveError(message) {
        var el = qs('#mmf-ai-save-error');
        if (!el) return;
        var text = String(message || '').trim();
        if (!text) {
            el.textContent = '';
            el.style.display = 'none';
            return;
        }
        el.textContent = text;
        el.style.display = '';
    }

    function clearAiSaveError() {
        setAiSaveError('');
    }

    function setAiSaveBusy(busy) {
        var confirmBtn = qs('#mmf-ai-save-confirm');
        var cancelBtn = qs('#mmf-ai-save-cancel');
        if (confirmBtn) {
            confirmBtn.disabled = !!busy;
            confirmBtn.classList.toggle('is-loading', !!busy);
            if (!confirmBtn.dataset.defaultLabel) {
                confirmBtn.dataset.defaultLabel = confirmBtn.textContent || t('aiConfirmSave');
            }
            confirmBtn.textContent = busy ? t('aiSaving') : confirmBtn.dataset.defaultLabel;
        }
        if (cancelBtn) cancelBtn.disabled = !!busy;
    }

    function extractApiErrorMessage(err, fallback) {
        var fb = fallback || t('aiSaveFailed');
        if (!err) return fb;
        if (typeof err === 'string' && err.trim()) return err;
        var response = err.response || null;
        var data = response && response.data !== undefined ? response.data : null;
        if (data && typeof data === 'object') {
            return data.message || data.msg || (data.error && data.error.message) || fb;
        }
        if (typeof data === 'string' && data.trim()) return data;
        if (err.message) return err.message;
        return fb;
    }

    function resolveAiSaveResult(res) {
        var data = res && res.data ? res.data : res;
        if (!data || typeof data !== 'object') {
            return [];
        }
        if (Array.isArray(data.added) && data.added.length) return data.added;
        if (Array.isArray(data.updated) && data.updated.length) return data.updated;
        if (res && Array.isArray(res.added) && res.added.length) return res.added;
        if (res && Array.isArray(res.updated) && res.updated.length) return res.updated;
        return [];
    }

    function confirmAiSave() {
        var selected = AI_GENERATIONS.filter(function (g) { return g.selected; });
        if (!selected.length) selected = AI_GENERATIONS.slice(-1);
        if (!selected.length || !CONFIG.aiDrawSaveUrl) return;
        if (!AI_SESSION_ID) {
            setAiSaveError(t('aiSaveSessionMissing'));
            return;
        }
        var modeInput = document.querySelector('input[name="mmf_ai_save_mode"]:checked');
        var saveMode = modeInput ? modeInput.value : 'save_as';
        if (saveMode !== 'overwrite' && !CWD_HASH) {
            setAiSaveError(t('uploadWaitDir'));
            return;
        }
        var filenameEl = qs('#mmf-ai-save-filename');
        var filename = filenameEl ? filenameEl.value.trim() : '';
        if (saveMode !== 'overwrite' && !filename) {
            setAiSaveError(t('aiSaveFilenameRequired') || t('aiSaveFailed'));
            return;
        }
        clearAiSaveError();
        setAiSaveBusy(true);
        var payload = {
            session_id: AI_SESSION_ID,
            save_mode: saveMode,
            target: CWD_HASH,
            source_file_hash: AI_SOURCE_HASH,
            filename: filename,
            generation_id: selected.length === 1 ? selected[0].id : '',
            generation_ids: selected.map(function (g) { return g.id; })
        };
        apiPostJson(CONFIG.aiDrawSaveUrl, payload, function (res) {
            setAiSaveBusy(false);
            var saved = resolveAiSaveResult(res);
            closeAiSaveDialog();
            finishAiDrawClose();
            AI_HAS_UNSAVED = false;
            showSuccess(t('aiSaved'));
            openDir(CWD_HASH);
            if (saved.length && saved[0].hash) {
                SELECTED = [saved[0].hash];
                updatePreviewPanel();
            }
        }, function (err) {
            setAiSaveBusy(false);
            var msg = extractApiErrorMessage(err, t('aiSaveFailed'));
            setAiSaveError(msg);
            showError(msg);
        });
    }

    function apiPostJson(url, payload, onDone, onErr) {
        var handleErr = function (err) {
            if (onErr) {
                onErr(err);
                return;
            }
            showError(extractApiErrorMessage(err, t('aiSaveFailed')));
        };
        if (window.Weline && window.Weline.Api) {
            var apiCall = null;
            if (typeof window.Weline.Api.post === 'function') {
                apiCall = window.Weline.Api.post(url, payload);
            } else if (typeof window.Weline.Api.request === 'function') {
                apiCall = window.Weline.Api.request(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: payload
                });
            }
            if (apiCall && typeof apiCall.then === 'function') {
                apiCall.then(function (res) {
                    if (res && res.success === false) {
                        throw new Error(res.message || res.msg || t('aiSaveFailed'));
                    }
                    onDone(res);
                }).catch(handleErr);
                return;
            }
        }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function () {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.error || res.success === false) {
                    handleErr(new Error(res.message || res.msg || res.error || t('aiSaveFailed')));
                    return;
                }
                onDone(res);
            } catch (e) {
                handleErr(e);
            }
        };
        xhr.onerror = function () { handleErr(new Error(t('networkError'))); };
        xhr.send(JSON.stringify(payload));
    }

    /* ─── util ───────────────────────────────────────────────────────── */

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function escAttr(s) {
        return (s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /* ─── expose ─────────────────────────────────────────────────────── */
    window.WelineMediaManager = {
        init: init,
        setupIframeMode: setupIframeMode,
        getSelected: function () {
            var files = [];
            SELECTED.forEach(function (hash) {
                var f = FILES[hash];
                if (f) files.push(f);
            });
            return files;
        },
        confirmSelection: confirmSelection,
        setCallback: function (cb) {
            GET_FILE_CALLBACK = cb;
        },
        setMultiSelect: function (multi) {
            MULTI_SELECT = !!multi;
        },
        enterSelectionMode: enterSelectionMode,
        exitSelectionMode: exitSelectionMode,
        clearSelection: clearSelection
    };

})();
