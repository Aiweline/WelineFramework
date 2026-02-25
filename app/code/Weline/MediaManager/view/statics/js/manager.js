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

    /* ─── helpers ────────────────────────────────────────────────────── */

    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return (ctx || document).querySelectorAll(sel); }

    function api(params, onDone, onErr) {
        var isUpload = params instanceof FormData;
        var url = CONNECTOR;
        var opts = {};
        if (isUpload) {
            opts.method = 'POST';
            opts.body = params;
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
            (onErr || showError)('Request timeout. The server may be busy or the connector URL may be wrong.');
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
                    (onErr || showError)('Invalid JSON response');
                }
            } else {
                (onErr || showError)('HTTP ' + xhr.status);
            }
        };
        xhr.onerror = function () { if (!timedOut) (onErr || showError)('Network error'); };
        if (isUpload && xhr.upload) {
            xhr.upload.onprogress = function (ev) {
                if (ev.lengthComputable) updateUploadProgress(Math.round(ev.loaded / ev.total * 100));
            };
        }
        xhr.send(opts.body || null);
    }

    function showError(msg) {
        if (window.AdminToast) {
            window.AdminToast.error(msg);
        } else {
            console.error('[MediaManager]', msg);
        }
    }

    function showSuccess(msg) {
        if (window.AdminToast) {
            window.AdminToast.success(msg);
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

    /* ─── init ───────────────────────────────────────────────────────── */

    function init(connectorUrl, startPath) {
        CONNECTOR = (typeof connectorUrl === 'string' ? connectorUrl : '').trim();
        if (!CONNECTOR) {
            setLoading(false);
            showError('Connector URL is not configured. Please refresh the page.');
            return;
        }
        START_PATH = (typeof startPath === 'string' ? startPath : '').trim();
        STORAGE_KEY = 'mmf_last_path_' + hashCode(START_PATH || '_root_');

        bindToolbar();
        bindDragDrop();
        bindContextMenu();

        var lastHash = loadLastPath();
        if (lastHash) {
            openDir(lastHash, true);
        } else {
            openDir('', true);
        }
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
        if (isInit) { params.init = '1'; params.tree = '1'; }
        else { params.tree = '1'; }

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
                saveLastPath();
            } catch (e) {
                setLoading(false);
                showError('Invalid response: ' + (e && e.message ? e.message : String(e)));
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
            container.innerHTML = '<div class="mmf-empty"><div class="mmf-empty-icon">\uD83D\uDCC2</div><div>No files</div></div>';
            return;
        }

        var html = '';
        items.forEach(function (f) {
            var isDir = f.mime === 'directory';
            var sel = SELECTED.indexOf(f.hash) >= 0;
            html += '<div class="mmf-item' + (sel ? ' selected' : '') + '" data-hash="' + f.hash + '" data-mime="' + (f.mime || '') + '">';
            html += '<div class="mmf-item-icon">';
            if (f.tmb && f.tmb !== '1') {
                html += '<img src="' + escAttr(f.tmb) + '" alt="" loading="lazy">';
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
        el.textContent = count + ' items' + (SELECTED.length ? ', ' + SELECTED.length + ' selected' : '');
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
                loadingEl.innerHTML = '<span class="mmf-spinner"></span>Loading...';
                el.appendChild(loadingEl);
            }
        } else {
            qsa('.mmf-loading', el).forEach(function(l) { l.remove(); });
            grid.style.display = '';
        }
    }

    /* ─── file events ────────────────────────────────────────────────── */

    function bindFileEvents(container) {
        qsa('.mmf-item', container).forEach(function (el) {
            el.addEventListener('click', function (e) {
                var hash = el.dataset.hash;
                if (e.ctrlKey || e.metaKey) {
                    toggleSelect(hash);
                } else {
                    SELECTED = [hash];
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
                    SELECTED = [hash];
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
        var fd = new FormData();
        fd.append('cmd', 'upload');
        fd.append('target', CWD_HASH);
        for (var i = 0; i < fileList.length; i++) {
            fd.append('upload[]', fileList[i]);
        }
        showUploadProgress(true);
        updateUploadProgress(0);
        api(fd, function (data) {
            showUploadProgress(false);
            showSuccess('Upload complete');
            openDir(CWD_HASH);
        }, function (err) {
            showUploadProgress(false);
            showError(err);
        });
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
        showDialog('New Folder', 'Folder name', 'Untitled', function (name) {
            if (!name) return;
            api({ cmd: 'mkdir', target: CWD_HASH, name: name }, function () {
                showSuccess('Folder created');
                openDir(CWD_HASH);
            });
        });
    }

    /* ─── rename (cmd=rename) ────────────────────────────────────────── */

    function renameSelected() {
        if (SELECTED.length !== 1) { showError('Select one item to rename'); return; }
        var f = FILES[SELECTED[0]];
        if (!f) return;
        var oldHash = f.hash;
        var isDir = f.mime === 'directory';
        showDialog('Rename', 'New name', f.name, function (name) {
            if (!name || name === f.name) return;
            api({ cmd: 'rename', target: oldHash, name: name }, function (data) {
                showSuccess('Renamed');
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
        if (!SELECTED.length) { showError('No items selected'); return; }
        var toDelete = SELECTED.slice();
        showConfirm('Delete ' + SELECTED.length + ' item(s)?', function () {
            api({ cmd: 'rm', targets: toDelete }, function () {
                showSuccess('Deleted');
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
        if (f && isImage) {
            html += '<div class="mmf-context-item" data-action="preview">\uD83D\uDD0D Preview</div>';
        }
        if (f && !isDir) {
            html += '<div class="mmf-context-item" data-action="download">\uD83D\uDCE5 Download</div>';
        }
        if (f && isDir) {
            html += '<div class="mmf-context-item" data-action="open">\uD83D\uDCC2 Open</div>';
        }
        if (SELECTED.length === 1) {
            html += '<div class="mmf-context-item" data-action="rename">\u270F\uFE0F Rename</div>';
        }
        if (SELECTED.length) {
            html += '<div class="mmf-context-sep"></div>';
            html += '<div class="mmf-context-item" data-action="delete">\uD83D\uDDD1\uFE0F Delete</div>';
        }

        menu.innerHTML = html;
        menu.style.left = x + 'px';
        menu.style.top = y + 'px';
        menu.classList.add('visible');

        qsa('.mmf-context-item', menu).forEach(function (it) {
            it.addEventListener('click', function () {
                var action = it.dataset.action;
                hideContextMenu();
                if (action === 'preview' && SELECTED.length === 1) openLightbox(SELECTED[0]);
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
            var imgUrl = f.url || (f.tmb && f.tmb !== '1' ? f.tmb : '');
            if (!imgUrl && CONNECTOR) {
                imgUrl = CONNECTOR + '&cmd=file&target=' + encodeURIComponent(f.hash);
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
                thumbUrl = CONNECTOR + '&cmd=tmb&target=' + encodeURIComponent(f.hash);
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
        showDialog('Confirm', '', msg, function (val) { onOk(); });
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
    window.WelineMediaManager = { init: init };

})();
