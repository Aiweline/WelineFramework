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

    function getThumbnailUrl(f) {
        if (!f || f.mime === 'directory') return null;
        if (f.tmb && f.tmb !== '1') {
            return f.tmb;
        }
        if (f.tmb === '1' && CONNECTOR) {
            return CONNECTOR + (CONNECTOR.indexOf('?') >= 0 ? '&' : '?') + 'cmd=tmb&target=' + encodeURIComponent(f.hash);
        }
        return null;
    }

    /* ─── init ───────────────────────────────────────────────────────── */

    var CURRENT_STORAGE = 'local';
    var CONFIG = {};

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
                html += '<img src="' + escAttr(thumbUrl) + '" alt="" loading="lazy" onerror="this.parentNode.innerHTML=\'<span class=mmf-icon-placeholder>' + fileIcon(f.mime, isDir) + '</span>\'">';
            } else {
                html += '<span class="mmf-icon-placeholder">' + fileIcon(f.mime, isDir) + '</span>';
            }
            html += '</div>';
            html += '<div class="mmf-item-name" title="' + escAttr(f.name) + '">' + escHtml(f.name) + '</div>';
            html += '</div>';
        });
        container.innerHTML = html;

        bindFileEvents(container);
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
                var thumbUrl = getThumbnailUrl(f) || '';
                img.src = thumbUrl;
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
        
        if (f && isImage) {
            html += '<div class="mmf-context-item" data-action="preview">\uD83D\uDD0D ' + t('preview') + '</div>';
        }
        if (f && !isDir) {
            html += '<div class="mmf-context-item" data-action="download">\uD83D\uDCE5 ' + t('download') + '</div>';
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
                else if (action === 'download' && SELECTED.length === 1) downloadFile(SELECTED[0]);
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
                imgUrl = CONNECTOR + (CONNECTOR.indexOf('?') >= 0 ? '&' : '?') + 'cmd=file&target=' + encodeURIComponent(f.hash);
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
            var thumbUrl = f.tmb && f.tmb !== '1' ? f.tmb : '';
            if (!thumbUrl && CONNECTOR) {
                thumbUrl = CONNECTOR + (CONNECTOR.indexOf('?') >= 0 ? '&' : '?') + 'cmd=tmb&target=' + encodeURIComponent(f.hash);
            }
            var activeClass = i === LIGHTBOX_INDEX ? ' active' : '';
            html += '<div class="mmf-lightbox-thumb' + activeClass + '" data-index="' + i + '">';
            if (thumbUrl) {
                html += '<img src="' + escAttr(thumbUrl) + '" alt="" loading="lazy">';
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
