/**
 * Weline Media Manager — iframe 嵌入版本
 * 继承 manager.js 核心逻辑，增加文件选择回调功能
 */
(function () {
    'use strict';

    var CONNECTOR = '';
    var CONFIG = {};
    var CWD_HASH = '';
    var CWD_INFO = {};
    var FILES = {};
    var TREE = {};
    var SELECTED = [];
    var LOADING = false;
    var ROOT_HASH = '';
    var LOCK_ROOT_HASH = '';
    var EXPANDED_NODES = {};
    var STORAGE_KEY = '';
    var START_PATH = '';

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
            (onErr || showError)(t('requestTimeout'));
        };
        xhr.onload = function () {
            if (timedOut) return;
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    console.log('[WelineMedia] API response:', data);
                    if (data.error) {
                        (onErr || showError)(Array.isArray(data.error) ? data.error.join(', ') : data.error);
                    } else {
                        onDone && onDone(data);
                    }
                } catch (e) {
                    console.error('[WelineMedia] JSON parse error:', e, 'Response (first 500 chars):', xhr.responseText.substring(0, 500));
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

    function showError(msg, showInContent) {
        console.error('[WelineMedia]', msg);
        if (window.parent && window.parent.AdminToast) {
            window.parent.AdminToast.error(msg);
        }
        if (showInContent) {
            var el = qs('.mmf-content .mmf-grid');
            if (el) {
                el.innerHTML = '<div class="mmf-empty"><div class="mmf-empty-icon">\u26A0\uFE0F</div><div>' + escHtml(msg) + '</div></div>';
            }
        }
    }

    function showSuccess(msg) {
        console.log('[WelineMedia]', msg);
        if (window.parent && window.parent.AdminToast) {
            window.parent.AdminToast.success(msg);
        }
    }

    function humanSize(bytes) {
        if (!bytes) return '—';
        var u = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        while (bytes >= 1024 && i < u.length - 1) { bytes /= 1024; i++; }
        return bytes.toFixed(i ? 1 : 0) + ' ' + u[i];
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

    function t(key, params) {
        var str = (CONFIG.i18n && CONFIG.i18n[key]) || key;
        if (params) {
            for (var k in params) {
                if (params.hasOwnProperty(k)) {
                    str = str.replace(new RegExp('%\\{' + k + '\\}', 'g'), params[k]);
                }
            }
        }
        return str;
    }

    function init(config) {
        CONFIG = config || {};
        CONNECTOR = (CONFIG.connectorUrl || '').trim();
        if (!CONNECTOR) {
            setLoading(false);
            showError(t('connectorNotConfigured'));
            return;
        }
        START_PATH = (CONFIG.startPath || '').trim();
        STORAGE_KEY = 'mmf_iframe_path_' + hashCode(START_PATH || '_root_');

        bindToolbar();
        bindDragDrop();
        bindContextMenu();

        var lastState = loadLastPath();
        if (lastState && lastState.hash) {
            openDir(lastState.hash, true);
        } else if (CONFIG.startPath) {
            openDirByPath(CONFIG.startPath);
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
            return state;
        } catch (e) {
            return null;
        }
    }

    /**
     * 通过路径打开目录
     */
    function openDirByPath(path) {
        setLoading(true);
        var params = { cmd: 'open', target: '', init: '1', tree: '1' };
        if (path) {
            params.path = path;
        }
        
        api(params, function (data) {
            setLoading(false);
            try {
                CWD_HASH = data.cwd ? data.cwd.hash : '';
                CWD_INFO = data.cwd || {};
                ROOT_HASH = data.root || CWD_HASH;
                
                // 如果启用了 lockPath，记录锁定的根目录 hash
                if (CONFIG.lockPath && CWD_HASH) {
                    LOCK_ROOT_HASH = CWD_HASH;
                }

                FILES = {};
                if (data.cwd) FILES[data.cwd.hash] = data.cwd;
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
                updateLockPathUI();
                saveLastPath();
            } catch (e) {
                console.error('[WelineMedia] openDirByPath render error:', e);
                showError(t('invalidResponse') + ': ' + (e && e.message ? e.message : String(e)), true);
            }
        }, function (err) {
            setLoading(false);
            showError(err, true);
            if (path) {
                openDir('', true);
            }
        });
    }

    /**
     * 更新锁定路径相关的 UI
     */
    function updateLockPathUI() {
        if (!CONFIG.lockPath) return;
        
        // 如果在锁定的根目录，隐藏面包屑中的上级链接
        var pathEl = qs('.mmf-path');
        if (pathEl && LOCK_ROOT_HASH) {
            var segs = qsa('.mmf-path-seg', pathEl);
            segs.forEach(function (seg) {
                var hash = seg.dataset.hash;
                // 检查是否是锁定目录的父级
                if (isParentOfLockRoot(hash)) {
                    seg.style.pointerEvents = 'none';
                    seg.style.opacity = '0.5';
                    seg.style.cursor = 'not-allowed';
                }
            });
        }
        
        // 隐藏侧边栏中锁定目录以外的目录
        var treeEl = qs('.mmf-tree');
        if (treeEl && LOCK_ROOT_HASH) {
            var treeItems = qsa('.mmf-tree-item', treeEl);
            treeItems.forEach(function (item) {
                var hash = item.dataset.hash;
                if (!isWithinLockRoot(hash)) {
                    item.style.pointerEvents = 'none';
                    item.style.opacity = '0.5';
                }
            });
        }
    }

    /**
     * 检查 hash 是否是锁定根目录的父级
     */
    function isParentOfLockRoot(hash) {
        if (!LOCK_ROOT_HASH || hash === LOCK_ROOT_HASH) return false;
        var cur = LOCK_ROOT_HASH;
        while (cur && FILES[cur]) {
            if (FILES[cur].phash === hash) return true;
            cur = FILES[cur].phash;
        }
        return false;
    }

    /**
     * 检查 hash 是否在锁定根目录范围内（包括自身和子目录）
     */
    function isWithinLockRoot(hash) {
        if (!LOCK_ROOT_HASH) return true;
        if (hash === LOCK_ROOT_HASH) return true;
        
        // 检查是否是锁定目录的子目录
        var cur = hash;
        while (cur && FILES[cur]) {
            if (FILES[cur].phash === LOCK_ROOT_HASH) return true;
            if (cur === LOCK_ROOT_HASH) return true;
            cur = FILES[cur].phash;
        }
        return false;
    }

    /**
     * 检查是否可以导航到指定目录
     */
    function canNavigateTo(hash) {
        if (!CONFIG.lockPath || !LOCK_ROOT_HASH) return true;
        return isWithinLockRoot(hash);
    }

    function openDir(target, isInit) {
        // 路径锁定检查
        if (!isInit && CONFIG.lockPath && LOCK_ROOT_HASH && target) {
            if (!canNavigateTo(target)) {
                showError(t('cannotAccessOutsidePath'));
                return;
            }
        }
        
        setLoading(true);
        var params = { cmd: 'open', target: target || '' };
        if (isInit) { params.init = '1'; params.tree = '1'; }
        else { params.tree = '1'; }

        api(params, function (data) {
            setLoading(false);
            try {
                CWD_HASH = data.cwd ? data.cwd.hash : '';
                CWD_INFO = data.cwd || {};
                
                // 记录根目录 hash
                if (isInit && data.root) {
                    ROOT_HASH = data.root;
                }

                FILES = {};
                if (data.cwd) FILES[data.cwd.hash] = data.cwd;
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
                updateLockPathUI();
                saveLastPath();
            } catch (e) {
                showError(t('invalidResponse') + ': ' + (e && e.message ? e.message : String(e)), isInit);
            }
        }, function (err) {
            setLoading(false);
            showError(err, isInit);
        });
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
        var el = qs('.mmf-tree');
        if (!el) return;
        
        // 保存当前展开状态
        saveExpandedState(el);
        
        el.innerHTML = buildTreeHtml(roots, childMap);
        bindTreeEvents(el);
        
        // 恢复展开状态
        restoreExpandedState(el);
    }

    /**
     * 保存已展开的节点状态
     */
    function saveExpandedState(container) {
        qsa('.mmf-tree-toggle.expanded', container).forEach(function (toggle) {
            var li = toggle.closest('li');
            if (li && li.dataset.hash) {
                EXPANDED_NODES[li.dataset.hash] = true;
            }
        });
    }

    /**
     * 恢复已展开的节点状态
     */
    function restoreExpandedState(container) {
        // 只处理直接子级的 li，然后递归处理
        var items = container.querySelectorAll(':scope > li[data-hash]');
        if (!items.length) {
            items = container.querySelectorAll('li[data-hash]');
        }
        
        items.forEach(function (li) {
            var hash = li.dataset.hash;
            if (EXPANDED_NODES[hash]) {
                // 找到该 li 下直接的 toggle 和 ul
                var children = li.children;
                var toggle = null;
                var subUl = null;
                
                for (var i = 0; i < children.length; i++) {
                    var child = children[i];
                    if (child.classList && child.classList.contains('mmf-tree-item')) {
                        toggle = child.querySelector('.mmf-tree-toggle');
                    }
                    if (child.tagName === 'UL') {
                        subUl = child;
                    }
                }
                
                if (toggle && subUl) {
                    toggle.classList.add('expanded');
                    subUl.style.display = 'block';
                    
                    // 如果是占位符且为空，需要重新加载
                    if (subUl.classList.contains('mmf-tree-placeholder') && subUl.children.length === 0) {
                        loadTreeChildren(hash, subUl);
                    }
                }
            }
        });
    }

    /**
     * 绑定目录树的事件
     */
    function bindTreeEvents(container) {
        // 点击目录名称：打开该目录
        qsa('.mmf-tree-item', container).forEach(function (item) {
            item.addEventListener('click', function (e) {
                // 如果点击的是展开箭头，不触发打开目录
                if (e.target.classList.contains('mmf-tree-toggle')) {
                    return;
                }
                var hash = item.dataset.hash;
                openDir(hash);
            });
        });

        // 点击展开箭头：展开/折叠子目录
        qsa('.mmf-tree-toggle', container).forEach(function (toggle) {
            toggle.addEventListener('click', function (e) {
                e.stopPropagation();
                var li = toggle.closest('li');
                if (!li) return;
                var hash = li.dataset.hash;
                
                // 找到 li 下直接的 ul 子元素
                var subUl = null;
                var children = li.children;
                for (var i = 0; i < children.length; i++) {
                    if (children[i].tagName === 'UL') {
                        subUl = children[i];
                        break;
                    }
                }
                
                if (!subUl) return;
                
                var isExpanded = toggle.classList.contains('expanded');
                
                if (isExpanded) {
                    // 折叠
                    toggle.classList.remove('expanded');
                    subUl.style.display = 'none';
                    delete EXPANDED_NODES[hash];
                } else {
                    // 展开
                    toggle.classList.add('expanded');
                    subUl.style.display = 'block';
                    EXPANDED_NODES[hash] = true;
                    
                    // 如果是占位符，需要懒加载
                    if (subUl.classList.contains('mmf-tree-placeholder')) {
                        loadTreeChildren(hash, subUl);
                    }
                }
            });
        });
    }

    /**
     * 懒加载目录的子目录
     */
    function loadTreeChildren(parentHash, placeholder) {
        placeholder.innerHTML = '<li class="mmf-tree-loading">' + t('loading') + '</li>';
        
        api({ cmd: 'tree', target: parentHash }, function (data) {
            if (data.tree && data.tree.length) {
                // 添加到 TREE 缓存
                data.tree.forEach(function (f) {
                    TREE[f.hash] = f;
                    if (!FILES[f.hash]) FILES[f.hash] = f;
                });
                
                // 构建子目录 HTML
                var childMap = {};
                var directChildren = [];
                data.tree.forEach(function (f) {
                    if (f.phash === parentHash) {
                        directChildren.push(f);
                    } else if (f.phash) {
                        if (!childMap[f.phash]) childMap[f.phash] = [];
                        childMap[f.phash].push(f);
                    }
                });
                
                placeholder.classList.remove('mmf-tree-placeholder');
                placeholder.innerHTML = buildTreeHtml(directChildren, childMap);
                bindTreeEvents(placeholder);
            } else {
                placeholder.innerHTML = '';
            }
        }, function (err) {
            placeholder.innerHTML = '<li class="mmf-tree-error">' + escHtml(err) + '</li>';
        });
    }

    function buildTreeHtml(nodes, childMap) {
        if (!nodes || !nodes.length) return '';
        var html = '';
        nodes.forEach(function (n) {
            var kids = childMap[n.hash];
            var hasKids = kids && kids.length;
            var hasDirs = n.dirs && n.dirs > 0;
            var isActive = n.hash === CWD_HASH;
            // 判断是否展开：有子节点已加载、在当前路径上、或之前已手动展开
            var isExpanded = hasKids || isInCurrentPath(n.hash) || EXPANDED_NODES[n.hash];
            
            html += '<li data-hash="' + n.hash + '">';
            html += '<div class="mmf-tree-item' + (isActive ? ' active' : '') + '" data-hash="' + n.hash + '">';
            
            // 展开箭头：有子目录（已加载或标记有子目录）时显示
            if (hasKids || hasDirs) {
                html += '<span class="mmf-tree-toggle' + (isExpanded ? ' expanded' : '') + '">\u25B6</span>';
            } else {
                html += '<span class="mmf-tree-toggle"></span>';
            }
            
            html += '\uD83D\uDCC1 ' + escHtml(n.name);
            html += '</div>';
            
            if (hasKids) {
                html += '<ul style="display:' + (isExpanded ? 'block' : 'none') + '">' + buildTreeHtml(kids, childMap) + '</ul>';
            } else if (hasDirs) {
                // 占位符，用于懒加载；如果之前已展开则显示
                var placeholderDisplay = EXPANDED_NODES[n.hash] ? 'block' : 'none';
                html += '<ul class="mmf-tree-placeholder" style="display:' + placeholderDisplay + '" data-parent="' + n.hash + '"></ul>';
            }
            
            html += '</li>';
        });
        return html;
    }

    /**
     * 检查 hash 是否在当前路径上
     */
    function isInCurrentPath(hash) {
        if (!CWD_HASH || hash === CWD_HASH) return true;
        var cur = CWD_HASH;
        while (cur && FILES[cur]) {
            if (FILES[cur].phash === hash) return true;
            cur = FILES[cur].phash;
        }
        return false;
    }

    function renderFiles() {
        var contentEl = qs('.mmf-content');
        var container = qs('.mmf-grid');
        
        if (!container && contentEl) {
            container = document.createElement('div');
            container.className = 'mmf-grid';
            contentEl.appendChild(container);
        }
        
        if (!container) {
            return;
        }

        var items = [];
        for (var h in FILES) {
            var f = FILES[h];
            if (f.phash === CWD_HASH && f.hash !== CWD_HASH) {
                items.push(f);
            }
        }

        items.sort(function (a, b) {
            var aDir = a.mime === 'directory' ? 0 : 1;
            var bDir = b.mime === 'directory' ? 0 : 1;
            if (aDir !== bDir) return aDir - bDir;
            return (a.name || '').localeCompare(b.name || '');
        });

        // 清空并渲染
        container.innerHTML = '';
        
        if (!items.length) {
            container.innerHTML = '<div class="mmf-empty"><div class="mmf-empty-icon">\uD83D\uDCC2</div><div>' + t('noFiles') + '</div></div>';
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
            // 隐藏 grid，显示 loading
            grid.style.display = 'none';
            var loadingEl = qs('.mmf-loading', el);
            if (!loadingEl) {
                loadingEl = document.createElement('div');
                loadingEl.className = 'mmf-loading';
                loadingEl.innerHTML = '<span class="mmf-spinner"></span>' + t('loading');
                el.appendChild(loadingEl);
            }
        } else {
            // 移除所有 loading 层，显示 grid
            qsa('.mmf-loading', el).forEach(function(l) { l.remove(); });
            grid.style.display = '';
        }
    }

    function bindFileEvents(container) {
        qsa('.mmf-item', container).forEach(function (el) {
            el.addEventListener('click', function (e) {
                var hash = el.dataset.hash;
                var f = FILES[hash];
                if (f && f.mime === 'directory') {
                    return;
                }
                if (e.ctrlKey || e.metaKey) {
                    if (CONFIG.multi) {
                        toggleSelect(hash);
                    } else {
                        SELECTED = [hash];
                        highlightSelected();
                    }
                } else {
                    if (!CONFIG.multi) {
                        SELECTED = [hash];
                    } else {
                        toggleSelect(hash);
                    }
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
                    SELECTED = [hash];
                    highlightSelected();
                    selectFiles();
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

    function bindToolbar() {
        var btnUpload = qs('#mmf-btn-upload');
        var btnNewFolder = qs('#mmf-btn-newfolder');
        var btnRename = qs('#mmf-btn-rename');
        var btnDelete = qs('#mmf-btn-delete');
        var btnRefresh = qs('#mmf-btn-refresh');
        var btnDownload = qs('#mmf-btn-download');
        var btnSelect = qs('#mmf-btn-select');
        var fileInput = qs('#mmf-file-input');

        if (btnUpload) btnUpload.addEventListener('click', function () {
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
        if (btnSelect) btnSelect.addEventListener('click', function () { selectFiles(); });

    }

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
        var allowedExts = CONFIG.ext && CONFIG.ext !== '*' ? CONFIG.ext.split(',').map(function(e) { return e.trim().toLowerCase(); }) : null;
        var maxSize = CONFIG.size || 102400;

        var validFiles = [];
        for (var i = 0; i < fileList.length; i++) {
            var file = fileList[i];
            
            // 大小检查
            if (file.size > maxSize) {
                showError(t('fileSizeExceeded', {name: file.name, size: humanSize(maxSize)}));
                return;
            }
            
            // 扩展名检查
            if (allowedExts) {
                var ext = (file.name || '').split('.').pop().toLowerCase();
                if (allowedExts.indexOf(ext) < 0) {
                    showError(t('fileTypeNotAllowed', {ext: ext, allowed: allowedExts.join(', ')}));
                    return;
                }
            }
            
            validFiles.push(file);
        }

        if (!validFiles.length) {
            showError(t('noValidFiles'));
            return;
        }

        var fd = new FormData();
        fd.append('cmd', 'upload');
        fd.append('target', CWD_HASH);
        for (var j = 0; j < validFiles.length; j++) {
            fd.append('upload[]', validFiles[j]);
        }
        showUploadProgress(true);
        updateUploadProgress(0);
        api(fd, function (data) {
            showUploadProgress(false);
            showSuccess(t('uploadComplete'));
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

    function promptNewFolder() {
        showDialog(t('newFolder'), t('folderName'), t('untitled'), function (name) {
            if (!name) return;
            api({ cmd: 'mkdir', target: CWD_HASH, name: name }, function () {
                showSuccess(t('folderCreated'));
                openDir(CWD_HASH);
            });
        });
    }

    function renameSelected() {
        if (SELECTED.length !== 1) { showError(t('selectOneToRename')); return; }
        var f = FILES[SELECTED[0]];
        if (!f) return;
        showDialog(t('rename'), t('newName'), f.name, function (name) {
            if (!name || name === f.name) return;
            api({ cmd: 'rename', target: f.hash, name: name }, function () {
                showSuccess(t('renamed'));
                openDir(CWD_HASH);
            });
        });
    }

    function deleteSelected() {
        if (!SELECTED.length) { showError(t('noItemsSelected')); return; }
        showConfirm(t('confirmDelete', {count: SELECTED.length}), function () {
            api({ cmd: 'rm', targets: SELECTED }, function () {
                showSuccess(t('deleted'));
                SELECTED = [];
                openDir(CWD_HASH);
            });
        });
    }

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

    /**
     * 文件选择回调 - 核心功能
     */
    function selectFiles() {
        if (!SELECTED.length) {
            showError(t('pleaseSelectFile'));
            return;
        }

        var selectedFiles = [];
        var allowedExts = CONFIG.ext && CONFIG.ext !== '*' ? CONFIG.ext.split(',').map(function(e) { return e.trim().toLowerCase(); }) : null;
        var maxSize = CONFIG.size || 102400;

        for (var i = 0; i < SELECTED.length; i++) {
            var hash = SELECTED[i];
            var f = FILES[hash];
            if (!f || f.mime === 'directory') continue;

            // 大小检查
            if (f.size && parseInt(f.size) > maxSize) {
                showError(t('fileSizeExceeded', {name: f.name, size: humanSize(maxSize)}));
                return;
            }

            // 扩展名检查
            if (allowedExts) {
                var ext = (f.name || '').split('.').pop().toLowerCase();
                if (allowedExts.indexOf(ext) < 0) {
                    showError(t('fileTypeNotAllowed', {ext: ext, allowed: allowedExts.join(', ')}));
                    return;
                }
            }

            selectedFiles.push(f);
        }

        if (!selectedFiles.length) {
            showError(t('pleaseSelectValidFiles'));
            return;
        }

        // 单选模式
        if (!CONFIG.multi && selectedFiles.length > 1) {
            selectedFiles = [selectedFiles[selectedFiles.length - 1]];
        }

        // 构建文件路径数组
        var urls = selectedFiles.map(function(f) {
            var relativePath = decodeHash(f.hash);
            return '/pub/media/' + relativePath;
        });

        // 回调到父窗口
        if (window.parent && CONFIG.target) {
            var parentDoc = window.parent.$(window.parent.document);
            var targetId = '#' + CONFIG.target;
            var preview = parentDoc.find(targetId + '-preview');

            if (!CONFIG.multi) {
                preview.empty();
                if (CONFIG.setAttr === 'text') {
                    parentDoc.find(targetId).focus().text(urls.join(',')).trigger('change').trigger('input');
                } else {
                    parentDoc.find(targetId).focus().val(urls.join(',')).trigger('change').trigger('input');
                }
            } else {
                var existUrls = parentDoc.find(targetId).val() || '';
                if (existUrls) existUrls += ',';
                if (CONFIG.setAttr === 'text') {
                    parentDoc.find(targetId).focus().text(existUrls + urls.join(',')).trigger('change').trigger('input');
                } else {
                    parentDoc.find(targetId).focus().val(existUrls + urls.join(',')).trigger('change').trigger('input');
                }
            }

            parentDoc.find(targetId).trigger('input').trigger('change');

            // 预览
            if (CONFIG.preview) {
                for (var j = 0; j < selectedFiles.length; j++) {
                    var sf = selectedFiles[j];
                    var fileJson = JSON.stringify(sf);
                    if (typeof fileJson.escapeString === 'function') {
                        fileJson = fileJson.escapeString();
                    }
                    var previewUrl = sf.tmb && sf.tmb !== '1' ? sf.tmb : '/pub/media/' + decodeHash(sf.hash);
                    var wrapper = document.createElement('div');
                    wrapper.innerHTML = '<div class="drag-item" draggable="true">' +
                        '<div class="close" onclick="delSelectedImage(event)">x</div>' +
                        '<img data-src="' + urls[j] + '" src="' + previewUrl + '" alt="' + escAttr(sf.name) + '" ' +
                        'class="drag-pic img-responsive" draggable="false" ' +
                        'data-file="' + escAttr(fileJson) + '">' +
                        '</div>';
                    preview.append(wrapper.firstChild);
                }
            }

            // 关闭弹窗
            if (parentDoc.find(targetId + '-close-modal').length > 0) {
                parentDoc.find(targetId + '-close-modal').click();
            }
        }
    }

    /**
     * 解码 hash 为相对路径
     */
    function decodeHash(hash) {
        if (!hash || !hash.startsWith('mm_')) return '';
        var b64 = hash.substring(3);
        b64 += '===='.substring(0, (4 - b64.length % 4) % 4);
        try {
            var decoded = atob(b64.replace(/-/g, '+').replace(/_/g, '/'));
            return decoded === '/' ? '' : decoded;
        } catch (e) {
            return '';
        }
    }

    function bindContextMenu() {
        document.addEventListener('click', function () { hideContextMenu(); });
    }

    function showContextMenu(x, y) {
        var menu = qs('.mmf-context-menu');
        if (!menu) return;
        var f = SELECTED.length === 1 ? FILES[SELECTED[0]] : null;
        var isDir = f && f.mime === 'directory';

        var html = '';
        if (f && !isDir) {
            html += '<div class="mmf-context-item" data-action="select">\u2714\uFE0F ' + t('ctxSelect') + '</div>';
            html += '<div class="mmf-context-item" data-action="download">\uD83D\uDCE5 ' + t('ctxDownload') + '</div>';
        }
        if (f && isDir) {
            html += '<div class="mmf-context-item" data-action="open">\uD83D\uDCC2 ' + t('ctxOpen') + '</div>';
        }
        if (SELECTED.length === 1) {
            html += '<div class="mmf-context-item" data-action="rename">\u270F\uFE0F ' + t('ctxRename') + '</div>';
        }
        if (SELECTED.length) {
            html += '<div class="mmf-context-sep"></div>';
            html += '<div class="mmf-context-item" data-action="delete">\uD83D\uDDD1\uFE0F ' + t('ctxDelete') + '</div>';
        }

        menu.innerHTML = html;
        menu.style.left = x + 'px';
        menu.style.top = y + 'px';
        menu.classList.add('visible');

        qsa('.mmf-context-item', menu).forEach(function (it) {
            it.addEventListener('click', function () {
                var action = it.dataset.action;
                hideContextMenu();
                if (action === 'select') selectFiles();
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
        showDialog(t('confirm'), '', msg, function () { onOk(); });
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function escAttr(s) {
        return (s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    window.WelineMediaManagerIframe = { init: init };

})();
