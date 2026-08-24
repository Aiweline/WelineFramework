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
    var LOCK_ROOT_PATH = '';
    var EXPANDED_NODES = {};
    var STORAGE_KEY = '';
    var START_PATH = '';
    var LAST_CLICKED_HASH = null;
    var SELECTION_MODE = false;
    var SELECTION_CONFIRMING = false;
    var GET_FILE_CALLBACK = null;
    var MULTI_SELECT = false;
    var ALLOWED_MIMES = [];
    var IFRAME_MODE = false;
    var I18N = {};
    var OPEN_REQUEST_SERIAL = 0;
    var STORAGE_CAPABILITIES = {};
    var CURRENT_CAPABILITIES = normalizeCapabilities({});
    var CONTEXT_MENU_BOUND = false;
    var CONTEXT_MENU_RETURN_FOCUS = null;
    var DIALOG_CLEANUP = null;
    var INTERNAL_DRAG_MIME = 'application/x-weline-media-files';
    var INTERNAL_DRAG_TARGETS = [];
    var INTERNAL_MOVE_PENDING = false;
    var EXTERNAL_DRAG_DEPTH = 0;
    var CLIPBOARD_BOUND = false;
    var UPLOAD_PENDING = false;
    var UPLOAD_XHR = null;
    var API_MAX_UPLOAD_FILE_BYTES = 14 * 1024 * 1024;
    var API_MAX_ASSET_UPLOAD_BYTES = 512 * 1024 * 1024;
    var UPLOAD_CHUNK_BYTES = 4 * 1024 * 1024;
    var API_MAX_UPLOAD_FILES = 100;
    var DETAILS_RETURN_FOCUS = null;
    var SAFE_UPLOAD_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'bmp', 'tiff', 'tif', 'avif',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'json',
        'zip', 'rar', 'gz', 'tar', '7z', 'mp3', 'wav', 'ogg', 'mp4', 'webm', 'avi',
        'mov', 'mkv', 'flv', 'wmv', 'ttf', 'otf', 'woff', 'woff2', 'eot'
    ];

    /* ─── helpers ────────────────────────────────────────────────────── */

    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return (ctx || document).querySelectorAll(sel); }

    function t(key, params) {
        var str = (I18N && I18N[key]) || key;
        if (params) {
            for (var k in params) {
                if (params.hasOwnProperty(k)) {
                    (function(value) {
                        str = str.replace(new RegExp('%\\{' + k + '\\}', 'g'), function() {
                            return String(value);
                        });
                    })(params[k]);
                }
            }
        }
        return str;
    }

    /**
     * Iframe media pickers never receive a backend bootstrap meta (only top-level
     * document navigations mint one). Walk only the same-origin ancestor chain and
     * reuse the page that owns the single authenticated Backend Worker marker.
     */
    function resolveBackendApiHost() {
        var candidate = window;
        while (candidate) {
            try {
                if (candidate.location.origin !== window.location.origin) {
                    break;
                }
                if (
                    candidate.document.querySelectorAll('meta[name="weline-worker-backend-bootstrap"]').length === 1
                    && candidate.Weline
                    && (
                        typeof candidate.Weline.load === 'function'
                        || candidate.Weline.Api
                    )
                ) {
                    return candidate;
                }
                if (!candidate.parent || candidate.parent === candidate) {
                    break;
                }
                candidate = candidate.parent;
            } catch (_error) {
                // Cross-origin parents must never receive or proxy backend authority.
                break;
            }
        }
        return window;
    }

    function mmResource(op, params) {
        var run = function(api){
            if (!api || typeof api.resource !== 'function') {
                throw new Error(t('backendApiUnavailable'));
            }
            return api.resource('media_manager')[op](params || {});
        };
        var host = resolveBackendApiHost();
        if (host.Weline && typeof host.Weline.load === 'function') {
            return host.Weline.load('api').then(run);
        }
        return Promise.resolve().then(function() {
            return run(host.Weline && host.Weline.Api);
        });
    }

    function api(params, onDone, onErr) {
        var prepare = Promise.resolve(params || {});
        return prepare.then(function(payload) {
            if (
                payload
                && payload.cmd !== 'storages'
                && CURRENT_STORAGE
                && CURRENT_STORAGE !== 'local'
                && !payload.storage
            ) {
                payload.storage = CURRENT_STORAGE;
            }
            if (payload && payload.cmd !== 'storages' && !payload.locale_code) {
                payload.locale_code = CONFIG.localeCode || 'zh_Hans_CN';
            }
            return mmResource('connector', payload);
        }).then(function(data){
            if (data && data.error) {
                (onErr || showError)(Array.isArray(data.error) ? data.error.join(', ') : data.error);
            } else {
                onDone && onDone(data);
            }
            return data;
        }).catch(function(err){
            (onErr || showError)((err && err.message) || t('networkError'));
            return null;
        });
    }

    function showError(msg) {
        announceInteraction(msg);
        var ui = window.Weline && window.Weline.UI;
        if (ui && ui.toast && typeof ui.toast.error === 'function') {
            ui.toast.error(msg);
        } else {
            console.error('[MediaManager]', msg);
        }
    }

    function showSuccess(msg) {
        announceInteraction(msg);
        var ui = window.Weline && window.Weline.UI;
        if (ui && ui.toast && typeof ui.toast.success === 'function') {
            ui.toast.success(msg);
        } else {
            console.log('[MediaManager]', msg);
        }
    }

    function humanSize(bytes) {
        bytes = Number(bytes);
        if (!Number.isFinite(bytes) || bytes < 0) return '—';
        if (bytes === 0) return '0 B';
        var u = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        while (bytes >= 1024 && i < u.length - 1) { bytes /= 1024; i++; }
        return bytes.toFixed(i ? 1 : 0) + ' ' + u[i];
    }

    function humanDateTime(value) {
        if (value === null || value === undefined || value === '') return '—';
        if (typeof value === 'string' && !/^\d+$/.test(value)) return value;
        var timestamp = Number(value);
        if (!Number.isFinite(timestamp) || timestamp <= 0) return '—';
        var date = new Date(timestamp < 1000000000000 ? timestamp * 1000 : timestamp);
        return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
    }

    function isImage(mime) {
        return typeof mime === 'string' && mime.indexOf('image/') === 0;
    }

    function isSvgFile(f) {
        if (!f) return false;
        if (f.mime === 'image/svg+xml') return true;
        return /\.svg$/i.test(String(f.name || ''));
    }

    function getConnectorResourceUrl(command, hash, extraParams) {
        if (!CONNECTOR || !hash) return '';
        var rel = CONNECTOR + (CONNECTOR.indexOf('?') >= 0 ? '&' : '?')
            + 'cmd=' + encodeURIComponent(command)
            + '&target=' + encodeURIComponent(hash)
            + '&locale_code=' + encodeURIComponent(CONFIG.localeCode || 'zh_Hans_CN');
        if (CURRENT_STORAGE && CURRENT_STORAGE !== 'local') {
            rel += '&storage=' + encodeURIComponent(CURRENT_STORAGE);
        }
        Object.keys(extraParams || {}).forEach(function(key) {
            rel += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(String(extraParams[key]));
        });
        try {
            return new URL(rel, document.baseURI).href;
        } catch (e) {
            return rel;
        }
    }

    function getFileResourceUrl(hash) {
        return getConnectorResourceUrl('file', hash);
    }

    function getThumbnailUrl(f) {
        if (!f || f.mime === 'directory') return null;
        if (f.preview_url) {
            return String(f.preview_url);
        }
        if (isSvgFile(f)) {
            return getFileResourceUrl(f.hash);
        }
        if (f.tmb && f.tmb !== '1') {
            return f.tmb;
        }
        if (f.tmb === '1' && CONNECTOR) {
            return getConnectorResourceUrl('tmb', f.hash);
        }
        // 无 elFinder 缩略图时，图片仍用原图在网格/侧栏预览
        if (isImageMime(f.mime) && f.hash) {
            return getFileResourceUrl(f.hash);
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

    var CURRENT_STORAGE = 'local::filesystem::media';
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
        if (document.documentElement) {
            var themePreference = typeof options.themePreference === 'string' ? options.themePreference : options.themeMode;
            if (themePreference !== 'system' && themePreference !== 'light' && themePreference !== 'dark') themePreference = 'system';
            var backendThemeRuntime = window.__WelineBackendThemeRuntime;
            if (backendThemeRuntime && typeof backendThemeRuntime.apply === 'function') {
                backendThemeRuntime.apply(themePreference);
            } else {
                var media = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)');
                var resolvedTheme = themePreference === 'dark' || (themePreference === 'system' && media && media.matches) ? 'dark' : 'light';
                document.documentElement.setAttribute('data-w-area', 'backend');
                document.documentElement.setAttribute('data-theme-preference', themePreference);
                document.documentElement.setAttribute('data-theme', resolvedTheme);
                document.documentElement.style.colorScheme = resolvedTheme;
            }
        }
        CONNECTOR = (typeof connectorUrl === 'string' ? connectorUrl : '').trim();
        if (!CONNECTOR) {
            setLoading(false);
            showError(t('connectorNotConfigured'));
            return;
        }
        START_PATH = (typeof startPath === 'string' ? startPath : '').trim();
        CURRENT_CAPABILITIES = normalizeCapabilities({});
        updateStorageKey();
        resetTransientUi();

        // 检查是否为 iframe 模式（通过 options.isIframe 或检测 window.parent）
        IFRAME_MODE = !!options.isIframe || (window.parent && window.parent !== window);
        MULTI_SELECT = !!options.multi;
        if (IFRAME_MODE) {
            document.documentElement.classList.add('mmf-iframe-host');
            document.body.classList.add('mmf-iframe-host');
        }
        
        bindToolbar();
        bindDragDrop();
        bindClipboardPaste();
        bindContextMenu();
        bindPreviewPanel();
        bindResponsiveChrome();
        bindDetailsDialog();
        bindAiDraw();
        updateToolbarCapabilities();
        
        // iframe 模式下绑定选择工具栏
        if (IFRAME_MODE) {
            window.addEventListener('message', handleParentMessage);
            bindSelectBar();
            bindIframeLayoutHost();
        }

        loadStorages().then(function(ready) {
            if (!ready) {
                setLoading(false);
                return;
            }
            var lastHash = loadLastPath();
            if (CONFIG.lockPath) lastHash = null;
            if (IFRAME_MODE && (CONFIG.initialValue || '').trim()) {
                lastHash = null;
            }
            openDir(lastHash || '', true);
        });
    }

    function loadStorages() {
        var select = qs('#mmf-storage-select');
        if (!select || !CONNECTOR) return Promise.resolve(false);

        var loading = mmResource('connector', {
            cmd: 'storages',
            locale_code: CONFIG.localeCode || 'zh_Hans_CN'
        })
            .then(function(data) {
                if (data && data.error) {
                    throw new Error(Array.isArray(data.error) ? data.error.join(', ') : String(data.error));
                }
                if (!data || !Array.isArray(data.storages) || !data.storages.length) {
                    throw new Error(t('storageCatalogEmpty'));
                }
                select.replaceChildren();
                var selectedStorage = null;
                var declaredDefault = null;
                data.storages.forEach(function(s) {
                    var opt = document.createElement('option');
                    opt.value = String(s.name || '');
                    opt.textContent = s.display_name || s.name;
                    opt.disabled = s.available === false || !opt.value;
                    STORAGE_CAPABILITIES[opt.value] = normalizeCapabilities(s.capabilities);
                    if (s.is_default && !declaredDefault) declaredDefault = s;
                    if (!opt.disabled && s.is_default && !selectedStorage) selectedStorage = s;
                    select.appendChild(opt);
                });
                if (declaredDefault && !selectedStorage) {
                    throw new Error(t('defaultStorageUnavailable'));
                }
                if (!declaredDefault) {
                    selectedStorage = data.storages.find(function(s) {
                        return s && s.available !== false && String(s.name || '') !== '';
                    }) || null;
                }
                if (!selectedStorage) throw new Error(t('storageCatalogUnavailable'));
                CURRENT_STORAGE = String(selectedStorage.name);
                CURRENT_CAPABILITIES = STORAGE_CAPABILITIES[CURRENT_STORAGE] || normalizeCapabilities({});
                select.value = CURRENT_STORAGE;
                updateStorageKey();
                updateToolbarCapabilities();
                return true;
            })
            .catch(function(e) {
                showError((e && e.message) || t('storageCatalogUnavailable'));
                return false;
            });

        if (select.dataset.mmfStorageBound !== '1') {
            select.dataset.mmfStorageBound = '1';
            select.addEventListener('change', function() {
                CURRENT_STORAGE = this.value;
                CURRENT_CAPABILITIES = STORAGE_CAPABILITIES[CURRENT_STORAGE] || normalizeCapabilities({});
                updateStorageKey();
                SELECTED.length = 0;
                FILES = {};
                TREE = {};
                EXPANDED_NODES = {};
                CWD_HASH = '';
                ROOT_HASH = '';
                LOCK_ROOT_PATH = '';
                openDir('', true);
                updateToolbarCapabilities();
            });
        }
        return loading;
    }

    function updateStorageKey() {
        STORAGE_KEY = 'mmf_last_path_' + hashCode((START_PATH || '_root_') + '|' + CURRENT_STORAGE);
    }

    function normalizeCapabilities(capabilities) {
        var normalized = {
            browse: false,
            create_directory: false,
            rename_directory: false,
            delete_directory: false,
            rename_file: false,
            move_file: false,
            delete_file: false,
            upload: false,
            download: false,
            preview: false,
            copy_url: false,
            ai_edit: false
        };
        if (capabilities && typeof capabilities === 'object') {
            Object.keys(normalized).forEach(function(key) {
                normalized[key] = capabilities[key] === true;
            });
            if (!Object.prototype.hasOwnProperty.call(capabilities, 'move_file')) {
                normalized.move_file = capabilities.rename_file === true;
            }
        }
        return normalized;
    }

    function hasCapability(name) {
        return CURRENT_CAPABILITIES && CURRENT_CAPABILITIES[name] === true;
    }

    function itemCapability(action, file) {
        if (!file) return false;
        if (
            file.mime === 'directory'
            && (
                file.path === ''
                || file.phash === null
                || (CONFIG.lockPath && ROOT_HASH !== '' && file.hash === ROOT_HASH)
            )
            && (action === 'rename' || action === 'delete')
        ) {
            return false;
        }
        var suffix = file.mime === 'directory' ? 'directory' : 'file';
        return hasCapability(action + '_' + suffix);
    }

    function updateToolbarCapabilities() {
        var selected = SELECTED.length === 1 ? FILES[SELECTED[0]] : null;
        var map = [
            ['#mmf-btn-upload', hasCapability('upload')],
            ['#mmf-btn-newfolder', hasCapability('create_directory')],
            ['#mmf-btn-rename', SELECTED.length === 1 && itemCapability('rename', selected)],
            ['#mmf-btn-delete', SELECTED.length > 0 && SELECTED.every(function(hash) {
                return itemCapability('delete', FILES[hash]);
            })],
            ['#mmf-btn-download', !!selected && selected.mime !== 'directory' && hasCapability('download')],
            ['#mmf-btn-ai-draw', hasCapability('ai_edit')],
            ['#mmf-btn-select', IFRAME_MODE && pickerSelectionIsEligible()]
        ];
        map.forEach(function(entry) {
            var button = qs(entry[0]);
            if (button) {
                button.disabled = !entry[1];
                button.setAttribute('aria-disabled', entry[1] ? 'false' : 'true');
            }
        });
    }

    function resetTransientUi() {
        var menu = qs('.mmf-context-menu');
        if (menu) {
            menu.hidden = true;
            menu.dataset.state = 'closed';
            menu.setAttribute('aria-hidden', 'true');
        }
        var menuTrigger = qs('[data-mmf-context-menu-root] [data-w-menu-trigger]');
        if (menuTrigger) menuTrigger.setAttribute('aria-expanded', 'false');
        var dialog = qs('.mmf-dialog-overlay');
        if (dialog) {
            dialog.classList.remove('visible');
            dialog.setAttribute('aria-hidden', 'true');
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

    function normalizeBoundaryPath(path) {
        return String(path || '').trim().replace(/\\/g, '/').replace(/^\/+|\/+$/g, '');
    }

    function isPathWithinLockedRoot(path) {
        if (!CONFIG.lockPath || ROOT_HASH === '') return true;
        var candidate = normalizeBoundaryPath(path);
        if (LOCK_ROOT_PATH === '') return true;
        return candidate === LOCK_ROOT_PATH || candidate.indexOf(LOCK_ROOT_PATH + '/') === 0;
    }

    function canOpenDirectoryHash(hash) {
        if (!CONFIG.lockPath || ROOT_HASH === '') return true;
        if (hash === ROOT_HASH) return true;
        var directory = FILES[hash] || TREE[hash];
        return !!directory
            && directory.mime === 'directory'
            && isPathWithinLockedRoot(directory.path);
    }

    function openDir(target, isInit) {
        if (CONFIG.lockPath && !isInit && !canOpenDirectoryHash(target)) {
            showError(t('cannotAccessOutsidePath'));
            return;
        }
        saveExpandedState();
        setLoading(true);
        var requestSerial = ++OPEN_REQUEST_SERIAL;
        var wrap = qs('.mmf-wrap');
        if (wrap) wrap.dataset.openState = 'pending';
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
                if (requestSerial !== OPEN_REQUEST_SERIAL) return;
                setLoading(false);
                CWD_HASH = data.cwd ? data.cwd.hash : '';
                CWD_INFO = data.cwd || {};
                if (data.capabilities) {
                    CURRENT_CAPABILITIES = normalizeCapabilities(data.capabilities);
                    STORAGE_CAPABILITIES[CURRENT_STORAGE] = CURRENT_CAPABILITIES;
                }
                if (isInit && CWD_HASH) {
                    ROOT_HASH = CWD_HASH;
                    LOCK_ROOT_PATH = normalizeBoundaryPath(CWD_INFO.path || START_PATH);
                }

                FILES = {};
                if (data.cwd && data.cwd.hash) {
                    FILES[data.cwd.hash] = data.cwd;
                }
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
                syncCompactPreviewState();
                closeSidebarDrawer();
                updateToolbarCapabilities();
                if (IFRAME_MODE && CONFIG.initialValue && !CONFIG._initialSelectionApplied) {
                    CONFIG._initialSelectionApplied = true;
                    applyInitialSelection();
                }
                saveLastPath();
                if (wrap) wrap.dataset.openState = 'done';
            } catch (e) {
                if (requestSerial !== OPEN_REQUEST_SERIAL) return;
                setLoading(false);
                if (wrap) wrap.dataset.openState = 'error';
                showError(t('invalidResponse') + ': ' + (e && e.message ? e.message : String(e)));
            }
        }, function (err) {
            if (requestSerial !== OPEN_REQUEST_SERIAL) return;
            setLoading(false);
            if (wrap) wrap.dataset.openState = 'error';
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
            if (CONFIG.lockPath && !isPathWithinLockedRoot(f.path)) {
                continue;
            }
            if (
                !f.phash
                || !TREE[f.phash]
                || (CONFIG.lockPath && !isPathWithinLockedRoot(TREE[f.phash].path))
            ) {
                roots.push(f);
            } else {
                if (!childMap[f.phash]) childMap[f.phash] = [];
                childMap[f.phash].push(f);
            }
        }

        expandToPath(CWD_HASH);

        var el = qs('.mmf-tree');
        if (!el) return;
        var title = qs('.mmf-sidebar-title');
        el.setAttribute('aria-label', title ? title.textContent.trim() : 'Folders');
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
            var canManage = itemCapability('rename', n) || itemCapability('delete', n);
            html += '<li>';
            html += '<div class="mmf-tree-item' + (isActive ? ' active' : '') + '" role="treeitem" tabindex="0"';
            html += ' aria-selected="' + (isActive ? 'true' : 'false') + '" data-hash="' + escAttr(n.hash) + '">';
            html += '<span class="mmf-tree-toggle' + (isExpanded ? ' expanded' : '') + '" aria-hidden="true">';
            html += (hasKids || hasPlaceholder) ? (isExpanded ? '\u25BC' : '\u25B6') : '';
            html += '</span>';
            html += '<span class="mmf-tree-label">\uD83D\uDCC1 ' + escHtml(n.name) + '</span>';
            if (canManage) {
                html += '<button type="button" class="mmf-tree-more" data-mmf-tree-menu';
                html += ' aria-label="' + escAttr(t('directoryActions', {name: n.name || ''})) + '" aria-haspopup="menu">...</button>';
            }
            html += '</div>';
            if (hasKids) {
                html += '<ul role="group" style="display:' + (isExpanded ? 'block' : 'none') + '">' + buildTreeHtml(kids, childMap) + '</ul>';
            } else if (hasPlaceholder) {
                html += '<ul role="group" style="display:none" class="mmf-tree-placeholder"></ul>';
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
            bindDirectoryDropTarget(item);
            item.onclick = function (e) {
                if (e.target.classList.contains('mmf-tree-toggle') || e.target.closest('[data-mmf-tree-menu]')) return;
                var hash = item.getAttribute('data-hash');
                openDir(hash);
            };
            item.oncontextmenu = function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (!selectTreeItemForMenu(item)) return;
                showContextMenu(e.clientX, e.clientY, null, item);
            };
            item.onkeydown = function (e) {
                if (e.target.closest('[data-mmf-tree-menu]')) return;
                var hash = item.getAttribute('data-hash');
                if (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) {
                    e.preventDefault();
                    if (!selectTreeItemForMenu(item)) return;
                    var rect = item.getBoundingClientRect();
                    showContextMenu(rect.left, rect.bottom, item, item);
                    return;
                }
                if (e.key === ' ') {
                    e.preventDefault();
                    selectTreeItemForMenu(item);
                    return;
                }
                if (e.key === 'Enter') {
                    e.preventDefault();
                    openDir(hash);
                }
            };
            var more = qs('[data-mmf-tree-menu]', item);
            if (more) {
                more.onclick = function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (!selectTreeItemForMenu(item)) return;
                    var rect = more.getBoundingClientRect();
                    showContextMenu(rect.left, rect.bottom, more, more);
                };
            }
        });
    }

    function selectTreeItemForMenu(item) {
        var hash = item && item.getAttribute('data-hash');
        var file = hash ? (FILES[hash] || TREE[hash]) : null;
        if (!file) return false;
        FILES[hash] = file;
        SELECTED = [hash];
        highlightSelected();
        return true;
    }

    function loadSubtree(parentHash, placeholder, toggle) {
        toggle.textContent = '...';
        // cmd=tree：只拉直接子目录；勿传 tree 数字标志（WQB1 会保留为 int，与 string 契约冲突）
        api({ cmd: 'tree', target: parentHash }, function (data) {
            var nodes = data && (data.tree || data.files) ? (data.tree || data.files) : [];
            nodes.forEach(function (f) {
                if (f && f.hash) {
                    TREE[f.hash] = f;
                    FILES[f.hash] = f;
                }
            });
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
        }, function (err) {
            toggle.textContent = '\u25B6';
            showError(err);
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
            var empty = document.createElement('div');
            empty.className = 'mmf-empty';
            var emptyIcon = document.createElement('div');
            emptyIcon.className = 'mmf-empty-icon';
            emptyIcon.textContent = '\uD83D\uDCC2';
            var emptyLabel = document.createElement('div');
            emptyLabel.textContent = t('noFiles');
            empty.appendChild(emptyIcon);
            empty.appendChild(emptyLabel);
            container.replaceChildren(empty);
            return;
        }

        var html = '';
        items.forEach(function (f) {
            var isDir = f.mime === 'directory';
            var sel = SELECTED.indexOf(f.hash) >= 0;
            var thumbUrl = getThumbnailUrl(f);
            var selectionIssue = fileSelectionIssue(f);
            var disabledClass = selectionIssue ? ' mmf-item-disabled mmf-item-disabled-' + selectionIssue.kind : '';
            var itemLabel = String(f.name || '');
            if (selectionIssue) itemLabel += '. ' + selectionIssue.message;
            html += '<div class="mmf-item' + (sel ? ' selected' : '') + disabledClass + '" role="button" tabindex="0" aria-pressed="' + (sel ? 'true' : 'false') + '"';
            if (!isDir) html += ' draggable="true"';
            if (selectionIssue) {
                html += ' data-selection-error="' + escAttr(selectionIssue.message) + '"';
            }
            html += ' aria-label="' + escAttr(itemLabel) + '" data-hash="' + escAttr(f.hash) + '" data-mime="' + escAttr(f.mime || '') + '">';
            html += '<button type="button" class="mmf-item-more" data-mmf-item-menu';
            html += ' aria-label="' + escAttr(t(isDir ? 'directoryActions' : 'fileActions', {name: f.name || ''})) + '" aria-haspopup="menu">...</button>';
            html += '<div class="mmf-item-icon">';
            if (thumbUrl) {
                html += '<img src="' + escAttr(thumbUrl) + '" alt="" loading="lazy" class="mmf-thumb' + (isSvgFile(f) ? ' mmf-thumb-svg' : '') + '" data-fallback-icon="' + escAttr(fileIcon(f.mime, isDir)) + '">';
            } else {
                html += '<span class="mmf-icon-placeholder">' + fileIcon(f.mime, isDir) + '</span>';
            }
            html += '</div>';
            html += '<div class="mmf-item-name" title="' + escAttr(f.name) + '">' + escHtml(f.name) + '</div>';
            if (selectionIssue) {
                html += '<div class="mmf-item-hint">' + escHtml(selectionIssue.message) + '</div>';
            }
            html += '</div>';
        });
        container.innerHTML = html;

        bindThumbnailFallbacks(container);
        bindFileEvents(container);
        scheduleIframeLayoutHeightSync();
    }

    function bindThumbnailFallbacks(container) {
        qsa('img.mmf-thumb[data-fallback-icon]', container).forEach(function (img) {
            img.addEventListener('error', function onThumbError() {
                var item = img.closest('.mmf-item');
                var hash = item && item.dataset.hash;
                var file = hash ? FILES[hash] : null;
                if (file && isImageMime(file.mime) && img.dataset.fallbackSrc !== '1') {
                    var fileUrl = getFileResourceUrl(file.hash);
                    if (fileUrl && img.getAttribute('src') !== fileUrl) {
                        img.dataset.fallbackSrc = '1';
                        img.src = fileUrl;
                        return;
                    }
                }
                var holder = img.parentElement;
                if (!holder) return;

                var fallback = document.createElement('span');
                fallback.className = 'mmf-icon-placeholder';
                fallback.textContent = img.dataset.fallbackIcon || fileIcon('', false);
                holder.replaceChildren(fallback);
            });
        });
    }

    function renderPath() {
        var el = qs('.mmf-path');
        if (!el) return;
        var parts = [];
        var cur = CWD_HASH;
        while (cur && FILES[cur]) {
            parts.unshift(FILES[cur]);
            if (CONFIG.lockPath && cur === ROOT_HASH) break;
            cur = FILES[cur].phash;
        }
        var html = '';
        parts.forEach(function (p, i) {
            if (i > 0) html += '<span class="mmf-path-sep">/</span>';
            html += '<span class="mmf-path-seg" data-hash="' + escAttr(p.hash) + '">' + escHtml(p.name) + '</span>';
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
                var spinner = document.createElement('span');
                spinner.className = 'mmf-spinner';
                spinner.setAttribute('aria-hidden', 'true');
                loadingEl.appendChild(spinner);
                loadingEl.appendChild(document.createTextNode(t('loading')));
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
            var renderedFile = FILES[el.dataset.hash];
            if (renderedFile && renderedFile.mime === 'directory') {
                bindDirectoryDropTarget(el);
            } else if (renderedFile) {
                bindInternalDragSource(el);
            }

            el.addEventListener('click', function (e) {
                var hash = el.dataset.hash;
                if (e.detail >= 2 && openDirectoryFromInteraction(hash, el.dataset.mime)) {
                    return;
                }
                
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
                if (openDirectoryFromInteraction(hash, el.dataset.mime)) {
                    return;
                } else if (IFRAME_MODE) {
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
                if (!SELECTION_MODE && !e.ctrlKey && !e.metaKey) {
                    SELECTED = [hash];
                } else if (SELECTED.indexOf(hash) < 0) {
                    SELECTED.push(hash);
                }
                highlightSelected();
                updateToolbarCapabilities();
                showContextMenu(e.clientX, e.clientY, null, el);
            });

            el.addEventListener('keydown', function (e) {
                var hash = el.dataset.hash;
                var selectedHash = hash;
                var renderedMime = el.dataset.mime;
                if (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) {
                    e.preventDefault();
                    SELECTED = [hash];
                    highlightSelected();
                    var rect = el.getBoundingClientRect();
                    showContextMenu(rect.left, rect.bottom, el, el);
                    return;
                }
                if (e.key === ' ') {
                    e.preventDefault();
                    SELECTED = [hash];
                    highlightSelected();
                    return;
                }
                if (e.key !== 'Enter') return;
                e.preventDefault();
                if (!openDirectoryFromInteraction(selectedHash, renderedMime)) {
                    SELECTED = [hash];
                    highlightSelected();
                }
            });

            var more = qs('[data-mmf-item-menu]', el);
            if (more) {
                more.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var hash = el.dataset.hash;
                    SELECTED = [hash];
                    highlightSelected();
                    var rect = more.getBoundingClientRect();
                    showContextMenu(rect.left, rect.bottom, more, more);
                });
            }
        });
    }

    function openDirectoryFromInteraction(hash, renderedMime) {
        var file = FILES[hash];
        if ((file && file.mime === 'directory') || renderedMime === 'directory') {
            openDir(hash);
            return true;
        }
        return false;
    }

    function toggleSelect(hash) {
        var idx = SELECTED.indexOf(hash);
        if (idx >= 0) SELECTED.splice(idx, 1);
        else SELECTED.push(hash);
        highlightSelected();
    }

    function highlightSelected() {
        qsa('.mmf-item').forEach(function (el) {
            var selected = SELECTED.indexOf(el.dataset.hash) >= 0;
            el.classList.toggle('selected', selected);
            el.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        qsa('.mmf-tree-item').forEach(function (el) {
            var selected = SELECTED.indexOf(el.dataset.hash) >= 0;
            el.classList.toggle('context-selected', selected);
            el.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
        updatePreviewPanel();
        syncCompactPreviewState();
        if (IFRAME_MODE) {
            updateSelectBar();
        }
        updateToolbarCapabilities();
    }

    /* ─── preview panel ───────────────────────────────────────────────── */

    function metadataRows(file, full) {
        if (!file) return [];
        var isDirectory = file.mime === 'directory';
        var rows = [
            [t('metadataType'), isDirectory ? t('folder') : (file.mime || t('unknown'))],
            [t('metadataSize'), isDirectory ? '—' : humanSize(file.size)],
            [t('metadataPath'), file.path || file.object_key || '—', true]
        ];
        if (!isDirectory) {
            var dimensions = file.width && file.height ? file.width + ' × ' + file.height : '';
            rows.push([t('metadataDimensions'), dimensions || '—', false, 'dimensions']);
            rows.push([t('metadataModifiedAt'), humanDateTime(file.ts)]);
            rows.push([t('metadataDisk'), file.disk_code || CURRENT_STORAGE || '—', true]);
            rows.push([t('metadataObjectKey'), file.object_key || file.path || '—', true]);
            if (file.asset_id) {
                rows = rows.concat([
                    [t('metadataAssetId'), file.asset_id, true],
                    [t('metadataOriginalName'), file.original_name || file.name || '—'],
                    [t('metadataLifecycle'), file.lifecycle_state || (file.asset_ready ? t('ready') : t('draft'))],
                    [t('metadataVisibility'), file.visibility || '—'],
                    [t('metadataRevision'), file.asset_revision || '—'],
                    [t('metadataChecksum'), file.sha256 || '—', true],
                    [t('metadataDefaultLocale'), file.default_locale || '—'],
                    [t('metadataLocale'), file.locale_code || CONFIG.localeCode || '—'],
                    [t('metadataDisplayName'), file.display_name || '—', true],
                    [t('metadataAlt'), file.default_alt || '—', true],
                    [t('metadataDescription'), file.description || '—', true],
                    [t('metadataCaption'), file.default_caption || '—', true],
                    [t('metadataTranslationState'), file.translation_state || '—'],
                    [t('metadataTranslationOrigin'), file.translation_origin || '—'],
                    [t('metadataSelectable'), file.asset_selectable === true ? t('yes') : t('no')],
                    [t('metadataCreatedAt'), file.created_at || '—'],
                    [t('metadataUpdatedAt'), file.updated_at || '—']
                ]);
            } else {
                rows.push([t('metadataAssetStatus'), t('assetUnregistered'), true]);
            }
        }
        if (!full) {
            return rows.filter(function(row, index) {
                return index < 4 || [
                    t('metadataDisplayName'),
                    t('metadataAlt'),
                    t('metadataDescription'),
                    t('metadataTranslationState'),
                    t('metadataAssetStatus')
                ].indexOf(row[0]) >= 0;
            });
        }
        return rows;
    }

    function renderMetadataList(container, file, full) {
        if (!container) return;
        container.dataset.fileHash = String((file && file.hash) || '');
        container.replaceChildren();
        metadataRows(file, full).forEach(function(row) {
            var wrapper = document.createElement('div');
            wrapper.className = 'mmf-preview-meta-item mmf-metadata-row' + (row[2] ? ' is-long' : '');
            if (row[3]) wrapper.dataset.field = row[3];
            var label = document.createElement('dt');
            label.className = 'mmf-preview-meta-label mmf-metadata-label';
            label.textContent = row[0];
            var value = document.createElement('dd');
            value.className = 'mmf-preview-meta-value mmf-metadata-value';
            value.textContent = row[1] === null || row[1] === undefined || row[1] === '' ? '—' : String(row[1]);
            wrapper.appendChild(label);
            wrapper.appendChild(value);
            container.appendChild(wrapper);
        });
    }

    function updateRenderedDimensions(file, width, height) {
        if (!file || width < 1 || height < 1) return;
        if (!file.width) file.width = width;
        if (!file.height) file.height = height;
        qsa('[data-field="dimensions"] .mmf-metadata-value').forEach(function(value) {
            var metadataList = value.closest('[data-file-hash]');
            if (metadataList && metadataList.dataset.fileHash !== String(file.hash || '')) return;
            value.textContent = width + ' × ' + height;
        });
    }

    function clearPreviewImage() {
        var image = qs('.mmf-preview-img');
        if (!image) return;
        image.onload = null;
        image.onerror = null;
        image.removeAttribute('src');
        image.alt = '';
        image.style.background = '';
        image.style.padding = '';
    }

    function isPreviewElementVisible(el) {
        return !!(el && window.getComputedStyle(el).display !== 'none');
    }

    function resetPreviewDetailScroll() {
        var scrollEl = qs('.mmf-preview-info-scroll');
        if (scrollEl) scrollEl.style.maxHeight = '';
    }

    function syncPreviewDetailScroll() {
        var preview = qs('.mmf-preview');
        var scrollEl = qs('.mmf-preview-info-scroll');
        var infoEl = qs('.mmf-preview-info');
        var actionsEl = qs('.mmf-preview-actions');
        if (!preview || !scrollEl || !infoEl || !isPreviewElementVisible(infoEl)) {
            resetPreviewDetailScroll();
            return;
        }

        var previewRect = preview.getBoundingClientRect();
        var scrollRect = scrollEl.getBoundingClientRect();
        var actionsHeight = isPreviewElementVisible(actionsEl)
            ? actionsEl.getBoundingClientRect().height
            : 0;
        var available = previewRect.bottom - scrollRect.top - actionsHeight - 6;

        scrollEl.style.maxHeight = Math.max(120, Math.floor(available)) + 'px';
        scrollEl.style.overflowY = 'auto';
    }

    function schedulePreviewDetailScrollSync() {
        syncPreviewDetailScroll();
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(function () {
                syncPreviewDetailScroll();
            });
        }
    }

    function syncIframeLayoutHeight() {
        if (!IFRAME_MODE) return;
        var viewportHeight = window.innerHeight
            || document.documentElement.clientHeight
            || document.body.clientHeight
            || 0;
        if (viewportHeight <= 0) return;
        var heightPx = Math.floor(viewportHeight) + 'px';
        document.documentElement.style.height = heightPx;
        document.documentElement.style.maxHeight = heightPx;
        document.documentElement.style.overflow = 'hidden';
        if (document.body) {
            document.body.style.height = heightPx;
            document.body.style.maxHeight = heightPx;
            document.body.style.overflow = 'hidden';
            document.body.style.margin = '0';
        }
        var main = qs('main.w-backend-page') || qs('#main-content');
        if (main) {
            main.style.height = heightPx;
            main.style.maxHeight = heightPx;
            main.style.minHeight = '0';
            main.style.flex = '1 1 auto';
            main.style.display = 'flex';
            main.style.flexDirection = 'column';
            main.style.overflow = 'hidden';
            main.style.padding = '0';
            main.style.margin = '0';
        }
        var wrap = qs('.mmf-wrap');
        if (wrap) {
            wrap.style.height = heightPx;
            wrap.style.maxHeight = heightPx;
            wrap.style.minHeight = '0';
        }
        schedulePreviewDetailScrollSync();
    }

    function scheduleIframeLayoutHeightSync() {
        syncIframeLayoutHeight();
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(syncIframeLayoutHeight);
        }
    }

    function bindIframeLayoutHost() {
        if (!IFRAME_MODE) return;
        scheduleIframeLayoutHeightSync();
        if (typeof window !== 'undefined') {
            window.addEventListener('resize', scheduleIframeLayoutHeightSync, { passive: true });
        }
        if (typeof window.visualViewport !== 'undefined' && window.visualViewport) {
            window.visualViewport.addEventListener('resize', scheduleIframeLayoutHeightSync, { passive: true });
        }
    }

    function updatePreviewPanel() {
        var emptyEl = qs('.mmf-preview-empty');
        var imageEl = qs('.mmf-preview-image');
        var infoEl = qs('.mmf-preview-info');

        if (!emptyEl || !imageEl || !infoEl) return;

        if (SELECTED.length !== 1) {
            clearPreviewImage();
            emptyEl.style.display = '';
            imageEl.style.display = 'none';
            infoEl.style.display = 'none';
            var contentElReset = qs('.mmf-preview-content');
            if (contentElReset) contentElReset.classList.remove('mmf-preview-content--with-image');
            resetPreviewDetailScroll();
            return;
        }

        var f = FILES[SELECTED[0]];
        if (!f) {
            clearPreviewImage();
            emptyEl.style.display = '';
            imageEl.style.display = 'none';
            infoEl.style.display = 'none';
            var contentElMissing = qs('.mmf-preview-content');
            if (contentElMissing) contentElMissing.classList.remove('mmf-preview-content--with-image');
            resetPreviewDetailScroll();
            return;
        }

        emptyEl.style.display = 'none';
        infoEl.style.display = 'grid';

        var nameEl = qs('.mmf-preview-name');
        var metadataEl = qs('[data-mmf-preview-meta]');
        var btnOpen = qs('.mmf-preview-btn-open');
        var btnDownload = qs('.mmf-preview-btn-download');
        var btnDetails = qs('.mmf-preview-btn-details');

        if (nameEl) nameEl.textContent = f.name || '';
        renderMetadataList(metadataEl, f, true);

        if (isImageMime(f.mime) && hasCapability('preview')) {
            imageEl.style.display = 'flex';
            var img = qs('.mmf-preview-img');
            if (img) {
                var previewUrl = getThumbnailUrl(f) || getFileResourceUrl(f.hash) || '';
                if (previewUrl) {
                    img.src = previewUrl;
                } else {
                    img.removeAttribute('src');
                }
                img.alt = f.default_alt || f.display_name || f.name || '';
                if (isSvgFile(f)) {
                    img.style.background = '#fff';
                    img.style.padding = '12px';
                } else {
                    img.style.background = '';
                    img.style.padding = '';
                }
                img.onload = function () {
                    updateRenderedDimensions(f, img.naturalWidth, img.naturalHeight);
                    schedulePreviewDetailScrollSync();
                };
                img.onerror = function () {
                    schedulePreviewDetailScrollSync();
                };
            }
            if (btnOpen) {
                btnOpen.textContent = '\uD83D\uDD0D ' + t('preview');
                btnOpen.style.display = '';
            }
        } else {
            clearPreviewImage();
            imageEl.style.display = 'none';
            if (f.mime === 'directory' && hasCapability('browse')) {
                if (btnOpen) {
                    btnOpen.textContent = '\uD83D\uDCC2 ' + t('open');
                    btnOpen.style.display = '';
                }
            } else {
                if (btnOpen) btnOpen.style.display = 'none';
            }
        }

        if (btnDownload) {
            btnDownload.style.display = f.mime === 'directory' || !hasCapability('download') ? 'none' : '';
        }
        if (btnDetails) {
            btnDetails.style.display = f.mime === 'directory' ? 'none' : '';
        }
        var btnAiEdit = qs('.mmf-preview-btn-ai-edit');
        if (btnAiEdit) {
            btnAiEdit.style.display = (isImageMime(f.mime) && !IFRAME_MODE && hasCapability('ai_edit')) ? '' : 'none';
        }

        var contentEl = qs('.mmf-preview-content');
        if (contentEl) {
            contentEl.classList.toggle('mmf-preview-content--with-image', isPreviewElementVisible(imageEl));
        }
        schedulePreviewDetailScrollSync();
        scheduleIframeLayoutHeightSync();
    }

    function bindPreviewPanel() {
        var imageEl = qs('.mmf-preview-image');
        var btnOpen = qs('.mmf-preview-btn-open');
        var btnDownload = qs('.mmf-preview-btn-download');
        var btnDetails = qs('.mmf-preview-btn-details');

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
        if (btnDetails) {
            btnDetails.onclick = function () {
                if (SELECTED.length === 1) openAssetDetails(SELECTED[0]);
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

        if (typeof window !== 'undefined') {
            window.addEventListener('resize', schedulePreviewDetailScrollSync, { passive: true });
        }
        var previewRoot = qs('.mmf-preview');
        if (previewRoot && typeof window.ResizeObserver === 'function') {
            var previewResizeObserver = new window.ResizeObserver(function () {
                schedulePreviewDetailScrollSync();
            });
            previewResizeObserver.observe(previewRoot);
        }
    }

    /* ─── compact responsive chrome ───────────────────────────────────── */

    var COMPACT_MQ = typeof window !== 'undefined' && window.matchMedia
        ? window.matchMedia('(max-width: 768px)')
        : null;

    function isCompactLayout() {
        return !!(COMPACT_MQ && COMPACT_MQ.matches);
    }

    function chromeWrap() {
        return qs('.mmf-wrap');
    }

    function syncChromeBackdrop() {
        var wrap = chromeWrap();
        var backdrop = qs('[data-mmf-chrome-backdrop]');
        if (!wrap || !backdrop) return;
        var open = wrap.classList.contains('is-sidebar-open') || wrap.classList.contains('is-preview-open');
        if (open && isCompactLayout()) {
            backdrop.hidden = false;
            backdrop.setAttribute('aria-hidden', 'false');
        } else {
            backdrop.hidden = true;
            backdrop.setAttribute('aria-hidden', 'true');
        }
    }

    function setSidebarDrawer(open) {
        var wrap = chromeWrap();
        var toggle = qs('[data-mmf-toggle-sidebar]');
        if (!wrap) return;
        wrap.classList.toggle('is-sidebar-open', !!open && isCompactLayout());
        if (toggle) toggle.setAttribute('aria-expanded', wrap.classList.contains('is-sidebar-open') ? 'true' : 'false');
        syncChromeBackdrop();
    }

    function setPreviewDrawer(open) {
        var wrap = chromeWrap();
        if (!wrap) return;
        wrap.classList.toggle('is-preview-open', !!open && isCompactLayout());
        syncChromeBackdrop();
    }

    function closeSidebarDrawer() {
        setSidebarDrawer(false);
    }

    function closePreviewDrawer() {
        setPreviewDrawer(false);
    }

    function closeChromeDrawers() {
        closeSidebarDrawer();
        closePreviewDrawer();
    }

    function syncCompactPreviewState() {
        if (!isCompactLayout()) {
            closeChromeDrawers();
            return;
        }
        if (SELECTED.length !== 1) {
            closePreviewDrawer();
            return;
        }
        var file = FILES[SELECTED[0]];
        if (!file || file.mime === 'directory') {
            closePreviewDrawer();
            return;
        }
        setSidebarDrawer(false);
        setPreviewDrawer(true);
    }

    function bindResponsiveChrome() {
        var toggle = qs('[data-mmf-toggle-sidebar]');
        var closeSidebar = qs('[data-mmf-close-sidebar]');
        var closePreview = qs('[data-mmf-close-preview]');
        var backdrop = qs('[data-mmf-chrome-backdrop]');

        if (toggle) {
            toggle.addEventListener('click', function () {
                if (!isCompactLayout()) return;
                var wrap = chromeWrap();
                var willOpen = !(wrap && wrap.classList.contains('is-sidebar-open'));
                if (willOpen) closePreviewDrawer();
                setSidebarDrawer(willOpen);
            });
        }
        if (closeSidebar) {
            closeSidebar.addEventListener('click', function () {
                closeSidebarDrawer();
            });
        }
        if (closePreview) {
            closePreview.addEventListener('click', function () {
                closePreviewDrawer();
            });
        }
        if (backdrop) {
            backdrop.addEventListener('click', function () {
                closeChromeDrawers();
            });
        }
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' || !isCompactLayout()) return;
            var wrap = chromeWrap();
            if (!wrap) return;
            if (wrap.classList.contains('is-sidebar-open') || wrap.classList.contains('is-preview-open')) {
                closeChromeDrawers();
            }
        });
        var onViewportChange = function () {
            // Crossing the responsive breakpoint must not turn a desktop
            // selection into an unsolicited modal drawer. A compact preview is
            // opened only by a selection interaction performed in compact mode.
            closeChromeDrawers();
        };
        if (COMPACT_MQ) {
            if (typeof COMPACT_MQ.addEventListener === 'function') {
                COMPACT_MQ.addEventListener('change', onViewportChange);
            } else if (typeof COMPACT_MQ.addListener === 'function') {
                COMPACT_MQ.addListener(onViewportChange);
            }
        }
        syncChromeBackdrop();
    }

    function openAssetDetails(hash) {
        var file = FILES[hash] || TREE[hash];
        var overlay = qs('.mmf-details-overlay');
        if (!file || file.mime === 'directory' || !overlay) return;
        DETAILS_RETURN_FOCUS = document.activeElement;
        var title = qs('.mmf-details-title', overlay);
        var list = qs('[data-mmf-details-list]', overlay);
        var visual = qs('.mmf-details-visual', overlay);
        var image = qs('.mmf-details-image', overlay);
        var edit = qs('.mmf-details-edit', overlay);
        if (title) title.textContent = file.name || t('fileDetails');
        renderMetadataList(list, file, true);
        var imageUrl = isImageMime(file.mime) ? (getThumbnailUrl(file) || getFileResourceUrl(file.hash)) : '';
        if (visual) visual.hidden = !imageUrl;
        if (image) {
            image.onload = null;
            if (imageUrl) {
                image.src = imageUrl;
            } else {
                image.removeAttribute('src');
            }
            image.alt = file.default_alt || file.display_name || file.name || '';
            if (imageUrl) {
                image.onload = function() {
                    updateRenderedDimensions(file, image.naturalWidth, image.naturalHeight);
                };
            }
        }
        if (edit) edit.style.display = file.mime !== 'directory' && file.asset_id ? '' : 'none';
        overlay.classList.add('visible');
        overlay.setAttribute('aria-hidden', 'false');
        var close = qs('.mmf-details-close', overlay);
        if (close) close.focus({preventScroll: true});
    }

    function closeAssetDetails(restoreFocus) {
        var overlay = qs('.mmf-details-overlay');
        if (!overlay) return;
        overlay.classList.remove('visible');
        overlay.setAttribute('aria-hidden', 'true');
        var image = qs('.mmf-details-image', overlay);
        if (image) {
            image.onload = null;
            image.removeAttribute('src');
        }
        if (restoreFocus && DETAILS_RETURN_FOCUS && document.contains(DETAILS_RETURN_FOCUS)) {
            DETAILS_RETURN_FOCUS.focus({preventScroll: true});
        }
        DETAILS_RETURN_FOCUS = null;
    }

    function bindDetailsDialog() {
        var overlay = qs('.mmf-details-overlay');
        if (!overlay || overlay.dataset.bound === '1') return;
        overlay.dataset.bound = '1';
        var close = qs('.mmf-details-close', overlay);
        var done = qs('.mmf-details-done', overlay);
        var edit = qs('.mmf-details-edit', overlay);
        if (close) close.addEventListener('click', function() { closeAssetDetails(true); });
        if (done) done.addEventListener('click', function() { closeAssetDetails(true); });
        if (edit) edit.addEventListener('click', function() {
            closeAssetDetails(false);
            editSelectedAssetMetadata();
        });
        overlay.addEventListener('pointerdown', function(event) {
            if (event.target === overlay) closeAssetDetails(true);
        });
        document.addEventListener('keydown', function(event) {
            if (!overlay.classList.contains('visible')) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                closeAssetDetails(true);
                return;
            }
            if (event.key === 'Tab') {
                var focusable = Array.prototype.slice.call(overlay.querySelectorAll(
                    'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
                )).filter(function(element) {
                    return !element.hidden && element.offsetParent !== null;
                });
                if (!focusable.length) {
                    event.preventDefault();
                    return;
                }
                var first = focusable[0];
                var last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
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
            setExternalDropState(false);
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

    }

    /* ─── upload ──────────────────────────────────────────────────────── */

    function dataTransferHasType(dataTransfer, type) {
        var types = dataTransfer && dataTransfer.types;
        if (!types) return false;
        for (var i = 0; i < types.length; i++) {
            if (String(types[i]).toLowerCase() === type.toLowerCase()) return true;
        }
        return false;
    }

    function isInternalDrag(dataTransfer) {
        if (!INTERNAL_DRAG_TARGETS.length) return false;
        if (dataTransferHasType(dataTransfer, INTERNAL_DRAG_MIME)) return true;
        return !dataTransferHasType(dataTransfer, 'Files');
    }

    function isExternalFileDrag(dataTransfer) {
        return dataTransferHasType(dataTransfer, 'Files') && !isInternalDrag(dataTransfer);
    }

    function setExternalDropState(visible) {
        var drop = qs('.mmf-upload-drop');
        if (!drop) return;
        drop.classList.toggle('visible', visible);
        drop.classList.toggle('dragover', visible);
        drop.setAttribute('aria-hidden', visible ? 'false' : 'true');
    }

    function bindDragDrop() {
        var content = qs('.mmf-content');
        if (!content) return;

        content.addEventListener('dragenter', function (e) {
            if (!isExternalFileDrag(e.dataTransfer)) return;
            e.preventDefault();
            EXTERNAL_DRAG_DEPTH += 1;
            if (EXTERNAL_DRAG_DEPTH === 1) {
                setExternalDropState(true);
                announceInteraction(t('dropUploadHint'));
            }
        });
        content.addEventListener('dragover', function (e) {
            if (!isExternalFileDrag(e.dataTransfer)) return;
            e.preventDefault();
            if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
            setExternalDropState(true);
        });
        content.addEventListener('dragleave', function (e) {
            if (!isExternalFileDrag(e.dataTransfer)) return;
            EXTERNAL_DRAG_DEPTH = Math.max(0, EXTERNAL_DRAG_DEPTH - 1);
            if (EXTERNAL_DRAG_DEPTH === 0) setExternalDropState(false);
        });
        content.addEventListener('drop', function (e) {
            EXTERNAL_DRAG_DEPTH = 0;
            setExternalDropState(false);
            if (isInternalDrag(e.dataTransfer)) {
                e.preventDefault();
                clearInternalDragState();
                showError(t('moveSameFolder'));
                return;
            }
            if (!isExternalFileDrag(e.dataTransfer)) return;
            e.preventDefault();
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                uploadFiles(e.dataTransfer.files, 'drop');
            }
        });
    }

    function bindClipboardPaste() {
        if (CLIPBOARD_BOUND) return;
        CLIPBOARD_BOUND = true;
        document.addEventListener('paste', function (e) {
            var target = e.target;
            if (
                target
                && target.nodeType === 1
                && (target.matches('input, textarea, select') || target.isContentEditable)
            ) {
                return;
            }

            var clipboard = e.clipboardData;
            if (!clipboard) return;
            var files = [];
            if (clipboard.items && clipboard.items.length) {
                for (var i = 0; i < clipboard.items.length; i++) {
                    if (clipboard.items[i].kind !== 'file') continue;
                    var file = clipboard.items[i].getAsFile();
                    if (file) files.push(file);
                }
            }
            if (!files.length && clipboard.files && clipboard.files.length) {
                files = Array.prototype.slice.call(clipboard.files);
            }
            if (!files.length) return;

            e.preventDefault();
            uploadFiles(files, 'paste');
        });
    }

    function bindInternalDragSource(el) {
        if (!el || el.dataset.mmfDragBound === '1') return;
        el.dataset.mmfDragBound = '1';
        el.addEventListener('dragstart', function (e) {
            var hash = el.dataset.hash;
            var file = FILES[hash];
            if (!file || file.mime === 'directory' || !e.dataTransfer) {
                e.preventDefault();
                return;
            }
            if (SELECTED.indexOf(hash) < 0) {
                SELECTED = [hash];
                highlightSelected();
            }
            INTERNAL_DRAG_TARGETS = SELECTED.filter(function (selectedHash, index, all) {
                var selectedFile = FILES[selectedHash];
                return selectedFile
                    && selectedFile.mime !== 'directory'
                    && selectedFile.phash === CWD_HASH
                    && all.indexOf(selectedHash) === index;
            });
            if (!INTERNAL_DRAG_TARGETS.length) {
                e.preventDefault();
                return;
            }
            e.dataTransfer.effectAllowed = 'move';
            try {
                e.dataTransfer.setData(INTERNAL_DRAG_MIME, JSON.stringify(INTERNAL_DRAG_TARGETS));
            } catch (e) {
                // INTERNAL_DRAG_TARGETS remains the same-document fallback.
            }
            el.classList.add('dragging');
        });
        el.addEventListener('dragend', function () {
            clearInternalDragState();
        });
    }

    function readInternalDragTargets(dataTransfer) {
        var activeTargets = INTERNAL_DRAG_TARGETS.slice();
        var raw = '';
        try {
            raw = dataTransfer ? dataTransfer.getData(INTERNAL_DRAG_MIME) : '';
        } catch (e) {}
        if (raw) {
            try {
                var parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) {
                    return parsed.filter(function (hash, index, all) {
                        return typeof hash === 'string'
                            && activeTargets.indexOf(hash) >= 0
                            && all.indexOf(hash) === index;
                    });
                }
            } catch (e) {}
        }
        return activeTargets;
    }

    function bindDirectoryDropTarget(el) {
        if (!el || el.dataset.mmfDropBound === '1') return;
        el.dataset.mmfDropBound = '1';
        el.addEventListener('dragenter', function (e) {
            if (isExternalFileDrag(e.dataTransfer)) {
                e.preventDefault();
                e.stopPropagation();
                EXTERNAL_DRAG_DEPTH = 0;
                setExternalDropState(false);
                if (!el.classList.contains('external-dragover')) {
                    var externalTarget = FILES[el.dataset.hash] || TREE[el.dataset.hash];
                    announceInteraction(t('dropUploadFolderHint', {
                        name: externalTarget && externalTarget.name ? externalTarget.name : ''
                    }));
                }
                el.classList.add('external-dragover');
                return;
            }
            if (!isInternalDrag(e.dataTransfer)) return;
            e.preventDefault();
            e.stopPropagation();
            if (el.dataset.hash !== CWD_HASH) {
                if (!el.classList.contains('internal-dragover')) {
                    var internalTarget = FILES[el.dataset.hash] || TREE[el.dataset.hash];
                    announceInteraction(t('moveFolderHint', {
                        name: internalTarget && internalTarget.name ? internalTarget.name : ''
                    }));
                }
                el.classList.add('internal-dragover');
            }
        });
        el.addEventListener('dragover', function (e) {
            if (isExternalFileDrag(e.dataTransfer)) {
                e.preventDefault();
                e.stopPropagation();
                EXTERNAL_DRAG_DEPTH = 0;
                setExternalDropState(false);
                if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
                el.classList.add('external-dragover');
                return;
            }
            if (!isInternalDrag(e.dataTransfer)) return;
            e.preventDefault();
            e.stopPropagation();
            if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
            if (el.dataset.hash !== CWD_HASH) el.classList.add('internal-dragover');
        });
        el.addEventListener('dragleave', function (e) {
            if (isExternalFileDrag(e.dataTransfer)) {
                e.stopPropagation();
                if (!e.relatedTarget || !el.contains(e.relatedTarget)) {
                    el.classList.remove('external-dragover');
                }
                return;
            }
            if (!isInternalDrag(e.dataTransfer)) return;
            e.stopPropagation();
            if (!e.relatedTarget || !el.contains(e.relatedTarget)) {
                el.classList.remove('internal-dragover');
            }
        });
        el.addEventListener('drop', function (e) {
            if (isExternalFileDrag(e.dataTransfer)) {
                e.preventDefault();
                e.stopPropagation();
                var externalDestinationHash = el.dataset.hash;
                el.classList.remove('external-dragover');
                EXTERNAL_DRAG_DEPTH = 0;
                setExternalDropState(false);
                if (externalDestinationHash && e.dataTransfer && e.dataTransfer.files.length) {
                    uploadFiles(e.dataTransfer.files, 'drop', externalDestinationHash);
                }
                return;
            }
            if (!isInternalDrag(e.dataTransfer)) return;
            e.preventDefault();
            e.stopPropagation();
            var destinationHash = el.dataset.hash;
            var targets = readInternalDragTargets(e.dataTransfer);
            clearInternalDragState();
            if (!destinationHash || destinationHash === CWD_HASH) {
                showError(t('moveSameFolder'));
                return;
            }
            moveFilesToDirectory(targets, destinationHash);
        });
    }

    function clearInternalDragState() {
        INTERNAL_DRAG_TARGETS = [];
        qsa('.mmf-item.dragging, .internal-dragover, .external-dragover').forEach(function (el) {
            el.classList.remove('dragging', 'internal-dragover', 'external-dragover');
        });
    }

    function moveFilesToDirectory(targets, destinationHash) {
        if (INTERNAL_MOVE_PENDING) return;
        if (!hasCapability('move_file')) {
            showError(t('providerActionUnsupported'));
            return;
        }
        var destination = FILES[destinationHash] || TREE[destinationHash];
        if (!destination || destination.mime !== 'directory') {
            showError(t('moveTargetMissing'));
            return;
        }
        var eligible = (targets || []).filter(function (hash, index, all) {
            var file = FILES[hash];
            return file
                && file.mime !== 'directory'
                && file.phash !== destinationHash
                && all.indexOf(hash) === index;
        });
        if (!eligible.length) {
            showError(t('moveNoEligible'));
            return;
        }

        INTERNAL_MOVE_PENDING = true;
        announceInteraction(t('moveStarted', {count: eligible.length}));
        api({cmd: 'move', targets: eligible, target: destinationHash}, function () {
            INTERNAL_MOVE_PENDING = false;
            SELECTED = [];
            LAST_CLICKED_HASH = null;
            showSuccess(t('moveComplete', {count: eligible.length}));
            openDir(CWD_HASH);
        }, function (err) {
            INTERNAL_MOVE_PENDING = false;
            showError(err);
        });
    }

    function announceInteraction(message) {
        var status = qs('.mmf-interaction-status');
        if (!status || !message) return;
        status.textContent = '';
        window.setTimeout(function () {
            status.textContent = String(message);
        }, 0);
    }

    function uploadLimitBytes() {
        var configuredBytes = parseInt(CONFIG.size, 10);
        if (!Number.isFinite(configuredBytes) || configuredBytes < 1) {
            configuredBytes = API_MAX_ASSET_UPLOAD_BYTES;
        }
        return Math.min(API_MAX_ASSET_UPLOAD_BYTES, configuredBytes);
    }

    function findOversizedUploadFile(fileList) {
        var files = Array.prototype.slice.call(fileList || []);
        var limit = uploadLimitBytes();
        var total = 0;
        for (var i = 0; i < files.length; i++) {
            var bytes = Math.max(0, Number(files[i].size) || 0);
            total += bytes;
            if (bytes > limit || total > limit) {
                return files[i];
            }
        }
        return null;
    }

    function configuredUploadExtensions() {
        var raw = String(CONFIG.ext || '*').trim().toLowerCase();
        if (!raw || raw === '*') return SAFE_UPLOAD_EXTENSIONS.slice();
        return raw.split(',').map(function(extension) {
            return extension.trim().replace(/^\./, '');
        }).filter(function(extension) {
            return extension && SAFE_UPLOAD_EXTENSIONS.indexOf(extension) >= 0;
        });
    }

    function configuredSelectionExtensions() {
        var raw = String(CONFIG.ext || '*').trim().toLowerCase();
        if (!raw || raw === '*' || raw.split(',').some(function(extension) {
            return extension.trim() === '*';
        })) {
            return null;
        }
        return raw.split(',').map(function(extension) {
            return extension.trim().replace(/^\./, '');
        }).filter(Boolean);
    }

    function selectionMimeAllowed(mime) {
        if (!ALLOWED_MIMES.length) return true;
        mime = String(mime || '').toLowerCase();
        return ALLOWED_MIMES.some(function(allowedMime) {
            allowedMime = String(allowedMime || '').trim().toLowerCase();
            if (allowedMime === '*' || allowedMime === '*/*' || allowedMime === mime) return true;
            if (allowedMime === 'image') return mime.indexOf('image/') === 0;
            if (/^[a-z0-9.+-]+\/\*$/.test(allowedMime)) {
                return mime.indexOf(allowedMime.slice(0, -1)) === 0;
            }
            return false;
        });
    }

    function fileSelectionIssue(file) {
        if (!IFRAME_MODE || !file || file.mime === 'directory') return null;
        if (Number(file.size || 0) > uploadLimitBytes()) {
            return {
                kind: 'size',
                message: t('fileSizeExceeded', {
                    name: file.name || '',
                    size: humanSize(uploadLimitBytes())
                })
            };
        }
        var allowedExtensions = configuredSelectionExtensions();
        var name = String(file.name || '');
        var dot = name.lastIndexOf('.');
        var extension = dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
        if (allowedExtensions && allowedExtensions.indexOf(extension) < 0) {
            return {
                kind: 'ext',
                message: t('fileTypeNotAllowed', {
                    ext: extension || t('unknown'),
                    allowed: allowedExtensions.join(', ')
                })
            };
        }
        if (!selectionMimeAllowed(file.mime)) {
            return {
                kind: 'ext',
                message: t('fileTypeNotAllowed', {
                    ext: file.mime || t('unknown'),
                    allowed: ALLOWED_MIMES.join(', ')
                })
            };
        }
        return null;
    }

    function pickerSelectionIsEligible() {
        if (!IFRAME_MODE || !SELECTED.length) return false;
        return SELECTED.every(function(hash) {
            var file = FILES[hash];
            return !!file && file.mime !== 'directory' && !fileSelectionIssue(file);
        });
    }

    function findDisallowedUploadFile(fileList) {
        var allowed = configuredUploadExtensions();
        var files = Array.prototype.slice.call(fileList || []);
        for (var i = 0; i < files.length; i++) {
            var name = String(files[i].name || '');
            var dot = name.lastIndexOf('.');
            var extension = dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
            if (allowed.indexOf(extension) < 0) {
                return {file: files[i], extension: extension || t('unknown'), allowed: allowed};
            }
        }
        return null;
    }

    function inferredUploadExtension(mime) {
        var extensions = {
            'image/jpeg': 'jpg',
            'image/png': 'png',
            'image/gif': 'gif',
            'image/webp': 'webp',
            'image/bmp': 'bmp',
            'image/tiff': 'tiff',
            'image/avif': 'avif',
            'text/plain': 'txt',
            'text/csv': 'csv',
            'application/json': 'json',
            'application/pdf': 'pdf'
        };
        return extensions[String(mime || '').toLowerCase()] || '';
    }

    function normalizeIncomingFiles(fileList, source) {
        var files = Array.prototype.slice.call(fileList || []);
        var stamp = Date.now();
        return files.map(function(file, index) {
            var name = String(file && file.name || '').trim();
            if (/\.[A-Za-z0-9]{1,16}$/.test(name)) return file;
            var extension = inferredUploadExtension(file && file.type);
            if (!extension || typeof File !== 'function') return file;
            var prefix = source === 'paste' ? 'pasted' : 'upload';
            return new File(
                [file],
                prefix + '-' + stamp + '-' + (index + 1) + '.' + extension,
                {type: file.type || '', lastModified: file.lastModified || stamp}
            );
        });
    }

    function uploadMultipart(fileList, metadataList, targetHash) {
        var files = Array.prototype.slice.call(fileList || []);
        return new Promise(function(resolve, reject) {
            var endpoint;
            try {
                endpoint = new URL(CONNECTOR, document.baseURI);
                if (endpoint.origin !== window.location.origin) {
                    throw new Error(t('crossOriginUploadRejected'));
                }
            } catch (error) {
                reject(error);
                return;
            }
            if (!CONFIG.connectorFormKey) {
                reject(new Error(t('uploadSecurityTokenMissing')));
                return;
            }

            var body = new FormData();
            body.append('cmd', 'upload');
            body.append('target', targetHash);
            body.append('storage', CURRENT_STORAGE);
            body.append('ext', CONFIG.ext || '*');
            body.append('size', String(uploadLimitBytes()));
            body.append('locale_code', CONFIG.localeCode || 'zh_Hans_CN');
            body.append('upload_metadata', JSON.stringify(metadataList || []));
            body.append('form_key', String(CONFIG.connectorFormKey));
            files.forEach(function(file) {
                body.append('upload[]', file, String(file.name || 'upload.bin'));
            });

            var xhr = new XMLHttpRequest();
            var settled = false;
            var finish = function(error, data) {
                if (settled) return;
                settled = true;
                if (UPLOAD_XHR === xhr) UPLOAD_XHR = null;
                if (error) reject(error);
                else resolve(data);
            };
            UPLOAD_XHR = xhr;
            xhr.open('POST', endpoint.href, true);
            xhr.withCredentials = true;
            xhr.timeout = 5 * 60 * 1000;
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.upload.onprogress = function(event) {
                if (event.lengthComputable && event.total > 0) {
                    updateUploadProgress(Math.min(90, Math.round((event.loaded / event.total) * 90)));
                }
            };
            xhr.onload = function() {
                if (xhr.status === 413) {
                    finish(new Error(t('uploadRequestTooLarge')));
                    return;
                }
                var response;
                try {
                    response = JSON.parse(String(xhr.responseText || ''));
                } catch (_error) {
                    finish(new Error(t('invalidJson')));
                    return;
                }
                if (xhr.status < 200 || xhr.status >= 300 || (response && response.error)) {
                    var message = response && response.error;
                    if (Array.isArray(message)) message = message.join(', ');
                    finish(new Error(String(message || t('networkError'))));
                    return;
                }
                if (!response || !Array.isArray(response.added) || response.added.length !== files.length) {
                    finish(new Error(t('uploadResponseMismatch')));
                    return;
                }
                finish(null, response);
            };
            xhr.onerror = function() { finish(new Error(t('networkError'))); };
            xhr.ontimeout = function() { finish(new Error(t('requestTimeout'))); };
            xhr.onabort = function() { finish(new Error(t('uploadCancelled'))); };
            xhr.send(body);
        });
    }

    function uploadProtocolError(message, retryable) {
        var error = new Error(String(message || t('networkError')));
        error.retryable = !!retryable;
        return error;
    }

    function connectorUploadEndpoint() {
        var endpoint = new URL(CONNECTOR, document.baseURI);
        if (endpoint.origin !== window.location.origin) {
            throw uploadProtocolError(t('crossOriginUploadRejected'), false);
        }
        if (!CONFIG.connectorFormKey) {
            throw uploadProtocolError(t('uploadSecurityTokenMissing'), false);
        }
        return endpoint.href;
    }

    function connectorUploadRequest(command, fields, filePart, onProgress, timeoutMs) {
        return new Promise(function(resolve, reject) {
            var endpoint;
            try {
                endpoint = connectorUploadEndpoint();
            } catch (error) {
                reject(error);
                return;
            }

            var body = new FormData();
            body.append('cmd', command);
            body.append('form_key', String(CONFIG.connectorFormKey));
            Object.keys(fields || {}).forEach(function(key) {
                var value = fields[key];
                if (value !== undefined && value !== null) {
                    body.append(key, String(value));
                }
            });
            if (filePart && filePart.blob) {
                body.append(
                    String(filePart.field || 'chunk'),
                    filePart.blob,
                    String(filePart.name || 'chunk.bin')
                );
            }

            var xhr = new XMLHttpRequest();
            var settled = false;
            var finish = function(error, data) {
                if (settled) return;
                settled = true;
                if (UPLOAD_XHR === xhr) UPLOAD_XHR = null;
                if (error) reject(error);
                else resolve(data);
            };
            UPLOAD_XHR = xhr;
            xhr.open('POST', endpoint, true);
            xhr.withCredentials = true;
            xhr.timeout = Math.max(1000, Number(timeoutMs) || (5 * 60 * 1000));
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            if (typeof onProgress === 'function') {
                xhr.upload.onprogress = function(event) {
                    if (event.lengthComputable && event.total > 0) {
                        onProgress(event.loaded, event.total);
                    }
                };
            }
            xhr.onload = function() {
                if (xhr.status === 413) {
                    finish(uploadProtocolError(t('uploadRequestTooLarge'), false));
                    return;
                }
                var response;
                try {
                    response = JSON.parse(String(xhr.responseText || ''));
                } catch (_error) {
                    finish(uploadProtocolError(t('invalidJson'), true));
                    return;
                }
                if (xhr.status < 200 || xhr.status >= 300 || (response && response.error)) {
                    var message = response && response.error;
                    if (Array.isArray(message)) message = message.join(', ');
                    finish(uploadProtocolError(
                        String(message || t('networkError')),
                        xhr.status === 408 || xhr.status === 429 || xhr.status >= 500
                    ));
                    return;
                }
                finish(null, response);
            };
            xhr.onerror = function() {
                finish(uploadProtocolError(t('networkError'), true));
            };
            xhr.ontimeout = function() {
                finish(uploadProtocolError(t('requestTimeout'), true));
            };
            xhr.onabort = function() {
                finish(uploadProtocolError(t('uploadCancelled'), false));
            };
            xhr.send(body);
        });
    }

    function retryResumableRequest(factory, retries) {
        return factory().catch(function(error) {
            if (!error || !error.retryable || retries < 1) throw error;
            return retryResumableRequest(factory, retries - 1);
        });
    }

    function blobArrayBuffer(blob) {
        if (blob && typeof blob.arrayBuffer === 'function') {
            return blob.arrayBuffer();
        }
        return new Promise(function(resolve, reject) {
            if (typeof FileReader !== 'function') {
                reject(uploadProtocolError(t('uploadHashUnavailable'), false));
                return;
            }
            var reader = new FileReader();
            reader.onload = function() { resolve(reader.result); };
            reader.onerror = function() {
                reject(uploadProtocolError(t('fileReadFailed', {name: blob && blob.name || ''}), false));
            };
            reader.readAsArrayBuffer(blob);
        });
    }

    function sha256Blob(blob) {
        if (!window.crypto || !window.crypto.subtle || typeof window.crypto.subtle.digest !== 'function') {
            return Promise.reject(uploadProtocolError(t('uploadHashUnavailable'), false));
        }
        return blobArrayBuffer(blob).then(function(buffer) {
            return window.crypto.subtle.digest('SHA-256', buffer);
        }).then(function(digest) {
            return Array.prototype.map.call(new Uint8Array(digest), function(byte) {
                return byte.toString(16).padStart(2, '0');
            }).join('');
        });
    }

    function abortResumableSession(sessionId) {
        if (!sessionId) return Promise.resolve(null);
        return connectorUploadRequest('upload_session_abort', {
            session_id: sessionId,
            locale_code: CONFIG.localeCode || 'zh_Hans_CN'
        }, null, null, 30 * 1000).catch(function() {
            // The server expires abandoned data-only sessions independently.
            return null;
        });
    }

    function uploadFileResumable(file, metadata, targetHash, bytesBefore, totalBytes) {
        var sessionId = '';
        var fileBytes = Math.max(0, Number(file.size) || 0);
        var chunkBytes = UPLOAD_CHUNK_BYTES;

        return connectorUploadRequest('upload_session_start', {
            target: targetHash,
            storage: CURRENT_STORAGE,
            ext: CONFIG.ext || '*',
            size: uploadLimitBytes(),
            locale_code: CONFIG.localeCode || 'zh_Hans_CN',
            file_name: String(file.name || 'upload.bin'),
            file_size: fileBytes,
            metadata: JSON.stringify(metadata || {})
        }, null, null, 30 * 1000).then(function(response) {
            var state = response && response.upload_session;
            sessionId = String(state && state.session_id || '');
            chunkBytes = Number(state && state.chunk_bytes);
            if (!/^[a-f0-9]{32}$/.test(sessionId)
                || !Number.isSafeInteger(chunkBytes)
                || chunkBytes < 1
                || chunkBytes > UPLOAD_CHUNK_BYTES
                || Number(state.expected_size) !== fileBytes
                || Number(state.received_size) !== 0
            ) {
                throw uploadProtocolError(t('uploadSessionInvalid'), false);
            }

            var offset = 0;
            var sendNextChunk = function() {
                if (offset >= fileBytes) return Promise.resolve(true);
                var end = Math.min(fileBytes, offset + chunkBytes);
                var part = file.slice(offset, end);
                var plannedOffset = offset;
                return sha256Blob(part).then(function(sha256) {
                    return retryResumableRequest(function() {
                        return connectorUploadRequest('upload_session_chunk', {
                            session_id: sessionId,
                            offset: plannedOffset,
                            chunk_sha256: sha256,
                            locale_code: CONFIG.localeCode || 'zh_Hans_CN'
                        }, {
                            field: 'chunk',
                            blob: part,
                            name: 'chunk.bin'
                        }, function(loaded) {
                            var uploaded = bytesBefore + plannedOffset + Math.min(part.size, loaded);
                            var progress = totalBytes > 0 ? Math.round((uploaded / totalBytes) * 98) : 98;
                            updateUploadProgress(Math.max(0, Math.min(98, progress)));
                        }, 5 * 60 * 1000);
                    }, 2);
                }).then(function(response) {
                    var state = response && response.upload_session;
                    if (!state
                        || String(state.session_id || '') !== sessionId
                        || Number(state.expected_size) !== fileBytes
                        || Number(state.received_size) !== end
                    ) {
                        throw uploadProtocolError(t('uploadSessionProgressMismatch'), false);
                    }
                    offset = end;
                    return sendNextChunk();
                });
            };

            return sendNextChunk().then(function() {
                return retryResumableRequest(function() {
                    return connectorUploadRequest('upload_session_complete', {
                        session_id: sessionId,
                        locale_code: CONFIG.localeCode || 'zh_Hans_CN'
                    }, null, null, 10 * 60 * 1000);
                }, 2);
            });
        }).then(function(response) {
            if (!response || !Array.isArray(response.added) || response.added.length !== 1
                || !response.added[0] || !response.added[0].asset_id
            ) {
                throw uploadProtocolError(t('uploadResponseMismatch'), false);
            }
            var completed = bytesBefore + fileBytes;
            var progress = totalBytes > 0 ? Math.round((completed / totalBytes) * 98) : 98;
            updateUploadProgress(Math.max(0, Math.min(98, progress)));
            return response.added[0];
        }).catch(function(error) {
            if (!sessionId) throw error;
            return abortResumableSession(sessionId).then(function() {
                throw error;
            });
        });
    }

    function uploadResumable(fileList, metadataList, targetHash) {
        var files = Array.prototype.slice.call(fileList || []);
        var totalBytes = files.reduce(function(total, file) {
            return total + Math.max(0, Number(file.size) || 0);
        }, 0);
        var results = [];
        var chain = Promise.resolve(true);
        var bytesBefore = 0;
        files.forEach(function(file, index) {
            var startAt = bytesBefore;
            bytesBefore += Math.max(0, Number(file.size) || 0);
            chain = chain.then(function() {
                return uploadFileResumable(
                    file,
                    metadataList[index] || {},
                    targetHash,
                    startAt,
                    totalBytes
                );
            }).then(function(asset) {
                results.push(asset);
            });
        });
        return chain.then(function() { return {added: results}; });
    }

    function canUseSingleMultipartRequest(fileList) {
        var files = Array.prototype.slice.call(fileList || []);
        var total = 0;
        for (var i = 0; i < files.length; i++) {
            var bytes = Math.max(0, Number(files[i].size) || 0);
            total += bytes;
            if (bytes > API_MAX_UPLOAD_FILE_BYTES || total > API_MAX_UPLOAD_FILE_BYTES) {
                return false;
            }
        }
        return true;
    }

    function requestUploadMetadata(fileList) {
        var ui = window.Weline && window.Weline.UI;
        if (!ui || !ui.dialog || typeof ui.dialog.prompt !== 'function') {
            return Promise.reject(new Error(t('uploadMetadataRequired')));
        }
        var files = Array.prototype.slice.call(fileList || []);
        var metadata = [];
        var chain = Promise.resolve(true);
        files.forEach(function(file) {
            chain = chain.then(function(continueUpload) {
                if (!continueUpload) return false;
                var name = String(file.name || '');
                var displayName = name.replace(/\.[^.]+$/, '') || name;
                return ui.dialog.prompt(t('uploadAltPromptForFile', {name: name}), {
                    title: t('uploadAltLabel') + ' · ' + name,
                    confirmLabel: t('continue'),
                    field: {type: 'text', required: true, value: displayName}
                }).then(function(altResult) {
                    if (!altResult || !altResult.confirmed) return false;
                    return ui.dialog.prompt(t('uploadDescriptionPromptForFile', {name: name}), {
                        title: t('uploadDescriptionLabel') + ' · ' + name,
                        confirmLabel: t('confirm'),
                        field: {type: 'textarea', required: true}
                    }).then(function(descriptionResult) {
                        if (!descriptionResult || !descriptionResult.confirmed) return false;
                        metadata.push({
                            display_name: displayName,
                            default_alt: String(altResult.value || '').trim(),
                            description: String(descriptionResult.value || '').trim(),
                            default_caption: ''
                        });
                        return true;
                    });
                });
            });
        });
        return chain.then(function(confirmed) { return confirmed ? metadata : null; });
    }

    function uploadFiles(fileList, source, targetHash) {
        if (!hasCapability('upload')) {
            showError(t('providerActionUnsupported'));
            return;
        }
        if (!CONNECTOR) {
            showError(t('connectorNotConfigured'));
            return;
        }
        if (!fileList || fileList.length === 0) {
            showError(t('noFiles'));
            return;
        }
        targetHash = String(targetHash || CWD_HASH || '');
        if (!targetHash) {
            showError(t('uploadWaitDir'));
            return;
        }
        if (UPLOAD_PENDING) {
            showError(t('uploadInProgress'));
            return;
        }
        var files = normalizeIncomingFiles(fileList, source);
        if (files.length > API_MAX_UPLOAD_FILES) {
            showError(t('fileCountExceeded', {count: API_MAX_UPLOAD_FILES}));
            return;
        }
        var oversized = findOversizedUploadFile(files);
        if (oversized) {
            showError(t('fileSizeExceeded', {name: oversized.name || '', size: humanSize(uploadLimitBytes())}));
            return;
        }
        var disallowed = findDisallowedUploadFile(files);
        if (disallowed) {
            showError(t('fileTypeNotAllowed', {
                ext: disallowed.extension,
                allowed: disallowed.allowed.join(', ')
            }));
            return;
        }

        UPLOAD_PENDING = true;
        requestUploadMetadata(files).then(function(metadataList) {
            if (!metadataList) return null;
            announceInteraction(t(source === 'paste' ? 'pasteUploadStarted' : 'uploadStarted', {
                count: files.length
            }));
            showUploadProgress(true);
            updateUploadProgress(0);
            var upload = canUseSingleMultipartRequest(files)
                ? uploadMultipart(files, metadataList, targetHash)
                : uploadResumable(files, metadataList, targetHash);
            return upload.then(function() {
                updateUploadProgress(100);
                showSuccess(t('uploadComplete'));
                openDir(CWD_HASH);
            });
        }).catch(function(error) {
            showError((error && error.message) || t('uploadMetadataRequired'));
        }).finally(function() {
            UPLOAD_PENDING = false;
            window.setTimeout(function() {
                showUploadProgress(false);
            }, 200);
        });
    }

    function showUploadProgress(visible) {
        var el = qs('.mmf-upload-progress');
        if (el) {
            el.classList.toggle('visible', visible);
            el.setAttribute('aria-hidden', visible ? 'false' : 'true');
        }
    }

    function updateUploadProgress(pct) {
        var bar = qs('.mmf-progress-bar');
        var txt = qs('.mmf-progress-text');
        var progress = qs('.mmf-upload-progress');
        if (bar) bar.style.width = pct + '%';
        if (txt) txt.textContent = pct + '%';
        if (progress) progress.setAttribute('aria-valuenow', String(pct));
    }

    /* ─── new folder (cmd=mkdir) ──────────────────────────────────────── */

    function promptNewFolder() {
        if (!hasCapability('create_directory')) {
            showError(t('providerActionUnsupported'));
            return;
        }
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
        var f = FILES[SELECTED[0]] || TREE[SELECTED[0]];
        if (!f) return;
        if (!itemCapability('rename', f)) {
            showError(t('providerActionUnsupported'));
            return;
        }
        var oldHash = f.hash;
        var isDir = f.mime === 'directory';
        var affectsCurrent = directoryContainsCurrent(f);
        showDialog(t('rename'), t('newName'), f.name, function (name) {
            if (!name || name === f.name) return;
            api({ cmd: 'rename', target: oldHash, name: name }, function (data) {
                showSuccess(t('renamed'));
                if (isDir) {
                    delete TREE[oldHash];
                    if (data && data.added && data.added.length) {
                        data.added.forEach(function (newFile) {
                            TREE[newFile.hash] = newFile;
                            FILES[newFile.hash] = newFile;
                        });
                    }
                }
                var nextHash = affectsCurrent && data && data.added && data.added[0]
                    ? data.added[0].hash
                    : CWD_HASH;
                openDir(nextHash);
            });
        });
    }

    /* ─── delete (cmd=rm) ────────────────────────────────────────────── */

    function deleteSelected() {
        if (!SELECTED.length) { showError(t('noItemsSelected')); return; }
        var permitted = SELECTED.every(function(hash) {
            return itemCapability('delete', FILES[hash] || TREE[hash]);
        });
        if (!permitted) {
            showError(t('providerActionUnsupported'));
            return;
        }
        var toDelete = SELECTED.slice();
        var selectedFile = SELECTED.length === 1 ? (FILES[SELECTED[0]] || TREE[SELECTED[0]]) : null;
        var nextHash = CWD_HASH;
        toDelete.forEach(function (hash) {
            var file = FILES[hash] || TREE[hash];
            if (directoryContainsCurrent(file)) {
                nextHash = file.phash || '';
            }
        });
        var message = selectedFile && selectedFile.mime === 'directory'
            ? t('confirmDeleteDirectory', {name: selectedFile.name || ''})
            : t('confirmDelete', {count: SELECTED.length});
        showConfirm(message, function () {
            api({ cmd: 'rm', targets: toDelete }, function () {
                showSuccess(t('deleted'));
                toDelete.forEach(function (hash) {
                    delete TREE[hash];
                    delete FILES[hash];
                });
                SELECTED = [];
                openDir(nextHash);
            });
        });
    }

    function directoryContainsCurrent(file) {
        if (!file || file.mime !== 'directory') return false;
        var directoryPath = String(file.path || '').replace(/^\/+|\/+$/g, '');
        var currentPath = String((CWD_INFO && CWD_INFO.path) || '').replace(/^\/+|\/+$/g, '');
        return directoryPath !== ''
            && (currentPath === directoryPath || currentPath.indexOf(directoryPath + '/') === 0);
    }

    /* ─── download (cmd=file) ────────────────────────────────────────── */

    function downloadFile(hash) {
        var f = FILES[hash];
        if (!f || f.mime === 'directory') return;
        if (!hasCapability('download')) {
            showError(t('providerActionUnsupported'));
            return;
        }
        var url = getConnectorResourceUrl('file', hash, {download: '1'});
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
        // Prefer the storage adapter's resolved public/temporary URL. The
        // connector URL is an authenticated backend redirect and is only a
        // compatibility fallback for legacy local objects.
        var url = String(f.preview_url || getFileResourceUrl(hash) || '');
        copyTextToClipboard(url, function () { showSuccess(t('urlCopied')); }, function () {});
    }

    /* ─── context menu ───────────────────────────────────────────────── */

    function contextMenuRuntime() {
        var root = qs('[data-mmf-context-menu-root]');
        if (!root || !window.Weline || !window.Weline.UI) return null;
        window.Weline.UI.mount(root);
        return window.Weline.UI.get(root, 'menu');
    }

    function resetContextMenuAnchor() {
        var trigger = qs('[data-mmf-context-menu-root] [data-w-menu-trigger]');
        if (!trigger) return;
        trigger.style.removeProperty('left');
        trigger.style.removeProperty('top');
    }

    function restoreContextMenuFocus() {
        if (CONTEXT_MENU_RETURN_FOCUS && document.contains(CONTEXT_MENU_RETURN_FOCUS)) {
            CONTEXT_MENU_RETURN_FOCUS.focus({preventScroll: true});
        }
    }

    function bindContextMenu() {
        if (CONTEXT_MENU_BOUND) return;
        CONTEXT_MENU_BOUND = true;
        var root = qs('[data-mmf-context-menu-root]');
        if (!root) return;
        if (window.Weline && window.Weline.UI) {
            window.Weline.UI.mount(root);
        } else {
            document.addEventListener('weline:ui:ready', function () {
                if (window.Weline && window.Weline.UI) window.Weline.UI.mount(root);
            }, {once: true});
        }
        root.addEventListener('weline:ui:menu:close', function (e) {
            if (e.target !== root) return;
            var reason = e.detail && e.detail.reason;
            resetContextMenuAnchor();
            if (reason === 'escape' || reason === 'tab') restoreContextMenuFocus();
            CONTEXT_MENU_RETURN_FOCUS = null;
        });
        document.addEventListener('contextmenu', function (e) {
            if (!e.target.closest('.mmf-item') && !e.target.closest('.mmf-context-menu')) {
                hideContextMenu(false);
            }
        });
    }

    function showContextMenu(x, y, anchor, returnFocus) {
        hideContextMenu(false);
        var root = qs('[data-mmf-context-menu-root]');
        var menu = qs('.mmf-context-menu');
        var trigger = root && qs('[data-w-menu-trigger]', root);
        if (!root || !menu || !trigger) return;
        var f = SELECTED.length === 1 ? (FILES[SELECTED[0]] || TREE[SELECTED[0]]) : null;
        var isDir = f && f.mime === 'directory';
        var isImage = f && isImageMime(f.mime);
        var anchorRect = anchor && anchor.getBoundingClientRect ? anchor.getBoundingClientRect() : null;
        var pointX = Number.isFinite(x) ? x : (anchorRect ? anchorRect.left : 8);
        var pointY = Number.isFinite(y) ? y : (anchorRect ? anchorRect.bottom : 8);
        var viewport = window.visualViewport;
        var viewportLeft = viewport ? viewport.offsetLeft : 0;
        var viewportTop = viewport ? viewport.offsetTop : 0;
        var viewportRight = viewportLeft + (viewport ? viewport.width : document.documentElement.clientWidth);
        var viewportBottom = viewportTop + (viewport ? viewport.height : document.documentElement.clientHeight);
        pointX = Math.max(viewportLeft + 8, Math.min(pointX, viewportRight - 9));
        pointY = Math.max(viewportTop + 8, Math.min(pointY, viewportBottom - 9));
        trigger.style.left = Math.round(pointX) + 'px';
        trigger.style.top = Math.round(pointY) + 'px';
        CONTEXT_MENU_RETURN_FOCUS = returnFocus || anchor || null;
        menu.replaceChildren();
        
        if (SELECTION_MODE) {
            addContextItem(menu, 'exit-selection', t('exitSelectionMode'), 'active');
            if (SELECTED.length > 0) {
                addContextItem(menu, 'clear-selection', t('clearSelection') + ' (' + SELECTED.length + ')');
            }
            if (IFRAME_MODE && pickerSelectionIsEligible()) {
                addContextItem(menu, 'confirm-selection', t('confirmSelection') + ' (' + SELECTED.length + ')', 'primary');
            }
            addContextSeparator(menu);
        } else {
            addContextItem(menu, 'enter-selection', t('selectionMode'));
            addContextSeparator(menu);
        }
        
        if (IFRAME_MODE && pickerSelectionIsEligible() && !SELECTION_MODE) {
            addContextItem(menu, 'confirm-selection', t('selectFiles'), 'primary');
            addContextSeparator(menu);
        }
        
        if (f && isImage && !IFRAME_MODE && hasCapability('ai_edit')) {
            addContextItem(menu, 'ai-edit', t('aiEdit'));
        }
        if (f && !isDir) {
            addContextItem(menu, 'view-details', t('viewDetails'));
        }
        if (f && isImage && hasCapability('preview')) {
            addContextItem(menu, 'preview', t('preview'));
        }
        if (f && !isDir && hasCapability('download')) {
            addContextItem(menu, 'download', t('download'));
        }
        if (f && !isDir && hasCapability('copy_url')) {
            addContextItem(menu, 'copy-url', t('copyUrl'));
        }
        if (f && !isDir && f.asset_id) {
            addContextItem(menu, 'edit-asset-metadata', t('editAssetMetadata'));
        }
        if (f && isDir && hasCapability('browse')) {
            addContextItem(menu, 'open', t('open'));
        }
        if (SELECTED.length === 1 && itemCapability('rename', f)) {
            addContextItem(menu, 'rename', t('rename'));
        }
        if (SELECTED.length && SELECTED.every(function(hash) {
            return itemCapability('delete', FILES[hash]);
        })) {
            addContextSeparator(menu);
            addContextItem(menu, 'delete', t('delete'), 'danger');
        }

        var runtime = contextMenuRuntime();
        if (!runtime) {
            resetContextMenuAnchor();
            CONTEXT_MENU_RETURN_FOCUS = null;
            return;
        }
        runtime.open(true);
    }

    function addContextItem(menu, action, label, variant) {
        var item = document.createElement('button');
        item.type = 'button';
        item.className = 'w-menu__item mmf-context-item';
        if (variant === 'active') item.dataset.state = 'active';
        if (variant === 'primary' || variant === 'danger') item.dataset.tone = variant;
        item.dataset.action = action;
        item.setAttribute('role', 'menuitem');
        item.textContent = label;
        item.addEventListener('click', function(e) {
            e.stopPropagation();
            var selectedAction = item.dataset.action;
            hideContextMenu(false);
            runContextAction(selectedAction);
        });
        menu.appendChild(item);
    }

    function addContextSeparator(menu) {
        var last = menu.lastElementChild;
        if (!last || last.classList.contains('mmf-context-sep')) return;
        var separator = document.createElement('div');
        separator.className = 'w-menu__divider mmf-context-sep';
        separator.setAttribute('role', 'separator');
        menu.appendChild(separator);
    }

    function runContextAction(action) {
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
        else if (action === 'view-details' && SELECTED.length === 1) openAssetDetails(SELECTED[0]);
        else if (action === 'edit-asset-metadata' && SELECTED.length === 1) editSelectedAssetMetadata();
        else if (action === 'open' && SELECTED.length === 1) openDir(SELECTED[0]);
        else if (action === 'rename') renameSelected();
        else if (action === 'delete') deleteSelected();
    }

    function editSelectedAssetMetadata() {
        var file = SELECTED.length === 1 ? FILES[SELECTED[0]] : null;
        var ui = window.Weline && window.Weline.UI;
        if (!file || file.mime === 'directory' || !file.asset_id) {
            showError(t('assetMetadataMissing'));
            return;
        }
        if (!ui || !ui.dialog || typeof ui.dialog.prompt !== 'function') {
            showError(t('assetMetadataEditorUnavailable'));
            return;
        }
        ui.dialog.prompt(t('assetDisplayNamePrompt'), {
            title: t('assetDisplayNameLabel'),
            confirmLabel: t('continue'),
            field: {type: 'text', required: true, value: String(file.display_name || file.name || '').trim()}
        }).then(function(nameResult) {
            if (!nameResult || !nameResult.confirmed) return null;
            return ui.dialog.prompt(t('assetDefaultAltPrompt'), {
                title: t('assetDefaultAltLabel'),
                confirmLabel: t('continue'),
                field: {type: 'text', required: true, value: String(file.default_alt || '').trim()}
            }).then(function(altResult) {
                if (!altResult || !altResult.confirmed) return null;
                return ui.dialog.prompt(t('assetDescriptionPrompt'), {
                    title: t('assetDescriptionLabel'),
                    confirmLabel: t('continue'),
                    field: {type: 'textarea', required: true, value: String(file.description || '').trim()}
                }).then(function(descriptionResult) {
                    if (!descriptionResult || !descriptionResult.confirmed) return null;
                    return ui.dialog.prompt(t('assetDefaultCaptionPrompt'), {
                        title: t('assetDefaultCaptionLabel'),
                        confirmLabel: t('save'),
                        field: {type: 'textarea', required: false, value: String(file.default_caption || '').trim()}
                    }).then(function(captionResult) {
                        if (!captionResult || !captionResult.confirmed) return null;
                        return {
                            display_name: String(nameResult.value || '').trim(),
                            default_alt: String(altResult.value || '').trim(),
                            description: String(descriptionResult.value || '').trim(),
                            default_caption: String(captionResult.value || '').trim()
                        };
                    });
                });
            });
        }).then(function(metadata) {
            if (!metadata) return;
            api({
                cmd: 'asset_metadata',
                target: file.hash,
                asset_id: String(file.asset_id),
                asset_revision: Number(file.asset_revision),
                locale_code: CONFIG.localeCode || 'zh_Hans_CN',
                display_name: metadata.display_name,
                default_alt: metadata.default_alt,
                description: metadata.description,
                default_caption: metadata.default_caption
            }, function(data) {
                var changed = data && data.changed && data.changed[file.hash];
                if (changed) {
                    FILES[file.hash] = Object.assign({}, FILES[file.hash] || file, changed);
                    updatePreviewPanel();
                    var details = qs('.mmf-details-overlay');
                    if (details && details.classList.contains('visible')) {
                        openAssetDetails(file.hash);
                    }
                }
                showSuccess(t('assetMetadataSaved'));
            });
        }).catch(function(error) {
            showError((error && error.message) || t('assetMetadataSaveFailed'));
        });
    }

    function hideContextMenu(restoreFocus) {
        var menu = qs('.mmf-context-menu');
        var runtime = contextMenuRuntime();
        if (runtime) {
            runtime.close(false, 'media-manager', true);
        } else if (menu) {
            menu.hidden = true;
            menu.dataset.state = 'closed';
            menu.setAttribute('aria-hidden', 'true');
        }
        resetContextMenuAnchor();
        if (restoreFocus) restoreContextMenuFocus();
        CONTEXT_MENU_RETURN_FOCUS = null;
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
            if (
                (f.phash === CWD_HASH || f.hash === CWD_HASH)
                && f.hash !== CWD_HASH
                && f.mime !== 'directory'
                && !fileSelectionIssue(f)
            ) {
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
            if (pathSet[fp] && !fileSelectionIssue(f)) SELECTED.push(h);
        }
        highlightSelected();
        updateStatus();
        if (IFRAME_MODE) updateSelectBar();
    }

    function confirmImageUsageSnapshots(selectedFiles) {
        if (!CONFIG.requireImageUsage) return Promise.resolve(selectedFiles);
        var ui = window.Weline && window.Weline.UI;
        if (!ui || !ui.dialog || typeof ui.dialog.prompt !== 'function') {
            return Promise.reject(new Error(t('imageAltRequired')));
        }
        var chain = Promise.resolve(true);
        selectedFiles.forEach(function(file) {
            chain = chain.then(function(continueSelection) {
                if (!continueSelection) return false;
                if (!isImage(String(file.mime || '')) || !file.asset_id || !file.locale_code) {
                    throw new Error(t('assetMetadataRequired'));
                }
                return ui.dialog.prompt(t('imageSemanticPrompt', { name: file.display_name || file.name || '' }), {
                    title: t('imageSemanticTitle'),
                    confirmLabel: t('confirm'),
                    field: {
                        type: 'select',
                        required: true,
                        value: 'information',
                        choices: {
                            information: t('imageSemanticInformation'),
                            decorative: t('imageSemanticDecorative')
                        }
                    }
                }).then(function(semanticResult) {
                    if (!semanticResult || !semanticResult.confirmed) return false;
                    var semantic = String(semanticResult.value || '');
                    if (semantic !== 'information' && semantic !== 'decorative') {
                        throw new Error(t('imageSemanticRequired'));
                    }
                    var decorative = semantic === 'decorative';
                    var altPromise = decorative
                        ? Promise.resolve('')
                        : ui.dialog.prompt(t('imageAltConfirmPrompt', { name: file.display_name || file.name || '' }), {
                            title: t('imageAltConfirmTitle'),
                            confirmLabel: t('confirm'),
                            field: {
                                type: 'text',
                                required: true,
                                value: String(file.default_alt || '').trim()
                            }
                        }).then(function(altResult) {
                            if (!altResult || !altResult.confirmed) return null;
                            var alt = String(altResult.value || '').trim();
                            if (!alt) throw new Error(t('imageAltRequired'));
                            return alt;
                        });
                    return altPromise.then(function(alt) {
                        if (alt === null) return false;
                        var usage = {
                            version: 1,
                            asset_id: String(file.asset_id),
                            locale_code: String(file.locale_code),
                            alt: alt,
                            alt_state: 'confirmed',
                            decorative: decorative,
                            caption: String(file.default_caption || '').trim() || null,
                            loading: 'lazy',
                            priority: 'auto',
                            widths: [480, 768, 1280],
                            sizes: '100vw'
                        };
                        file.image_usage = usage;
                        file.file_image_node = { type: 'file-image', usage: usage };
                        return true;
                    });
                });
            });
        });
        return chain.then(function(confirmed) {
            return confirmed ? selectedFiles : null;
        });
    }

    function finishSelection(selectedFiles) {
        if (GET_FILE_CALLBACK) {
            GET_FILE_CALLBACK(selectedFiles);
        } else if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                type: 'weline-media-manager-select',
                target: CONFIG.target || '',
                files: selectedFiles,
                multi: MULTI_SELECT
            }, window.location.origin);
        }

        exitSelectionMode();
        SELECTED = [];
        highlightSelected();
        updateSelectBar();
    }

    function typedAssetSelection(f) {
        return {
            type: 'file-asset',
            asset_id: String(f.asset_id || ''),
            locale_code: String(f.locale_code || CONFIG.localeCode || ''),
            display_name: String(f.display_name || f.name || ''),
            default_alt: String(f.default_alt || ''),
            description: String(f.description || ''),
            default_caption: String(f.default_caption || ''),
            translation_state: String(f.translation_state || ''),
            asset_selectable: f.asset_selectable === true,
            original_name: String(f.original_name || f.name || ''),
            mime: String(f.mime || ''),
            size: Number(f.size || 0),
            width: f.width || null,
            height: f.height || null,
            // Transient editor-only thumbnail; not part of persisted file-image usage.
            editor_preview_url: String(getThumbnailUrl(f) || f.preview_url || getFileResourceUrl(f.hash) || '').trim()
        };
    }

    function legacyPathSelection(f) {
        var relativePath = f.path || '';
        var fileUrl = String(f.preview_url || '');
        if (!fileUrl && CURRENT_STORAGE === 'local::filesystem::media') {
            fileUrl = '/pub/media/' + relativePath;
        }
        return {
            type: 'legacy-media-path',
            name: f.name,
            mime: f.mime,
            size: f.size,
            path: fileUrl,
            url: fileUrl,
            thumb: getThumbnailUrl(f) || fileUrl
        };
    }

    function confirmSelection() {
        if (SELECTION_CONFIRMING) return;
        if (!SELECTED.length) {
            showError(t('pleaseSelectFile'));
            return;
        }
        
        var selectedFiles = [];
        var blockedByAssetMetadata = false;
        var selectionIssue = null;
        SELECTED.forEach(function (hash) {
            var f = FILES[hash];
            if (f && f.mime !== 'directory') {
                var issue = fileSelectionIssue(f);
                if (issue) {
                    selectionIssue = selectionIssue || issue;
                    return;
                }
                if (CURRENT_STORAGE.indexOf('::') >= 0 && (!f.asset_id || f.asset_selectable !== true)) {
                    blockedByAssetMetadata = true;
                    return;
                }
                selectedFiles.push(CONFIG.requireImageUsage
                    ? typedAssetSelection(f)
                    : legacyPathSelection(f));
            }
        });

        if (selectionIssue) {
            showError(selectionIssue.message);
            return;
        }

        if (blockedByAssetMetadata) {
            showError(t('assetMetadataRequired'));
            return;
        }
        
        if (!selectedFiles.length) {
            showError(t('pleaseSelectValidFiles'));
            return;
        }
        
        if (!MULTI_SELECT && selectedFiles.length > 1) {
            selectedFiles = [selectedFiles[0]];
        }
        
        SELECTION_CONFIRMING = true;
        confirmImageUsageSnapshots(selectedFiles).then(function(confirmedFiles) {
            if (confirmedFiles) finishSelection(confirmedFiles);
        }).catch(function(error) {
            showError((error && error.message) || t('imageAltRequired'));
        }).finally(function() {
            SELECTION_CONFIRMING = false;
        });
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
                    }, window.location.origin);
                }
            });
        }
    }

    function updateSelectBar() {
        var bar = qs('#mmf-select-bar');
        var countEl = qs('#mmf-select-count-num');
        var wrap = qs('.mmf-wrap');
        
        if (!bar) return;

        if (IFRAME_MODE && MULTI_SELECT) {
            bar.style.display = 'flex';
            if (wrap) wrap.classList.add('with-select-bar');
        } else if (MULTI_SELECT && SELECTED.length > 0) {
            bar.style.display = 'flex';
            if (wrap) wrap.classList.add('with-select-bar');
        } else {
            bar.style.display = 'none';
            if (wrap) wrap.classList.remove('with-select-bar');
        }
        
        if (countEl) {
            countEl.textContent = SELECTED.length;
        }
        scheduleIframeLayoutHeightSync();
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
        if (CWD_HASH) renderFiles();
        updateToolbarCapabilities();
        scheduleIframeLayoutHeightSync();
    }

    function handleParentMessage(e) {
        if (e.source !== window.parent || e.origin !== window.location.origin) return;
        if (!e.data || typeof e.data !== 'object') return;
        
        if (e.data.type === 'weline-media-manager-init') {
            if (typeof e.data.target === 'string') {
                CONFIG.target = String(e.data.target).trim();
            }
            setupIframeMode({
                multi: e.data.multi,
                mimes: e.data.mimes,
                callback: function (files) {
                    if (window.parent && window.parent !== window) {
                        window.parent.postMessage({
                            type: 'weline-media-manager-select',
                            target: CONFIG.target || '',
                            files: files
                        }, window.location.origin);
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
        var image = qs('.mmf-lightbox-img');
        if (image) {
            image.onload = null;
            image.removeAttribute('src');
        }
        LIGHTBOX_IMAGES = [];
        LIGHTBOX_INDEX = 0;
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
        openManagerDialog({
            title: title,
            label: label,
            value: defaultVal || '',
            input: true,
            onOk: function(value) { onOk(value); }
        });
    }

    function showConfirm(msg, onOk) {
        openManagerDialog({
            title: t('confirm'),
            message: msg,
            input: false,
            destructive: true,
            onOk: onOk
        });
    }

    function openManagerDialog(options) {
        var overlay = qs('.mmf-dialog-overlay');
        if (!overlay) return;
        if (DIALOG_CLEANUP) DIALOG_CLEANUP();
        var dialog = qs('.mmf-dialog', overlay);
        var titleEl = qs('.mmf-dialog-title', overlay);
        var messageEl = qs('.mmf-dialog-message', overlay);
        var inp = qs('.mmf-dialog-input', overlay);
        var okBtn = qs('.mmf-dialog-ok', overlay);
        var cancelBtn = qs('.mmf-dialog-cancel', overlay);
        var returnFocus = document.activeElement;
        var hasInput = options.input === true;
        titleEl.textContent = options.title || '';
        messageEl.textContent = options.message || '';
        messageEl.hidden = !options.message;
        inp.hidden = !hasInput;
        inp.value = hasInput ? (options.value || '') : '';
        inp.placeholder = hasInput ? (options.label || '') : '';
        inp.setAttribute('aria-label', hasInput ? (options.label || options.title || '') : '');
        okBtn.classList.toggle('mmf-btn-danger', options.destructive === true);
        okBtn.classList.toggle('mmf-btn-primary', options.destructive !== true);
        overlay.setAttribute('aria-hidden', 'false');
        overlay.classList.add('visible');
        var closed = false;

        function close(restoreFocus) {
            if (closed) return;
            closed = true;
            overlay.classList.remove('visible');
            overlay.setAttribute('aria-hidden', 'true');
            okBtn.removeEventListener('click', handleOk);
            cancelBtn.removeEventListener('click', handleCancel);
            overlay.removeEventListener('pointerdown', handleOverlay);
            document.removeEventListener('keydown', handleKey, true);
            if (DIALOG_CLEANUP === cleanup) DIALOG_CLEANUP = null;
            if (restoreFocus && returnFocus && document.contains(returnFocus)) {
                returnFocus.focus({preventScroll: true});
            }
        }
        function cleanup() { close(false); }
        function handleOk() {
            var value = hasInput ? inp.value.trim() : '';
            close(true);
            if (typeof options.onOk === 'function') options.onOk(value);
        }
        function handleCancel() { close(true); }
        function handleOverlay(e) {
            if (e.target === overlay) handleCancel();
        }
        function handleKey(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                handleCancel();
                return;
            }
            if (e.key === 'Enter' && hasInput && e.target === inp) {
                e.preventDefault();
                handleOk();
                return;
            }
            if (e.key !== 'Tab') return;
            var focusable = Array.prototype.slice.call(
                dialog.querySelectorAll('button:not(:disabled), input:not([hidden]):not(:disabled)')
            );
            if (!focusable.length) return;
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }

        DIALOG_CLEANUP = cleanup;
        okBtn.addEventListener('click', handleOk);
        cancelBtn.addEventListener('click', handleCancel);
        overlay.addEventListener('pointerdown', handleOverlay);
        document.addEventListener('keydown', handleKey, true);
        if (hasInput) {
            inp.focus();
            inp.select();
        } else {
            cancelBtn.focus();
        }
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
        bindAiPromptDraft();
        var clearPromptBtn = qs('#mmf-ai-btn-clear-prompt');
        if (clearPromptBtn) clearPromptBtn.addEventListener('click', clearAiPromptDraft);
        var polishBtn = qs('#mmf-ai-btn-polish');
        if (polishBtn) polishBtn.addEventListener('click', polishAiPrompt);
    }

    var AI_PROMPT_DRAFT_KEY = 'mmf-ai-draw-prompt-draft-v1';
    var AI_PROMPT_DRAFT_TIMER = null;
    var AI_PROMPT_POLISHING = false;

    function aiPromptDraftKey() {
        return AI_PROMPT_DRAFT_KEY + (STORAGE_KEY ? (':' + STORAGE_KEY) : '');
    }

    function readAiPromptDraft() {
        try {
            var raw = localStorage.getItem(aiPromptDraftKey());
            if (!raw) return null;
            var data = JSON.parse(raw);
            if (!data || typeof data !== 'object') return null;
            return data;
        } catch (e) {
            return null;
        }
    }

    function writeAiPromptDraft() {
        var promptEl = qs('#mmf-ai-prompt');
        var batchEl = qs('#mmf-ai-batch-prompts');
        var countEl = qs('#mmf-ai-batch-count');
        var sizeEl = qs('#mmf-ai-size');
        var formatEl = qs('#mmf-ai-format');
        var payload = {
            prompt: promptEl ? String(promptEl.value || '') : '',
            batch_prompts: batchEl ? String(batchEl.value || '') : '',
            batch_count: countEl ? String(countEl.value || '2') : '2',
            size: sizeEl ? String(sizeEl.value || '') : '',
            output_format: formatEl ? String(formatEl.value || '') : '',
            updated_at: Date.now()
        };
        try {
            if (!payload.prompt && !payload.batch_prompts) {
                localStorage.removeItem(aiPromptDraftKey());
            } else {
                localStorage.setItem(aiPromptDraftKey(), JSON.stringify(payload));
            }
        } catch (e) {}
    }

    function scheduleAiPromptDraftSave() {
        if (AI_PROMPT_DRAFT_TIMER) clearTimeout(AI_PROMPT_DRAFT_TIMER);
        AI_PROMPT_DRAFT_TIMER = setTimeout(function () {
            AI_PROMPT_DRAFT_TIMER = null;
            writeAiPromptDraft();
        }, 250);
    }

    function restoreAiPromptDraft() {
        var draft = readAiPromptDraft();
        var promptEl = qs('#mmf-ai-prompt');
        if (promptEl) {
            promptEl.placeholder = t('aiPromptPlaceholder') || '';
            if (draft && typeof draft.prompt === 'string') {
                promptEl.value = draft.prompt;
            }
        }
        if (!draft) return;
        var batchEl = qs('#mmf-ai-batch-prompts');
        if (batchEl && typeof draft.batch_prompts === 'string') {
            batchEl.value = draft.batch_prompts;
        }
        var countEl = qs('#mmf-ai-batch-count');
        if (countEl && draft.batch_count) {
            countEl.value = draft.batch_count;
        }
        var sizeEl = qs('#mmf-ai-size');
        if (sizeEl && draft.size) {
            sizeEl.value = draft.size;
        }
        var formatEl = qs('#mmf-ai-format');
        if (formatEl && draft.output_format) {
            formatEl.value = draft.output_format;
        }
    }

    function clearAiPromptDraft() {
        if (isAiGenerating() || AI_PROMPT_POLISHING) return;
        var promptEl = qs('#mmf-ai-prompt');
        var batchEl = qs('#mmf-ai-batch-prompts');
        if (promptEl) promptEl.value = '';
        if (batchEl) batchEl.value = '';
        try { localStorage.removeItem(aiPromptDraftKey()); } catch (e) {}
        clearAiPromptError();
        if (promptEl) promptEl.focus();
    }

    function bindAiPromptDraft() {
        ['#mmf-ai-prompt', '#mmf-ai-batch-prompts', '#mmf-ai-batch-count', '#mmf-ai-size', '#mmf-ai-format'].forEach(function (sel) {
            var el = qs(sel);
            if (!el || el.__mmfDraftBound) return;
            el.__mmfDraftBound = true;
            el.addEventListener('input', scheduleAiPromptDraftSave);
            el.addEventListener('change', scheduleAiPromptDraftSave);
            if (sel === '#mmf-ai-prompt') {
                el.addEventListener('input', clearAiPromptError);
            }
        });
    }

    function toPlainText(msg) {
        var text = String(msg == null ? '' : msg);
        if (text.indexOf('<') >= 0) {
            text = text.replace(/<br\s*\/?\s*>/gi, ' ').replace(/<[^>]*>/g, ' ');
        }
        return text.replace(/&(?:nbsp|#160);/gi, ' ')
            .replace(/&amp;/gi, '&')
            .replace(/&lt;/gi, '<')
            .replace(/&gt;/gi, '>')
            .replace(/&quot;/gi, '"')
            .replace(/&#(?:0*39|x0*27);/gi, "'")
            .replace(/\s+/g, ' ')
            .trim();
    }

    function setAiPromptError(msg) {
        var el = qs('#mmf-ai-prompt-error');
        if (!el) return;
        var text = toPlainText(msg);
        el.textContent = text;
        el.style.display = text ? 'block' : 'none';
    }

    function clearAiPromptError() {
        setAiPromptError('');
    }

    function setAiPolishBusy(busy) {
        AI_PROMPT_POLISHING = !!busy;
        var btn = qs('#mmf-ai-btn-polish');
        if (btn) {
            btn.classList.toggle('is-loading', AI_PROMPT_POLISHING);
            btn.disabled = AI_PROMPT_POLISHING || isAiGenerating();
            btn.textContent = AI_PROMPT_POLISHING ? t('aiPromptPolishing') : t('aiPromptPolish');
        }
        var clearBtn = qs('#mmf-ai-btn-clear-prompt');
        if (clearBtn) clearBtn.disabled = AI_PROMPT_POLISHING || isAiGenerating();
        var promptEl = qs('#mmf-ai-prompt');
        if (promptEl && !isAiGenerating()) promptEl.disabled = AI_PROMPT_POLISHING;
    }

    function polishAiPrompt() {
        if (isAiGenerating() || AI_PROMPT_POLISHING) return;
        var promptEl = qs('#mmf-ai-prompt');
        var prompt = promptEl ? String(promptEl.value || '').trim() : '';
        if (!prompt) {
            setAiPromptError(t('aiNoPrompt'));
            showError(t('aiNoPrompt'));
            return;
        }
        clearAiPromptError();
        setAiPolishBusy(true);
        setAiStatus(t('aiPromptPolishing'), 'running');
        mmResource('polishPrompt', { prompt: prompt }).then(function (res) {
            var body = res && res.data && typeof res.data.prompt === 'string' ? res.data
                : (res && typeof res.prompt === 'string' ? res : null);
            var polished = body && typeof body.prompt === 'string' ? String(body.prompt).trim() : '';
            var ok = !!(res && res.success !== false && polished);
            if (!ok) {
                throw new Error((res && res.message) || t('aiPromptPolishFailed'));
            }
            if (promptEl) {
                promptEl.value = polished;
                writeAiPromptDraft();
                promptEl.focus();
            }
            clearAiPromptError();
            setAiStatus(t('aiPromptPolishDone'), 'ready');
        }).catch(function (err) {
            var msg = err && err.message ? err.message : t('aiPromptPolishFailed');
            if (window.WelineApiBusiness && typeof window.WelineApiBusiness.formatApiError === 'function') {
                msg = window.WelineApiBusiness.formatApiError(err, msg);
            } else if (window.Weline && window.Weline.ApiBusiness && typeof window.Weline.ApiBusiness.formatApiError === 'function') {
                msg = window.Weline.ApiBusiness.formatApiError(err, msg);
            }
            setAiPromptError(msg);
            showError(toPlainText(msg));
            setAiStatus('', '');
        }).finally(function () {
            setAiPolishBusy(false);
        });
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

        ['#mmf-ai-btn-generate', '#mmf-ai-btn-continue', '#mmf-ai-btn-save', '#mmf-ai-btn-polish', '#mmf-ai-btn-clear-prompt'].forEach(function (sel) {
            var el = qs(sel);
            if (!el) return;
            if (AI_GENERATING) {
                el.disabled = true;
            } else if (sel === '#mmf-ai-btn-save') {
                el.disabled = !AI_GENERATIONS.length;
            } else if (sel === '#mmf-ai-btn-polish' || sel === '#mmf-ai-btn-clear-prompt') {
                el.disabled = AI_PROMPT_POLISHING;
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
        mmResource('config', {}).then(function (res) {
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
        AI_SOURCE_HASH = '';
        updateAiReferencePreview('');
        resetAiPreview();
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
        clearAiPromptError();
        restoreAiPromptDraft();
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
            disk_code: CURRENT_STORAGE,
            locale_code: CONFIG.localeCode || 'zh_Hans_CN',
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
        AI_STREAM_CONTROLLER = { aborted: false, abort: function(){ this.aborted = true; } };
        mmResource('generate', payload).then(function (res) {
            if (AI_STREAM_CONTROLLER && AI_STREAM_CONTROLLER.aborted) return;
            var events = (res && res.events) || [];
            events.forEach(function (item) {
                handleAiSseEvent(item.event || 'message', item.data);
            });
            if (!AI_STREAM_TERMINAL) {
                reportAiError(t('aiStreamDisconnected'));
            }
        }).catch(function (err) {
            if (AI_STREAM_CONTROLLER && AI_STREAM_CONTROLLER.aborted) return;
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
            if (!AI_GENERATIONS.length && data.generation_id) {
                addAiPreviewItem(data);
            }
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
        var serverUrl = String(data.preview_url || data.previewUrl || data.url || '').trim();
        if (serverUrl) return serverUrl;
        if (data.generation_id) {
            var sessionId = String(data.session_id || AI_SESSION_ID || '').trim();
            var previewToken = String(data.preview_token || data.previewToken || '').trim();
            return buildAiPreviewUrl(sessionId, data.generation_id, previewToken);
        }
        return '';
    }

    function applyAiPreviewImage(img, src, onFail) {
        if (!img || !src) {
            if (typeof onFail === 'function') onFail();
            return;
        }
        var empty = qs('#mmf-ai-preview-empty');
        img.onload = function () {
            img.onerror = null;
            if (empty) empty.style.display = 'none';
        };
        img.onerror = function () {
            img.onerror = null;
            if (typeof onFail === 'function') onFail();
        };
        if (empty) empty.style.display = 'none';
        img.style.display = 'block';
        if (src.indexOf('data:') === 0 || src.indexOf('blob:') === 0) {
            img.src = src;
            return;
        }
        var cacheBust = (src.indexOf('?') >= 0 ? '&' : '?') + '_t=' + Date.now();
        img.src = src + cacheBust;
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
            overwriteWrap.style.display = 'none';
        }
        var saveAsMode = qs('input[name="mmf_ai_save_mode"][value="save_as"]');
        if (saveAsMode) saveAsMode.checked = true;
        var filename = qs('#mmf-ai-save-filename');
        if (filename) {
            filename.value = selected[0].filename || '';
            if (!filename.value) {
                var promptEl = qs('#mmf-ai-prompt');
                var promptStem = promptToAltFilenameStem(promptEl ? promptEl.value : '');
                if (promptStem) filename.value = promptStem + '.png';
            }
        }
        var promptEl = qs('#mmf-ai-prompt');
        var alt = qs('#mmf-ai-save-alt');
        if (alt) alt.value = String(promptEl ? promptEl.value : '').trim();
        var description = qs('#mmf-ai-save-description');
        if (description) description.value = '';
        var caption = qs('#mmf-ai-save-caption');
        if (caption) caption.value = '';
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
        var altEl = qs('#mmf-ai-save-alt');
        var descriptionEl = qs('#mmf-ai-save-description');
        var captionEl = qs('#mmf-ai-save-caption');
        var defaultAlt = altEl ? altEl.value.trim() : '';
        var description = descriptionEl ? descriptionEl.value.trim() : '';
        if (!defaultAlt) {
            setAiSaveError(t('aiSaveAltRequired'));
            return;
        }
        if (!description) {
            setAiSaveError(t('aiSaveDescriptionRequired'));
            return;
        }
        clearAiSaveError();
        setAiSaveBusy(true);
        var payload = {
            session_id: AI_SESSION_ID,
            save_mode: saveMode,
            target: CWD_HASH,
            disk_code: CURRENT_STORAGE,
            locale_code: CONFIG.localeCode || 'zh_Hans_CN',
            source_file_hash: AI_SOURCE_HASH,
            filename: filename,
            display_name: filename.replace(/\.[^.]+$/, '') || filename,
            default_alt: defaultAlt,
            description: description,
            default_caption: captionEl ? captionEl.value.trim() : '',
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

    function normalizeApiJsonPayload(body) {
        if (!body || typeof body !== 'object' || Array.isArray(body)) {
            return body;
        }
        var normalized = Object.assign({}, body);
        if (body.data && typeof body.data === 'object' && !Array.isArray(body.data)) {
            Object.keys(body.data).forEach(function (key) {
                if (!Object.prototype.hasOwnProperty.call(normalized, key)) {
                    normalized[key] = body.data[key];
                }
            });
        }
        return normalized;
    }

    function apiPostJson(url, payload, onDone, onErr) {
        var handleErr = function (err) {
            if (onErr) {
                onErr(err);
                return;
            }
            showError(extractApiErrorMessage(err, t('aiSaveFailed')));
        };
        var finishOk = function (res) {
            if (res && res.success === false) {
                handleErr(new Error(res.message || res.msg || t('aiSaveFailed')));
                return;
            }
            onDone(res);
        };
        var parseResponse = function (res, text) {
            var body = {};
            if (text) {
                try {
                    body = JSON.parse(text);
                } catch (e) {
                    throw new Error(t('invalidJson'));
                }
            }
            if (!res.ok) {
                throw new Error((body && (body.message || body.msg)) || ('HTTP ' + res.status));
            }
            if (body && body.success === false) {
                throw new Error(body.message || body.msg || t('aiSaveFailed'));
            }
            return normalizeApiJsonPayload(body);
        };
        mmResource('save', payload).then(function(body){
            finishOk(normalizeApiJsonPayload(body && body.data !== undefined ? body : body));
        }).catch(handleErr);
    }

    /* ─── util ───────────────────────────────────────────────────────── */

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function escAttr(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
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
