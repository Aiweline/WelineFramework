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

    function init(connectorUrl) {
        CONNECTOR = (typeof connectorUrl === 'string' ? connectorUrl : '').trim();
        if (!CONNECTOR) {
            setLoading(false);
            showError('Connector URL is not configured. Please refresh the page.');
            return;
        }
        bindToolbar();
        bindDragDrop();
        bindContextMenu();
        openDir('', true);
    }

    /* ─── open directory (cmd=open) ──────────────────────────────────── */

    function openDir(target, isInit) {
        setLoading(true);
        var params = { cmd: 'open', target: target || '' };
        if (isInit) { params.init = '1'; params.tree = '1'; }
        else { params.tree = '1'; }

        api(params, function (data) {
            try {
                setLoading(false);
                CWD_HASH = data.cwd ? data.cwd.hash : '';
                CWD_INFO = data.cwd || {};

                FILES = {};
                if (data.files) {
                    data.files.forEach(function (f) { FILES[f.hash] = f; });
                }

                if (data.tree) {
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

        var el = qs('.mmf-tree');
        if (!el) return;
        el.innerHTML = buildTreeHtml(roots, childMap);
    }

    function buildTreeHtml(nodes, childMap) {
        if (!nodes || !nodes.length) return '';
        var html = '';
        nodes.forEach(function (n) {
            var kids = childMap[n.hash];
            var hasKids = kids && kids.length;
            var isActive = n.hash === CWD_HASH;
            html += '<li>';
            html += '<div class="mmf-tree-item' + (isActive ? ' active' : '') + '" data-hash="' + n.hash + '">';
            html += '<span class="mmf-tree-toggle">' + (hasKids ? '\u25B6' : '') + '</span>';
            html += '\uD83D\uDCC1 ' + escHtml(n.name);
            html += '</div>';
            if (hasKids) {
                html += '<ul style="display:' + (isActive ? 'block' : 'none') + '">' + buildTreeHtml(kids, childMap) + '</ul>';
            }
            html += '</li>';
        });
        return html;
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
        if (on) {
            el.innerHTML = '<div class="mmf-loading"><span class="mmf-spinner"></span>Loading...</div>';
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
        showDialog('Rename', 'New name', f.name, function (name) {
            if (!name || name === f.name) return;
            api({ cmd: 'rename', target: f.hash, name: name }, function () {
                showSuccess('Renamed');
                openDir(CWD_HASH);
            });
        });
    }

    /* ─── delete (cmd=rm) ────────────────────────────────────────────── */

    function deleteSelected() {
        if (!SELECTED.length) { showError('No items selected'); return; }
        showConfirm('Delete ' + SELECTED.length + ' item(s)?', function () {
            api({ cmd: 'rm', targets: SELECTED }, function () {
                showSuccess('Deleted');
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

        var html = '';
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
                if (action === 'download' && SELECTED.length === 1) downloadFile(SELECTED[0]);
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
