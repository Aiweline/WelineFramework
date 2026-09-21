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
    var INSTALLED_LOCALES = [];
    var AI_AUTO_TRANSLATION = false;
    var LOCALE_WORKBENCH = {
        assetId: '',
        hash: '',
        activeLocale: '',
        localesByCode: {},
        revision: 0,
        busy: ''
    };
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
    var NAV_PENDING = null;
    var SEARCH_SERIAL = 0;
    var SEARCH_DEBOUNCE_TIMER = null;
    var SEARCH_HIGHLIGHT_TIMER = null;
    var SAFE_UPLOAD_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'bmp', 'tiff', 'tif', 'avif',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'json',
        'zip', 'rar', 'gz', 'tar', '7z', 'mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac',
        'flac', 'opus', 'wma', 'weba', 'mp4', 'webm', 'avi',
        'mov', 'mkv', 'flv', 'wmv', 'ttf', 'otf', 'woff', 'woff2', 'eot'
    ];
    // Active browser content must never be uploaded via the media picker, even when
    // a caller passes an explicit ext= contract.
    var BLOCKED_UPLOAD_EXTENSIONS = ['svg', 'html', 'htm', 'css', 'js', 'xml'];

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

    function connectorNativeRequest(params) {
        return new Promise(function(resolve, reject) {
            if (!CONNECTOR) {
                reject(new Error(t('connectorNotConfigured')));
                return;
            }
            var endpoint;
            try {
                endpoint = new URL(CONNECTOR, document.baseURI);
            } catch (_error) {
                reject(new Error(t('connectorNotConfigured')));
                return;
            }
            if (endpoint.origin !== window.location.origin) {
                reject(new Error(t('crossOriginUploadRejected')));
                return;
            }
            if (!CONFIG.connectorFormKey) {
                reject(new Error(t('uploadSecurityTokenMissing')));
                return;
            }
            var body = new FormData();
            body.append('form_key', String(CONFIG.connectorFormKey));
            Object.keys(params || {}).forEach(function(key) {
                if (Object.prototype.hasOwnProperty.call(params, key)) {
                    appendConnectorFormField(body, key, params[key]);
                }
            });
            var xhr = new XMLHttpRequest();
            xhr.open('POST', endpoint.href, true);
            xhr.withCredentials = true;
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() {
                var response;
                try {
                    response = JSON.parse(String(xhr.responseText || ''));
                } catch (_error) {
                    reject(new Error(t('invalidJson')));
                    return;
                }
                if (xhr.status < 200 || xhr.status >= 300 || (response && response.error)) {
                    var message = response && response.error;
                    if (Array.isArray(message)) {
                        message = message.join(', ');
                    }
                    reject(new Error(String(message || t('networkError'))));
                    return;
                }
                resolve(response);
            };
            xhr.onerror = function() {
                reject(new Error(t('networkError')));
            };
            xhr.send(body);
        });
    }

    /**
     * FormData must preserve PHP array keys (targets[]). String(array) becomes
     * "a,b,c" and ConnectorService.requiredTargetHashes then rejects as empty.
     */
    function appendConnectorFormField(body, key, value) {
        if (value === undefined || value === null) {
            return;
        }
        if (Array.isArray(value)) {
            value.forEach(function(item, index) {
                if (item === undefined || item === null) {
                    return;
                }
                if (typeof item === 'object') {
                    body.append(key + '[' + index + ']', JSON.stringify(item));
                    return;
                }
                body.append(key + '[]', String(item));
            });
            return;
        }
        if (typeof value === 'object') {
            body.append(key, JSON.stringify(value));
            return;
        }
        body.append(key, String(value));
    }

    function mmResource(op, params) {
        if (op === 'connector') {
            if (IFRAME_MODE) {
                return connectorNativeRequest(params || {});
            }
            var runConnector = function(api){
                if (!api || typeof api.resource !== 'function') {
                    return connectorNativeRequest(params || {});
                }
                return api.resource('media_manager').connector(params || {});
            };
            var host = resolveBackendApiHost();
            if (host.Weline && typeof host.Weline.load === 'function') {
                return host.Weline.load('api').then(runConnector).catch(function() {
                    return connectorNativeRequest(params || {});
                });
            }
            if (host.Weline && host.Weline.Api) {
                return Promise.resolve().then(function() {
                    return runConnector(host.Weline.Api);
                });
            }
            return connectorNativeRequest(params || {});
        }
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
            if (payload && payload.cmd !== 'storages') {
                if (CONFIG.lockPath) {
                    payload.lockPath = '1';
                    if (CONFIG.lockRoot) {
                        payload.lockRoot = CONFIG.lockRoot;
                    }
                } else if (payload.lockPath == null) {
                    payload.lockPath = '0';
                }
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
                var focusAssetId = urlParams.get('asset_id');
                if (focusAssetId !== null && String(focusAssetId).trim() !== '') {
                    CONFIG.focusAssetId = String(focusAssetId).trim().toLowerCase();
                }
                [
                    'identity', 'identity_root', 'identity_code', 'identity_scope',
                    'identity_kind', 'identity_field', 'identity_component', 'identity_locale',
                    'identity_instance', 'ref_mode',
                ].forEach(function (key) {
                    var v = urlParams.get(key);
                    if (v !== null && String(v).trim() !== '') CONFIG[key] = String(v).trim();
                });
                [
                    ['aspect_ratio', 'aspectRatio'],
                    ['aspectRatio', 'aspectRatio'],
                    ['aspect_ratio_tolerance', 'aspectRatioTolerance'],
                    ['aspectRatioTolerance', 'aspectRatioTolerance'],
                    ['recommend_width', 'recommendWidth'],
                    ['recommendWidth', 'recommendWidth'],
                    ['recommend_height', 'recommendHeight'],
                    ['recommendHeight', 'recommendHeight'],
                    ['lockRoot', 'lockRoot'],
                    ['lock_root', 'lockRoot'],
                    ['path', 'startPathFromUrl']
                ].forEach(function (pair) {
                    var raw = urlParams.get(pair[0]);
                    if (raw !== null && String(raw).trim() !== '') {
                        CONFIG[pair[1]] = String(raw).trim();
                    }
                });
                var lockPathRaw = urlParams.get('lockPath');
                if (lockPathRaw === null) lockPathRaw = urlParams.get('lock_path');
                if (lockPathRaw !== null && String(lockPathRaw).trim() !== '') {
                    CONFIG.lockPath = !!(lockPathRaw === '1' || String(lockPathRaw).toLowerCase() === 'true');
                }
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
        if (!START_PATH && CONFIG.startPathFromUrl) {
            START_PATH = String(CONFIG.startPathFromUrl).trim();
        }
        CONFIG.lockPath = !!CONFIG.lockPath;
        CONFIG.lockRoot = normalizeBoundaryPath(CONFIG.lockRoot || '');
        LOCK_ROOT_PATH = CONFIG.lockRoot;
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
        bindMediaSearch();
        bindContextMenu();
        bindPreviewPanel();
        bindResponsiveChrome();
        bindDetailsDialog();
        bindTranslationConfigToggle();
        bindLocaleWorkbenchActions();
        bindAiDraw();
        updateToolbarCapabilities();
        updateAspectRatioHint();
        updatePathLockButton();
        
        // iframe 模式下绑定选择工具栏
        if (IFRAME_MODE) {
            window.addEventListener('message', handleParentMessage);
            bindSelectBar();
            bindIframeLayoutHost();
            // 多选嵌入选择器：默认进入选择模式，单击切换勾选（无需 Ctrl/右键）
            if (MULTI_SELECT) {
                enterSelectionMode();
                updateSelectBar();
            }
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
            if (IFRAME_MODE && (CONFIG.focusAssetId || '').trim()) {
                lastHash = null;
            }
            openDir(lastHash || '', true);
            if (IFRAME_MODE && (CONFIG.focusAssetId || '').trim()) {
                applyFocusAssetId();
            }
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
        if (!CONFIG.lockPath) return true;
        var candidate = normalizeBoundaryPath(path);
        if (LOCK_ROOT_PATH === '') return true;
        return candidate === LOCK_ROOT_PATH || candidate.indexOf(LOCK_ROOT_PATH + '/') === 0;
    }

    function isTreeItemOutsideLock(path) {
        if (!CONFIG.lockPath || LOCK_ROOT_PATH === '') return false;
        return !isPathWithinLockedRoot(path);
    }

    function resolveHashForPath(path) {
        var normalized = normalizeBoundaryPath(path);
        if (normalized === '') return '';
        var collections = [FILES, TREE];
        for (var i = 0; i < collections.length; i++) {
            var bag = collections[i];
            for (var hash in bag) {
                if (!Object.prototype.hasOwnProperty.call(bag, hash)) continue;
                if (normalizeBoundaryPath(bag[hash].path) === normalized) {
                    return hash;
                }
            }
        }
        return '';
    }

    function canOpenDirectoryHash(hash) {
        if (!CONFIG.lockPath) return true;
        if (ROOT_HASH !== '' && hash === ROOT_HASH) return true;
        var directory = FILES[hash] || TREE[hash];
        return !!directory
            && directory.mime === 'directory'
            && isPathWithinLockedRoot(directory.path);
    }

    function updatePathLockButton() {
        var btn = qs('#mmf-btn-path-lock');
        var row = qs('.mmf-path-row');
        if (row) {
            row.classList.toggle('mmf-path-row--locked', !!(CONFIG.lockRoot && CONFIG.lockPath));
        }
        if (!btn) return;
        var available = !!CONFIG.lockRoot;
        btn.classList.toggle('mmf-path-lock--available', available);
        btn.hidden = !available;
        if (!available) return;
        var locked = !!CONFIG.lockPath;
        btn.setAttribute('aria-pressed', locked ? 'true' : 'false');
        var label = locked ? t('unlockDirectory') : t('lockDirectory');
        var icon = locked ? '\uD83D\uDD12' : '\uD83D\uDD13';
        btn.title = label;
        var iconEl = qs('.mmf-path-lock-icon', btn);
        var labelEl = qs('.mmf-path-lock-label', btn);
        if (iconEl) iconEl.textContent = icon;
        if (labelEl) labelEl.textContent = label;
        else btn.textContent = label;
    }

    function setPathLockEnabled(enabled) {
        var next = !!enabled;
        var changed = next !== !!CONFIG.lockPath;
        CONFIG.lockPath = next;
        updatePathLockButton();
        if (next && LOCK_ROOT_PATH) {
            if (!isPathWithinLockedRoot(CWD_INFO.path || '')) {
                var hash = resolveHashForPath(LOCK_ROOT_PATH);
                if (hash) {
                    openDir(hash);
                } else {
                    START_PATH = LOCK_ROOT_PATH;
                    openDir('', true);
                }
            } else {
                try { renderTree(); } catch (_e) {}
                try { renderPath(); } catch (_e2) {}
            }
        } else {
            try { renderTree(); } catch (_e3) {}
            try { renderPath(); } catch (_e4) {}
        }
        if (changed) {
            if (next) showSuccess(t('directoryRelocked'));
            else showSuccess(t('directoryUnlocked'));
        }
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
                if (data.translation_config) {
                    applyTranslationConfig(data.translation_config);
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

                if (isInit && CWD_HASH) {
                    if (CONFIG.lockRoot) {
                        LOCK_ROOT_PATH = normalizeBoundaryPath(CONFIG.lockRoot);
                        ROOT_HASH = resolveHashForPath(LOCK_ROOT_PATH) || CWD_HASH;
                    } else {
                        ROOT_HASH = CWD_HASH;
                        LOCK_ROOT_PATH = normalizeBoundaryPath(CWD_INFO.path || START_PATH);
                        if (CONFIG.lockPath && !CONFIG.lockRoot) {
                            CONFIG.lockRoot = LOCK_ROOT_PATH;
                        }
                    }
                    updatePathLockButton();
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
                if (data.folder_created) {
                    showSuccess(t('folderCreated'));
                }
                applyNavigationPending();
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
            if (!f.phash || !TREE[f.phash]) {
                roots.push(f);
            } else {
                if (!childMap[f.phash]) childMap[f.phash] = [];
                childMap[f.phash].push(f);
            }
        }

        expandToPath(CWD_HASH);
        if (CONFIG.lockPath && ROOT_HASH) {
            expandToPath(ROOT_HASH);
            roots.forEach(function (r) {
                EXPANDED_NODES[r.hash] = true;
            });
        }

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
            var canManage = !isTreeItemOutsideLock(n.path)
                && (itemCapability('rename', n) || itemCapability('delete', n));
            var outsideLock = isTreeItemOutsideLock(n.path);
            html += '<li>';
            html += '<div class="mmf-tree-item' + (isActive ? ' active' : '') + (outsideLock ? ' mmf-tree-item--outside-lock' : '') + '" role="treeitem" tabindex="0"';
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
                if (item.classList.contains('mmf-tree-item--outside-lock')) {
                    showError(t('cannotAccessOutsidePath'));
                    return;
                }
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
                    if (item.classList.contains('mmf-tree-item--outside-lock')) {
                        showError(t('cannotAccessOutsidePath'));
                        return;
                    }
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
        if (item && item.classList.contains('mmf-tree-item--outside-lock')) {
            showError(t('cannotAccessOutsidePath'));
            return false;
        }
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

    function bindMediaSearch() {
        var input = qs('#mmf-search-input');
        var results = qs('#mmf-search-results');
        if (!input || !results || input.dataset.mmfSearchBound === '1') return;
        input.dataset.mmfSearchBound = '1';

        function setResultsVisible(visible) {
            results.hidden = !visible;
            input.setAttribute('aria-expanded', visible ? 'true' : 'false');
        }

        function hideResults() {
            setResultsVisible(false);
            results.replaceChildren();
        }

        function renderSearchState(kind, message) {
            results.replaceChildren();
            var el = document.createElement('div');
            el.className = kind === 'loading' ? 'mmf-search-loading' : 'mmf-search-empty';
            el.textContent = message;
            results.appendChild(el);
            setResultsVisible(true);
        }

        function isSearchResultAccessible(entry) {
            if (!entry) return false;
            if (!CONFIG.lockPath || LOCK_ROOT_PATH === '') return true;
            return isPathWithinLockedRoot(entry.path || '');
        }

        function renderSearchResults(items) {
            results.replaceChildren();
            if (!items.length) {
                renderSearchState('empty', t('searchNoResults'));
                return;
            }
            items.forEach(function(entry, index) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'mmf-search-result';
                button.setAttribute('role', 'option');
                button.id = 'mmf-search-result-' + index;
                button.dataset.hash = entry.hash || '';
                var isDirectory = entry.mime === 'directory';
                button.innerHTML = ''
                    + '<span class="mmf-search-result-icon" aria-hidden="true">'
                    + (isDirectory ? '\uD83D\uDCC1' : '\uD83D\uDCC4')
                    + '</span>'
                    + '<span class="mmf-search-result-body">'
                    + '<span class="mmf-search-result-name">' + escHtml(entry.name || '') + '</span>'
                    + '<span class="mmf-search-result-path">' + escHtml(formatSearchResultPath(entry.path || '')) + '</span>'
                    + '</span>';
                button.addEventListener('click', function() {
                    hideResults();
                    input.value = entry.name || '';
                    navigateToSearchResult(entry);
                });
                results.appendChild(button);
            });
            setResultsVisible(true);
        }

        function runSearch() {
            var query = String(input.value || '').trim();
            if (!query) {
                hideResults();
                return;
            }
            var requestSerial = ++SEARCH_SERIAL;
            renderSearchState('loading', t('searching'));
            var params = {cmd: 'search', query: query, limit: 50};
            if (CONFIG.lockPath && LOCK_ROOT_PATH) {
                params.path = LOCK_ROOT_PATH;
            } else if (START_PATH) {
                params.path = START_PATH;
            }
            api(params, function(data) {
                if (requestSerial !== SEARCH_SERIAL) return;
                var matches = Array.isArray(data && data.results) ? data.results : [];
                renderSearchResults(matches.filter(isSearchResultAccessible));
            }, function() {
                if (requestSerial !== SEARCH_SERIAL) return;
                renderSearchState('empty', t('searchFailed'));
            });
        }

        input.addEventListener('input', function() {
            if (SEARCH_DEBOUNCE_TIMER) window.clearTimeout(SEARCH_DEBOUNCE_TIMER);
            var query = String(input.value || '').trim();
            if (!query) {
                hideResults();
                return;
            }
            SEARCH_DEBOUNCE_TIMER = window.setTimeout(runSearch, 300);
        });
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hideResults();
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                if (SEARCH_DEBOUNCE_TIMER) {
                    window.clearTimeout(SEARCH_DEBOUNCE_TIMER);
                    SEARCH_DEBOUNCE_TIMER = null;
                }
                runSearch();
            }
        });
        input.addEventListener('focus', function() {
            if (String(input.value || '').trim() && results.childElementCount) {
                setResultsVisible(true);
            }
        });
        document.addEventListener('pointerdown', function(e) {
            if (!input.contains(e.target) && !results.contains(e.target)) {
                hideResults();
            }
        });
    }

    function formatSearchResultPath(path) {
        var value = String(path || '').replace(/^\/+|\/+$/g, '');
        return value || '/';
    }

    function navigateToSearchResult(entry) {
        if (!entry || !entry.hash) return;
        var isDirectory = entry.mime === 'directory';
        var directoryHash = isDirectory ? entry.hash : String(entry.phash || '');
        if (!directoryHash) {
            showError(t('searchTargetMissing'));
            return;
        }
        if (!canOpenDirectoryHash(directoryHash)) {
            showError(t('cannotAccessOutsidePath'));
            return;
        }
        NAV_PENDING = {
            directoryHash: directoryHash,
            focusHash: isDirectory ? '' : entry.hash,
            treeFocusHash: isDirectory ? entry.hash : directoryHash,
            highlightHash: entry.hash
        };
        openDir(directoryHash);
    }

    function applyFocusAssetId() {
        var assetId = String(CONFIG.focusAssetId || '').trim().toLowerCase();
        if (!assetId || CONFIG._focusAssetApplied) {
            return;
        }
        CONFIG._focusAssetApplied = true;
        api({
            cmd: 'find_asset',
            asset_id: assetId,
            locale_code: CONFIG.localeCode || 'zh_Hans_CN'
        }, function(data) {
            if (data && data.error) {
                showError(Array.isArray(data.error) ? data.error.join(', ') : String(data.error));
                return;
            }
            var entry = data && data.result;
            if (!entry || !entry.hash) {
                showError(t('searchTargetMissing'));
                return;
            }
            if (data.storage && String(data.storage) !== String(CURRENT_STORAGE || '')) {
                var select = qs('#mmf-storage-select');
                if (select) {
                    select.value = String(data.storage);
                    CURRENT_STORAGE = String(data.storage);
                    updateStorageKey();
                }
            }
            var directoryHash = String(entry.phash || '');
            if (!directoryHash && entry.path) {
                directoryHash = '';
            }
            NAV_PENDING = {
                directoryHash: directoryHash,
                focusHash: entry.hash,
                treeFocusHash: directoryHash || entry.hash,
                highlightHash: entry.hash,
                openDetails: true
            };
            openDir(directoryHash);
        }, function(err) {
            showError(err || t('searchFailed'));
        });
    }

    function applyNavigationPending() {
        if (!NAV_PENDING || NAV_PENDING.directoryHash !== CWD_HASH) return;
        var pending = NAV_PENDING;
        NAV_PENDING = null;
        expandToPath(pending.treeFocusHash);
        renderTree();
        if (pending.focusHash && FILES[pending.focusHash]) {
            SELECTED = [pending.focusHash];
        } else if (pending.focusHash === '' && pending.treeFocusHash) {
            SELECTED = [pending.treeFocusHash];
        }
        highlightSelected();
        updateStatus();
        updatePreviewPanel();
        if (IFRAME_MODE) {
            updateSelectBar();
        }
        window.requestAnimationFrame(function() {
            if (pending.focusHash) {
                scrollMediaItemIntoView(pending.focusHash);
            }
            scrollTreeItemIntoView(pending.treeFocusHash);
            flashSearchHighlight(pending.highlightHash || pending.treeFocusHash);
            if (pending.openDetails && pending.focusHash && FILES[pending.focusHash]) {
                openAssetDetails(pending.focusHash);
            }
        });
    }

    function scrollMediaItemIntoView(hash) {
        var item = qs('.mmf-item[data-hash="' + hash + '"]');
        if (item) item.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }

    function scrollTreeItemIntoView(hash) {
        var item = qs('.mmf-tree-item[data-hash="' + hash + '"]');
        if (item) item.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }

    function flashSearchHighlight(hash) {
        if (!hash) return;
        qsa('.mmf-item.search-highlight, .mmf-tree-item.search-highlight').forEach(function(el) {
            el.classList.remove('search-highlight');
        });
        qsa('[data-hash="' + hash + '"]').forEach(function(el) {
            if (el.classList.contains('mmf-item') || el.classList.contains('mmf-tree-item')) {
                el.classList.add('search-highlight');
            }
        });
        if (SEARCH_HIGHLIGHT_TIMER) window.clearTimeout(SEARCH_HIGHLIGHT_TIMER);
        SEARCH_HIGHLIGHT_TIMER = window.setTimeout(function() {
            qsa('.mmf-item.search-highlight, .mmf-tree-item.search-highlight').forEach(function(el) {
                el.classList.remove('search-highlight');
            });
            SEARCH_HIGHLIGHT_TIMER = null;
        }, 2400);
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
            reapplyIdentityFilterIfNeeded();
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
            if (!isDir && Number(f.reference_count || f.ref_count || 0) > 0) {
                html += '<span class="mmf-ref-badge" data-mmf-ref-badge>' + escHtml('引用 ' + Number(f.reference_count || f.ref_count || 0)) + '</span>';
            }
            if (selectionIssue) {
                html += '<div class="mmf-item-hint">' + escHtml(selectionIssue.message) + '</div>';
            }
            html += '</div>';
        });
        container.innerHTML = html;

        bindThumbnailFallbacks(container);
        bindFileEvents(container);
        scheduleIframeLayoutHeightSync();
        reapplyIdentityFilterIfNeeded();
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
        var guard = 0;
        while (cur && guard < 64) {
            guard += 1;
            var node = FILES[cur] || TREE[cur];
            if (!node) break;
            parts.unshift(node);
            if (CONFIG.lockPath && ROOT_HASH && cur === ROOT_HASH) break;
            if (!node.phash) break;
            cur = node.phash;
        }
        var html = '';
        parts.forEach(function (p, i) {
            if (i > 0) html += '<span class="mmf-path-sep">/</span>';
            html += '<span class="mmf-path-seg" data-hash="' + escAttr(p.hash) + '">' + escHtml(p.name) + '</span>';
        });
        if (!html && CONFIG.lockRoot) {
            html = '<span class="mmf-path-seg" data-hash="' + escAttr(ROOT_HASH || CWD_HASH) + '">'
                + escHtml(LOCK_ROOT_PATH || CONFIG.lockRoot)
                + '</span>';
        }
        el.innerHTML = html;
        updatePathLockButton();

        qsa('.mmf-path-seg', el).forEach(function (seg) {
            seg.addEventListener('click', function () {
                var hash = seg.dataset.hash;
                if (!hash) return;
                openDir(hash);
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

                // selection-error files remain clickable for preview; confirm/dblclick stay blocked.
                var selectionError = el.getAttribute('data-selection-error');
                var hasSelectionError = !!(selectionError && FILES[hash] && FILES[hash].mime !== 'directory');

                if (SELECTION_MODE) {
                    toggleSelect(hash);
                    LAST_CLICKED_HASH = hash;
                    updateStatus();
                    updatePreviewPanel();
                    if (hasSelectionError) showError(selectionError);
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
                updatePreviewPanel();
                if (hasSelectionError) showError(selectionError);
            });

            el.addEventListener('dblclick', function () {
                var hash = el.dataset.hash;
                var f = FILES[hash];
                if (!f) return;
                if (openDirectoryFromInteraction(hash, el.dataset.mime)) {
                    return;
                }
                var selectionError = el.getAttribute('data-selection-error');
                if (selectionError) {
                    showError(selectionError);
                    return;
                }
                if (IFRAME_MODE) {
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
        var hadDimensions = Number(file.width || 0) > 0 && Number(file.height || 0) > 0;
        if (!file.width) file.width = width;
        if (!file.height) file.height = height;
        qsa('[data-field="dimensions"] .mmf-metadata-value').forEach(function(value) {
            var metadataList = value.closest('[data-file-hash]');
            if (metadataList && metadataList.dataset.fileHash !== String(file.hash || '')) return;
            value.textContent = width + ' × ' + height;
        });
        if (!hadDimensions && configuredAspectRatio() && IFRAME_MODE) {
            renderFiles();
            highlightSelected();
        }
    }

    function isTruthyFlag(value) {
        return value === true || value === 1 || value === '1';
    }

    function applyTranslationConfig(config) {
        if (!config || typeof config !== 'object') return;
        AI_AUTO_TRANSLATION = isTruthyFlag(config.ai_auto_translation);
        if (Array.isArray(config.installed_locales)) {
            INSTALLED_LOCALES = config.installed_locales.map(function(code) {
                return String(code || '').trim();
            }).filter(Boolean);
        }
        var checkbox = qs('[data-mmf-ai-auto-translate]');
        if (checkbox) checkbox.checked = AI_AUTO_TRANSLATION;
    }

    function bindTranslationConfigToggle() {
        var checkbox = qs('[data-mmf-ai-auto-translate]');
        if (!checkbox || checkbox.dataset.bound === '1') return;
        checkbox.dataset.bound = '1';
        checkbox.addEventListener('change', function() {
            var enabled = !!checkbox.checked;
            api({
                cmd: 'translation_config',
                action: 'set',
                ai_auto_translation: enabled ? 1 : 0
            }, function(data) {
                applyTranslationConfig(data || {ai_auto_translation: enabled});
                if (enabled) {
                    var queueId = data && Number(data.queue_id) > 0 ? Number(data.queue_id) : 0;
                    showSuccess(queueId > 0
                        ? t('aiAutoTranslationQueued', {id: queueId})
                        : t('aiAutoTranslationOn'));
                } else {
                    showSuccess(t('aiAutoTranslationOff'));
                }
            }, function(err) {
                checkbox.checked = AI_AUTO_TRANSLATION;
                showError(err || t('aiAutoTranslationSaveFailed'));
            });
        });
    }

    function localeRecordMap(locales) {
        var map = {};
        (locales || []).forEach(function(row) {
            if (!row || !row.locale_code) return;
            map[String(row.locale_code)] = row;
        });
        return map;
    }

    function renderLocaleWorkbench(file) {
        var root = qs('[data-mmf-locale-workbench]');
        if (!root) return;
        if (!file || file.mime === 'directory' || !file.asset_id) {
            root.hidden = true;
            LOCALE_WORKBENCH.assetId = '';
            return;
        }
        root.hidden = false;
        LOCALE_WORKBENCH.assetId = String(file.asset_id);
        LOCALE_WORKBENCH.hash = String(file.hash || '');
        LOCALE_WORKBENCH.revision = Number(file.asset_revision || 0);
        LOCALE_WORKBENCH.activeLocale = String(
            LOCALE_WORKBENCH.activeLocale || file.locale_code || CONFIG.localeCode || 'zh_Hans_CN'
        );
        api({
            cmd: 'asset_locales',
            target: file.hash,
            asset_id: String(file.asset_id)
        }, function(data) {
            if (!data || String(data.asset_id) !== LOCALE_WORKBENCH.assetId) return;
            if (Array.isArray(data.installed_locales) && data.installed_locales.length) {
                INSTALLED_LOCALES = data.installed_locales.map(String);
            }
            LOCALE_WORKBENCH.localesByCode = localeRecordMap(data.locales || []);
            paintLocaleWorkbench();
        });
    }

    function paintLocaleWorkbench() {
        var tabs = qs('[data-mmf-locale-tabs]');
        if (!tabs) return;
        tabs.replaceChildren();
        var codes = INSTALLED_LOCALES.slice();
        if (!codes.length) {
            codes = Object.keys(LOCALE_WORKBENCH.localesByCode);
        }
        if (codes.indexOf(LOCALE_WORKBENCH.activeLocale) < 0 && codes.length) {
            LOCALE_WORKBENCH.activeLocale = codes[0];
        }
        var sourceLocale = sourceLocaleForWorkbench();
        var hint = qs('[data-mmf-locale-workbench-hint]');
        if (hint) {
            hint.textContent = t('localeWorkbenchHint');
        }
        codes.forEach(function(code) {
            var row = LOCALE_WORKBENCH.localesByCode[code];
            var missing = !(row && row.has_content);
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mmf-locale-tab' + (code === LOCALE_WORKBENCH.activeLocale ? ' is-active' : '');
            btn.setAttribute('role', 'tab');
            btn.setAttribute('aria-selected', code === LOCALE_WORKBENCH.activeLocale ? 'true' : 'false');
            btn.dataset.locale = code;
            btn.textContent = code + (missing ? ' · ' + t('localeMissing') : '');
            btn.title = code === sourceLocale
                ? t('localeTabSourceTitle')
                : (missing ? t('localeTabTempFillTitle') : t('localeTabSwitchTitle'));
            btn.addEventListener('click', function() {
                LOCALE_WORKBENCH.activeLocale = code;
                paintLocaleWorkbench();
            });
            btn.addEventListener('dblclick', function(e) {
                e.preventDefault();
                LOCALE_WORKBENCH.activeLocale = code;
                paintLocaleWorkbench();
                requestLocaleTempFill(code);
            });
            tabs.appendChild(btn);
        });
        var active = LOCALE_WORKBENCH.localesByCode[LOCALE_WORKBENCH.activeLocale] || {};
        var displayName = qs('[data-mmf-locale-display-name]');
        var defaultAlt = qs('[data-mmf-locale-default-alt]');
        var description = qs('[data-mmf-locale-description]');
        var caption = qs('[data-mmf-locale-caption]');
        var status = qs('[data-mmf-locale-status]');
        if (displayName) displayName.value = String(active.display_name || '');
        if (defaultAlt) defaultAlt.value = String(active.default_alt || '');
        if (description) description.value = String(active.description || '');
        if (caption) caption.value = String(active.default_caption || '');
        if (status) {
            status.classList.toggle('is-loading', LOCALE_WORKBENCH.busy === 'translate');
            if (LOCALE_WORKBENCH.busy === 'translate') {
                status.textContent = t('localeTranslatingHint');
            } else {
                status.textContent = [
                    t('metadataTranslationState') + ': ' + (active.translation_state || '—'),
                    t('metadataTranslationOrigin') + ': ' + (active.translation_origin || '—')
                ].join(' · ');
            }
        }
    }

    function sourceLocaleForWorkbench() {
        var file = FILES[LOCALE_WORKBENCH.hash] || {};
        return String(file.locale_code || CONFIG.localeCode || 'zh_Hans_CN');
    }

    function runAssetTranslateMissing(targetLocales, onSuccessMessage) {
        if (!LOCALE_WORKBENCH.assetId || !LOCALE_WORKBENCH.hash || LOCALE_WORKBENCH.busy) return;
        setLocaleWorkbenchBusy(true, 'translate');
        var startTranslate = function() {
            var payload = {
                cmd: 'asset_translate_missing',
                target: LOCALE_WORKBENCH.hash,
                asset_id: LOCALE_WORKBENCH.assetId,
                locale_code: sourceLocaleForWorkbench()
            };
            if (targetLocales && targetLocales.length) {
                payload.target_locales = targetLocales;
            }
            api(payload, function(data) {
                setLocaleWorkbenchBusy(false);
                if (data && data.errors && data.errors.length) {
                    showError(data.errors.join('; '));
                } else if (typeof onSuccessMessage === 'function') {
                    showSuccess(onSuccessMessage(data));
                } else {
                    showSuccess(t('oneClickTranslateDone', {
                        filled: (data && data.filled && data.filled.length) || 0,
                        skipped: (data && data.skipped && data.skipped.length) || 0
                    }));
                }
                LOCALE_WORKBENCH.localesByCode = localeRecordMap((data && data.locales) || []);
                paintLocaleWorkbench();
            }, function(err) {
                setLocaleWorkbenchBusy(false);
                showError(err || t('oneClickTranslateFailed'));
            });
        };
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(function() {
                window.requestAnimationFrame(startTranslate);
            });
        } else {
            startTranslate();
        }
    }

    function requestLocaleTempFill(code) {
        if (!LOCALE_WORKBENCH.assetId || !LOCALE_WORKBENCH.hash || LOCALE_WORKBENCH.busy) return;
        var locale = String(code || '').trim();
        if (!locale) return;
        var source = sourceLocaleForWorkbench();
        if (locale === source) {
            showSuccess(t('localeTempFillSourceSkip'));
            return;
        }
        var row = LOCALE_WORKBENCH.localesByCode[locale];
        if (row && row.has_content) {
            showSuccess(t('localeTempFillSkipped', { locale: locale }));
            return;
        }
        runAssetTranslateMissing([locale], function(data) {
            var filled = (data && data.filled && data.filled.length) || 0;
            var skipped = (data && data.skipped && data.skipped.length) || 0;
            if (filled > 0) {
                return t('localeTempFillDone', { locale: locale });
            }
            return t('oneClickTranslateDone', { filled: filled, skipped: skipped });
        });
    }

    function rememberLocaleButtonLabel(btn) {
        if (!btn || btn.dataset.defaultLabel) return;
        btn.dataset.defaultLabel = String(btn.textContent || '').trim();
    }

    function setLocaleActionButtonBusy(btn, busy, loadingText) {
        if (!btn) return;
        rememberLocaleButtonLabel(btn);
        btn.disabled = !!busy;
        btn.classList.toggle('is-loading', !!busy);
        btn.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (busy) {
            btn.innerHTML = '<span class="mmf-spinner" aria-hidden="true"></span><span>'
                + escHtml(String(loadingText || ''))
                + '</span>';
            return;
        }
        btn.textContent = btn.dataset.defaultLabel || btn.textContent || '';
    }

    function ensureLocaleWorkbenchLoadingOverlay(root) {
        if (!root) return null;
        var overlay = qs('[data-mmf-locale-loading-overlay]', root);
        if (overlay) return overlay;
        overlay = document.createElement('div');
        overlay.className = 'mmf-locale-loading-overlay';
        overlay.setAttribute('data-mmf-locale-loading-overlay', '');
        overlay.hidden = true;
        overlay.innerHTML = '<span class="mmf-spinner" aria-hidden="true"></span><span data-mmf-locale-loading-text></span>';
        root.appendChild(overlay);
        return overlay;
    }

    function setLocaleWorkbenchBusy(busy, mode) {
        LOCALE_WORKBENCH.busy = busy ? String(mode || 'translate') : '';
        var root = qs('[data-mmf-locale-workbench]');
        var saveBtn = qs('[data-mmf-locale-save]');
        var translateBtn = qs('[data-mmf-locale-translate]');
        var editor = qs('[data-mmf-locale-editor]');
        var status = qs('[data-mmf-locale-status]');
        var overlay = ensureLocaleWorkbenchLoadingOverlay(root);
        var overlayText = overlay ? qs('[data-mmf-locale-loading-text]', overlay) : null;
        var loadingText = mode === 'save' ? t('localeSaving') : t('localeTranslatingHint');
        if (root) {
            root.classList.toggle('is-busy', !!busy);
            root.setAttribute('aria-busy', busy ? 'true' : 'false');
        }
        setLocaleActionButtonBusy(saveBtn, !!busy && mode === 'save', t('localeSaving'));
        setLocaleActionButtonBusy(translateBtn, !!busy && mode === 'translate', t('localeTranslating'));
        if (editor) {
            editor.classList.toggle('is-busy', !!busy);
            qsa('input, textarea', editor).forEach(function(el) {
                el.disabled = !!busy;
            });
        }
        var tabs = qs('[data-mmf-locale-tabs]');
        if (tabs) {
            qsa('button', tabs).forEach(function(tab) {
                tab.disabled = !!busy;
            });
        }
        if (overlay) {
            overlay.hidden = !busy;
            if (overlayText) overlayText.textContent = busy ? loadingText : '';
        }
        if (status && busy) {
            status.classList.add('is-loading');
            status.textContent = loadingText;
        } else if (status && !busy) {
            status.classList.remove('is-loading');
        }
    }

    function bindLocaleWorkbenchActions() {
        var saveBtn = qs('[data-mmf-locale-save]');
        var translateBtn = qs('[data-mmf-locale-translate]');
        if (saveBtn && saveBtn.dataset.bound !== '1') {
            saveBtn.dataset.bound = '1';
            saveBtn.addEventListener('click', function() {
                if (!LOCALE_WORKBENCH.assetId || !LOCALE_WORKBENCH.hash || LOCALE_WORKBENCH.busy) return;
                setLocaleWorkbenchBusy(true, 'save');
                api({
                    cmd: 'asset_metadata',
                    target: LOCALE_WORKBENCH.hash,
                    asset_id: LOCALE_WORKBENCH.assetId,
                    asset_revision: LOCALE_WORKBENCH.revision || 1,
                    locale_code: LOCALE_WORKBENCH.activeLocale,
                    display_name: String((qs('[data-mmf-locale-display-name]') || {}).value || '').trim(),
                    default_alt: String((qs('[data-mmf-locale-default-alt]') || {}).value || '').trim(),
                    description: String((qs('[data-mmf-locale-description]') || {}).value || '').trim(),
                    default_caption: String((qs('[data-mmf-locale-caption]') || {}).value || '').trim()
                }, function(data) {
                    setLocaleWorkbenchBusy(false);
                    var changed = data && data.changed && data.changed[LOCALE_WORKBENCH.hash];
                    if (changed) {
                        FILES[LOCALE_WORKBENCH.hash] = Object.assign({}, FILES[LOCALE_WORKBENCH.hash] || {}, changed);
                        LOCALE_WORKBENCH.revision = Number(changed.asset_revision || LOCALE_WORKBENCH.revision || 0);
                    }
                    showSuccess(t('localeSaveSuccess'));
                    renderLocaleWorkbench(FILES[LOCALE_WORKBENCH.hash]);
                    updatePreviewPanel();
                }, function(err) {
                    setLocaleWorkbenchBusy(false);
                    showError(err || t('localeSaveFailed'));
                });
            });
        }
        if (translateBtn && translateBtn.dataset.bound !== '1') {
            translateBtn.dataset.bound = '1';
            translateBtn.addEventListener('click', function() {
                runAssetTranslateMissing(null);
            });
        }
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
        document.documentElement.style.removeProperty('height');
        document.documentElement.style.removeProperty('max-height');
        document.documentElement.style.minHeight = '0';
        document.documentElement.style.overflow = 'hidden';
        if (document.body) {
            document.body.style.removeProperty('height');
            document.body.style.removeProperty('max-height');
            document.body.style.minHeight = '0';
            document.body.style.overflow = 'hidden';
            document.body.style.margin = '0';
        }
        var main = qs('main.w-backend-page') || qs('#main-content');
        if (main) {
            main.style.removeProperty('height');
            main.style.removeProperty('max-height');
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
            wrap.style.removeProperty('height');
            wrap.style.removeProperty('max-height');
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
        var previewRoot = qs('[data-mmf-preview]') || qs('.mmf-preview');
        var emptyEl = qs('.mmf-preview-empty');
        var imageEl = qs('.mmf-preview-image');
        var infoEl = qs('.mmf-preview-info');

        if (!emptyEl || !imageEl || !infoEl) return;

        function showEmptyPreview() {
            if (previewRoot) {
                previewRoot.classList.remove('mmf-preview--active');
                previewRoot.removeAttribute('data-mmf-preview-hash');
            }
            emptyEl.hidden = false;
            emptyEl.style.display = '';
            clearPreviewImage();
            imageEl.style.display = 'none';
            infoEl.style.display = 'none';
            var workbench = qs('[data-mmf-locale-workbench]');
            if (workbench) workbench.hidden = true;
            var contentElReset = qs('.mmf-preview-content');
            if (contentElReset) contentElReset.classList.remove('mmf-preview-content--with-image');
            resetPreviewDetailScroll();
        }

        if (SELECTED.length !== 1) {
            showEmptyPreview();
            return;
        }

        var f = FILES[SELECTED[0]];
        if (!f) {
            showEmptyPreview();
            return;
        }

        if (previewRoot) {
            previewRoot.classList.add('mmf-preview--active');
            previewRoot.setAttribute('data-mmf-preview-hash', String(f.hash || SELECTED[0] || ''));
        }
        emptyEl.hidden = true;
        emptyEl.style.display = 'none';
        infoEl.style.display = 'grid';

        var nameEl = qs('.mmf-preview-name');
        var metadataEl = qs('[data-mmf-preview-meta]');
        var btnOpen = qs('.mmf-preview-btn-open');
        var btnDownload = qs('.mmf-preview-btn-download');
        var btnDetails = qs('.mmf-preview-btn-details');

        if (nameEl) nameEl.textContent = f.name || '';
        renderMetadataList(metadataEl, f, true);
        renderLocaleWorkbench(f);

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
        var btnPathLock = qs('#mmf-btn-path-lock');
        if (btnPathLock) btnPathLock.addEventListener('click', function () {
            if (!CONFIG.lockRoot) return;
            setPathLockEnabled(!CONFIG.lockPath);
        });
        bindViewModeSwitch();
        bindReferencePanelActions();
        initIdentityBar();
    }

    var VIEW_MODE = 'files'; // files | refs | trash

    function bindViewModeSwitch() {
        var buttons = document.querySelectorAll('[data-mmf-view-mode]');
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var mode = String(btn.getAttribute('data-mmf-view-mode') || 'files');
                setViewMode(mode);
            });
        });
        var trashBtn = qs('#mmf-btn-trash');
        if (trashBtn) {
            trashBtn.addEventListener('click', function () {
                setViewMode(VIEW_MODE === 'trash' ? 'files' : 'trash');
            });
        }
    }

    function setViewMode(mode) {
        VIEW_MODE = mode === 'refs' || mode === 'trash' ? mode : 'files';
        document.querySelectorAll('[data-mmf-view-mode]').forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-mmf-view-mode') === VIEW_MODE);
        });
        var wrap = qs('.mmf-wrap');
        if (wrap) {
            wrap.classList.toggle('mmf-view-refs', VIEW_MODE === 'refs');
            wrap.classList.toggle('mmf-view-trash', VIEW_MODE === 'trash');
        }
        var tree = qs('.mmf-tree');
        var refPanel = qs('#mmf-ref-panel');
        var title = qs('.mmf-sidebar-title');
        if (tree) tree.hidden = VIEW_MODE === 'refs';
        if (refPanel) refPanel.hidden = VIEW_MODE !== 'refs';
        if (title && VIEW_MODE !== 'refs') title.hidden = false;
        if (title && VIEW_MODE === 'refs') title.hidden = true;
        CONFIG.referenceViewMode = VIEW_MODE === 'refs';
        CONFIG.trashViewMode = VIEW_MODE === 'trash';
        if (VIEW_MODE === 'files' && typeof openDir === 'function' && CWD_HASH !== undefined) {
            openDir(CWD_HASH);
        }
        updateStatus();
    }

    function mediaReferenceApiBase() {
        return String(CONFIG.mediaReferenceBase || '/backend/weline_filemanager/backend/media-reference');
    }

    var IDENTITY_ACTIVE_TAGS = {};
    var IDENTITY_CHIP_GROUPS = [
        { id: 'root', labelKey: 'identityGroupRoot', keys: ['root'] },
        { id: 'code', labelKey: 'identityGroupCode', keys: ['key', 'sku', 'theme', 'brand', 'post', 'category', 'mail', 'attribute', 'supplier', 'website', 'code'] },
        { id: 'scope', labelKey: 'identityGroupScope', keys: ['scope'] },
        { id: 'slot', labelKey: 'identityGroupSlot', keys: ['kind', 'field', 'component', 'locale', 'instance', 'role', 'ns', 'axis', 'option', 'layout', 'index'] },
    ];

    function parseCurrentIdentityTags() {
        var tags = {};
        var path = String(CONFIG.identity || '').trim();
        if (path) {
            var parts = path.split(':').filter(function (p) { return p !== ''; });
            if (parts.length) {
                tags.root = parts[0];
                for (var i = 1; i + 1 < parts.length; i += 2) {
                    var k = String(parts[i] || '').trim();
                    var v = String(parts[i + 1] || '').trim();
                    if (k && v) tags[k] = v;
                }
            }
        }
        if (!tags.root && CONFIG.identity_root) tags.root = String(CONFIG.identity_root).trim();
        if (CONFIG.identity_scope) tags.scope = String(CONFIG.identity_scope).trim();
        if (CONFIG.identity_code) {
            var codeKey = 'code';
            if (tags.root === 'product') codeKey = 'sku';
            else if (tags.root === 'blog') codeKey = 'post';
            else if (tags.root === 'catalog') codeKey = 'category';
            else if (tags.root === 'config') codeKey = 'key';
            else if (tags.root === 'smtp') codeKey = 'mail';
            else if (tags.root === 'theme' || tags.root === 'widget') codeKey = 'theme';
            else if (tags.root === 'product_brand') codeKey = 'brand';
            else if (tags.root === 'product_supplier') codeKey = 'supplier';
            else if (tags.root === 'eav') codeKey = 'attribute';
            if (!tags[codeKey]) tags[codeKey] = String(CONFIG.identity_code).trim();
        }
        ['kind', 'field', 'component', 'locale', 'instance'].forEach(function (k) {
            var confKey = 'identity_' + k;
            if (CONFIG[confKey] && !tags[k]) tags[k] = String(CONFIG[confKey]).trim();
        });
        return tags;
    }

    function identityGroupLabel(group) {
        var fallback = ({
            identityGroupRoot: '大类',
            identityGroupCode: '身份',
            identityGroupScope: '范围',
            identityGroupSlot: '槽位',
        })[group.labelKey] || group.id;
        var translated = t(group.labelKey);
        return (translated && translated !== group.labelKey) ? translated : fallback;
    }

    function setIdentityHint(text) {
        var el = qs('[data-mmf-identity-hint]');
        if (el) el.textContent = text || '';
    }

    function syncRefPanelFromTags(tags) {
        tags = tags || {};
        var rootEl = qs('#mmf-ref-root');
        var scopeEl = qs('#mmf-ref-scope');
        var codeEl = qs('#mmf-ref-code');
        var kindEl = qs('#mmf-ref-kind');
        var componentEl = qs('#mmf-ref-component');
        if (rootEl && tags.root) rootEl.value = tags.root;
        if (scopeEl && tags.scope) scopeEl.value = tags.scope;
        if (codeEl) {
            codeEl.value = tags.key || tags.sku || tags.theme || tags.post || tags.category
                || tags.brand || tags.mail || tags.attribute || tags.supplier || tags.code || '';
        }
        if (kindEl && tags.kind) kindEl.value = tags.kind;
        if (componentEl && tags.component) componentEl.value = tags.component;
    }

    function applyIdentityFilter(tags, done) {
        IDENTITY_ACTIVE_TAGS = tags && Object.keys(tags).length ? Object.assign({}, tags) : {};
        var allBtn = qs('[data-mmf-identity-all]');
        if (allBtn) {
            var isAll = !Object.keys(IDENTITY_ACTIVE_TAGS).length;
            allBtn.classList.toggle('is-active', isAll);
            allBtn.setAttribute('aria-pressed', isAll ? 'true' : 'false');
        }
        qsa('[data-mmf-identity-chip]').forEach(function (chip) {
            var k = chip.getAttribute('data-tag-key') || '';
            var v = chip.getAttribute('data-tag-value') || '';
            var on = !!(IDENTITY_ACTIVE_TAGS[k] && String(IDENTITY_ACTIVE_TAGS[k]) === String(v));
            chip.classList.toggle('is-active', on);
            chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        if (!Object.keys(IDENTITY_ACTIVE_TAGS).length) {
            qsa('.mmf-item').forEach(function (el) { el.style.display = ''; });
            setIdentityHint('');
            setRefReport('');
            if (done) done({ cleared: true, count: null });
            return;
        }
        syncRefPanelFromTags(IDENTITY_ACTIVE_TAGS);
        var qsParams = Object.keys(IDENTITY_ACTIVE_TAGS).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(IDENTITY_ACTIVE_TAGS[k]);
        }).join('&');
        setIdentityHint('…');
        mediaReferenceFetch('GET', mediaReferenceApiBase() + '/by-tags?' + qsParams, null, function (res) {
            var items = (res && res.items) || [];
            var ids = {};
            items.forEach(function (row) {
                var id = String(row.asset_id || '');
                if (id) ids[id] = true;
            });
            var idKeys = Object.keys(ids);
            var visible = 0;
            qsa('.mmf-item').forEach(function (el) {
                var hash = el.getAttribute('data-hash') || '';
                var file = FILES[hash];
                var assetId = file && file.asset_id ? String(file.asset_id) : '';
                var show = !idKeys.length ? false : !!(assetId && ids[assetId]);
                if (file && file.mime === 'directory') show = true;
                el.style.display = show ? '' : 'none';
                if (show && !(file && file.mime === 'directory')) visible++;
            });
            var hitLabel = t('identityFilterHit');
            if (!hitLabel || hitLabel === 'identityFilterHit') hitLabel = '命中引用';
            var visLabel = t('identityFilterVisible');
            if (!visLabel || visLabel === 'identityFilterVisible') visLabel = '当前可见';
            var hint = hitLabel + ': ' + items.length
                + (idKeys.length ? (' · ' + visLabel + ' ' + visible) : '');
            setIdentityHint(hint);
            setRefReport(hint);
            if (done) done({ count: items.length, visible: visible });
        }, function (err) {
            setIdentityHint((err && err.message) || 'filter failed');
            if (done) done({ error: err });
        });
    }

    function reapplyIdentityFilterIfNeeded() {
        if (Object.keys(IDENTITY_ACTIVE_TAGS).length) {
            applyIdentityFilter(IDENTITY_ACTIVE_TAGS);
        }
    }

    function identitySummaryText(tags) {
        tags = tags || {};
        var codeKeys = ['key', 'sku', 'theme', 'brand', 'post', 'category', 'mail', 'attribute', 'supplier', 'website', 'code'];
        for (var i = 0; i < codeKeys.length; i++) {
            var ck = codeKeys[i];
            if (tags[ck]) return ck + ': ' + tags[ck];
        }
        if (tags.root && tags.scope) return tags.root + ' · ' + tags.scope;
        if (tags.root) return String(tags.root);
        if (tags.scope) return 'scope: ' + tags.scope;
        return '';
    }

    function setIdentityBarExpanded(expanded) {
        var bar = qs('#mmf-identity-bar');
        var toggle = qs('[data-mmf-identity-toggle]');
        var panel = qs('[data-mmf-identity-panel]');
        if (!bar || !toggle || !panel) return;
        bar.classList.toggle('is-collapsed', !expanded);
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        panel.hidden = !expanded;
    }

    function initIdentityBar() {
        var bar = qs('#mmf-identity-bar');
        var groupsEl = qs('[data-mmf-identity-groups]');
        var allBtn = qs('[data-mmf-identity-all]');
        var summaryEl = qs('[data-mmf-identity-summary]');
        var toggle = qs('[data-mmf-identity-toggle]');
        if (!bar || !groupsEl) return;

        var tags = parseCurrentIdentityTags();
        var hasTags = Object.keys(tags).length > 0;
        bar.hidden = !hasTags;
        if (!hasTags) return;

        if (summaryEl) summaryEl.textContent = identitySummaryText(tags);
        // 默认收起：只显示一行身份摘要；展开后才是标签筛选。
        setIdentityBarExpanded(false);
        if (toggle && !toggle.dataset.mmfIdentityToggleBound) {
            toggle.dataset.mmfIdentityToggleBound = '1';
            toggle.addEventListener('click', function () {
                var open = toggle.getAttribute('aria-expanded') === 'true';
                setIdentityBarExpanded(!open);
            });
        }

        groupsEl.innerHTML = '';
        IDENTITY_CHIP_GROUPS.forEach(function (group) {
            var chips = [];
            group.keys.forEach(function (key) {
                if (!tags[key]) return;
                chips.push({ key: key, value: tags[key] });
            });
            if (!chips.length) return;
            var row = document.createElement('div');
            row.className = 'mmf-identity-group';
            row.setAttribute('data-mmf-identity-group', group.id);
            var label = document.createElement('span');
            label.className = 'mmf-identity-group__label';
            label.textContent = identityGroupLabel(group);
            row.appendChild(label);
            chips.forEach(function (chipData) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'mmf-identity-chip';
                btn.setAttribute('data-mmf-identity-chip', '1');
                btn.setAttribute('data-tag-key', chipData.key);
                btn.setAttribute('data-tag-value', chipData.value);
                btn.setAttribute('aria-pressed', 'false');
                btn.title = chipData.key + ':' + chipData.value;
                if (chipData.key !== 'root') {
                    var keySpan = document.createElement('span');
                    keySpan.className = 'mmf-identity-chip__key';
                    keySpan.textContent = chipData.key + ':';
                    btn.appendChild(keySpan);
                }
                var valSpan = document.createElement('span');
                valSpan.className = 'mmf-identity-chip__val';
                valSpan.textContent = chipData.value;
                btn.appendChild(valSpan);
                btn.addEventListener('click', function () {
                    var next = Object.assign({}, IDENTITY_ACTIVE_TAGS);
                    var active = next[chipData.key] && String(next[chipData.key]) === String(chipData.value);
                    if (active) delete next[chipData.key];
                    else next[chipData.key] = chipData.value;
                    applyIdentityFilter(next);
                });
                row.appendChild(btn);
            });
            label.style.cursor = 'pointer';
            label.title = (function () {
                var h = t('identityFilterGroupHint');
                return (!h || h === 'identityFilterGroupHint') ? '点击按本组全部标签过滤' : h;
            })();
            label.addEventListener('click', function () {
                var next = {};
                chips.forEach(function (c) { next[c.key] = c.value; });
                applyIdentityFilter(next);
            });
            groupsEl.appendChild(row);
        });

        // 摘要在分组渲染后再写一次，避免配置晚到或缓存旧脚本时一行空白。
        if (summaryEl) {
            var summary = identitySummaryText(tags);
            if (!summary) {
                var firstChip = groupsEl.querySelector('[data-mmf-identity-chip][data-tag-key]');
                if (firstChip) {
                    var fk = firstChip.getAttribute('data-tag-key') || '';
                    var fv = firstChip.getAttribute('data-tag-value') || '';
                    summary = fk === 'root' ? fv : (fk + ': ' + fv);
                }
            }
            summaryEl.textContent = summary;
        }

        if (allBtn) {
            allBtn.addEventListener('click', function () {
                applyIdentityFilter({});
            });
        }
        syncRefPanelFromTags(tags);
    }

    function collectRefTags() {
        var tags = {};
        var root = (qs('#mmf-ref-root') || {}).value || '';
        var scope = (qs('#mmf-ref-scope') || {}).value || '';
        var code = (qs('#mmf-ref-code') || {}).value || '';
        var kind = (qs('#mmf-ref-kind') || {}).value || '';
        var component = (qs('#mmf-ref-component') || {}).value || '';
        if (root) tags.root = root.trim();
        if (scope) tags.scope = scope.trim();
        if (code) {
            var codeVal = code.trim();
            if (root === 'product') tags.sku = codeVal;
            else if (root === 'blog') tags.post = codeVal;
            else if (root === 'catalog') tags.category = codeVal;
            else if (root === 'config') tags.key = codeVal;
            else if (root === 'smtp') tags.mail = codeVal;
            else if (root === 'product_brand') tags.brand = codeVal;
            else if (root === 'product_supplier') tags.supplier = codeVal;
            else if (root === 'eav') tags.attribute = codeVal;
            else if (root === 'theme' || root === 'widget') tags.theme = codeVal;
            else tags.code = codeVal;
        }
        if (kind) tags.kind = kind.trim();
        if (component) tags.component = component.trim();
        return tags;
    }

    function setRefReport(text) {
        var el = qs('#mmf-ref-report');
        if (el) el.textContent = text || '';
    }

    function mediaReferenceFetch(method, url, body, onDone, onErr) {
        var opts = {
            method: method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        };
        if (body != null) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        fetch(url, opts).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok || (data && data.ok === false)) {
                    throw new Error((data && data.error) || ('HTTP ' + res.status));
                }
                return data;
            });
        }).then(function (data) {
            if (onDone) onDone(data);
        }).catch(function (err) {
            if (onErr) onErr(err);
            else setRefReport((err && err.message) || 'request failed');
        });
    }

    function bindReferencePanelActions() {
        var applyBtn = qs('#mmf-ref-apply');
        var unbindBtn = qs('#mmf-ref-unbind-prefix');
        var unrefBtn = qs('#mmf-ref-unreferenced');
        var closeBtn = qs('#mmf-ref-close-scope');
        if (applyBtn) applyBtn.addEventListener('click', function () {
            applyIdentityFilter(collectRefTags());
        });
        if (unbindBtn) unbindBtn.addEventListener('click', function () {
            var tags = collectRefTags();
            if (!tags.root || !(tags.sku || tags.theme || tags.post || tags.category || tags.key || tags.mail || tags.brand || tags.supplier || tags.attribute || tags.code || tags.scope)) {
                setRefReport('需要 root + code/scope');
                return;
            }
            if (!window.confirm('确认仅卸载匹配引用（不删文件）？')) return;
            var code = tags.sku || tags.theme || tags.post || tags.category || tags.key || tags.mail || tags.brand || tags.supplier || tags.attribute || tags.code || tags.scope;
            mediaReferenceFetch('POST', mediaReferenceApiBase() + '/unbind', {
                type: tags.root,
                scope: tags.scope || CONFIG.identity_scope || 'default.default.default',
                code: code,
            }, function (res) {
                setRefReport('已卸引用: ' + (res && res.removed != null ? res.removed : 0));
            });
        });
        if (unrefBtn) unrefBtn.addEventListener('click', function () {
            var count = 0;
            qsa('.mmf-item').forEach(function (el) {
                var hash = el.getAttribute('data-hash') || '';
                var file = FILES[hash];
                var refs = Number(file && (file.reference_count || file.ref_count) || 0);
                var show = file && file.mime !== 'directory' && refs <= 0;
                el.style.display = show ? '' : 'none';
                if (show) count++;
            });
            setRefReport('无引用文件: ' + count);
        });
        if (closeBtn) closeBtn.addEventListener('click', function () {
            var scope = ((qs('#mmf-ref-scope') || {}).value || '').trim();
            if (!scope) {
                setRefReport('关站需要填写 scope');
                return;
            }
            if (!window.confirm('确认按范围清引用？\n' + scope)) return;
            mediaReferenceFetch('POST', mediaReferenceApiBase() + '/delete-by-scope', {
                scope: scope,
                include_children: true,
            }, function (res) {
                setRefReport(
                    '卸 ' + (res.removed || 0)
                    + ' · 共享保留 ' + (res.shared_kept || 0)
                    + ' · scope ' + (res.scope || scope)
                );
            });
        });
    }

    function decorateReferenceBadge(fileEl, file) {
        if (!(fileEl instanceof HTMLElement) || !file || !file.asset_id) return;
        var count = Number(file.reference_count || file.ref_count || 0);
        var badge = fileEl.querySelector('[data-mmf-ref-badge]');
        if (count <= 0) {
            if (badge) badge.remove();
            return;
        }
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'mmf-ref-badge';
            badge.setAttribute('data-mmf-ref-badge', '');
            fileEl.appendChild(badge);
        }
        badge.textContent = t('referenceCount') ? (t('referenceCount') + ' ' + count) : ('引用 ' + count);
        badge.title = badge.textContent;
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
        if (!raw || raw === '*') {
            return SAFE_UPLOAD_EXTENSIONS.slice();
        }
        // upload-ext-contract:v2 — Explicit picker/block ext= is the contract
        // (e.g. StoreMusic audio with m4a). Do NOT re-intersect with SAFE_UPLOAD_EXTENSIONS
        // or newly added formats get silently dropped while the UI still displays
        // the full contract list.
        return raw.split(',').map(function(extension) {
            return extension.trim().replace(/^\./, '');
        }).filter(function(extension) {
            return extension
                && extension !== '*'
                && BLOCKED_UPLOAD_EXTENSIONS.indexOf(extension) < 0;
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
        }).filter(function(extension) {
            return extension && BLOCKED_UPLOAD_EXTENSIONS.indexOf(extension) < 0;
        });
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

    function gcdInt(a, b) {
        a = Math.abs(Math.round(Number(a) || 0));
        b = Math.abs(Math.round(Number(b) || 0));
        while (b) {
            var tmp = b;
            b = a % b;
            a = tmp;
        }
        return a || 1;
    }

    function parseAspectRatio(raw) {
        var match = String(raw || '').trim().match(/^(\d+(?:\.\d+)?)\s*[:xX×\/]\s*(\d+(?:\.\d+)?)$/);
        if (!match) return null;
        var width = Math.round(Number(match[1]));
        var height = Math.round(Number(match[2]));
        if (!(width > 0 && height > 0)) return null;
        var g = gcdInt(width, height);
        return {
            w: width / g,
            h: height / g,
            label: String(width / g) + ':' + String(height / g)
        };
    }

    function configuredAspectRatio() {
        var explicit = parseAspectRatio(CONFIG.aspectRatio || CONFIG.aspect_ratio || '');
        if (explicit) return explicit;
        var recommendW = parseInt(CONFIG.recommendWidth || CONFIG.recommend_width || '', 10);
        var recommendH = parseInt(CONFIG.recommendHeight || CONFIG.recommend_height || '', 10);
        if (!(recommendW > 0 && recommendH > 0)) return null;
        var g = gcdInt(recommendW, recommendH);
        return {
            w: recommendW / g,
            h: recommendH / g,
            label: String(recommendW / g) + ':' + String(recommendH / g)
        };
    }

    function aspectRatioTolerance() {
        var raw = CONFIG.aspectRatioTolerance || CONFIG.aspect_ratio_tolerance || '0.02';
        var value = Number(raw);
        if (!Number.isFinite(value) || value < 0) return 0.02;
        return value;
    }

    function matchesAspectRatio(fileWidth, fileHeight, ratioWidth, ratioHeight, tolerance) {
        if (!(fileWidth > 0 && fileHeight > 0 && ratioWidth > 0 && ratioHeight > 0)) return false;
        var actual = fileWidth / fileHeight;
        var target = ratioWidth / ratioHeight;
        if (!(target > 0) || !Number.isFinite(actual) || !Number.isFinite(target)) return false;
        var absDiff = Math.abs(actual - target);
        // Absolute slack (legacy) OR relative slack (better for wide strips like 64:5 /
        // 1909×150 vs 1920×150 ≈ 0.57% relative, which fails a tiny absolute 0.02).
        return absDiff <= tolerance || (absDiff / target) <= tolerance;
    }

    function formatFileAspectLabel(width, height) {
        var w = Math.round(Number(width) || 0);
        var h = Math.round(Number(height) || 0);
        if (!(w > 0 && h > 0)) return '';
        var g = gcdInt(w, h);
        return String(w / g) + ':' + String(h / g);
    }

    function updateAspectRatioHint() {
        var hint = qs('#mmf-aspect-hint');
        if (!hint) return;
        var ratio = configuredAspectRatio();
        if (!ratio || !IFRAME_MODE) {
            hint.hidden = true;
            hint.textContent = '';
            return;
        }
        var text = t('aspectRatioRequired', { ratio: ratio.label });
        var recommendW = parseInt(CONFIG.recommendWidth || CONFIG.recommend_width || '', 10);
        var recommendH = parseInt(CONFIG.recommendHeight || CONFIG.recommend_height || '', 10);
        if (recommendW > 0 && recommendH > 0) {
            text += ' · ' + t('aspectRatioRecommendHint', {
                width: String(recommendW),
                height: String(recommendH)
            });
        }
        hint.textContent = text;
        hint.hidden = false;
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
        var ratio = configuredAspectRatio();
        if (ratio && isImageMime(file.mime)) {
            var width = Number(file.width || 0);
            var height = Number(file.height || 0);
            if (!(width > 0 && height > 0)) {
                return {
                    kind: 'aspect_ratio',
                    message: t('aspectRatioDimensionsMissing')
                };
            }
            if (!matchesAspectRatio(width, height, ratio.w, ratio.h, aspectRatioTolerance())) {
                return {
                    kind: 'aspect_ratio',
                    message: t('aspectRatioMismatch', {
                        ratio: ratio.label,
                        actual: formatFileAspectLabel(width, height) || (width + '×' + height)
                    })
                };
            }
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

    function splitUploadFileName(name) {
        var value = String(name || '');
        var dot = value.lastIndexOf('.');
        if (dot <= 0) return {base: value, ext: ''};
        return {base: value.slice(0, dot), ext: value.slice(dot)};
    }

    /**
     * 冲突弹窗新名：扩展名以源文件为准。可带可不带；不带则补齐，带错则纠正，避免无扩展上传报错。
     */
    function ensureUploadFileExtension(name, originalName) {
        var trimmed = String(name || '').trim();
        if (!trimmed) return trimmed;
        var expectedExt = splitUploadFileName(originalName).ext;
        if (!expectedExt) return trimmed;
        var match = trimmed.match(/\.[A-Za-z0-9]{1,16}$/);
        if (!match) return trimmed + expectedExt;
        if (match[0].toLowerCase() === expectedExt.toLowerCase()) return trimmed;
        return trimmed.slice(0, -match[0].length) + expectedExt;
    }

    function suggestUniqueUploadFileName(name, reservedNames) {
        var normalized = String(name || '');
        var lower = normalized.toLowerCase();
        if (!reservedNames[lower]) return normalized;
        var parts = splitUploadFileName(normalized);
        var index = 1;
        var candidate = '';
        do {
            candidate = parts.base + ' (' + index + ')' + parts.ext;
            index++;
        } while (reservedNames[candidate.toLowerCase()]);
        return candidate;
    }

    function renameUploadFile(file, newName) {
        if (!file || typeof File !== 'function') return file;
        if (String(file.name || '') === newName) return file;
        return new File(
            [file],
            newName,
            {type: file.type || '', lastModified: file.lastModified || Date.now()}
        );
    }

    function directoryChildFiles(targetHash) {
        var list = [];
        targetHash = String(targetHash || CWD_HASH || '');
        for (var h in FILES) {
            var file = FILES[h];
            if (file && file.phash === targetHash && file.mime !== 'directory' && file.name) {
                list.push({
                    hash: String(file.hash || h),
                    name: String(file.name),
                    mime: String(file.mime || '')
                });
            }
        }
        list.sort(function(a, b) {
            return a.name.localeCompare(b.name, undefined, {sensitivity: 'base'});
        });
        return list;
    }

    function filterDirectoryChildFiles(files, query) {
        var q = String(query || '').trim().toLowerCase();
        if (!q) return files.slice();
        return files.filter(function(file) {
            return String(file.name || '').toLowerCase().indexOf(q) !== -1;
        });
    }

    function setOverwritePickError(message) {
        var el = qs('#mmf-overwrite-pick-error');
        if (!el) return;
        var text = String(message || '').trim();
        if (!text) {
            el.textContent = '';
            el.hidden = true;
            return;
        }
        el.textContent = text;
        el.hidden = false;
    }

    function promptOptionalOverwriteTargets(fileList, targetHash) {
        var overlay = qs('#mmf-overwrite-pick-overlay');
        var rowsEl = qs('[data-mmf-overwrite-pick-rows]');
        var confirmBtn = qs('#mmf-overwrite-pick-confirm');
        var cancelBtn = qs('#mmf-overwrite-pick-cancel');
        var files = Array.prototype.slice.call(fileList || []);
        if (!overlay || !rowsEl || !confirmBtn || !cancelBtn || !files.length) {
            return Promise.resolve({
                files: files,
                overwriteFlags: files.map(function() { return false; })
            });
        }
        var candidates = directoryChildFiles(targetHash);
        if (!candidates.length) {
            return Promise.resolve({
                files: files,
                overwriteFlags: files.map(function() { return false; })
            });
        }

        return new Promise(function(resolve) {
            var settled = false;
            var mappings = files.map(function() { return ''; });
            var returnFocus = document.activeElement;

            function finish(result) {
                if (settled) return;
                settled = true;
                overlay.classList.remove('visible');
                overlay.hidden = true;
                overlay.setAttribute('aria-hidden', 'true');
                confirmBtn.removeEventListener('click', handleConfirm);
                cancelBtn.removeEventListener('click', handleCancel);
                overlay.removeEventListener('pointerdown', handleOverlay);
                document.removeEventListener('keydown', handleKey, true);
                if (returnFocus && document.contains(returnFocus)) {
                    returnFocus.focus({preventScroll: true});
                }
                resolve(result);
            }

            function handleCancel() {
                finish(null);
            }

            function handleConfirm() {
                setOverwritePickError('');
                var used = {};
                var nextFiles = files.slice();
                var flags = files.map(function() { return false; });
                for (var i = 0; i < mappings.length; i++) {
                    var targetName = String(mappings[i] || '').trim();
                    if (!targetName) continue;
                    var key = targetName.toLowerCase();
                    if (used[key]) {
                        setOverwritePickError(t('overwritePickDuplicateTarget', {name: targetName}));
                        return;
                    }
                    used[key] = true;
                    nextFiles[i] = renameUploadFile(nextFiles[i], targetName);
                    flags[i] = true;
                }
                finish({files: nextFiles, overwriteFlags: flags});
            }

            function handleOverlay(e) {
                if (e.target === overlay) handleCancel();
            }

            function handleKey(e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    handleCancel();
                }
            }

            function renderCandidates(rowIndex, listEl, searchValue, selectedName) {
                listEl.replaceChildren();
                var filtered = filterDirectoryChildFiles(candidates, searchValue);
                if (!filtered.length) {
                    var empty = document.createElement('div');
                    empty.className = 'mmf-overwrite-pick-selected';
                    empty.textContent = t('overwritePickNoMatch');
                    listEl.appendChild(empty);
                    return;
                }
                filtered.forEach(function(item) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'mmf-overwrite-pick-candidate' + (item.name === selectedName ? ' is-active' : '');
                    btn.textContent = item.name;
                    btn.setAttribute('role', 'option');
                    btn.setAttribute('aria-selected', item.name === selectedName ? 'true' : 'false');
                    btn.addEventListener('click', function() {
                        mappings[rowIndex] = item.name;
                        updateRowSelection(rowIndex);
                    });
                    listEl.appendChild(btn);
                });
            }

            function updateRowSelection(rowIndex) {
                var row = rowsEl.querySelector('[data-mmf-overwrite-row="' + rowIndex + '"]');
                if (!row) return;
                var selectedEl = row.querySelector('[data-mmf-overwrite-selected]');
                var searchEl = row.querySelector('[data-mmf-overwrite-search]');
                var listEl = row.querySelector('[data-mmf-overwrite-list]');
                var name = String(mappings[rowIndex] || '');
                if (selectedEl) {
                    if (name) {
                        selectedEl.hidden = false;
                        selectedEl.textContent = t('overwritePickSelected', {name: name});
                    } else {
                        selectedEl.hidden = true;
                        selectedEl.textContent = '';
                    }
                }
                if (listEl && searchEl) {
                    renderCandidates(rowIndex, listEl, searchEl.value, name);
                }
            }

            rowsEl.replaceChildren();
            setOverwritePickError('');
            files.forEach(function(file, index) {
                var row = document.createElement('div');
                row.className = 'mmf-overwrite-pick-row';
                row.setAttribute('data-mmf-overwrite-row', String(index));

                var incoming = document.createElement('div');
                incoming.className = 'mmf-overwrite-pick-incoming';
                incoming.textContent = t('overwritePickIncoming') + ' · ' + String(file.name || '');

                var search = document.createElement('input');
                search.type = 'search';
                search.className = 'mmf-overwrite-pick-search';
                search.setAttribute('data-mmf-overwrite-search', '');
                search.setAttribute('aria-label', t('overwritePickSearch'));
                search.placeholder = t('overwritePickSearch');

                var list = document.createElement('div');
                list.className = 'mmf-overwrite-pick-candidates';
                list.setAttribute('data-mmf-overwrite-list', '');
                list.setAttribute('role', 'listbox');

                var selected = document.createElement('p');
                selected.className = 'mmf-overwrite-pick-selected';
                selected.setAttribute('data-mmf-overwrite-selected', '');
                selected.hidden = true;

                var actions = document.createElement('div');
                actions.className = 'mmf-overwrite-pick-row-actions';
                var clearBtn = document.createElement('button');
                clearBtn.type = 'button';
                clearBtn.className = 'mmf-btn';
                clearBtn.textContent = t('overwritePickClear');
                clearBtn.addEventListener('click', function() {
                    mappings[index] = '';
                    updateRowSelection(index);
                });
                actions.appendChild(clearBtn);

                search.addEventListener('input', function() {
                    renderCandidates(index, list, search.value, mappings[index]);
                });

                row.appendChild(incoming);
                row.appendChild(search);
                row.appendChild(list);
                row.appendChild(selected);
                row.appendChild(actions);
                rowsEl.appendChild(row);
                renderCandidates(index, list, '', '');
            });

            overlay.hidden = false;
            overlay.classList.add('visible');
            overlay.setAttribute('aria-hidden', 'false');
            confirmBtn.addEventListener('click', handleConfirm);
            cancelBtn.addEventListener('click', handleCancel);
            overlay.addEventListener('pointerdown', handleOverlay);
            document.addEventListener('keydown', handleKey, true);
            var firstSearch = rowsEl.querySelector('[data-mmf-overwrite-search]');
            if (firstSearch) firstSearch.focus();
        });
    }

    function directoryChildNameSet(targetHash) {
        var names = {};
        targetHash = String(targetHash || '');
        for (var h in FILES) {
            var file = FILES[h];
            if (file && file.phash === targetHash && file.mime !== 'directory' && file.name) {
                names[String(file.name).toLowerCase()] = true;
            }
        }
        return names;
    }

    function fetchDirectoryChildNames(targetHash) {
        targetHash = String(targetHash || CWD_HASH || '');
        if (targetHash === String(CWD_HASH || '')) {
            return Promise.resolve(directoryChildNameSet(targetHash));
        }
        return api({cmd: 'open', target: targetHash}).then(function(data) {
            var names = {};
            if (!data || !Array.isArray(data.files)) return names;
            data.files.forEach(function(file) {
                if (file && file.mime !== 'directory' && file.name) {
                    names[String(file.name).toLowerCase()] = true;
                }
            });
            return names;
        });
    }

    function promptUploadRename(originalName, suggestedName) {
        return new Promise(function(resolve) {
            var settled = false;
            function finish(value) {
                if (settled) return;
                settled = true;
                resolve(value);
            }
            openManagerDialog({
                title: t('uploadNameConflictTitle'),
                message: t('uploadNameConflictMessage', {name: originalName}),
                label: t('newName'),
                value: suggestedName,
                input: true,
                okLabel: t('uploadConfirmNewName'),
                secondaryLabel: t('uploadOverwriteExisting'),
                onSecondary: function() {
                    openManagerDialog({
                        title: t('uploadOverwriteConfirmTitle'),
                        message: t('uploadOverwriteConfirmMessage', {name: originalName}),
                        input: false,
                        destructive: true,
                        okLabel: t('uploadOverwriteConfirm'),
                        onOk: function() {
                            finish({action: 'overwrite', name: originalName});
                        },
                        onCancel: function() {
                            promptUploadRename(originalName, suggestedName).then(finish);
                        }
                    });
                },
                onOk: function(value) {
                    var trimmed = String(value || '').trim();
                    finish(trimmed ? {action: 'rename', name: trimmed} : null);
                },
                onCancel: function() { finish(null); }
            });
        });
    }

    function confirmUploadName(originalName, reservedNames) {
        var suggestedName = suggestUniqueUploadFileName(originalName, reservedNames);
        return promptUploadRename(originalName, suggestedName).then(function(decision) {
            if (!decision || !decision.name) return null;
            if (decision.action === 'overwrite') {
                return {name: String(decision.name), overwrite: true};
            }
            var confirmedName = ensureUploadFileExtension(String(decision.name), originalName);
            if (!confirmedName) return null;
            if (reservedNames[confirmedName.toLowerCase()]) {
                showError(t('uploadNameStillExists', {name: confirmedName}));
                return confirmUploadName(confirmedName, reservedNames);
            }
            return {name: confirmedName, overwrite: false};
        });
    }

    function resolveUploadNameConflicts(fileList, targetHash, presetOverwriteFlags) {
        var files = Array.prototype.slice.call(fileList || []);
        var overwriteFlags = Array.prototype.slice.call(presetOverwriteFlags || []);
        while (overwriteFlags.length < files.length) overwriteFlags.push(false);
        return fetchDirectoryChildNames(targetHash).then(function(reservedNames) {
            var chain = Promise.resolve({files: files, overwriteFlags: overwriteFlags});
            files.forEach(function(file, index) {
                chain = chain.then(function(current) {
                    if (!current || !current.files) return null;
                    var currentFiles = current.files;
                    var flags = current.overwriteFlags;
                    var currentName = String(currentFiles[index].name || '');
                    var lowerName = currentName.toLowerCase();
                    if (flags[index]) {
                        reservedNames[lowerName] = true;
                        return {files: currentFiles, overwriteFlags: flags};
                    }
                    if (!reservedNames[lowerName]) {
                        reservedNames[lowerName] = true;
                        return {files: currentFiles, overwriteFlags: flags};
                    }
                    return confirmUploadName(currentName, reservedNames).then(function(decision) {
                        if (!decision || !decision.name) return null;
                        if (decision.overwrite) {
                            flags[index] = true;
                            reservedNames[decision.name.toLowerCase()] = true;
                            return {files: currentFiles, overwriteFlags: flags};
                        }
                        if (decision.name !== currentName) {
                            currentFiles[index] = renameUploadFile(currentFiles[index], decision.name);
                        }
                        reservedNames[decision.name.toLowerCase()] = true;
                        flags[index] = false;
                        return {files: currentFiles, overwriteFlags: flags};
                    });
                });
            });
            return chain;
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
            body.append('lockPath', CONFIG.lockPath ? '1' : '0');
            if (CONFIG.lockPath && CONFIG.lockRoot) {
                body.append('lockRoot', String(CONFIG.lockRoot));
            }
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
                appendConnectorFormField(body, key, fields[key]);
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

    function requestUploadMetadata(fileList, overwriteFlags) {
        var ui = window.Weline && window.Weline.UI;
        if (!ui || !ui.dialog || typeof ui.dialog.prompt !== 'function') {
            return Promise.reject(new Error(t('uploadMetadataRequired')));
        }
        var files = Array.prototype.slice.call(fileList || []);
        var flags = Array.prototype.slice.call(overwriteFlags || []);
        while (flags.length < files.length) flags.push(false);
        var metadata = [];
        var needsCopy = false;
        files.forEach(function(file, index) {
            if (flags[index]) {
                metadata[index] = {overwrite: true};
            } else {
                needsCopy = true;
            }
        });
        if (!needsCopy) {
            return Promise.resolve({
                metadata: metadata,
                mode: 'upload'
            });
        }
        var chain = Promise.resolve(true);
        files.forEach(function(file, index) {
            chain = chain.then(function(continueUpload) {
                if (!continueUpload) return false;
                if (flags[index]) {
                    metadata[index] = {overwrite: true};
                    return true;
                }
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
                        metadata[index] = {
                            display_name: displayName,
                            default_alt: String(altResult.value || '').trim(),
                            description: String(descriptionResult.value || '').trim(),
                            default_caption: ''
                        };
                        return true;
                    });
                });
            });
        });
        return chain.then(function(confirmed) {
            if (!confirmed) return null;
            return ui.dialog.prompt(t('uploadModePrompt'), {
                title: t('uploadModeTitle'),
                confirmLabel: t('confirm'),
                field: {
                    type: 'select',
                    required: true,
                    value: 'upload',
                    choices: {
                        upload: t('confirmUploadOnly'),
                        translate: t('uploadWithOneClickTranslate')
                    }
                }
            }).then(function(modeResult) {
                if (!modeResult || !modeResult.confirmed) return null;
                return {
                    metadata: metadata,
                    mode: String(modeResult.value || 'upload') === 'translate' ? 'translate' : 'upload'
                };
            });
        });
    }

    function translateUploadedAssets(addedFiles) {
        var files = Array.prototype.slice.call(addedFiles || []).filter(function(file) {
            return file && file.asset_id;
        });
        if (!files.length) return Promise.resolve();
        var chain = Promise.resolve(true);
        files.forEach(function(file) {
            chain = chain.then(function() {
                return new Promise(function(resolve) {
                    api({
                        cmd: 'asset_translate_missing',
                        target: file.hash,
                        asset_id: String(file.asset_id),
                        locale_code: String(file.locale_code || CONFIG.localeCode || 'zh_Hans_CN')
                    }, function(data) {
                        if (data && data.errors && data.errors.length) {
                            showError(data.errors.join('; '));
                        } else {
                            showSuccess(t('oneClickTranslateDone', {
                                filled: (data && data.filled && data.filled.length) || 0,
                                skipped: (data && data.skipped && data.skipped.length) || 0
                            }));
                        }
                        resolve(true);
                    }, function(err) {
                        showError(err || t('oneClickTranslateFailed'));
                        resolve(false);
                    });
                });
            });
        });
        return chain;
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
        var pickTargets = (source === 'drop' || source === 'paste')
            ? promptOptionalOverwriteTargets(files, targetHash)
            : Promise.resolve({
                files: files,
                overwriteFlags: files.map(function() { return false; })
            });
        pickTargets.then(function(mapped) {
            if (!mapped || !mapped.files) return null;
            return resolveUploadNameConflicts(mapped.files, targetHash, mapped.overwriteFlags).then(function(resolved) {
            if (!resolved || !resolved.files) return null;
            var resolvedFiles = resolved.files;
            var overwriteFlags = resolved.overwriteFlags || [];
            return requestUploadMetadata(resolvedFiles, overwriteFlags).then(function(uploadPlan) {
                if (!uploadPlan || !uploadPlan.metadata) return null;
                announceInteraction(t(source === 'paste' ? 'pasteUploadStarted' : 'uploadStarted', {
                    count: resolvedFiles.length
                }));
                showUploadProgress(true);
                updateUploadProgress(0);
                var upload = canUseSingleMultipartRequest(resolvedFiles)
                    ? uploadMultipart(resolvedFiles, uploadPlan.metadata, targetHash)
                    : uploadResumable(resolvedFiles, uploadPlan.metadata, targetHash);
                return upload.then(function(response) {
                    updateUploadProgress(100);
                    showSuccess(t('uploadComplete'));
                    var added = response && Array.isArray(response.added) ? response.added : [];
                    var after = uploadPlan.mode === 'translate'
                        ? translateUploadedAssets(added.filter(function(item) {
                            return !(item && item.overwritten);
                        }))
                        : Promise.resolve();
                    return after.then(function() {
                        openDir(CWD_HASH);
                    });
                });
            });
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
        // 引用管理模式：只提示卸引用，不走物理删
        if (CONFIG.referenceViewMode) {
            showError(t('useRefPanelUnbind') || '引用管理模式请使用左侧「卸引用前缀 / 关站清引用」，不会删除物理文件。');
            return;
        }
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
            if (!(IFRAME_MODE && MULTI_SELECT)) {
                addContextItem(menu, 'exit-selection', t('exitSelectionMode'), 'active');
            }
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

    function promptAssetLocaleMetadata(file) {
        var ui = window.Weline && window.Weline.UI;
        if (!file || file.mime === 'directory') {
            return Promise.reject(new Error(t('assetMetadataMissing')));
        }
        if (!ui || !ui.dialog || typeof ui.dialog.prompt !== 'function') {
            return Promise.reject(new Error(t('assetMetadataEditorUnavailable')));
        }
        return ui.dialog.prompt(t('assetDisplayNamePrompt'), {
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
        });
    }

    function applyAssetDescriptionChange(file, data) {
        var changed = data && data.changed && data.changed[file.hash];
        if (!changed) return null;
        FILES[file.hash] = Object.assign({}, FILES[file.hash] || file, changed);
        updatePreviewPanel();
        var details = qs('.mmf-details-overlay');
        if (details && details.classList.contains('visible')) {
            openAssetDetails(file.hash);
        }
        if (LOCALE_WORKBENCH.hash === String(file.hash || '')) {
            renderLocaleWorkbench(FILES[file.hash]);
        }
        return FILES[file.hash];
    }

    function saveAssetLocaleMetadata(file, metadata) {
        return new Promise(function(resolve, reject) {
            if (!file || !metadata) {
                reject(new Error(t('assetMetadataMissing')));
                return;
            }
            if (file.asset_id) {
                api({
                    cmd: 'asset_metadata',
                    target: file.hash,
                    asset_id: String(file.asset_id),
                    asset_revision: Number(file.asset_revision) || 1,
                    locale_code: CONFIG.localeCode || 'zh_Hans_CN',
                    display_name: metadata.display_name,
                    default_alt: metadata.default_alt,
                    description: metadata.description,
                    default_caption: metadata.default_caption
                }, function(data) {
                    resolve(applyAssetDescriptionChange(file, data));
                }, function(err) {
                    reject(new Error(err || t('assetMetadataSaveFailed')));
                });
                return;
            }
            api({
                cmd: 'asset_ensure',
                target: file.hash,
                locale_code: CONFIG.localeCode || 'zh_Hans_CN',
                display_name: metadata.display_name,
                default_alt: metadata.default_alt,
                description: metadata.description,
                default_caption: metadata.default_caption
            }, function(data) {
                resolve(applyAssetDescriptionChange(file, data));
            }, function(err) {
                reject(new Error(err || t('assetMetadataSaveFailed')));
            });
        });
    }

    function supplementAssetMetadataForSelection(file) {
        return promptAssetLocaleMetadata(file).then(function(metadata) {
            if (!metadata) return null;
            return saveAssetLocaleMetadata(file, metadata).then(function(updated) {
                showSuccess(t('assetMetadataSaved'));
                return updated;
            });
        });
    }

    function editSelectedAssetMetadata() {
        var file = SELECTED.length === 1 ? FILES[SELECTED[0]] : null;
        if (!file || file.mime === 'directory') {
            showError(t('assetMetadataMissing'));
            return;
        }
        supplementAssetMetadataForSelection(file).catch(function(error) {
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
        // iframe 多选选择器必须保持选择模式，否则单击会变成单选覆盖
        if (IFRAME_MODE && MULTI_SELECT) {
            enterSelectionMode();
            return;
        }
        SELECTION_MODE = false;
        var wrap = qs('.mmf-wrap');
        if (wrap) wrap.classList.remove('mmf-selection-mode');
        updateStatus();
    }

    function clearSelection() {
        SELECTED = [];
        highlightSelected();
        updateStatus();
        if (SELECTED.length === 0 && !(IFRAME_MODE && MULTI_SELECT)) {
            exitSelectionMode();
        }
        if (IFRAME_MODE) {
            updateSelectBar();
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
                            // Prefer picker CONFIG locale (Theme Editor site-default stamp)
                            // over the asset-row locale so draft validation matches.
                            locale_code: String(CONFIG.localeCode || file.locale_code),
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
        var blockedMetadataFiles = [];
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
                    blockedMetadataFiles.push(f);
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

        if (blockedMetadataFiles.length) {
            SELECTION_CONFIRMING = true;
            var chain = Promise.resolve(true);
            blockedMetadataFiles.forEach(function(file) {
                chain = chain.then(function(continueSupplement) {
                    if (!continueSupplement) return false;
                    return supplementAssetMetadataForSelection(file).then(function(updated) {
                        return !!updated;
                    });
                });
            });
            chain.then(function(completed) {
                SELECTION_CONFIRMING = false;
                if (completed) confirmSelection();
            }).catch(function(error) {
                SELECTION_CONFIRMING = false;
                showError((error && error.message) || t('assetMetadataSaveFailed'));
            });
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
        if (MULTI_SELECT) {
            enterSelectionMode();
        }
        if (CWD_HASH) renderFiles();
        updateToolbarCapabilities();
        updateSelectBar();
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
        var secondaryBtn = qs('.mmf-dialog-secondary', overlay);
        var returnFocus = document.activeElement;
        var hasInput = options.input === true;
        var hasSecondary = !!(options.secondaryLabel && typeof options.onSecondary === 'function' && secondaryBtn);
        var defaultOkLabel = okBtn.getAttribute('data-default-label') || okBtn.textContent || 'OK';
        if (!okBtn.getAttribute('data-default-label')) {
            okBtn.setAttribute('data-default-label', defaultOkLabel);
        }
        titleEl.textContent = options.title || '';
        messageEl.textContent = options.message || '';
        messageEl.hidden = !options.message;
        inp.hidden = !hasInput;
        inp.value = hasInput ? (options.value || '') : '';
        inp.placeholder = hasInput ? (options.label || '') : '';
        inp.setAttribute('aria-label', hasInput ? (options.label || options.title || '') : '');
        okBtn.textContent = options.okLabel || defaultOkLabel;
        okBtn.classList.toggle('mmf-btn-danger', options.destructive === true);
        okBtn.classList.toggle('mmf-btn-primary', options.destructive !== true);
        if (secondaryBtn) {
            secondaryBtn.hidden = !hasSecondary;
            secondaryBtn.textContent = hasSecondary ? String(options.secondaryLabel || '') : '';
        }
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
            if (secondaryBtn) secondaryBtn.removeEventListener('click', handleSecondary);
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
        function handleCancel() {
            close(true);
            if (typeof options.onCancel === 'function') options.onCancel();
        }
        function handleSecondary() {
            close(true);
            if (typeof options.onSecondary === 'function') options.onSecondary();
        }
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
                dialog.querySelectorAll('button:not([hidden]):not(:disabled), input:not([hidden]):not(:disabled)')
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
        if (secondaryBtn && hasSecondary) {
            secondaryBtn.addEventListener('click', handleSecondary);
        }
        overlay.addEventListener('pointerdown', handleOverlay);
        document.addEventListener('keydown', handleKey, true);
        if (hasInput) {
            inp.focus();
            var nameParts = splitUploadFileName(inp.value);
            if (nameParts.ext && nameParts.base) {
                // 只选中主名，扩展名固定可见，便于改名且不带扩展时仍可自动补齐
                inp.setSelectionRange(0, nameParts.base.length);
            } else {
                inp.select();
            }
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
        qsa('input[name="mmf_ai_save_mode"]').forEach(function(input) {
            input.addEventListener('change', syncAiSaveModeFields);
        });
        var aiTargetSearch = qs('#mmf-ai-save-target-search');
        if (aiTargetSearch) {
            aiTargetSearch.addEventListener('input', renderAiSaveTargetCandidates);
        }
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

    function syncAiSaveModeFields() {
        var modeInput = document.querySelector('input[name="mmf_ai_save_mode"]:checked');
        var saveMode = modeInput ? modeInput.value : 'save_as';
        var targetPick = qs('#mmf-ai-save-target-pick');
        var newfileFields = qs('#mmf-ai-save-newfile-fields');
        var isReplaceTarget = saveMode === 'replace_target';
        var isOverwriteSource = saveMode === 'overwrite';
        var inheritMeta = isReplaceTarget || isOverwriteSource;
        if (targetPick) targetPick.hidden = !isReplaceTarget;
        if (newfileFields) newfileFields.hidden = inheritMeta;
        if (isReplaceTarget) {
            renderAiSaveTargetCandidates();
        }
    }

    function renderAiSaveTargetCandidates() {
        var list = qs('#mmf-ai-save-target-list');
        var search = qs('#mmf-ai-save-target-search');
        var selectedEl = qs('#mmf-ai-save-target-selected');
        var hidden = qs('#mmf-ai-save-target-name');
        if (!list) return;
        var selectedName = hidden ? String(hidden.value || '') : '';
        var candidates = filterDirectoryChildFiles(directoryChildFiles(CWD_HASH), search ? search.value : '');
        list.replaceChildren();
        if (!candidates.length) {
            var empty = document.createElement('div');
            empty.className = 'mmf-overwrite-pick-selected';
            empty.textContent = directoryChildFiles(CWD_HASH).length ? t('overwritePickNoMatch') : t('overwritePickEmpty');
            list.appendChild(empty);
        } else {
            candidates.forEach(function(item) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'mmf-overwrite-pick-candidate' + (item.name === selectedName ? ' is-active' : '');
                btn.textContent = item.name;
                btn.addEventListener('click', function() {
                    if (hidden) hidden.value = item.name;
                    if (selectedEl) {
                        selectedEl.hidden = false;
                        selectedEl.textContent = t('overwritePickSelected', {name: item.name});
                    }
                    renderAiSaveTargetCandidates();
                });
                list.appendChild(btn);
            });
        }
        if (selectedEl) {
            if (selectedName) {
                selectedEl.hidden = false;
                selectedEl.textContent = t('overwritePickSelected', {name: selectedName});
            } else {
                selectedEl.hidden = true;
                selectedEl.textContent = '';
            }
        }
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
            overwriteWrap.style.display = AI_SOURCE_HASH ? '' : 'none';
        }
        var saveAsMode = qs('input[name="mmf_ai_save_mode"][value="save_as"]');
        if (saveAsMode) saveAsMode.checked = true;
        var targetHidden = qs('#mmf-ai-save-target-name');
        if (targetHidden) targetHidden.value = '';
        var targetSearch = qs('#mmf-ai-save-target-search');
        if (targetSearch) targetSearch.value = '';
        var filename = qs('#mmf-ai-save-filename');
        if (filename) {
            filename.value = selected[0].filename || '';
            if (!filename.value) {
                var promptElForName = qs('#mmf-ai-prompt');
                var promptStem = promptToAltFilenameStem(promptElForName ? promptElForName.value : '');
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
        syncAiSaveModeFields();
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
        if (saveMode === 'replace_target') {
            saveMode = 'overwrite';
        }
        if (saveMode !== 'overwrite' && !CWD_HASH) {
            setAiSaveError(t('uploadWaitDir'));
            return;
        }
        var filenameEl = qs('#mmf-ai-save-filename');
        var filename = filenameEl ? filenameEl.value.trim() : '';
        var targetNameEl = qs('#mmf-ai-save-target-name');
        var targetName = targetNameEl ? targetNameEl.value.trim() : '';
        var modeRaw = modeInput ? modeInput.value : 'save_as';
        if (modeRaw === 'replace_target') {
            if (!targetName) {
                setAiSaveError(t('aiSaveTargetRequired'));
                return;
            }
            filename = targetName;
        }
        if (saveMode !== 'overwrite' && !filename) {
            setAiSaveError(t('aiSaveFilenameRequired') || t('aiSaveFailed'));
            return;
        }
        var altEl = qs('#mmf-ai-save-alt');
        var descriptionEl = qs('#mmf-ai-save-description');
        var captionEl = qs('#mmf-ai-save-caption');
        var defaultAlt = altEl ? altEl.value.trim() : '';
        var description = descriptionEl ? descriptionEl.value.trim() : '';
        if (saveMode !== 'overwrite') {
            if (!defaultAlt) {
                setAiSaveError(t('aiSaveAltRequired'));
                return;
            }
            if (!description) {
                setAiSaveError(t('aiSaveDescriptionRequired'));
                return;
            }
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
        [
            'identity', 'identity_root', 'identity_code', 'identity_scope',
            'identity_kind', 'identity_field', 'identity_component', 'identity_locale',
            'identity_instance',
        ].forEach(function (key) {
            if (CONFIG[key]) payload[key] = CONFIG[key];
        });
        // Fail-closed for strong business save_as without identity
        if (saveMode === 'save_as' && CONFIG.requireIdentity === true
            && !payload.identity && !(payload.identity_root && payload.identity_code)) {
            setAiSaveBusy(false);
            setAiSaveError(t('aiSaveIdentityRequired') || 'Media identity required for business save.');
            return;
        }
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
        ensureUploadFileExtension: ensureUploadFileExtension,
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
